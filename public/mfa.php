<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Deze pagina is alleen bereikbaar na een geldige wachtwoordcontrole in login.php
// (tweede factor van de login-flow), niet als losstaand toegangspunt.
$pendingEmail = $_SESSION['mfa_pending_email'] ?? null;
if (!$pendingEmail) {
    header('Location: ' . BASE . '/login.php');
    exit;
}
if (!empty($_SESSION['authenticated'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

setupDatabase();
$account = getEmployeeByEmail($pendingEmail);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $code = $_POST['code'] ?? '';
    if ($account && !empty($account['mfa_secret']) && verifyTotp($account['mfa_secret'], $code)) {
        unset($_SESSION['mfa_pending_email']);
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['user'] = $pendingEmail;
        seedData();
        header('Location: ' . BASE . '/index.php');
        exit;
    }
    $error = 'Ongeldige of verlopen code.';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificatiecode - Tijdregistratie</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/components.css">
</head>
<body class="min-h-screen flex items-center justify-center p-4" style="background:var(--hz-bg); min-height:100vh; display:flex; align-items:center; justify-content:center;">
    <div style="width:100%; max-width:420px;">
        <div class="hz-card">
            <h1 style="font-size:1.3rem; font-weight:700; margin-bottom:.25rem; color:var(--hz-text);">Verificatiecode</h1>
            <p style="font-size:.85rem; color:var(--hz-text-muted); margin-bottom:1.25rem;">
                Voer de 6-cijferige code uit je authenticator-app in (MFA is ingeschakeld voor dit account).
            </p>
            <?php if ($error): ?>
                <div style="margin-bottom:1rem; padding:.75rem; background:#fee2e2; border:1px solid #fecaca; color:#991b1b; border-radius:var(--hz-radius); font-size:.85rem;"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="hz-field">
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder=" " autofocus required>
                    <label>Code</label>
                </div>
                <button type="submit" class="hz-btn hz-btn--primary" style="width:100%;">Verifiëren</button>
            </form>
            <p style="margin-top:1rem; text-align:center;"><a href="<?= BASE ?>/login.php" style="font-size:.8rem; color:var(--hz-text-muted);">Terug naar inloggen</a></p>
        </div>
    </div>
</body>
</html>
