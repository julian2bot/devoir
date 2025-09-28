SELECT user, host, plugin FROM mysql.user;


CREATE USER 'appuser'@'localhost' IDENTIFIED BY 'password123';
GRANT ALL PRIVILEGES ON agenda_devoirs.* TO 'appuser'@'localhost';
FLUSH PRIVILEGES;

$host = 'localhost';
$user = 'appuser';
$password = 'password123';
$dbname = 'agenda_devoirs';