<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

requireAccountant($pdo);

$uid = getLoggedInUserId();
$errors = [];
$editId = (int)($_GET['edit'] ?? 0);

$allowedTypes = ['Receive', 'Provide'];
$allowedMethods = ['cash', 'bank_transfer', 'pos', 'online'];

function isValidDateValue(string $date): bool
{
    if ($date === '') {
        return false;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

function loan_old(array $formData, string $key, $default = '')
{
    return $formData[$key] ?? $default;
}

$rows = $pdo->query('
    SELECT l.*, p.name AS person_name, p.city AS person_city, p.phone AS person_phone
    FROM loan l
    LEFT JOIN people p ON p.ID = l.pid
    ORDER BY l.ID DESC
')->fetchAll();

$editRow = null;
foreach ($rows as $row) {
    if ((int)$row['ID'] === $editId) {
        $editRow = $row;
        break;
    }
}

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$selectedDonorId = 0;
$selectedDonor = null;

if ($editRow) {
    $selectedDonorId = (int)($editRow['pid'] ?? 0);
} else {
    $selectedDonorId = (int)($_POST['person_id'] ?? ($_GET['person_id'] ?? 0));
}

if ($selectedDonorId > 0) {
    $stmt = $pdo->prepare('SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1');
    $stmt->execute([$selectedDonorId]);
    $selectedDonor = $stmt->fetch() ?: null;

    if ($selectedDonor && $q === '') {
        $q = (string)$selectedDonor['name'];
    }
}

$results = [];
if ($q !== '') {
    $stmt = $pdo->prepare('
        SELECT ID, name, city, phone
        FROM people
        WHERE name LIKE ? OR phone LIKE ? OR city LIKE ? OR CAST(ID AS CHAR) LIKE ?
        ORDER BY name ASC
        LIMIT 30
    ');
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like, $like]);
    $results = $stmt->fetchAll();
}

$formData = [
    'person_id'      => (int)($selectedDonor['ID'] ?? $selectedDonorId),
    'name'           => (string)($editRow['name'] ?? ''),
    'amount'         => (string)($editRow['amount'] ?? ''),
    'received_date'  => (string)($editRow['received_date'] ?? date('Y-m-d')),
    'return_date'    => (string)($editRow['return_date'] ?? date('Y-m-d', strtotime('+30 days'))),
    'type'           => (string)($editRow['type'] ?? 'Receive'),
    'method'         => (string)($editRow['method'] ?? 'cash'),
    'notes'          => (string)($editRow['notes'] ?? ''),
    'returned'       => (int)($editRow['returned'] ?? 0),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();

    $action = (string)($_POST['action'] ?? 'create');
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $selectedDonorId = (int)($_POST['person_id'] ?? 0);

    if ($selectedDonorId > 0) {
        $stmt = $pdo->prepare('SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1');
        $stmt->execute([$selectedDonorId]);
        $selectedDonor = $stmt->fetch() ?: null;
    } else {
        $selectedDonor = null;
    }

    $formData = [
        'person_id'      => $selectedDonorId,
        'name'           => trim((string)($_POST['name'] ?? '')),
        'amount'         => (string)($_POST['amount'] ?? ''),
        'received_date'  => (string)($_POST['received_date'] ?? date('Y-m-d')),
        'return_date'    => (string)($_POST['return_date'] ?? date('Y-m-d', strtotime('+30 days'))),
        'type'           => (string)($_POST['type'] ?? 'Receive'),
        'method'         => normalizePaymentMethod((string)($_POST['method'] ?? 'cash')),
        'notes'          => trim((string)($_POST['notes'] ?? '')),
        'returned'       => (int)($_POST['returned'] ?? 0) === 1 ? 1 : 0,
    ];

    $amount = (float)$formData['amount'];

    if ($formData['person_id'] <= 0) {
        $errors[] = 'Select donor.';
    }

    if ($formData['person_id'] > 0 && !$selectedDonor) {
        $errors[] = 'Selected donor was not found.';
    }

    if ($formData['name'] === '') {
        $errors[] = 'Loan name required.';
    }

    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than 0.';
    }

    if (!in_array($formData['type'], $allowedTypes, true)) {
        $errors[] = 'Invalid loan type selected.';
    }

    if (!in_array($formData['method'], $allowedMethods, true)) {
        $errors[] = 'Invalid payment method selected.';
    }

    if (!isValidDateValue($formData['received_date'])) {
        $errors[] = 'Received date is invalid.';
    }

    if (!isValidDateValue($formData['return_date'])) {
        $errors[] = 'Return date is invalid.';
    }

    if (
        isValidDateValue($formData['received_date']) &&
        isValidDateValue($formData['return_date']) &&
        $formData['return_date'] < $formData['received_date']
    ) {
        $errors[] = 'Return date cannot be earlier than received date.';
    }

    if (!$errors) {
        if ($action === 'update' && $loanId > 0) {
            $stmt = $pdo->prepare('
                UPDATE loan
                SET pid = ?, name = ?, amount = ?, received_date = ?, return_date = ?, type = ?, method = ?, notes = ?, returned = ?
                WHERE ID = ?
            ');
            $stmt->execute([
                $formData['person_id'],
                $formData['name'],
                $amount,
                $formData['received_date'],
                $formData['return_date'],
                $formData['type'],
                $formData['method'],
                $formData['notes'],
                $formData['returned'],
                $loanId
            ]);

            systemLog($pdo, $uid, 'loan', 'update', 'Loan updated: ' . $formData['name'], $loanId);
            setFlash('success', 'Loan updated.');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO loan (pid, name, amount, received_date, return_date, type, method, notes, uid, returned, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $formData['person_id'],
                $formData['name'],
                $amount,
                $formData['received_date'],
                $formData['return_date'],
                $formData['type'],
                $formData['method'],
                $formData['notes'],
                $uid,
                0
            ]);

            systemLog($pdo, $uid, 'loan', 'create', 'Loan created: ' . $formData['name']);
            setFlash('success', 'Loan saved.');
        }

        header('Location: loan_page.php');
        exit;
    }
}

$autoJumpToForm = $selectedDonorId > 0 && $selectedDonor && $_SERVER['REQUEST_METHOD'] !== 'POST';

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Loans</h1>

<div class="card loan-card-v5 stack">
    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $er): ?>
                <div><?= e($er) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="loan-search-wrap-v5">
        <?php if ($editRow): ?>
            <input type="hidden" name="edit" value="<?= (int)$editRow['ID'] ?>">
        <?php endif; ?>
<div class="loan-search-row-v5">
    <input
        type="text"
        id="donor_search"
        name="q"
        value="<?= e($q) ?>"
        placeholder="Search donor by name, phone, city or donor ID"
        class="loan-search-input-v5"
        autocomplete="off"
    >
</div>

<div id="loan_donor_results" class="loan-results-v5">
    <?php if ($q !== '' && !$selectedDonor): ?>
        <?php if ($results): ?>
            <?php foreach ($results as $row): ?>
                <button
                    type="button"
                    class="loan-donor-result-v5"
                    data-donor-id="<?= (int)$row['ID'] ?>"
                    data-donor-name="<?= e((string)$row['name']) ?>"
                    data-donor-city="<?= e((string)($row['city'] ?: '')) ?>"
                    data-donor-phone="<?= e((string)($row['phone'] ?: '')) ?>"
                >
                    <span class="loan-donor-left-v5">
                        <strong><?= e((string)$row['name']) ?></strong>
                        <small>
                            ID <?= (int)$row['ID'] ?><?= !empty($row['city']) ? ' · ' . e((string)$row['city']) : '' ?>
                        </small>
                    </span>
                    <span class="loan-donor-right-v5"><?= e((string)($row['phone'] ?: '—')) ?></span>
                </button>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="loan-empty-v5 muted">No donor found.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

    <form method="post" class="loan-form-wrap-v5 stack" id="loan_form_v5">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
        <input type="hidden" name="person_id" id="loan_person_id" value="<?= (int)loan_old($formData, 'person_id', 0) ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">

        <?php if ($editRow): ?>
            <input type="hidden" name="loan_id" value="<?= (int)$editRow['ID'] ?>">
        <?php endif; ?>

<div id="loan_selected_donor_box" class="loan-selected-v5<?= $selectedDonor ? ' is-selected' : '' ?>">
    <div class="muted">Selected donor</div>

    <div id="loan_selected_donor_name" class="loan-selected-name-v5">
        <?= e((string)($selectedDonor['name'] ?? 'No donor selected')) ?>
    </div>

    <div id="loan_selected_donor_meta" class="loan-selected-meta-v5">
        <?php if ($selectedDonor): ?>
            ID <?= (int)$selectedDonor['ID'] ?>
            <?= !empty($selectedDonor['city']) ? ' · ' . e((string)$selectedDonor['city']) : '' ?>
            <?= !empty($selectedDonor['phone']) ? ' · ' . e((string)$selectedDonor['phone']) : '' ?>
        <?php else: ?>
            Select a donor from search results.
        <?php endif; ?>
    </div>
</div>

        <div id="loan_fields_section" class="<?= $selectedDonor ? '' : 'is-disabled' ?>">
            <div class="loan-toolbar-v5">
                <h2 class="loan-subtitle"><?= $editRow ? 'Edit Loan' : 'New Loan' ?></h2>
                <?php if ($editRow): ?>
                    <a class="btn" href="loan_page.php">Cancel</a>
                <?php endif; ?>
            </div>

            <div>
                <label>Loan Name</label>
                <input type="text" name="name" value="<?= e((string)loan_old($formData, 'name', '')) ?>" required>
            </div>

            <div>
                <label>Amount</label>
                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    name="amount"
                    id="loan_amount_input_v5"
                    value="<?= e((string)loan_old($formData, 'amount', '')) ?>"
                    required
                >
                <div class="loan-amount-row-v5">
                    <?php foreach ([10, 20, 50, 100, 500, 1000] as $inc): ?>
                        <button type="button" class="loan-mini-card-v5 loan-amount-add-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="loan-date-grid-v5">
                <div>
                    <label>Received Date</label>
                    <input type="date" name="received_date" value="<?= e((string)loan_old($formData, 'received_date', date('Y-m-d'))) ?>" required>
                </div>
                <div>
                    <label>Return Date</label>
                    <input type="date" name="return_date" value="<?= e((string)loan_old($formData, 'return_date', date('Y-m-d', strtotime('+30 days')))) ?>" required>
                </div>
            </div>

            <div>
                <div class="section-title">Type</div>
                <div class="loan-type-row-v5">
                    <?php foreach (['Receive' => '📥', 'Provide' => '📤'] as $typeOption => $icon): ?>
                        <label class="loan-option-v5">
                            <input
                                type="radio"
                                name="type"
                                value="<?= e($typeOption) ?>"
                                <?= (string)loan_old($formData, 'type', 'Receive') === $typeOption ? 'checked' : '' ?>
                            >
                            <span class="loan-pill-v5">
                                <span class="icon"><?= $icon ?></span>
                                <span><?= e($typeOption) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <div class="section-title">Method</div>
                <div class="loan-payment-row-v5">
                    <?php
                    $methodOptions = [
                        ['cash', '💵', 'Cash'],
                        ['bank_transfer', '🏦', 'Bank Transfer'],
                        ['pos', '💳', 'POS'],
                        ['online', '🌐', 'Online'],
                    ];
                    ?>
                    <?php foreach ($methodOptions as $m): ?>
                        <label class="loan-option-v5">
                            <input
                                type="radio"
                                name="method"
                                value="<?= e($m[0]) ?>"
                                <?= (string)loan_old($formData, 'method', 'cash') === $m[0] ? 'checked' : '' ?>
                            >
                            <span class="loan-pill-v5">
                                <span class="icon"><?= e($m[1]) ?></span>
                                <span><?= e($m[2]) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label>Notes</label>
                <textarea name="notes"><?= e((string)loan_old($formData, 'notes', '')) ?></textarea>
            </div>

            <?php if ($editRow): ?>
                <div class="loan-returned-box-v5">
                    <div>
                        <strong>Returned</strong>
                        <div class="muted">Mark returned loans without deleting the record.</div>
                    </div>
                    <label class="loan-returned-check-v5">
                        <input type="checkbox" name="returned" value="1" <?= (int)loan_old($formData, 'returned', 0) === 1 ? 'checked' : '' ?>>
                        <span>Returned</span>
                    </label>
                </div>
            <?php endif; ?>

            <div class="loan-submit-wrap-v5">
                <button class="btn btn-primary" type="submit">
                    <?= $editRow ? 'Update Loan' : 'Save Loan' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<div class="card loan-list-card-v5">
    <div class="loan-toolbar-v5">
        <h2 class="loan-subtitle">Loan List</h2>
        <span class="tag blue"><?= count($rows) ?> total</span>
    </div>

    <div class="loan-table-wrap-v5 table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Person</th>
                    <th>Loan</th>
                    <th>Amount</th>
                    <th>Received</th>
                    <th>Return</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e((string)$row['person_name']) ?></td>
                            <td><?= e((string)$row['name']) ?></td>
                            <td><?= money((float)$row['amount']) ?></td>
                            <td><?= e((string)$row['received_date']) ?></td>
                            <td><?= e((string)$row['return_date']) ?></td>
                            <td><?= e((string)$row['type']) ?></td>
                            <td><?= e((string)$row['method']) ?></td>
                            <td>
                                <span class="tag <?= (int)$row['returned'] === 1 ? 'green' : 'orange' ?>">
                                    <?= (int)$row['returned'] === 1 ? 'Returned' : 'Active' ?>
                                </span>
                            </td>
                            <td>
                                <a class="btn" href="loan_page.php?edit=<?= (int)$row['ID'] ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="muted">No loans yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const resultWrap = document.getElementById('loan_donor_results');
    const personInput = document.getElementById('loan_person_id');
    const selectedBox = document.getElementById('loan_selected_donor_box');
    const selectedName = document.getElementById('loan_selected_donor_name');
    const selectedMeta = document.getElementById('loan_selected_donor_meta');
    const fieldSection = document.getElementById('loan_fields_section');

    function enableFields() {
        if (fieldSection) fieldSection.classList.remove('is-disabled');
        if (selectedBox) selectedBox.classList.add('is-selected');
    }

    function goForm() {
        if (fieldSection) {
            fieldSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function selectDonor(id, name, city, phone) {
        if (personInput) personInput.value = id;
        if (selectedName) selectedName.textContent = name || 'Selected donor';
        if (selectedMeta) {
            selectedMeta.textContent = ['ID ' + id, city || '', phone || ''].filter(Boolean).join(' · ');
        }
        enableFields();
        if (resultWrap) {
            resultWrap.innerHTML = '';
            resultWrap.style.display = 'none';
        }
        goForm();
    }

    if (resultWrap) {
        resultWrap.addEventListener('click', function (e) {
            const btn = e.target.closest('.loan-donor-result-v5');
            if (!btn) return;
            e.preventDefault();
            selectDonor(
                btn.dataset.donorId || '',
                btn.dataset.donorName || '',
                btn.dataset.donorCity || '',
                btn.dataset.donorPhone || ''
            );
        });
    }

    document.querySelectorAll('.loan-amount-add-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById('loan_amount_input_v5');
            if (!input) return;
            const add = parseFloat(btn.dataset.add || '0');
            const current = parseFloat(input.value || '0');
            input.value = (current + add).toFixed(2);
        });
    });

    if (<?= $autoJumpToForm ? 'true' : 'false' ?>) {
        enableFields();
        window.setTimeout(goForm, 120);
    }
})();
</script>

<script>
(function () {
    const searchInput = document.getElementById('donor_search');
    const resultsBox = document.getElementById('loan_donor_results');
    const searchBtn = document.getElementById('loan_search_btn');
    const personInput = document.getElementById('loan_person_id');
    const selectedBox = document.getElementById('loan_selected_donor_box');
    const selectedName = document.getElementById('loan_selected_donor_name');
    const selectedMeta = document.getElementById('loan_selected_donor_meta');
    const fieldSection = document.getElementById('loan_fields_section');

    let timer = null;

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function renderResults(data) {
        let html = '';

        if (!Array.isArray(data) || data.length === 0) {
            resultsBox.innerHTML = '<div class="loan-empty-v5 muted">No donor found.</div>';
            return;
        }

        data.forEach(function (p) {
            const id = parseInt(p.ID || 0, 10);
            const name = p.name || '';
            const city = p.city || '';
            const phone = p.phone || '';

            html += `
                <button
                    type="button"
                    class="loan-donor-result-v5"
                    data-id="${id}"
                    data-name="${esc(name)}"
                    data-city="${esc(city)}"
                    data-phone="${esc(phone)}"
                >
                    <span class="loan-donor-left-v5">
                        <strong>${esc(name)}</strong>
                        <small>ID ${id}${city ? ' · ' + esc(city) : ''}</small>
                    </span>
                    <span class="loan-donor-right-v5">${phone ? esc(phone) : '—'}</span>
                </button>
            `;
        });

        resultsBox.innerHTML = html;
    }

    function runSearch() {
        const q = searchInput.value.trim();

        if (q.length < 2) {
            resultsBox.innerHTML = '';
            return;
        }

        fetch('ajax_search_people.php?q=' + encodeURIComponent(q))
            .then(function (res) { return res.json(); })
            .then(function (data) { renderResults(data); })
            .catch(function () {
                resultsBox.innerHTML = '<div class="loan-empty-v5 muted">Search failed.</div>';
            });
    }

    function selectDonor(id, name, city, phone) {
        personInput.value = id;
        selectedName.textContent = name || 'No donor selected';
        selectedMeta.textContent = ['ID ' + id, city || '', phone || ''].filter(Boolean).join(' · ');
        selectedBox.classList.add('is-selected');
        fieldSection.classList.remove('is-disabled');
        resultsBox.innerHTML = '';
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(runSearch, 250);
        });
    }

    if (searchBtn) {
        searchBtn.addEventListener('click', runSearch);
    }

    if (resultsBox) {
        resultsBox.addEventListener('click', function (e) {
            const card = e.target.closest('.loan-donor-result-v5');
            if (!card) return;

            e.preventDefault();

            selectDonor(
                card.dataset.id || '',
                card.dataset.name || '',
                card.dataset.city || '',
                card.dataset.phone || ''
            );
        });
    }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>