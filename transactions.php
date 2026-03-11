<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'operator');

$uid = getLoggedInUserId();
$rows = tableExists($pdo, 'operator_ledger') ? $pdo->query('SELECT ol.ID, ol.person_id, ol.reference_id, ol.created_at AS date, p.name AS person, ol.transaction_category AS category, COALESCE(e.name, l.name, "—") AS reference_name, ol.amount, ol.payment_method AS method, ol.notes, ol.invoice_no FROM operator_ledger ol LEFT JOIN people p ON p.ID = ol.person_id LEFT JOIN events e ON e.ID = ol.reference_id LEFT JOIN loan l ON l.ID = ol.reference_id WHERE ol.operator_id = ' . (int)$uid . ' ORDER BY ol.ID DESC LIMIT 100')->fetchAll() : [];
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Transactions</h1>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Invoice</th><th>Donor</th><th>Category</th><th>Reference</th><th>Method</th><th>Amount</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= e((string)$row['date']) ?></td>
          <td><?= e((string)$row['invoice_no']) ?></td>
          <td><?= e(resolveLedgerDonorName($pdo, $row)) ?></td>
          <td><span class="tag orange"><?= e(ucwords(str_replace('_', ' ', (string)$row['category']))) ?></span></td>
          <td><?= e((string)$row['reference_name']) ?></td>
          <td><?= e((string)$row['method']) ?></td>
          <td><?= money((float)$row['amount']) ?></td>
          <td><a class="btn" target="_blank" href="receipt_print.php?id=<?= (int)$row['ID'] ?>">Receipt</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted">No transactions yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
