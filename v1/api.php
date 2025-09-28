<?php
header('Content-Type: application/json');
session_start();
require_once 'db.php';

// Sécurité : Vérifier si l'utilisateur est authentifié
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Accès non autorisé']);
    http_response_code(401);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_all_homework':
            // Récupérer tous les devoirs
            $stmt_hw = $pdo->query('SELECT h.*, u.username as created_by FROM homework h JOIN users u ON h.created_by_id = u.id ORDER BY h.due_date ASC');
            $homeworks = $stmt_hw->fetchAll();

            // Récupérer toutes les tâches et le statut pour l'utilisateur actuel
            $stmt_tasks = $pdo->prepare('
                SELECT t.*, ts.is_completed 
                FROM tasks t 
                LEFT JOIN task_status ts ON t.id = ts.task_id AND ts.user_id = ?
            ');
            $stmt_tasks->execute([$user_id]);
            $all_tasks = $stmt_tasks->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

            // Associer les tâches aux devoirs
            foreach ($homeworks as &$hw) {
                $hw['tasks'] = $all_tasks[$hw['id']] ?? [];
            }

            echo json_encode(['status' => 'success', 'data' => $homeworks]);
            break;

        case 'save_homework':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? null;
            
            // Validation simple
            if (empty($data['title']) || empty($data['dueDate']) || empty($data['color'])) {
                 throw new Exception("Données manquantes.");
            }

            if ($id) { // Mise à jour
                $stmt = $pdo->prepare('UPDATE homework SET title = ?, subject = ?, due_date = ?, color = ? WHERE id = ?');
                $stmt->execute([$data['title'], $data['subject'], $data['dueDate'], $data['color'], $id]);
            } else { // Création
                $stmt = $pdo->prepare('INSERT INTO homework (title, subject, due_date, color, created_by_id) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$data['title'], $data['subject'], $data['dueDate'], $data['color'], $user_id]);
                $id = $pdo->lastInsertId();
            }

            // Gérer les tâches
            $stmt = $pdo->prepare('DELETE FROM tasks WHERE homework_id = ?');
            $stmt->execute([$id]);

            if (!empty($data['tasks'])) {
                $stmt = $pdo->prepare('INSERT INTO tasks (homework_id, description) VALUES (?, ?)');
                foreach ($data['tasks'] as $task) {
                    if (!empty($task['description'])) {
                        $stmt->execute([$id, $task['description']]);
                    }
                }
            }
            
            echo json_encode(['status' => 'success', 'message' => 'Devoir sauvegardé.']);
            break;
            
        case 'delete_homework':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? null;
            if ($id) {
                $stmt = $pdo->prepare('DELETE FROM homework WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['status' => 'success', 'message' => 'Devoir supprimé.']);
            } else {
                 throw new Exception("ID manquant pour la suppression.");
            }
            break;

        case 'toggle_task':
            $data = json_decode(file_get_contents('php://input'), true);
            $task_id = $data['taskId'];
            $is_completed = $data['isCompleted'] ? 1 : 0;
            
            // INSERT ... ON DUPLICATE KEY UPDATE est atomique et efficace
            $stmt = $pdo->prepare('
                INSERT INTO task_status (user_id, task_id, is_completed) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE is_completed = ?
            ');
            $stmt->execute([$user_id, $task_id, $is_completed, $is_completed]);

            echo json_encode(['status' => 'success']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Action non reconnue']);
            http_response_code(400);
            break;
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    http_response_code(500);
}
?>
