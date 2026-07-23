<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

hzSessionStart();

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
$lockedSeconds = loginLockoutSecondsRemaining('mfa');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();

    if ($lockedSeconds > 0) {
        $error = 'Te veel mislukte pogingen. Probeer het over ' . (int)ceil($lockedSeconds / 60) . ' minuten opnieuw.';
    } else {
        $code = $_POST['code'] ?? '';
        if ($account && !empty($account['mfa_secret']) && verifyTotp($account['mfa_secret'], $code)) {
            clearLoginFailures('mfa');
            unset($_SESSION['mfa_pending_email']);
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['user'] = $pendingEmail;
            seedData();
            header('Location: ' . BASE . '/index.php');
            exit;
        }
        registerLoginFailure('mfa');
        $error = 'Ongeldige of verlopen code.';
    }
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
    <style>
        :root { --tr-violet:#7c3aed; --tr-purple:#a855f7; --tr-blue:#3b82f6; }
        body.tr-mesh {
            min-height: 100vh; background: #ede9fe; position: relative; overflow: hidden;
            display: flex; align-items: center; justify-content: center; padding: 1rem;
        }
        .tr-orb { position: absolute; border-radius: 50%; filter: blur(60px); opacity: .55; animation: tr-float 16s ease-in-out infinite; }
        .tr-orb--1 { width: 420px; height: 420px; background: var(--tr-violet); top: -120px; left: -100px; }
        .tr-orb--2 { width: 380px; height: 380px; background: var(--tr-blue); bottom: -140px; right: -80px; animation-delay: -5s; }
        .tr-orb--3 { width: 320px; height: 320px; background: var(--tr-purple); top: 40%; left: 60%; animation-delay: -10s; }
        @keyframes tr-float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -25px) scale(1.08); }
            66% { transform: translate(-20px, 20px) scale(0.95); }
        }
        .tr-glass {
            position: relative; z-index: 10;
            background: rgba(255,255,255,.6); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,.7); box-shadow: 0 8px 32px rgba(124,58,237,.18);
            border-radius: 1rem; padding: 2rem;
        }
    </style>
</head>
<body class="tr-mesh">
    <div class="tr-orb tr-orb--1"></div>
    <div class="tr-orb tr-orb--2"></div>
    <div class="tr-orb tr-orb--3"></div>
    <div style="width:100%; max-width:420px; position:relative; z-index:10;">
        <div class="tr-glass">
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
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder=" " autofocus required <?= $lockedSeconds > 0 ? 'disabled' : '' ?>>
                    <label>Code</label>
                </div>
                <button type="submit" class="hz-btn hz-btn--primary" style="width:100%;" <?= $lockedSeconds > 0 ? 'disabled' : '' ?>>Verifiëren</button>
            </form>
            <p style="margin-top:1rem; text-align:center;"><a href="<?= BASE ?>/login.php" style="font-size:.8rem; color:var(--hz-text-muted);">Terug naar inloggen</a></p>
        </div>
    </div>
</body>
</html>
