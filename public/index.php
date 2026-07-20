<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
setupDatabase();

$db = getDB();

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $employee = trim($_POST['employee_name'] ?? '');
        $project = trim($_POST['project'] ?? '');
        $clockIn = $_POST['clock_in'] ?? '';
        $clockOut = !empty($_POST['clock_out']) ? $_POST['clock_out'] : null;
        $notes = trim($_POST['notes'] ?? '');

        if ($employee !== '' && $project !== '' && $clockIn !== '') {
            $duration = 0;
            if ($clockOut) {
                $dIn = new DateTime($clockIn);
                $dOut = new DateTime($clockOut);
                $duration = max(0, (int)$dOut->diff($dIn)->i + (int)$dOut->diff($dIn)->h * 60 + (int)$dOut->diff($dIn)->d * 1440);
            }

            if ($action === 'add') {
                $stmt = $db->prepare("INSERT INTO tijd_entries (employee_name, project, clock_in, clock_out, duration_minutes, notes) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$employee, $project, $clockIn, $clockOut, $duration, $notes]);
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE tijd_entries SET employee_name=?, project=?, clock_in=?, clock_out=?, duration_minutes=?, notes=? WHERE id=?");
                    $stmt->execute([$employee, $project, $clockIn, $clockOut, $duration, $notes, $id]);
                }
            }
        }
        header('Location: ' . BASE . '/index.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM tijd_entries WHERE id = ?");
            $stmt->execute([$id]);
        }
        header('Location: ' . BASE . '/index.php');
        exit;
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $db->prepare("SELECT clock_in, clock_out FROM tijd_entries WHERE id = ?");
            $row->execute([$id]);
            $entry = $row->fetch();
            if ($entry) {
                if ($entry['clock_out'] === null) {
                    $now = new DateTime();
                    $clockIn = new DateTime($entry['clock_in']);
                    $duration = (int)$now->diff($clockIn)->i + (int)$now->diff($clockIn)->h * 60 + (int)$now->diff($clockIn)->d * 1440;
                    $stmt = $db->prepare("UPDATE tijd_entries SET clock_out=?, duration_minutes=? WHERE id=?");
                    $stmt->execute([$now->format('Y-m-d H:i:s'), $duration, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE tijd_entries SET clock_out=NULL, duration_minutes=0 WHERE id=?");
                    $stmt->execute([$id]);
                }
            }
        }
        header('Location: ' . BASE . '/index.php');
        exit;
    }
}

// --- Fetch data ---
$search = trim($_GET['search'] ?? '');
$filterEmployee = $_GET['employee'] ?? '';
$filterProject = $_GET['project'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(employee_name LIKE ? OR project LIKE ? OR notes LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filterEmployee !== '') {
    $where[] = "employee_name = ?";
    $params[] = $filterEmployee;
}
if ($filterProject !== '') {
    $where[] = "project = ?";
    $params[] = $filterProject;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$sql = "SELECT * FROM tijd_entries {$whereSql} ORDER BY clock_in DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Stats
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));

$statToday = $db->query("SELECT COALESCE(SUM(duration_minutes), 0) as total FROM tijd_entries WHERE DATE(clock_in) = '{$today}'")->fetch();
$statWeek = $db->query("SELECT COALESCE(SUM(duration_minutes), 0) as total FROM tijd_entries WHERE DATE(clock_in) >= '{$weekStart}'")->fetch();
$statActive = $db->query("SELECT COUNT(*) as total FROM tijd_entries WHERE clock_out IS NULL")->fetch();
$statProjects = $db->query("SELECT COUNT(DISTINCT project) as total FROM tijd_entries")->fetch();

$employees = $db->query("SELECT DISTINCT employee_name FROM tijd_entries ORDER BY employee_name")->fetchAll(PDO::FETCH_COLUMN);
$projects = $db->query("SELECT DISTINCT project FROM tijd_entries ORDER BY project")->fetchAll(PDO::FETCH_COLUMN);

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
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tijdregistratie Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50">

<!-- Navbar -->
<nav class="bg-white shadow-sm border-b border-slate-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <div class="flex items-center gap-3">
                <svg class="w-7 h-7 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                <span class="text-xl font-bold text-slate-800">Tijdregistratie</span>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-sm text-slate-500"><?= e($_SESSION['user'] ?? '') ?></span>
                <a href="<?= BASE ?>/logout.php" class="text-sm text-slate-500 hover:text-red-500 transition">
                    <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Uitloggen
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wide">Vandaag Gewerkt</p>
                    <p class="text-xl font-bold text-slate-800"><?= formatDuration((int)$statToday['total']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wide">Deze Week</p>
                    <p class="text-xl font-bold text-slate-800"><?= formatDuration((int)$statWeek['total']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wide">Actieve Timers</p>
                    <p class="text-xl font-bold text-slate-800"><?= (int)$statActive['total'] ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
                    <svg class="w-5 h-5 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                        <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wide">Totaal Projecten</p>
                    <p class="text-xl font-bold text-slate-800"><?= (int)$statProjects['total'] ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6">
        <form method="GET" class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input
                        type="text"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Zoeken..."
                        class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm"
                    >
                </div>
            </div>
            <select name="employee" class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <option value="">Alle medewerkers</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= e($emp) ?>" <?= $filterEmployee === $emp ? 'selected' : '' ?>><?= e($emp) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="project" class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <option value="">Alle projecten</option>
                <?php foreach ($projects as $proj): ?>
                    <option value="<?= e($proj) ?>" <?= $filterProject === $proj ? 'selected' : '' ?>><?= e($proj) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white text-sm font-medium rounded-lg transition">
                Zoeken
            </button>
            <a href="<?= BASE ?>/index.php" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-lg transition text-center">
                Reset
            </a>
        </form>
    </div>

    <!-- Add button -->
    <div class="flex justify-end mb-4">
        <button onclick="openModal()" class="flex items-center gap-2 px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white text-sm font-medium rounded-lg transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Nieuwe Registratie
        </button>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="text-left px-4 py-3 font-medium text-slate-600">Medewerker</th>
                        <th class="text-left px-4 py-3 font-medium text-slate-600">Project</th>
                        <th class="text-left px-4 py-3 font-medium text-slate-600">Starttijd</th>
                        <th class="text-left px-4 py-3 font-medium text-slate-600">Eindtijd</th>
                        <th class="text-left px-4 py-3 font-medium text-slate-600">Duur</th>
                        <th class="text-left px-4 py-3 font-medium text-slate-600">Notities</th>
                        <th class="text-right px-4 py-3 font-medium text-slate-600">Acties</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-400">Geen registraties gevonden.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $entry): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 font-medium text-slate-800"><?= e($entry['employee_name']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                        <?= e($entry['project']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-600"><?= formatDateTime($entry['clock_in']) ?></td>
                                <td class="px-4 py-3 text-slate-600">
                                    <?php if ($entry['clock_out']): ?>
                                        <?= formatDateTime($entry['clock_out']) ?>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 text-amber-600 font-medium">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 8 8">
                                                <circle cx="4" cy="4" r="4"/>
                                            </svg>
                                            Actief
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-800">
                                    <?php if ($entry['clock_out']): ?>
                                        <?= formatDuration((int)$entry['duration_minutes']) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-slate-500 max-w-[200px] truncate"><?= e($entry['notes']) ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
                                            <button type="submit" title="<?= $entry['clock_out'] ? 'Heropenen' : 'Stoppen' ?>" class="p-1.5 rounded-lg hover:bg-slate-100 transition <?= $entry['clock_out'] ? 'text-emerald-500' : 'text-red-500' ?>">
                                                <?php if ($entry['clock_out']): ?>
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                        <polygon points="5 3 19 12 5 21 5 3"/>
                                                    </svg>
                                                <?php else: ?>
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                        <rect x="6" y="4" width="4" height="16"/>
                                                        <rect x="14" y="4" width="4" height="16"/>
                                                    </svg>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                        <button onclick="editEntry(<?= e(json_encode($entry)) ?>)" title="Bewerken" class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-blue-500 transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                            </svg>
                                        </button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Weet je zeker dat je deze registratie wilt verwijderen?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
                                            <button type="submit" title="Verwijderen" class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-red-500 transition">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <polyline points="3 6 5 6 21 6"/>
                                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Data refreshes every 30 min (demo reset) -->
    <p class="mt-4 text-xs text-slate-400 text-center">Demo data wordt elke <?= DEMO_RESET_MINUTES ?> minuten gereset.</p>
</div>

<!-- Modal -->
<div id="modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-lg bg-white rounded-xl shadow-2xl p-6">
        <div class="flex items-center justify-between mb-5">
            <h2 id="modalTitle" class="text-lg font-bold text-slate-800">Nieuwe Registratie</h2>
            <button onclick="closeModal()" class="p-1 rounded-lg hover:bg-slate-100 text-slate-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId" value="">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Medewerker</label>
                <input type="text" name="employee_name" id="formEmployee" required
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm"
                    placeholder="Naam medewerker">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Project</label>
                <select name="project" id="formProject" required
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm">
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?= e($proj) ?>"><?= e($proj) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Starttijd</label>
                    <input type="datetime-local" name="clock_in" id="formClockIn" required
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Eindtijd</label>
                    <input type="datetime-local" name="clock_out" id="formClockOut"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Notities</label>
                <textarea name="notes" id="formNotes" rows="3"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm"
                    placeholder="Optioneel..."></textarea>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-medium text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition">
                    Annuleren
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-500 hover:bg-blue-600 rounded-lg transition">
                    Opslaan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('modal').classList.remove('hidden');
    document.getElementById('modalTitle').textContent = 'Nieuwe Registratie';
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('formEmployee').value = '';
    document.getElementById('formProject').selectedIndex = 0;
    document.getElementById('formClockIn').value = '';
    document.getElementById('formClockOut').value = '';
    document.getElementById('formNotes').value = '';
}

function editEntry(entry) {
    document.getElementById('modal').classList.remove('hidden');
    document.getElementById('modalTitle').textContent = 'Registratie Bewerken';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = entry.id;
    document.getElementById('formEmployee').value = entry.employee_name;
    document.getElementById('formProject').value = entry.project;
    document.getElementById('formClockIn').value = entry.clock_in ? entry.clock_in.replace(' ', 'T') : '';
    document.getElementById('formClockOut').value = entry.clock_out ? entry.clock_out.replace(' ', 'T') : '';
    document.getElementById('formNotes').value = entry.notes || '';
}

function closeModal() {
    document.getElementById('modal').classList.add('hidden');
}
</script>

</body>
</html>
