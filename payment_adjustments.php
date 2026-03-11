<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAccountant($pdo);
$uid = getLoggedInUserId(); $errors=[];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrfOrFail();
  $amount = (float)($_POST['amount'] ?? 0); $notes = trim((string)($_POST['notes'] ?? '')); $sign = trim((string)($_POST['management_sign'] ?? ''));
  if ($amount == 0.0) $errors[] = 'Amount is required.'; if ($sign === '') $errors[] = 'Management sign / approval is required.';
  if (!$errors) { $pdo->prepare('INSERT INTO accountant_adjustments (amount, notes, management_sign, status, created_by, created_at) VALUES (?, ?, ?, "approved", ?, NOW())')->execute([$amount,$notes,$sign,$uid]); $adjId = (int)$pdo->lastInsertId(); $pdo->prepare('INSERT INTO accountant_ledger (entry_type, amount, payment_method, notes, created_by, created_at) VALUES ("adjustment", ?, "adjustment", ?, ?, NOW())')->execute([$amount,'Adjustment #'.$adjId.' / '.$notes,$uid]); systemLog($pdo,$uid,'adjustment','create','Adjustment '.number_format($amount,2),$adjId); setFlash('success','Adjustment posted.'); header('Location: payment_adjustments.php'); exit; }
}
$rows = tableExists($pdo,'accountant_adjustments') ? $pdo->query('SELECT * FROM accountant_adjustments ORDER BY ID DESC LIMIT 50')->fetchAll() : [];
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Payment Adjustments</h1>
<div class="grid-2">
  <div class="card stack"><?php if($errors): ?><div class="alert error"><?php foreach($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?><div class="helper">Use this page only for opening balances or accountant corrections. Each adjustment is printed and recorded for approval.</div><form method="post" class="stack"><?= csrfField() ?><div><label>Amount (+ / -)</label><input type="number" step="0.01" name="amount" required></div><div><label>Management Sign / Approval</label><input type="text" name="management_sign" required></div><div><label>Notes</label><textarea name="notes"></textarea></div><button class="btn btn-primary" type="submit">Save Adjustment</button></form></div>
  <div class="card"><div class="toolbar"><h2 style="margin:0">Adjustment Record</h2><button class="btn" onclick="window.print()">Print Letter</button></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Amount</th><th>Sign</th><th>Status</th><th>Notes</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= e((string)$row['created_at']) ?></td><td><?= money((float)$row['amount']) ?></td><td><?= e((string)$row['management_sign']) ?></td><td><span class="tag green">Approved</span></td><td><?= e((string)$row['notes']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
