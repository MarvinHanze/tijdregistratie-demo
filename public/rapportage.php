<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireManager();
setupDatabase();

$db = getDB();
$regels = getCaoRegels();

$vanaf = $_GET['vanaf'] ?? date('Y-m-01');
$tot = $_GET['tot'] ?? date('Y-m-d');

$stmt = $db->prepare("
    SELECT e.*, emp.name AS emp_name, emp.contract_uren_per_week AS emp_contract_uren FROM tijd_entries e
    LEFT JOIN tijd_employees emp ON emp.id = e.employee_id
    WHERE DATE(e.clock_in) BETWEEN ? AND ?
    ORDER BY e.clock_in ASC
");
$stmt->execute([$vanaf, $tot]);
$entries = $stmt->fetchAll();

// --- Aggregatie per medewerker ---
$perEmployee = [];
$perEmployeeWeekMinutes = []; // [employeeId][isoYearWeek] = minuten, voor overuren-berekening
foreach ($entries as $entry) {
    $name = $entry['emp_name'] ?? $entry['employee_name'];
    $empId = $entry['employee_id'] ?? 0;
    if (!isset($perEmployee[$empId])) {
        $contractUren = $entry['emp_contract_uren'] !== null ? (float)$entry['emp_contract_uren'] : null;
        $perEmployee[$empId] = ['naam' => $name, 'minuten' => 0, 'ort_avond' => 0, 'ort_weekend' => 0, 'registraties' => 0, 'contract_uren' => $contractUren];
    }
    $perEmployee[$empId]['minuten'] += (int)$entry['duration_minutes'];
    $perEmployee[$empId]['registraties']++;

    if ($entry['clock_out']) {
        $in = new DateTime($entry['clock_in']);
        $out = new DateTime($entry['clock_out']);
        $ort = calculateORTMinutes($in, $out, $regels);
        $perEmployee[$empId]['ort_avond'] += $ort['avond_nacht_minuten'];
        $perEmployee[$empId]['ort_weekend'] += $ort['weekend_minuten'];

        $weekKey = $in->format('o-W');
        $perEmployeeWeekMinutes[$empId][$weekKey] = ($perEmployeeWeekMinutes[$empId][$weekKey] ?? 0) + (int)$entry['duration_minutes'];
    }
}
foreach ($perEmployee as $empId => &$row) {
    $overtime = 0;
    foreach ($perEmployeeWeekMinutes[$empId] ?? [] as $weekMinutes) {
        $overtime += calculateOvertimeMinutes($weekMinutes, $regels, $row['contract_uren']);
    }
    $row['overuren_minuten'] = $overtime;
}
unset($row);

// --- Aggregatie per project ---
$perProject = [];
foreach ($entries as $entry) {
    $proj = $entry['project'];
    if (!isset($perProject[$proj])) {
        $perProject[$proj] = ['minuten' => 0, 'medewerkers' => [], 'registraties' => 0];
    }
    $perProject[$proj]['minuten'] += (int)$entry['duration_minutes'];
    $perProject[$proj]['registraties']++;
    $empKey = $entry['employee_id'] ?? $entry['employee_name'];
    $perProject[$proj]['medewerkers'][$empKey] = true;
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rapportage_' . $vanaf . '_' . $tot . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Type', 'Naam', 'Totaal uren', 'Overuren', 'ORT avond/nacht (u)', 'ORT weekend (u)', 'Registraties'], ';');
    foreach ($perEmployee as $row) {
        fputcsv($out, [
            'Medewerker', $row['naam'],
            round($row['minuten'] / 60, 2),
            round($row['overuren_minuten'] / 60, 2),
            round($row['ort_avond'] / 60, 2),
            round($row['ort_weekend'] / 60, 2),
            $row['registraties'],
        ], ';');
    }
    foreach ($perProject as $naam => $row) {
        fputcsv($out, ['Project', $naam, round($row['minuten'] / 60, 2), '', '', '', $row['registraties']], ';');
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/includes/layout.php';
layoutStart('Rapportages', 'rapportage');

$totaalMinuten = array_sum(array_column($entries, 'duration_minutes'));
?>

<div class="hz-card" style="margin-bottom:1.25rem;">
    <form method="GET" style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:end;">
        <div class="hz-field" style="margin-bottom:0;">
            <input type="date" name="vanaf" value="<?= e($vanaf) ?>" placeholder=" " required>
            <label>Vanaf</label>
        </div>
        <div class="hz-field" style="margin-bottom:0;">
            <input type="date" name="tot" value="<?= e($tot) ?>" placeholder=" " required>
            <label>Tot en met</label>
        </div>
        <button type="submit" class="hz-btn hz-btn--primary">Filteren</button>
        <a href="?vanaf=<?= e($vanaf) ?>&tot=<?= e($tot) ?>&export=csv" class="hz-btn hz-btn--outline"><?= hz_icon('download') ?> CSV-export</a>
    </form>
</div>

<div class="hz-grid hz-grid--3" style="margin-bottom:1.25rem;">
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Totaal gewerkte uren (periode)</div>
        <div class="hz-card__value"><?= formatDuration((int)$totaalMinuten) ?></div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Aantal registraties</div>
        <div class="hz-card__value"><?= count($entries) ?></div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Actieve projecten</div>
        <div class="hz-card__value"><?= count($perProject) ?></div>
    </div>
</div>

<div class="hz-card" style="padding:0; overflow:hidden; margin-bottom:1.25rem;">
    <div style="padding:1rem 1.25rem 0;"><strong>Uren per medewerker</strong></div>
    <div style="overflow-x:auto;">
    <table class="hz-table">
        <thead><tr><th>Medewerker</th><th>Totaal</th><th>Overuren (week-normering)</th><th>ORT avond/nacht</th><th>ORT weekend</th><th>Registraties</th></tr></thead>
        <tbody>
        <?php if (empty($perEmployee)): ?>
            <tr><td colspan="6" style="text-align:center; color:var(--hz-text-muted); padding:1.5rem;">Geen data in deze periode.</td></tr>
        <?php else: foreach ($perEmployee as $row): ?>
            <tr>
                <td style="font-weight:600;"><?= e($row['naam']) ?></td>
                <td><?= formatDuration((int)$row['minuten']) ?></td>
                <td><?= $row['overuren_minuten'] > 0 ? '<span class="hz-badge hz-badge--orange">' . formatDuration((int)$row['overuren_minuten']) . '</span>' : '-' ?></td>
                <td><?= formatDuration((int)$row['ort_avond']) ?></td>
                <td><?= formatDuration((int)$row['ort_weekend']) ?></td>
                <td><?= $row['registraties'] ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="hz-card" style="padding:0; overflow:hidden;">
    <div style="padding:1rem 1.25rem 0;"><strong>Uren per project</strong></div>
    <div style="overflow-x:auto;">
    <table class="hz-table">
        <thead><tr><th>Project</th><th>Totaal uren</th><th>Aantal medewerkers</th><th>Registraties</th></tr></thead>
        <tbody>
        <?php if (empty($perProject)): ?>
            <tr><td colspan="4" style="text-align:center; color:var(--hz-text-muted); padding:1.5rem;">Geen data in deze periode.</td></tr>
        <?php else: foreach ($perProject as $naam => $row): ?>
            <tr>
                <td style="font-weight:600;"><span class="hz-badge hz-badge--gray"><?= e($naam) ?></span></td>
                <td><?= formatDuration((int)$row['minuten']) ?></td>
                <td><?= count($row['medewerkers']) ?></td>
                <td><?= $row['registraties'] ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<p style="margin-top:1rem; font-size:.75rem; color:var(--hz-text-muted); text-align:center;">
    Overuren/ORT zijn regel-gebaseerde schattingen op basis van de instelbare CAO-regels (zie Instellingen), geen officieel loonkundig advies.
</p>

<?php layoutEnd(); ?>
