<?php
// Put this inside your left sidebar builder in includes/header.php or wherever sidebar links are printed.
// Show accountant expense link for accountant/admin login.

$role = function_exists('currentRole') ? currentRole($pdo) : '';
?>
<?php if (in_array($role, ['accountant', 'admin'], true)): ?>
    <a class="sidebar-link" href="account_expense.php">
        <span class="icon">💸</span>
        <span>Accountant Expense</span>
    </a>
<?php endif; ?>

<?php if (in_array($role, ['operator', 'admin'], true)): ?>
    <a class="sidebar-link" href="expense_page.php">
        <span class="icon">🧾</span>
        <span>Operator Expense</span>
    </a>
<?php endif; ?>
