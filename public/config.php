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

/**
 * Maakt alle tabellen aan (idempotent) en migreert de bestaande tijd_entries-tabel
 * met een employee_id kolom, zodat medewerkers nu een echte tijd_employees-rij zijn
 * in plaats van enkel een losse naam-string.
 */
function setupDatabase(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS tijd_employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        role ENUM('medewerker','manager') NOT NULL DEFAULT 'medewerker',
        contract_uren_per_week DECIMAL(5,2) NOT NULL DEFAULT 40,
        verlof_saldo_uren DECIMAL(6,2) NOT NULL DEFAULT 200,
        mfa_secret VARCHAR(32) NULL,
        mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

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

    // Migratie voor een tabel die al zonder employee_id bestond (bestaande demo-installaties).
    $col = $db->query("
        SELECT COUNT(*) c FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'tijd_entries' AND column_name = 'employee_id'
    ")->fetch();
    if ((int)$col['c'] === 0) {
        $db->exec("ALTER TABLE tijd_entries ADD COLUMN employee_id INT NULL AFTER id");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS tijd_cao_regels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        regel_key VARCHAR(60) NOT NULL UNIQUE,
        label VARCHAR(150) NOT NULL,
        waarde DECIMAL(8,2) NOT NULL,
        eenheid VARCHAR(40) NOT NULL,
        omschrijving VARCHAR(255) NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS tijd_verlof (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        type VARCHAR(30) NOT NULL DEFAULT 'vakantie',
        uren DECIMAL(6,2) NOT NULL DEFAULT 0,
        status ENUM('aangevraagd','goedgekeurd','afgewezen') NOT NULL DEFAULT 'aangevraagd',
        reden VARCHAR(255) NULL,
        behandeld_door VARCHAR(150) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS tijd_integraties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        naam VARCHAR(60) NOT NULL UNIQUE,
        label VARCHAR(100) NOT NULL,
        categorie VARCHAR(30) NOT NULL,
        verbonden TINYINT(1) NOT NULL DEFAULT 0,
        laatste_sync DATETIME NULL,
        status_bericht VARCHAR(255) NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS tijd_settings (
        id INT PRIMARY KEY DEFAULT 1,
        last_reset DATETIME
    )");

    // Vaste instellingen/stamdata: alleen seeden als leeg (geen periodieke reset zoals de demo-uren-data).
    seedEmployeesIfEmpty();
    seedCaoRegelsIfEmpty();
    seedIntegratiesIfEmpty();

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

/** Medewerkers-stamdata: eenmalig aangemaakt, blijft bestaan over demo-resets heen
 *  (anders zou een MFA-koppeling of rol-instelling elke 30 min verdwijnen). */
function seedEmployeesIfEmpty(): void {
    $db = getDB();
    $count = $db->query("SELECT COUNT(*) c FROM tijd_employees")->fetch();
    if ((int)$count['c'] > 0) return;

    // (naam, e-mail, rol)
    $employees = [
        ['Sophie de Vries', 'sophie@demo.nl', 'medewerker'],
        ['Thomas Bakker', 'thomas@demo.nl', 'medewerker'],
        ['Emma Jansen', 'emma@demo.nl', 'medewerker'],
        ['Daan Visser', 'daan@demo.nl', 'medewerker'],
        ['Lisa Smit', 'lisa@demo.nl', 'medewerker'],
        ['Max Mulder', 'max@demo.nl', 'medewerker'],
        ['Fleur de Groot', 'fleur@demo.nl', 'manager'],
        ['Ruben van Dijk', 'ruben@demo.nl', 'medewerker'],
        ['Beheerder', ADMIN_EMAIL, 'manager'],
    ];

    $stmt = $db->prepare("INSERT INTO tijd_employees (name, email, role, contract_uren_per_week, verlof_saldo_uren) VALUES (?, ?, ?, ?, ?)");
    foreach ($employees as $emp) {
        $stmt->execute([$emp[0], $emp[1], $emp[2], 40, round(random_int(1200, 2400) / 10, 1)]);
    }
}

/** CAO-drempelwaarden zijn instelbaar via instellingen.php, niet hardcoded. */
function seedCaoRegelsIfEmpty(): void {
    $db = getDB();
    $count = $db->query("SELECT COUNT(*) c FROM tijd_cao_regels")->fetch();
    if ((int)$count['c'] > 0) return;

    $regels = [
        ['pauze_drempel_minuten', 'Verplichte pauze vanaf gewerkte tijd', 360, 'minuten', 'Vanaf hoeveel minuten aaneengesloten werk een pauze verplicht is.'],
        ['pauze_duur_minuten', 'Duur verplichte pauze', 30, 'minuten', 'Pauzeduur die wordt afgetrokken zodra de drempel is overschreden.'],
        ['normuren_per_week', 'Normuren per week', 40, 'uren', 'Standaard contracturen per week, gebruikt voor de overuren-berekening.'],
        ['overuren_toeslag_percentage', 'Overuren toeslag', 125, '% van uurloon', 'Percentage doorbetaling voor uren boven de normuren per week.'],
        ['ort_avond_start_uur', 'ORT avond start', 18, 'uur (0-23)', 'Vanaf dit uur geldt onregelmatigheidstoeslag.'],
        ['ort_avond_eind_uur', 'ORT avond eind', 6, 'uur (0-23)', 'Tot dit uur (de volgende ochtend) geldt onregelmatigheidstoeslag.'],
        ['ort_avond_percentage', 'ORT avond/nacht toeslag', 20, '% toeslag', 'Toeslagpercentage voor gewerkte uren in het avond/nacht-venster.'],
        ['ort_weekend_percentage', 'ORT weekend toeslag', 35, '% toeslag', 'Toeslagpercentage voor gewerkte uren op zaterdag/zondag.'],
    ];
    $stmt = $db->prepare("INSERT INTO tijd_cao_regels (regel_key, label, waarde, eenheid, omschrijving) VALUES (?, ?, ?, ?, ?)");
    foreach ($regels as $r) {
        $stmt->execute($r);
    }
}

/** Mock-integraties (HR/payroll/agenda) — puur ter demonstratie, geen echte API's. */
function seedIntegratiesIfEmpty(): void {
    $db = getDB();
    $count = $db->query("SELECT COUNT(*) c FROM tijd_integraties")->fetch();
    if ((int)$count['c'] > 0) return;

    $items = [
        ['exact', 'Exact Online (Payroll)', 'payroll'],
        ['xero', 'Xero (Payroll)', 'payroll'],
        ['nmbrs', 'Nmbrs (HR/Payroll)', 'payroll'],
        ['google_agenda', 'Google Agenda', 'agenda'],
        ['outlook_agenda', 'Outlook Agenda', 'agenda'],
    ];
    $stmt = $db->prepare("INSERT INTO tijd_integraties (naam, label, categorie, verbonden) VALUES (?, ?, ?, 0)");
    foreach ($items as $it) {
        $stmt->execute($it);
    }
}

/** Demo-data die elke DEMO_RESET_MINUTES ververst wordt: uren-registraties en verlofaanvragen. */
function seedData(): void {
    $db = getDB();
    seedEmployeesIfEmpty();

    $employeeRows = $db->query("SELECT id, name FROM tijd_employees ORDER BY id")->fetchAll();
    if (empty($employeeRows)) {
        return;
    }

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

    $db->exec("TRUNCATE TABLE tijd_entries");

    $now = new DateTime();
    $stmt = $db->prepare("INSERT INTO tijd_entries (employee_id, employee_name, project, clock_in, clock_out, duration_minutes, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");

    for ($i = 0; $i < 25; $i++) {
        $emp = $employeeRows[array_rand($employeeRows)];
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
            $emp['id'],
            $emp['name'],
            $proj,
            $clockIn->format('Y-m-d H:i:s'),
            $clockOut ? $clockOut->format('Y-m-d H:i:s') : null,
            $duration,
            $note,
        ]);
    }

    // Demo-verlofaanvragen, ook periodiek ververst.
    $db->exec("TRUNCATE TABLE tijd_verlof");
    $verlofTypes = ['vakantie', 'ziek', 'onbetaald', 'bijzonder'];
    $statuses = ['aangevraagd', 'goedgekeurd', 'afgewezen'];
    $stmtV = $db->prepare("INSERT INTO tijd_verlof (employee_id, start_date, end_date, type, uren, status, reden, behandeld_door) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    for ($i = 0; $i < 8; $i++) {
        $emp = $employeeRows[array_rand($employeeRows)];
        $startOffset = random_int(-10, 20);
        $start = (clone $now)->modify("{$startOffset} days");
        $days = random_int(1, 5);
        $end = (clone $start)->modify('+' . ($days - 1) . ' days');
        $type = $verlofTypes[array_rand($verlofTypes)];
        $status = $statuses[array_rand($statuses)];
        $stmtV->execute([
            $emp['id'],
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $type,
            $days * 8,
            $status,
            'Demo-aanvraag',
            $status !== 'aangevraagd' ? 'Beheerder' : null,
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

/** Pagina's/acties die uitsluitend voor de manager-rol bedoeld zijn (verlof goedkeuren,
 *  CAO-regels wijzigen, MFA-beheer). In deze demo is er één gedeeld beheerdersaccount
 *  dat als manager optreedt, maar de check leest echt uit tijd_employees.role. */
function requireManager(): void {
    requireAuth();
    $account = currentAccountEmployee();
    if (!$account || $account['role'] !== 'manager') {
        http_response_code(403);
        exit('Alleen voor managers.');
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

// ==========================================================================
// Medewerkers & rol-gebaseerde weergave
// ==========================================================================

function getEmployees(): array {
    return getDB()->query("SELECT * FROM tijd_employees ORDER BY (role = 'manager') DESC, name ASC")->fetchAll();
}

function getEmployeeById(int $id): ?array {
    $stmt = getDB()->prepare("SELECT * FROM tijd_employees WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getEmployeeByEmail(string $email): ?array {
    $stmt = getDB()->prepare("SELECT * FROM tijd_employees WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * De ingelogde sessie is één gedeeld beheerdersaccount (bewuste demo-vereenvoudiging:
 * er is geen aparte inlog per medewerker). We koppelen dit aan de 'Beheerder'-rij in
 * tijd_employees (e-mail = ADMIN_EMAIL, rol = manager) zodat MFA en rolcontroles
 * op een echte database-rij werken in plaats van op een losse sessievariabele.
 */
function currentAccountEmployee(): ?array {
    return getEmployeeByEmail(ADMIN_EMAIL);
}

/**
 * Medewerker waar de UI momenteel "als" naar kijkt (sidebar-selectie). Managers kunnen
 * wisselen tussen medewerkers/urenstaten; ?employee_id=0 betekent "alle medewerkers"
 * (team-overzicht). Simuleert het onderscheid medewerker- vs managerweergave binnen
 * één gedeeld demo-account.
 */
function viewedEmployeeId(): ?int {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_GET['employee_id'])) {
        $val = (int)$_GET['employee_id'];
        if ($val > 0) {
            $_SESSION['viewed_employee_id'] = $val;
        } else {
            unset($_SESSION['viewed_employee_id']);
        }
    }
    return isset($_SESSION['viewed_employee_id']) ? (int)$_SESSION['viewed_employee_id'] : null;
}

// ==========================================================================
// CAO-berekeningen (pauze, overuren, ORT) — instelbaar via tijd_cao_regels
// ==========================================================================

function getCaoRegels(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $rows = getDB()->query("SELECT regel_key, waarde FROM tijd_cao_regels")->fetchAll();
    $cache = [];
    foreach ($rows as $r) {
        $cache[$r['regel_key']] = (float)$r['waarde'];
    }
    return $cache;
}

/** Verplichte pauze (in minuten) die in mindering komt op een gewerkte periode. */
function calculateBreakMinutes(int $workedMinutes, array $regels): int {
    $drempel = $regels['pauze_drempel_minuten'] ?? 360.0;
    $duur = $regels['pauze_duur_minuten'] ?? 30.0;
    return $workedMinutes > $drempel ? (int)round($duur) : 0;
}

/** Overuren (in minuten) boven de ingestelde normuren per week. */
function calculateOvertimeMinutes(int $weekTotalMinutes, array $regels): int {
    $normUren = $regels['normuren_per_week'] ?? 40.0;
    $normMinuten = (int)round($normUren * 60);
    return max(0, $weekTotalMinutes - $normMinuten);
}

/**
 * Regel-gebaseerde ORT-schatting: telt hoeveel minuten van een shift binnen het
 * ingestelde avond/nacht-venster vallen en/of in het weekend liggen. Geen externe
 * loonregels, puur een indicatieve berekening voor de demo.
 */
function calculateORTMinutes(DateTime $clockIn, DateTime $clockOut, array $regels): array {
    $avondStart = (int)($regels['ort_avond_start_uur'] ?? 18);
    $avondEind = (int)($regels['ort_avond_eind_uur'] ?? 6);
    $avondNachtMinuten = 0;
    $weekendMinuten = 0;

    $cursor = clone $clockIn;
    $totalMinutes = max(0, (int)(($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 60));
    $totalMinutes = min($totalMinutes, 24 * 60); // demo-veiligheidslimiet

    for ($m = 0; $m < $totalMinutes; $m++) {
        $uur = (int)$cursor->format('G');
        $isAvondNacht = ($avondStart <= $avondEind)
            ? ($uur >= $avondStart && $uur < $avondEind)
            : ($uur >= $avondStart || $uur < $avondEind);
        $isWeekend = in_array((int)$cursor->format('N'), [6, 7], true);
        if ($isAvondNacht) {
            $avondNachtMinuten++;
        }
        if ($isWeekend) {
            $weekendMinuten++;
        }
        $cursor->modify('+1 minute');
    }

    return ['avond_nacht_minuten' => $avondNachtMinuten, 'weekend_minuten' => $weekendMinuten];
}

/** Open registraties waarvan de medewerker vermoedelijk vergeten is uit te klokken. */
function getForgottenClockouts(int $olderThanHours = 12): array {
    $stmt = getDB()->prepare("
        SELECT e.*, emp.name AS emp_display_name
        FROM tijd_entries e
        LEFT JOIN tijd_employees emp ON emp.id = e.employee_id
        WHERE e.clock_out IS NULL AND e.clock_in < (NOW() - INTERVAL ? HOUR)
        ORDER BY e.clock_in ASC
    ");
    $stmt->execute([$olderThanHours]);
    return $stmt->fetchAll();
}

/**
 * Eenvoudige regel-gebaseerde duur-schatting voor een (nieuw) project: gemiddelde
 * duur van afgeronde registraties op projecten met een vergelijkbare naam, met een
 * val-back naar het algemeen gemiddelde. Geen externe AI-call.
 */
function estimateProjectDurationMinutes(string $projectName): array {
    $db = getDB();
    $rows = $db->query("
        SELECT project, duration_minutes FROM tijd_entries WHERE clock_out IS NOT NULL
    ")->fetchAll();

    if (empty($rows)) {
        return ['gemiddelde_minuten' => 0, 'basis' => 'geen_data', 'vergelijkbaar_project' => null];
    }

    $perProject = [];
    foreach ($rows as $r) {
        $perProject[$r['project']][] = (int)$r['duration_minutes'];
    }

    $bestMatch = null;
    $bestScore = 0;
    foreach (array_keys($perProject) as $existing) {
        similar_text(mb_strtolower($projectName), mb_strtolower($existing), $pct);
        if ($pct > $bestScore) {
            $bestScore = $pct;
            $bestMatch = $existing;
        }
    }

    if ($bestMatch !== null && $bestScore >= 40.0) {
        $durations = $perProject[$bestMatch];
        $avg = array_sum($durations) / count($durations);
        return ['gemiddelde_minuten' => (int)round($avg), 'basis' => 'vergelijkbaar_project', 'vergelijkbaar_project' => $bestMatch];
    }

    $all = array_merge(...array_values($perProject));
    $avg = count($all) ? array_sum($all) / count($all) : 0;
    return ['gemiddelde_minuten' => (int)round($avg), 'basis' => 'algemeen_gemiddelde', 'vergelijkbaar_project' => null];
}

// ==========================================================================
// TOTP (RFC 6238) — compacte eigen implementatie voor MFA, geen dependency
// ==========================================================================

function base32Encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binaryString = '';
    foreach (str_split($data) as $char) {
        $binaryString .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($binaryString, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function base32Decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
    $binaryString = '';
    foreach (str_split($b32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            continue;
        }
        $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($binaryString, 8) as $byte) {
        if (strlen($byte) < 8) {
            continue;
        }
        $bytes .= chr(bindec($byte));
    }
    return $bytes;
}

function generateTOTPSecret(int $bytes = 20): string {
    return base32Encode(random_bytes($bytes));
}

/** RFC 6238 TOTP-code op basis van HMAC-SHA1 (RFC 4226), 30s-periode, 6 cijfers. */
function totpCode(string $secretBase32, ?int $timestamp = null, int $period = 30, int $digits = 6): string {
    $timestamp = $timestamp ?? time();
    $counter = intdiv($timestamp, $period);
    $binCounter = pack('N*', 0) . pack('N*', $counter); // 8 bytes big-endian counter
    $key = base32Decode($secretBase32);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    $code = $truncated % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/** Vergelijkt met een tijdvenster van +/- 1 periode om kloktolerantie op te vangen. */
function verifyTotp(string $secretBase32, string $code, int $window = 1, int $period = 30): bool {
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if ($code === '') {
        return false;
    }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCode($secretBase32, $now + ($i * $period), $period), $code)) {
            return true;
        }
    }
    return false;
}

// ==========================================================================
// Weergave-helpers (gedeeld door alle pagina's)
// ==========================================================================

function formatDuration(int $minutes): string {
    if ($minutes <= 0) return '-';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return "{$h}u {$m}m";
}

function formatDateTime(?string $dt): string {
    if (!$dt) return '-';
    return date('d-m-Y H:i', strtotime($dt));
}

function totpUri(string $secretBase32, string $accountLabel, string $issuer = 'Tijdregistratie Demo'): string {
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=6&period=30',
        rawurlencode($issuer),
        rawurlencode($accountLabel),
        $secretBase32,
        rawurlencode($issuer)
    );
}
