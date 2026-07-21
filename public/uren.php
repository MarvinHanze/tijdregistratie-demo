<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
setupDatabase();

$db = getDB();

// --- Handle POST actions (handmatige correcties door manager) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $project = trim($_POST['project'] ?? '');
        $clockIn = $_POST['clock_in'] ?? '';
        $clockOut = !empty($_POST['clock_out']) ? $_POST['clock_out'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $employee = $employeeId > 0 ? getEmployeeById($employeeId) : null;

        if ($employee && $project !== '' && $clockIn !== '') {
            $duration = 0;
            if ($clockOut) {
                $dIn = new DateTime($clockIn);
                $dOut = new DateTime($clockOut);
                $diff = $dOut->diff($dIn);
                $duration = max(0, (int)$diff->i + (int)$diff->h * 60 + (int)$diff->days * 1440);
            }

            if ($action === 'add') {
                $stmt = $db->prepare("INSERT INTO tijd_entries (employee_id, employee_name, project, clock_in, clock_out, duration_minutes, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$employeeId, $employee['name'], $project, $clockIn, $clockOut, $duration, $notes]);
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE tijd_entries SET employee_id=?, employee_name=?, project=?, clock_in=?, clock_out=?, duration_minutes=?, notes=? WHERE id=?");
                    $stmt->execute([$employeeId, $employee['name'], $project, $clockIn, $clockOut, $duration, $notes, $id]);
                }
            }
        }
        header('Location: ' . BASE . '/uren.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM tijd_entries WHERE id = ?");
            $stmt->execute([$id]);
        }
        header('Location: ' . BASE . '/uren.php');
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
                    $diff = $now->diff($clockIn);
                    $duration = (int)$diff->i + (int)$diff->h * 60 + (int)$diff->days * 1440;
                    $stmt = $db->prepare("UPDATE tijd_entries SET clock_out=?, duration_minutes=? WHERE id=?");
                    $stmt->execute([$now->format('Y-m-d H:i:s'), $duration, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE tijd_entries SET clock_out=NULL, duration_minutes=0 WHERE id=?");
                    $stmt->execute([$id]);
                }
            }
        }
        header('Location: ' . BASE . '/uren.php');
        exit;
    }
}

// --- Filters: sidebar-selectie bepaalt de medewerker-scope (medewerker ziet alleen
//     eigen uren, manager kan "Alle medewerkers" kiezen voor het teamoverzicht) ---
$viewedId = viewedEmployeeId();
$search = trim($_GET['search'] ?? '');
$filterProject = $_GET['project'] ?? '';

$where = [];
$params = [];

if ($viewedId !== null) {
    $where[] = 'e.employee_id = ?';
    $params[] = $viewedId;
}
if ($search !== '') {
    $where[] = '(e.employee_name LIKE ? OR e.project LIKE ? OR e.notes LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filterProject !== '') {
    $where[] = 'e.project = ?';
    $params[] = $filterProject;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- CSV-export (server-side data-formatting, geen externe dienst) ---
if (($_GET['export'] ?? '') === 'csv') {
    $sql = "SELECT e.*, emp.name AS emp_display_name FROM tijd_entries e LEFT JOIN tijd_employees emp ON emp.id = e.employee_id {$whereSql} ORDER BY e.clock_in DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="urenregistratie_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM voor correcte weergave in Excel
    fputcsv($out, ['Medewerker', 'Project', 'Starttijd', 'Eindtijd', 'Duur (minuten)', 'Notities'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['emp_display_name'] ?? $r['employee_name'],
            $r['project'],
            $r['clock_in'],
            $r['clock_out'] ?? '',
            $r['duration_minutes'],
            $r['notes'],
        ], ';');
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/includes/layout.php';

$sql = "SELECT e.*, emp.name AS emp_display_name FROM tijd_entries e LEFT JOIN tijd_employees emp ON emp.id = e.employee_id {$whereSql} ORDER BY e.clock_in DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$employees = getEmployees();
$projects = $db->query("SELECT DISTINCT project FROM tijd_entries ORDER BY project")->fetchAll(PDO::FETCH_COLUMN);

$exportQuery = http_build_query(array_filter([
    'search' => $search,
    'project' => $filterProject,
    'export' => 'csv',
]));

layoutStart('Urenregistratie', 'uren');
?>

<div class="hz-card" style="margin-bottom:1.25rem;">
    <form method="GET" class="flex flex-col sm:flex-row gap-3" style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Zoeken op naam, project of notitie..."
               style="flex:1; min-width:200px; padding:.55rem .75rem; border:1px solid var(--hz-border); border-radius:var(--hz-radius); background:var(--hz-surface); color:var(--hz-text);">
        <select name="project" style="padding:.55rem .75rem; border:1px solid var(--hz-border); border-radius:var(--hz-radius); background:var(--hz-surface); color:var(--hz-text);">
            <option value="">Alle projecten</option>
            <?php foreach ($projects as $proj): ?>
                <option value="<?= e($proj) ?>" <?= $filterProject === $proj ? 'selected' : '' ?>><?= e($proj) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="hz-btn hz-btn--primary">Zoeken</button>
        <a href="<?= BASE ?>/uren.php" class="hz-btn hz-btn--secondary">Reset</a>
        <a href="?<?= $exportQuery ?>" class="hz-btn hz-btn--outline">⬇ CSV-export</a>
        <button type="button" class="hz-btn hz-btn--primary" style="margin-left:auto;" onclick="openModal()">+ Handmatige correctie</button>
    </form>
    <?php if ($viewedId !== null): $vEmp = getEmployeeById($viewedId); ?>
        <p style="font-size:.8rem; color:var(--hz-text-muted); margin-top:.6rem;">Gefilterd op medewerker: <strong><?= e($vEmp['name'] ?? '') ?></strong> — kies "Alle medewerkers" in de sidebar voor het teamoverzicht.</p>
    <?php endif; ?>
</div>

<div class="hz-card" style="padding:0; overflow:hidden;">
    <div style="overflow-x:auto;">
    <table class="hz-table">
        <thead>
            <tr>
                <th>Medewerker</th>
                <th>Project</th>
                <th>Starttijd</th>
                <th>Eindtijd</th>
                <th>Duur</th>
                <th>Notities</th>
                <th style="text-align:right;">Acties</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($entries)): ?>
            <tr><td colspan="7" style="text-align:center; color:var(--hz-text-muted); padding:2rem;">Geen registraties gevonden.</td></tr>
        <?php else: foreach ($entries as $entry): ?>
            <tr>
                <td style="font-weight:600;"><?= e($entry['emp_display_name'] ?? $entry['employee_name']) ?></td>
                <td><span class="hz-badge hz-badge--gray"><?= e($entry['project']) ?></span></td>
                <td><?= formatDateTime($entry['clock_in']) ?></td>
                <td>
                    <?php if ($entry['clock_out']): ?>
                        <?= formatDateTime($entry['clock_out']) ?>
                    <?php else: ?>
                        <span class="hz-badge hz-badge--orange">● Actief</span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:600;"><?= $entry['clock_out'] ? formatDuration((int)$entry['duration_minutes']) : '-' ?></td>
                <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= e($entry['notes'] ?? '') ?></td>
                <td>
                    <div style="display:flex; justify-content:flex-end; gap:.25rem;">
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
                            <button type="submit" class="hz-icon-btn" title="<?= $entry['clock_out'] ? 'Heropenen' : 'Stoppen' ?>">
                                <?= $entry['clock_out'] ? '▶' : '⏹' ?>
                            </button>
                        </form>
                        <button type="button" class="hz-icon-btn" title="Bewerken" onclick='editEntry(<?= json_encode($entry, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>✎</button>
                        <form method="POST" style="display:inline;" data-hz-confirm="Weet je zeker dat je deze registratie wilt verwijderen?">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
                            <button type="submit" class="hz-icon-btn" title="Verwijderen">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<p style="margin-top:1rem; font-size:.75rem; color:var(--hz-text-muted); text-align:center;">Demo data wordt elke <?= DEMO_RESET_MINUTES ?> minuten gereset.</p>

<!-- Modal: handmatige correctie -->
<div class="hz-modal__backdrop" id="modal">
    <div class="hz-modal">
        <div class="hz-modal__header">
            <h2 id="modalTitle" style="font-weight:700;">Handmatige correctie</h2>
            <button type="button" class="hz-icon-btn" onclick="closeModal()">✕</button>
        </div>
        <p style="font-size:.8rem; color:var(--hz-text-muted); margin-top:-.5rem; margin-bottom:1rem;">
            Voor correcties/nabewerking. Voor de dagelijkse klok in/uit: gebruik de knop op het Dashboard (server-tijd, niet handmatig aan te passen).
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId" value="">
            <div class="hz-field">
                <select name="employee_id" id="formEmployee" required>
                    <option value="">Kies medewerker...</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int)$emp['id'] ?>"><?= e($emp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Medewerker</label>
            </div>
            <div class="hz-field">
                <select name="project" id="formProject" required>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?= e($proj) ?>"><?= e($proj) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Project</label>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                <div class="hz-field">
                    <input type="datetime-local" name="clock_in" id="formClockIn" placeholder=" " required>
                    <label>Starttijd</label>
                </div>
                <div class="hz-field">
                    <input type="datetime-local" name="clock_out" id="formClockOut" placeholder=" ">
                    <label>Eindtijd</label>
                </div>
            </div>
            <div class="hz-field">
                <textarea name="notes" id="formNotes" rows="3" placeholder=" "></textarea>
                <label>Notities</label>
            </div>
            <div class="hz-modal__footer">
                <button type="button" class="hz-btn hz-btn--secondary" onclick="closeModal()">Annuleren</button>
                <button type="submit" class="hz-btn hz-btn--primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('modal').classList.add('hz-is-open');
    document.getElementById('modalTitle').textContent = 'Handmatige correctie toevoegen';
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('formEmployee').selectedIndex = 0;
    document.getElementById('formProject').selectedIndex = 0;
    document.getElementById('formClockIn').value = '';
    document.getElementById('formClockOut').value = '';
    document.getElementById('formNotes').value = '';
}
function editEntry(entry) {
    document.getElementById('modal').classList.add('hz-is-open');
    document.getElementById('modalTitle').textContent = 'Registratie bewerken';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = entry.id;
    document.getElementById('formEmployee').value = entry.employee_id || '';
    document.getElementById('formProject').value = entry.project;
    document.getElementById('formClockIn').value = entry.clock_in ? entry.clock_in.replace(' ', 'T') : '';
    document.getElementById('formClockOut').value = entry.clock_out ? entry.clock_out.replace(' ', 'T') : '';
    document.getElementById('formNotes').value = entry.notes || '';
}
function closeModal() {
    document.getElementById('modal').classList.remove('hz-is-open');
}
</script>

<?php layoutEnd(); ?>
