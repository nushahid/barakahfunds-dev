<?php
require_once __DIR__ . '/functions.php';
startSecureSession();
$flash = getFlash();
$current = basename($_SERVER['PHP_SELF']);
$user = isset($pdo) ? currentUser($pdo) : null;
$role = $user && isset($pdo) ? currentRole($pdo) : 'guest';
$appTitle = isset($pdo) ? appName($pdo) : 'BarakahFunds v4';
$lang = isset($pdo) ? currentLanguage($pdo) : 'en';
$logoPath = (isset($pdo) && function_exists('receiptLogoPath')) ? receiptLogoPath($pdo) : '';
$menu = [
    ['file' => 'index.php', 'label' => 'Dashboard', 'icon' => '🏠', 'roles' => ['operator','accountant','admin']],
    ['file' => 'donors.php', 'label' => 'Donors', 'icon' => '👥', 'roles' => ['operator']],
    ['file' => 'transaction_page.php', 'label' => 'Collect Donation', 'icon' => '💝', 'roles' => ['operator']],
    ['file' => 'transactions.php', 'label' => 'Transactions', 'icon' => '🧾', 'roles' => ['operator']],
    ['file' => 'expense_page.php', 'label' => 'Add Expense', 'icon' => '💸', 'roles' => ['operator']],
    ['file' => 'account_expense.php', 'label' => 'Account Expense', 'icon' => '🏦', 'roles' => ['accountant','admin']],
    ['file' => 'transfer_requests.php', 'label' => 'Transfers', 'icon' => '🔄', 'roles' => ['operator','accountant']],
    ['file' => 'accounts_report.php', 'label' => 'Reports', 'icon' => '📊', 'roles' => ['accountant','admin']],
    ['file' => 'stripe_reconciliation.php', 'label' => 'Stripe Reconciliation', 'icon' => '🧾', 'roles' => ['accountant']],
    ['file' => 'payment_adjustments.php', 'label' => 'Payment Adjustments', 'icon' => '🛠️', 'roles' => ['accountant']],
    ['file' => 'event_page.php', 'label' => 'Events', 'icon' => '🎉', 'roles' => ['accountant','admin']],
    ['file' => 'loan_page.php', 'label' => 'Loans', 'icon' => '🤝', 'roles' => ['accountant','admin']],
    ['file' => 'expense_categories.php', 'label' => 'Expense Categories', 'icon' => '🗂️', 'roles' => ['accountant','admin']],
    ['file' => 'death_societies.php', 'label' => 'Death Societies', 'icon' => '🕊️', 'roles' => ['accountant','admin']],
    ['file' => 'mosque_settings.php', 'label' => 'Mosque Settings', 'icon' => '🕌', 'roles' => ['admin']],
    ['file' => 'admin_users.php', 'label' => 'Users', 'icon' => '👤', 'roles' => ['admin']],
    ['file' => 'system_logs.php', 'label' => 'System Logs', 'icon' => '📜', 'roles' => ['admin']],
    ['file' => 'my_profile.php', 'label' => 'My Profile', 'icon' => '🙍', 'roles' => ['operator','accountant','admin']],
];
$pageName = strtolower(pathinfo($current, PATHINFO_FILENAME));
$pageClass = preg_replace('/[^a-z0-9]+/i', '-', $pageName);
$pageCssFile = __DIR__ . '/../assets/pages/' . $pageName . '.css';
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appTitle) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <?php if (is_file($pageCssFile)): ?>
    <link rel="stylesheet" href="assets/pages/<?= e($pageName) ?>.css?v=5.0">
    <?php endif; ?>
</head>
<body class="page-<?= e($pageClass) ?>">
<div class="mobile-topbar">
    <button class="icon-btn" type="button" onclick="toggleSidebar()" aria-label="Open menu">☰</button>
    <a class="mobile-topbar-brand" href="index.php" aria-label="Dashboard">
        <?php if ($logoPath !== ''): ?><img class="mobile-topbar-logo" src="<?= e($logoPath) ?>" alt="<?= e($appTitle) ?> logo"><?php endif; ?>
        <div class="mobile-topbar-title-wrap">
            <div class="mobile-topbar-title"><?= e($appTitle) ?></div>
            <div class="mobile-topbar-subtitle">Mosque management</div>
        </div>
    </a>
    <a class="icon-btn" href="my_profile.php" aria-label="My profile">👤</a>
</div>
<div class="sidebar-backdrop" onclick="toggleSidebar(false)"></div>
<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="brand-row">
            <a class="brand brand-with-logo" href="index.php" aria-label="Dashboard">
                <?php if ($logoPath !== ''): ?><img class="brand-logo" src="<?= e($logoPath) ?>" alt="<?= e($appTitle) ?> logo"><?php endif; ?>
                <div>
                    <div class="brand-title"><?= e($appTitle) ?></div>
                    <div class="brand-subtitle">Mosque management</div>
                </div>
            </a>
            <?php if ($user): ?>
            <button class="sidebar-mode-btn" type="button" onclick="toggleSidebarMode()" title="Toggle icons only / icons with text">
                <span aria-hidden="true">🪄</span>
                <span class="sidebar-mode-label">Icons / Text</span>
            </button>
            <?php endif; ?>
        </div>
        <?php if ($user): ?>
            <div class="sidebar-user-card">
                <div class="muted-light">Signed in as</div>
                <div class="sidebar-user-name"><?= e((string)$user['username']) ?></div>
                <div class="sidebar-user-role"><?= e(roleLabel($role)) ?></div>
            </div>
        <?php endif; ?>
        <nav class="sidebar-nav">
            <?php foreach ($menu as $item): if (!$user || !in_array($role, $item['roles'], true)) continue; ?>
                <a class="nav-link <?= $current === $item['file'] ? 'active' : '' ?>" href="<?= e($item['file']) ?>">
                    <span class="nav-icon" aria-hidden="true"><?= e($item['icon']) ?></span>
                    <span class="nav-text"><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
            <a class="nav-link" href="logout.php">
                <span class="nav-icon" aria-hidden="true">⎋</span>
                <span class="nav-text">Logout</span>
            </a>
        </nav>
    </aside>
    <main class="main">
        <?php if ($flash): ?>
            <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
