<?php

declare(strict_types=1);

/**
 * Crée une collection dans ChromaDB via le point d'accès correct.
 *
 * @param string $collectionName Nom de la collection
 * @param string $baseUrl URL de l'API de ChromaDB
 * @return string Message de succès ou d'erreur
 */
function createChromaCollection(string $collectionName = 'documents_rag', string $baseUrl = 'http://localhost:8000'): string
{
    // Utilisation de la route correcte (v1 ou racine de l'API selon l'image)
    $url = $baseUrl . '/api/v1/collections'; // Souvent la route par défaut pour les versions récentes

    $data = [
        'name' => $collectionName,
        'metadata' => [
            'description' => 'Collection pour le RAG et les documents du projet'
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201 || $httpCode === 204) {
        return "<p style='color: green;'>Collection '{$collectionName}' créée avec succès (ou existait déjà) dans ChromaDB.</p>";
    } else {
        // En cas d'erreur 404, on tente une alternative (ex: v2/collections)
        $urlV2 = $baseUrl . '/api/v2/collections';
        $chV2 = curl_init($urlV2);
        curl_setopt($chV2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chV2, CURLOPT_POST, true);
        curl_setopt($chV2, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($chV2, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        
        $responseV2 = curl_exec($chV2);
        $httpCodeV2 = curl_getinfo($chV2, CURLINFO_HTTP_CODE);
        curl_close($chV2);

        if ($httpCodeV2 === 200 || $httpCodeV2 === 201 || $httpCodeV2 === 204) {
            return "<p style='color: green;'>Collection '{$collectionName}' créée avec succès via l'API v2.</p>";
        }

        return "<p style='color: red;'>Erreur lors de la création de la collection. Code HTTP V1: {$httpCode} / Code HTTP V2: {$httpCodeV2}. Réponse : " . htmlspecialchars($responseV2) . "</p>";
    }
}

// === Exécution ===
try {
    echo "<h3>Création de la collection dans ChromaDB...</h3>";
    echo createChromaCollection();
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Exception :</strong> " . $e->getMessage() . "</p>";
}