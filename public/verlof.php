<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
setupDatabase();
require_once __DIR__ . '/includes/layout.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'aanvragen') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $start = $_POST['start_date'] ?? '';
        $end = $_POST['end_date'] ?? '';
        $type = $_POST['type'] ?? 'vakantie';
        $uren = (float)($_POST['uren'] ?? 0);
        $reden = trim($_POST['reden'] ?? '');
        $employee = $employeeId > 0 ? getEmployeeById($employeeId) : null;

        if ($employee && $start !== '' && $end !== '' && $uren > 0) {
            $stmt = $db->prepare("INSERT INTO tijd_verlof (employee_id, start_date, end_date, type, uren, status, reden) VALUES (?, ?, ?, ?, ?, 'aangevraagd', ?)");
            $stmt->execute([$employeeId, $start, $end, $type, $uren, $reden]);
        }
        header('Location: ' . BASE . '/verlof.php');
        exit;
    }

    if ($action === 'beoordelen') {
        // Alleen managers behandelen aanvragen (goed-/afkeuren).
        requireManager();
        $id = (int)($_POST['id'] ?? 0);
        $besluit = $_POST['besluit'] ?? '';
        if ($id > 0 && in_array($besluit, ['goedgekeurd', 'afgewezen'], true)) {
            $account = currentAccountEmployee();
            $stmt = $db->prepare("UPDATE tijd_verlof SET status = ?, behandeld_door = ? WHERE id = ?");
            $stmt->execute([$besluit, $account['name'] ?? 'Manager', $id]);

            // Bij goedkeuring: verlofsaldo van de medewerker verlagen (eenvoudige demo-boekhouding).
            if ($besluit === 'goedgekeurd') {
                $row = $db->prepare("SELECT employee_id, uren FROM tijd_verlof WHERE id = ?");
                $row->execute([$id]);
                $verlof = $row->fetch();
                if ($verlof) {
                    $upd = $db->prepare("UPDATE tijd_employees SET verlof_saldo_uren = GREATEST(0, verlof_saldo_uren - ?) WHERE id = ?");
                    $upd->execute([$verlof['uren'], $verlof['employee_id']]);
                }
            }
        }
        header('Location: ' . BASE . '/verlof.php');
        exit;
    }
}

$viewedId = viewedEmployeeId();
$account = currentAccountEmployee();
$employees = getEmployees();

$sql = "SELECT v.*, emp.name AS emp_name FROM tijd_verlof v LEFT JOIN tijd_employees emp ON emp.id = v.employee_id";
$params = [];
if ($viewedId !== null) {
    $sql .= ' WHERE v.employee_id = ?';
    $params[] = $viewedId;
}
$sql .= ' ORDER BY v.start_date DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$aanvragen = $stmt->fetchAll();

$badgeMap = ['aangevraagd' => 'hz-badge--orange', 'goedgekeurd' => 'hz-badge--green', 'afgewezen' => 'hz-badge--red'];

layoutStart('Verlof', 'verlof');
?>

<div class="hz-grid hz-grid--3" style="margin-bottom:1.25rem;">
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Openstaande aanvragen</div>
        <div class="hz-card__value"><?= count(array_filter($aanvragen, fn($a) => $a['status'] === 'aangevraagd')) ?></div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Goedgekeurd</div>
        <div class="hz-card__value"><?= count(array_filter($aanvragen, fn($a) => $a['status'] === 'goedgekeurd')) ?></div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Afgewezen</div>
        <div class="hz-card__value"><?= count(array_filter($aanvragen, fn($a) => $a['status'] === 'afgewezen')) ?></div>
    </div>
</div>

<div class="hz-card" style="margin-bottom:1.25rem;">
    <div class="hz-card__header">
        <strong>Nieuwe verlofaanvraag</strong>
    </div>
    <form method="POST" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:1rem; align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="aanvragen">
        <div class="hz-field" style="margin-bottom:0;">
            <select name="employee_id" required>
                <option value="">Kies medewerker...</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>" <?= $viewedId === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Medewerker</label>
        </div>
        <div class="hz-field" style="margin-bottom:0;">
            <input type="date" name="start_date" placeholder=" " required>
            <label>Startdatum</label>
        </div>
        <div class="hz-field" style="margin-bottom:0;">
            <input type="date" name="end_date" placeholder=" " required>
            <label>Einddatum</label>
        </div>
        <div class="hz-field" style="margin-bottom:0;">
            <select name="type">
                <option value="vakantie">Vakantie</option>
                <option value="ziek">Ziek</option>
                <option value="onbetaald">Onbetaald</option>
                <option value="bijzonder">Bijzonder verlof</option>
            </select>
            <label>Type</label>
        </div>
        <div class="hz-field" style="margin-bottom:0;">
            <input type="number" name="uren" min="1" step="0.5" value="8" placeholder=" " required>
            <label>Uren</label>
        </div>
        <div class="hz-field" style="margin-bottom:0;">
            <input type="text" name="reden" placeholder=" ">
            <label>Reden (optioneel)</label>
        </div>
        <button type="submit" class="hz-btn hz-btn--primary">Aanvragen</button>
    </form>
</div>

<div class="hz-card" style="padding:0; overflow:hidden;">
    <div style="overflow-x:auto;">
    <table class="hz-table">
        <thead>
            <tr>
                <th>Medewerker</th><th>Periode</th><th>Type</th><th>Uren</th><th>Status</th><th>Behandeld door</th><th style="text-align:right;">Acties</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($aanvragen)): ?>
            <tr><td colspan="7" style="text-align:center; color:var(--hz-text-muted); padding:2rem;">Geen verlofaanvragen gevonden.</td></tr>
        <?php else: foreach ($aanvragen as $a): ?>
            <tr>
                <td style="font-weight:600;"><?= e($a['emp_name'] ?? '') ?></td>
                <td><?= date('d-m-Y', strtotime($a['start_date'])) ?> t/m <?= date('d-m-Y', strtotime($a['end_date'])) ?></td>
                <td><span class="hz-badge hz-badge--gray"><?= e(ucfirst($a['type'])) ?></span></td>
                <td><?= (float)$a['uren'] ?>u</td>
                <td><span class="hz-badge <?= $badgeMap[$a['status']] ?? 'hz-badge--gray' ?>"><?= e(ucfirst($a['status'])) ?></span></td>
                <td><?= e($a['behandeld_door'] ?? '-') ?></td>
                <td>
                    <?php if ($a['status'] === 'aangevraagd'): ?>
                        <div style="display:flex; justify-content:flex-end; gap:.35rem;">
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="beoordelen">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="besluit" value="goedgekeurd">
                                <button type="submit" class="hz-btn hz-btn--secondary" style="padding:.3rem .6rem; color:var(--hz-success);">✓ Goedkeuren</button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="beoordelen">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="besluit" value="afgewezen">
                                <button type="submit" class="hz-btn hz-btn--secondary" style="padding:.3rem .6rem; color:var(--hz-danger);">✕ Afwijzen</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--hz-text-muted); font-size:.8rem;">Afgehandeld</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>
<p style="margin-top:1rem; font-size:.75rem; color:var(--hz-text-muted); text-align:center;">Goed-/afkeuren is beperkt tot managers. Demo-aanvragen worden elke <?= DEMO_RESET_MINUTES ?> minuten ververst.</p>

<?php layoutEnd(); ?>
