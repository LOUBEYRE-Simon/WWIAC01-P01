<?php
/**
 * Squelette d'orchestration PHP - point d'entrée qui reçoit une demande
 * d'analyse de PDF (URL), et enchaîne les 6 étapes en lançant un process
 * Python par étape (exec/proc_open), comme demandé.
 *
 * Étapes (un script CLI Python par étape, contrat commun : JSON sur stdin,
 * JSON sur stdout, voir cli_common.py pour le détail) :
 *   1. step1_split_pdf.py       -> nombre de pages
 *   2. step2_extract_page.py    -> texte de la page (pdftotext, sinon OCR)
 *   3. step3_anonymize.py       -> texte anonymisé + entités (Presidio)
 *   4. step4_classify.py        -> type de document
 *   5. step5_ollama_header.py   -> émetteur/destinataire/en-tête (Ollama, texte BRUT)
 *   6. step6_ollama_lines.py    -> lignes de détail (Ollama, si type éligible)
 *
 * Décisions actées avec l'utilisateur :
 * - Mode d'appel : CLI (exec), pas de service HTTP à maintenir. Contrepartie
 *   assumée : chaque appel relance un interpréteur Python, et step3
 *   (Presidio/spaCy) a un coût de démarrage mesuré à ~4-5s par appel.
 * - step5/step6 (Ollama) reçoivent le texte BRUT, pas anonymisé : Ollama
 *   tourne 100% en local (minicpm-v4.5), aucune donnée ne sort du réseau
 *   interne à cette étape. L'anonymisation (étape 3) sert donc ici à
 *   produire une version tracée/auditable du document, pas à protéger
 *   l'appel Ollama lui-même.
 * - entity_mapping (sortie de l'étape 3) est la donnée la plus sensible du
 *   pipeline : à ne conserver qu'en local (ex: colonne chiffrée en base),
 *   jamais dans un log en clair, jamais transmise à un service externe.
 *
 * Ce fichier est un SQUELETTE à adapter à l'architecture PHP existante
 * (voir functions.php pour les conventions déjà en place, ex: préfixe sNN_).
 * Chemins des scripts, gestion des erreurs par étape, et politique de
 * parallélisation (une page à la fois ici, par simplicité) sont à ajuster.
 *
 * Informations de contrôle (ajout) : process_document() mesure désormais la
 * durée de chaque étape (fetch, split, extraction, anonymisation,
 * classification, Ollama) via timed_call(), et renvoie :
 *   - "duration_ms" : durée totale du traitement
 *   - "logs"        : détail étape par étape (script, n° de page le cas
 *     échéant, statut, durée en ms, message d'erreur éventuel)
 * $logs est aussi passé par référence à process_document() : même si une
 * étape fatale (fetch du PDF, split) lève une exception et interrompt le
 * traitement, l'appelant récupère quand même les logs déjà accumulés
 * jusque-là (voir test_get_endpoint.php pour l'utilisation de ce paramètre).
 */

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

define('PYTHON_BIN', 'python3');
define('SCRIPTS_DIR', __DIR__); // dossier contenant les step*_*.py
define('WORK_DIR', sys_get_temp_dir());

// Timeouts par étape (secondes) - à ajuster selon la volumétrie réelle des
// documents. Les valeurs ci-dessous sont dérivées de mesures faites sur le
// prototype : ~0.1s pour pdftotext, ~6-15s pour l'OCR à 300 DPI, ~5s pour
// Presidio (dont ~4-5s de démarrage spaCy), et un Ollama vision-langage 8B
// qui peut prendre plusieurs dizaines de secondes selon le matériel - à
// mesurer avec le vrai modèle avant de figer ces valeurs.
const STEP_TIMEOUTS = [
    'split'     => 10,
    'extract'   => 60,
    'anonymize' => 30,
    'classify'  => 15,
    'header'    => 120,
    'lines'     => 120,
];

// minicpm-v4.5 est un SLM (small language model) : fenêtre de contexte
// d'environ 40K tokens, PAS un grand modèle avec des dizaines/centaines de
// milliers de tokens de marge. Cette fenêtre doit couvrir : le prompt
// système, les instructions/le nom des champs demandés, le texte du
// document, ET la réponse JSON générée par le modèle. Ces plafonds en
// caractères sont volontairement prudents (marge large sous la limite
// réelle) - à ajuster si des mesures de tokenisation réelles sont faites.
const HEADER_TEXT_MAX_CHARS = 12000; // step5 : texte page 1 + dernière page
const LINES_TEXT_MAX_CHARS = 12000;  // step6 : texte d'une seule page


// ---------------------------------------------------------------------
// Tronque un texte pour rester sous un budget de caractères, en gardant le
// début (en-tête/émetteur/destinataire, quasi toujours au début du texte)
// ET la fin (totaux/devise, souvent en pied de document sur les factures
// multi-pages) - c'est le milieu, généralement les lignes de détail (déjà
// couvertes séparément par step6), qui saute en cas de dépassement.
// ---------------------------------------------------------------------
function truncate_for_context(string $text, int $maxChars): string
{
    if (mb_strlen($text) <= $maxChars) {
        return $text;
    }

    $headChars = (int) round($maxChars * 0.6);
    $tailChars = $maxChars - $headChars;

    return mb_substr($text, 0, $headChars)
        . "\n\n[... contenu tronqué pour respecter la fenêtre de contexte du modèle ...]\n\n"
        . mb_substr($text, -$tailChars);
}


// ---------------------------------------------------------------------
// Exécution d'une étape Python : écrit $input en JSON sur stdin, lit le
// JSON de sortie, applique un timeout via l'utilitaire `timeout` (coreutils
// Linux), et lève une exception explicite en cas d'échec.
// ---------------------------------------------------------------------
function call_python_step(string $scriptName, array $input, int $timeoutSec): array
{
    $scriptPath = SCRIPTS_DIR . DIRECTORY_SEPARATOR . $scriptName;
    if (!is_file($scriptPath)) {
        throw new RuntimeException("Script introuvable : $scriptPath");
    }

    $cmd = sprintf(
        'timeout %d %s %s',
        $timeoutSec,
        escapeshellarg(PYTHON_BIN),
        escapeshellarg($scriptPath)
    );

    $descriptorSpec = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("Impossible de lancer le process : $cmd");
    }

    fwrite($pipes[0], json_encode($input, JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    // Code 124 = timeout atteint (convention de l'utilitaire `timeout`)
    if ($exitCode === 124) {
        throw new RuntimeException("$scriptName a dépassé le timeout de {$timeoutSec}s");
    }

    $decoded = json_decode($stdout, true);
    if ($decoded === null) {
        throw new RuntimeException(
            "$scriptName n'a pas renvoyé de JSON valide (exit=$exitCode). " .
            "stdout: " . substr($stdout, 0, 500) . " | stderr: " . substr($stderr, 0, 500)
        );
    }

    if (($decoded['status'] ?? null) !== 'ok') {
        throw new RuntimeException("$scriptName a échoué : " . ($decoded['error'] ?? 'erreur inconnue'));
    }

    return $decoded;
}


// ---------------------------------------------------------------------
// Mesure de durée + journalisation uniforme, pour n'importe quel appel
// (fetch_pdf_from_url, call_python_step...). Enregistre systématiquement
// une entrée dans $logs (succès ou échec) puis relance l'exception s'il y
// en a une, pour ne rien changer au flux de contrôle existant (page
// ignorée en cas d'erreur, etc.) - seule la journalisation est ajoutée.
// ---------------------------------------------------------------------
function timed_call(array &$logs, string $label, callable $fn, ?int $pageNumber = null)
{
    $t0 = microtime(true);
    $status = 'ok';
    $errorMessage = null;
    try {
        $result = $fn();
        return $result;
    } catch (Throwable $e) {
        $status = 'error';
        $errorMessage = $e->getMessage();
        throw $e;
    } finally {
        $logs[] = [
            'step' => $label,
            'page_number' => $pageNumber,
            'status' => $status,
            'duration_ms' => round((microtime(true) - $t0) * 1000),
            'error' => $errorMessage,
        ];
    }
}


// ---------------------------------------------------------------------
// Étape 0 (implicite) : récupération du fichier source par URL
// ---------------------------------------------------------------------
function fetch_pdf_from_url(string $url): string
{
    $localPath = WORK_DIR . '/rgpd_pipeline_' . uniqid() . '.pdf';

    $cmd = sprintf(
        'wget --timeout=30 -q -O %s %s',
        escapeshellarg($localPath),
        escapeshellarg($url)
    );
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($localPath) || filesize($localPath) === 0) {
        throw new RuntimeException("Échec du téléchargement du PDF depuis : $url");
    }

    return $localPath;
}


// ---------------------------------------------------------------------
// Orchestration complète pour un document
// ---------------------------------------------------------------------
function process_document(string $pdfUrl, array &$logs = []): array
{
    $globalStart = microtime(true);

    $pdfPath = timed_call($logs, 'fetch_pdf', function () use ($pdfUrl) {
        return fetch_pdf_from_url($pdfUrl);
    });

    try {
        // Étape 1 : nombre de pages
        $split = timed_call($logs, 'step1_split_pdf.py', function () use ($pdfPath) {
            return call_python_step('step1_split_pdf.py', ['pdf_path' => $pdfPath], STEP_TIMEOUTS['split']);
        });
        $nbPages = $split['nb_pages'];

        $pages = [];
        $documentType = null;
        $documentTypeConfidence = 0.0;
        $header = null;
        $allLines = [];
        $allRawText = []; // texte de chaque page correctement extraite, pour l'étape 5 (voir plus bas)

        for ($pageNumber = 1; $pageNumber <= $nbPages; $pageNumber++) {
            try {
                // Étape 2 : extraction du texte (pdftotext, sinon OCR)
                $extraction = timed_call($logs, 'step2_extract_page.py', function () use ($pdfPath, $pageNumber) {
                    return call_python_step('step2_extract_page.py', [
                        'pdf_path' => $pdfPath,
                        'page_number' => $pageNumber,
                    ], STEP_TIMEOUTS['extract']);
                }, $pageNumber);

                $rawText = $extraction['text'];
                $allRawText[] = $rawText;

                // Étape 3 : anonymisation (trace/audit - pas sur le chemin Ollama)
                $anonymization = timed_call($logs, 'step3_anonymize.py', function () use ($rawText) {
                    return call_python_step('step3_anonymize.py', ['text' => $rawText], STEP_TIMEOUTS['anonymize']);
                }, $pageNumber);

                // Étape 4 : classification du type de document
                $classification = timed_call($logs, 'step4_classify.py', function () use ($rawText) {
                    return call_python_step('step4_classify.py', ['text' => $rawText], STEP_TIMEOUTS['classify']);
                }, $pageNumber);

                $pages[] = [
                    'page_number' => $pageNumber,
                    'extraction_source' => $extraction['source'],
                    'extraction_engine' => $extraction['engine'],
                    'raw_text' => $rawText,
                    'anonymized_text' => $anonymization['anonymized_text'],
                    'entities_detected' => $anonymization['entities_detected'],
                    // 'entity_mapping' : sensible - à stocker à part (voir note en tête de fichier),
                    // volontairement absent de la structure retournée à l'appelant HTTP.
                    'document_type' => $classification['document_type'],
                    'document_type_confidence' => $classification['confidence'],
                ];

                // On retient le type de document le plus confiant du lot comme
                // type global (hypothèse simplificatrice : à affiner si le PDF
                // mélange plusieurs types de documents sur des pages différentes).
                if ($documentType === null || $classification['confidence'] > $documentTypeConfidence) {
                    $documentType = $classification['document_type'];
                    $documentTypeConfidence = $classification['confidence'];
                }

                // Étape 6 (conditionnelle) : lignes de détail, potentiellement
                // réparties sur plusieurs pages -> on agrège au fil des pages.
                // Texte plafonné (voir HEADER_TEXT_MAX_CHARS/LINES_TEXT_MAX_CHARS
                // en tête de fichier) - le modèle est un SLM à ~40K tokens de
                // contexte, pas de marge pour envoyer une page entière sans limite.
                $linesInputText = truncate_for_context($rawText, LINES_TEXT_MAX_CHARS);
                $linesResult = timed_call($logs, 'step6_ollama_lines.py', function () use ($linesInputText, $classification) {
                    return call_python_step('step6_ollama_lines.py', [
                        'text' => $linesInputText,
                        'document_type' => $classification['document_type'],
                    ], STEP_TIMEOUTS['lines']);
                }, $pageNumber);

                if (!($linesResult['skipped'] ?? false)) {
                    $allLines = array_merge($allLines, $linesResult['lines']);
                }

            } catch (Throwable $pageError) {
                // Une page en échec ne doit pas faire échouer tout le document -
                // on la journalise et on continue (politique à ajuster selon les
                // besoins métier : peut-être préférable de tout arrêter selon le cas).
                $pages[] = [
                    'page_number' => $pageNumber,
                    'error' => $pageError->getMessage(),
                ];
            }
        }

        // Étape 5 : en-tête (émetteur/destinataire, mais aussi totaux/devise).
        // Appelée UNE SEULE FOIS, après la boucle.
        //
        // Correction suite à un cas réel : sur une facture de 2 pages, les
        // totaux (Sous-total HT, TVA, Total TTC) étaient en page 2, alors que
        // step5 n'était appelée qu'avec le texte de la page 1 -> montant_total
        // et devise revenaient systématiquement à null. L'hypothèse "l'en-tête
        // est sur la première page" ne tient pas dès qu'un total de facture
        // apparaît en pied de document plutôt qu'en en-tête.
        //
        // MAIS : minicpm-v4.5 est un SLM à ~40K tokens de contexte - pas un
        // grand modèle. Concaténer TOUTES les pages (comme un premier essai
        // l'a fait) fonctionne sur 1-2 pages mais casse dès qu'un document en
        // fait davantage. On envoie donc seulement la première page (en-tête,
        // quasi toujours en tête de document) et la dernière (totaux, souvent
        // en pied de document) - pas les pages intermédiaires, qui sont
        // typiquement des lignes de détail déjà couvertes par step6 page par
        // page. Le tout est en plus plafonné par truncate_for_context().
        //
        // Point important (régression observée puis corrigée) : sur un
        // document de 2 pages, "première page" et "dernière page" sont les
        // 2 SEULES pages - rien n'est omis. Insérer quand même un marqueur
        // "[...]" entre les deux a mesurablement dégradé l'extraction (date
        // perdue, référence de commande polluée par les codes articles) -
        // le modèle semble l'avoir interprété comme un signal de contenu
        // tronqué à "reconstituer". Ce marqueur n'est donc utilisé que
        // lorsque des pages sont réellement omises (3 pages ou plus).
        if (!empty($allRawText)) {
            $nbExtractedPages = count($allRawText);
            if ($nbExtractedPages === 1) {
                $headerInputText = $allRawText[0];
            } elseif ($nbExtractedPages === 2) {
                $headerInputText = $allRawText[0] . "\n\n" . $allRawText[1];
            } else {
                $headerInputText = $allRawText[0]
                    . "\n\n[Pages intermédiaires non incluses]\n\n"
                    . $allRawText[$nbExtractedPages - 1];
            }
            $headerInputText = truncate_for_context($headerInputText, HEADER_TEXT_MAX_CHARS);

            $headerResult = timed_call($logs, 'step5_ollama_header.py', function () use ($headerInputText, $documentType) {
                return call_python_step('step5_ollama_header.py', [
                    'text' => $headerInputText,
                    'document_type' => $documentType,
                ], STEP_TIMEOUTS['header']);
            });
            $header = $headerResult['header'];
        }

        return [
            'status' => 'ok',
            'duration_ms' => round((microtime(true) - $globalStart) * 1000),
            'nb_pages' => $nbPages,
            'document_type' => $documentType,
            'document_type_confidence' => $documentTypeConfidence,
            'header' => $header,
            'lines' => $allLines,
            'pages' => $pages,
            'logs' => $logs,
        ];

    } finally {
        // Nettoyage du fichier téléchargé - à conserver plus longtemps si un
        // stockage/archivage du PDF source est requis par ailleurs.
        if (is_file($pdfPath)) {
            unlink($pdfPath);
        }
    }
}


// ---------------------------------------------------------------------
// Exemple de point d'entrée HTTP (à adapter au framework/routing existant)
// ---------------------------------------------------------------------
// $input = json_decode(file_get_contents('php://input'), true);
// $result = process_document($input['pdf_url']);
// header('Content-Type: application/json');
// echo json_encode($result, JSON_UNESCAPED_UNICODE);
