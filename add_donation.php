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

function dn_old($key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function loadPersonById(PDO $pdo, int $personId): ?array
{
    if ($personId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
    $stmt->execute([$personId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function resolveDonationReferencePerson(string $mode, ?array $operatorPerson, ?array $profilePerson): ?array
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

$operatorPerson = loadPersonById($pdo, $operatorLinkedPersonId);

/*
|--------------------------------------------------------------------------
| Profile person if opened from person_profile.php?id=...
|--------------------------------------------------------------------------
*/
$profilePersonId = (int)($_GET['person_id'] ?? $_POST['profile_person_id'] ?? 0);
$profilePerson = loadPersonById($pdo, $profilePersonId);

if (!$profilePerson) {
    $profilePersonId = 0;
}

/*
|--------------------------------------------------------------------------
| Mode is forced by page open context
|--------------------------------------------------------------------------
*/
$hasProfilePerson = $profilePersonId > 0;
$modeValue = $hasProfilePerson ? 'someone_else' : 'operator';

$selectedReferencePerson = resolveDonationReferencePerson($modeValue, $operatorPerson, $profilePerson);
$selectedReferencePersonId = (int)($selectedReferencePerson['ID'] ?? 0);

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/
$categories = [];
if (tableExists($pdo, 'donation_categories')) {
    try {
        $categories = $pdo->query("SELECT ID, name FROM donation_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $categories = [];
    }
}

if (!$categories) {
    $categories = [
        ['ID' => 'materials', 'name' => 'Materials'],
        ['ID' => 'food', 'name' => 'Food'],
        ['ID' => 'cleaning', 'name' => 'Cleaning'],
        ['ID' => 'service', 'name' => 'Service'],
        ['ID' => 'equipment', 'name' => 'Equipment'],
        ['ID' => 'utility_support', 'name' => 'Utility Support'],
        ['ID' => 'other', 'name' => 'Other'],
    ];
}

/*
|--------------------------------------------------------------------------
| Save
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'save_donation') {
    verifyCsrfOrFail();

    $profilePersonId = (int)($_POST['profile_person_id'] ?? 0);
    $profilePerson = loadPersonById($pdo, $profilePersonId);

    $hasProfilePerson = $profilePersonId > 0 && $profilePerson;
    $modeValue = $hasProfilePerson ? 'someone_else' : 'operator';

    $operatorLinkedPersonId = (int)($_POST['operator_person_id'] ?? $operatorLinkedPersonId);
    $operatorPerson = loadPersonById($pdo, $operatorLinkedPersonId);

    $itemTitle = trim((string)($_POST['item_title'] ?? ''));
    $category = trim((string)($_POST['donation_category'] ?? ''));
    $estimatedValue = (float)($_POST['estimated_value'] ?? 0);
    $quantity = trim((string)($_POST['quantity'] ?? ''));
    $donationDate = trim((string)($_POST['donation_date'] ?? date('Y-m-d')));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($modeValue === 'operator' && !$operatorPerson) {
        $errors[] = 'Your operator account is not linked with any donor profile.';
    }

    if ($modeValue === 'someone_else' && !$profilePerson) {
        $errors[] = 'No profile person was selected. Open this page from a person profile.';
    }

    if ($itemTitle === '') {
        $errors[] = 'Please enter donation item or title.';
    }

    if ($category === '') {
        $errors[] = 'Please select a donation category.';
    }

    if ($estimatedValue <= 0) {
        $errors[] = 'Estimated value must be greater than zero.';
    }

    if ($donationDate === '') {
        $errors[] = 'Date is required.';
    }

    $selectedReferencePerson = resolveDonationReferencePerson($modeValue, $operatorPerson, $profilePerson);
    $selectedReferencePersonId = (int)($selectedReferencePerson['ID'] ?? 0);

    if ($selectedReferencePersonId <= 0) {
        $errors[] = 'Reference person could not be found.';
    }

    if (!$errors) {
        $saved = false;
        $safeQuantity = $quantity !== '' ? $quantity : null;
        $referenceName = (string)($selectedReferencePerson['name'] ?? 'Unknown');

        $modeNote = $modeValue === 'operator'
            ? 'Reference person is operator linked donor ' . (string)($operatorPerson['name'] ?? 'Unknown')
            : 'Reference person selected from profile ' . (string)($profilePerson['name'] ?? 'Unknown');

        $finalNotes = trim($modeNote . ($notes !== '' ? ' | ' . $notes : ''));

        if (tableExists($pdo, 'informational_donations')) {
            $stmt = $pdo->prepare('
                INSERT INTO informational_donations
                    (person_id, item_title, category, estimated_value, quantity, notes, created_by, donation_date, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $selectedReferencePersonId,
                $itemTitle,
                $category,
                $estimatedValue,
                $safeQuantity,
                $finalNotes,
                $uid,
                $donationDate,
            ]);
            $saved = true;
        } elseif (tableExists($pdo, 'operator_ledger')) {
            $ledgerNoteParts = [
                'Informational donation only - no deduction from operator balance',
                'Reference person: ' . $referenceName,
                'Donation item: ' . $itemTitle,
                'Estimated value: ' . number_format($estimatedValue, 2, '.', ''),
            ];

            if ($safeQuantity !== null) {
                $ledgerNoteParts[] = 'Quantity: ' . $safeQuantity;
            }

            if ($finalNotes !== '') {
                $ledgerNoteParts[] = $finalNotes;
            }

            $stmt = $pdo->prepare('
                INSERT INTO operator_ledger
                    (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, notes, created_by, created_at)
                VALUES
                    (?, ?, "expense", ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $uid,
                $selectedReferencePersonId,
                'donation_value',
                0.00,
                'cash',
                implode(' | ', $ledgerNoteParts),
                $uid,
                $donationDate . ' 12:00:00',
            ]);
            $saved = true;
        }

        if ($saved) {
            setFlash('success', 'Donation value saved successfully. No operator balance was deducted.');
            header('Location: person_profile.php?id=' . $selectedReferencePersonId);
            exit;
        }

        $errors[] = 'No supported table was found to save this donation entry.';
    }
}

$pageClass = 'page-add-donation';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Add Donation</h1>

<div class="card add-donation-card-v2 stack">
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

    <div class="add-donation-refbar-v2">
        <div class="add-donation-refmain-v2">
            <div class="add-donation-reflabel-v2">Reference Person</div>
            <div class="add-donation-refname-v2">
                <?= e((string)($selectedReferencePerson['name'] ?? 'No person selected')) ?>
            </div>
            <div class="add-donation-refmeta-v2">
                <?php if ($selectedReferencePerson): ?>
                    <?= e((string)($selectedReferencePerson['city'] ?? '')) ?>
                    <?= !empty($selectedReferencePerson['phone']) ? ' · ' . e((string)$selectedReferencePerson['phone']) : '' ?>
                <?php else: ?>
                    No linked donor profile found.
                <?php endif; ?>
            </div>
        </div>

        <div class="add-donation-refbadge-v2">
            <?= $modeValue === 'operator' ? 'Operator' : 'Someone Else' ?>
        </div>
    </div>

    <form method="post" id="add_donation_form_v2" class="stack add-donation-form-v2">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="save_donation">
        <input type="hidden" name="operator_person_id" value="<?= (int)$operatorLinkedPersonId ?>">
        <input type="hidden" name="profile_person_id" value="<?= (int)$profilePersonId ?>">
        <input type="hidden" name="donation_mode" value="<?= e($modeValue) ?>">

        <div class="add-donation-grid-top-v2">
            <div>
                <label>Donation Item / Title</label>
                <input
                    type="text"
                    name="item_title"
                    value="<?= e((string)dn_old('item_title', '')) ?>"
                    placeholder="Example: Tissue roll, cleaning service, chairs"
                >
            </div>

            <div>
                <label>Category</label>
                <select name="donation_category">
                    <option value="">Select category</option>
                    <?php foreach ($categories as $cat): ?>
                        <?php $catId = (string)$cat['ID']; ?>
                        <option value="<?= e($catId) ?>" <?= (string)dn_old('donation_category', '') === $catId ? 'selected' : '' ?>>
                            <?= e((string)$cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="add-donation-grid-mid-v2">
            <div>
                <label>Estimated Value</label>
                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    name="estimated_value"
                    id="estimated_value_input_v2"
                    value="<?= e((string)dn_old('estimated_value', '')) ?>"
                    placeholder="0.00"
                >

                <div class="add-donation-amount-row-v2">
                    <?php foreach ([10, 20, 50, 100] as $inc): ?>
                        <button type="button" class="add-donation-mini-btn-v2 amount-add-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label>Quantity</label>
                <input
                    type="text"
                    name="quantity"
                    value="<?= e((string)dn_old('quantity', '')) ?>"
                    placeholder="Example: 24 rolls / 10 boxes / 1 service"
                >
            </div>

            <div>
                <label>Date</label>
                <input
                    type="date"
                    name="donation_date"
                    value="<?= e((string)dn_old('donation_date', date('Y-m-d'))) ?>"
                >
            </div>
        </div>

        <div>
            <label>Notes</label>
            <input
                type="text"
                name="notes"
                value="<?= e((string)dn_old('notes', '')) ?>"
                placeholder="Optional note for planning or description"
            >
        </div>

        <div class="toolbar add-donation-toolbar-v2">
            <button type="submit" class="btn btn-primary">Save Donation Value</button>
        </div>
    </form>
</div>

<script>
(function () {
    document.querySelectorAll('.amount-add-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById('estimated_value_input_v2');
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