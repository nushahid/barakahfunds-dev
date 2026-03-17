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

function ex_old(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function loadExpensePersonById(PDO $pdo, int $personId): ?array
{
    if ($personId <= 0 || !tableExists($pdo, 'people')) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1');
    $stmt->execute([$personId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

$operatorLinkedPersonId = (int)($currentUser['person_id'] ?? 0);
if ($operatorLinkedPersonId <= 0 && $uid > 0 && tableExists($pdo, 'users')) {
    $stmt = $pdo->prepare('SELECT person_id FROM users WHERE ID = ? LIMIT 1');
    $stmt->execute([$uid]);
    $operatorLinkedPersonId = (int)$stmt->fetchColumn();
}
$operatorPerson = loadExpensePersonById($pdo, $operatorLinkedPersonId);

$profilePersonId = (int)($_GET['person_id'] ?? $_GET['id'] ?? $_POST['profile_person_id'] ?? 0);
$profilePerson = loadExpensePersonById($pdo, $profilePersonId);
if (!$profilePerson) {
    $profilePersonId = 0;
}

$selectedReferencePerson = $profilePerson ?: $operatorPerson;
$modeValue = $profilePerson ? 'profile' : 'operator';

$categories = [];
if (tableExists($pdo, 'expense_categories')) {
    try {
        $categories = $pdo->query('SELECT ID, category_name FROM expense_categories WHERE status = 1 ORDER BY category_name ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $categories = [];
    }
}

$paymentMethodMap = [
    'cash' => ['label' => 'Cash', 'icon' => '💵'],
    'bank' => ['label' => 'Bank', 'icon' => '🏦'],
    'pos' => ['label' => 'POS', 'icon' => '💳'],
    'stripe' => ['label' => 'Stripe', 'icon' => '🟦'],
    'online' => ['label' => 'Online', 'icon' => '🌐'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_expense') {
    verifyCsrfOrFail();

    $profilePersonId = (int)($_POST['profile_person_id'] ?? 0);
    $profilePerson = loadExpensePersonById($pdo, $profilePersonId);

    $operatorLinkedPersonId = (int)($_POST['operator_person_id'] ?? $operatorLinkedPersonId);
    $operatorPerson = loadExpensePersonById($pdo, $operatorLinkedPersonId);

    $selectedReferencePerson = $profilePerson ?: $operatorPerson;
    $modeValue = $profilePerson ? 'profile' : 'operator';

    $referencePersonId = (int)($selectedReferencePerson['ID'] ?? 0);
    $expenseTitle = trim((string)($_POST['expense_title'] ?? ''));
    $categoryId = (int)($_POST['expense_category'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $isSponsored = (string)($_POST['is_sponsored'] ?? '0') === '1';
    $paymentMethod = $isSponsored ? 'no-cash' : (string)($_POST['payment_method'] ?? 'cash');
    $expenseDate = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!$operatorPerson) {
        $errors[] = 'Your operator account is not linked with any donor profile.';
    }

    if ($referencePersonId <= 0) {
        $errors[] = 'Reference donor could not be found.';
    }

    if ($expenseTitle === '') {
        $errors[] = 'Please enter expense item or title.';
    }

    if ($categoryId <= 0) {
        $errors[] = 'Please select an expense category.';
    }

    if ($amount <= 0) {
        $errors[] = $isSponsored ? 'Value must be greater than zero.' : 'Amount must be greater than zero.';
    }

    if (!$isSponsored && !isset($paymentMethodMap[$paymentMethod])) {
        $errors[] = 'Please select a valid payment method.';
    }

    if ($expenseDate === '') {
        $errors[] = 'Please select expense date.';
    }

    if (!tableExists($pdo, 'expense')) {
        $errors[] = 'Expense table was not found in database.';
    }

    if (!$errors) {
        $contextBits = [];
        $contextBits[] = 'Handled by operator: ' . (string)($operatorPerson['name'] ?? 'Unknown');
        $contextBits[] = 'Reference donor: ' . (string)($selectedReferencePerson['name'] ?? 'Unknown');
        $contextBits[] = $modeValue === 'profile'
            ? 'Reference donor selected automatically from person profile.'
            : 'Reference donor selected from operator linked donor.';
        $contextBits[] = $isSponsored
            ? 'Sponsored expense informational record only. No cash movement.'
            : 'Operator paid/handled this expense. It is not expense made by donor.';
        if ($notes !== '') {
            $contextBits[] = $notes;
        }

        $insertCols = ['pid', 'exp_cat', 'name', 'amount', 'payment_method', 'expense_date', 'donation', 'notes', 'uid', 'created_at'];
        $insertVals = [
            $referencePersonId,
            $categoryId,
            $expenseTitle,
            $amount,
            $paymentMethod,
            $expenseDate,
            $isSponsored ? 1 : 0,
            implode(' | ', $contextBits),
            $uid,
            date('Y-m-d H:i:s'),
        ];

        if (function_exists('columnExists') && columnExists($pdo, 'expense', 'operator_person_id')) {
            $insertCols[] = 'operator_person_id';
            $insertVals[] = (int)($operatorPerson['ID'] ?? 0);
        }

        if (function_exists('columnExists') && columnExists($pdo, 'expense', 'media')) {
            $insertCols[] = 'media';
            $insertVals[] = null;
        }

        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $sql = 'INSERT INTO expense (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($insertVals);

        setFlash('success', $isSponsored ? 'Sponsored expense saved successfully.' : 'Expense saved successfully.');
        header('Location: person_profile.php?id=' . $referencePersonId);
        exit;
    }
}

$pageClass = 'page-add-expense';
require_once __DIR__ . '/includes/header.php';

$isSponsoredOld = ex_old('is_sponsored', '0') === '1';
$selectedMethod = ex_old('payment_method', 'cash');
?>

<style>
body.page-add-expense .add-expense-form-v2{
    gap:18px;
}
body.page-add-expense .add-expense-info-v2,
body.page-add-expense .add-expense-toggle-wrap-v2{
    border:1px solid #fdba74;
    background:#fffaf3;
    border-radius:18px;
    padding:18px;
}
body.page-add-expense .add-expense-info-v2{
    color:#9a3412;
    line-height:1.5;
}
body.page-add-expense .add-expense-toggle-v2{
    display:flex;
    align-items:center;
    gap:14px;
    cursor:pointer;
    font-weight:800;
    color:#431407;
}
body.page-add-expense .add-expense-toggle-v2 input[type="checkbox"]{
    position:absolute;
    opacity:0;
    pointer-events:none;
}
body.page-add-expense .add-expense-toggle-slider-v2{
    position:relative;
    width:66px;
    height:34px;
    border-radius:999px;
    background:#fdba74;
    flex:0 0 auto;
    transition:.2s ease;
}
body.page-add-expense .add-expense-toggle-slider-v2::after{
    content:"";
    position:absolute;
    top:4px;
    left:4px;
    width:26px;
    height:26px;
    border-radius:50%;
    background:#fff;
    box-shadow:0 1px 3px rgba(0,0,0,.2);
    transition:.2s ease;
}
body.page-add-expense .add-expense-toggle-v2 input[type="checkbox"]:checked + .add-expense-toggle-slider-v2{
    background:#f97316;
}
body.page-add-expense .add-expense-toggle-v2 input[type="checkbox"]:checked + .add-expense-toggle-slider-v2::after{
    left:36px;
}
body.page-add-expense .add-expense-toggle-text-v2{
    font-size:18px;
}
body.page-add-expense .add-expense-toggle-help-v2{
    margin-top:14px;
    color:#9a3412;
    line-height:1.5;
}
body.page-add-expense .add-expense-grid-top-v2,
body.page-add-expense .add-expense-grid-bottom-v2{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:16px;
    align-items:start;
}
body.page-add-expense .add-expense-grid-bottom-v2{
    margin-top:2px;
}
body.page-add-expense .add-expense-grid-top-v2 > *,
body.page-add-expense .add-expense-grid-bottom-v2 > *{
    min-width:0;
}
body.page-add-expense .add-expense-amount-block-v2,
body.page-add-expense .add-expense-payment-row-v2{
    display:block;
}
body.page-add-expense .add-expense-amount-row-v2{
    display:grid;
    grid-template-columns:repeat(7, minmax(0, 1fr));
    gap:8px;
    margin-top:12px;
    width:100%;
}
body.page-add-expense .add-expense-mini-btn-v2{
    width:100%;
    min-width:0;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:10px 0;
    border-radius:12px;
    border:1px solid #cbd5e1;
    background:#fff;
    font-weight:800;
    text-align:center;
    cursor:pointer;
    transition:.15s ease;
    box-sizing:border-box;
}
body.page-add-expense .add-expense-mini-btn-v2:hover{
    border-color:#fb923c;
    background:#fff7ed;
}
body.page-add-expense .add-expense-field-v2 > label,
body.page-add-expense .add-expense-field-full-v2 > label{
    display:block;
    margin-bottom:10px;
    font-weight:800;
    color:#0f172a;
}
body.page-add-expense .add-expense-field-v2,
body.page-add-expense .add-expense-field-full-v2{
    min-width:0;
}
body.page-add-expense .add-expense-field-v2 > input,
body.page-add-expense .add-expense-field-v2 > select,
body.page-add-expense .add-expense-field-full-v2 > input{
    width:100%;
    max-width:100%;
    min-width:0;
    box-sizing:border-box;
}
body.page-add-expense .add-expense-field-v2 > input[type="date"]{
    display:block;
    width:100%;
    max-width:100%;
    min-width:0;
    overflow:hidden;
}
body.page-add-expense .payment-methods-grid-v2{
    display:grid;
    grid-template-columns:repeat(5, minmax(84px, 1fr));
    gap:10px;
    align-items:stretch;
}
body.page-add-expense .payment-method-option-v2 input[type="radio"]{
    position:absolute;
    opacity:0;
    pointer-events:none;
}
body.page-add-expense .payment-method-card-v2{
    border:1px solid #cbd5e1;
    background:#fff;
    border-radius:14px;
    min-height:58px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:3px;
    cursor:pointer;
    transition:.15s ease;
    text-align:center;
    padding:6px 4px;
}
body.page-add-expense .payment-method-card-v2:hover{
    border-color:#fb923c;
    background:#fff7ed;
}
body.page-add-expense .payment-method-option-v2 input[type="radio"]:checked + .payment-method-card-v2{
    background:#ff6f0f;
    border-color:#ff6f0f;
    color:#fff;
}
body.page-add-expense .payment-method-icon-v2{
    font-size:16px;
    line-height:1;
}
body.page-add-expense .payment-method-label-v2{
    font-size:11px;
    font-weight:800;
    line-height:1.1;
}
body.page-add-expense .add-expense-static-field-v2 > label{
    display:block;
    margin-bottom:10px;
    font-weight:800;
    color:#0f172a;
}
body.page-add-expense .add-expense-static-value-v2{
    min-height:58px;
    display:flex;
    align-items:center;
    border:1px dashed #fdba74;
    background:#fffaf3;
    border-radius:16px;
    padding:14px 16px;
    color:#9a3412;
    font-weight:700;
    line-height:1.35;
}
@media (max-width: 1100px){
    body.page-add-expense .payment-methods-grid-v2{
        grid-template-columns:repeat(5, minmax(72px, 1fr));
    }
}
@media (max-width: 860px){
    body.page-add-expense .add-expense-grid-bottom-v2{
        grid-template-columns:1fr;
    }
}
@media (max-width: 720px){
    body.page-add-expense .add-expense-grid-top-v2,
    body.page-add-expense .add-expense-grid-bottom-v2{
        grid-template-columns:1fr;
    }
    body.page-add-expense .add-expense-amount-row-v2{
        grid-template-columns:repeat(4, minmax(0, 1fr));
    }
    body.page-add-expense .payment-methods-grid-v2{
        grid-template-columns:repeat(3, minmax(72px, 1fr));
    }
}
</style>

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
            <div class="add-expense-reflabel-v2">Reference Donor</div>
            <div class="add-expense-refname-v2"><?= e((string)($selectedReferencePerson['name'] ?? 'No donor selected')) ?></div>
            <div class="add-expense-refmeta-v2">
                <?php if ($selectedReferencePerson): ?>
                    <?= e((string)($selectedReferencePerson['city'] ?? '')) ?>
                    <?= !empty($selectedReferencePerson['phone']) ? ' · ' . e((string)$selectedReferencePerson['phone']) : '' ?>
                <?php else: ?>
                    No linked donor profile found.
                <?php endif; ?>
            </div>
        </div>
        <div class="add-expense-refbadge-v2"><?= $modeValue === 'profile' ? 'Profile linked donor' : 'Operator linked donor' ?></div>
    </div>

    <div class="add-expense-info-v2">
        <strong>Important:</strong>
        This expense is always paid or handled by the operator. The selected donor is only a reference for reporting. It is not expense made by that person.
    </div>

    <form method="post" id="add_expense_form_v2" class="stack add-expense-form-v2">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="save_expense">
        <input type="hidden" name="operator_person_id" value="<?= (int)$operatorLinkedPersonId ?>">
        <input type="hidden" name="profile_person_id" value="<?= (int)$profilePersonId ?>">
        <input type="hidden" name="expenser_mode" value="<?= e($modeValue) ?>">
        <input type="hidden" name="is_sponsored" value="0">

        <div class="add-expense-toggle-wrap-v2">
            <label class="add-expense-toggle-v2">
                <input type="checkbox" name="is_sponsored" value="1" id="is_sponsored_toggle_v2" <?= $isSponsoredOld ? 'checked' : '' ?>>
                <span class="add-expense-toggle-slider-v2"></span>
                <span class="add-expense-toggle-text-v2">Donated / Sponsored Expense</span>
            </label>
            <div class="add-expense-toggle-help-v2">
                Turn on for reference-only value. It will be included in sponsored expense report and saved with payment method <strong>no-cash</strong>.
            </div>
        </div>

        <div class="add-expense-grid-top-v2">
            <div class="add-expense-field-v2">
                <label>Expense Item / Title</label>
                <input type="text" name="expense_title" value="<?= e(ex_old('expense_title')) ?>" placeholder="Example: Tissue roll, cleaning work, transport">
            </div>

            <div class="add-expense-field-v2">
                <label>Category</label>
                <select name="expense_category">
                    <option value="">Select category</option>
                    <?php foreach ($categories as $cat): ?>
                        <?php $catId = (string)($cat['ID'] ?? ''); ?>
                        <option value="<?= e($catId) ?>" <?= ex_old('expense_category') === $catId ? 'selected' : '' ?>>
                            <?= e((string)($cat['category_name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="add-expense-amount-block-v2">
            <div class="add-expense-field-full-v2">
                <label><span id="amount_label_v2"><?= $isSponsoredOld ? 'Value' : 'Amount' ?></span></label>
                <input type="number" step="0.01" min="0.01" name="amount" id="amount_input_v2" value="<?= e(ex_old('amount')) ?>" placeholder="0.00">
            </div>
        </div>

        <div class="add-expense-amount-row-v2">
            <?php foreach ([10, 20, 50, 100, 200, 500, 1000] as $inc): ?>
                <button type="button" class="add-expense-mini-btn-v2 amount-add-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
            <?php endforeach; ?>
        </div>

        <div class="add-expense-payment-row-v2">
            <div class="add-expense-field-full-v2" id="payment_method_wrap_v2">
                <label>Payment Method</label>
                <div class="payment-methods-grid-v2">
                    <?php foreach ($paymentMethodMap as $value => $method): ?>
                        <label class="payment-method-option-v2">
                            <input type="radio" name="payment_method" value="<?= e($value) ?>" <?= $selectedMethod === $value ? 'checked' : '' ?>>
                            <span class="payment-method-card-v2">
                                <span class="payment-method-icon-v2"><?= e($method['icon']) ?></span>
                                <span class="payment-method-label-v2"><?= e($method['label']) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="add-expense-static-field-v2" id="payment_method_info_v2" style="display:none;">
                <label>Payment Method</label>
                <div class="add-expense-static-value-v2">No cash movement (saved as no-cash)</div>
            </div>
        </div>

        <div class="add-expense-grid-bottom-v2">
            <div class="add-expense-field-v2">
                <label>Date</label>
                <input type="date" name="expense_date" value="<?= e(ex_old('expense_date', date('Y-m-d'))) ?>">
            </div>

            <div class="add-expense-field-v2">
                <label>Notes</label>
                <input type="text" name="notes" value="<?= e(ex_old('notes')) ?>" placeholder="Optional note">
            </div>
        </div>

        <div class="toolbar add-expense-toolbar-v2">
            <button type="submit" class="btn btn-primary" id="save_btn_v2"><?= $isSponsoredOld ? 'Save Sponsored Expense' : 'Save Expense' ?></button>
        </div>
    </form>
</div>

<script>
(function () {
    document.querySelectorAll('.amount-add-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById('amount_input_v2');
            if (!input) return;
            var add = parseFloat(btn.getAttribute('data-add') || '0');
            var current = parseFloat(input.value || '0');
            input.value = (current + add).toFixed(2);
        });
    });

    var sponsoredToggle = document.getElementById('is_sponsored_toggle_v2');
    var paymentWrap = document.getElementById('payment_method_wrap_v2');
    var paymentInfo = document.getElementById('payment_method_info_v2');
    var amountLabel = document.getElementById('amount_label_v2');
    var saveBtn = document.getElementById('save_btn_v2');

    function syncSponsoredMode() {
        var isSponsored = !!(sponsoredToggle && sponsoredToggle.checked);
        if (paymentWrap) paymentWrap.style.display = isSponsored ? 'none' : '';
        if (paymentInfo) paymentInfo.style.display = isSponsored ? '' : 'none';
        if (amountLabel) amountLabel.textContent = isSponsored ? 'Value' : 'Amount';
        if (saveBtn) saveBtn.textContent = isSponsored ? 'Save Sponsored Expense' : 'Save Expense';
    }

    if (sponsoredToggle) {
        sponsoredToggle.addEventListener('change', syncSponsoredMode);
        syncSponsoredMode();
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
