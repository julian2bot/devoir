-- Ce schéma est conçu pour une base de données relationnelle comme MySQL ou MariaDB.
-- Il permet de stocker les utilisateurs, les devoirs, les tâches associées et le suivi individuel de complétion.

-- Table pour stocker les informations des utilisateurs
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL, -- Le mot de passe doit toujours être "hashé"
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table pour les devoirs
CREATE TABLE homework (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    subject VARCHAR(100),
    due_date DATE NOT NULL,
    color_hex VARCHAR(7) DEFAULT '#6366F1', -- Couleur par défaut
    created_by_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Table pour les tâches spécifiques à chaque devoir
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    homework_id INT NOT NULL,
    description TEXT NOT NULL,
    FOREIGN KEY (homework_id) REFERENCES homework(id) ON DELETE CASCADE -- Si un devoir est supprimé, ses tâches le sont aussi
);

-- Table de liaison pour suivre les tâches complétées par chaque utilisateur
-- C'est ici que la magie opère pour le suivi individuel.
CREATE TABLE task_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_id INT NOT NULL,
    is_completed BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    UNIQUE(user_id, task_id) -- Garantit qu'un utilisateur n'a qu'un seul statut par tâche
);

