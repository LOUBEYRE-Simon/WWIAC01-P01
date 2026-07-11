<?php

declare(strict_types=1);

function getChromaCollections(string $baseUrl = 'http://localhost:8000'): string
{
    // Utilisation du endpoint v2
    $url = $baseUrl . '/api/v2/collections';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Certains serveurs ChromaDB en v2 attendent une méthode GET, d'autres POST selon l'implémentation,
    // mais le protocole d'interrogation standard est GET pour lister.
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return "<p style='color: green;'>Connecté avec succès. Réponse de ChromaDB V2 : " . htmlspecialchars($response) . "</p>";
    } else {
        return "<p style='color: red;'>Erreur HTTP {$httpCode}. Impossible de lister les collections. Réponse : " . htmlspecialchars($response) . "</p>";
    }
}

try {
    echo "<h3>Vérification des collections sur ChromaDB (v2)...</h3>";
    echo getChromaCollections();
} catch (Exception $e) {
    echo "<p style='color: red;'>Exception : " . $e->getMessage() . "</p>";
}