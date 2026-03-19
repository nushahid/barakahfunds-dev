<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$role = currentRole($pdo);
$uid = getLoggedInUserId();
$isFinanceDashboard = in_array($role, ['admin', 'accountant'], true);

function dashboardScalar(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return ($value !== false && $value !== null) ? $value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function dashboardFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function dashboardPaymentMethodLabel(?string $method): string
{
    $key = strtolower(trim((string)$method));

    return match ($key) {
        'cash', 'cash_manual', 'manual_cash' => 'Cash',
        'bank', 'bank_manual', 'manual_bank', 'bank_transfer' => 'Bank',
        'stripe', 'stripe_auto' => 'Stripe',
        'online' => 'Online',
        'pos' => 'POS',
        '' => 'Unknown',
        default => ucwords(str_replace('_', ' ', $key)),
    };
}

function dashboardCard(array $card): array
{
    return $card + [
        'label' => '',
        'value' => null,
        'note' => '',
        'badge' => '',
        'badge_class' => 'summary',
        'group_class' => 'group-default',
        'value_type' => 'money',
    ];
}

function dashboardRenderCards(array $cards): void
{
    ?>
    <div class="dashboard-cards">
        <?php foreach ($cards as $card): ?>
            <?php
            $card = dashboardCard($card);
            $label = (string)$card['label'];
            $value = $card['value'];
            $note = (string)$card['note'];
            $badge = (string)$card['badge'];
            $badgeClass = (string)$card['badge_class'];
            $groupClass = (string)$card['group_class'];
            $valueType = (string)$card['value_type'];

            if ($label === '' || $value === null || $value === '') {
                continue;
            }

            $displayValue = $valueType === 'count'
                ? (string)((int)$value)
                : money((float)$value);
            ?>
            <div class="card stat-card dashboard-card <?= e($groupClass) ?>">
                <div class="dashboard-card-top">
                    <div class="dashboard-card-label"><?= e($label) ?></div>
                    <?php if ($badge !== ''): ?>
                        <span class="dashboard-card-badge dashboard-card-badge-<?= e($badgeClass) ?>"><?= e($badge) ?></span>
                    <?php endif; ?>
                </div>
                <div class="summary dashboard-card-value"><?= e($displayValue) ?></div>
                <?php if ($note !== ''): ?>
                    <div class="dashboard-card-note"><?= e($note) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function dashboardRenderMiniCards(array $cards): void
{
    ?>
    <div class="dashboard-mini-cards">
        <?php foreach ($cards as $card): ?>
            <?php
            $card = dashboardCard($card);
            $label = (string)$card['label'];
            $value = $card['value'];
            $note = (string)$card['note'];
            $badge = (string)$card['badge'];
            $badgeClass = (string)$card['badge_class'];
            $valueType = (string)$card['value_type'];

            if ($label === '' || $value === null || $value === '') {
                continue;
            }

            $displayValue = $valueType === 'count'
                ? (string)((int)$value)
                : money((float)$value);
            ?>
            <div class="dashboard-mini-card">
                <div class="dashboard-mini-top">
                    <div class="dashboard-mini-label"><?= e($label) ?></div>
                    <?php if ($badge !== ''): ?>
                        <span class="dashboard-card-badge dashboard-card-badge-<?= e($badgeClass) ?> dashboard-mini-badge"><?= e($badge) ?></span>
                    <?php endif; ?>
                </div>
                <div class="dashboard-mini-value"><?= e($displayValue) ?></div>
                <?php if ($note !== ''): ?>
                    <div class="dashboard-mini-note"><?= e($note) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

/*
|--------------------------------------------------------------------------
| Main cards
|--------------------------------------------------------------------------
*/

$monthlyCards = [
    dashboardCard([
        'label' => 'Month Dues Expected',
        'value' => getMonthlyAgreed($pdo),
        'note' => 'Expected monthly dues for ' . currentMonthLabel(),
        'badge' => 'Expected',
        'badge_class' => 'expected',
        'group_class' => 'group-monthly',
    ]),
    dashboardCard([
        'label' => 'Month Dues Received',
        'value' => getMonthlyCollected($pdo),
        'note' => 'Monthly dues actually received in current month',
        'badge' => 'Received',
        'badge_class' => 'received',
        'group_class' => 'group-monthly',
    ]),
    dashboardCard([
        'label' => 'Month Dues Pending',
        'value' => getPendingMonthly($pdo),
        'note' => 'Still pending from monthly dues',
        'badge' => 'Pending',
        'badge_class' => 'pending',
        'group_class' => 'group-monthly',
    ]),
    dashboardCard([
        'label' => 'Month Total Received',
        'value' => getMonthlyTotalCollected($pdo),
        'note' => 'All posted month income, not only monthly dues',
        'badge' => 'Summary',
        'badge_class' => 'summary',
        'group_class' => 'group-monthly',
    ]),
    dashboardCard([
        'label' => 'Month Expenses',
        'value' => getMonthlyExpense($pdo),
        'note' => 'All expenses posted in current month',
        'badge' => 'Expense',
        'badge_class' => 'expense',
        'group_class' => 'group-monthly',
    ]),
    dashboardCard([
        'label' => 'Prev. Month Stripe Posted',
        'value' => getPreviousMonthStripeAmount($pdo),
        'note' => 'Online dues are reconciled in the following month',
        'badge' => 'Online',
        'badge_class' => 'summary',
        'group_class' => 'group-monthly',
    ]),
];

$cashCards = [];
$activityCards = [];
$controlCards = [];
$loanCards = [];
$eventCards = [];

/*
|--------------------------------------------------------------------------
| Operator cards
|--------------------------------------------------------------------------
*/
if ($role === 'operator') {
    $myCollectionsThisMonth = (float)dashboardScalar(
        $pdo,
        "SELECT COALESCE(SUM(amount),0)
         FROM operator_ledger
         WHERE operator_id = ?
           AND COALESCE(is_removed,0) = 0
           AND created_at >= ?
           AND created_at < ?
           AND LOWER(COALESCE(transaction_type,'')) IN ('credit','collection')",
        [$uid, currentMonthStart(), nextMonthStart()],
        0
    );

    $myExpensesThisMonth = (float)dashboardScalar(
        $pdo,
        "SELECT COALESCE(SUM(amount),0)
         FROM expense
         WHERE uid = ?
           AND DATE(COALESCE(expense_date, created_at)) >= ?
           AND DATE(COALESCE(expense_date, created_at)) < ?",
        [$uid, date('Y-m-d', strtotime(currentMonthStart())), date('Y-m-d', strtotime(nextMonthStart()))],
        0
    );

    $myAnonymousThisMonth = (float)dashboardScalar(
        $pdo,
        "SELECT COALESCE(SUM(amount),0)
         FROM anonymous_collections
         WHERE created_by = ?
           AND created_at >= ?
           AND created_at < ?",
        [$uid, currentMonthStart(), nextMonthStart()],
        0
    );

    $myPendingDeleteRequests = (int)dashboardScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM transaction_delete_requests tdr
         INNER JOIN operator_ledger ol ON ol.ID = tdr.ledger_id
         WHERE tdr.status = 'pending'
           AND ol.operator_id = ?",
        [$uid],
        0
    );

    $cashCards = [
        dashboardCard([
            'label' => 'My Cash in Hand',
            'value' => operatorBalance($pdo, $uid),
            'note' => 'Current working balance',
            'badge' => 'Cash',
            'badge_class' => 'received',
            'group_class' => 'group-cash',
        ]),
        dashboardCard([
            'label' => 'My Pending Transfers',
            'value' => operatorPendingTransfers($pdo, $uid),
            'note' => 'Waiting for receiver approval',
            'badge' => 'Pending',
            'badge_class' => 'pending',
            'group_class' => 'group-cash',
        ]),
    ];

    $activityCards = [
        dashboardCard([
            'label' => 'My Collections This Month',
            'value' => $myCollectionsThisMonth,
            'note' => 'Your own collected entries this month',
            'badge' => 'Work',
            'badge_class' => 'summary',
            'group_class' => 'group-activity',
        ]),
        dashboardCard([
            'label' => 'My Expenses This Month',
            'value' => $myExpensesThisMonth,
            'note' => 'Expenses saved by you this month',
            'badge' => 'Expense',
            'badge_class' => 'expense',
            'group_class' => 'group-activity',
        ]),
        dashboardCard([
            'label' => 'My Anonymous Collections',
            'value' => $myAnonymousThisMonth,
            'note' => 'Anonymous / box / Jumma / misc entries by you',
            'badge' => 'Anon',
            'badge_class' => 'summary',
            'group_class' => 'group-activity',
        ]),
        dashboardCard([
            'label' => 'My Delete Requests',
            'value' => $myPendingDeleteRequests,
            'note' => 'Pending delete requests awaiting review',
            'badge' => 'Pending',
            'badge_class' => 'pending',
            'group_class' => 'group-activity',
            'value_type' => 'count',
        ]),
    ];
}

/*
|--------------------------------------------------------------------------
| Admin / accountant cards
|--------------------------------------------------------------------------
*/
if ($isFinanceDashboard) {
    $failedStripePlans = 0;
    if (tableExists($pdo, 'member_monthly_dues')) {
        $failedStripePlans = (int)dashboardScalar(
            $pdo,
            "SELECT COUNT(*)
             FROM member_monthly_dues
             WHERE status = 'failed'
               AND payment_source = 'stripe'
               AND due_month = ?",
            [date('Y-m-01', strtotime('-1 month'))],
            0
        );
    }

    $pendingTransferRequests = 0;
    if (tableExists($pdo, 'balance_transfers')) {
        $pendingTransferRequests = (int)dashboardScalar(
            $pdo,
            "SELECT COUNT(*) FROM balance_transfers WHERE status = 'pending'",
            [],
            0
        );
    }

    $pendingDeleteRequests = 0;
    if (tableExists($pdo, 'transaction_delete_requests')) {
        $pendingDeleteRequests = (int)dashboardScalar(
            $pdo,
            "SELECT COUNT(*) FROM transaction_delete_requests WHERE status = 'pending'",
            [],
            0
        );
    }

    $activeDonationBoxes = 0;
    if (tableExists($pdo, 'donation_boxes')) {
        $activeDonationBoxes = (int)dashboardScalar(
            $pdo,
            "SELECT COUNT(*) FROM donation_boxes WHERE active = 1",
            [],
            0
        );
    }

    $anonymousThisMonth = 0.0;
    if (tableExists($pdo, 'anonymous_collections')) {
        $anonymousThisMonth = (float)dashboardScalar(
            $pdo,
            "SELECT COALESCE(SUM(amount),0)
             FROM anonymous_collections
             WHERE created_at >= ?
               AND created_at < ?",
            [currentMonthStart(), nextMonthStart()],
            0
        );
    }

    $activeEventsCount = 0;
    if (tableExists($pdo, 'events')) {
        $activeEventsCount = (int)dashboardScalar(
            $pdo,
            "SELECT COUNT(*)
             FROM events
             WHERE COALESCE(status,1) = 1",
            [],
            0
        );
    }

    $cashCards = [
        dashboardCard([
            'label' => 'Operators Cash in Hand',
            'value' => getTotalCashInAllOperators($pdo),
            'note' => 'Combined balances of all operators',
            'badge' => 'Cash',
            'badge_class' => 'received',
            'group_class' => 'group-cash',
        ]),
        dashboardCard([
            'label' => 'Bank Balance',
            'value' => getAccountantBankBalance($pdo),
            'note' => 'Current bank / accountant position',
            'badge' => 'Bank',
            'badge_class' => 'summary',
            'group_class' => 'group-cash',
        ]),
        dashboardCard([
            'label' => 'All-Time Donations',
            'value' => getTotalCollectionTillNow($pdo),
            'note' => 'All donation / collection data till now',
            'badge' => 'Total',
            'badge_class' => 'summary',
            'group_class' => 'group-cash',
        ]),
        dashboardCard([
            'label' => 'All-Time Expenses',
            'value' => getTotalExpenseTillNow($pdo),
            'note' => 'All expense data till now',
            'badge' => 'Expense',
            'badge_class' => 'expense',
            'group_class' => 'group-cash',
        ]),
    ];

    $controlCards = [
        dashboardCard([
            'label' => 'Pending Transfers',
            'value' => $pendingTransferRequests,
            'note' => 'Transfer requests waiting for action',
            'badge' => 'Pending',
            'badge_class' => 'pending',
            'value_type' => 'count',
        ]),
        dashboardCard([
            'label' => 'Pending Delete Requests',
            'value' => $pendingDeleteRequests,
            'note' => 'Transactions waiting for delete review',
            'badge' => 'Review',
            'badge_class' => 'pending',
            'value_type' => 'count',
        ]),
        dashboardCard([
            'label' => 'Failed Stripe Plans',
            'value' => $failedStripePlans,
            'note' => 'Failed Stripe dues from ' . previousMonthLabel(),
            'badge' => 'Alert',
            'badge_class' => 'expense',
            'value_type' => 'count',
        ]),
        dashboardCard([
            'label' => 'Active Donation Boxes',
            'value' => $activeDonationBoxes,
            'note' => 'Donation boxes currently active',
            'badge' => 'Boxes',
            'badge_class' => 'summary',
            'value_type' => 'count',
        ]),
        dashboardCard([
            'label' => 'Anonymous This Month',
            'value' => $anonymousThisMonth,
            'note' => 'Anonymous, box, Jumma and misc collection',
            'badge' => 'Anon',
            'badge_class' => 'received',
        ]),
        dashboardCard([
            'label' => 'Active Events',
            'value' => $activeEventsCount,
            'note' => 'Currently active fundraising events',
            'badge' => 'Events',
            'badge_class' => 'summary',
            'value_type' => 'count',
        ]),
    ];

    if (tableExists($pdo, 'loan')) {
        $loanStats = dashboardFetchAll(
            $pdo,
            "SELECT
                COUNT(*) AS total_loans,
                COALESCE(SUM(l.amount),0) AS total_loan_amount,
                COALESCE(SUM(COALESCE(rt.returned_amount,0)),0) AS returned_amount,
                COALESCE(SUM(GREATEST(COALESCE(l.amount,0) - COALESCE(rt.returned_amount,0), 0)),0) AS remaining_amount,
                COALESCE(SUM(CASE
                    WHEN l.return_date IS NOT NULL
                     AND l.return_date < CURDATE()
                     AND GREATEST(COALESCE(l.amount,0) - COALESCE(rt.returned_amount,0), 0) > 0
                    THEN 1 ELSE 0 END),0) AS overdue_count
             FROM loan l
             LEFT JOIN (
                SELECT lid, SUM(amount) AS returned_amount
                FROM loan_trans
                GROUP BY lid
             ) rt ON rt.lid = l.ID"
        );
        $loanRow = $loanStats[0] ?? [];

        $loanCards = [
            dashboardCard([
                'label' => 'Active Loans',
                'value' => (int)($loanRow['total_loans'] ?? 0),
                'note' => 'Loan records currently in system',
                'badge' => 'Loans',
                'badge_class' => 'summary',
                'value_type' => 'count',
            ]),
            dashboardCard([
                'label' => 'Loan Amount',
                'value' => (float)($loanRow['total_loan_amount'] ?? 0),
                'note' => 'Original amount given in loans',
                'badge' => 'Issued',
                'badge_class' => 'summary',
            ]),
            dashboardCard([
                'label' => 'Returned So Far',
                'value' => (float)($loanRow['returned_amount'] ?? 0),
                'note' => 'Loan returns received so far',
                'badge' => 'Returned',
                'badge_class' => 'received',
            ]),
            dashboardCard([
                'label' => 'Outstanding Loans',
                'value' => (float)($loanRow['remaining_amount'] ?? 0),
                'note' => 'Remaining unpaid loan balance',
                'badge' => 'Open',
                'badge_class' => 'pending',
            ]),
            dashboardCard([
                'label' => 'Overdue Loans',
                'value' => (int)($loanRow['overdue_count'] ?? 0),
                'note' => 'Past return date and still unpaid',
                'badge' => 'Alert',
                'badge_class' => 'expense',
                'value_type' => 'count',
            ]),
        ];
    }

    if (tableExists($pdo, 'events')) {
        $eventStats = dashboardFetchAll(
            $pdo,
            "SELECT
                COALESCE(SUM(COALESCE(e.estimated,0)),0) AS target_total,
                COALESCE(SUM((
                    SELECT COALESCE(SUM(ol.amount),0)
                    FROM operator_ledger ol
                    WHERE ol.reference_id = e.ID
                      AND COALESCE(ol.is_removed,0) = 0
                      AND LOWER(COALESCE(ol.transaction_category,'')) = 'event'
                )),0) AS collected_total
             FROM events e"
        );
        $eventRow = $eventStats[0] ?? [];
        $targetTotal = (float)($eventRow['target_total'] ?? 0);
        $collectedTotal = (float)($eventRow['collected_total'] ?? 0);
        $remainingTarget = max($targetTotal - $collectedTotal, 0);

        $eventCards = [
            dashboardCard([
                'label' => 'Event Target Total',
                'value' => $targetTotal,
                'note' => 'Combined fundraising target of events',
                'badge' => 'Target',
                'badge_class' => 'expected',
            ]),
            dashboardCard([
                'label' => 'Event Collected Total',
                'value' => $collectedTotal,
                'note' => 'Collected against event entries',
                'badge' => 'Collected',
                'badge_class' => 'received',
            ]),
            dashboardCard([
                'label' => 'Event Remaining Target',
                'value' => $remainingTarget,
                'note' => 'Still needed to reach targets',
                'badge' => 'Open',
                'badge_class' => 'pending',
            ]),
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Chart + method + recent activity
|--------------------------------------------------------------------------
*/
$dailySeries = getDailyCollectionSeries($pdo);
$rawMethodTotals = getCollectionByMethod($pdo, currentMonthStart(), nextMonthStart());
$methodTotals = [];

foreach ($rawMethodTotals as $method => $total) {
    $label = dashboardPaymentMethodLabel((string)$method);
    if (!isset($methodTotals[$label])) {
        $methodTotals[$label] = 0.0;
    }
    $methodTotals[$label] += (float)$total;
}

$previousStripe = getPreviousMonthStripeAmount($pdo);

$recentRows = [];
if (tableExists($pdo, 'operator_ledger')) {
    $sql = "SELECT
                ol.ID,
                ol.created_at,
                ol.transaction_category,
                ol.transaction_type,
                ol.amount,
                ol.payment_method,
                p.name AS person_name,
                u.name AS operator_name
            FROM operator_ledger ol
            LEFT JOIN people p ON p.ID = ol.person_id
            LEFT JOIN users u ON u.ID = ol.operator_id
            WHERE COALESCE(ol.is_removed,0) = 0";

    if ($role === 'operator') {
        $sql .= ' AND ol.operator_id = ' . (int)$uid;
    }

    $sql .= ' ORDER BY ol.ID DESC LIMIT 12';
    $recentRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$events = function_exists('activeEventsSummary') ? activeEventsSummary($pdo) : [];

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Dashboard</h1>
<div class="muted dashboard-intro">
    Current month overview for <?= e(currentMonthLabel()) ?>.
    Stripe reconciliation is posted in the following month, so the Stripe card uses <strong><?= e(previousMonthLabel()) ?></strong>.
</div>

<div class="dashboard-card-sections">

    <section class="dashboard-card-group">
        <div class="toolbar dashboard-toolbar">
            <div>
                <h2 class="dashboard-section-title">Monthly Overview</h2>
                <div class="muted dashboard-section-helper">Expected, received, pending, total and expense view for the current month.</div>
            </div>
            <span class="tag blue"><?= e(currentMonthLabel()) ?></span>
        </div>
        <?php dashboardRenderCards($monthlyCards); ?>
    </section>

    <section class="dashboard-card-group">
        <div class="toolbar dashboard-toolbar">
            <div>
                <h2 class="dashboard-section-title">Cash Position</h2>
                <div class="muted dashboard-section-helper">
                    <?= $role === 'operator'
                        ? 'Your current balance and pending transfer position.'
                        : 'Cash, bank and all-time finance position.' ?>
                </div>
            </div>
        </div>
        <?php dashboardRenderCards($cashCards); ?>
    </section>

    <?php if ($role === 'operator'): ?>
        <section class="dashboard-card-group">
            <div class="toolbar dashboard-toolbar">
                <div>
                    <h2 class="dashboard-section-title">My Activity</h2>
                    <div class="muted dashboard-section-helper">What you have handled in the current month.</div>
                </div>
            </div>
            <?php dashboardRenderCards($activityCards); ?>
        </section>
    <?php endif; ?>

    <?php if ($isFinanceDashboard && $controlCards): ?>
        <section class="dashboard-card-group">
            <div class="toolbar dashboard-toolbar">
                <div>
                    <h2 class="dashboard-section-title">Finance Control</h2>
                    <div class="muted dashboard-section-helper">Items that need review, action or monitoring.</div>
                </div>
            </div>
            <?php dashboardRenderMiniCards($controlCards); ?>
        </section>
    <?php endif; ?>

    <?php if ($isFinanceDashboard && $loanCards): ?>
        <section class="dashboard-card-group">
            <div class="toolbar dashboard-toolbar">
                <div>
                    <h2 class="dashboard-section-title">Loans Summary</h2>
                    <div class="muted dashboard-section-helper">Loan position and overdue follow-up.</div>
                </div>
                <a class="btn" href="loan_page.php">Open Loans</a>
            </div>
            <?php dashboardRenderMiniCards($loanCards); ?>
        </section>
    <?php endif; ?>

    <?php if ($isFinanceDashboard && $eventCards): ?>
        <section class="dashboard-card-group">
            <div class="toolbar dashboard-toolbar">
                <div>
                    <h2 class="dashboard-section-title">Events Summary</h2>
                    <div class="muted dashboard-section-helper">Event target, collected amount and remaining target.</div>
                </div>
                <a class="btn" href="event_page.php">View Events</a>
            </div>
            <?php dashboardRenderMiniCards($eventCards); ?>
        </section>
    <?php endif; ?>

</div>

<div class="grid-2 ledger-grid dashboard-ledger-grid">
    <div class="card">
        <div class="toolbar dashboard-toolbar">
            <h2 class="dashboard-section-title">Monthly Collected Amount</h2>
            <span class="tag blue"><?= e(currentMonthLabel()) ?></span>
        </div>
        <div class="chart-shell">
            <?php
            $dailyValues = array_values($dailySeries);
            $max = $dailyValues ? max(1, ...$dailyValues) : 1;
            foreach ($dailySeries as $date => $amount):
                $height = max(8, (int)round(((float)$amount / $max) * 180));
            ?>
                <div class="chart-bar-wrap">
                    <div class="chart-value"><?= (int)round((float)$amount) ?></div>
                    <div class="chart-bar" style="height: <?= (int)$height ?>px"></div>
                    <div class="chart-label"><?= e(date('d', strtotime((string)$date))) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$dailySeries): ?>
                <div class="muted">No daily collection data found for this month.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="toolbar dashboard-toolbar">
                <h2 class="dashboard-section-title">Collection by Payment Method</h2>
            </div>
            <div class="stack compact">
                <?php foreach ($methodTotals as $methodLabel => $total): ?>
                    <div class="meter-row dashboard-method-row">
                        <div class="dashboard-method-label">
                            <strong><?= e($methodLabel) ?></strong>
                        </div>
                        <div class="dashboard-method-value"><?= money((float)$total) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$methodTotals): ?>
                    <div class="muted">No payment method totals found.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="toolbar dashboard-toolbar">
                <h2 class="dashboard-section-title">Previous Month Online Report</h2>
                <span class="tag orange"><?= e(previousMonthLabel()) ?></span>
            </div>
            <div class="summary dashboard-online-value"><?= money((float)$previousStripe) ?></div>
            <div class="muted">Accountant posts successful Stripe monthly payments from the previous month and leaves failed ones suspended.</div>
        </div>
    </div>
</div>

<div class="grid-2 dashboard-bottom-grid">
    <div class="card dashboard-recent-activity dashboard-recent-activity-v5">
        <div class="toolbar dashboard-toolbar">
            <h2 class="dashboard-section-title">Recent Activity</h2>
            <?php if ($role !== 'operator'): ?>
                <a class="btn" href="accounts_report.php">Open Accounts Report</a>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Person</th>
                        <th>Operator</th>
                        <th>Method</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentRows as $row): ?>
                    <tr>
                        <td><?= e((string)$row['created_at']) ?></td>
                        <td>
                            <span class="tag orange">
                                <?= e(ucwords(str_replace('_', ' ', (string)($row['transaction_category'] ?? $row['transaction_type'] ?? '')))) ?>
                            </span>
                        </td>
                        <td><?= e((string)($row['person_name'] ?: '—')) ?></td>
                        <td><?= e((string)($row['operator_name'] ?: '—')) ?></td>
                        <td><?= e(dashboardPaymentMethodLabel((string)($row['payment_method'] ?? ''))) ?></td>
                        <td><?= money((float)$row['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentRows): ?>
                    <tr><td colspan="6" class="muted">No activity available yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card dashboard-events-card">
        <div class="toolbar dashboard-toolbar-wrap">
            <h2 class="dashboard-section-title">Active Events</h2>
            <?php if ($isFinanceDashboard): ?>
                <a class="btn" href="event_page.php">View Events</a>
            <?php endif; ?>
        </div>
        <div class="stack compact dashboard-events-list dashboard-events-list-v5">
            <?php foreach ($events as $event): ?>
                <div class="meter-row dashboard-event-row">
                    <div>
                        <strong><?= e((string)$event['name']) ?></strong><br>
                        <span class="muted">Collected <?= money((float)$event['collected']) ?> / Target <?= money((float)$event['estimate']) ?></span>
                    </div>
                    <div>
                        <span class="tag <?= (float)$event['remaining'] <= 0 ? 'green' : 'blue' ?>">
                            <?= money((float)$event['remaining']) ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$events): ?>
                <div class="muted">No active events found.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>