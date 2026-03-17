<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$uid = getLoggedInUserId();
$errors = [];
$success = getFlash('success');
$errorFlash = getFlash('error');

$currentUser = null;
$linkedPersonId = 0;
try {
    $stmt = $pdo->prepare("SELECT ID, name, person_id FROM users WHERE ID = ? LIMIT 1");
    $stmt->execute([$uid]);
    $currentUser = $stmt->fetch();
    $linkedPersonId = (int)($currentUser['person_id'] ?? 0);
} catch (Throwable $e) {
    $currentUser = ['ID' => $uid, 'name' => 'Operator'];
}

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$selectedPersonId = (int)($_GET['person_id'] ?? $_POST['person_id'] ?? 0);
$selectedPerson = null;
$results = [];

if ($selectedPersonId > 0) {
    $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
    $stmt->execute([$selectedPersonId]);
    $selectedPerson = $stmt->fetch();
    if ($selectedPerson) {
        $q = (string)$selectedPerson['name'];
    }
}

if ($q !== '' && !$selectedPerson) {
    $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE name LIKE ? OR phone LIKE ? OR city LIKE ? ORDER BY name ASC LIMIT 30");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $results = $stmt->fetchAll();
}

function expense_old(string $key, $default = '') {
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
        ['ID' => 'food', 'name' => 'Food'],
        ['ID' => 'maintenance', 'name' => 'Maintenance'],
        ['ID' => 'travel', 'name' => 'Travel'],
        ['ID' => 'utility', 'name' => 'Utility'],
        ['ID' => 'other', 'name' => 'Other'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'save_expense') {
    verifyCsrfOrFail();

    $selectedPersonId = (int)($_POST['person_id'] ?? 0);
    $paidMode = (string)($_POST['expense_paid_mode'] ?? 'operator');
    $category = trim((string)($_POST['expense_category'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = (string)($_POST['payment_method'] ?? 'cash');
    $expenseDate = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $allowedModes = ['operator', 'delegate'];
    $allowedMethods = ['cash', 'bank', 'pos', 'stripe', 'online'];

    if ($selectedPersonId <= 0) $errors[] = 'Please select a person first.';
    if (!in_array($paidMode, $allowedModes, true)) $errors[] = 'Invalid expense mode.';
    if ($category === '') $errors[] = 'Please select an expense category.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
    if (!in_array($paymentMethod, $allowedMethods, true)) $errors[] = 'Invalid payment method.';
    if ($expenseDate === '') $errors[] = 'Date is required.';

    if ($selectedPersonId > 0 && !$selectedPerson) {
        $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
        $stmt->execute([$selectedPersonId]);
        $selectedPerson = $stmt->fetch();
    }

    if (!$errors) {
        $selectedName = (string)($selectedPerson['name'] ?? 'Selected person');
        $modeNote = $paidMode === 'delegate'
            ? 'Delegate Paid | Given from my mosque balance to ' . $selectedName . ' for payment'
            : 'Operator Paid | Paid from my mosque balance';

        $finalNotes = trim($modeNote . ($notes !== '' ? ' | ' . $notes : ''));

        if (tableExists($pdo, 'operator_ledger')) {
            $stmt = $pdo->prepare('INSERT INTO operator_ledger (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, notes, created_by, created_at) VALUES (?, ?, "expense", ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $uid,
                $selectedPersonId,
                $category,
                -1 * $amount,
                $paymentMethod,
                $finalNotes,
                $uid,
                $expenseDate . ' 12:00:00'
            ]);
        }

        setFlash('success', 'Expense saved successfully.');
        header('Location: person_profile.php?id=' . $selectedPersonId);
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="expense_page_split.css">

<h1 class="title">Add Expense</h1>

<div class="card expense-card-v6 stack">
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($errorFlash): ?><div class="alert error"><?= e($errorFlash) ?></div><?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="get" class="expense-search-wrap-v6">
        <div class="expense-search-row-v6">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search person by name or phone" class="expense-search-input-v6">
            <button type="submit" class="btn expense-search-btn-v6">Search</button>
        </div>
        <div class="expense-search-actions-v6">
            <a href="add_person.php" class="btn btn-primary expense-add-btn-v6">Add New Donor</a>
        </div>
    </form>

    <?php if ($q !== '' && !$selectedPerson): ?>
        <div id="person_results" class="expense-results-v6">
            <?php if ($results): ?>
                <?php foreach ($results as $row): ?>
                    <button type="button" class="expense-donor-result-v6"
                        data-person-id="<?= (int)$row['ID'] ?>"
                        data-person-name="<?= e((string)$row['name']) ?>"
                        data-person-city="<?= e((string)($row['city'] ?: '')) ?>"
                        data-person-phone="<?= e((string)($row['phone'] ?: '')) ?>">
                        <span class="expense-donor-left-v6">
                            <strong><?= e((string)$row['name']) ?></strong>
                            <small><?= e((string)($row['city'] ?: '')) ?></small>
                        </span>
                        <span class="expense-donor-right-v6"><?= e((string)($row['phone'] ?: '—')) ?></span>
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="muted">No person found.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" id="expense_form_v6" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="save_expense">
        <input type="hidden" name="person_id" id="person_id" value="<?= (int)($selectedPerson['ID'] ?? $selectedPersonId) ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">

        <div id="selected_person_box" class="expense-selected-v6<?= $selectedPerson ? ' is-selected' : '' ?>">
            <div class="muted">Selected person</div>
            <div id="selected_person_name" class="expense-selected-name-v6"><?= e((string)($selectedPerson['name'] ?? 'No person selected')) ?></div>
            <div id="selected_person_meta" class="expense-selected-meta-v6"><?php if ($selectedPerson): ?><?= e((string)($selectedPerson['city'] ?: '')) ?><?= !empty($selectedPerson['phone']) ? ' · ' . e((string)$selectedPerson['phone']) : '' ?><?php endif; ?></div>
        </div>

        <div id="expense_fields_section" class="<?= $selectedPerson ? '' : 'is-disabled' ?>">
            <div id="expense_flow_start_v6" class="section-title">How was this expense paid?</div>
            <div class="expense-mode-grid-v6">
                <?php $modeValue = (string)expense_old('expense_paid_mode', 'operator'); ?>
                <label class="expense-card-option-v6">
                    <input type="radio" name="expense_paid_mode" value="operator" <?= $modeValue === 'operator' ? 'checked' : '' ?>>
                    <span class="expense-card-pill-v6"><span class="icon">🙋</span><strong>Operator Paid</strong><small>Paid from my mosque balance.</small></span>
                </label>
                <label class="expense-card-option-v6">
                    <input type="radio" name="expense_paid_mode" value="delegate" <?= $modeValue === 'delegate' ? 'checked' : '' ?>>
                    <span class="expense-card-pill-v6"><span class="icon">🤝</span><strong>Delegate Paid</strong><small>Given from my mosque balance to the selected person for payment.</small></span>
                </label>
            </div>

            <div class="section-title">Expense Category</div>
            <select name="expense_category">
                <option value="">Select category</option>
                <?php $catValue = (string)expense_old('expense_category', ''); ?>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e((string)$cat['ID']) ?>" <?= $catValue === (string)$cat['ID'] ? 'selected' : '' ?>><?= e((string)$cat['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="section-title">Amount</div>
            <div class="stack compact">
                <input type="number" step="0.01" min="0.01" name="amount" id="amount_input_v6" value="<?= e((string)expense_old('amount', '')) ?>" placeholder="0.00">
                <div class="expense-amount-row-v6">
                    <?php foreach ([10,20,50,100,500,1000] as $inc): ?>
                        <button type="button" class="expense-mini-card-v6 amount-add-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="section-title">Payment Method</div>
            <div class="expense-payment-row-v6">
                <?php $methodValue = (string)expense_old('payment_method', 'cash'); $methods = ['cash'=>['💵','Cash'],'bank'=>['🏦','Bank'],'pos'=>['💳','POS'],'stripe'=>['💠','Stripe'],'online'=>['🌐','Online']]; ?>
                <?php foreach ($methods as $value => [$icon, $label]): ?>
                    <label class="expense-card-option-v6 expense-payment-option-v6">
                        <input type="radio" name="payment_method" value="<?= e($value) ?>" <?= $methodValue === $value ? 'checked' : '' ?>>
                        <span class="expense-card-pill-v6"><span class="icon"><?= $icon ?></span><span><?= e($label) ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="expense-date-notes-grid-v6">
                <div>
                    <label>Date</label>
                    <input type="date" name="expense_date" value="<?= e((string)expense_old('expense_date', date('Y-m-d'))) ?>">
                </div>
                <div>
                    <label>Notes</label>
                    <input type="text" name="notes" value="<?= e((string)expense_old('notes', '')) ?>" placeholder="Optional note">
                </div>
            </div>

            <div class="toolbar expense-toolbar-v6">
                <button type="submit" class="btn btn-primary">Save Expense</button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const resultWrap = document.getElementById('person_results');
    const personInput = document.getElementById('person_id');
    const selectedBox = document.getElementById('selected_person_box');
    const selectedName = document.getElementById('selected_person_name');
    const selectedMeta = document.getElementById('selected_person_meta');
    const fieldSection = document.getElementById('expense_fields_section');

    function enableFields() {
        fieldSection.classList.remove('is-disabled');
        selectedBox.classList.add('is-selected');
    }

    function selectPerson(id, name, city, phone) {
        personInput.value = id;
        selectedName.textContent = name || 'Selected person';
        selectedMeta.textContent = [city || '', phone || ''].filter(Boolean).join(' · ');
        enableFields();
        if (resultWrap) {
            resultWrap.innerHTML = '';
            resultWrap.style.display = 'none';
        }
        const target = document.getElementById('expense_flow_start_v6');
        if (target) target.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    if (resultWrap) {
        resultWrap.addEventListener('click', function (e) {
            const btn = e.target.closest('.expense-donor-result-v6');
            if (!btn) return;
            e.preventDefault();
            selectPerson(btn.getAttribute('data-person-id') || '', btn.getAttribute('data-person-name') || '', btn.getAttribute('data-person-city') || '', btn.getAttribute('data-person-phone') || '');
        });
    }

    document.querySelectorAll('.amount-add-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById('amount_input_v6');
            const add = parseFloat(btn.getAttribute('data-add') || '0');
            const current = parseFloat(input.value || '0');
            input.value = (current + add).toFixed(2);
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
