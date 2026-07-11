<?php

declare(strict_types=1);

// Classe de connexion (définie dans db.php)
class DatabaseConnection
{
    private ?PDO $connection = null;

    public function __construct(
        private string $host = '127.0.0.1',
        private string $dbname = 'anything_llm_php',
        private string $username = 'app_user',
        private string $password = 'votre_mot_de_passe_app'
    ) {}

    public function connect(): PDO
    {
        if ($this->connection === null) {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            try {
                $this->connection = new PDO($dsn, $this->username, $this->password, $options);
            } catch (PDOException $e) {
                throw new RuntimeException('Erreur de connexion à la base de données : ' . $e->getMessage(), (int)$e->getCode());
            }
        }

        return $this->connection;
    }
}

// === Exécution et test ===
try {
    $dbManager = new DatabaseConnection();
    $pdo = $dbManager->connect();

    echo "<p style='color: green;'>Connexion à la base de données validée avec succès !</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Erreur :</strong> " . $e->getMessage() . "</p>";
}