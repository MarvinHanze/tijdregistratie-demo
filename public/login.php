<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

hzSessionStart();
setupDatabase();

if (!empty($_SESSION['authenticated'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

$error = '';
$lockedSeconds = loginLockoutSecondsRemaining('login');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();

    if ($lockedSeconds > 0) {
        $error = 'Te veel mislukte inlogpogingen. Probeer het over ' . (int)ceil($lockedSeconds / 60) . ' minuten opnieuw.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';

        if ($email === ADMIN_EMAIL && password_verify($pass, ADMIN_PASS_HASH)) {
            clearLoginFailures('login');
            session_regenerate_id(true);
            $account = getEmployeeByEmail(ADMIN_EMAIL);

            if ($account && !empty($account['mfa_enabled'])) {
                // MFA vereist: nog niet volledig inloggen, eerst naar de TOTP-stap.
                $_SESSION['mfa_pending_email'] = $email;
                header('Location: ' . BASE . '/mfa.php');
                exit;
            }

            $_SESSION['authenticated'] = true;
            $_SESSION['user'] = $email;
            seedData();
            header('Location: ' . BASE . '/index.php');
            exit;
        }
        registerLoginFailure('login');
        $error = 'Ongeldige inloggegevens.';
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inloggen - Tijdregistratie</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/components.css">
    <style>
        :root { --tr-violet:#7c3aed; --tr-purple:#a855f7; --tr-blue:#3b82f6; }
        body.tr-mesh {
            min-height: 100vh;
            background: #ede9fe;
            position: relative;
            overflow: hidden;
            display: flex; align-items: center; justify-content: center; padding: 1rem;
        }
        .tr-orb { position: absolute; border-radius: 50%; filter: blur(60px); opacity: .55; animation: tr-float 16s ease-in-out infinite; }
        .tr-orb--1 { width: 420px; height: 420px; background: var(--tr-violet); top: -120px; left: -100px; animation-delay: 0s; }
        .tr-orb--2 { width: 380px; height: 380px; background: var(--tr-blue); bottom: -140px; right: -80px; animation-delay: -5s; }
        .tr-orb--3 { width: 320px; height: 320px; background: var(--tr-purple); top: 40%; left: 60%; animation-delay: -10s; }
        .tr-orb--4 { width: 260px; height: 260px; background: #6366f1; bottom: 10%; left: 5%; animation-delay: -3s; }
        @keyframes tr-float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -25px) scale(1.08); }
            66% { transform: translate(-20px, 20px) scale(0.95); }
        }
        .tr-glass {
            position: relative; z-index: 10;
            background: rgba(255,255,255,.6);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,.7);
            box-shadow: 0 8px 32px rgba(124,58,237,.18);
        }
        .tr-clock-badge {
            width: 56px; height: 56px; border-radius: 16px;
            background: linear-gradient(135deg, var(--tr-violet), var(--tr-blue));
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 6px 16px rgba(124,58,237,.35);
        }
    </style>
</head>
<body class="tr-mesh">
    <div class="tr-orb tr-orb--1"></div>
    <div class="tr-orb tr-orb--2"></div>
    <div class="tr-orb tr-orb--3"></div>
    <div class="tr-orb tr-orb--4"></div>

    <div class="w-full max-w-md relative z-10">
        <div class="tr-glass rounded-2xl shadow-xl p-8">
            <div class="flex flex-col items-center justify-center gap-3 mb-8">
                <div class="tr-clock-badge">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-slate-800">Tijdregistratie</h1>
                <p class="text-xs text-slate-500 -mt-2">Registreer en beheer je gewerkte uren</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-4 p-3 bg-red-50/80 border border-red-200 text-red-700 rounded-lg text-sm">
                    <?= e($error) ?>
                </div>
            <?php elseif ($lockedSeconds > 0): ?>
                <div class="mb-4 p-3 bg-red-50/80 border border-red-200 text-red-700 rounded-lg text-sm">
                    Te veel mislukte inlogpogingen. Probeer het over <?= (int)ceil($lockedSeconds / 60) ?> minuten opnieuw.
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5" <?= $lockedSeconds > 0 ? 'aria-disabled="true"' : '' ?>>
                <?= csrfField() ?>
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700 mb-1">E-mailadres</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        value="<?= e($email ?? 'admin@demo.nl') ?>"
                        class="w-full px-4 py-2.5 border border-white/70 bg-white/70 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none transition"
                        placeholder="admin@demo.nl"
                    >
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Wachtwoord</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        value="demo123"
                        class="w-full px-4 py-2.5 border border-white/70 bg-white/70 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none transition"
                        placeholder="demo123"
                    >
                </div>
                <button
                    type="submit"
                    class="w-full text-white font-medium py-2.5 rounded-lg transition shadow-md"
                    style="background: linear-gradient(135deg, var(--tr-violet), var(--tr-blue));"
                    <?= $lockedSeconds > 0 ? 'disabled' : '' ?>
                >
                    Inloggen
                </button>
            </form>

            <p class="mt-6 text-xs text-slate-500 text-center">
                Demo: admin@demo.nl / demo123
            </p>
        </div>
    </div>
</body>
</html>
