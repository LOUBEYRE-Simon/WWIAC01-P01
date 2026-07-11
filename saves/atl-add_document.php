<?php

declare(strict_types=1);

require_once 'atl-db.php';

/**
 * Génère un embedding via l'API d'Ollama.
 *
 * @param string $text Le texte à vectoriser
 * @param string $model Modèle d'embedding (ex: 'nomic-embed-text' ou modèle par défaut)
 * @param string $baseUrl URL d'Ollama
 * @return array Tableau de floats représentant l'embedding
 */
function getOllamaEmbedding(string $text, string $model = 'nomic-embed-text', string $baseUrl = 'http://127.0.0.1:11434'): array
{
    $url = $baseUrl . '/api/embeddings';
    $data = [
        'model' => $model,
        'prompt' => $text,
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

    if ($httpCode !== 200) {
        throw new RuntimeException("Erreur lors de la génération de l'embedding par Ollama (HTTP {$httpCode}).");
    }

    $decoded = json_decode($response, true);

    // Retourne le vecteur d'embedding
    return $decoded['embedding'] ?? [];
}

/**
 * Sauvegarde le document et son vecteur dans MariaDB.
 */
function saveDocumentToDb(string $content, array $embedding): bool
{
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    // Utilisation d'un identifiant unique (UUID ou hash) pour le chunk_id
    $chunkId = hash('sha256', $content . time());
    // L'embedding est sérialisé en JSON pour être stocké dans une colonne texte/LONGTEXT
    $embeddingJson = json_encode($embedding);

    $stmt = $pdo->prepare("
        INSERT INTO vectors (collection_name, chunk_id, content, embedding)
        VALUES (:collection, :chunk_id, :content, :embedding)
    ");

    return $stmt->execute([
        ':collection' => 'documents_rag',
        ':chunk_id' => $chunkId,
        ':content' => $content,
        ':embedding' => $embeddingJson,
    ]);
}

// === Exécution du test unitaire ===
try {
    echo "<h3>Ajout d'un document au RAG...</h3>";

    $documentContent = "Les LLMs (Large Language Models) et les RAG (Retrieval-Augmented Generation) permettent d'enrichir les réponses des IA avec des bases de connaissances externes.";

    // 1. Génération de l'embedding (Assurez-vous qu'Ollama possède le modèle d'embedding, ex: nomic-embed-text)
    // Vous pouvez installer le modèle avec la commande : ollama run nomic-embed-text
    echo "Génération des embeddings via Ollama...<br>";
    $embedding = getOllamaEmbedding($documentContent);

    // 2. Sauvegarde dans MariaDB
    if (!empty($embedding)) {
        if (saveDocumentToDb($documentContent, $embedding)) {
            echo "<p style='color: green;'>Document et embedding enregistrés avec succès dans MariaDB !</p>";
        } else {
            echo "<p style='color: red;'>Erreur lors de la sauvegarde en base de données.</p>";
        }
    } else {
        echo "<p style='color: red;'>L'embedding est vide, vérifiez qu'Ollama est bien configuré avec un modèle d'embedding.</p>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Exception :</strong> " . $e->getMessage() . "</p>";
}