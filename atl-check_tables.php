<?php

declare(strict_types=1);

require_once 'atl-db.php'; // Inclut votre fichier de connexion (db.php)

try {
    // Instanciation de la connexion et récupération de l'objet PDO
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    // Requête pour lister les tables de la base de données
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "<h2>Validation : Tables existantes dans la base de données</h2>";
    
    if (count($tables) > 0) {
        echo "<ul style='color: green;'>";
        foreach ($tables as $table) {
            echo "<li><strong>{$table}</strong></li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>Aucune table trouvée dans la base de données.</p>";
    }

} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>Erreur :</strong> " . $e->getMessage() . "</p>";
}