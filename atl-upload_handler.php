<?php
declare(strict_types=1);

require_once 'atl-db.php';
require_once 'atl-add_document.php'; // On réutilise votre fonction de sauvegarde

/**
 * Extrait le texte d'un PDF via pdftotext
 */
function extractTextFromPdfToText(string $filePath): string {
    $outputFile = $filePath . ".txt";
    shell_exec("pdftotext " . escapeshellarg($filePath) . " " . escapeshellarg($outputFile));
    if (file_exists($outputFile)) {
        $text = file_get_contents($outputFile);
        unlink($outputFile); // Nettoyage
        return $text;
    }
    return "";
}

/**
 * Extrait le texte d'un PDF via Ghostscript
 * -dTextFormat=2 : tente de conserver la mise en page (espaces/colonnes)
 */
function extractTextFromPdf(string $filePath): string {
    $outputFile = $filePath . ".txt";
    
    // Commande GS optimisée pour la mise en page
    $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -dTextFormat=3 -sOutputFile=" . escapeshellarg($outputFile) . " " . escapeshellarg($filePath);
    
    shell_exec($cmd);
    
    if (file_exists($outputFile)) {
        $text = file_get_contents($outputFile);
        unlink($outputFile); // Nettoyage
        return $text;
    }
    return "";
}

/**
 * Extrait le texte d'un lien HTML via curl et strip_tags
 */
function extractTextFromHtml(string $url): string {
    $html = shell_exec("curl -s " . escapeshellarg($url));
    // Nettoyage rudimentaire du HTML pour ne garder que le texte
    $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $html);
    $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $text);
    return strip_tags($text);
}

/**
 * Logique principale d'intégration au RAG
 */
 if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $textContent = "";
    $sourceName = "";

    // 1. Extraction du texte selon la source
    if (!empty($_FILES['file']['tmp_name'])) {
        $file = $_FILES['file'];
        $sourceName = $file['name']; // On garde le nom du fichier comme référence
        $ext = pathinfo($sourceName, PATHINFO_EXTENSION);
        
        if ($ext === 'pdf') {
            $textContent = extractTextFromPdf($file['tmp_name']);
        } elseif ($ext === 'txt') {
            $textContent = file_get_contents($file['tmp_name']);
        }
    } elseif (!empty($_POST['url'])) {
        $sourceName = $_POST['url'];
        $textContent = extractTextFromHtml($sourceName);
    }

    // 2. Lancement de l'indexation intelligente (Le fameux appel)
    if (!empty($textContent)) {
        echo "<h3>Traitement de : " . htmlspecialchars($sourceName) . "</h3>";
        
        // C'est ICI que l'on appelle la fonction globale.
        // Elle va boucler sur les chunks, appeler Ollama et enregistrer en BDD.
        $nbSegments = processAndIndexDocument($textContent, $sourceName);
        
        echo "<p style='color: green;'>Succès : $nbSegments segments ont été ajoutés à votre base de connaissances.</p>";
        echo "<a href='atl.php'>Retour à l'assistant</a>";
    } else {
        echo "Erreur : Aucun contenu n'a pu être extrait.";
    }
}

/*
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = "";
    
    // CAS 1 : Fichier (PDF ou TXT)
    if (!empty($_FILES['file']['tmp_name'])) {
        $file = $_FILES['file'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        if ($ext === 'pdf') {
            $content = extractTextFromPdf($file['tmp_name']);
        } elseif ($ext === 'txt') {
            $content = file_get_contents($file['tmp_name']);
        }
    } 
    // CAS 2 : URL
    elseif (!empty($_POST['url'])) {
        $content = extractTextFromHtml($_POST['url']);
    }

    // Envoi au pipeline RAG (Ollama + MariaDB)
    if (!empty($content)) {
        // Optionnel : Découper le texte en morceaux (chunking) si le texte est trop long
        // Pour l'instant, on envoie le bloc complet
        $embedding = getOllamaEmbedding($content);
        if (saveDocumentToDb($content, $embedding)) {
            echo '<a href="atl.php">Document indexé avec succès !</a>';
            echo '<a href="atl-check_vectors.php">Voir la base de données</a>';
        }
    }
}
*/
