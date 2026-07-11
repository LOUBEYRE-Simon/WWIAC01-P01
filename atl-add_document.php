<?php

declare(strict_types=1);

require_once 'atl-db.php';

/**
 * GÉNÉRATION D'EMBEDDING
 * Appelle l'API Ollama pour transformer un texte en vecteur.
 */
function getOllamaEmbedding(string $text, string $model = 'nomic-embed-text', string $baseUrl = 'http://127.0.0.1:11434'): array
{
    $url = $baseUrl . '/api/embeddings';
    $data = ['model' => $model, 'prompt' => $text];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException("Erreur Ollama (Code $httpCode) : " . $response);
    }

    $decoded = json_decode($response, true);
    return $decoded['embedding'] ?? [];
}

/**
 * CHUNKING INTELLIGENT
 * Découpe le texte en segments avec chevauchement (overlap).
 */
function chunkText(string $text, int $size = 1000, int $overlap = 150): array
{
    $chunks = [];
    $start = 0;
    $textLength = mb_strlen($text);

    while ($start < $textLength) {
        $chunk = mb_substr($text, $start, $size);
        
        // Si on n'est pas à la fin, on cherche une coupure propre (espace ou point)
        if ($start + $size < $textLength) {
            $lastSpace = mb_strrpos($chunk, ' ');
            $lastLine = mb_strrpos($chunk, "\n");
            $cutAt = max($lastSpace, $lastLine);
            
            if ($cutAt !== false && $cutAt > ($size * 0.7)) {
                $chunk = mb_substr($chunk, 0, $cutAt);
            }
        }

        $chunks[] = trim($chunk);
        // On avance de (taille - overlap) pour garder le contexte
        $start += (mb_strlen($chunk) - $overlap);
        
        if (mb_strlen($chunk) <= $overlap) break; // Sécurité
    }
    return array_filter($chunks);
}

/**
 * SAUVEGARDE EN BASE DE DONNÉES
 * Enregistre un segment et son vecteur dans MariaDB.
 */
function saveDocumentToDb(string $content, array $embedding, string $source = 'manual_upload'): bool
{
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    $chunkId = hash('sha256', $content . microtime());
    $embeddingJson = json_encode($embedding);

    $stmt = $pdo->prepare("
        INSERT INTO vectors (collection_name, chunk_id, content, embedding)
        VALUES (:collection, :chunk_id, :content, :embedding)
    ");

    return $stmt->execute([
        ':collection' => $source,
        ':chunk_id'   => $chunkId,
        ':content'    => $content,
        ':embedding'  => $embeddingJson,
    ]);
}

/**
 * PIPELINE COMPLET
 * Prend un texte brut, le découpe, le vectorise et l'enregistre.
 */
function processAndIndexDocument(string $fullText, string $sourceName): int
{
    $chunks = chunkText($fullText);
    $count = 0;

    foreach ($chunks as $chunk) {
        $embedding = getOllamaEmbedding($chunk);
        if (saveDocumentToDb($chunk, $embedding, $sourceName)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Calcule la similarité cosinus entre deux vecteurs.
 * Requis pour comparer la question (embedding) aux documents en base.
 */
function calculateSimilarity(array $vecA, array $vecB): float {
    $dotProduct = 0; $normA = 0; $normB = 0;
    foreach ($vecA as $i => $value) {
        $dotProduct += $value * ($vecB[$i] ?? 0);
        $normA += $value ** 2;
        $normB += ($vecB[$i] ?? 0) ** 2;
    }
    return ($normA * $normB) == 0 ? 0 : $dotProduct / (sqrt($normA) * sqrt($normB));
}


