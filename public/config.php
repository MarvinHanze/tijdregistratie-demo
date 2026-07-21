<?php
declare(strict_types=1);

define('DB_HOST', 'y11ovnrne4yk4p9zbhe39tti');
define('DB_NAME', 'demos');
define('DB_USER', 'mysql');
define('DB_PASS', '23ns613Dyo1vgiAOQCt2ABFZzujOsxuyROvqNk4unUoZxWpwN9nIPrMNTt4QFkzG');
define('BASE', '/tijdregistratie');
define('DEMO_RESET_MINUTES', 30);
define('ADMIN_EMAIL', 'admin@demo.nl');
define('ADMIN_PASS_HASH', '$2y$10$W8b6Br7j9XjZfX/EPR7u3OkSihmDT3d9aKzVgSyX2MeHr1VIa1auG');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function setupDatabase(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS tijd_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_name VARCHAR(100) NOT NULL,
        project VARCHAR(100) NOT NULL,
        clock_in DATETIME NOT NULL,
        clock_out DATETIME NULL,
        duration_minutes INT DEFAULT 0,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS tijd_settings (
        id INT PRIMARY KEY DEFAULT 1,
        last_reset DATETIME
    )");

    $row = $db->query("SELECT last_reset FROM tijd_settings WHERE id = 1")->fetch();
    $needsReset = false;

    if (!$row) {
        $db->exec("INSERT INTO tijd_settings (id, last_reset) VALUES (1, NOW())");
        $needsReset = true;
    } else {
        $lastReset = new DateTime($row['last_reset']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $lastReset->getTimestamp();
        if ($diff > DEMO_RESET_MINUTES * 60) {
            $needsReset = true;
        }
    }

    if ($needsReset) {
        seedData();
        $db->exec("UPDATE tijd_settings SET last_reset = NOW() WHERE id = 1");
    }
}

function seedData(): void {
    $db = getDB();
    $db->exec("TRUNCATE TABLE tijd_entries");

    $employees = ['Sophie', 'Thomas', 'Emma', 'Daan', 'Lisa', 'Max', 'Fleur', 'Ruben'];
    $projects = ['Project Alpha', 'Website Redesign', 'App Development', 'Consulting', 'Training'];

    $notes = [
        'Sprint planning',
        'Klantoverleg',
        'Code review',
        'Bug fix',
        'Design mockup',
        'Documentatie',
        'Testing',
        'Refactor',
        'Deploy',
        'Overleg team',
    ];

    $now = new DateTime();
    $stmt = $db->prepare("INSERT INTO tijd_entries (employee_name, project, clock_in, clock_out, duration_minutes, notes) VALUES (?, ?, ?, ?, ?, ?)");

    for ($i = 0; $i < 25; $i++) {
        $emp = $employees[array_rand($employees)];
        $proj = $projects[array_rand($projects)];
        $note = $notes[array_rand($notes)];

        $daysAgo = random_int(0, 13);
        $hour = random_int(7, 16);
        $minute = random_int(0, 3) * 15;

        $clockIn = clone $now;
        $clockIn->modify("-{$daysAgo} days");
        $clockIn->setTime($hour, $minute);

        $isOpen = ($i < 4);
        $clockOut = null;
        $duration = 0;

        if (!$isOpen) {
            $workMinutes = random_int(120, 540);
            $clockOut = clone $clockIn;
            $clockOut->modify("+{$workMinutes} minutes");
            $duration = $workMinutes;
        }

        $stmt->execute([
            $emp,
            $proj,
            $clockIn->format('Y-m-d H:i:s'),
            $clockOut ? $clockOut->format('Y-m-d H:i:s') : null,
            $duration,
            $note,
        ]);
    }
}

function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['authenticated'])) {
        header('Location: ' . BASE . '/login.php');
        exit;
    }
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function generateCSRFToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(generateCSRFToken()) . '">';
}

function verifyCSRF(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Ongeldige aanvraag.');
    }
}
