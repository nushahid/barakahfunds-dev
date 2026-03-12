<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAccountant($pdo);
$uid = getLoggedInUserId();
$errors = [];
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? 'create');
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $personId = (int)($_POST['person_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $received = (string)($_POST['received_date'] ?? date('Y-m-d'));
    $return = (string)($_POST['return_date'] ?? date('Y-m-d', strtotime('+30 days')));
    $type = (string)($_POST['type'] ?? 'Receive');
    $method = normalizePaymentMethod((string)($_POST['method'] ?? 'cash'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $returned = (int)($_POST['returned'] ?? 0) === 1 ? 1 : 0;

    if ($personId <= 0) $errors[] = 'Select person.';
    if ($name === '') $errors[] = 'Loan name required.';
    if ($amount <= 0) $errors[] = 'Amount required.';

    if (!$errors) {
        if ($action === 'update' && $loanId > 0) {
            $pdo->prepare('UPDATE loan SET pid = ?, name = ?, amount = ?, received_date = ?, return_date = ?, type = ?, method = ?, notes = ?, returned = ? WHERE ID = ?')->execute([$personId, $name, $amount, $received, $return, $type, $method, $notes, $returned, $loanId]);
            systemLog($pdo, $uid, 'loan', 'update', 'Loan ' . $name, $loanId);
            setFlash('success', 'Loan updated.');
        } else {
            $pdo->prepare('INSERT INTO loan (pid, name, amount, received_date, return_date, type, method, notes, uid, returned, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())')->execute([$personId, $name, $amount, $received, $return, $type, $method, $notes, $uid]);
            systemLog($pdo, $uid, 'loan', 'create', 'Loan ' . $name);
            setFlash('success', 'Loan saved.');
        }
        header('Location: loan_page.php');
        exit;
    }
}

$people = getPeople($pdo);
$rows = $pdo->query('SELECT l.*, p.name AS person_name FROM loan l LEFT JOIN people p ON p.ID=l.pid ORDER BY l.ID DESC')->fetchAll();
$editRow = null;
foreach ($rows as $row) {
    if ((int)$row['ID'] === $editId) { $editRow = $row; break; }
}
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Loans</h1>
<div class="grid-2 loan-layout">
  <div class="card stack loan-form-card"><?php if($errors): ?><div class="alert error"><?php foreach($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
    <div class="toolbar">
      <h2 class="loan-subtitle"><?= $editRow ? 'Edit Loan' : 'New Loan' ?></h2>
      <?php if ($editRow): ?><a class="btn" href="loan_page.php">Cancel</a><?php endif; ?>
    </div>
    <form method="post" class="stack">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
      <?php if ($editRow): ?><input type="hidden" name="loan_id" value="<?= (int)$editRow['ID'] ?>"><?php endif; ?>
      <div><label>Person</label><select name="person_id" required><option value="">Select person</option><?php foreach($people as $person): ?><option value="<?= (int)$person['ID'] ?>" <?= (int)($editRow['pid'] ?? 0) === (int)$person['ID'] ? 'selected' : '' ?>><?= e($person['name']) ?> · <?= e($person['phone']) ?></option><?php endforeach; ?></select></div>
      <div><label>Loan Name</label><input type="text" name="name" value="<?= e((string)($editRow['name'] ?? '')) ?>" required></div>
      <div><label>Amount</label><input type="number" step="0.01" name="amount" value="<?= e((string)($editRow['amount'] ?? '')) ?>" required></div>
      <div class="inline-grid-2"><div><label>Received Date</label><input type="date" name="received_date" value="<?= e((string)($editRow['received_date'] ?? date('Y-m-d'))) ?>"></div><div><label>Return Date</label><input type="date" name="return_date" value="<?= e((string)($editRow['return_date'] ?? date('Y-m-d', strtotime('+30 days')))) ?>"></div></div>
      <div><label>Type</label><div class="payment-chip-group"><?php foreach(['Receive','Provide'] as $typeOption): ?><label class="selector-label"><input class="selector-input" type="radio" name="type" value="<?= e($typeOption) ?>" <?= (($editRow['type'] ?? 'Receive') === $typeOption) ? 'checked' : '' ?>><span class="payment-chip"><span><?= $typeOption === 'Receive' ? '📥' : '📤' ?></span><span><?= e($typeOption) ?></span></span></label><?php endforeach; ?></div></div>
      <div><label>Method</label><div class="payment-chip-group"><?php foreach([['cash','💵','Cash'],['bank_transfer','🏦','Bank'],['pos','💳','POS'],['online','🌐','Online']] as $m): ?><label class="selector-label"><input class="selector-input" type="radio" name="method" value="<?= e($m[0]) ?>" <?= (($editRow['method'] ?? 'cash') === $m[0]) ? 'checked' : '' ?>><span class="payment-chip"><span><?= e($m[1]) ?></span><span><?= e($m[2]) ?></span></span></label><?php endforeach; ?></div></div>
      <div><label>Notes</label><textarea name="notes"><?= e((string)($editRow['notes'] ?? '')) ?></textarea></div>
      <?php if ($editRow): ?>
      <div class="switch-row"><div><strong>Returned</strong><div class="muted">Mark returned loans without deleting the record.</div></div><label><input type="checkbox" name="returned" value="1" <?= (int)($editRow['returned'] ?? 0) === 1 ? 'checked' : '' ?>> Returned</label></div>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit"><?= $editRow ? 'Update Loan' : 'Save Loan' ?></button>
    </form>
  </div>
  <div class="card"><div class="toolbar"><h2 class="loan-subtitle">Loan List</h2><span class="tag blue"><?= count($rows) ?> total</span></div><div class="table-wrap"><table><thead><tr><th>Person</th><th>Loan</th><th>Amount</th><th>Received</th><th>Return</th><th>Type</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= e((string)$row['person_name']) ?></td><td><?= e((string)$row['name']) ?></td><td><?= money((float)$row['amount']) ?></td><td><?= e((string)$row['received_date']) ?></td><td><?= e((string)$row['return_date']) ?></td><td><?= e((string)$row['type']) ?></td><td><span class="tag <?= (int)$row['returned']===1?'green':'orange' ?>"><?= (int)$row['returned']===1?'Returned':'Active' ?></span></td><td><a class="btn" href="loan_page.php?edit=<?= (int)$row['ID'] ?>">Edit</a></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="8" class="muted">No loans yet.</td></tr><?php endif; ?></tbody></table></div></div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
