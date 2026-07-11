<?php

declare(strict_types=1);

require_once 'atl-db.php';

try {
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    // Compte le nombre d'éléments et affiche les premiers caractères du contenu
    $stmt = $pdo->query("SELECT id, collection_name, chunk_id, SUBSTRING(content, 1, 300) AS short_content, created_at FROM vectors ORDER BY id DESC LIMIT 50");
    // $stmt = $pdo->query("SELECT id, collection_name, chunk_id, content AS short_content, created_at FROM vectors ORDER BY id DESC LIMIT 50");
    $rows = $stmt->fetchAll();

    echo "<h2>Validation : Contenu de la table 'vectors'</h2>";

    if (count($rows) > 0) {
        echo "<table border='1' cellpadding='8' style='border-collapse: collapse; font-family: sans-serif;'>";
        echo "<thead>
                <tr style='background-color: #f2f2f2;'>
                    <th>ID</th>
                    <th>Collection</th>
                    <th>Chunk ID</th>
                    <th>Contenu (début)</th>
                    <th>Date de création</th>
                </tr>
              </thead>";
        echo "<tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['collection_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['chunk_id']) . "</td>";
            echo "<td><pre>" . htmlspecialchars($row['short_content']) . "...</pre></td>";
            echo "<td>" . $row['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>La table <code>vectors</code> est vide.</p>";
    }
    echo '<a href="atl.php">Retour sur le chat !</a>';

} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>Erreur :</strong> " . $e->getMessage() . "</p>";
}