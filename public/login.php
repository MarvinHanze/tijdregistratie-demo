<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['authenticated'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';

    if ($email === ADMIN_EMAIL && password_verify($pass, ADMIN_PASS_HASH)) {
        session_regenerate_id(true);
        setupDatabase();
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
    $error = 'Ongeldige inloggegevens.';
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
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-xl shadow-lg p-8">
            <div class="flex items-center justify-center gap-3 mb-8">
                <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                <h1 class="text-2xl font-bold text-slate-800">Tijdregistratie</h1>
            </div>

            <?php if ($error): ?>
                <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <?= csrfField() ?>
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700 mb-1">E-mailadres</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        value="<?= e($email ?? 'admin@demo.nl') ?>"
                        class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition"
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
                        class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition"
                        placeholder="demo123"
                    >
                </div>
                <button
                    type="submit"
                    class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-2.5 rounded-lg transition"
                >
                    Inloggen
                </button>
            </form>

            <p class="mt-6 text-xs text-slate-400 text-center">
                Demo: admin@demo.nl / demo123
            </p>
        </div>
    </div>
</body>
</html>
