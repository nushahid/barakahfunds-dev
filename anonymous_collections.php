<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, ['operator','accountant','admin']);
if (function_exists('migrateV42')) { migrateV42($pdo); }

$uid = getLoggedInUserId();
$defaults = function_exists('ensureDefaultAnonymousDonors') ? ensureDefaultAnonymousDonors($pdo) : [];
if (!$defaults) {
    $defaults = [
        'Anonymous Donation' => 0,
        'Mosque Donation Box' => 0,
        'Jumma Prayer Collection' => 0,
        'Miscellaneous Collection' => 0,
    ];
}

$errors = [];
$boxEditId = isset($_GET['edit_box']) ? (int)$_GET['edit_box'] : 0;
$boxToEdit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_box' || $action === 'update_box') {
        $editId = (int)($_POST['box_id'] ?? 0);
        $boxNumber = trim((string)($_POST['box_number'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($boxNumber === '') {
            $errors[] = 'Box number is required.';
        }

        if (!$errors) {
            if ($action === 'update_box' && $editId > 0) {
                $stmt = $pdo->prepare('UPDATE donation_boxes SET box_number = ?, title = ?, notes = ?, active = ? WHERE ID = ?');
                $stmt->execute([$boxNumber, $title, $notes, $active, $editId]);
                setFlash('success', 'Donation box updated.');
            } else {
                $qr = randomToken(6);
                $stmt = $pdo->prepare('INSERT INTO donation_boxes (box_number, title, qr_token, active, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$boxNumber, $title, $qr, 1, $notes, $uid]);
                setFlash('success', 'Donation box created.');
            }
            header('Location: anonymous_collections.php');
            exit;
        }
        $boxEditId = $editId;
    }

    if ($action === 'add_collection') {
        $collectionType = (string)($_POST['collection_type'] ?? 'anonymous');
        $amount = (float)($_POST['amount'] ?? 0);
        $method = normalizePaymentMethod((string)($_POST['payment_method'] ?? 'cash'));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $boxId = (int)($_POST['box_id'] ?? 0);

        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }

        $personId = match ($collectionType) {
            'donation_box' => (int)($defaults['Mosque Donation Box'] ?? 0),
            'jumma' => (int)($defaults['Jumma Prayer Collection'] ?? 0),
            'misc' => (int)($defaults['Miscellaneous Collection'] ?? 0),
            default => (int)($defaults['Anonymous Donation'] ?? 0),
        };

        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO anonymous_collections (person_id, box_id, collection_type, amount, payment_method, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$personId, $boxId ?: null, $collectionType, $amount, $method, $notes, $uid]);

            $invoiceNo = generateInvoiceNumber($pdo);
            $token = randomToken(8);
            $category = 'one_time';
            $settlementStatus = in_array($method, ['bank_transfer', 'pos', 'online'], true) ? 'pending_confirmation' : 'confirmed';

            $pdo->prepare('INSERT INTO operator_ledger (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, notes, created_by, created_at) VALUES (?, ?, "collection", ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$uid, $personId, $category, $amount, $method, $settlementStatus, $boxId ?: null, $invoiceNo, $token, strtoupper($collectionType) . ' | ' . $notes, $uid]);

            setFlash('success', 'Anonymous collection saved.');
            header('Location: anonymous_collections.php');
            exit;
        }
    }
}

$boxes = tableExists($pdo, 'donation_boxes')
    ? $pdo->query('SELECT b.*, COALESCE((SELECT SUM(amount) FROM anonymous_collections ac WHERE ac.box_id = b.ID), 0) AS total_collected FROM donation_boxes b ORDER BY b.box_number ASC')->fetchAll()
    : [];
$rows = tableExists($pdo, 'anonymous_collections')
    ? $pdo->query('SELECT ac.*, b.box_number FROM anonymous_collections ac LEFT JOIN donation_boxes b ON b.ID = ac.box_id ORDER BY ac.ID DESC LIMIT 50')->fetchAll()
    : [];

foreach ($boxes as $box) {
    if ((int)$box['ID'] === $boxEditId) {
        $boxToEdit = $box;
        break;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Anonymous Collections</h1>

<div class="page-intro muted">Fast collection entry with mobile-friendly cards. Donation box management stays on this page, and the quick bottom bar link has been removed.</div>

<div class="ac-layout">
    <div class="stack">
        <div class="card ac-form-card">
            <?php if ($errors): ?>
                <div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
            <?php endif; ?>

            <div class="toolbar ac-heading-row">
                <h2 class="ac-title">New Collection</h2>
                <span class="tag blue">Fast Entry</span>
            </div>

            <form method="post" class="stack ac-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_collection">

                <div>
                    <label class="ac-label">Collection Type</label>
                    <div class="ac-type-grid">
                        <?php foreach ([
                            ['anonymous', '🙈', 'Anonymous'],
                            ['donation_box', '📦', 'Donation Box'],
                            ['jumma', '🕌', 'Jumma'],
                            ['misc', '🧺', 'Misc'],
                        ] as $c): ?>
                            <label class="ac-choice-card ac-choice-card-large">
                                <input type="radio" name="collection_type" value="<?= e($c[0]) ?>" <?= $c[0] === 'anonymous' ? 'checked' : '' ?>>
                                <span class="ac-choice-card-inner">
                                    <span class="ac-choice-icon"><?= e($c[1]) ?></span>
                                    <span class="ac-choice-text"><?= e($c[2]) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="inline-grid-2 ac-top-fields">
                    <div>
                        <label class="ac-label">Donation Box</label>
                        <select name="box_id" class="ac-input">
                            <option value="0">No specific box</option>
                            <?php foreach ($boxes as $box): ?>
                                <option value="<?= (int)$box['ID'] ?>">Box <?= e((string)$box['box_number']) ?><?= !empty($box['title']) ? ' · ' . e((string)$box['title']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="ac-label">Amount</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="anon_amount" class="ac-input" required>
                    </div>
                </div>

                <div>
                    <label class="ac-label">Suggested Amount</label>
                    <div class="ac-suggested-grid">
                        <?php foreach ([5, 10, 20, 50, 100] as $amount): ?>
                            <button class="ac-suggested-card" type="button" onclick="setExactAmount('anon_amount', <?= (int)$amount ?>)"><?= money((float)$amount) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="ac-label">Payment Method</label>
                    <div class="ac-method-grid">
                        <?php foreach ([
                            ['cash', '💵', 'Cash'],
                            ['bank_transfer', '🏦', 'Bank'],
                            ['pos', '💳', 'POS'],
                            ['stripe', '🟦', 'Stripe'],
                        ] as $m): ?>
                            <label class="ac-choice-card ac-choice-card-method">
                                <input type="radio" name="payment_method" value="<?= e($m[0]) ?>" <?= $m[0] === 'cash' ? 'checked' : '' ?>>
                                <span class="ac-choice-card-inner">
                                    <span class="ac-choice-icon"><?= e($m[1]) ?></span>
                                    <span class="ac-choice-text"><?= e($m[2]) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="ac-label">Notes</label>
                    <textarea name="notes" class="ac-input ac-textarea" rows="3"></textarea>
                </div>

                <button class="btn btn-primary ac-save-btn" type="submit">Save Anonymous Collection</button>
            </form>
        </div>

        <div class="card ac-form-card">
            <div class="toolbar ac-heading-row">
                <h2 class="ac-title"><?= $boxToEdit ? 'Edit Donation Box' : 'New Donation Box' ?></h2>
                <?php if ($boxToEdit): ?>
                    <a class="btn" href="anonymous_collections.php">Cancel Edit</a>
                <?php else: ?>
                    <span class="tag orange">Boxes</span>
                <?php endif; ?>
            </div>

            <form method="post" class="stack ac-box-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?= $boxToEdit ? 'update_box' : 'add_box' ?>">
                <input type="hidden" name="box_id" value="<?= (int)($boxToEdit['ID'] ?? 0) ?>">

                <div class="inline-grid-2 ac-top-fields">
                    <div>
                        <label class="ac-label">Box Number</label>
                        <input type="text" name="box_number" class="ac-input" value="<?= e((string)($boxToEdit['box_number'] ?? '')) ?>" required>
                    </div>
                    <div>
                        <label class="ac-label">Title</label>
                        <input type="text" name="title" class="ac-input" value="<?= e((string)($boxToEdit['title'] ?? '')) ?>">
                    </div>
                </div>

                <div>
                    <label class="ac-label">Notes</label>
                    <textarea name="notes" class="ac-input ac-textarea" rows="3"><?= e((string)($boxToEdit['notes'] ?? '')) ?></textarea>
                </div>

                <?php if ($boxToEdit): ?>
                    <label class="ac-status-toggle">
                        <input type="checkbox" name="active" value="1" <?= !empty($boxToEdit['active']) ? 'checked' : '' ?>>
                        <span>Box Active</span>
                    </label>
                <?php endif; ?>

                <button class="btn btn-primary ac-save-btn" type="submit"><?= $boxToEdit ? 'Update Donation Box' : 'Create Donation Box' ?></button>
            </form>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="toolbar ac-heading-row">
                <h2 class="ac-title">Donation Boxes</h2>
                <span class="tag blue"><?= count($boxes) ?> Total</span>
            </div>

            <div class="ac-boxes-list">
                <?php foreach ($boxes as $box):
                    $boxUrl = publicBaseUrl() . '/anonymous_collections.php?box=' . (int)$box['ID'] . '&token=' . urlencode((string)$box['qr_token']);
                    $qrImg = 'https://chart.googleapis.com/chart?chs=110x110&cht=qr&chl=' . urlencode($boxUrl);
                ?>
                    <div class="ac-box-card">
                        <div class="ac-box-card-main">
                            <div>
                                <div class="ac-box-number">Box <?= e((string)$box['box_number']) ?></div>
                                <?php if (!empty($box['title'])): ?><div class="muted"><?= e((string)$box['title']) ?></div><?php endif; ?>
                                <div class="ac-box-total">Collected: <?= money((float)$box['total_collected']) ?></div>
                                <div class="ac-box-status-row">
                                    <span class="tag <?= !empty($box['active']) ? 'green' : 'orange' ?>"><?= !empty($box['active']) ? 'Active' : 'Inactive' ?></span>
                                    <a class="btn btn-small" href="anonymous_collections.php?edit_box=<?= (int)$box['ID'] ?>">Edit</a>
                                </div>
                            </div>
                            <img class="ac-box-qr" src="<?= e($qrImg) ?>" alt="Box QR" width="88" height="88">
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!$boxes): ?>
                    <div class="muted">No donation boxes yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="toolbar ac-heading-row">
                <h2 class="ac-title">Recent Anonymous Collections</h2>
                <span class="tag orange">Latest 50</span>
            </div>
            <div class="table-wrap">
                <table>
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
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e(function_exists('formatDateTimeDisplay') ? formatDateTimeDisplay((string)$row['created_at']) : date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?></td>
                                <td><?= e(ucwords(str_replace('_', ' ', (string)$row['collection_type']))) ?></td>
                                <td><?= e((string)($row['box_number'] ?? '—')) ?></td>
                                <td><?= money((float)$row['amount']) ?></td>
                                <td><?= e((string)$row['payment_method'] === 'online' ? 'Stripe' : ucwords(str_replace('_', ' ', (string)$row['payment_method']))) ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$rows): ?>
                            <tr><td colspan="5" class="muted">No anonymous collections yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function setExactAmount(targetId, value) {
    const input = document.getElementById(targetId);
    if (!input) return;
    input.value = Number(value).toFixed(2);
    input.focus();
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
