<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();

require_once __DIR__ . '/includes/db.php';

if (isUserSessionValid($pdo)) {
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $errors[] = 'Username and password are required.';
    } else {
        $result = verifyLogin($pdo, $username, $password);
        if ($result['ok']) {
            setFlash('success', 'Welcome back.');
            header('Location: index.php');
            exit;
        }

        $errors[] = $result['message'];
    }
}

$flash = getFlash();

$appName = appName($pdo);
$logoPath = trim(receiptLogoPath($pdo));
$hasLogo = $logoPath !== '' && is_file(__DIR__ . '/' . ltrim($logoPath, '/'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - <?= e($appName) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/login-admin.css">
</head>
<body class="admin-login-page">

<div class="admin-login-layout">
    <section class="admin-login-hero">
        <div class="admin-login-hero__overlay"></div>
        <div class="admin-login-hero__content">
            <div class="admin-login-brand">
                <?php if ($hasLogo): ?>
                    <div class="admin-login-brand__logo-wrap">
                        <img
                            src="<?= e($logoPath) ?>"
                            alt="<?= e($appName) ?> logo"
                            class="admin-login-brand__logo">
                    </div>
                <?php endif; ?>

                <div class="admin-login-brand__text">
                    <div class="admin-login-brand__eyebrow">Administration Portal</div>
                    <h1><?= e($appName) ?></h1>
                    <p>
                        Secure access for mosque administration, accounting, and operator workflows
                        in one place.
                    </p>
                </div>
            </div>

            <div class="admin-login-highlights">
                <div class="admin-login-highlight">
                    <span class="admin-login-highlight__icon">✓</span>
                    <span>Protected session handling</span>
                </div>
                <div class="admin-login-highlight">
                    <span class="admin-login-highlight__icon">✓</span>
                    <span>Device-aware login validation</span>
                </div>
                <div class="admin-login-highlight">
                    <span class="admin-login-highlight__icon">✓</span>
                    <span>Mobile-friendly admin access</span>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-login-panel">
        <div class="admin-login-card">
            <div class="admin-login-card__top">
                <?php if ($hasLogo): ?>
                    <img
                        src="<?= e($logoPath) ?>"
                        alt="<?= e($appName) ?> logo"
                        class="admin-login-card__logo">
                <?php endif; ?>

                <div>
                    <div class="admin-login-card__label">Welcome back</div>
                    <h2>Sign in to continue</h2>
                    <p>Use your assigned username and password to access the system dashboard.</p>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert <?= e($flash['type']) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert error">
                    <?php foreach ($errors as $er): ?>
                        <div><?= e($er) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="admin-login-form" novalidate>
                <?= csrfField() ?>

                <div class="admin-field">
                    <label for="username">Username</label>
                    <input
                        type="text"
                        name="username"
                        id="username"
                        autocomplete="username"
                        value="<?= e((string)($_POST['username'] ?? '')) ?>"
                        required>
                </div>

                <div class="admin-field">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        name="password"
                        id="password"
                        autocomplete="current-password"
                        required>
                </div>

                <button class="admin-login-btn" type="submit">Log In</button>
            </form>

            <div class="admin-login-footer-note">
                Access is restricted to authorized users only.
            </div>
        </div>
    </section>
</div>

</body>
</html>
