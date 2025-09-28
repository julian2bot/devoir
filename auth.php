<?php
session_start();
require_once 'db.php';

// Si l'utilisateur est déjà connecté, on le redirige vers l'accueil
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$is_login_form = !isset($_GET['register']); // Affiche le formulaire de connexion par défaut

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Tous les champs sont requis.';
    } else {
        if (isset($_POST['register_action'])) { // Action d'inscription
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Ce nom d\'utilisateur existe déjà.';
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
                $stmt->execute([$username, $password_hash]);
                // Connecte l'utilisateur juste après l'inscription
                $_SESSION['user_id'] = $pdo->lastInsertId();
                $_SESSION['username'] = $username;
                header('Location: index.php');
                exit();
            }
        } else { // Action de connexion
            $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                header('Location: index.php');
                exit();
            } else {
                $error = 'Nom d\'utilisateur ou mot de passe incorrect.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_login_form ? 'Connexion' : 'Inscription' ?> - Agenda Devoirs</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-100 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-sm p-8 bg-white rounded-2xl shadow-xl text-center">
        <i class="ph-duotone ph-student text-6xl text-indigo-500"></i>
        <h1 class="text-3xl font-bold text-slate-800 mt-4">Agenda Devoirs</h1>
        <p class="text-slate-500 mt-2"><?= $is_login_form ? 'Connectez-vous pour commencer.' : 'Créez un compte pour continuer.' ?></p>
        
        <?php if ($error): ?>
            <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="mt-8 text-left">
            <div class="mb-4">
                <label for="username" class="block text-sm font-medium text-slate-700">Nom d'utilisateur</label>
                <input type="text" name="username" id="username" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
            </div>
            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-slate-700">Mot de passe</label>
                <input type="password" name="password" id="password" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
            </div>
            
            <?php if ($is_login_form): ?>
                <button type="submit" name="login_action" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:shadow-lg transition-all">
                    Connexion
                </button>
                <p class="text-center text-sm text-slate-500 mt-4">
                    Pas encore de compte ? <a href="?register" class="font-semibold text-indigo-600 hover:underline">Inscrivez-vous</a>
                </p>
            <?php else: ?>
                <button type="submit" name="register_action" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:shadow-lg transition-all">
                    S'inscrire
                </button>
                <p class="text-center text-sm text-slate-500 mt-4">
                    Déjà un compte ? <a href="?" class="font-semibold text-indigo-600 hover:underline">Connectez-vous</a>
                </p>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>
