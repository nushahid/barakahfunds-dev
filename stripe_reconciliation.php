<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAccountant($pdo);
if (currentRole($pdo) !== 'accountant' && currentRole($pdo) !== 'admin') { exit('Forbidden'); }
$uid = getLoggedInUserId();
$month = (string)($_GET['month'] ?? date('Y-m', strtotime('-1 month')));
$start = $month . '-01';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrfOrFail();
  $month = (string)($_POST['month'] ?? $month); $start = $month . '-01';
  $selected = array_map('intval', array_keys($_POST['paid'] ?? []));
  $failed = array_map('intval', array_keys($_POST['failed'] ?? []));
  foreach ($selected as $personId) {
    $plan = getPersonCurrentPlan($pdo, $personId); if (!$plan) continue;
    $exists = queryValue($pdo, 'SELECT COUNT(*) FROM member_monthly_dues WHERE plan_id = ? AND due_month = ?', [(int)$plan['ID'],$start], 0);
    if ($exists > 0) continue;
    $amount = (float)$plan['amount'];
    $pdo->prepare('INSERT INTO member_monthly_dues (plan_id, due_month, expected_amount, status, paid_amount, paid_at, payment_source, operator_id, notes, ledger_id) VALUES (?, ?, ?, "paid", ?, NOW(), "stripe", NULL, ?, NULL)')->execute([(int)$plan['ID'],$start,$amount,$amount,'Posted from Stripe reconciliation']);
    $pdo->prepare('INSERT INTO accountant_ledger (entry_type, amount, payment_method, notes, created_by, created_at) VALUES ("stripe_reconciliation", ?, "online", ?, ?, NOW())')->execute([$amount,'Stripe reconciliation for donor #'.$personId.' / '.$month,$uid]);
    if (tableExists($pdo,'operator_ledger')) {
      $pdo->prepare('INSERT INTO operator_ledger (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, notes, created_by, created_at) VALUES (?, ?, "collection", "monthly", ?, "online", ?, ?, NOW())')->execute([$uid,$personId,$amount,'Stripe monthly reconciliation '.$month,$uid]);
    }
    personLog($pdo,$uid,$personId,'stripe_paid','Stripe paid for '.$month);
  }
  foreach ($failed as $personId) {
    $plan = getPersonCurrentPlan($pdo, $personId); if (!$plan) continue;
    $pdo->prepare('INSERT INTO member_monthly_dues (plan_id, due_month, expected_amount, status, paid_amount, payment_source, notes) VALUES (?, ?, ?, "failed", 0, "stripe", ?) ON DUPLICATE KEY UPDATE status="failed", notes=VALUES(notes)')->execute([(int)$plan['ID'],$start,(float)$plan['amount'],'Stripe failed for '.$month]);
    $pdo->prepare('UPDATE member_monthly_plans SET active = 0, notes = ? WHERE ID = ?')->execute(['Suspended after failed Stripe payment in ' . $month, (int)$plan['ID']]);
    personLog($pdo,$uid,$personId,'stripe_failed','Stripe failed and plan suspended for '.$month);
  }
  systemLog($pdo,$uid,'stripe','reconcile','Month '.$month.' posted');
  setFlash('success','Stripe reconciliation posted.'); header('Location: stripe_reconciliation.php?month='.$month); exit;
}
$rows = [];
if (tableExists($pdo, 'member_monthly_plans')) {
  $sql = 'SELECT p.ID AS person_id, p.name, p.phone, m.amount, m.payment_mode FROM member_monthly_plans m JOIN people p ON p.ID = m.member_id WHERE m.active = 1 AND m.payment_mode = "stripe_auto" ORDER BY p.name ASC';
  $rows = $pdo->query($sql)->fetchAll();
}
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Stripe Reconciliation</h1>
<div class="helper" style="margin-bottom:16px;">All active Stripe donors are listed for the <strong>previous month</strong>. Keep successful donors checked, uncheck failures, and failed donors will be suspended from next month until they are reactivated.</div>
<div class="card">
  <form method="post" class="stack">
    <?= csrfField() ?>
    <div class="inline-grid-2"><div><label>Month</label><input type="month" name="month" value="<?= e($month) ?>"></div><div class="muted" style="align-self:end">Default workflow: all listed donors are successful, only failed ones are marked below.</div></div>
    <div class="table-wrap"><table><thead><tr><th>Pay</th><th>Fail</th><th>Donor</th><th>Phone</th><th>Agreed Amount</th><th>Mode</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><input type="checkbox" name="paid[<?= (int)$row['person_id'] ?>]" value="1" checked></td><td><input type="checkbox" name="failed[<?= (int)$row['person_id'] ?>]" value="1"></td><td><?= e((string)$row['name']) ?></td><td><?= e((string)$row['phone']) ?></td><td><?= money((float)$row['amount']) ?></td><td><?= e((string)$row['payment_mode']) ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="6" class="muted">No active Stripe donors found.</td></tr><?php endif; ?></tbody></table></div>
    <button class="btn btn-primary" type="submit">Post Selected To Ledger</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
