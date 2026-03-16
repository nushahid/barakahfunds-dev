<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, ['operator','accountant']);

if (function_exists('migrateV42')) {
    migrateV42($pdo);
}

$uid = getLoggedInUserId();
$role = currentRole($pdo);
$errors = [];

function anonymousCollectionTypeLabel(string $type): string
{
    return match ($type) {
        'donation_box' => 'Donation Box',
        'jumma' => 'Jumma',
        'misc' => 'Misc',
        default => 'Anonymous',
    };
}

function anonymousCollectionMethodLabel(string $method): string
{
    return match ($method) {
        'bank' => 'Bank',
        'pos' => 'POS',
        'stripe' => 'Stripe',
        'online' => 'Online',
        default => 'Cash',
    };
}

function anonymousCollectionMethodIcon(string $method): string
{
    return match ($method) {
        'bank' => '🏦',
        'pos' => '💳',
        'stripe' => '🟪',
        'online' => '🌐',
        default => '💵',
    };
}

function ensureAnonymousDefaultPeople(PDO $pdo, int $createdBy = 0): array
{
    $defaults = [
        'anonymous' => 'Anonymous Donation',
        'donation_box' => 'Mosque Donation Box',
        'jumma' => 'Jumma Prayer Collection',
        'misc' => 'Miscellaneous Collection',
    ];

    $select = $pdo->prepare('SELECT ID FROM people WHERE name = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO people (name, notes, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');

    $result = [];
    foreach ($defaults as $type => $name) {
        $select->execute([$name]);
        $id = (int)($select->fetchColumn() ?: 0);
        if ($id <= 0) {
            $insert->execute([$name, 'System default anonymous collection person', $createdBy ?: null, $createdBy ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
        $result[$type] = $id;
    }

    return $result;
}

$defaultPeople = ensureAnonymousDefaultPeople($pdo, $uid);
$paymentMethods = ['cash', 'bank', 'pos', 'stripe', 'online'];
$selectedType = 'anonymous';
$selectedMethod = 'cash';
$selectedBoxId = 0;
$amountValue = '';
$notesValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_collection') {
        $collectionType = (string)($_POST['collection_type'] ?? 'anonymous');
        $boxId = (int)($_POST['box_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $paymentMethod = (string)($_POST['payment_method'] ?? 'cash');

        if (!in_array($collectionType, ['anonymous', 'donation_box', 'jumma', 'misc'], true)) {
            $collectionType = 'anonymous';
        }
        if (!in_array($paymentMethod, $paymentMethods, true)) {
            $paymentMethod = 'cash';
        }
        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }
        if ($collectionType === 'donation_box') {
            if ($boxId <= 0) {
                $errors[] = 'Please select a donation box.';
            } else {
                $stmt = $pdo->prepare('SELECT ID FROM donation_boxes WHERE ID = ? AND active = 1 LIMIT 1');
                $stmt->execute([$boxId]);
                if (!$stmt->fetch()) {
                    $errors[] = 'Selected donation box is not active.';
                }
            }
        } else {
            $boxId = 0;
        }

        $selectedType = $collectionType;
        $selectedMethod = $paymentMethod;
        $selectedBoxId = $boxId;
        $amountValue = $amount > 0 ? rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') : '';
        $notesValue = $notes;

        if (!$errors) {
            $personId = (int)($defaultPeople[$collectionType] ?? $defaultPeople['anonymous']);
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO anonymous_collections (person_id, box_id, collection_type, amount, payment_method, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([
                    $personId,
                    $boxId > 0 ? $boxId : null,
                    $collectionType,
                    $amount,
                    $paymentMethod,
                    $notes !== '' ? $notes : null,
                    $uid ?: null,
                ]);

                $referenceId = $boxId > 0 ? $boxId : null;
                $invoiceNo = generateInvoiceNumber($pdo);
                $receiptToken = randomToken(12);
                $ledgerNotes = anonymousCollectionTypeLabel($collectionType);
                if ($boxId > 0) {
                    $boxStmt = $pdo->prepare('SELECT box_number, title FROM donation_boxes WHERE ID = ? LIMIT 1');
                    $boxStmt->execute([$boxId]);
                    $box = $boxStmt->fetch();
                    if ($box) {
                        $ledgerNotes .= ' | Box ' . (string)$box['box_number'];
                        if (!empty($box['title'])) {
                            $ledgerNotes .= ' - ' . trim((string)$box['title']);
                        }
                    }
                }
                $ledgerNotes .= ' | Method: ' . anonymousCollectionMethodLabel($paymentMethod);
                if ($notes !== '') {
                    $ledgerNotes .= ' | ' . $notes;
                }

                $ledger = $pdo->prepare('INSERT INTO operator_ledger (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                $ledger->execute([
                    $uid,
                    $personId,
                    'collection',
                    $collectionType,
                    $amount,
                    $paymentMethod,
                    'confirmed',
                    $referenceId,
                    $invoiceNo,
                    $receiptToken,
                    $ledgerNotes,
                    $uid,
                ]);

                $pdo->commit();
                setFlash('success', 'Anonymous collection saved successfully.');
                header('Location: anonymous_collections.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to save the collection right now.';
            }
        }
    }
}

$boxRows = [];
if (tableExists($pdo, 'donation_boxes')) {
    $boxRows = $pdo->query(
        'SELECT b.*, COALESCE((SELECT SUM(ac.amount) FROM anonymous_collections ac WHERE ac.box_id = b.ID), 0) AS total_collected
         FROM donation_boxes b
         ORDER BY b.active DESC, b.box_number ASC, b.ID ASC'
    )->fetchAll();
}

$activeBoxes = array_values(array_filter($boxRows, static fn($row) => (int)($row['active'] ?? 0) === 1));

$recentRows = [];
if (tableExists($pdo, 'anonymous_collections')) {
    $recentStmt = $pdo->query(
        'SELECT ac.*, p.name AS person_name, b.box_number, b.title AS box_title
         FROM anonymous_collections ac
         LEFT JOIN people p ON p.ID = ac.person_id
         LEFT JOIN donation_boxes b ON b.ID = ac.box_id
         ORDER BY ac.ID DESC
         LIMIT 50'
    );
    $recentRows = $recentStmt->fetchAll();
}

$summaryStmt = $pdo->query(
    "SELECT collection_type, COALESCE(SUM(amount),0) AS total
     FROM anonymous_collections
     GROUP BY collection_type"
);
$summary = [
    'anonymous' => 0.0,
    'donation_box' => 0.0,
    'jumma' => 0.0,
    'misc' => 0.0,
];
foreach ($summaryStmt->fetchAll() as $row) {
    $summary[(string)$row['collection_type']] = (float)$row['total'];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="title">Anonymous Collections</h1>
        <p class="muted anon-subtitle">Collections for anonymous, donation box, Jumma, and misc entries.</p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert error"><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($role === 'accountant'): ?>
<div class="anon-summary-grid">
    <div class="card summary-card"><div class="summary-label">Anonymous</div><div class="summary-value"><?= money($summary['anonymous']) ?></div></div>
    <div class="card summary-card"><div class="summary-label">Donation Box</div><div class="summary-value"><?= money($summary['donation_box']) ?></div></div>
    <div class="card summary-card"><div class="summary-label">Jumma</div><div class="summary-value"><?= money($summary['jumma']) ?></div></div>
    <div class="card summary-card"><div class="summary-label">Misc</div><div class="summary-value"><?= money($summary['misc']) ?></div></div>
</div>
<?php endif; ?>

<div class="anon-layout-grid<?= $role !== 'accountant' ? ' anon-layout-grid-operator' : '' ?>">
    <section class="card stack">
        <div class="section-head">
            <h2>New Collection</h2>
        </div>

        <form method="post" class="stack" id="anonymousCollectionForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_collection">

            <div>
                <label class="field-label">Collection Type</label>
                <div class="type-grid">
                    <?php foreach ([
                        ['anonymous', '🙈', 'Anonymous'],
                        ['donation_box', '📦', 'Donation Box'],
                        ['jumma', '🕌', 'Jumma'],
                        ['misc', '🧺', 'Misc'],
                    ] as $option): ?>
                        <label class="type-option">
                            <input type="radio" name="collection_type" value="<?= e($option[0]) ?>" <?= $option[0] === $selectedType ? 'checked' : '' ?>>
                            <span class="type-chip">
                                <strong><?= e($option[1]) ?></strong>
                                <span><?= e($option[2]) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="donationBoxField" class="box-field" hidden>
                <label class="field-label" for="box_id">Donation Box</label>
                <select name="box_id" id="box_id">
                    <option value="0">Select donation box</option>
                    <?php foreach ($activeBoxes as $box): ?>
                        <option value="<?= (int)$box['ID'] ?>" <?= (int)$box['ID'] === $selectedBoxId ? 'selected' : '' ?>>
                            Box <?= e((string)$box['box_number']) ?><?= trim((string)($box['title'] ?? '')) !== '' ? ' - ' . e(trim((string)$box['title'])) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$activeBoxes): ?>
                    <div class="inline-note">No active donation boxes found. Create one first on the donation boxes page.</div>
                <?php endif; ?>
            </div>

            <div>
                <label class="field-label" for="payment_method">Payment Type</label>
                <div class="payment-grid" role="radiogroup" aria-label="Payment Type">
                    <?php foreach ($paymentMethods as $method): ?>
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="<?= e($method) ?>" <?= $method === $selectedMethod ? 'checked' : '' ?>>
                            <span class="payment-chip">
                                <span class="payment-chip-icon"><?= e(anonymousCollectionMethodIcon($method)) ?></span>
                                <span class="payment-chip-text"><?= e(anonymousCollectionMethodLabel($method)) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label class="field-label" for="amount">Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" id="amount" required autocomplete="off" value="<?= e($amountValue) ?>">
                <div class="quick-amounts">
                    <?php foreach ([10, 20, 50, 100, 200] as $preset): ?>
                        <button class="quick-amount-btn" type="button" data-amount="<?= $preset ?>">+<?= $preset ?></button>
                    <?php endforeach; ?>
                    <button class="quick-amount-btn clear-btn" type="button" data-clear="1">Clear</button>
                </div>
            </div>

            <div>
                <label class="field-label" for="notes">Notes</label>
                <textarea name="notes" id="notes" rows="4" placeholder="Optional notes"><?= e($notesValue) ?></textarea>
            </div>

            <button class="btn btn-primary" type="submit">Save Collection</button>
        </form>
    </section>

    <section class="stack">
        <?php if ($role === 'accountant'): ?>
        <div class="card">
            <div class="section-head">
                <h2>Donation Boxes</h2>
                <a class="text-link" href="donation_boxes.php">Open page</a>
            </div>
            <div class="table-wrap recent-wrap">
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Box</th>
                            <th>Status</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($boxRows, 0, 8) as $box): ?>
                            <tr>
                                <td data-label="Box">
                                    <strong><?= e((string)$box['box_number']) ?></strong>
                                    <?php if (trim((string)($box['title'] ?? '')) !== ''): ?>
                                        <div class="muted"><?= e((string)$box['title']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status"><?= (int)$box['active'] === 1 ? 'Active' : 'Inactive' ?></td>
                                <td data-label="Total"><?= money((float)$box['total_collected']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$boxRows): ?>
                            <tr><td colspan="3" class="muted">No donation boxes available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="section-head">
                <h2>Recent Entries</h2>
                <span class="muted">Latest 50</span>
            </div>

            <?php if (!$recentRows): ?>
                <div class="muted">No anonymous collections yet.</div>
            <?php else: ?>

                <div class="recent-entries-desktop table-wrap recent-wrap">
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Box</th>
                                <th>Amount</th>
                                <th>Method</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRows as $row): ?>
                                <tr>
                                    <td>
                                        <?= e(function_exists('formatDateTimeDisplay') ? formatDateTimeDisplay((string)$row['created_at']) : date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?>
                                    </td>
                                    <td>
                                        <?= e(anonymousCollectionTypeLabel((string)$row['collection_type'])) ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['box_number'])): ?>
                                            <?= e((string)$row['box_number']) ?>
                                            <?php if (trim((string)($row['box_title'] ?? '')) !== ''): ?>
                                                <div class="muted"><?= e((string)$row['box_title']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= money((float)$row['amount']) ?></td>
                                    <td><?= e(anonymousCollectionMethodLabel((string)($row['payment_method'] ?? 'cash'))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="recent-entries-mobile">
                    <?php foreach ($recentRows as $row): ?>
                        <div class="recent-entry-card">
                            <div class="recent-entry-type">
                                <div class="recent-entry-label">Type</div>
                                <div class="recent-entry-value">
                                    <?= e(anonymousCollectionTypeLabel((string)$row['collection_type'])) ?>
                                </div>
                            </div>

                            <div class="recent-entry-amount">
                                <div class="recent-entry-label">Amount</div>
                                <div class="recent-entry-value">
                                    <?= money((float)$row['amount']) ?>
                                </div>
                            </div>

                            <div class="recent-entry-method">
                                <div class="recent-entry-label">Method</div>
                                <div class="recent-entry-value">
                                    <?= e(anonymousCollectionMethodLabel((string)($row['payment_method'] ?? 'cash'))) ?>
                                </div>
                            </div>

                            <div class="recent-entry-date">
                                <?= e(function_exists('formatDateTimeDisplay') ? formatDateTimeDisplay((string)$row['created_at']) : date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(function () {
    const typeInputs = Array.from(document.querySelectorAll('input[name="collection_type"]'));
    const boxField = document.getElementById('donationBoxField');
    const boxSelect = document.getElementById('box_id');
    const amountInput = document.getElementById('amount');

    function syncBoxField() {
        const checked = document.querySelector('input[name="collection_type"]:checked');
        const isBox = checked && checked.value === 'donation_box';
        boxField.hidden = !isBox;
        if (!isBox && boxSelect) boxSelect.value = '0';
    }

    typeInputs.forEach(input => input.addEventListener('change', syncBoxField));
    syncBoxField();

    document.querySelectorAll('.quick-amount-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            if (this.dataset.clear === '1') {
                amountInput.value = '';
                amountInput.focus();
                return;
            }
            const value = parseFloat(this.dataset.amount || '0');
            const current = parseFloat(amountInput.value || '0');
            amountInput.value = (current + value).toFixed(2).replace(/\.00$/, '');
            amountInput.focus();
        });
    });

    if (amountInput) amountInput.focus();
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
