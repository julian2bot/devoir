<?php

// Configuration et initialisation de la base de données SQLite
$db_file = 'devoirs.sqlite';
try {
    // Crée le fichier de base de données s'il n'existe pas
    $db = new PDO('sqlite:' . $db_file);
    // Active la gestion des erreurs et l'affichage des avertissements
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Active le support des contraintes de clé étrangère
    $db->exec('PRAGMA foreign_keys = ON;');
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

/**
 * Initialisation des tables
 */
function init_db($db) {
    $db->exec("
        -- Table des Devoirs (Assignments)
        CREATE TABLE IF NOT EXISTS assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            subject TEXT NOT NULL, -- Matière (ex: Math, Anglais)
            due_date TEXT NOT NULL, -- Date d'échéance (YYYY-MM-DD)
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $db->exec("
        -- Table des Tâches (Tasks)
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            assignment_id INTEGER NOT NULL,
            description TEXT NOT NULL,
            FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
        );
    ");

    $db->exec("
        -- Table de suivi des tâches par utilisateur (User Completion Status)
        CREATE TABLE IF NOT EXISTS user_tasks (
            task_id INTEGER NOT NULL,
            user_id TEXT NOT NULL,
            is_completed INTEGER DEFAULT 0, -- 0 ou 1
            PRIMARY KEY (task_id, user_id),
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        );
    ");
}

init_db($db);

// --- API Backend Logic (Handles AJAX POST requests) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    $action = $data['action'] ?? '';
    $user_id = $data['user_id'] ?? '';

    if (empty($user_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID manquant.']);
        exit;
    }

    try {
        switch ($action) {
            case 'add_assignment':
                $title = $data['title'] ?? '';
                $subject = $data['subject'] ?? '';
                $due_date = $data['due_date'] ?? '';
                $tasks_desc = $data['tasks'] ?? []; // Array of task descriptions

                if (empty($title) || empty($subject) || empty($due_date)) {
                    throw new Exception('Données de devoir manquantes.');
                }

                $db->beginTransaction();

                // 1. Ajouter le devoir
                $stmt = $db->prepare("INSERT INTO assignments (title, subject, due_date) VALUES (?, ?, ?)");
                $stmt->execute([$title, $subject, $due_date]);
                $assignment_id = $db->lastInsertId();

                // 2. Ajouter les tâches
                foreach ($tasks_desc as $desc) {
                    $stmt = $db->prepare("INSERT INTO tasks (assignment_id, description) VALUES (?, ?)");
                    $stmt->execute([$assignment_id, $desc]);
                }

                $db->commit();
                echo json_encode(['success' => true, 'assignment_id' => $assignment_id]);
                break;

            case 'get_assignments':
                $start_date = $data['start_date'] ?? null;
                $end_date = $data['end_date'] ?? null;
                $upcoming_only = $data['upcoming_only'] ?? false;
                $today = date('Y-m-d');
                $limit_date = date('Y-m-d', strtotime('+4 days')); // Pour le dashboard 3/4 jours

                $where_clauses = [];
                $params = [];
                $sql_filter = '';

                // Filtre pour le dashboard (Upcoming)
                if ($upcoming_only) {
                    $where_clauses[] = "a.due_date BETWEEN ? AND ?";
                    $params[] = $today;
                    $params[] = $limit_date;
                }
                // Filtre pour le calendrier (Date range)
                elseif ($start_date && $end_date) {
                    $where_clauses[] = "a.due_date BETWEEN ? AND ?";
                    $params[] = $start_date;
                    $params[] = $end_date;
                }
                // Si aucun filtre, on charge tout
                else {
                    // Charger tous les devoirs actifs (à partir d'il y a 6 mois par exemple, pour éviter de charger l'historique trop lointain)
                    $limit_history = date('Y-m-d', strtotime('-6 months'));
                    $where_clauses[] = "a.due_date >= ?";
                    $params[] = $limit_history;
                }

                if (!empty($where_clauses)) {
                    $sql_filter = ' WHERE ' . implode(' AND ', $where_clauses);
                }

                // Requête principale pour les devoirs
                $sql = "SELECT a.* FROM assignments a $sql_filter ORDER BY a.due_date ASC";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $assignments_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $assignments = [];
                foreach ($assignments_raw as $a) {
                    $assignment_id = $a['id'];

                    // Récupérer les tâches pour ce devoir
                    $stmt_tasks = $db->prepare("SELECT id, description FROM tasks WHERE assignment_id = ? ORDER BY id ASC");
                    $stmt_tasks->execute([$assignment_id]);
                    $tasks_raw = $stmt_tasks->fetchAll(PDO::FETCH_ASSOC);

                    $tasks = [];
                    foreach ($tasks_raw as $t) {
                        $task_id = $t['id'];

                        // Récupérer le statut de complétion pour l'utilisateur actuel
                        $stmt_status = $db->prepare("SELECT is_completed FROM user_tasks WHERE task_id = ? AND user_id = ?");
                        $stmt_status->execute([$task_id, $user_id]);
                        $status = $stmt_status->fetch(PDO::FETCH_ASSOC);

                        $tasks[] = [
                            'id' => $task_id,
                            'description' => $t['description'],
                            'is_completed' => $status ? (int)$status['is_completed'] : 0,
                        ];
                    }

                    $a['tasks'] = $tasks;
                    $assignments[] = $a;
                }

                echo json_encode(['success' => true, 'assignments' => $assignments, 'user_id' => $user_id]);
                break;

            case 'toggle_task_completion':
                $task_id = $data['task_id'] ?? 0;
                $is_completed = (int)($data['is_completed'] ?? 0);

                if (empty($task_id)) {
                    throw new Exception('ID de tâche manquant.');
                }

                // UPSERT (UPDATE or INSERT) :
                // Tente de mettre à jour. Si aucune ligne n'est affectée, on insère.
                $stmt = $db->prepare("UPDATE user_tasks SET is_completed = ? WHERE task_id = ? AND user_id = ?");
                $stmt->execute([$is_completed, $task_id, $user_id]);

                if ($stmt->rowCount() === 0) {
                    $stmt = $db->prepare("INSERT INTO user_tasks (task_id, user_id, is_completed) VALUES (?, ?, ?)");
                    $stmt->execute([$task_id, $user_id, $is_completed]);
                }

                echo json_encode(['success' => true, 'task_id' => $task_id, 'is_completed' => $is_completed]);
                break;

            case 'delete_assignment':
                $assignment_id = $data['assignment_id'] ?? 0;

                if (empty($assignment_id)) {
                    throw new Exception('ID de devoir manquant.');
                }

                // Utilisation de CASCADE DELETE configuré dans l'initialisation de la DB:
                // La suppression du devoir supprimera automatiquement toutes les tâches et les entrées user_tasks associées.
                $stmt = $db->prepare("DELETE FROM assignments WHERE id = ?");
                $stmt->execute([$assignment_id]);

                echo json_encode(['success' => true, 'assignment_id' => $assignment_id]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Action non reconnue.']);
                break;
        }

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DevoirFlow - Gestion Collaborative des Devoirs</title>
    <!-- Chargement de Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- Icônes Lucide -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7f9fb; }
        .assignment-card { transition: transform 0.2s, box-shadow 0.2s; }
        .assignment-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1); }
        .color-math { background-color: #e0f2fe; border-left: 4px solid #0ea5e9; } /* Blue */
        .color-anglais { background-color: #fef9c3; border-left: 4px solid #eab308; } /* Yellow */
        .color-histoire { background-color: #fee2e2; border-left: 4px solid #ef4444; } /* Red */
        .color-svt { background-color: #d1fae5; border-left: 4px solid #10b981; } /* Green */
        .color-general { background-color: #f3e8ff; border-left: 4px solid #8b5cf6; } /* Purple (Default) */
        /* Styles pour le calendrier (basique) */
        .calendar-day { min-height: 100px; }
        .today { background-color: #dbeafe !important; border: 2px solid #3b82f6; }
        .text-done { text-decoration: line-through; color: #6b7280; }
        .modal { transition: opacity 0.3s ease-in-out; }
    </style>
</head>
<body class="min-h-screen flex">

    <!-- Modale d'ajout de devoir -->
    <div id="addAssignmentModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-75 flex items-center justify-center opacity-0 pointer-events-none z-50">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 m-4 transform transition-all scale-95">
            <h3 class="text-2xl font-bold mb-4 text-gray-800 flex justify-between items-center">
                Ajouter un nouveau Devoir
                <button onclick="closeModal('addAssignmentModal')" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </h3>
            <form id="addAssignmentForm" onsubmit="handleAssignmentSubmit(event)">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="title">Titre du Devoir</label>
                    <input type="text" id="title" name="title" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="subject">Matière</label>
                        <select id="subject" name="subject" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="Math">Mathématiques</option>
                            <option value="Anglais">Anglais</option>
                            <option value="Histoire">Histoire/Géo</option>
                            <option value="SVT">SVT/Physique</option>
                            <option value="General">Autre</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="due_date">Date d'échéance</label>
                        <input type="date" id="due_date" name="due_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>

                <div class="mb-4">
                    <h4 class="text-lg font-semibold text-gray-700 mb-2 flex justify-between items-center">
                        Tâches à faire (étapes)
                        <button type="button" onclick="addTaskInput()" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium flex items-center">
                            <i data-lucide="plus-circle" class="w-4 h-4 mr-1"></i> Ajouter
                        </button>
                    </h4>
                    <div id="tasksContainer" class="space-y-2">
                        <!-- Les champs de tâches seront ajoutés ici -->
                        <div class="flex items-center space-x-2">
                            <input type="text" name="tasks[]" placeholder="Ex: Faire l'exercice 3 page 45" required class="w-full px-3 py-2 border border-gray-200 rounded-lg">
                            <button type="button" onclick="this.parentNode.remove()" class="text-gray-400 hover:text-red-500"><i data-lucide="trash-2" class="w-5 h-5"></i></button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('addAssignmentModal')" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">Annuler</button>
                    <button type="submit" id="addAssignmentBtn" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 flex items-center">
                        <i data-lucide="save" class="w-4 h-4 mr-2"></i> Enregistrer le Devoir
                    </button>
                </div>
            </form>
        </div>
    </div>


    <!-- Modale de Détails/Suppression de Devoir -->
    <div id="detailsAssignmentModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-75 flex items-center justify-center opacity-0 pointer-events-none z-50">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 m-4 transform transition-all scale-95">
            <div id="assignmentDetailsContent">
                <!-- Les détails et les tâches seront injectés ici par JS -->
            </div>
            <div class="flex justify-between items-center mt-6 pt-4 border-t border-gray-200">
                <button onclick="confirmDeleteAssignment()" id="deleteAssignmentBtn" class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 flex items-center">
                    <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i> Supprimer le Devoir
                </button>
                <button onclick="closeModal('detailsAssignmentModal')" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">Fermer</button>
            </div>
        </div>
    </div>

    <!-- Contenu Principal de l'Application -->
    <!-- Sidebar - Devoirs Urgents (Dashboard) -->
    <aside class="w-72 bg-white p-6 border-r border-gray-100 flex-shrink-0">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-extrabold text-indigo-700">DevoirFlow</h1>
        </div>

        <div class="mb-4 flex items-center text-sm text-gray-600">
            <i data-lucide="user" class="w-4 h-4 mr-2"></i>
            <span>Mon ID: <strong id="currentUserId" class="text-gray-800 font-mono text-xs break-all">...</strong></span>
        </div>

        <div class="mb-6">
            <button onclick="openModal('addAssignmentModal')" class="w-full flex items-center justify-center px-4 py-3 border border-transparent text-sm font-medium rounded-xl shadow-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150">
                <i data-lucide="plus" class="w-5 h-5 mr-2"></i> Ajouter un Devoir
            </button>
        </div>

        <h2 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
            <i data-lucide="bell" class="w-5 h-5 mr-2 text-red-500"></i> Urgent (4 Jours)
        </h2>
        <div id="upcomingAssignmentsList" class="space-y-3">
            <p class="text-sm text-gray-500">Chargement des devoirs...</p>
        </div>
    </aside>

    <!-- Main Content - Calendar View -->
    <main class="flex-grow p-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
            <i data-lucide="calendar" class="w-6 h-6 mr-2 text-indigo-600"></i> Calendrier des Devoirs
        </h2>
        <div id="calendar" class="bg-white rounded-xl shadow-lg p-4">
            <!-- Header du Calendrier -->
            <div class="flex justify-between items-center mb-4">
                <button onclick="changeMonth(-1)" class="p-2 rounded-full hover:bg-gray-100"><i data-lucide="chevron-left" class="w-6 h-6"></i></button>
                <h3 id="currentMonthYear" class="text-xl font-semibold text-gray-700">Mois Année</h3>
                <button onclick="changeMonth(1)" class="p-2 rounded-full hover:bg-gray-100"><i data-lucide="chevron-right" class="w-6 h-6"></i></button>
            </div>

            <!-- Jours de la semaine -->
            <div class="grid grid-cols-7 gap-1 text-center font-medium text-sm text-gray-500 mb-2">
                <div>Lun</div><div>Mar</div><div>Mer</div><div>Jeu</div><div>Ven</div><div>Sam</div><div>Dim</div>
            </div>

            <!-- Grille du Calendrier -->
            <div id="calendarGrid" class="grid grid-cols-7 gap-1">
                <!-- Les jours seront injectés ici par JS -->
            </div>
        </div>

        <!-- Section Liste (Détails) -->
        <h2 class="text-2xl font-bold text-gray-800 mt-10 mb-6 flex items-center">
            <i data-lucide="list-checks" class="w-6 h-6 mr-2 text-indigo-600"></i> Tous les Devoirs
        </h2>
        <div id="allAssignmentsList" class="space-y-4">
            <!-- Liste complète des devoirs -->
        </div>

    </main>

    <script>
        // Configuration de la base URL pour les appels API (le fichier actuel)
        const API_URL = 'index.php';

        // Variables d'état
        let assignments = []; // Tous les devoirs chargés
        let currentUserId = null;
        let currentMonth = new Date().getMonth();
        let currentYear = new Date().getFullYear();

        // Mapping des couleurs pour les matières
        const subjectColors = {
            'Math': 'color-math',
            'Anglais': 'color-anglais',
            'Histoire': 'color-histoire',
            'SVT': 'color-svt',
            'General': 'color-general'
        };

        /**
         * 1. Gestion de l'Identifiant Utilisateur Anonyme
         */
        function initUser() {
            let userId = localStorage.getItem('devoir_user_id');
            if (!userId) {
                // Générer un UUID simple
                userId = 'user-' + Date.now() + Math.floor(Math.random() * 9999);
                localStorage.setItem('devoir_user_id', userId);
            }
            currentUserId = userId;
            document.getElementById('currentUserId').textContent = userId;
        }

        /**
         * 2. Fonctions d'API (AJAX)
         */
        async function apiCall(action, payload = {}) {
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action, user_id: currentUserId, ...payload })
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ error: 'Erreur réseau ou du serveur PHP.' }));
                    console.error('Erreur API:', errorData);
                    alert(`Erreur: ${errorData.error || 'Une erreur inconnue est survenue.'}`);
                    return null;
                }

                const data = await response.json();
                if (!data.success) {
                    alert(`Échec de l'opération: ${data.error}`);
                    return null;
                }
                return data;

            } catch (error) {
                console.error('Erreur de la requête AJAX:', error);
                alert('Erreur de communication avec le serveur. Vérifiez la console.');
                return null;
            }
        }

        async function loadAssignments(upcomingOnly = false) {
            const data = await apiCall('get_assignments', { upcoming_only: upcomingOnly });
            if (data) {
                assignments = data.assignments;
                renderUpcomingAssignments(assignments);
                renderAllAssignmentsList(assignments);
                if (!upcomingOnly) {
                    renderCalendar(currentMonth, currentYear);
                }
            }
        }

        async function toggleTaskCompletion(taskId, isCompleted) {
            const data = await apiCall('toggle_task_completion', { task_id: taskId, is_completed: isCompleted });
            if (data) {
                // Mettre à jour l'état local sans recharger toute la liste (pour la fluidité)
                const task = assignments.flatMap(a => a.tasks).find(t => t.id === taskId);
                if (task) {
                    task.is_completed = isCompleted;
                    // Forcer la mise à jour des vues pour que le changement soit visible
                    renderUpcomingAssignments(assignments);
                    renderAllAssignmentsList(assignments);
                    // Pas besoin de re-rendre le calendrier car le statut n'y est pas affiché directement
                }
            }
        }

        /**
         * 3. Fonctions d'Interface Utilisateur (Rendu)
         */
        function getProgressBar(tasks) {
            if (tasks.length === 0) return { percent: 100, text: "Pas de tâches" };
            const completed = tasks.filter(t => t.is_completed === 1).length;
            const percent = Math.round((completed / tasks.length) * 100);
            return { percent, text: `${completed}/${tasks.length} complétées` };
        }

        function createAssignmentCard(assignment) {
            const colorClass = subjectColors[assignment.subject] || subjectColors['General'];
            const progress = getProgressBar(assignment.tasks);
            const isUrgent = new Date(assignment.due_date) < new Date(new Date().setDate(new Date().getDate() + 4));
            const isPastDue = new Date(assignment.due_date) < new Date(new Date().setHours(0, 0, 0, 0));

            return `
                <div class="assignment-card p-4 rounded-xl shadow-sm ${colorClass} ${isPastDue ? 'opacity-70' : ''} cursor-pointer" onclick="openDetailsModal(${assignment.id})">
                    <div class="flex justify-between items-start mb-2">
                        <h4 class="font-bold text-gray-800 text-base">${assignment.title}</h4>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full ${isUrgent && !isPastDue ? 'bg-red-500 text-white' : 'bg-white/70 text-gray-600'}">
                            ${assignment.subject}
                        </span>
                    </div>
                    <p class="text-xs text-gray-600 mb-3 flex items-center">
                        <i data-lucide="clock" class="w-3 h-3 mr-1"></i> Échéance: ${new Date(assignment.due_date).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })}
                    </p>

                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div class="h-2.5 rounded-full transition-all duration-500 ${progress.percent === 100 ? 'bg-green-500' : 'bg-indigo-500'}" style="width: ${progress.percent}%;"></div>
                    </div>
                    <p class="text-xs text-gray-600 mt-1">${progress.text}</p>
                </div>
            `;
        }

        function renderUpcomingAssignments(allAssignments) {
            const container = document.getElementById('upcomingAssignmentsList');
            container.innerHTML = '';
            const today = new Date();
            const fourDaysLater = new Date();
            fourDaysLater.setDate(today.getDate() + 4);

            const upcoming = allAssignments
                .filter(a => new Date(a.due_date) >= today && new Date(a.due_date) <= fourDaysLater)
                .sort((a, b) => new Date(a.due_date) - new Date(b.due_date));

            if (upcoming.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-500 p-2 bg-gray-50 rounded-lg">Aucun devoir urgent pour les 4 prochains jours. Bravo !</p>';
                return;
            }

            upcoming.forEach(a => {
                container.innerHTML += createAssignmentCard(a);
            });
            lucide.createIcons(); // Réinitialiser les icônes
        }

        function renderAllAssignmentsList(allAssignments) {
            const container = document.getElementById('allAssignmentsList');
            container.innerHTML = '';

            // Trier par date d'échéance
            const sortedAssignments = [...allAssignments].sort((a, b) => new Date(a.due_date) - new Date(b.due_date));

            if (sortedAssignments.length === 0) {
                 container.innerHTML = '<p class="text-lg text-gray-500 p-6 text-center">Aucun devoir enregistré. Utilisez le bouton "Ajouter un Devoir" pour commencer.</p>';
                return;
            }

            sortedAssignments.forEach(a => {
                const colorClass = subjectColors[a.subject] || subjectColors['General'];
                const progress = getProgressBar(a.tasks);

                let tasksHtml = a.tasks.map(t => `
                    <li class="flex items-center space-x-2 py-1">
                        <input type="checkbox" id="task-${t.id}" data-task-id="${t.id}" ${t.is_completed ? 'checked' : ''}
                               onclick="handleTaskToggle(${t.id}, this.checked)"
                               class="w-4 h-4 text-indigo-600 bg-gray-100 border-gray-300 rounded focus:ring-indigo-500">
                        <label for="task-${t.id}" class="text-sm ${t.is_completed ? 'text-done' : 'text-gray-700'}">${t.description}</label>
                    </li>
                `).join('');

                container.innerHTML += `
                    <div class="bg-white p-6 rounded-xl shadow-md border-l-4 ${colorClass.replace('color-', 'border-')}">
                        <div class="flex justify-between items-start mb-3">
                            <h3 class="text-xl font-bold text-gray-800">${a.title} (${a.subject})</h3>
                            <button onclick="openDetailsModal(${a.id})" class="text-indigo-600 hover:text-indigo-800 p-1 rounded-full hover:bg-indigo-50">
                                <i data-lucide="eye" class="w-5 h-5"></i>
                            </button>
                        </div>
                        <div class="text-sm text-gray-600 mb-4">
                            Échéance: <span class="font-medium text-gray-800">${new Date(a.due_date).toLocaleDateString('fr-FR', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
                        </div>
                        <div class="mb-4">
                            <div class="w-full bg-gray-200 rounded-full h-2.5">
                                <div class="h-2.5 rounded-full transition-all duration-500 ${progress.percent === 100 ? 'bg-green-500' : 'bg-indigo-500'}" style="width: ${progress.percent}%;"></div>
                            </div>
                            <p class="text-xs text-gray-600 mt-1">${progress.text} (Individuel)</p>
                        </div>
                        <h4 class="text-base font-semibold text-gray-700 mb-2">Tâches:</h4>
                        <ul class="space-y-1">${tasksHtml}</ul>
                    </div>
                `;
            });
            lucide.createIcons(); // Réinitialiser les icônes
        }

        /**
         * 4. Logique du Calendrier
         */
        const monthNames = ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"];

        function getDaysInMonth(month, year) {
            return new Date(year, month + 1, 0).getDate();
        }

        function getFirstDayOfMonth(month, year) {
            // Renvoie 0 pour Dimanche, 1 pour Lundi, etc. (ajusté pour commencer la semaine à Lundi)
            const day = new Date(year, month, 1).getDay();
            return (day === 0) ? 6 : day - 1;
        }

        function renderCalendar(month, year) {
            const container = document.getElementById('calendarGrid');
            const monthYearDisplay = document.getElementById('currentMonthYear');
            container.innerHTML = '';

            monthYearDisplay.textContent = `${monthNames[month]} ${year}`;

            const daysInMonth = getDaysInMonth(month, year);
            const firstDay = getFirstDayOfMonth(month, year);
            const todayDate = new Date().toISOString().split('T')[0];

            // Mapping des devoirs par date pour le mois affiché
            const assignmentsByDate = assignments.reduce((acc, a) => {
                const dateKey = a.due_date;
                if (!acc[dateKey]) { acc[dateKey] = []; }
                acc[dateKey].push(a);
                return acc;
            }, {});

            // Remplissage des jours vides au début
            for (let i = 0; i < firstDay; i++) {
                container.innerHTML += `<div class="p-2 border border-gray-100 rounded-lg bg-gray-50 calendar-day"></div>`;
            }

            // Remplissage des jours du mois
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const isToday = dateStr === todayDate;

                let dayContent = '';
                const dayAssignments = assignmentsByDate[dateStr] || [];

                dayAssignments.forEach(a => {
                    const colorClass = subjectColors[a.subject] || subjectColors['General'];
                    const progress = getProgressBar(a.tasks);
                    const progressBg = progress.percent === 100 ? 'bg-green-500' : 'bg-indigo-500';

                    dayContent += `
                        <div class="text-xs p-1 mt-1 rounded-md cursor-pointer text-gray-800 hover:shadow-lg transition ${colorClass.replace(/bg-.*-(\d+)/, 'bg-opacity-80')}" onclick="openDetailsModal(${a.id})">
                            <span class="font-semibold block">${a.title}</span>
                            <div class="h-1 rounded-full ${progressBg} mt-0.5" style="width: ${progress.percent}%;"></div>
                        </div>
                    `;
                });

                container.innerHTML += `
                    <div class="p-2 border border-gray-200 rounded-lg bg-white calendar-day ${isToday ? 'today' : ''}">
                        <div class="font-bold text-sm text-gray-800 mb-1">${day}</div>
                        ${dayContent}
                    </div>
                `;
            }

            // Remplissage des jours vides à la fin
            const totalCells = firstDay + daysInMonth;
            const remainingCells = 42 - totalCells; // 6 lignes x 7 jours = 42
            for (let i = 0; i < remainingCells && totalCells + i < 42; i++) {
                container.innerHTML += `<div class="p-2 border border-gray-100 rounded-lg bg-gray-50 calendar-day"></div>`;
            }
        }

        function changeMonth(delta) {
            currentMonth += delta;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            } else if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
            renderCalendar(currentMonth, currentYear);
        }

        /**
         * 5. Gestion des Modales et Formulaires
         */
        function openModal(id) {
            const modal = document.getElementById(id);
            modal.classList.remove('pointer-events-none', 'opacity-0');
            modal.classList.add('opacity-100');
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            modal.classList.remove('opacity-100');
            modal.classList.add('opacity-0', 'pointer-events-none');
            // Réinitialiser les formulaires
            if (id === 'addAssignmentModal') {
                document.getElementById('addAssignmentForm').reset();
                // Assurer qu'il y a au moins un champ de tâche
                document.getElementById('tasksContainer').innerHTML = '';
                addTaskInput();
            }
        }

        function addTaskInput(description = '') {
            const container = document.getElementById('tasksContainer');
            const newDiv = document.createElement('div');
            newDiv.className = 'flex items-center space-x-2';
            newDiv.innerHTML = `
                <input type="text" name="tasks[]" placeholder="Ex: Faire l'exercice 3 page 45" value="${description}" required class="w-full px-3 py-2 border border-gray-200 rounded-lg">
                <button type="button" onclick="this.parentNode.remove()" class="text-gray-400 hover:text-red-500"><i data-lucide="trash-2" class="w-5 h-5"></i></button>
            `;
            container.appendChild(newDiv);
            lucide.createIcons();
        }

        async function handleAssignmentSubmit(event) {
            event.preventDefault();

            const form = event.target;
            const title = form.title.value;
            const subject = form.subject.value;
            const due_date = form.due_date.value;
            const tasks = Array.from(form.elements['tasks[]']).map(input => input.value).filter(v => v.trim() !== '');

            const data = await apiCall('add_assignment', { title, subject, due_date, tasks });

            if (data) {
                closeModal('addAssignmentModal');
                await loadAssignments(); // Recharger toutes les données
            }
        }

        function openDetailsModal(assignmentId) {
            const assignment = assignments.find(a => a.id === assignmentId);
            if (!assignment) {
                alert("Devoir introuvable.");
                return;
            }

            const colorClass = subjectColors[assignment.subject] || subjectColors['General'];
            const progress = getProgressBar(assignment.tasks);

            let tasksHtml = assignment.tasks.map(t => `
                <li class="flex items-center space-x-3 py-2 border-b border-gray-100 last:border-b-0">
                    <input type="checkbox" id="detail-task-${t.id}" data-task-id="${t.id}" ${t.is_completed ? 'checked' : ''}
                           onclick="handleTaskToggle(${t.id}, this.checked)"
                           class="w-5 h-5 text-indigo-600 bg-gray-100 border-gray-300 rounded focus:ring-indigo-500">
                    <label for="detail-task-${t.id}" class="text-base ${t.is_completed ? 'text-done font-light text-gray-500' : 'text-gray-700'}">${t.description}</label>
                </li>
            `).join('');

            const content = document.getElementById('assignmentDetailsContent');
            content.innerHTML = `
                <div class="${colorClass.replace('color-', 'border-')} p-4 rounded-lg mb-4">
                    <h3 class="text-3xl font-extrabold text-gray-800">${assignment.title}</h3>
                    <p class="text-sm font-medium text-gray-600">${assignment.subject}</p>
                </div>
                <div class="mb-5">
                    <p class="text-md text-gray-700 flex items-center mb-2">
                        <i data-lucide="calendar" class="w-5 h-5 mr-2 text-indigo-500"></i>
                        Échéance: <span class="ml-2 font-semibold">${new Date(assignment.due_date).toLocaleDateString('fr-FR', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
                    </p>
                    <p class="text-md text-gray-700 flex items-center">
                        <i data-lucide="list-checks" class="w-5 h-5 mr-2 text-indigo-500"></i>
                        Progression personnelle: <span class="ml-2 font-semibold">${progress.text}</span>
                    </p>
                </div>

                <h4 class="text-xl font-bold text-gray-800 mb-3 border-b pb-2">Tâches Détaillées:</h4>
                <ul class="divide-y divide-gray-100">${tasksHtml}</ul>

                <input type="hidden" id="currentAssignmentId" value="${assignmentId}">
            `;
            lucide.createIcons();
            openModal('detailsAssignmentModal');
        }

        async function handleTaskToggle(taskId, isChecked) {
            const isCompleted = isChecked ? 1 : 0;
            // Appel API pour mettre à jour
            const data = await toggleTaskCompletion(taskId, isCompleted);

            if (data) {
                // Mettre à jour toutes les cases à cocher (dans les deux vues et la modale)
                document.querySelectorAll(`[data-task-id="${taskId}"]`).forEach(checkbox => {
                    checkbox.checked = isChecked;
                    const label = checkbox.nextElementSibling;
                    if (label) {
                        label.classList.toggle('text-done', isChecked);
                        label.classList.toggle('font-light', isChecked);
                        label.classList.toggle('text-gray-500', isChecked);
                        label.classList.toggle('text-gray-700', !isChecked);
                    }
                });
                // Re-rendre les listes pour mettre à jour les barres de progression
                renderUpcomingAssignments(assignments);
                renderAllAssignmentsList(assignments);

                // Optionnel: Mettre à jour le texte de progression dans la modale de détails
                const currentAssignmentId = document.getElementById('currentAssignmentId')?.value;
                if (currentAssignmentId) {
                    const assignment = assignments.find(a => a.id == currentAssignmentId);
                    if (assignment) {
                         const progress = getProgressBar(assignment.tasks);
                         document.querySelector('#assignmentDetailsContent p:nth-child(2) span:last-child').textContent = progress.text;
                    }
                }
            }
        }

        function confirmDeleteAssignment() {
            const assignmentId = document.getElementById('currentAssignmentId').value;
            if (confirm("Êtes-vous sûr de vouloir supprimer ce devoir et toutes ses tâches associées ? Cette action est irréversible et affectera tous les utilisateurs.")) {
                deleteAssignment(assignmentId);
            }
        }

        async function deleteAssignment(assignmentId) {
            const data = await apiCall('delete_assignment', { assignment_id: assignmentId });
            if (data) {
                closeModal('detailsAssignmentModal');
                await loadAssignments(); // Recharger toutes les données
            }
        }

        // --- Initialisation au chargement de la page ---
        window.onload = async function() {
            lucide.createIcons();
            initUser();
            await loadAssignments();
        }
    </script>
</body>
</html>
