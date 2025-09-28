<?php
session_start();

// Vérifie si l'utilisateur est connecté, sinon le redirige vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit();
}

$username = htmlspecialchars($_SESSION['username']);
$user_id = $_SESSION['user_id'];

// Gestion de la déconnexion
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: auth.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda de Devoirs Collaboratif</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    
    <!-- Dépendances pour le calendrier -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/luxon@3.4.4/build/global/luxon.min.js"></script>

    <!-- Polices et icônes -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="bg-slate-50 antialiased text-slate-800">

    <div id="app" class="min-h-screen">
        <header class="bg-white/80 backdrop-blur-lg sticky top-0 z-30 border-b border-slate-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center space-x-2">
                        <i class="ph-duotone ph-student text-3xl text-indigo-500"></i>
                        <span class="text-xl font-bold">Agenda Devoirs</span>
                    </div>
                    <div class="flex items-center space-x-4">
                         <p class="text-sm text-slate-600 hidden md:block">Connecté en tant que: <span class="font-bold"><?= $username ?></span></p>
                        <a href="?logout=true" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 transition-colors">
                            Déconnexion
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Colonne de gauche : Prochains devoirs -->
                <div class="lg:col-span-1 space-y-6">
                    <div class="flex items-center justify-between">
                         <h2 class="text-2xl font-bold">Prochains Devoirs</h2>
                         <button id="add-homework-btn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:shadow-lg transition-all flex items-center space-x-2">
                            <i class="ph ph-plus-circle"></i>
                            <span>Ajouter</span>
                        </button>
                    </div>
                    <div id="upcoming-homework-list" class="space-y-4">
                       <!-- Les devoirs seront injectés ici par JS -->
                       <div id="loader" class="text-center p-8"><span class="animate-pulse">Chargement...</span></div>
                    </div>
                </div>

                <!-- Colonne de droite : Calendrier -->
                <div class="lg:col-span-2 bg-white p-4 sm:p-6 rounded-2xl shadow-lg">
                    <div id='calendar'></div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modale pour ajouter/modifier un devoir -->
    <div id="homework-modal" class="fixed inset-0 bg-black/50 z-40 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 transform scale-95 opacity-0 transition-all duration-300" id="homework-modal-content">
            <div class="flex items-center justify-between mb-6">
                <h3 id="modal-title" class="text-2xl font-bold">Ajouter un devoir</h3>
                <button id="close-modal-btn" class="text-slate-400 hover:text-slate-600"><i class="ph ph-x text-2xl"></i></button>
            </div>
            <form id="homework-form">
                <input type="hidden" id="homework-id">
                <div class="space-y-4">
                    <div>
                        <label for="title" class="block text-sm font-medium text-slate-700">Titre</label>
                        <input type="text" id="title" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="subject" class="block text-sm font-medium text-slate-700">Matière</label>
                            <input type="text" id="subject" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                        <div>
                            <label for="due-date" class="block text-sm font-medium text-slate-700">Date d'échéance</label>
                            <input type="date" id="due-date" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Couleur</label>
                        <div id="color-picker" class="flex flex-wrap gap-2"></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Tâches</label>
                        <div id="tasks-container" class="mt-2 space-y-2"></div>
                        <button type="button" id="add-task-btn" class="mt-2 text-sm text-indigo-600 hover:text-indigo-800 font-semibold flex items-center space-x-1">
                            <i class="ph ph-plus"></i>
                            <span>Ajouter une tâche</span>
                        </button>
                    </div>
                </div>
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" id="delete-homework-btn" class="bg-red-100 text-red-700 hover:bg-red-200 font-bold py-2 px-4 rounded-lg transition-colors hidden">Supprimer</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:shadow-lg transition-all">Sauvegarder</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="script.js"></script>
</body>
</html>
