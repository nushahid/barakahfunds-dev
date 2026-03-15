<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$uid = getLoggedInUserId();
$errors = [];
$success = getFlash('success');
$errorFlash = getFlash('error');

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

function support_old(string $key, $default = '') {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'save_direct_support') {
    verifyCsrfOrFail();

    $selectedPersonId = (int)($_POST['person_id'] ?? 0);
    $category = trim((string)($_POST['support_category'] ?? ''));
    $supportValue = (float)($_POST['support_value'] ?? 0);
    $supportDate = trim((string)($_POST['support_date'] ?? date('Y-m-d')));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($selectedPersonId <= 0) $errors[] = 'Please select a person first.';
    if ($category === '') $errors[] = 'Please select a support category.';
    if ($supportValue <= 0) $errors[] = 'Value must be greater than zero.';
    if ($supportDate === '') $errors[] = 'Date is required.';

    if ($selectedPersonId > 0 && !$selectedPerson) {
        $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
        $stmt->execute([$selectedPersonId]);
        $selectedPerson = $stmt->fetch();
    }

    if (!$errors) {
        $selectedName = (string)($selectedPerson['name'] ?? 'Selected person');
        $finalNotes = 'Direct Support | Paid personally by ' . $selectedName . ' | Recorded value: ' . number_format($supportValue, 2, '.', '') . ($notes !== '' ? ' | ' . $notes : '');

        if (tableExists($pdo, 'operator_ledger')) {
            $stmt = $pdo->prepare('INSERT INTO operator_ledger (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, notes, created_by, created_at) VALUES (?, ?, "expense", ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $uid,
                $selectedPersonId,
                $category,
                0.00,
                'cash',
                $finalNotes,
                $uid,
                $supportDate . ' 12:00:00'
            ]);
        }

        setFlash('success', 'Direct support saved successfully.');
        header('Location: person_profile.php?id=' . $selectedPersonId);
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="direct_support_page.css">

<h1 class="title">Add Direct Support</h1>

<div class="card support-card-v6 stack">
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($errorFlash): ?><div class="alert error"><?= e($errorFlash) ?></div><?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="get" class="support-search-wrap-v6">
        <div class="support-search-row-v6">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search person by name or phone" class="support-search-input-v6">
            <button type="submit" class="btn support-search-btn-v6">Search</button>
        </div>
        <div class="support-search-actions-v6">
            <a href="add_person.php" class="btn btn-primary support-add-btn-v6">Add New Donor</a>
        </div>
    </form>

    <?php if ($q !== '' && !$selectedPerson): ?>
        <div id="support_person_results" class="support-results-v6">
            <?php if ($results): ?>
                <?php foreach ($results as $row): ?>
                    <button type="button" class="support-person-result-v6"
                        data-person-id="<?= (int)$row['ID'] ?>"
                        data-person-name="<?= e((string)$row['name']) ?>"
                        data-person-city="<?= e((string)($row['city'] ?: '')) ?>"
                        data-person-phone="<?= e((string)($row['phone'] ?: '')) ?>">
                        <span class="support-person-left-v6">
                            <strong><?= e((string)$row['name']) ?></strong>
                            <small><?= e((string)($row['city'] ?: '')) ?></small>
                        </span>
                        <span class="support-person-right-v6"><?= e((string)($row['phone'] ?: '—')) ?></span>
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="muted">No person found.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" id="direct_support_form_v6" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="save_direct_support">
        <input type="hidden" name="person_id" id="support_person_id" value="<?= (int)($selectedPerson['ID'] ?? $selectedPersonId) ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">

        <div id="selected_support_person_box" class="support-selected-v6<?= $selectedPerson ? ' is-selected' : '' ?>">
            <div class="muted">Selected person</div>
            <div id="selected_support_person_name" class="support-selected-name-v6"><?= e((string)($selectedPerson['name'] ?? 'No person selected')) ?></div>
            <div id="selected_support_person_meta" class="support-selected-meta-v6"><?php if ($selectedPerson): ?><?= e((string)($selectedPerson['city'] ?: '')) ?><?= !empty($selectedPerson['phone']) ? ' · ' . e((string)$selectedPerson['phone']) : '' ?><?php endif; ?></div>
        </div>

        <div id="support_fields_section" class="<?= $selectedPerson ? '' : 'is-disabled' ?>">
            <div class="section-title">Direct Support</div>
            <div class="support-helper-v6">Paid personally by the selected person. Recorded for value only, with no cash balance effect.</div>

            <div class="section-title">Support Category</div>
            <select name="support_category">
                <option value="">Select category</option>
                <?php $catValue = (string)support_old('support_category', ''); ?>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e((string)$cat['ID']) ?>" <?= $catValue === (string)$cat['ID'] ? 'selected' : '' ?>><?= e((string)$cat['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="section-title">Support Value</div>
            <div class="stack compact">
                <input type="number" step="0.01" min="0.01" name="support_value" id="support_value_input_v6" value="<?= e((string)support_old('support_value', '')) ?>" placeholder="0.00">
                <div class="support-amount-row-v6">
                    <?php foreach ([10,20,50,100,500,1000] as $inc): ?>
                        <button type="button" class="support-mini-card-v6 support-add-value-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="support-date-notes-grid-v6">
                <div>
                    <label>Date</label>
                    <input type="date" name="support_date" value="<?= e((string)support_old('support_date', date('Y-m-d'))) ?>">
                </div>
                <div>
                    <label>Notes</label>
                    <input type="text" name="notes" value="<?= e((string)support_old('notes', '')) ?>" placeholder="Optional note">
                </div>
            </div>

            <div class="toolbar support-toolbar-v6">
                <button type="submit" class="btn btn-primary">Save Direct Support</button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const resultWrap = document.getElementById('support_person_results');
    const personInput = document.getElementById('support_person_id');
    const selectedBox = document.getElementById('selected_support_person_box');
    const selectedName = document.getElementById('selected_support_person_name');
    const selectedMeta = document.getElementById('selected_support_person_meta');
    const fieldSection = document.getElementById('support_fields_section');

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
    }

    if (resultWrap) {
        resultWrap.addEventListener('click', function (e) {
            const btn = e.target.closest('.support-person-result-v6');
            if (!btn) return;
            e.preventDefault();
            selectPerson(btn.getAttribute('data-person-id') || '', btn.getAttribute('data-person-name') || '', btn.getAttribute('data-person-city') || '', btn.getAttribute('data-person-phone') || '');
        });
    }

    document.querySelectorAll('.support-add-value-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById('support_value_input_v6');
            const add = parseFloat(btn.getAttribute('data-add') || '0');
            const current = parseFloat(input.value || '0');
            input.value = (current + add).toFixed(2);
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
