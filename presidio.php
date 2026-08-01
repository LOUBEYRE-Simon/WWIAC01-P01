<?php
/**
 * Point d'entrée GET pratique pour des tests manuels rapides (navigateur ou
 * curl), sans avoir à construire un corps de requête.
 *
 * Usage : test_get_endpoint.php?fic=<URL du PDF encodée en base64>
 * Exemple donné par l'utilisateur :
 *   ?fic=aHR0cHM6Ly9lZGktZXhwbG9pdGF0aW9uLmdyb3VwZXNpZmEuY29tL2dlZC9TQUZFWC8yMDI2MDczMS9GRVgtRE9DLTAwMDAwMDc4MzYxNS5wZGY=
 *   (décode en https://edi-exploitation.groupesifa.com/ged/SAFEX/20260731/FEX-DOC-000000783615.pdf)
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

$ficParam = $_GET['fic'] ?? null;
if (!$ficParam) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => "Paramètre 'fic' manquant"]);
    exit;
}

$pdfUrl = base64_decode($ficParam, true);
if ($pdfUrl === false || !filter_var($pdfUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error' => "Paramètre 'fic' invalide : base64 non décodable ou URL malformée une fois décodée",
    ]);
    exit;
}

try {
    $result = process_document($pdfUrl);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
