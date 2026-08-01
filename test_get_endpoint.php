<?php
/**
 * Point d'entrée GET pratique pour des tests manuels rapides (navigateur ou
 * curl), sans avoir à construire un corps de requête.
 *
 * Usage : test_get_endpoint.php?fic=<URL du PDF encodée en base64>&fid=<id de suivi>
 * Exemple donné par l'utilisateur :
 *   ?fic=aHR0cHM6Ly9lZGktZXhwbG9pdGF0aW9uLmdyb3VwZXNpZmEuY29tL2dlZC9TQUZFWC8yMDI2MDczMS9GRVgtRE9DLTAwMDAwMDc4MzYxNS5wZGY=
 *   (décode en https://edi-exploitation.groupesifa.com/ged/SAFEX/20260731/FEX-DOC-000000783615.pdf)
 *
 * Paramètres GET :
 *   fic - URL du PDF encodée en base64 (obligatoire)
 *   fid - identifiant de suivi/corrélation fourni par l'appelant (optionnel),
 *         renvoyé tel quel dans la réponse ("fid") pour confirmation - utile
 *         pour retrouver la bonne réponse quand plusieurs tests tournent en
 *         parallèle.
 *
 * La réponse inclut désormais les informations de contrôle demandées :
 *   - "fid"                 : l'identifiant transmis par l'appelant (ou null si absent)
 *   - "request_duration_ms" : durée totale de la requête HTTP (décodage du GET inclus)
 *   - "duration_ms"         : durée du traitement interne (process_document),
 *     un sous-ensemble de request_duration_ms
 *   - "logs"                : détail étape par étape (script, n° de page le cas
 *     échéant, statut "ok"/"error", durée en ms, message d'erreur éventuel) -
 *     présents même en cas d'échec fatal (fetch/split), grâce au passage de
 *     $logs par référence à process_document().
 *
 * ATTENTION - à réserver aux tests, pas à la production :
 * - Le base64 n'est PAS un chiffrement : l'URL/le nom de fichier source
 *   restent parfaitement lisibles pour quiconque lit les logs d'accès du
 *   serveur web, l'historique du navigateur, ou un cache intermédiaire.
 * - Un GET est plus facilement mis en cache / rejoué / partagé qu'un POST.
 * Pour la production, préférer un vrai POST JSON (voir l'exemple en bas de
 * pipeline_orchestrator.php).
 */

require_once __DIR__ . '/pipeline_orchestrator.php';

header('Content-Type: application/json');

$requestStart = microtime(true);
$fid = $_GET['fid'] ?? null;

$ficParam = $_GET['fic'] ?? null;
if (!$ficParam) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error' => "Paramètre 'fic' manquant",
        'fid' => $fid,
        'request_duration_ms' => round((microtime(true) - $requestStart) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdfUrl = base64_decode($ficParam, true);
if ($pdfUrl === false || !filter_var($pdfUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error' => "Paramètre 'fic' invalide : base64 non décodable ou URL malformée une fois décodée",
        'fid' => $fid,
        'request_duration_ms' => round((microtime(true) - $requestStart) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$logs = [];
try {
    $result = process_document($pdfUrl, $logs);
    $result['fid'] = $fid;
    $result['request_duration_ms'] = round((microtime(true) - $requestStart) * 1000);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'fid' => $fid,
        'request_duration_ms' => round((microtime(true) - $requestStart) * 1000),
        // Logs partiels accumulés avant l'échec fatal (fetch_pdf ou step1
        // typiquement) - process_document() écrit dans $logs par référence
        // même quand elle lève une exception.
        'logs' => $logs,
    ], JSON_UNESCAPED_UNICODE);
}
