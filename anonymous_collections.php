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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add_box') {
        $boxNumber = trim((string)($_POST['box_number'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($boxNumber === '') $errors[] = 'Box number is required.';
        if (!$errors) {
            $qr = randomToken(6);
            $stmt = $pdo->prepare('INSERT INTO donation_boxes (box_number, title, qr_token, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$boxNumber, $title, $qr, $notes, $uid]);
            setFlash('success', 'Donation box created.'); header('Location: anonymous_collections.php'); exit;
        }
    }
    if ($action === 'add_collection') {
        $collectionType = (string)($_POST['collection_type'] ?? 'anonymous');
        $amount = (float)($_POST['amount'] ?? 0);
        $method = normalizePaymentMethod((string)($_POST['payment_method'] ?? 'cash'));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $boxId = (int)($_POST['box_id'] ?? 0);
        if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
        $personId = match($collectionType){
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
            $category = $collectionType === 'donation_box' ? 'one_time' : 'one_time';
            $pdo->prepare('INSERT INTO operator_ledger (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, notes, created_by, created_at) VALUES (?, ?, "collection", ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$uid, $personId, $category, $amount, $method, in_array($method,['bank_transfer','pos'],true)?'pending_confirmation':'confirmed', $boxId ?: null, $invoiceNo, $token, strtoupper($collectionType) . ' | ' . $notes, $uid]);
            setFlash('success', 'Anonymous collection saved.'); header('Location: anonymous_collections.php'); exit;
        }
    }
}
$boxes = tableExists($pdo,'donation_boxes') ? $pdo->query('SELECT b.*, COALESCE((SELECT SUM(amount) FROM anonymous_collections ac WHERE ac.box_id = b.ID),0) AS total_collected FROM donation_boxes b ORDER BY b.box_number ASC')->fetchAll() : [];
$rows = tableExists($pdo,'anonymous_collections') ? $pdo->query('SELECT ac.*, b.box_number FROM anonymous_collections ac LEFT JOIN donation_boxes b ON b.ID = ac.box_id ORDER BY ac.ID DESC LIMIT 50')->fetchAll() : [];
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Anonymous Collections</h1>
<div class="grid-2">
  <div class="stack">
    <div class="card stack">
      <?php if ($errors): ?><div class="alert error"><?php foreach($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
      <h2 style="margin:0">New Collection</h2>
      <form method="post" class="stack">
        <?= csrfField() ?><input type="hidden" name="action" value="add_collection">
        <div><label>Collection Type</label><div class="payment-chip-group"><?php foreach([['anonymous','🙈','Anonymous'],['donation_box','📦','Donation Box'],['jumma','🕌','Jumma'],['misc','🧺','Misc']] as $c): ?><label class="selector-label"><input class="selector-input" type="radio" name="collection_type" value="<?= e($c[0]) ?>" <?= $c[0]==='anonymous'?'checked':'' ?>><span class="payment-chip"><span><?= e($c[1]) ?></span><span><?= e($c[2]) ?></span></span></label><?php endforeach; ?></div></div>
        <div><label>Donation Box</label><select name="box_id"><option value="0">No specific box</option><?php foreach($boxes as $box): ?><option value="<?= (int)$box['ID'] ?>">Box <?= e((string)$box['box_number']) ?><?= !empty($box['title']) ? ' · ' . e((string)$box['title']) : '' ?></option><?php endforeach; ?></select></div>
        <div><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="anon_amount" required><div class="quick-amount-group" style="margin-top:10px;"><?php foreach([10,20,50,100] as $inc): ?><button class="quick-amount-btn" type="button" onclick="setAmount('anon_amount',<?= $inc ?>)">+<?= $inc ?></button><?php endforeach; ?></div></div>
        <div><label>Payment Method</label><div class="payment-chip-group"><?php foreach ([['cash','💵','Cash'],['bank_transfer','🏦','Bank'],['pos','💳','POS'],['online','🌐','Online']] as $m): ?><label class="selector-label"><input class="selector-input" type="radio" name="payment_method" value="<?= e($m[0]) ?>" <?= $m[0]==='cash'?'checked':'' ?>><span class="payment-chip"><span><?= e($m[1]) ?></span><span><?= e($m[2]) ?></span></span></label><?php endforeach; ?></div></div>
        <div><label>Notes</label><textarea name="notes"></textarea></div>
        <button class="btn btn-primary" type="submit">Save Anonymous Collection</button>
      </form>
    </div>
    <div class="card stack">
      <h2 style="margin:0">New Donation Box</h2>
      <form method="post" class="stack">
        <?= csrfField() ?><input type="hidden" name="action" value="add_box">
        <div class="inline-grid-2"><div><label>Box Number</label><input type="text" name="box_number" required></div><div><label>Title</label><input type="text" name="title"></div></div>
        <div><label>Notes</label><textarea name="notes"></textarea></div>
        <button class="btn btn-primary" type="submit">Create Donation Box</button>
      </form>
    </div>
  </div>
  <div class="stack">
    <div class="card"><h2 style="margin-top:0">Donation Boxes</h2><div class="table-wrap"><table><thead><tr><th>Box</th><th>QR</th><th>Total Collected</th></tr></thead><tbody><?php foreach($boxes as $box): $boxUrl = publicBaseUrl() . '/anonymous_collections.php?box=' . (int)$box['ID'] . '&token=' . urlencode((string)$box['qr_token']); $qrImg = 'https://chart.googleapis.com/chart?chs=110x110&cht=qr&chl=' . urlencode($boxUrl); ?><tr><td><strong><?= e((string)$box['box_number']) ?></strong><div class="muted"><?= e((string)($box['title'] ?? '')) ?></div></td><td><img src="<?= e($qrImg) ?>" alt="Box QR" width="72" height="72"></td><td><?= money((float)$box['total_collected']) ?></td></tr><?php endforeach; if(!$boxes): ?><tr><td colspan="3" class="muted">No donation boxes yet.</td></tr><?php endif; ?></tbody></table></div></div>
    <div class="card"><h2 style="margin-top:0">Recent Anonymous Collections</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Box</th><th>Amount</th><th>Method</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= e(function_exists('formatDateTimeDisplay') ? formatDateTimeDisplay((string)$row['created_at']) : date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?></td><td><?= e((string)$row['collection_type']) ?></td><td><?= e((string)($row['box_number'] ?? '—')) ?></td><td><?= money((float)$row['amount']) ?></td><td><?= e((string)$row['payment_method']) ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="5" class="muted">No anonymous collections yet.</td></tr><?php endif; ?></tbody></table></div></div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
