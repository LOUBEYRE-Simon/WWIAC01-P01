<?php

declare(strict_types=1);

require_once 'atl-db.php';

// 1. Récupération des fonctions d'embedding et de similarité de l'étape précédente
function getQueryEmbedding(string $query, string $model = 'nomic-embed-text', string $baseUrl = 'http://127.0.0.1:11434'): array
{
    $url = $baseUrl . '/api/embeddings';
    $data = [
        'model' => $model,
        'prompt' => $query,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException("Erreur lors de la génération de l'embedding de la requête.");
    }

    $decoded = json_decode($response, true);
    return $decoded['embedding'] ?? [];
}

function findSimilarDocuments(array $queryEmbedding, int $limit = 1): array
{
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    $stmt = $pdo->query("SELECT chunk_id, content, embedding FROM vectors");
    $allVectors = $stmt->fetchAll();
    $similarities = [];

    foreach ($allVectors as $row) {
        $storedEmbedding = json_decode($row['embedding'], true);
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($queryEmbedding); $i++) {
            if (isset($storedEmbedding[$i])) {
                $dotProduct += $queryEmbeddingValue = $queryEmbeddingValue = $queryEmbedding[$i] * $storedEmbedding[$i];
                $normA += $queryEmbedding[$i] * $queryEmbedding[$i];
                $normB += $storedEmbedding[$i] * $storedEmbedding[$i];
            }
        }

        $similarity = 0.0;
        if ($normA > 0 && $normB > 0) {
            $similarity = $dotProduct / (sqrt($normA) * sqrt($normB));
        }

        $similarities[] = [
            'id' => $row['chunk_id'],
            'content' => $row['content'],
            'similarity' => $similarity,
        ];
    }

    usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
    return array_slice($similarities, 0, $limit);
}

// 2. Fonction pour appeler le modèle de génération (ex: llama3 ou mistral)
function generateRagResponse(string $prompt, string $context, string $model = 'gemma4:e4b', string $baseUrl = 'http://127.0.0.1:11434'): string
{
    $url = $baseUrl . '/api/generate';

    // Construction du prompt enrichi avec le contexte
    $fullPrompt = "Tu es un assistant IA. Utilise le contexte fourni pour répondre à la question de l'utilisateur.
Si le contexte ne contient pas la réponse, dis-le honnêtement.

Contexte :
{$context}

Question :
{$prompt}";

    $data = [
        'model' => $model,
        'prompt' => $fullPrompt,
        'stream' => false, // Désactiver le streaming pour obtenir la réponse complète en une fois
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException("Erreur lors de l'appel au modèle de génération de texte.");
    }

    $decoded = json_decode($response, true);
    return $decoded['response'] ?? 'Aucune réponse générée.';
}

// === Exécution ===
try {
    echo "<h3>Génération de réponse RAG (Retrieval-Augmented Generation)...</h3>";

    $userQuestion = "Qu'est-ce qu'un LLM ?";
    
    echo "<strong>Question posée :</strong> " . htmlspecialchars($userQuestion) . "<br><br>";

    // Étape 1 : Récupération des embeddings et du document similaire
    $queryEmbedding = getQueryEmbedding($userQuestion);
    $results = findSimilarDocuments($queryEmbedding);

    if (count($results) > 0) {
        $context = $results[0]['content'];
        echo "<em>Contexte trouvé dans la base de données :</em> " . htmlspecialchars($context) . "<br><br>";

        // Étape 2 : Génération de la réponse avec Ollama
        echo "<strong>Génération de la réponse par le LLM...</strong><br>";
        $response = generateRagResponse($userQuestion, $context);

        echo "<div style='background-color: #f9f9f9; border-left: 4px solid #4CAF50; padding: 15px; margin-top: 10px;'>";
        echo nl2br(htmlspecialchars($response));
        echo "</div>";
    } else {
        echo "<p style='color: orange;'>Aucun contexte pertinent trouvé pour répondre à la question.</p>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur : " . $e->getMessage() . "</p>";
}