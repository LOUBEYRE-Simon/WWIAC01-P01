<?php

declare(strict_types=1);

require_once 'atl-db.php';
require_once 'atl-add_document.php';

// Fonctions utilitaires (Embedding et Similarité)
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
        throw new RuntimeException("Erreur lors de la génération de l'embedding.");
    }

    $decoded = json_decode($response, true);
    return $decoded['embedding'] ?? [];
}

function findSimilarDocuments(array $queryEmbedding, int $limit = 3): array
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

    usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
    return array_slice($similarities, 0, $limit);
}

/**
 * Recherche les segments les plus pertinents parmi TOUS les documents
 */
function searchKnowledgeBase(string $userQuery, int $topK = 4): array {
    // 1. Transformer la question en vecteur
    $queryVector = getOllamaEmbedding($userQuery);

    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    // 2. Récupérer tous les segments stockés
    $stmt = $pdo->query("SELECT content, embedding, collection_name FROM vectors");
    $allSegments = $stmt->fetchAll();
    
    $hits = [];
    foreach ($allSegments as $segment) {
        $vectorInDb = json_decode($segment['embedding'], true);
        
        // 3. Calculer la similarité avec notre fonction mathématique
        $score = calculateSimilarity($queryVector, $vectorInDb);
        
        $hits[] = [
            'content' => $segment['content'],
            'source'  => $segment['collection_name'],
            'score'   => $score
        ];
    }

    // 4. Trier par score (le plus pertinent en premier)
    usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);

    // 5. Retourner les K meilleurs
    return array_slice($hits, 0, $topK);
}

/**
 * Recherche les K segments les plus similaires.
 * @param array $queryEmbedding Le vecteur de la question
 * @param int $k Nombre de segments à récupérer
 */
function findTopKSimilarDocuments(array $queryEmbedding, int $k = 3): array {
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    // On récupère tout le contenu pour comparer
    $stmt = $pdo->query("SELECT content, embedding, collection_name FROM vectors");
    $allEntries = $stmt->fetchAll();
    
    $scoredResults = [];

    foreach ($allEntries as $entry) {
        $vectorInDb = json_decode($entry['embedding'], true);
        
        // Utilisation de notre nouvelle fonction de calcul
        $score = calculateSimilarity($queryEmbedding, $vectorInDb);
        
        $scoredResults[] = [
            'content' => $entry['content'],
            'source' => $entry['collection_name'],
            'score' => $score
        ];
    }

    // TRI : On place les scores les plus hauts en premier
    usort($scoredResults, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    // On retourne les K meilleurs (ex: les 3 meilleurs segments)
    return array_slice($scoredResults, 0, $k);
}

function findTopKSimilarDocumentsOld(array $queryEmbedding, int $k = 4): array
{
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    $stmt = $pdo->query("SELECT content, embedding, source_name FROM vectors");
    $all = $stmt->fetchAll();
    
    $results = [];
    foreach ($all as $row) {
        $storedEnv = json_decode($row['embedding'], true);
        
        // Calcul de similarité (on peut réutiliser la fonction de test précédente)
        $sim = calculateSimilarity($queryEmbedding, $storedEnv);
        
        $results[] = [
            'content' => $row['content'],
            'source' => $row['source_name'],
            'similarity' => $sim
        ];
    }

    // Tri par score décroissant
    usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

    // On retourne les K meilleurs résultats au-dessus d'un seuil de 0.4 (pour éviter le bruit)
    return array_slice(array_filter($results, fn($r) => $r['similarity'] > 0.4), 0, $k);
}


function generateRagResponse(string $prompt, string $context, string $model = 'gemma4:e4b', string $baseUrl = 'http://127.0.0.1:11434'): string
{
    $url = $baseUrl . '/api/generate';
    $fullPrompt = "Tu es un assistant IA. Utilise le contexte fourni pour répondre à la question de l'utilisateur.
Si le contexte ne contient pas la réponse, dis-le honnêtement.

Contexte :
{$context}

Question :
{$prompt}";

    $data = [
        'model' => $model,
        'prompt' => $fullPrompt,
        'stream' => false,
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
        throw new RuntimeException("Erreur de l'API Ollama.");
    }

    $decoded = json_decode($response, true);
    return $decoded['response'] ?? 'Aucune réponse générée.';
}

function generateMultiContextResponse(string $question, array $similarSegments): string
{
    $contextText = "";
    foreach ($similarSegments as $index => $seg) {
        $contextText .= "[Source " . ($index + 1) . " : " . $seg['source'] . "]\n";
        $contextText .= $seg['content'] . "\n\n";
    }

    $fullPrompt = "Tu es un assistant expert. Voici plusieurs extraits de documents pour t'aider à répondre.
Utilise ces informations pour construire une réponse complète. 
Cite la source (ex: [Source 1]) si tu utilises une information spécifique.

CONTEXTE :
$contextText

QUESTION :
$question

RÉPONSE :";

    // Appel à Ollama (identique à avant...)
    return callOllamaGenerate('llama3', $fullPrompt);
}

/*
function testMultiDocumentRetrieval() {
    echo "<h3>Test de récupération Multi-Documents</h3>";
    
    // Simuler l'indexation de deux documents différents sur le même sujet
    saveDocumentToDb("Le chat est un félin domestique.", [1,0], "Doc_Animaux");
    saveDocumentToDb("Le chat aime chasser les souris.", [0.9,0.1], "Doc_Chasse");
    
    $queryVec = [1, 0.1]; // Question sur le chat
    $results = findTopKSimilarDocuments($queryVec, 2);
    
    echo "Nombre de sources trouvées : " . count($results) . "<br>";
    foreach($results as $r) {
        echo "- Trouvé dans : " . $r['source'] . " (Score: " . round($r['score'], 2) . ")<br>";
    }
    
    assert(count($results) >= 2, "Le système devrait trouver les deux documents.");
}
// testMultiDocumentRetrieval();

// Logique de traitement du formulaire
$responseContent = "";
$contextContent = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['question'])) {
    $question = trim($_POST['question']);
    try {
        $queryEmbedding = getQueryEmbedding($question);
        $results = findSimilarDocuments($queryEmbedding);

        if (count($results) > 0) {
            $contextContent = $results[0]['content'];
            $responseContent = generateRagResponse($question, $contextContent);
        } else {
            $responseContent = "Désolé, aucune information pertinente n'a été trouvée dans la base de données pour répondre à cette question.";
        }
    } catch (Exception $e) {
        $responseContent = "Erreur : " . $e->getMessage();
    }
}
*/


$question = "";
$responseContent = "";
$sourcesUsed = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['question'])) {
    $question = trim($_POST['question']);
    
    try {
        // ÉTAPE 1 : Appeler la recherche
        // On demande les 3 meilleurs segments (Top-K = 3)
        $relevantSegments = searchKnowledgeBase($question, 10);

        if (!empty($relevantSegments)) {
            // ÉTAPE 2 : Préparer le contexte pour le LLM
            $contextText = "";
            foreach ($relevantSegments as $index => $seg) {
                $contextText .= "Extrait " . ($index + 1) . " (Source: " . $seg['source'] . ") : " . $seg['content'] . "\n\n";
                $sourcesUsed[] = $seg['source']; // Pour l'affichage plus tard
            }

            // ÉTAPE 3 : Envoyer au LLM (generateRagResponse)
            $responseContent = generateRagResponse($question, $contextText);
        } else {
            $responseContent = "Je n'ai trouvé aucune information pertinente dans vos documents.";
        }
    } catch (Exception $e) {
        $responseContent = "Erreur système : " . $e->getMessage();
    }
}


?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistant RAG - Projet</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f6;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        h2 {
            color: #2c3e50;
            margin-top: 0;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        label {
            font-weight: bold;
        }
        textarea {
            width: 100%;
            height: 80px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            resize: vertical;
            box-sizing: border-box;
        }
        button, a {
            background-color: #28a745;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #218838;
        }
        .result-box {
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-left: 4px solid #007bff;
            border-radius: 4px;
        }
        .context-box {
            margin-top: 15px;
            font-size: 0.9em;
            color: #555;
            background: #eee;
            padding: 10px;
            border-radius: 4px;
        }
    </style>
</head>
<body>

<a style="background-color: #007bff;" href="atl-check_vectors.php" target="_blank">Vectors</a>
<a style="background-color: #007bff;" href="atl-init_tables.php" target="_blank">Script SQL</a>
<hr/>

<div class="container" style="margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 20px;">
    <h3>📂 Ajouter une source au RAG</h3>
    <form action="atl-upload_handler.php" method="POST" enctype="multipart/form-data" style="flex-direction: row; align-items: center;">
        <input type="file" name="file" accept=".pdf,.txt">
        <span>OU</span>
        <input type="url" name="url" placeholder="Lien HTML (http...)">
        <button type="submit" style="background-color: #007bff;">Indexer</button>
    </form>
</div>

<div class="container">
    <h2>🤖 Assistant d'Intelligence Artificielle</h2>
    
    <form method="POST" action="">
        <label for="question">Votre question :</label>
        <textarea name="question" id="question" placeholder="Ex: Qu'est-ce qu'un LLM ?"><?= htmlspecialchars($question) ?></textarea>
        <button type="submit">Envoyer</button>
    </form>

<?php if (!empty($responseContent)): ?>
    <div class="result-box">
        <strong>Réponse de l'IA :</strong><br>
        <?= nl2br(htmlspecialchars($responseContent)) ?>
    </div>

    <div class="sources-box" style="font-size: 0.8em; color: gray;">
        Sources consultées : <?= implode(', ', array_unique($sourcesUsed)) ?>
    </div>
<?php endif; ?>
</div>

</body>
</html>