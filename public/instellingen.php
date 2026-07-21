<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireManager();
setupDatabase();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = getDB();
$account = currentAccountEmployee();
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_cao') {
        $stmt = $db->prepare("UPDATE tijd_cao_regels SET waarde = ? WHERE regel_key = ?");
        foreach ($_POST['regel'] ?? [] as $key => $waarde) {
            $stmt->execute([(float)str_replace(',', '.', (string)$waarde), $key]);
        }
        $flash = 'CAO-regels bijgewerkt.';
    }

    if ($action === 'sync_integratie') {
        $naam = $_POST['naam'] ?? '';
        $stmt = $db->prepare("UPDATE tijd_integraties SET verbonden = 1, laatste_sync = NOW(), status_bericht = ? WHERE naam = ?");
        $stmt->execute(['Sync gesimuleerd — geen echte API-call (mock).', $naam]);
        $flash = 'Sync gesimuleerd voor "' . $naam . '".';
    }

    if ($action === 'disconnect_integratie') {
        $naam = $_POST['naam'] ?? '';
        $stmt = $db->prepare("UPDATE tijd_integraties SET verbonden = 0, status_bericht = 'Ontkoppeld.' WHERE naam = ?");
        $stmt->execute([$naam]);
        $flash = 'Integratie ontkoppeld.';
    }

    if ($action === 'mfa_generate' && $account) {
        $secret = generateTOTPSecret();
        $_SESSION['pending_mfa_secret'] = $secret;
        $flash = 'Nieuwe MFA-sleutel gegenereerd. Voer de 6-cijferige code hieronder in om te bevestigen.';
    }

    if ($action === 'mfa_enable' && $account) {
        $pending = $_SESSION['pending_mfa_secret'] ?? '';
        $code = $_POST['code'] ?? '';
        if ($pending !== '' && verifyTotp($pending, $code)) {
            $stmt = $db->prepare("UPDATE tijd_employees SET mfa_secret = ?, mfa_enabled = 1 WHERE id = ?");
            $stmt->execute([$pending, $account['id']]);
            unset($_SESSION['pending_mfa_secret']);
            $flash = 'MFA succesvol ingeschakeld voor dit beheerdersaccount.';
        } else {
            $flash = 'Ongeldige of verlopen code — probeer opnieuw.';
        }
    }

    if ($action === 'mfa_disable' && $account) {
        $stmt = $db->prepare("UPDATE tijd_employees SET mfa_secret = NULL, mfa_enabled = 0 WHERE id = ?");
        $stmt->execute([$account['id']]);
        $flash = 'MFA uitgeschakeld.';
    }

    $account = currentAccountEmployee();
}

$caoRegels = $db->query("SELECT * FROM tijd_cao_regels ORDER BY id")->fetchAll();
$integraties = $db->query("SELECT * FROM tijd_integraties ORDER BY categorie, label")->fetchAll();
$pendingSecret = $_SESSION['pending_mfa_secret'] ?? null;

require_once __DIR__ . '/includes/layout.php';
layoutStart('Instellingen', 'instellingen');
?>

<?php if ($flash): ?>
    <div class="hz-card" style="border-left:4px solid var(--hz-primary); margin-bottom:1.25rem;"><?= e($flash) ?></div>
<?php endif; ?>

<!-- CAO-regels -->
<div class="hz-card" style="margin-bottom:1.25rem;">
    <div class="hz-card__header"><strong>CAO-beleid & drempelwaarden</strong></div>
    <p style="font-size:.82rem; color:var(--hz-text-muted); margin-bottom:.75rem;">
        Deze drempelwaarden sturen de pauze-, overuren- en ORT-berekeningen op Dashboard en Rapportages — instelbaar in plaats van hardcoded.
    </p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_cao">
        <div style="overflow-x:auto;">
        <table class="hz-table">
            <thead><tr><th>Regel</th><th>Waarde</th><th>Eenheid</th><th>Omschrijving</th></tr></thead>
            <tbody>
            <?php foreach ($caoRegels as $r): ?>
                <tr>
                    <td style="font-weight:600;"><?= e($r['label']) ?></td>
                    <td><input type="text" name="regel[<?= e($r['regel_key']) ?>]" value="<?= e((string)$r['waarde']) ?>" style="width:90px; padding:.35rem .5rem; border:1px solid var(--hz-border); border-radius:var(--hz-radius); background:var(--hz-surface); color:var(--hz-text);"></td>
                    <td style="color:var(--hz-text-muted);"><?= e($r['eenheid']) ?></td>
                    <td style="color:var(--hz-text-muted); font-size:.82rem;"><?= e($r['omschrijving']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <button type="submit" class="hz-btn hz-btn--primary" style="margin-top:1rem;">Opslaan</button>
    </form>
</div>

<!-- Integraties (mock) -->
<div class="hz-card" style="margin-bottom:1.25rem;">
    <div class="hz-card__header"><strong>Integraties</strong> <span class="hz-badge hz-badge--gray">mock — geen echte API-calls</span></div>
    <p style="font-size:.82rem; color:var(--hz-text-muted); margin-bottom:.75rem;">
        HR/payroll-koppelingen en agenda-sync vereisen in het echt een OAuth-verbinding met de externe dienst. Deze demo simuleert alleen de UI-flow.
    </p>
    <div style="overflow-x:auto;">
    <table class="hz-table">
        <thead><tr><th>Integratie</th><th>Categorie</th><th>Status</th><th>Laatste sync</th><th style="text-align:right;">Actie</th></tr></thead>
        <tbody>
        <?php foreach ($integraties as $i): ?>
            <tr>
                <td style="font-weight:600;"><?= e($i['label']) ?></td>
                <td><span class="hz-badge hz-badge--gray"><?= e($i['categorie']) ?></span></td>
                <td>
                    <?php if ($i['verbonden']): ?>
                        <span class="hz-badge hz-badge--green">Verbonden</span>
                    <?php else: ?>
                        <span class="hz-badge hz-badge--gray">Niet verbonden</span>
                    <?php endif; ?>
                    <?php if ($i['status_bericht']): ?><div style="font-size:.75rem; color:var(--hz-text-muted); margin-top:.2rem;"><?= e($i['status_bericht']) ?></div><?php endif; ?>
                </td>
                <td><?= $i['laatste_sync'] ? formatDateTime($i['laatste_sync']) : '-' ?></td>
                <td style="text-align:right;">
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="naam" value="<?= e($i['naam']) ?>">
                        <input type="hidden" name="action" value="sync_integratie">
                        <button type="submit" class="hz-btn hz-btn--secondary" style="padding:.35rem .7rem;">🔄 Simuleer sync</button>
                    </form>
                    <?php if ($i['verbonden']): ?>
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="naam" value="<?= e($i['naam']) ?>">
                        <input type="hidden" name="action" value="disconnect_integratie">
                        <button type="submit" class="hz-btn hz-btn--ghost" style="padding:.35rem .7rem;">Ontkoppelen</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="font-size:.78rem; color:var(--hz-text-muted); margin-top:.75rem;">
        Single sign-on (SSO) via een identity provider (bijv. Microsoft Entra ID/Google Workspace/SAML) is een infrastructuur-/organisatiekeuze en vereist een echte IdP-koppeling — niet in deze demo geïmplementeerd.
    </p>
</div>

<!-- MFA -->
<div class="hz-card">
    <div class="hz-card__header"><strong>MFA voor beheerders/managers</strong> <span class="hz-badge hz-badge--gray">echte TOTP (RFC 6238)</span></div>
    <?php if ($account && $account['mfa_enabled']): ?>
        <p style="color:var(--hz-success); font-weight:600; margin-bottom:.75rem;">✓ MFA is ingeschakeld voor <?= e($account['name']) ?> (<?= e($account['email']) ?>).</p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mfa_disable">
            <button type="submit" class="hz-btn hz-btn--danger">MFA uitschakelen</button>
        </form>
    <?php else: ?>
        <p style="font-size:.82rem; color:var(--hz-text-muted); margin-bottom:.75rem;">
            MFA is nog niet ingeschakeld. Genereer een sleutel, voeg deze toe aan een authenticator-app (Google Authenticator, Authy, ...) en bevestig met de 6-cijferige code.
        </p>
        <form method="POST" style="margin-bottom:1rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mfa_generate">
            <button type="submit" class="hz-btn hz-btn--secondary">🔑 Genereer MFA-sleutel</button>
        </form>
        <?php if ($pendingSecret): ?>
            <div style="background:var(--hz-bg); border:1px solid var(--hz-border); border-radius:var(--hz-radius); padding:1rem; margin-bottom:1rem;">
                <p style="font-size:.82rem; margin-bottom:.35rem;"><strong>Geheime sleutel (base32):</strong> <code style="font-size:.9rem;"><?= e($pendingSecret) ?></code></p>
                <p style="font-size:.78rem; color:var(--hz-text-muted); word-break:break-all;"><strong>otpauth-URI</strong> (handmatig invoeren in authenticator-app): <?= e(totpUri($pendingSecret, $account['email'] ?? 'admin')) ?></p>
                <p style="font-size:.78rem; color:var(--hz-text-muted); margin-top:.5rem;">Demo-hulpje (niet in productie tonen): huidige geldige code is <code><?= e(totpCode($pendingSecret)) ?></code>.</p>
            </div>
            <form method="POST" style="display:flex; gap:.5rem; align-items:end;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mfa_enable">
                <div class="hz-field" style="margin-bottom:0;">
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder=" " required>
                    <label>6-cijferige code</label>
                </div>
                <button type="submit" class="hz-btn hz-btn--primary">Bevestigen & inschakelen</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php layoutEnd(); ?>
