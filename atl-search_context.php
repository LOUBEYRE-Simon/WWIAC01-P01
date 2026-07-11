<?php

declare(strict_types=1);

require_once 'atl-db.php';

/**
 * Génère l'embedding pour la requête utilisateur.
 */
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

/**
 * Recherche les documents les plus similaires (calcul de similarité cosinus en SQL).
 */
function findSimilarDocuments(array $queryEmbedding, int $limit = 3): array
{
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    // Récupération de tous les embeddings stockés pour le calcul
    $stmt = $pdo->query("SELECT chunk_id, content, embedding FROM vectors");
    $allVectors = $stmt->fetchAll();

    $similarities = [];

    foreach ($allVectors as $row) {
        $storedEmbedding = json_decode($row['embedding'], true);

        // Calcul de la similarité cosinus simple en PHP
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($queryEmbedding); $i++) {
            if (isset($storedEmbedding[$i])) {
                $dotProduct += $queryEmbedding[$i] * $storedEmbedding[$i];
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

    // Tri par ordre décroissant de similarité
    usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

    return array_slice($similarities, 0, $limit);
}

// === Exécution ===
try {
    echo "<h3>Recherche de contexte dans le RAG...</h3>";

    $userQuestion = "Qu'est-ce qu'un LLM ?";
    
    echo "<strong>Question posée :</strong> " . htmlspecialchars($userQuestion) . "<br><br>";

    // 1. Génération de l'embedding de la question
    $queryEmbedding = getQueryEmbedding($userQuestion);

    // 2. Recherche
    $results = findSimilarDocuments($queryEmbedding);

    echo "<h4>Résultats trouvés :</h4>";
    if (count($results) > 0) {
        foreach ($results as $result) {
            echo "<div style='border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; border-radius: 5px;'>";
            echo "<strong>Similarité :</strong> " . number_format($result['similarity'] * 100, 2) . "%<br>";
            echo "<strong>Contenu :</strong> " . htmlspecialchars($result['content']);
            echo "</div>";
        }
    } else {
        echo "<p>Aucun document trouvé pour cette question.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur : " . $e->getMessage() . "</p>";
}