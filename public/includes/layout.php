<?php
declare(strict_types=1);
// Gedeelde layout: linker sidebar-navigatie (medewerkers/pagina's) + topbar.
// Plain-PHP include, geen framework/templating-engine — consistent met de rest van de app.

/** @var array<string,array{0:string,1:string}> $GLOBALS['__navItems'] */
$GLOBALS['__navItems'] = [
    'dashboard'    => ['index.php', 'Dashboard'],
    'uren'         => ['uren.php', 'Urenregistratie'],
    'verlof'       => ['verlof.php', 'Verlof'],
    'rapportage'   => ['rapportage.php', 'Rapportages'],
    'instellingen' => ['instellingen.php', 'Instellingen'],
];

function layoutStart(string $title, string $active): array {
    $viewedId = viewedEmployeeId();
    $employees = getEmployees();
    $account = currentAccountEmployee();
    $forgotten = getForgottenClockouts();
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> · Tijdregistratie</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/components.css">
    <style>
        body { background: var(--hz-bg); }
        .app-shell { display: flex; align-items: stretch; min-height: 100vh; }
        .app-sidebar { flex-shrink: 0; }
        .app-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .app-content { padding: 1.5rem; width: 100%; box-sizing: border-box; padding-bottom: 4.5rem; }
        @media (max-width: 720px) {
            .app-sidebar { display: none; }
            .app-content { padding-bottom: 5rem; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <aside class="hz-sidebar app-sidebar" id="mainSidebar">
        <div style="padding:1rem; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--hz-border);">
            <span class="hz-sidebar__label" style="font-weight:700; color:var(--hz-text); white-space:nowrap;">⏱ Tijdregistratie</span>
            <button class="hz-sidebar__toggle" data-hz-sidebar-toggle="mainSidebar" title="In-/uitklappen">☰</button>
        </div>
        <nav style="padding:.75rem 0; flex:1; overflow-y:auto;">
            <?php foreach ($GLOBALS['__navItems'] as $key => $item): [$href, $label] = $item; ?>
                <a class="hz-sidebar__item <?= $active === $key ? 'hz-is-active' : '' ?>" href="<?= BASE ?>/<?= e($href) ?>">
                    <span class="hz-sidebar__label"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>

            <div class="hz-sidebar__label" style="margin:1rem .5rem .35rem; font-size:.7rem; text-transform:uppercase; letter-spacing:.03em; color:var(--hz-text-muted);">Medewerkers</div>
            <a class="hz-sidebar__item <?= $viewedId === null ? 'hz-is-active' : '' ?>" href="?employee_id=0">
                <span class="hz-sidebar__label">Alle medewerkers (team)</span>
            </a>
            <?php foreach ($employees as $emp): ?>
                <a class="hz-sidebar__item <?= $viewedId === (int)$emp['id'] ? 'hz-is-active' : '' ?>" href="?employee_id=<?= (int)$emp['id'] ?>">
                    <span class="hz-sidebar__label"><?= e($emp['name']) ?><?= $emp['role'] === 'manager' ? ' 👑' : '' ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="app-main">
        <div class="hz-navbar hz-navbar--sticky">
            <div style="display:flex; align-items:center; gap:.75rem;">
                <span style="font-weight:600; color:var(--hz-text);"><?= e($title) ?></span>
                <?php if (!empty($forgotten)): ?>
                    <span class="hz-badge hz-badge--red hz-tooltip">
                        ⚠ <?= count($forgotten) ?> vergeten uitklok-actie<?= count($forgotten) === 1 ? '' : 's' ?>
                        <span class="hz-tooltip__bubble">Open sinds &gt; 12 uur — controleer op de Urenregistratie-pagina</span>
                    </span>
                <?php endif; ?>
            </div>
            <div class="hz-navbar__actions">
                <span style="font-size:.85rem; color:var(--hz-text-muted);">
                    <?= e($account['name'] ?? ($_SESSION['user'] ?? '')) ?>
                    <?= ($account['role'] ?? '') === 'manager' ? ' · Manager' : '' ?>
                </span>
                <a href="<?= BASE ?>/logout.php" class="hz-btn hz-btn--ghost" style="padding:.4rem .7rem;">Uitloggen</a>
            </div>
        </div>
        <main class="app-content">
    <?php
    return ['viewedId' => $viewedId, 'employees' => $employees, 'account' => $account, 'forgotten' => $forgotten];
}

function layoutEnd(): void {
    ?>
        </main>
        <nav class="hz-bottomnav">
            <?php foreach ($GLOBALS['__navItems'] as $key => $item): [$href, $label] = $item; ?>
                <a class="hz-bottomnav__item" href="<?= BASE ?>/<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>
<script src="<?= BASE ?>/assets/js/components.js"></script>
</body>
</html>
    <?php
}
