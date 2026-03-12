<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$role = currentRole($pdo);
$uid = getLoggedInUserId();
$isFinanceDashboard = in_array($role, ['admin', 'accountant'], true);

$cards = [
    'Monthly Total Collected' => getMonthlyTotalCollected($pdo),
    'Monthly Agreed' => getMonthlyAgreed($pdo),
    'Monthly Collected' => getMonthlyCollected($pdo),
    'Monthly Expense' => getMonthlyExpense($pdo),
];

if ($role === 'operator') {
    $cards['My Cash in Hand'] = operatorBalance($pdo, $uid);
    $cards['Pending Monthly'] = getPendingMonthly($pdo);
} else {
    $cards['Total Cash in Hand (All Operators)'] = getTotalCashInAllOperators($pdo);
    $cards['Total Cash in Bank Account'] = getAccountantBankBalance($pdo);
    $cards['All Donation Collection Till Now'] = getTotalCollectionTillNow($pdo);
    $cards['All Expense Till Now'] = getTotalExpenseTillNow($pdo);
    $cards['Pending Monthly'] = getPendingMonthly($pdo);
}

$dailySeries = getDailyCollectionSeries($pdo);
$methodTotals = getCollectionByMethod($pdo, currentMonthStart(), nextMonthStart());
$previousStripe = getPreviousMonthStripeAmount($pdo);
$recentRows = [];
if (tableExists($pdo, 'operator_ledger')) {
    $sql = "SELECT ol.ID, ol.created_at, ol.transaction_category, ol.amount, ol.payment_method, p.name AS person_name, u.name AS operator_name FROM operator_ledger ol LEFT JOIN people p ON p.ID = ol.person_id LEFT JOIN users u ON u.ID = ol.operator_id";
    if ($role === 'operator') {
        $sql .= ' WHERE ol.operator_id = ' . (int)$uid;
    }
    $sql .= ' ORDER BY ol.ID DESC LIMIT 12';
    $recentRows = $pdo->query($sql)->fetchAll();
}
$events = activeEventsSummary($pdo);
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Dashboard</h1>
<div class="muted" style="margin-bottom:18px;">Current month overview for <?= e(currentMonthLabel()) ?>. Stripe reconciliation is posted in the following month, so the online card below uses <strong><?= e(previousMonthLabel()) ?></strong>.</div>
<div class="grid-3 dashboard-cards" style="margin-bottom:24px;">
  <?php $cardIndex = 0; foreach ($cards as $label => $value): ?>
    <?php $cardIndex++; ?>
    <div class="card stat-card dashboard-card dashboard-card-<?= (int)$cardIndex ?> dashboard-card-role-<?= e($role) ?>">
      <div class="dashboard-card-label"><?= e($label) ?></div>
      <div class="summary dashboard-card-value"><?= money((float)$value) ?></div>
      <?php if ($label === 'My Cash in Hand'): ?>
        <div class="dashboard-card-note">Pending transfer approval: <?= money(operatorPendingTransfers($pdo, $uid)) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<div class="grid-2 ledger-grid" style="margin-top:24px;">
  <div class="card">
    <div class="toolbar dashboard-toolbar"><h2 class="dashboard-section-title">Monthly Collected Amount</h2><span class="tag blue"><?= e(currentMonthLabel()) ?></span></div>
    <div class="chart-shell">
      <?php $max = max(1, ...array_values($dailySeries)); foreach ($dailySeries as $date => $amount): $height = max(8, (int)round(($amount / $max) * 180)); ?>
        <div class="chart-bar-wrap">
          <div class="chart-value"><?= (int)round($amount) ?></div>
          <div class="chart-bar" style="height: <?= $height ?>px"></div>
          <div class="chart-label"><?= e(date('d', strtotime($date))) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="stack">
    <div class="card">
      <div class="toolbar dashboard-toolbar"><h2 class="dashboard-section-title">Collection by Payment Method</h2></div>
      <div class="stack compact dashboard-method-list">
        <?php foreach ($methodTotals as $method => $total): ?>
          <div class="meter-row dashboard-method-row"><div class="dashboard-method-label"><strong><?= e($method === 'bank_manual' ? 'Bank' : ($method === 'cash_manual' ? 'Cash' : ucwords(str_replace('_', ' ', $method)))) ?></strong></div><div class="dashboard-method-value"><?= money((float)$total) ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card">
      <div class="toolbar dashboard-toolbar"><h2 class="dashboard-section-title">Previous Month Online Report</h2><span class="tag orange"><?= e(previousMonthLabel()) ?></span></div>
      <div class="summary dashboard-online-value"><?= money($previousStripe) ?></div>
      <div class="muted">Accountant posts all successful Stripe monthly payments from the previous month and leaves failed ones suspended.</div>
    </div>
  </div>
</div>
<div class="grid-2" style="margin-top:24px;">
  <div class="card dashboard-recent-activity dashboard-recent-activity-v5">
    <div class="toolbar"><h2 style="margin:0">Recent Activity</h2><?php if ($role !== 'operator'): ?><a class="btn" href="accounts_report.php">Open Accounts Report</a><?php endif; ?></div>
    <div class="table-wrap">
      <table><thead><tr><th>Date</th><th>Category</th><th>Person</th><th>Operator</th><th>Method</th><th>Amount</th></tr></thead><tbody>
      <?php foreach ($recentRows as $row): ?>
        <tr>
          <td><?= e((string)$row['created_at']) ?></td>
          <td><span class="tag orange"><?= e((string)$row['transaction_category']) ?></span></td>
          <td><?= e((string)($row['person_name'] ?: '—')) ?></td>
          <td><?= e((string)($row['operator_name'] ?: '—')) ?></td>
          <td><?= e((string)$row['payment_method'] === 'bank_manual' ? 'Bank' : ((string)$row['payment_method'] === 'cash_manual' ? 'Cash' : ucwords(str_replace('_', ' ', (string)$row['payment_method'])))) ?></td>
          <td><?= money((float)$row['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentRows): ?><tr><td colspan="6" class="muted">No activity available yet.</td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>
  <div class="card dashboard-events-card">
    <div class="toolbar dashboard-toolbar-wrap"><h2 class="dashboard-section-title">Active Events</h2><?php if ($isFinanceDashboard): ?><a class="btn" href="event_page.php">View Events</a><?php endif; ?></div>
    <div class="stack compact dashboard-events-list dashboard-events-list-v5">
      <?php foreach ($events as $event): ?>
        <div class="meter-row dashboard-event-row">
          <div>
            <strong><?= e((string)$event['name']) ?></strong><br>
            <span class="muted">Collected <?= money((float)$event['collected']) ?> / Target <?= money((float)$event['estimate']) ?></span>
          </div>
          <div><span class="tag <?= (float)$event['remaining'] <= 0 ? 'green' : 'blue' ?>"><?= money((float)$event['remaining']) ?></span></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$events): ?><div class="muted">No active events found.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
