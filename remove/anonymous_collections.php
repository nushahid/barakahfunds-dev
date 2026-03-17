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
$editBoxId = isset($_GET['edit_box']) ? (int)$_GET['edit_box'] : 0;
$selectedType = (string)($_POST['collection_type'] ?? 'anonymous');
$boxForm = [
    'ID' => 0,
    'box_number' => '',
    'title' => '',
    'notes' => '',
    'active' => 1,
    'qr_token' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_box') {
        $boxId = (int)($_POST['box_id'] ?? 0);
        $boxNumber = trim((string)($_POST['box_number'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($boxNumber === '') {
            $errors[] = 'Box number is required.';
        }

        if (!$errors) {
            try {
                if ($boxId > 0) {
                    $stmt = $pdo->prepare('UPDATE donation_boxes SET box_number = ?, title = ?, notes = ?, active = ? WHERE ID = ?');
                    $stmt->execute([$boxNumber, $title, $notes, $active, $boxId]);
                    setFlash('success', 'Donation box updated.');
                } else {
                    $qr = randomToken(6);
                    $stmt = $pdo->prepare('INSERT INTO donation_boxes (box_number, title, qr_token, notes, active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
                    $stmt->execute([$boxNumber, $title, $qr, $notes, $active, $uid]);
                    setFlash('success', 'Donation box created.');
                }
                header('Location: anonymous_collections.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Donation box could not be saved.';
            }
        }

        $editBoxId = $boxId;
        $boxForm = [
            'ID' => $boxId,
            'box_number' => $boxNumber,
            'title' => $title,
            'notes' => $notes,
            'active' => $active,
            'qr_token' => '',
        ];
    }

    if ($action === 'add_collection') {
        $selectedType = (string)($_POST['collection_type'] ?? 'anonymous');
        $amount = (float)($_POST['amount'] ?? 0);
        $method = normalizePaymentMethod((string)($_POST['payment_method'] ?? 'cash'));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $boxId = (int)($_POST['box_id'] ?? 0);
        if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
        if ($selectedType === 'donation_box' && $boxId <= 0) $errors[] = 'Please select a donation box.';
        $personId = match($selectedType){
            'donation_box' => (int)($defaults['Mosque Donation Box'] ?? 0),
            'jumma' => (int)($defaults['Jumma Prayer Collection'] ?? 0),
            'misc' => (int)($defaults['Miscellaneous Collection'] ?? 0),
            default => (int)($defaults['Anonymous Donation'] ?? 0),
        };
        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO anonymous_collections (person_id, box_id, collection_type, amount, payment_method, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$personId, $boxId ?: null, $selectedType, $amount, $method, $notes, $uid]);
            $invoiceNo = generateInvoiceNumber($pdo);
            $token = randomToken(8);
            $category = 'one_time';
            $pdo->prepare('INSERT INTO operator_ledger (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, notes, created_by, created_at) VALUES (?, ?, "collection", ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$uid, $personId, $category, $amount, $method, in_array($method,['bank_transfer','pos','online'],true)?'pending_confirmation':'confirmed', $boxId ?: null, $invoiceNo, $token, strtoupper($selectedType) . ' | ' . $notes, $uid]);
            setFlash('success', 'Anonymous collection saved.');
            header('Location: anonymous_collections.php');
            exit;
        }
    }
}

$boxes = tableExists($pdo,'donation_boxes')
    ? $pdo->query('SELECT b.*, COALESCE((SELECT SUM(amount) FROM anonymous_collections ac WHERE ac.box_id = b.ID),0) AS total_collected FROM donation_boxes b ORDER BY b.box_number ASC')->fetchAll()
    : [];
$rows = tableExists($pdo,'anonymous_collections')
    ? $pdo->query('SELECT ac.*, b.box_number FROM anonymous_collections ac LEFT JOIN donation_boxes b ON b.ID = ac.box_id ORDER BY ac.ID DESC LIMIT 50')->fetchAll()
    : [];

if ($editBoxId > 0) {
    foreach ($boxes as $box) {
        if ((int)$box['ID'] === $editBoxId) {
            $boxForm = $box;
            break;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Anonymous Collections</h1>

<div class="grid-2 ac-layout-grid">
  <div class="stack">
    <div class="card ac-form-card">
      <?php if ($errors): ?>
        <div class="alert error"><?php foreach($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
      <?php endif; ?>

      <div class="toolbar ac-toolbar">
        <h2 class="ac-heading">New Collection</h2>
      </div>

      <form method="post" class="stack ac-main-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_collection">

        <div>
          <label class="ac-label">Collection Type</label>
          <div class="ac-big-grid">
            <?php foreach ([
              ['anonymous','🙈','Anonymous'],
              ['donation_box','📦','Donation Box'],
              ['jumma','🕌','Jumma'],
              ['misc','🧺','Miscellaneous'],
            ] as $c): ?>
              <label class="ac-option-card">
                <input type="radio" name="collection_type" value="<?= e($c[0]) ?>" <?= $selectedType === $c[0] ? 'checked' : '' ?> data-collection-type>
                <span class="ac-card-face">
                  <span class="ac-card-icon"><?= e($c[1]) ?></span>
                  <span class="ac-card-title"><?= e($c[2]) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div id="donation_box_select_wrap" class="<?= $selectedType === 'donation_box' ? '' : 'ac-hidden' ?>">
          <label class="ac-label" for="box_id">Donation Box</label>
          <select class="ac-select" name="box_id" id="box_id">
            <option value="0">Select donation box</option>
            <?php foreach($boxes as $box): ?>
              <option value="<?= (int)$box['ID'] ?>" <?= (int)($_POST['box_id'] ?? 0) === (int)$box['ID'] ? 'selected' : '' ?>>
                Box <?= e((string)$box['box_number']) ?><?= !empty($box['title']) ? ' · ' . e((string)$box['title']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="ac-label" for="anon_amount">Amount</label>
          <input class="ac-amount-input" type="number" step="0.01" min="0.01" name="amount" id="anon_amount" value="<?= e((string)($_POST['amount'] ?? '')) ?>" required>
          <div class="ac-quick-grid">
            <?php foreach([10,20,50,100] as $inc): ?>
              <button class="ac-quick-card" type="button" onclick="setAmount('anon_amount',<?= $inc ?>)">+<?= $inc ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <label class="ac-label">Payment Method</label>
          <div class="ac-pay-grid">
            <?php $postedMethod = (string)($_POST['payment_method'] ?? 'cash'); ?>
            <?php foreach ([['cash','💵','Cash'],['bank_transfer','🏦','Bank'],['pos','💳','POS'],['stripe','🌐','Stripe']] as $m): ?>
              <label class="ac-pay-card-wrap">
                <input class="ac-pay-input" type="radio" name="payment_method" value="<?= e($m[0]) ?>" <?= $postedMethod === $m[0] ? 'checked' : '' ?>>
                <span class="ac-pay-card">
                  <span class="ac-card-icon"><?= e($m[1]) ?></span>
                  <span class="ac-card-title"><?= e($m[2]) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <label class="ac-label" for="ac_notes">Notes</label>
          <textarea class="ac-textarea" name="notes" id="ac_notes"><?= e((string)($_POST['notes'] ?? '')) ?></textarea>
        </div>

        <button class="btn btn-primary ac-save-btn" type="submit">Save Anonymous Collection</button>
      </form>
    </div>

    <div class="card ac-box-form-card">
      <div class="toolbar ac-toolbar">
        <h2 class="ac-heading"><?= !empty($boxForm['ID']) ? 'Edit Donation Box' : 'New Donation Box' ?></h2>
        <?php if (!empty($boxForm['ID'])): ?>
          <a class="btn" href="anonymous_collections.php">Cancel</a>
        <?php endif; ?>
      </div>

      <form method="post" class="stack ac-box-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_box">
        <input type="hidden" name="box_id" value="<?= (int)$boxForm['ID'] ?>">

        <div class="inline-grid-2 ac-box-grid">
          <div>
            <label class="ac-label" for="box_number">Box Number</label>
            <input type="text" name="box_number" id="box_number" value="<?= e((string)$boxForm['box_number']) ?>" required>
          </div>
          <div>
            <label class="ac-label" for="box_title">Title</label>
            <input type="text" name="title" id="box_title" value="<?= e((string)$boxForm['title']) ?>">
          </div>
        </div>

        <div>
          <label class="ac-label" for="box_notes">Notes</label>
          <textarea class="ac-textarea" name="notes" id="box_notes"><?= e((string)$boxForm['notes']) ?></textarea>
        </div>

        <label class="switch-row ac-switch-row">
          <span>Active box</span>
          <input type="checkbox" name="active" value="1" <?= !empty($boxForm['active']) ? 'checked' : '' ?>>
        </label>

        <button class="btn btn-primary ac-save-btn" type="submit"><?= !empty($boxForm['ID']) ? 'Update Donation Box' : 'Create Donation Box' ?></button>
      </form>
    </div>
  </div>

  <div class="stack ac-right-stack">
    <div class="card ac-box-list-card">
      <div class="toolbar ac-toolbar">
        <h2 class="ac-heading">Donation Boxes</h2>
      </div>

      <div class="ac-box-list">
        <?php foreach($boxes as $box): ?>
          <?php $boxUrl = publicBaseUrl() . '/anonymous_collections.php?box=' . (int)$box['ID'] . '&token=' . urlencode((string)$box['qr_token']); ?>
          <?php $qrImg = 'https://chart.googleapis.com/chart?chs=140x140&cht=qr&chl=' . urlencode($boxUrl); ?>
          <div class="ac-box-item<?= empty($box['active']) ? ' is-inactive' : '' ?>">
            <div class="ac-box-meta">
              <div>
                <strong>Box <?= e((string)$box['box_number']) ?></strong>
                <?php if (!empty($box['title'])): ?><div class="muted"><?= e((string)$box['title']) ?></div><?php endif; ?>
              </div>
              <span class="tag <?= !empty($box['active']) ? 'green' : 'orange' ?>"><?= !empty($box['active']) ? 'Active' : 'Inactive' ?></span>
            </div>
            <div class="ac-box-qr-wrap">
              <img class="ac-box-qr" src="<?= e($qrImg) ?>" alt="Box QR">
            </div>
            <div class="ac-box-total">Total Collected: <strong><?= money((float)$box['total_collected']) ?></strong></div>
            <div class="ac-box-actions">
              <a class="btn" href="anonymous_collections.php?edit_box=<?= (int)$box['ID'] ?>">Edit</a>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if(!$boxes): ?>
          <div class="muted">No donation boxes yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="toolbar ac-toolbar">
        <h2 class="ac-heading">Recent Anonymous Collections</h2>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Date</th><th>Type</th><th>Box</th><th>Amount</th><th>Method</th></tr>
          </thead>
          <tbody>
            <?php foreach($rows as $row): ?>
              <tr>
                <td><?= e(function_exists('formatDateTimeDisplay') ? formatDateTimeDisplay((string)$row['created_at']) : date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?></td>
                <td><?= e((string)$row['collection_type']) ?></td>
                <td><?= e((string)($row['box_number'] ?? '—')) ?></td>
                <td><?= money((float)$row['amount']) ?></td>
                <td><?= e((string)$row['payment_method']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$rows): ?>
              <tr><td colspan="5" class="muted">No anonymous collections yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const typeInputs = document.querySelectorAll('[data-collection-type]');
  const boxWrap = document.getElementById('donation_box_select_wrap');
  const boxSelect = document.getElementById('box_id');
  function syncBoxVisibility(){
    const selected = document.querySelector('[data-collection-type]:checked');
    const show = selected && selected.value === 'donation_box';
    if (boxWrap) boxWrap.classList.toggle('ac-hidden', !show);
    if (!show && boxSelect) boxSelect.value = '0';
  }
  typeInputs.forEach(function(input){ input.addEventListener('change', syncBoxVisibility); });
  syncBoxVisibility();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
