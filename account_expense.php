<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

if (currentRole($pdo) !== 'accountant' && currentRole($pdo) !== 'admin') {
    exit('Only accountant/admin can enter mosque account expenses.');
}

$uid = getLoggedInUserId();
$errors = [];
$success = getFlash('success');
$errorFlash = getFlash('error');

function acc_old($key, $default = '') {
    return $_POST[$key] ?? $default;
}

$categories = [];
if (tableExists($pdo, 'expense_categories')) {
    try {
        $categories = $pdo->query("SELECT ID, name FROM expense_categories ORDER BY name ASC")->fetchAll();
    } catch (Throwable $e) {
        $categories = [];
    }
}
if (!$categories) {
    $categories = [
        ['ID' => 'utility', 'name' => 'Utility'],
        ['ID' => 'bank_charge', 'name' => 'Bank Charge'],
        ['ID' => 'property', 'name' => 'Land / Property'],
        ['ID' => 'supplier', 'name' => 'Supplier Payment'],
        ['ID' => 'service', 'name' => 'Service Fee'],
        ['ID' => 'other', 'name' => 'Other'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'save_account_expense') {
    verifyCsrfOrFail();

    $source = (string)($_POST['account_source'] ?? 'bank');
    $category = trim((string)($_POST['expense_category'] ?? ''));
    $vendor = trim((string)($_POST['vendor_name'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = (string)($_POST['payment_method'] ?? 'bank');
    $expenseDate = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $allowedSources = ['bank', 'accountant_cash'];
    $allowedMethods = ['bank', 'cash', 'online', 'stripe', 'pos'];

    if (!in_array($source, $allowedSources, true)) $errors[] = 'Invalid account source.';
    if ($category === '') $errors[] = 'Please select an expense category.';
    if ($vendor === '') $errors[] = 'Enter vendor / company / payee name.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
    if (!in_array($paymentMethod, $allowedMethods, true)) $errors[] = 'Invalid payment method.';
    if ($expenseDate === '') $errors[] = 'Date is required.';

    if (!$errors) {
        $finalNotes = trim('Mosque account expense | source: ' . $source . ' | payee: ' . $vendor . ($notes !== '' ? ' | ' . $notes : ''));

        if (tableExists($pdo, 'accountant_ledger')) {
            $stmt = $pdo->prepare('
                INSERT INTO accountant_ledger
                    (entry_type, amount, payment_method, notes, created_by, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                'expense',
                -1 * $amount,
                $paymentMethod,
                $finalNotes,
                $uid,
                $expenseDate . ' 12:00:00'
            ]);
        }

        setFlash('success', 'Mosque account expense saved successfully.');
        header('Location: account_expense.php');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Accountant Expense</h1>

<div class="card account-expense-card-v5 stack">
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($errorFlash): ?><div class="alert error"><?= e($errorFlash) ?></div><?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="helper">
        Use this page only when mosque bank/accountant cash pays directly:
        utility bills, bank commission, official supplier payments, land payment, direct bank deductions.
    </div>

    <form method="post" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="save_account_expense">

        <div class="section-title">Account source</div>
        <div class="account-source-grid-v5">
            <?php
            $srcValue = (string)acc_old('account_source', 'bank');
            $sources = [
                'bank' => ['🏦', 'Mosque Bank', 'Direct bank deduction / transfer'],
                'accountant_cash' => ['💵', 'Accountant Cash', 'Cash held by accountant'],
            ];
            foreach ($sources as $value => [$icon, $label, $sub]):
            ?>
            <label class="account-card-option-v5">
                <input type="radio" name="account_source" value="<?= e($value) ?>" <?= $srcValue === $value ? 'checked' : '' ?>>
                <span class="account-card-pill-v5">
                    <span class="icon"><?= $icon ?></span>
                    <span><?= e($label) ?></span>
                    <small><?= e($sub) ?></small>
                </span>
            </label>
            <?php endforeach; ?>
        </div>

        <div class="section-title">Expense category</div>
        <select name="expense_category">
            <option value="">Select category</option>
            <?php foreach ($categories as $cat): $catId = (string)$cat['ID']; ?>
                <option value="<?= e($catId) ?>" <?= (string)acc_old('expense_category', '') === $catId ? 'selected' : '' ?>><?= e((string)$cat['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="account-two-grid-v5">
            <div>
                <label>Vendor / Company / Payee</label>
                <input type="text" name="vendor_name" value="<?= e((string)acc_old('vendor_name', '')) ?>" placeholder="Example: Utility company / land owner / supplier">
            </div>
            <div>
                <label>Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" id="account_amount_input_v5" value="<?= e((string)acc_old('amount', '')) ?>" placeholder="0.00">
                <div class="account-amount-row-v5">
                    <?php foreach ([10,20,50,100,500,1000] as $inc): ?>
                        <button type="button" class="account-mini-card-v5 account-add-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="section-title">Payment method</div>
        <div class="account-payment-row-v5">
            <?php
            $methodValue = (string)acc_old('payment_method', 'bank');
            $methods = [
                'bank' => ['🏦', 'Bank'],
                'cash' => ['💵', 'Cash'],
                'online' => ['🌐', 'Online'],
                'stripe' => ['💠', 'Stripe'],
                'pos' => ['💳', 'POS'],
            ];
            foreach ($methods as $value => [$icon, $label]):
            ?>
            <label class="account-card-option-v5 account-payment-option-v5">
                <input type="radio" name="payment_method" value="<?= e($value) ?>" <?= $methodValue === $value ? 'checked' : '' ?>>
                <span class="account-card-pill-v5"><span class="icon"><?= $icon ?></span><span><?= e($label) ?></span></span>
            </label>
            <?php endforeach; ?>
        </div>

        <div class="account-two-grid-v5">
            <div>
                <label>Date</label>
                <input type="date" name="expense_date" value="<?= e((string)acc_old('expense_date', date('Y-m-d'))) ?>">
            </div>
            <div>
                <label>Notes / Receipt Reference</label>
                <input type="text" name="notes" value="<?= e((string)acc_old('notes', '')) ?>" placeholder="Receipt no, utility bill ref, bank memo">
            </div>
        </div>

        <div class="toolbar account-toolbar-v5">
            <button type="submit" class="btn btn-primary">Save Accountant Expense</button>
        </div>
    </form>
</div>

<script>
document.querySelectorAll('.account-add-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const input = document.getElementById('account_amount_input_v5');
        const add = parseFloat(btn.getAttribute('data-add') || '0');
        const current = parseFloat(input.value || '0');
        input.value = (current + add).toFixed(2);
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
