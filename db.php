<?php
// db.php - Fichier de connexion à la base de données

// --- MODIFIEZ CES INFORMATIONS ---
$host = 'localhost';      // L'hôte de votre base de données (souvent 'localhost')
$dbname = 'agenda_devoirs'; // Le nom de votre base de données
$user = 'appuser';           // Votre nom d'utilisateur pour la base de données
$password = 'password123';           // Votre mot de passe
// ---------------------------------

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lance des exceptions en cas d'erreur
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Récupère les résultats en tableau associatif
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Utilise les vraies requêtes préparées
];

try {
    // Crée une instance de PDO
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (\PDOException $e) {
    // En cas d'erreur de connexion, arrête le script et affiche un message
    // En production, il serait préférable de logger cette erreur plutôt que de l'afficher
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>
