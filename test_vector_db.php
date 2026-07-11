<?php

declare(strict_types=1);

/**
 * Test de connexion à l'API ChromaDB.
 *
 * @param string $baseUrl URL de base de ChromaDB (ex: 'http://localhost:8000')
 * @return bool Vrai si la base est joignable
 */
function checkChromaDb(string $baseUrl = 'http://localhost:8000'): bool
{
    $ch = curl_init($baseUrl . '/api/v2/heartbeat');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

// === Exécution et test ===
try {
    echo "<h3>Vérification de la base de données vectorielle...</h3>";
    
    if (checkChromaDb()) {
        echo "<p style='color: green;'>Connexion à ChromaDB réussie !</p>";
    } else {
        echo "<p style='color: orange;'>ChromaDB ne répond pas à l'adresse par défaut (http://localhost:8000).</p>";
        echo "<p><em>Veuillez vous assurer que le service ChromaDB est démarré sur votre machine.</em></p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Erreur :</strong> " . $e->getMessage() . "</p>";
}