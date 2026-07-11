<?php

declare(strict_types=1);

/**
 * Test de connexion à l'API Ollama.
 *
 * @param string $model Nom du modèle à utiliser (ex: 'llama3')
 * @param string $prompt Texte à envoyer
 * @param string $baseUrl URL de base d'Ollama
 * @return string Réponse du modèle
 */
function callOllama(string $model, string $prompt, string $baseUrl = 'http://127.0.0.1:11434'): string
{
    $url = $baseUrl . '/api/generate';

    $data = [
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false, // Désactiver le streaming pour obtenir la réponse en un bloc
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
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Erreur cURL : {$error}");
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException("Erreur HTTP {$httpCode}. Vérifiez si Ollama est démarré.");
    }

    $decoded = json_decode($response, true);

    return $decoded['response'] ?? 'Aucune réponse reçue.';
}

// === Exécution et test ===
try {
    $modele = 'gemma4:e4b'; // Remplacez par votre modèle
    $question = 'Bonjour ! Réponds en une phrase pour confirmer que tu es connecté.';
    
    echo "<h3>Envoi de la requête à Ollama...</h3>";
    $reponse = callOllama($modele, $question);
    
    echo "<p><strong>Modèle utilisé :</strong> {$modele}</p>";
    echo "<p><strong>Réponse :</strong> {$reponse}</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Erreur :</strong> " . $e->getMessage() . "</p>";
}