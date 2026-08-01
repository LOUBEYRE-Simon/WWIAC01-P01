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
function process_document(string $pdfUrl): array
{
    $pdfPath = fetch_pdf_from_url($pdfUrl);

    try {
        // Étape 1 : nombre de pages
        $split = call_python_step('step1_split_pdf.py', ['pdf_path' => $pdfPath], STEP_TIMEOUTS['split']);
        $nbPages = $split['nb_pages'];

        $pages = [];
        $documentType = null;
        $documentTypeConfidence = 0.0;
        $header = null;
        $allLines = [];

        for ($pageNumber = 1; $pageNumber <= $nbPages; $pageNumber++) {
            try {
                // Étape 2 : extraction du texte (pdftotext, sinon OCR)
                $extraction = call_python_step('step2_extract_page.py', [
                    'pdf_path' => $pdfPath,
                    'page_number' => $pageNumber,
                ], STEP_TIMEOUTS['extract']);

                $rawText = $extraction['text'];

                // Étape 3 : anonymisation (trace/audit - pas sur le chemin Ollama)
                $anonymization = call_python_step('step3_anonymize.py', [
                    'text' => $rawText,
                ], STEP_TIMEOUTS['anonymize']);

                // Étape 4 : classification du type de document
                $classification = call_python_step('step4_classify.py', [
                    'text' => $rawText,
                ], STEP_TIMEOUTS['classify']);

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

                // Étape 5 : en-tête (émetteur/destinataire...) - typiquement
                // présent sur la première page seulement, donc un seul appel
                // suffit. Ajuster si le format réel varie.
                if ($pageNumber === 1) {
                    $headerResult = call_python_step('step5_ollama_header.py', [
                        'text' => $rawText,
                        'document_type' => $classification['document_type'],
                    ], STEP_TIMEOUTS['header']);
                    $header = $headerResult['header'];
                }

                // Étape 6 (conditionnelle) : lignes de détail, potentiellement
                // réparties sur plusieurs pages -> on agrège au fil des pages.
                $linesResult = call_python_step('step6_ollama_lines.py', [
                    'text' => $rawText,
                    'document_type' => $classification['document_type'],
                ], STEP_TIMEOUTS['lines']);

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

        return [
            'status' => 'ok',
            'nb_pages' => $nbPages,
            'document_type' => $documentType,
            'document_type_confidence' => $documentTypeConfidence,
            'header' => $header,
            'lines' => $allLines,
            'pages' => $pages,
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
