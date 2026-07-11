<?php

declare(strict_types=1);

require_once 'atl-db.php'; // Inclut votre fichier de connexion à la BDD

try {
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    $sql = "
    CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `role` ENUM('admin', 'user', 'guest') DEFAULT 'user',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS `prompts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `system_prompt` TEXT NULL,
        `user_input` TEXT NOT NULL,
        `ai_response` TEXT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS `rules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(100) NOT NULL,
        `instruction` TEXT NOT NULL,
        `example_expected` TEXT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
	
	CREATE TABLE IF NOT EXISTS `vectors` (
		`id` INT AUTO_INCREMENT PRIMARY KEY,
		`collection_name` VARCHAR(100) DEFAULT 'documents_rag',
		`chunk_id` VARCHAR(100) NOT NULL UNIQUE,
		`content` TEXT NOT NULL,
		`embedding` LONGTEXT NOT NULL, -- Stockage des embeddings générés par Ollama
		`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
	);

	-- ALTER TABLE `vectors` ADD COLUMN `source_name` VARCHAR(255) AFTER `collection_name`;
	
	TRUNCATE `vectors`;
    ";

    $pdo->exec($sql);
    echo "<p style='color: green;'>Tables créées avec succès.</p>";
} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>Erreur SQL :</strong> " . $e->getMessage() . "</p>";
}