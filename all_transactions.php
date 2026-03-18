<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'accountant');

$pageClass = 'page-all-transactions';
$cssFile = 'assets/css/all_transactions.css';

$method     = trim((string)($_GET['method'] ?? ''));
$category   = trim((string)($_GET['category'] ?? ''));
$dateFrom   = trim((string)($_GET['date_from'] ?? ''));
$dateTo     = trim((string)($_GET['date_to'] ?? ''));
$operatorId = max(0, (int)($_GET['operator_id'] ?? 0));

function atxFormatDate(?string $value): string
{
    if (!$value) {
        return '—';
    }

    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : (string)$value;
}

function atxBuildOperatorLedgerWhere(string $alias, string $method, string $category, string $dateFrom, string $dateTo, int $operatorId): array
{
    $where = ["COALESCE({$alias}.is_removed, 0) = 0"];
    $params = [];

    if ($method !== '') {
        $where[] = "{$alias}.payment_method = :method";
        $params['method'] = $method;
    }

    if ($category !== '') {
        $where[] = "{$alias}.transaction_category = :category";
        $params['category'] = $category;
    }

    if ($dateFrom !== '') {
        $where[] = "DATE({$alias}.created_at) >= :date_from";
        $params['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "DATE({$alias}.created_at) <= :date_to";
        $params['date_to'] = $dateTo;
    }

    if ($operatorId > 0) {
        $where[] = "{$alias}.operator_id = :operator_id";
        $params['operator_id'] = $operatorId;
    }

    return [$where, $params];
}

function atxBuildExpenseWhere(string $alias, string $dateFrom, string $dateTo, int $operatorId): array
{
    $where = ['1=1'];
    $params = [];

    if ($dateFrom !== '') {
        $where[] = "DATE(COALESCE({$alias}.expense_date, {$alias}.created_at)) >= :expense_date_from";
        $params['expense_date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "DATE(COALESCE({$alias}.expense_date, {$alias}.created_at)) <= :expense_date_to";
        $params['expense_date_to'] = $dateTo;
    }

    if ($operatorId > 0) {
        $where[] = "{$alias}.uid = :expense_operator_id";
        $params['expense_operator_id'] = $operatorId;
    }

    return [$where, $params];
}

function atxBuildLoanWhere(string $alias, string $dateFrom, string $dateTo, int $operatorId): array
{
    $where = ['1=1'];
    $params = [];

    if ($dateFrom !== '') {
        $where[] = "DATE(COALESCE({$alias}.received_date, {$alias}.created_at)) >= :loan_date_from";
        $params['loan_date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "DATE(COALESCE({$alias}.received_date, {$alias}.created_at)) <= :loan_date_to";
        $params['loan_date_to'] = $dateTo;
    }

    if ($operatorId > 0) {
        $where[] = "{$alias}.uid = :loan_operator_id";
        $params['loan_operator_id'] = $operatorId;
    }

    return [$where, $params];
}

function atxBuildLoanTransWhere(string $alias, string $dateFrom, string $dateTo, int $operatorId): array
{
    $where = ['1=1'];
    $params = [];

    if ($dateFrom !== '') {
        $where[] = "DATE({$alias}.created_at) >= :loan_trans_from";
        $params['loan_trans_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "DATE({$alias}.created_at) <= :loan_trans_to";
        $params['loan_trans_to'] = $dateTo;
    }

    if ($operatorId > 0) {
        $where[] = "{$alias}.uid = :loan_trans_operator_id";
        $params['loan_trans_operator_id'] = $operatorId;
    }

    return [$where, $params];
}

function atxBuildTransferWhere(string $alias, string $dateFrom, string $dateTo): array
{
    $where = ['1=1'];
    $params = [];

    if ($dateFrom !== '') {
        $where[] = "DATE({$alias}.created_at) >= :transfer_from";
        $params['transfer_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "DATE({$alias}.created_at) <= :transfer_to";
        $params['transfer_to'] = $dateTo;
    }

    return [$where, $params];
}

[$ledgerWhere, $ledgerParams] = atxBuildOperatorLedgerWhere('ol', $method, $category, $dateFrom, $dateTo, $operatorId);
[$expenseWhere, $expenseParams] = atxBuildExpenseWhere('ex', $dateFrom, $dateTo, $operatorId);
[$loanWhere, $loanParams] = atxBuildLoanWhere('l', $dateFrom, $dateTo, $operatorId);
[$loanTransWhere, $loanTransParams] = atxBuildLoanTransWhere('lt', $dateFrom, $dateTo, $operatorId);
[$transferWhere, $transferParams] = atxBuildTransferWhere('tl', $dateFrom, $dateTo);

$rows = [];
$summary = [
    'total_donation' => 0.0,
    'operator_cash_in_hand' => 0.0,
    'total_expense' => 0.0,
    'total_monthly_agreed' => 0.0,
    'total_monthly_collected' => 0.0,
    'total_event_amount' => 0.0,
    'total_general_donation' => 0.0,
    'total_loan_collected' => 0.0,
    'total_loan_return' => 0.0,
    'total_loan_remaining' => 0.0,
    'selected_operator_loan_remaining' => 0.0,
    'transfer_out' => 0.0,
    'transfer_in' => 0.0,
];
$categoryCards = [];
$eventCards = [];
$selectedOperatorName = 'All Operators';

if ($operatorId > 0 && tableExists($pdo, 'users')) {
    $stmt = $pdo->prepare('SELECT name FROM users WHERE ID = :id LIMIT 1');
    $stmt->execute(['id' => $operatorId]);
    $selectedOperatorName = (string)($stmt->fetchColumn() ?: 'Selected Operator');
}

if (tableExists($pdo, 'operator_ledger')) {
    $sql = "
        SELECT 
            ol.ID,
            ol.person_id,
            ol.reference_id,
            ol.operator_id,
            ol.transaction_type,
            ol.transaction_category AS category,
            ol.created_at AS date,
            p.name AS person,
            CASE 
                WHEN LOWER(COALESCE(ol.transaction_category, '')) = 'event' THEN ev.name
                WHEN LOWER(COALESCE(ol.transaction_type, '')) LIKE '%loan%' OR LOWER(COALESCE(ol.transaction_category, '')) LIKE '%loan%' THEN ln.name
                ELSE '—'
            END AS reference_name,
            ol.amount,
            ol.payment_method AS method,
            ol.notes,
            ol.invoice_no,
            ol.receipt_path,
            u.name AS operator_name
        FROM operator_ledger ol
        LEFT JOIN people p ON p.ID = ol.person_id
        LEFT JOIN events ev ON ev.ID = ol.reference_id
        LEFT JOIN loan ln ON ln.ID = ol.reference_id
        LEFT JOIN users u ON u.ID = ol.operator_id
        WHERE " . implode(' AND ', $ledgerWhere) . "
        ORDER BY ol.ID DESC
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ledgerParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summarySql = "
        SELECT
            COALESCE(SUM(ol.amount), 0) AS total_donation,
            COALESCE(SUM(CASE
                WHEN LOWER(COALESCE(ol.transaction_category, '')) = 'monthly'
                  OR LOWER(COALESCE(ol.transaction_type, '')) LIKE '%monthly%'
                THEN ol.amount ELSE 0 END), 0) AS total_monthly_collected,
            COALESCE(SUM(CASE
                WHEN LOWER(COALESCE(ol.transaction_category, '')) = 'event'
                  OR LOWER(COALESCE(ol.transaction_type, '')) LIKE '%event%'
                THEN ol.amount ELSE 0 END), 0) AS total_event_amount,
            COALESCE(SUM(CASE
                WHEN LOWER(COALESCE(ol.transaction_category, '')) IN ('donation', 'one_time', 'anonymous', 'anonymous_collection', 'life_membership')
                  OR LOWER(COALESCE(ol.transaction_type, '')) IN ('donation', 'one_time', 'anonymous_collection', 'life_membership')
                THEN ol.amount ELSE 0 END), 0) AS total_general_donation
        FROM operator_ledger ol
        WHERE " . implode(' AND ', $ledgerWhere);

    $stmt = $pdo->prepare($summarySql);
    $stmt->execute($ledgerParams);
    $summary = array_merge($summary, (array)$stmt->fetch(PDO::FETCH_ASSOC));

    $categorySql = "
        SELECT
            COALESCE(NULLIF(ol.transaction_category, ''), 'uncategorized') AS label,
            COUNT(*) AS item_count,
            COALESCE(SUM(ol.amount), 0) AS total_amount
        FROM operator_ledger ol
        WHERE " . implode(' AND ', $ledgerWhere) . "
        GROUP BY COALESCE(NULLIF(ol.transaction_category, ''), 'uncategorized')
        ORDER BY total_amount DESC, label ASC
        LIMIT 12
    ";
    $stmt = $pdo->prepare($categorySql);
    $stmt->execute($ledgerParams);
    $categoryCards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eventSql = "
        SELECT
            COALESCE(ev.name, 'Event #' + ol.reference_id) AS event_name,
            COUNT(*) AS item_count,
            COALESCE(SUM(ol.amount), 0) AS total_amount
        FROM operator_ledger ol
        LEFT JOIN events ev ON ev.ID = ol.reference_id
        WHERE " . implode(' AND ', $ledgerWhere) . "
          AND (LOWER(COALESCE(ol.transaction_category, '')) = 'event' OR LOWER(COALESCE(ol.transaction_type, '')) LIKE '%event%')
        GROUP BY ol.reference_id, ev.name
        ORDER BY total_amount DESC, event_name ASC
        LIMIT 12
    ";
    $eventSql = str_replace("'Event #' + ol.reference_id", "CONCAT('Event #', COALESCE(ol.reference_id, 0))", $eventSql);
    $stmt = $pdo->prepare($eventSql);
    $stmt->execute($ledgerParams);
    $eventCards = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (tableExists($pdo, 'expense')) {
    $expenseSql = "SELECT COALESCE(SUM(ex.amount), 0) FROM expense ex WHERE " . implode(' AND ', $expenseWhere);
    $stmt = $pdo->prepare($expenseSql);
    $stmt->execute($expenseParams);
    $summary['total_expense'] = (float)$stmt->fetchColumn();
}

if (tableExists($pdo, 'member_monthly_plans')) {
    $planWhere = ['mmp.active = 1'];
    $planParams = [];
    if ($operatorId > 0) {
        $planWhere[] = 'mmp.assigned_operator_id = :plan_operator_id';
        $planParams['plan_operator_id'] = $operatorId;
    }

    $planSql = "SELECT COALESCE(SUM(mmp.amount), 0) FROM member_monthly_plans mmp WHERE " . implode(' AND ', $planWhere);
    $stmt = $pdo->prepare($planSql);
    $stmt->execute($planParams);
    $summary['total_monthly_agreed'] = (float)$stmt->fetchColumn();
}

if (tableExists($pdo, 'loan')) {
    $loanSql = "SELECT COALESCE(SUM(l.amount), 0) FROM loan l WHERE " . implode(' AND ', $loanWhere);
    $stmt = $pdo->prepare($loanSql);
    $stmt->execute($loanParams);
    $summary['total_loan_collected'] = (float)$stmt->fetchColumn();

    $loanRemainingSql = "
        SELECT COALESCE(SUM(GREATEST(COALESCE(l.amount, 0) - COALESCE(rt.returned_amount, 0), 0)), 0)
        FROM loan l
        LEFT JOIN (
            SELECT lid, SUM(amount) AS returned_amount
            FROM loan_trans
            GROUP BY lid
        ) rt ON rt.lid = l.ID
        WHERE " . implode(' AND ', $loanWhere);
    $stmt = $pdo->prepare($loanRemainingSql);
    $stmt->execute($loanParams);
    $summary['selected_operator_loan_remaining'] = (float)$stmt->fetchColumn();

    if ($operatorId > 0) {
        $summary['total_loan_remaining'] = $summary['selected_operator_loan_remaining'];
    } else {
        [$allLoanWhere, $allLoanParams] = atxBuildLoanWhere('l', $dateFrom, $dateTo, 0);
        $allLoanRemainingSql = "
            SELECT COALESCE(SUM(GREATEST(COALESCE(l.amount, 0) - COALESCE(rt.returned_amount, 0), 0)), 0)
            FROM loan l
            LEFT JOIN (
                SELECT lid, SUM(amount) AS returned_amount
                FROM loan_trans
                GROUP BY lid
            ) rt ON rt.lid = l.ID
            WHERE " . implode(' AND ', $allLoanWhere);
        $stmt = $pdo->prepare($allLoanRemainingSql);
        $stmt->execute($allLoanParams);
        $summary['total_loan_remaining'] = (float)$stmt->fetchColumn();
    }
}

if (tableExists($pdo, 'loan_trans')) {
    $loanTransSql = "SELECT COALESCE(SUM(lt.amount), 0) FROM loan_trans lt WHERE " . implode(' AND ', $loanTransWhere);
    $stmt = $pdo->prepare($loanTransSql);
    $stmt->execute($loanTransParams);
    $summary['total_loan_return'] = (float)$stmt->fetchColumn();
}

if (tableExists($pdo, 'transfer_ledger')) {
    $transferOutWhere = $transferWhere;
    $transferInWhere  = $transferWhere;

    $transferOutParams = $transferParams;
    $transferInParams = $transferParams;

    if ($operatorId > 0) {
        $transferOutWhere[] = 'tl.from_user_id = :transfer_from_user';
        $transferInWhere[]  = 'tl.to_user_id = :transfer_to_user';
        $transferOutParams['transfer_from_user'] = $operatorId;
        $transferInParams['transfer_to_user'] = $operatorId;
    } else {
        $transferOutWhere[] = "tl.transfer_type IN ('operator_to_operator', 'operator_to_bank', 'operator_to_office')";
        $transferInWhere[] = "tl.transfer_type IN ('operator_to_operator', 'bank_to_operator', 'office_to_operator')";
    }

    $transferOutSql = "SELECT COALESCE(SUM(tl.amount), 0) FROM transfer_ledger tl WHERE " . implode(' AND ', $transferOutWhere);
    $stmt = $pdo->prepare($transferOutSql);
    $stmt->execute($transferOutParams);
    $summary['transfer_out'] = (float)$stmt->fetchColumn();

    $transferInSql = "SELECT COALESCE(SUM(tl.amount), 0) FROM transfer_ledger tl WHERE " . implode(' AND ', $transferInWhere);
    $stmt = $pdo->prepare($transferInSql);
    $stmt->execute($transferInParams);
    $summary['transfer_in'] = (float)$stmt->fetchColumn();
}

$summary['total_donation'] = (float)$summary['total_donation'];
$summary['total_general_donation'] = (float)$summary['total_general_donation'];
$summary['total_monthly_collected'] = (float)$summary['total_monthly_collected'];
$summary['total_monthly_agreed'] = (float)$summary['total_monthly_agreed'];
$summary['total_event_amount'] = (float)$summary['total_event_amount'];
$summary['total_loan_collected'] = (float)$summary['total_loan_collected'];
$summary['total_loan_return'] = (float)$summary['total_loan_return'];
$summary['operator_cash_in_hand'] = (
    (float)$summary['total_donation']
    + (float)$summary['transfer_in']
    - (float)$summary['total_expense']
    - (float)$summary['transfer_out']
);

$methods = tableExists($pdo, 'operator_ledger')
    ? $pdo->query("SELECT DISTINCT payment_method FROM operator_ledger WHERE payment_method IS NOT NULL AND payment_method <> '' ORDER BY payment_method")->fetchAll(PDO::FETCH_COLUMN)
    : [];

$categories = tableExists($pdo, 'operator_ledger')
    ? $pdo->query("SELECT DISTINCT transaction_category FROM operator_ledger WHERE transaction_category IS NOT NULL AND transaction_category <> '' ORDER BY transaction_category")->fetchAll(PDO::FETCH_COLUMN)
    : [];

$operators = [];
if (tableExists($pdo, 'users') && tableExists($pdo, 'operator_ledger')) {
    $operatorsSql = "
        SELECT DISTINCT u.ID, u.name
        FROM users u
        INNER JOIN operator_ledger ol ON ol.operator_id = u.ID
        ORDER BY u.name ASC
    ";
    $operators = $pdo->query($operatorsSql)->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e($cssFile) ?>?v=<?= @filemtime(__DIR__ . '/' . $cssFile) ?: time() ?>">

<div class="page-head atx-page-head">
    <div>
        <h1 class="title">All Transactions</h1>
        <p class="muted atx-subtitle">Dashboard summary for donations, expenses, loans, monthly dues, events, and operator cash in hand.</p>
    </div>
</div>

<div class="card atx-filter-card">
    <form method="get" class="atx-filters-grid">
        <div class="atx-filter-box">
            <label>Operator</label>
            <select name="operator_id">
                <option value="">All Operators</option>
                <?php foreach ($operators as $op): ?>
                    <option value="<?= (int)$op['ID'] ?>" <?= $operatorId === (int)$op['ID'] ? 'selected' : '' ?>><?= e($op['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="atx-filter-box">
            <label>Payment Method</label>
            <select name="method">
                <option value="">All Methods</option>
                <?php foreach ($methods as $m): ?>
                    <option value="<?= e($m) ?>" <?= $method === $m ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', (string)$m))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="atx-filter-box">
            <label>Category</label>
            <select name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', (string)$c))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="atx-filter-box">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        </div>

        <div class="atx-filter-box">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        </div>

        <div class="atx-filter-box atx-filter-actions">
            <label>&nbsp;</label>
            <div class="atx-filter-buttons">
                <button type="submit" class="btn">Filter</button>
                <a href="all_transactions.php" class="btn btn-light">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="atx-summary-grid">
    <div class="card atx-stat-card">
        <span class="atx-stat-label">Total Donation Collected</span>
        <strong class="atx-stat-value"><?= money($summary['total_donation']) ?></strong>
        <span class="atx-stat-note"><?= e($selectedOperatorName) ?></span>
    </div>

    <div class="card atx-stat-card highlight">
        <span class="atx-stat-label">Cash In Hand</span>
        <strong class="atx-stat-value"><?= money($summary['operator_cash_in_hand']) ?></strong>
        <span class="atx-stat-note">Collected + transfers in − expense − transfers out</span>
    </div>

    <div class="card atx-stat-card">
        <span class="atx-stat-label">Total Expense</span>
        <strong class="atx-stat-value"><?= money($summary['total_expense']) ?></strong>
        <span class="atx-stat-note"><?= e($selectedOperatorName) ?></span>
    </div>

    <div class="card atx-stat-card">
        <span class="atx-stat-label">General Donation</span>
        <strong class="atx-stat-value"><?= money($summary['total_general_donation']) ?></strong>
        <span class="atx-stat-note">One-time / normal / anonymous / life membership</span>
    </div>

    <div class="card atx-stat-card">
        <span class="atx-stat-label">Loan Collected</span>
        <strong class="atx-stat-value"><?= money($summary['total_loan_collected']) ?></strong>
        <span class="atx-stat-note"><?= e($selectedOperatorName) ?></span>
    </div>

    <div class="card atx-stat-card">
        <span class="atx-stat-label">Loan Return</span>
        <strong class="atx-stat-value"><?= money($summary['total_loan_return']) ?></strong>
        <span class="atx-stat-note"><?= e($selectedOperatorName) ?></span>
    </div>

    <div class="card atx-stat-card">
        <span class="atx-stat-label">Loan Remaining</span>
        <strong class="atx-stat-value"><?= money($summary['total_loan_remaining']) ?></strong>
        <span class="atx-stat-note"><?= $operatorId > 0 ? 'Selected operator loan balance' : 'All loans remaining' ?></span>
    </div>

    <div class="card atx-stat-card">
        <span class="atx-stat-label">Monthly Agreed</span>
        <strong class="atx-stat-value"><?= money($summary['total_monthly_agreed']) ?></strong>
        <span class="atx-stat-note"><?= $operatorId > 0 ? 'Assigned to selected operator' : 'All active monthly plans' ?></span>
    </div>

    <div class="card atx-stat-card">
        <span class="atx-stat-label">Monthly Collected</span>
        <strong class="atx-stat-value"><?= money($summary['total_monthly_collected']) ?></strong>
        <span class="atx-stat-note"><?= e($selectedOperatorName) ?></span>
    </div>

    <div class="card atx-stat-card">
        <span class="atx-stat-label">Event Amount</span>
        <strong class="atx-stat-value"><?= money($summary['total_event_amount']) ?></strong>
        <span class="atx-stat-note"><?= $category === 'event' ? 'Event filter active' : 'All event-related collection' ?></span>
    </div>

    <div class="card atx-stat-card mini">
        <span class="atx-stat-label">Transfer Out</span>
        <strong class="atx-stat-value"><?= money($summary['transfer_out']) ?></strong>
        <span class="atx-stat-note">To office / bank / another operator</span>
    </div>

    <div class="card atx-stat-card mini">
        <span class="atx-stat-label">Transfer In</span>
        <strong class="atx-stat-value"><?= money($summary['transfer_in']) ?></strong>
        <span class="atx-stat-note">From bank / office / another operator</span>
    </div>
</div>

<div class="atx-panels-grid">
    <div class="card atx-panel-card">
        <div class="atx-panel-head">
            <h2>Category Summary</h2>
            <span><?= e($selectedOperatorName) ?></span>
        </div>
        <div class="atx-mini-grid">
            <?php if ($categoryCards): ?>
                <?php foreach ($categoryCards as $item): ?>
                    <div class="atx-mini-card">
                        <strong><?= e(ucwords(str_replace('_', ' ', (string)$item['label']))) ?></strong>
                        <span><?= money((float)$item['total_amount']) ?></span>
                        <small><?= (int)$item['item_count'] ?> entries</small>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted">No category summary found.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card atx-panel-card">
        <div class="atx-panel-head">
            <h2>Event Summary</h2>
            <span><?= $category === 'event' ? 'Filtered event view' : 'All event-related collection' ?></span>
        </div>
        <div class="atx-mini-grid">
            <?php if ($eventCards): ?>
                <?php foreach ($eventCards as $item): ?>
                    <div class="atx-mini-card event-card">
                        <strong><?= e((string)$item['event_name']) ?></strong>
                        <span><?= money((float)$item['total_amount']) ?></span>
                        <small><?= (int)$item['item_count'] ?> entries</small>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted">No event transactions found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card atx-table-card">
    <div class="atx-panel-head table-head">
        <h2>Transactions</h2>
        <span><?= count($rows) ?> showing</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Operator</th>
                    <th>Donor</th>
                    <th>Type</th>
                    <th>Category</th>
                    <th>Reference</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Receipt</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e(atxFormatDate((string)$row['date'])) ?></td>
                            <td><?= e((string)$row['invoice_no']) ?></td>
                            <td><?= e((string)($row['operator_name'] ?? '—')) ?></td>
                            <td><?= e(resolveLedgerDonorName($pdo, $row)) ?></td>
                            <td><span class="tag orange"><?= e(ucwords(str_replace('_', ' ', (string)$row['transaction_type']))) ?></span></td>
                            <td><span class="tag soft"><?= e(ucwords(str_replace('_', ' ', (string)$row['category']))) ?></span></td>
                            <td><?= e((string)$row['reference_name']) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', (string)$row['method']))) ?></td>
                            <td><strong><?= money((float)$row['amount']) ?></strong></td>
                            <td>
                                <a class="btn btn-small" target="_blank" href="receipt_print.php?id=<?= (int)$row['ID'] ?>">Receipt</a>
                            </td>
                            <td>
                                <form method="post" action="transaction_delete_direct.php" onsubmit="return confirm('Are you sure you want to delete this transaction?');">
                                    <input type="hidden" name="ledger_id" value="<?= (int)$row['ID'] ?>">
                                    <button type="submit" class="btn danger btn-small">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="muted">No transactions found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
