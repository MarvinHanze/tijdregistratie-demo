<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
setupDatabase();
require_once __DIR__ . '/includes/layout.php';

$db = getDB();

// --- Server-side klok in/uit: timestamps komen ALTIJD van de server (NOW()/date()),
//     nooit uit client-input. Dit is bewust losgekoppeld van de handmatige-correctie
//     invoer op de Urenregistratie-pagina. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $action = $_POST['action'] ?? '';
    $employeeId = (int)($_POST['employee_id'] ?? 0);

    if ($action === 'server_clock_in' && $employeeId > 0) {
        $project = trim($_POST['project'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $employee = getEmployeeById($employeeId);
        if ($employee && $project !== '') {
            $stmt = $db->prepare("INSERT INTO tijd_entries (employee_id, employee_name, project, clock_in, clock_out, duration_minutes, notes) VALUES (?, ?, ?, ?, NULL, 0, ?)");
            $stmt->execute([$employeeId, $employee['name'], $project, date('Y-m-d H:i:s'), $notes]);
        }
        header('Location: ' . BASE . '/index.php');
        exit;
    }

    if ($action === 'server_clock_out' && $employeeId > 0) {
        $stmt = $db->prepare("SELECT id, clock_in FROM tijd_entries WHERE employee_id = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
        $stmt->execute([$employeeId]);
        $open = $stmt->fetch();
        if ($open) {
            $now = new DateTime();
            $clockIn = new DateTime($open['clock_in']);
            $diff = $now->diff($clockIn);
            $duration = (int)$diff->i + (int)$diff->h * 60 + (int)$diff->days * 1440;
            $upd = $db->prepare("UPDATE tijd_entries SET clock_out = ?, duration_minutes = ? WHERE id = ?");
            $upd->execute([$now->format('Y-m-d H:i:s'), $duration, $open['id']]);
        }
        header('Location: ' . BASE . '/index.php');
        exit;
    }
}

$account = currentAccountEmployee();
$viewedId = viewedEmployeeId();
$focusEmployeeId = $viewedId ?? (int)($account['id'] ?? 0);
$focusEmployee = $focusEmployeeId ? getEmployeeById($focusEmployeeId) : null;
$isTeamView = ($viewedId === null);
$regels = getCaoRegels();

$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));

if ($focusEmployee) {
    $stmtToday = $db->prepare("SELECT COALESCE(SUM(duration_minutes),0) t FROM tijd_entries WHERE employee_id = ? AND DATE(clock_in) = ?");
    $stmtToday->execute([$focusEmployeeId, $today]);
    $minutesToday = (int)$stmtToday->fetch()['t'];

    $stmtWeek = $db->prepare("SELECT COALESCE(SUM(duration_minutes),0) t FROM tijd_entries WHERE employee_id = ? AND DATE(clock_in) >= ?");
    $stmtWeek->execute([$focusEmployeeId, $weekStart]);
    $minutesWeek = (int)$stmtWeek->fetch()['t'];

    $normUren = isset($focusEmployee['contract_uren_per_week']) ? (float)$focusEmployee['contract_uren_per_week'] : ($regels['normuren_per_week'] ?? 40.0);
    $overtimeMinutes = calculateOvertimeMinutes($minutesWeek, $regels, $normUren);

    $stmtOpen = $db->prepare("SELECT * FROM tijd_entries WHERE employee_id = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
    $stmtOpen->execute([$focusEmployeeId]);
    $openEntry = $stmtOpen->fetch();

    $stmtRecentProjects = $db->prepare("SELECT project, MAX(clock_in) mx FROM tijd_entries WHERE employee_id = ? GROUP BY project ORDER BY mx DESC LIMIT 5");
    $stmtRecentProjects->execute([$focusEmployeeId]);
    $recentProjects = $stmtRecentProjects->fetchAll(PDO::FETCH_COLUMN);

    $stmtTimeline = $db->prepare("SELECT * FROM tijd_entries WHERE employee_id = ? AND DATE(clock_in) >= ? ORDER BY clock_in DESC LIMIT 10");
    $stmtTimeline->execute([$focusEmployeeId, $weekStart]);
    $timeline = $stmtTimeline->fetchAll();
} else {
    $openEntry = null;
    $recentProjects = $db->query("SELECT project, MAX(clock_in) mx FROM tijd_entries GROUP BY project ORDER BY mx DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
}

// Teamweergave (manager, geen medewerker geselecteerd)
$teamStatToday = $db->prepare("SELECT COALESCE(SUM(duration_minutes),0) t FROM tijd_entries WHERE DATE(clock_in) = ?");
$teamStatToday->execute([$today]);
$teamMinutesToday = (int)$teamStatToday->fetch()['t'];

$teamStatWeek = $db->prepare("SELECT COALESCE(SUM(duration_minutes),0) t FROM tijd_entries WHERE DATE(clock_in) >= ?");
$teamStatWeek->execute([$weekStart]);
$teamMinutesWeek = (int)$teamStatWeek->fetch()['t'];

$teamActive = (int)$db->query("SELECT COUNT(*) t FROM tijd_entries WHERE clock_out IS NULL")->fetch()['t'];

$statusPerEmployeeStmt = $db->prepare("
    SELECT emp.id, emp.name, emp.role,
           (SELECT COUNT(*) FROM tijd_entries e WHERE e.employee_id = emp.id AND e.clock_out IS NULL) AS actief,
           (SELECT COALESCE(SUM(duration_minutes),0) FROM tijd_entries e WHERE e.employee_id = emp.id AND DATE(e.clock_in) >= ?) AS week_minuten
    FROM tijd_employees emp ORDER BY name
");
$statusPerEmployeeStmt->execute([$weekStart]);
$statusPerEmployee = $statusPerEmployeeStmt->fetchAll();

$layout = layoutStart('Dashboard', 'dashboard');
$forgotten = $layout['forgotten'];
?>

<?php if (!empty($forgotten)): ?>
<div class="hz-card" style="border-left:4px solid var(--hz-danger); margin-bottom:1.25rem;">
    <strong style="color:var(--hz-danger);"><?= hz_icon('alert-triangle') ?> Vergeten uit te klokken</strong>
    <p style="color:var(--hz-text-muted); font-size:.88rem; margin:.35rem 0 .5rem;">
        Deze registraties staan meer dan 12 uur open. Dit is een in-app herinnering (er wordt geen e-mail verstuurd in deze demo).
    </p>
    <ul style="font-size:.88rem; padding-left:1.1rem; margin:0;">
        <?php foreach ($forgotten as $f): ?>
            <li><?= e($f['emp_display_name'] ?? $f['employee_name']) ?> — <?= e($f['project']) ?> (sinds <?= date('d-m-Y H:i', strtotime($f['clock_in'])) ?>)</li>
        <?php endforeach; ?>
    </ul>
    <a href="<?= BASE ?>/uren.php" class="hz-btn hz-btn--secondary" style="margin-top:.6rem;">Corrigeren op Urenregistratie</a>
</div>
<?php endif; ?>

<?php if ($focusEmployee): ?>
<!-- Persoonlijk dashboard voor de geselecteerde medewerker -->
<div class="hz-grid hz-grid--3" style="margin-bottom:1.25rem;">
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Gewerkt vandaag</div>
        <div class="hz-card__value"><?= formatDuration($minutesToday) ?></div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Gewerkt deze week</div>
        <div class="hz-card__value"><?= formatDuration($minutesWeek) ?></div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Normuren deze week</div>
        <div class="hz-card__value"><?= (float)$normUren ?>u</div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Overuren deze week</div>
        <div class="hz-card__value" style="color:<?= $overtimeMinutes > 0 ? 'var(--hz-warning)' : 'var(--hz-text)' ?>;"><?= formatDuration($overtimeMinutes) ?></div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Opgebouwd verlof</div>
        <div class="hz-card__value"><?= (float)$focusEmployee['verlof_saldo_uren'] ?>u</div>
    </div>
</div>

<div class="hz-grid hz-grid--3" style="margin-bottom:1.25rem;">
    <!-- Klok in/uit -->
    <div class="hz-card">
        <div class="hz-card__header"><strong>Klok in/uit — <?= e($focusEmployee['name']) ?></strong></div>
        <?php if ($openEntry): ?>
            <p style="font-size:.85rem; color:var(--hz-text-muted); margin-bottom:.75rem;">
                Actief sinds <?= date('H:i', strtotime($openEntry['clock_in'])) ?> op
                <span class="hz-badge hz-badge--green"><?= e($openEntry['project']) ?></span>
            </p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="server_clock_out">
                <input type="hidden" name="employee_id" value="<?= $focusEmployeeId ?>">
                <button type="submit" class="hz-btn hz-btn--danger" style="width:100%;"><?= hz_icon('stop') ?> Klok uit (nu)</button>
            </form>
        <?php else: ?>
            <form method="POST" class="space-y-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="server_clock_in">
                <input type="hidden" name="employee_id" value="<?= $focusEmployeeId ?>">
                <div class="hz-field">
                    <input type="text" name="project" id="quickProject" placeholder=" " required list="recentProjectsList">
                    <label>Project</label>
                </div>
                <datalist id="recentProjectsList">
                    <?php foreach ($recentProjects as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?>
                </datalist>
                <?php if (!empty($recentProjects)): ?>
                <div class="hz-flex-wrap" style="margin:-.25rem 0 .5rem;">
                    <?php foreach ($recentProjects as $p): ?>
                        <button type="button" class="hz-badge hz-badge--gray" style="cursor:pointer; border:none;" onclick='document.getElementById("quickProject").value=<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>'><?= hz_icon('refresh-cw') ?> <?= e($p) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="hz-field">
                    <input type="text" name="notes" id="quickNotes" placeholder=" ">
                    <label>Notities (optioneel)</label>
                </div>
                <button type="submit" class="hz-btn hz-btn--primary" style="width:100%;"><?= hz_icon('play') ?> Klok in (nu)</button>
            </form>
            <p style="font-size:.72rem; color:var(--hz-text-muted); margin-top:.5rem;">Starttijd wordt altijd server-side vastgelegd — dit veld is niet uit de browser aan te passen.</p>
        <?php endif; ?>
    </div>

    <!-- Spraakgestuurde invoer (demo) -->
    <div class="hz-card">
        <div class="hz-card__header"><strong><?= hz_icon('mic') ?> Spraakinvoer</strong> <span class="hz-badge hz-badge--gray">demo / browserafhankelijk</span></div>
        <p style="font-size:.82rem; color:var(--hz-text-muted); margin-bottom:.6rem;">
            Zeg bijv. "twee uur op project App Development". Werkt alleen in Chrome/Edge (Web Speech API).
        </p>
        <button type="button" class="hz-btn hz-btn--secondary" id="voiceBtn" onclick="startVoiceDemo()"><?= hz_icon('mic') ?> Start opname</button>
        <p id="voiceResult" style="font-size:.85rem; margin-top:.6rem; color:var(--hz-text);"></p>
    </div>

    <!-- Geofencing simulatie (demo) -->
    <div class="hz-card">
        <div class="hz-card__header"><strong><?= hz_icon('map-pin') ?> Locatie-check</strong> <span class="hz-badge hz-badge--gray">simulatie</span></div>
        <p style="font-size:.82rem; color:var(--hz-text-muted); margin-bottom:.6rem;">
            Simuleert geofencing met de browser Geolocation-API t.o.v. een fictief kantooradres. Geen native app/PWA-geofencing.
        </p>
        <button type="button" class="hz-btn hz-btn--secondary" onclick="checkGeofence()"><?= hz_icon('map-pin') ?> Ik ben op kantoor Amsterdam</button>
        <p id="geoResult" style="font-size:.85rem; margin-top:.6rem; color:var(--hz-text);"></p>
    </div>
</div>

<!-- Tijdlijn deze week -->
<div class="hz-card" style="margin-bottom:1.25rem;">
    <div class="hz-card__header"><strong>Tijdlijn deze week — <?= e($focusEmployee['name']) ?></strong></div>
    <?php if (empty($timeline)): ?>
        <p style="color:var(--hz-text-muted); font-size:.88rem;">Nog geen registraties deze week.</p>
    <?php else: ?>
        <ul style="font-size:.88rem; list-style:none; padding:0; margin:0;">
            <?php foreach ($timeline as $t): ?>
                <li style="display:flex; justify-content:space-between; padding:.5rem 0; border-bottom:1px solid var(--hz-border);">
                    <span><strong><?= date('D d-m', strtotime($t['clock_in'])) ?></strong> · <span class="hz-badge hz-badge--gray"><?= e($t['project']) ?></span> <?= e($t['notes']) ?></span>
                    <span><?= date('H:i', strtotime($t['clock_in'])) ?> – <?= $t['clock_out'] ? date('H:i', strtotime($t['clock_out'])) : '…' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php else: /* team view */ ?>
<div class="hz-grid hz-grid--3" style="margin-bottom:1.25rem;">
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Team gewerkt vandaag</div>
        <div class="hz-card__value"><?= formatDuration($teamMinutesToday) ?></div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Team gewerkt deze week</div>
        <div class="hz-card__value"><?= formatDuration($teamMinutesWeek) ?></div>
    </div>
    <div class="hz-card hz-card--stat">
        <div class="hz-card__label">Actieve timers</div>
        <div class="hz-card__value"><?= $teamActive ?></div>
    </div>
</div>
<p style="color:var(--hz-text-muted); font-size:.9rem; margin-bottom:1rem;">Selecteer een medewerker in de sidebar links om diens persoonlijke dashboard, klok in/uit-knop en uren te bekijken.</p>
<div class="hz-card">
    <div class="hz-card__header"><strong>Status per medewerker</strong></div>
    <table class="hz-table">
        <thead><tr><th>Naam</th><th>Rol</th><th>Deze week</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($statusPerEmployee as $s): ?>
            <tr>
                <td><a href="?employee_id=<?= (int)$s['id'] ?>" style="color:var(--hz-primary); text-decoration:none;"><?= e($s['name']) ?></a></td>
                <td><span class="hz-badge hz-badge--gray"><?= e($s['role']) ?></span></td>
                <td><?= formatDuration((int)$s['week_minuten']) ?></td>
                <td><?= $s['actief'] > 0 ? '<span class="hz-badge hz-badge--green">Actief</span>' : '<span class="hz-badge hz-badge--gray">Niet actief</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- AI-schatting projectduur (regel-gebaseerd, geen externe AI-call) -->
<div class="hz-card" style="margin-top:1.25rem;">
    <div class="hz-card__header"><strong><?= hz_icon('lightbulb') ?> Nieuw project? Schat de duur</strong> <span class="hz-badge hz-badge--gray">regel-gebaseerd, geen externe AI-call</span></div>
    <p style="font-size:.82rem; color:var(--hz-text-muted); margin-bottom:.6rem;">
        Vergelijkt de projectnaam met historische registraties en toont de gemiddelde duur van het meest vergelijkbare project.
    </p>
    <div style="display:flex; gap:.5rem; max-width:420px;">
        <input type="text" id="estimateProject" placeholder="bijv. Website Redesign 2.0" style="flex:1; padding:.55rem .75rem; border:1px solid var(--hz-border); border-radius:var(--hz-radius); background:var(--hz-surface); color:var(--hz-text);">
        <button type="button" class="hz-btn hz-btn--secondary" onclick="runEstimate()">Schat</button>
    </div>
    <p id="estimateResult" style="font-size:.85rem; margin-top:.6rem; color:var(--hz-text);"></p>
</div>

<script>
// --- Spraakgestuurde invoer demo (Web Speech API) ---
const VOICE_ERROR_MESSAGES = {
    'network': 'Spraakherkenning kon de herkenningsdienst van de browser niet bereiken (netwerk- of browserbeperking, buiten deze app om — vaak tijdelijk). Probeer het opnieuw, of vul de velden hierboven handmatig in.',
    'not-allowed': 'Microfoon-toegang is geweigerd. Sta microfoongebruik toe voor deze site en probeer opnieuw.',
    'service-not-allowed': 'De browser staat spraakherkenning op deze pagina niet toe. Vul de velden hierboven handmatig in.',
    'no-speech': 'Geen spraak gedetecteerd. Probeer het opnieuw en spreek duidelijk in de microfoon.',
    'audio-capture': 'Geen microfoon gevonden. Sluit een microfoon aan of vul de velden hierboven handmatig in.',
    'aborted': 'Opname geannuleerd.'
};

function startVoiceDemo() {
    const resultEl = document.getElementById('voiceResult');
    const btn = document.getElementById('voiceBtn');
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
        resultEl.textContent = 'Web Speech API wordt niet ondersteund in deze browser (probeer Chrome/Edge).';
        return;
    }
    if (btn.disabled) return; // voorkom dubbel starten tijdens het luisteren
    const oldRetry = document.getElementById('voiceRetryBtn');
    if (oldRetry) oldRetry.remove();

    const recognition = new SpeechRecognition();
    recognition.lang = 'nl-NL';
    recognition.interimResults = false;
    resultEl.textContent = 'Luisteren...';
    btn.disabled = true;
    btn.dataset.originalLabel = btn.innerHTML;
    btn.innerHTML = btn.innerHTML.replace('Start opname', 'Luisteren...');

    const woordGetallen = { een:1, twee:2, drie:3, vier:4, vijf:5, zes:6, zeven:7, acht:8, negen:9, tien:10, elf:11, twaalf:12 };

    recognition.onresult = function (event) {
        const transcript = event.results[0][0].transcript.toLowerCase();
        let uren = null;
        const cijferMatch = transcript.match(/(\d+([.,]\d+)?)\s*uur/);
        if (cijferMatch) {
            uren = parseFloat(cijferMatch[1].replace(',', '.'));
        } else {
            for (const woord in woordGetallen) {
                if (transcript.indexOf(woord + ' uur') > -1) { uren = woordGetallen[woord]; break; }
            }
        }
        const projectMatch = transcript.match(/project\s+([a-z0-9 ]+)/i);
        const project = projectMatch ? projectMatch[1].trim() : null;

        if (uren !== null && project) {
            document.getElementById('quickProject').value = project;
            document.getElementById('quickNotes').value = 'Via spraak: ' + uren + ' uur (herkend, controleer voor opslaan)';
            resultEl.textContent = 'Herkend: ' + uren + ' uur op project "' + project + '" — ingevuld in het klok-in formulier.';
        } else {
            resultEl.textContent = 'Niet herkend ("' + transcript + '"). Probeer: "twee uur op project Consulting".';
        }
    };
    recognition.onerror = function (e) {
        const message = VOICE_ERROR_MESSAGES[e.error] || ('Fout bij spraakherkenning: ' + e.error);
        resultEl.textContent = message;
        if (e.error === 'network') {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.id = 'voiceRetryBtn';
            retry.className = 'hz-btn hz-btn--secondary';
            retry.style.marginTop = '.5rem';
            retry.textContent = 'Probeer opnieuw';
            retry.onclick = function () { retry.remove(); startVoiceDemo(); };
            resultEl.after(retry);
        }
    };
    recognition.onend = function () {
        btn.disabled = false;
        if (btn.dataset.originalLabel) { btn.innerHTML = btn.dataset.originalLabel; }
    };
    recognition.start();
}

// --- Geofencing simulatie (demo) ---
function checkGeofence() {
    const resultEl = document.getElementById('geoResult');
    if (!navigator.geolocation) {
        resultEl.textContent = 'Geolocation wordt niet ondersteund door deze browser.';
        return;
    }
    resultEl.textContent = 'Locatie opvragen...';
    const kantoor = { lat: 52.3380, lon: 4.8720 }; // fictief demo-kantoor "Amsterdam Zuidas"
    navigator.geolocation.getCurrentPosition(function (pos) {
        const R = 6371000;
        const dLat = (pos.coords.latitude - kantoor.lat) * Math.PI / 180;
        const dLon = (pos.coords.longitude - kantoor.lon) * Math.PI / 180;
        const a = Math.sin(dLat/2)**2 + Math.cos(kantoor.lat*Math.PI/180) * Math.cos(pos.coords.latitude*Math.PI/180) * Math.sin(dLon/2)**2;
        const afstand = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        if (afstand < 250) {
            resultEl.innerHTML = '<?= hz_icon('check-circle') ?> Binnen bereik (~' + Math.round(afstand) + ' m van kantoor). Simulatie: klok-in zou automatisch toegestaan worden.';
        } else {
            resultEl.innerHTML = '<?= hz_icon('x-octagon') ?> Buiten bereik (~' + (afstand/1000).toFixed(1) + ' km van kantoor). Simulatie: klok-in zou geblokkeerd worden.';
        }
    }, function (err) {
        resultEl.textContent = 'Locatie niet beschikbaar: ' + err.message;
    });
}

// --- AI-schatting (regel-gebaseerd) ---
function runEstimate() {
    const project = document.getElementById('estimateProject').value.trim();
    const resultEl = document.getElementById('estimateResult');
    if (!project) { resultEl.textContent = 'Vul een projectnaam in.'; return; }
    resultEl.textContent = 'Bezig...';
    fetch('<?= BASE ?>/estimate.php?project=' + encodeURIComponent(project))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.gemiddelde_minuten <= 0) {
                resultEl.textContent = 'Nog onvoldoende historische data om een schatting te maken.';
                return;
            }
            const uren = (data.gemiddelde_minuten / 60).toFixed(1);
            let basis = 'algemeen gemiddelde over alle projecten';
            if (data.basis === 'vergelijkbaar_project') {
                basis = 'gemiddelde van vergelijkbaar project "' + data.vergelijkbaar_project + '"';
            }
            resultEl.textContent = 'Geschatte duur per registratie: ' + uren + ' uur (op basis van ' + basis + ').';
        })
        .catch(function () { resultEl.textContent = 'Schatting kon niet worden opgehaald.'; });
}
</script>

<?php layoutEnd(); ?>
