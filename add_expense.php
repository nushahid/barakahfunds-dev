<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$currentUser = currentUser($pdo);
$uid = (int)($currentUser['ID'] ?? 0);

$errors = [];
$success = getFlash('success');
$errorFlash = getFlash('error');

function ex_old($key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function loadExpensePersonById(PDO $pdo, int $personId): ?array
{
    if ($personId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
    $stmt->execute([$personId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function resolveExpenseReferencePerson(string $mode, ?array $operatorPerson, ?array $profilePerson): ?array
{
    if ($mode === 'someone_else') {
        return $profilePerson ?: null;
    }

    return $operatorPerson ?: null;
}

/*
|--------------------------------------------------------------------------
| Operator linked donor/person
|--------------------------------------------------------------------------
*/
$operatorLinkedPersonId = (int)($currentUser['person_id'] ?? 0);

if ($operatorLinkedPersonId <= 0 && $uid > 0) {
    $stmt = $pdo->prepare("SELECT person_id FROM users WHERE ID = ? LIMIT 1");
    $stmt->execute([$uid]);
    $operatorLinkedPersonId = (int)$stmt->fetchColumn();
}

$operatorPerson = loadExpensePersonById($pdo, $operatorLinkedPersonId);

/*
|--------------------------------------------------------------------------
| Profile person if opened from person_profile.php?id=...
|--------------------------------------------------------------------------
*/
$profilePersonId = (int)($_GET['person_id'] ?? $_POST['profile_person_id'] ?? 0);
$profilePerson = loadExpensePersonById($pdo, $profilePersonId);

if (!$profilePerson) {
    $profilePersonId = 0;
}

/*
|--------------------------------------------------------------------------
| Mode by context
|--------------------------------------------------------------------------
*/
$hasProfilePerson = $profilePersonId > 0;

$showOperatorOption = !$hasProfilePerson;
$showSomeoneElseOption = $hasProfilePerson;

$modeValue = $hasProfilePerson ? 'someone_else' : 'operator';

$selectedReferencePerson = resolveExpenseReferencePerson($modeValue, $operatorPerson, $profilePerson);
$selectedReferencePersonId = (int)($selectedReferencePerson['ID'] ?? 0);

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/
$categories = [];
if (tableExists($pdo, 'expense_categories')) {
    try {
        $categories = $pdo->query("SELECT ID, name FROM expense_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
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

/*
|--------------------------------------------------------------------------
| Save
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'save_expense') {
    verifyCsrfOrFail();

    $profilePersonId = (int)($_POST['profile_person_id'] ?? 0);
    $profilePerson = loadExpensePersonById($pdo, $profilePersonId);

    $hasProfilePerson = $profilePersonId > 0 && $profilePerson;
    $modeValue = $hasProfilePerson ? 'someone_else' : 'operator';

    $operatorLinkedPersonId = (int)($_POST['operator_person_id'] ?? $operatorLinkedPersonId);
    $operatorPerson = loadExpensePersonById($pdo, $operatorLinkedPersonId);

    $expenseTitle = trim((string)($_POST['expense_title'] ?? ''));
    $category = trim((string)($_POST['expense_category'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = (string)($_POST['payment_method'] ?? 'cash');
    $expenseDate = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $allowedMethods = ['cash', 'bank', 'pos', 'stripe', 'online'];

    if ($modeValue === 'operator' && !$operatorPerson) {
        $errors[] = 'Your operator account is not linked with any donor profile.';
    }

    if ($modeValue === 'someone_else' && !$profilePerson) {
        $errors[] = 'No profile person was selected. Open this page from a person profile.';
    }

    if ($expenseTitle === '') {
        $errors[] = 'Please enter expense item or title.';
    }

    if ($category === '') {
        $errors[] = 'Please select an expense category.';
    }

    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }

    if (!in_array($paymentMethod, $allowedMethods, true)) {
        $errors[] = 'Invalid payment method.';
    }

    if ($expenseDate === '') {
        $errors[] = 'Date is required.';
    }

    $selectedReferencePerson = resolveExpenseReferencePerson($modeValue, $operatorPerson, $profilePerson);
    $selectedReferencePersonId = (int)($selectedReferencePerson['ID'] ?? 0);

    if ($selectedReferencePersonId <= 0) {
        $errors[] = 'Reference person could not be found.';
    }

    if (!$errors) {
        $modeNote = $modeValue === 'operator'
            ? 'Reference person is operator linked donor ' . (string)($operatorPerson['name'] ?? 'Unknown')
            : 'Reference person selected from profile ' . (string)($profilePerson['name'] ?? 'Unknown');

        $noteParts = [
            'Expense item: ' . $expenseTitle,
            $modeNote,
        ];

        if ($notes !== '') {
            $noteParts[] = $notes;
        }

        $finalNotes = implode(' | ', $noteParts);
        $ledgerAmount = -1 * $amount;

        if (tableExists($pdo, 'operator_ledger')) {
            $stmt = $pdo->prepare('
                INSERT INTO operator_ledger
                    (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, notes, created_by, created_at)
                VALUES
                    (?, ?, "expense", ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $uid,
                $selectedReferencePersonId,
                $category,
                $ledgerAmount,
                $paymentMethod,
                $finalNotes,
                $uid,
                $expenseDate . ' 12:00:00'
            ]);
        }

        setFlash('success', 'Expense saved successfully.');
        header('Location: person_profile.php?id=' . $selectedReferencePersonId);
        exit;
    }
}

$pageClass = 'page-add-expense';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Add Expense</h1>

<div class="card add-expense-card-v2 stack">
    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($errorFlash): ?>
        <div class="alert error"><?= e($errorFlash) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $er): ?>
                <div><?= e($er) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="add-expense-refbar-v2">
        <div class="add-expense-refmain-v2">
            <div class="add-expense-reflabel-v2">Reference Person</div>
            <div class="add-expense-refname-v2">
                <?= e((string)($selectedReferencePerson['name'] ?? 'No person selected')) ?>
            </div>
            <div class="add-expense-refmeta-v2">
                <?php if ($selectedReferencePerson): ?>
                    <?= e((string)($selectedReferencePerson['city'] ?? '')) ?>
                    <?= !empty($selectedReferencePerson['phone']) ? ' · ' . e((string)$selectedReferencePerson['phone']) : '' ?>
                <?php else: ?>
                    No linked donor profile found.
                <?php endif; ?>
            </div>
        </div>

        <div class="add-expense-refbadge-v2">
            <?= $modeValue === 'operator' ? 'Operator' : 'Someone Else' ?>
        </div>
    </div>

    <form method="post" id="add_expense_form_v2" class="stack add-expense-form-v2">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="save_expense">
        <input type="hidden" name="operator_person_id" value="<?= (int)$operatorLinkedPersonId ?>">
        <input type="hidden" name="profile_person_id" value="<?= (int)$profilePersonId ?>">
        <input type="hidden" name="expenser_mode" value="<?= e($modeValue) ?>">

        <div class="add-expense-grid-top-v2">
            <div>
                <label>Expense Item / Title</label>
                <input
                    type="text"
                    name="expense_title"
                    value="<?= e((string)ex_old('expense_title', '')) ?>"
                    placeholder="Example: Tissue roll, cleaning work, transport"
                >
            </div>

            <div>
                <label>Category</label>
                <select name="expense_category">
                    <option value="">Select category</option>
                    <?php foreach ($categories as $cat): ?>
                        <?php $catId = (string)$cat['ID']; ?>
                        <option value="<?= e($catId) ?>" <?= (string)ex_old('expense_category', '') === $catId ? 'selected' : '' ?>>
                            <?= e((string)$cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="add-expense-grid-mid-v2">
            <div>
                <label>Amount</label>
                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    name="amount"
                    id="amount_input_v2"
                    value="<?= e((string)ex_old('amount', '')) ?>"
                    placeholder="0.00"
                >

                <div class="add-expense-amount-row-v2">
                    <?php foreach ([10, 20, 50, 100] as $inc): ?>
                        <button type="button" class="add-expense-mini-btn-v2 amount-add-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label>Payment Method</label>
                <select name="payment_method">
                    <?php
                    $methodValue = (string)ex_old('payment_method', 'cash');
                    $methods = [
                        'cash' => 'Cash',
                        'bank' => 'Bank',
                        'pos' => 'POS',
                        'stripe' => 'Stripe',
                        'online' => 'Online',
                    ];
                    foreach ($methods as $value => $label):
                    ?>
                        <option value="<?= e($value) ?>" <?= $methodValue === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Date</label>
                <input
                    type="date"
                    name="expense_date"
                    value="<?= e((string)ex_old('expense_date', date('Y-m-d'))) ?>"
                >
            </div>
        </div>

        <div>
            <label>Notes</label>
            <input
                type="text"
                name="notes"
                value="<?= e((string)ex_old('notes', '')) ?>"
                placeholder="Optional note"
            >
        </div>

        <div class="toolbar add-expense-toolbar-v2">
            <button type="submit" class="btn btn-primary">Save Expense</button>
        </div>
    </form>
</div>

<script>
(function () {
    document.querySelectorAll('.amount-add-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById('amount_input_v2');
            if (!input) {
                return;
            }

            const add = parseFloat(btn.getAttribute('data-add') || '0');
            const current = parseFloat(input.value || '0');
            input.value = (current + add).toFixed(2);
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>