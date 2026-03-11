
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
try {
    $stmt = $pdo->prepare("SELECT ID, name, person_id FROM users WHERE ID = ? LIMIT 1");
    $stmt->execute([$uid]);
    $currentUser = $stmt->fetch();
    $linkedPersonId = (int)($currentUser['person_id'] ?? 0);
} catch (Throwable $e) {
    $currentUser = ['ID' => $uid, 'name' => 'Operator'];
}

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$selectedDonorId = (int)($_GET['person_id'] ?? $_POST['person_id'] ?? 0);
if ($selectedDonorId <= 0 && currentRole($pdo) === 'operator') {
    $selectedDonorId = $linkedPersonId ?? 0;
    if ($selectedDonorId <= 0) {
        $selectedDonorId = getUserPersonId($pdo, $uid);
    }
}
$selectedDonor = null;
$results = [];

if ($selectedDonorId > 0) {
    $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
    $stmt->execute([$selectedDonorId]);
    $selectedDonor = $stmt->fetch();
    if ($selectedDonor) {
        $q = (string)$selectedDonor['name'];
    }
}

if ($q !== '' && !$selectedDonor) {
    $stmt = $pdo->prepare("
        SELECT ID, name, city, phone
        FROM people
        WHERE name LIKE ? OR phone LIKE ? OR city LIKE ?
        ORDER BY name ASC
        LIMIT 30
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $results = $stmt->fetchAll();
}

function ex_old($key, $default = '') {
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

    $selectedDonorId = (int)($_POST['person_id'] ?? 0);
    $expenserMode = (string)($_POST['expenser_mode'] ?? 'operator');
    $delegatedName = trim((string)($_POST['delegated_name'] ?? ''));
    $category = trim((string)($_POST['expense_category'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = (string)($_POST['payment_method'] ?? 'cash');
    $expenseDate = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $allowedModes = ['operator', 'delegated', 'donation'];
    $allowedMethods = ['cash', 'bank', 'pos', 'stripe', 'online'];

    if ($selectedDonorId <= 0) $errors[] = 'Please select a donor first.';
    if (!in_array($expenserMode, $allowedModes, true)) $errors[] = 'Invalid expenser type.';
    if ($expenserMode === 'delegated' && $delegatedName === '') $errors[] = 'Enter who received money for the expense.';
    if ($category === '') $errors[] = 'Please select an expense category.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
    if (!in_array($paymentMethod, $allowedMethods, true)) $errors[] = 'Invalid payment method.';
    if ($expenseDate === '') $errors[] = 'Date is required.';

    if ($selectedDonorId > 0 && !$selectedDonor) {
        $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
        $stmt->execute([$selectedDonorId]);
        $selectedDonor = $stmt->fetch();
    }

    if (!$errors) {
        $modeNote = '';
        $ledgerAmount = -1 * $amount;

        if ($expenserMode === 'operator') {
            $modeNote = 'Paid by operator ' . (string)($currentUser['name'] ?? 'Operator');
        } elseif ($expenserMode === 'delegated') {
            $modeNote = 'Cash/expense amount given to ' . $delegatedName . ' for spending';
        } else {
            $modeNote = 'Covered by donation / no deduction from operator balance';
            $ledgerAmount = 0.00;
        }

        $finalNotes = trim($modeNote . ($notes !== '' ? ' | ' . $notes : ''));

        if (tableExists($pdo, 'operator_ledger')) {
            $stmt = $pdo->prepare('
                INSERT INTO operator_ledger
                    (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, notes, created_by, created_at)
                VALUES
                    (?, ?, "expense", ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $uid,
                $selectedDonorId > 0 ? $selectedDonorId : null,
                $category,
                $ledgerAmount,
                $paymentMethod,
                $finalNotes,
                $uid,
                $expenseDate . ' 12:00:00'
            ]);
        }

        setFlash('success', 'Expense saved successfully.');
        header('Location: person_profile.php?id=' . (int)$selectedDonorId);
        exit;
    }
}

function expenseMethodLabel(string $method): string {
    return match ($method) {
        'cash' => 'Cash',
        'bank' => 'Bank',
        'pos' => 'POS',
        'stripe' => 'Stripe',
        'online' => 'Online',
        default => ucwords(str_replace('_', ' ', $method)),
    };
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Add Expense</h1>

<div class="card expense-card-v5 stack">
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($errorFlash): ?><div class="alert error"><?= e($errorFlash) ?></div><?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="expense-search-wrap-v5">
        <div class="expense-search-row-v5">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search donor by name or phone" class="expense-search-input-v5">
            <button type="submit" class="btn expense-search-btn-v5">Search</button>
        </div>
        <div class="expense-search-actions-v5">
            <a href="add_person.php" class="btn btn-primary expense-add-btn-v5">Add New Donor</a>
        </div>
    </form>

    <?php if ($q !== '' && !$selectedDonor): ?>
        <div id="donor_results" class="expense-results-v5">
            <?php if ($results): ?>
                <?php foreach ($results as $row): ?>
                    <button
                        type="button"
                        class="expense-donor-result-v5"
                        data-donor-id="<?= (int)$row['ID'] ?>"
                        data-donor-name="<?= e((string)$row['name']) ?>"
                        data-donor-city="<?= e((string)($row['city'] ?: '')) ?>"
                        data-donor-phone="<?= e((string)($row['phone'] ?: '')) ?>"
                    >
                        <span class="expense-donor-left-v5">
                            <strong><?= e((string)$row['name']) ?></strong>
                            <small><?= e((string)($row['city'] ?: '')) ?></small>
                        </span>
                        <span class="expense-donor-right-v5"><?= e((string)($row['phone'] ?: '—')) ?></span>
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="muted">No donor found.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" id="expense_form_v5" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="save_expense">
        <input type="hidden" name="person_id" id="person_id" value="<?= (int)($selectedDonor['ID'] ?? $selectedDonorId) ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">

        <div id="selected_donor_box" class="expense-selected-v5<?= $selectedDonor ? ' is-selected' : '' ?>">
            <div class="muted">Selected donor</div>
            <div id="selected_donor_name" class="expense-selected-name-v5"><?= e((string)($selectedDonor['name'] ?? 'No donor selected')) ?></div>
            <div id="selected_donor_meta" class="expense-selected-meta-v5">
                <?php if ($selectedDonor): ?>
                    <?= e((string)($selectedDonor['city'] ?: '')) ?><?= !empty($selectedDonor['phone']) ? ' · ' . e((string)$selectedDonor['phone']) : '' ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="expense_fields_section" class="<?= $selectedDonor ? '' : 'is-disabled' ?>">
            <div id="expense_flow_start_v5" class="section-title">Who paid / handled this expense?</div>
            <div class="expense-mode-grid-v5">
                <?php
                $modeValue = (string)ex_old('expenser_mode', 'operator');
                $modes = [
                    'operator' => ['🙋', 'Operator', 'Deduct from my balance'],
                    'delegated' => ['🤝', 'Someone Else', 'I gave money to another person'],
                    'donation' => ['🎁', 'Donation', 'No deduction from my balance'],
                ];
                foreach ($modes as $value => [$icon, $label, $sub]):
                ?>
                <label class="expense-card-option-v5">
                    <input type="radio" name="expenser_mode" value="<?= e($value) ?>" <?= $modeValue === $value ? 'checked' : '' ?>>
                    <span class="expense-card-pill-v5">
                        <span class="icon"><?= $icon ?></span>
                        <span><?= e($label) ?></span>
                        <small><?= e($sub) ?></small>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>

            <div id="delegated_box_v5" class="stack compact expense-conditional-v5<?= $modeValue === 'delegated' ? '' : ' hidden' ?>">
                <label>Person who received money for expense</label>
                <input type="text" name="delegated_name" value="<?= e((string)ex_old('delegated_name', '')) ?>" placeholder="Enter person name">
            </div>

            <div class="section-title">Expense Category</div>
            <div class="stack compact">
                <select name="expense_category">
                    <option value="">Select category</option>
                    <?php foreach ($categories as $cat): ?>
                        <?php $catId = (string)$cat['ID']; ?>
                        <option value="<?= e($catId) ?>" <?= (string)ex_old('expense_category', '') === $catId ? 'selected' : '' ?>><?= e((string)$cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="section-title">Amount</div>
            <div class="stack compact">
                <input type="number" step="0.01" min="0.01" name="amount" id="amount_input_v5" value="<?= e((string)ex_old('amount', '')) ?>" placeholder="0.00">
                <div class="expense-amount-row-v5">
                    <?php foreach ([10,20,50,100,500,1000] as $inc): ?>
                        <button type="button" class="expense-mini-card-v5 amount-add-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="section-title">Payment Method</div>
            <div class="expense-payment-row-v5">
                <?php
                $methodValue = (string)ex_old('payment_method', 'cash');
                $methods = [
                    'cash' => ['💵', 'Cash'],
                    'bank' => ['🏦', 'Bank'],
                    'pos' => ['💳', 'POS'],
                    'stripe' => ['💠', 'Stripe'],
                    'online' => ['🌐', 'Online'],
                ];
                foreach ($methods as $value => [$icon, $label]):
                ?>
                <label class="expense-card-option-v5 expense-payment-option-v5">
                    <input type="radio" name="payment_method" value="<?= e($value) ?>" <?= $methodValue === $value ? 'checked' : '' ?>>
                    <span class="expense-card-pill-v5"><span class="icon"><?= $icon ?></span><span><?= e($label) ?></span></span>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="expense-date-notes-grid-v5">
                <div>
                    <label>Date</label>
                    <input type="date" name="expense_date" value="<?= e((string)ex_old('expense_date', date('Y-m-d'))) ?>">
                </div>
                <div>
                    <label>Notes</label>
                    <input type="text" name="notes" value="<?= e((string)ex_old('notes', '')) ?>" placeholder="Optional note">
                </div>
            </div>

            <div class="toolbar expense-toolbar-v5">
                <button type="submit" class="btn btn-primary">Save Expense</button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const resultWrap = document.getElementById('donor_results');
    const personInput = document.getElementById('person_id');
    const selectedBox = document.getElementById('selected_donor_box');
    const selectedName = document.getElementById('selected_donor_name');
    const selectedMeta = document.getElementById('selected_donor_meta');
    const fieldSection = document.getElementById('expense_fields_section');

    function enableFields() {
        fieldSection.classList.remove('is-disabled');
        selectedBox.classList.add('is-selected');
    }

    function selectDonor(id, name, city, phone) {
        personInput.value = id;
        selectedName.textContent = name || 'Selected donor';
        selectedMeta.textContent = [city || '', phone || ''].filter(Boolean).join(' · ');
        enableFields();
        if (resultWrap) {
            resultWrap.innerHTML = '';
            resultWrap.style.display = 'none';
        }
        const target = document.getElementById('expense_flow_start_v5');
        if (target) {
            target.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    }

    if (resultWrap) {
        resultWrap.addEventListener('click', function (e) {
            const btn = e.target.closest('.expense-donor-result-v5');
            if (!btn) return;
            e.preventDefault();
            selectDonor(
                btn.getAttribute('data-donor-id') || '',
                btn.getAttribute('data-donor-name') || '',
                btn.getAttribute('data-donor-city') || '',
                btn.getAttribute('data-donor-phone') || ''
            );
        });
    }

    document.querySelectorAll('.amount-add-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById('amount_input_v5');
            const add = parseFloat(btn.getAttribute('data-add') || '0');
            const current = parseFloat(input.value || '0');
            input.value = (current + add).toFixed(2);
        });
    });

    function syncModeFields() {
        const selected = document.querySelector('input[name="expenser_mode"]:checked');
        const delegatedBox = document.getElementById('delegated_box_v5');
        const val = selected ? selected.value : 'operator';
        delegatedBox.classList.toggle('hidden', val !== 'delegated');
    }

    document.querySelectorAll('input[name="expenser_mode"]').forEach(function (radio) {
        radio.addEventListener('change', syncModeFields);
    });
    syncModeFields();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
