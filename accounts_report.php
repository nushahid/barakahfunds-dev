<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAccountant($pdo);

$period = (string)($_GET['period'] ?? 'monthly');
[$start, $end, $label] = match ($period) {
    'quarterly' => [date('Y-m-01 00:00:00', strtotime('-2 months')), date('Y-m-01 00:00:00', strtotime('+1 month')), 'Current Quarter'],
    'annual' => [date('Y-01-01 00:00:00'), date('Y-01-01 00:00:00', strtotime('+1 year')), 'Current Year'],
    default => [currentMonthStart(), nextMonthStart(), currentMonthLabel()],
};
$startDate = date('Y-m-d', strtotime($start));
$endDate = date('Y-m-d', strtotime($end));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    if (currentRole($pdo) === 'accountant' && (string)($_POST['action'] ?? '') === 'bank_deposit') {
        $amount = (float)($_POST['amount'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $cashOnHandNow = getAccountantCashOnHand($pdo);

        if ($amount > 0 && $amount <= $cashOnHandNow) {
            $pdo->prepare('INSERT INTO accountant_ledger (entry_type, amount, payment_method, notes, created_by, created_at) VALUES ("bank_deposit", ?, "bank_transfer", ?, ?, NOW())')
                ->execute([$amount, $notes !== '' ? $notes : 'Cash moved to bank', getLoggedInUserId()]);
            systemLog($pdo, getLoggedInUserId(), 'accountant', 'bank_deposit', 'Bank deposit ' . number_format($amount, 2));
            setFlash('success', 'Cash moved to bank successfully.');
        } else {
            setFlash('error', 'Deposit amount must be positive and cannot exceed accountant cash in hand.');
        }

        header('Location: accounts_report.php?period=' . urlencode($period));
        exit;
    }
}

function reportSum(PDO $pdo, string $sql, array $params = []): float
{
    try {
        return (float)queryValue($pdo, $sql, $params);
    } catch (Throwable $e) {
        return 0.0;
    }
}
// Keep it for operator_ledger (Collections & Expenses)
$collections = reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE amount > 0 AND created_at >= ? AND created_at < ? AND COALESCE(is_removed, 0) = 0', [$start, $end]);
$expenses = abs(reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE amount < 0 AND transaction_category = "expense" AND created_at >= ? AND created_at < ? AND COALESCE(is_removed, 0) = 0', [$start, $end]));

// REMOVE it for accountant_ledger (Adjustments)
$adjustments = reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM accountant_ledger WHERE entry_type = "adjustment" AND created_at >= ? AND created_at < ?', [$start, $end]);

// Opening Balance - operator_ledger (Keep it)
$collectionsBefore = reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE amount > 0 AND created_at < ? AND COALESCE(is_removed, 0) = 0', [$start]);
$expensesBefore = abs(reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE amount < 0 AND transaction_category = "expense" AND created_at < ? AND COALESCE(is_removed, 0) = 0', [$start]));

// Opening Balance - accountant_ledger (Remove it)
$adjustmentsBefore = reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM accountant_ledger WHERE entry_type = "adjustment" AND created_at < ?', [$start]);

$openingBalance = $collectionsBefore - $expensesBefore + $adjustmentsBefore;
$closingBalance = $openingBalance + $collections - $expenses + $adjustments;

$transfers = reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM accountant_ledger WHERE amount > 0 AND entry_type = "transfer_in" AND created_at >= ? AND created_at < ?', [$start, $end]);
$stripePosted = reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM accountant_ledger WHERE amount > 0 AND entry_type = "stripe_reconciliation" AND created_at >= ? AND created_at < ?', [$start, $end]);
$bankDeposits = reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM accountant_ledger WHERE entry_type = "bank_deposit" AND created_at >= ? AND created_at < ?', [$start, $end]);

$loanReceived = 0.0;
if (tableExists($pdo, 'loan')) {
    $loanReceived = reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM loan WHERE UPPER(COALESCE(type,"")) = "RECEIVE" AND received_date >= ? AND received_date < ?', [$startDate, $endDate]);
}

$loanReturned = 0.0;
if (tableExists($pdo, 'loan_trans')) {
    $loanReturned = reportSum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM loan_trans WHERE created_at >= ? AND created_at < ?', [$start, $end]);
}

$allOperatorsCash = function_exists('allOperatorsCashInHand') ? allOperatorsCashInHand($pdo) : 0.0;
$cashOnHand = getAccountantCashOnHand($pdo);
$bankBalance = getAccountantBankBalance($pdo);
$methods = getCollectionByMethod($pdo, $start, $end);
if (!isset($methods['online'])) {
    $methods['online'] = 0.0;
}

// Operator Ledger (Keep is_removed)
$rowsStmt = $pdo->prepare('SELECT created_at, invoice_no, transaction_category, payment_method, amount, notes FROM operator_ledger WHERE created_at >= ? AND created_at < ? AND COALESCE(is_removed, 0) = 0 ORDER BY ID DESC LIMIT 200');


$rowsStmt->execute([$start, $end]);
$rows = $rowsStmt->fetchAll();

$accRows = [];
if (tableExists($pdo, 'accountant_ledger')) {
// Accountant Ledger (Remove is_removed)
$accStmt = $pdo->prepare('SELECT created_at, entry_type, payment_method, amount, notes FROM accountant_ledger WHERE created_at >= ? AND created_at < ? ORDER BY ID DESC LIMIT 100');

    $accStmt->execute([$start, $end]);
    $accRows = $accStmt->fetchAll();
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.reports-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.reports-summary .card{min-width:0}
@media (max-width: 960px){
  .reports-summary{grid-template-columns:repeat(2,minmax(0,1fr)) !important;gap:12px}
}
</style>
<h1 class="title">Accounts Report</h1>
<div class="toolbar">
    <div class="badge-list">
        <a class="btn <?= $period === 'monthly' ? 'btn-primary' : '' ?>" href="?period=monthly">Monthly</a>
        <a class="btn <?= $period === 'quarterly' ? 'btn-primary' : '' ?>" href="?period=quarterly">Quarterly</a>
        <a class="btn <?= $period === 'annual' ? 'btn-primary' : '' ?>" href="?period=annual">Annual</a>
    </div>
    <span class="tag blue"><?= e($label) ?></span>
</div>

<div class="grid-4 donors-summary reports-summary" style="margin-bottom:18px;">
    <div class="card">
        <div class="muted">Opening Balance</div>
        <div class="summary"><?= money($openingBalance) ?></div>
    </div>
    <div class="card">
        <div class="muted">Total Donation Collected</div>
        <div class="summary"><?= money($collections) ?></div>
    </div>
    <div class="card">
        <div class="muted">Total Expense</div>
        <div class="summary"><?= money($expenses) ?></div>
    </div>
    <div class="card">
        <div class="muted">Closing Balance</div>
        <div class="summary"><?= money($closingBalance) ?></div>
    </div>
</div>

<div class="grid-2">
    <div class="card report-summary-card">
        <h2 style="margin-top:0">Mosque Treasury Summary</h2>
        <div class="meter-row"><div><strong>Total Donation Collected</strong></div><div><?= money($collections) ?></div></div>
        <div class="meter-row"><div><strong>Total Expense</strong></div><div><?= money($expenses) ?></div></div>
        <div class="meter-row"><div><strong>Total Loan Received</strong></div><div><?= money($loanReceived) ?></div></div>
        <div class="meter-row"><div><strong>Total Loan Returned</strong></div><div><?= money($loanReturned) ?></div></div>
        <div class="meter-row"><div><strong>Adjustments</strong></div><div><?= money($adjustments) ?></div></div>
        <div class="meter-row"><div><strong>Transfers Received</strong></div><div><?= money($transfers) ?></div></div>
        <div class="meter-row"><div><strong>Stripe Posted</strong></div><div><?= money($stripePosted) ?></div></div>
        <div class="meter-row"><div><strong>Opening Balance</strong></div><div><?= money($openingBalance) ?></div></div>
        <div class="meter-row"><div><strong>Closing Balance</strong></div><div><?= money($closingBalance) ?></div></div>
    </div>

    <div class="card report-summary-card">
        <h2 style="margin-top:0">Current Holdings</h2>
        <div class="meter-row"><div><strong>All Operators Cash in Hand</strong></div><div><?= money($allOperatorsCash) ?></div></div>
        <div class="meter-row"><div><strong>Accountant Cash in Hand</strong></div><div><?= money($cashOnHand) ?></div></div>
        <div class="meter-row"><div><strong>Total Cash in Hand</strong></div><div><?= money($allOperatorsCash + $cashOnHand) ?></div></div>
        <div class="meter-row"><div><strong>Total Cash in Bank</strong></div><div><?= money($bankBalance) ?></div></div>
        <div class="meter-row"><div><strong>Cash Moved to Bank</strong></div><div><?= money($bankDeposits) ?></div></div>
    </div>
</div>

<div class="card report-method-card" style="margin-top:18px;">
    <h2 style="margin-top:0">By Payment Method</h2>
    <?php
    $methodLabels = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'pos' => 'POS',
        'online' => 'Stripe / Online',
        'adjustment' => 'Adjustment',
    ];
    foreach ($methods as $method => $value):
        if ((float)$value == 0.0 && !in_array($method, ['cash', 'bank_transfer', 'pos', 'online'], true)) {
            continue;
        }
        $labelText = $methodLabels[$method] ?? ucwords(str_replace('_', ' ', (string)$method));
    ?>
        <div class="meter-row"><div><strong><?= e($labelText) ?></strong></div><div><?= money((float)$value) ?></div></div>
    <?php endforeach; ?>
</div>

<!-- <?php if (currentRole($pdo) === 'accountant'): ?>
<div class="card" style="margin-top:18px;">
    <h2 style="margin-top:0">Move Cash to Bank</h2>
    <div class="helper">Use this when the accountant deposits mosque cash into the bank. This reduces accountant cash in hand and increases bank balance.</div>
    <form method="post" class="stack compact">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bank_deposit">
        <div class="inline-grid-2">
            <div>
                <label>Amount</label>
                <input type="number" step="0.01" min="0.01" max="<?= e((string)$cashOnHand) ?>" name="amount" required>
            </div>
            <div>
                <label>Notes</label>
                <input type="text" name="notes" placeholder="Bank deposit reference">
            </div>
        </div>
        <button class="btn btn-primary" type="submit">Transfer Cash to Bank</button>
    </form>
</div>
<?php endif; ?> -->

<div class="card" style="margin-top:18px;">
    <div class="toolbar">
        <h2 style="margin:0">Detailed Collection Ledger</h2>
        <button class="btn" onclick="window.print()">Print</button>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Invoice</th><th>Category</th><th>Method</th><th>Amount</th><th>Notes</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e((string)$row['created_at']) ?></td>
                        <td><?= e((string)($row['invoice_no'] ?? '—')) ?></td>
                        <td><?= e((string)$row['transaction_category']) ?></td>
                        <td><?= e((string)$row['payment_method']) ?></td>
                        <td><?= money((float)$row['amount']) ?></td>
                        <td><?= e((string)$row['notes']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="muted">No collection entries found for this period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:18px;">
    <h2 style="margin-top:0">Accountant Ledger</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Entry Type</th><th>Method</th><th>Amount</th><th>Notes</th></tr>
            </thead>
            <tbody>
                <?php foreach ($accRows as $row): ?>
                    <tr>
                        <td><?= e((string)$row['created_at']) ?></td>
                        <td><?= e((string)$row['entry_type']) ?></td>
                        <td><?= e((string)$row['payment_method']) ?></td>
                        <td><?= money((float)$row['amount']) ?></td>
                        <td><?= e((string)$row['notes']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$accRows): ?>
                    <tr><td colspan="5" class="muted">No accountant ledger entries for this period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
