<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

requireRole($pdo, ['accountant', 'admin']);

$statusFilter = trim((string)($_GET['status'] ?? 'pending'));
$methodFilter = trim((string)($_GET['method'] ?? 'all'));
$monthFilter  = trim((string)($_GET['month'] ?? date('Y-m')));

$allowedStatuses = ['pending', 'accepted', 'rejected', 'all'];
$allowedMethods  = ['all', 'bank', 'pos', 'stripe', 'online'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}
if (!in_array($methodFilter, $allowedMethods, true)) {
    $methodFilter = 'all';
}
if (!preg_match('/^\d{4}-\d{2}$/', $monthFilter)) {
    $monthFilter = date('Y-m');
}

if (!tableExists($pdo, 'transaction_approval_requests')) {
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <h1 class="title">Transaction Approval Requests</h1>
        <p class="muted">Table <strong>transaction_approval_requests</strong> was not found.</p>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

if (!tableExists($pdo, 'operator_ledger')) {
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <h1 class="title">Transaction Approval Requests</h1>
        <p class="muted">Table <strong>operator_ledger</strong> was not found.</p>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$hasOlCreatedAt            = columnExists($pdo, 'operator_ledger', 'created_at');
$hasOlInvoiceNo            = columnExists($pdo, 'operator_ledger', 'invoice_no');
$hasOlAmount               = columnExists($pdo, 'operator_ledger', 'amount');
$hasOlTransactionCategory  = columnExists($pdo, 'operator_ledger', 'transaction_category');
$hasOlPaymentMethod        = columnExists($pdo, 'operator_ledger', 'payment_method');
$hasOlNotes                = columnExists($pdo, 'operator_ledger', 'notes');
$hasOlIsRemoved            = columnExists($pdo, 'operator_ledger', 'is_removed');
$hasOlRemovedAt            = columnExists($pdo, 'operator_ledger', 'removed_at');
$hasOlRemovedReason        = columnExists($pdo, 'operator_ledger', 'removed_reason');
$hasOlSettlementStatus     = columnExists($pdo, 'operator_ledger', 'settlement_status');
$hasOlPersonId             = columnExists($pdo, 'operator_ledger', 'person_id');
$hasOlOperatorId           = columnExists($pdo, 'operator_ledger', 'operator_id');

$select = [
    'tar.ID',
    'tar.ledger_id',
    'tar.requested_by',
    'tar.payment_method AS request_payment_method',
    'tar.requested_at',
    'tar.status',
    'tar.accountant_id',
    'tar.accountant_reason',
    'tar.reviewed_at',
    $hasOlCreatedAt           ? 'ol.created_at AS transaction_date' : 'NULL AS transaction_date',
    $hasOlInvoiceNo           ? 'ol.invoice_no' : 'NULL AS invoice_no',
    $hasOlAmount              ? 'ol.amount' : '0 AS amount',
    $hasOlTransactionCategory ? 'ol.transaction_category' : 'NULL AS transaction_category',
    $hasOlPaymentMethod       ? 'ol.payment_method' : 'tar.payment_method AS payment_method',
    $hasOlNotes               ? 'ol.notes' : 'NULL AS notes',
    $hasOlIsRemoved           ? 'ol.is_removed' : '0 AS is_removed',
    $hasOlRemovedAt           ? 'ol.removed_at' : 'NULL AS removed_at',
    $hasOlRemovedReason       ? 'ol.removed_reason' : 'NULL AS removed_reason',
    $hasOlSettlementStatus    ? 'ol.settlement_status' : 'NULL AS settlement_status',
    $hasOlPersonId            ? 'donor.name AS donor_name' : 'NULL AS donor_name',
    'requester.name AS requested_by_name',
    'reviewer.name AS accountant_name',
    $hasOlOperatorId          ? 'operator_user.name AS operator_user_name' : 'NULL AS operator_user_name',
];

$sql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM transaction_approval_requests tar
    INNER JOIN operator_ledger ol ON ol.ID = tar.ledger_id
";

if ($hasOlPersonId) {
    $sql .= "\nLEFT JOIN people donor ON donor.ID = ol.person_id";
}
$sql .= "\nLEFT JOIN users requester ON requester.ID = tar.requested_by";
$sql .= "\nLEFT JOIN users reviewer ON reviewer.ID = tar.accountant_id";
if ($hasOlOperatorId) {
    $sql .= "\nLEFT JOIN users operator_user ON operator_user.ID = ol.operator_id";
}

$params = [];
$where = [];

if ($statusFilter !== 'all') {
    $where[] = 'tar.status = :status';
    $params['status'] = $statusFilter;
}
if ($methodFilter !== 'all') {
    $where[] = 'tar.payment_method = :payment_method';
    $params['payment_method'] = $methodFilter;
}
if ($hasOlCreatedAt) {
    $where[] = 'DATE_FORMAT(ol.created_at, "%Y-%m") = :month_filter';
    $params['month_filter'] = $monthFilter;
}

if ($where) {
    $sql .= "\nWHERE " . implode(' AND ', $where);
}

$sql .= "\nORDER BY
            CASE WHEN tar.status = 'pending' THEN 0 ELSE 1 END,
            " . ($hasOlCreatedAt ? "ol.created_at DESC," : "") . "
            tar.ID DESC
          LIMIT 300";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stats = [
    'pending'  => 0,
    'accepted' => 0,
    'rejected' => 0,
    'all'      => 0,
];

$countSql = "
    SELECT tar.status, COUNT(*) AS total
    FROM transaction_approval_requests tar
    INNER JOIN operator_ledger ol ON ol.ID = tar.ledger_id
";
$countWhere = [];
$countParams = [];

if ($hasOlCreatedAt) {
    $countWhere[] = "DATE_FORMAT(ol.created_at, '%Y-%m') = ?";
    $countParams[] = $monthFilter;
}
if ($methodFilter !== 'all') {
    $countWhere[] = "tar.payment_method = ?";
    $countParams[] = $methodFilter;
}
if ($countWhere) {
    $countSql .= " WHERE " . implode(' AND ', $countWhere);
}
$countSql .= " GROUP BY tar.status";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
foreach ($countStmt->fetchAll() as $r) {
    $st = (string)$r['status'];
    $total = (int)$r['total'];
    if (isset($stats[$st])) {
        $stats[$st] = $total;
    }
    $stats['all'] += $total;
}

$success = trim((string)($_GET['success'] ?? ''));
$error   = trim((string)($_GET['error'] ?? ''));

require_once __DIR__ . '/includes/header.php';
?>

<div class="delete-requests-page">
    <div class="page-head">
        <div>
            <h1 class="title">Transaction Approval Requests</h1>
            <p class="page-subtitle">Cash stays immediate. Bank, POS, Stripe, and Online transactions wait here for accountant approval.</p>
        </div>
        <div class="page-actions" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <a class="btn soft" href="transaction_approval_requests.php?status=pending&method=all&month=<?= urlencode($monthFilter) ?>">All Pending</a>
            <a class="btn soft" href="transaction_approval_requests.php?status=pending&method=bank&month=<?= urlencode($monthFilter) ?>">Bank</a>
            <a class="btn soft" href="transaction_approval_requests.php?status=pending&method=online&month=<?= urlencode($monthFilter) ?>">Online</a>
            <a class="btn soft" href="transaction_approval_requests.php?status=pending&method=pos&month=<?= urlencode($monthFilter) ?>">POS</a>
            <a class="btn soft" href="transaction_approval_requests.php?status=pending&method=stripe&month=<?= urlencode($monthFilter) ?>">Stripe</a>
            <a class="btn soft" href="transaction_delete_requests.php?status=pending">Delete Requests</a>
            <a class="btn soft" href="stripe_reconciliation.php?month=<?= urlencode($monthFilter) ?>">Stripe Reconciliation</a>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert success">
            <?php if ($success === 'done'): ?>
                Request updated successfully.
            <?php else: ?>
                <?= e($success) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="get" class="card filter-toolbar">
        <div class="filter-toolbar-grid">
            <div>
                <label>Status</label>
                <select name="status">
                    <?php foreach ($allowedStatuses as $statusOption): ?>
                        <option value="<?= e($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                            <?= e(ucfirst($statusOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Payment Method</label>
                <select name="method">
                    <?php foreach ($allowedMethods as $methodOption): ?>
                        <option value="<?= e($methodOption) ?>" <?= $methodFilter === $methodOption ? 'selected' : '' ?>>
                            <?= e($methodOption === 'all' ? 'All' : ucwords($methodOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Month</label>
                <input type="month" name="month" value="<?= e($monthFilter) ?>">
            </div>
            <div class="filter-toolbar-actions">
                <button class="btn soft" type="submit">Apply Filter</button>
            </div>
        </div>
    </form>

    <div class="stats-grid">
        <a class="stat-card <?= $statusFilter === 'all' ? 'active' : '' ?>" href="transaction_approval_requests.php?status=all&method=<?= urlencode($methodFilter) ?>&month=<?= urlencode($monthFilter) ?>">
            <div class="stat-label">All Requests</div>
            <div class="stat-value"><?= (int)$stats['all'] ?></div>
        </a>
        <a class="stat-card pending <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="transaction_approval_requests.php?status=pending&method=<?= urlencode($methodFilter) ?>&month=<?= urlencode($monthFilter) ?>">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?= (int)$stats['pending'] ?></div>
        </a>
        <a class="stat-card accepted <?= $statusFilter === 'accepted' ? 'active' : '' ?>" href="transaction_approval_requests.php?status=accepted&method=<?= urlencode($methodFilter) ?>&month=<?= urlencode($monthFilter) ?>">
            <div class="stat-label">Accepted</div>
            <div class="stat-value"><?= (int)$stats['accepted'] ?></div>
        </a>
        <a class="stat-card rejected <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="transaction_approval_requests.php?status=rejected&method=<?= urlencode($methodFilter) ?>&month=<?= urlencode($monthFilter) ?>">
            <div class="stat-label">Rejected</div>
            <div class="stat-value"><?= (int)$stats['rejected'] ?></div>
        </a>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state card">
            <div class="empty-icon">🧾</div>
            <h3>No approval requests found</h3>
            <p>There are no non-cash transaction approvals in this section for the selected filters.</p>
        </div>
    <?php else: ?>
        <div class="requests-grid">
            <?php foreach ($rows as $row): ?>
                <?php
                $status = (string)$row['status'];
                $isPending = $status === 'pending';
                $isAccepted = $status === 'accepted';
                $isRejected = $status === 'rejected';

                $categoryLabel = trim((string)$row['transaction_category']) !== ''
                    ? ucwords(str_replace('_', ' ', (string)$row['transaction_category']))
                    : '—';

                $displayMethod = trim((string)$row['payment_method']) !== ''
                    ? (string)$row['payment_method']
                    : (string)$row['request_payment_method'];

                $methodLabel = trim($displayMethod) !== ''
                    ? ucwords(str_replace('_', ' ', $displayMethod))
                    : '—';

                $invoiceNo = trim((string)$row['invoice_no']) !== '' ? (string)$row['invoice_no'] : '—';
                $donorName = trim((string)$row['donor_name']) !== '' ? (string)$row['donor_name'] : '—';
                $requestedByName = trim((string)$row['requested_by_name']) !== '' ? (string)$row['requested_by_name'] : ('User #' . (int)$row['requested_by']);
                $operatorName = trim((string)$row['operator_user_name']) !== '' ? (string)$row['operator_user_name'] : '—';
                $reviewerName = trim((string)$row['accountant_name']) !== '' ? (string)$row['accountant_name'] : '—';
                $visibleInTotals = ((int)$row['is_removed'] === 1) ? 'No, waiting/rejected' : 'Yes, accepted';
                ?>
                <div class="request-card">
                    <div class="request-card-head">
                        <div class="request-head-left">
                            <div class="request-title-row">
                                <h3>Invoice: <?= e($invoiceNo) ?></h3>
                                <span class="status-pill <?= e($status) ?>"><?= e(ucfirst($status)) ?></span>
                            </div>
                            <div class="request-meta">
                                <span>Request #<?= (int)$row['ID'] ?></span>
                                <span>Ledger #<?= (int)$row['ledger_id'] ?></span>
                                <span><?= e($methodLabel) ?></span>
                            </div>
                        </div>
                        <div class="amount-box"><?= money((float)$row['amount']) ?></div>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <span class="label">Donor</span>
                            <span class="value"><?= e($donorName) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Category</span>
                            <span class="value"><?= e($categoryLabel) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Method</span>
                            <span class="value"><?= e($methodLabel) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Date & Time</span>
                            <span class="value"><?= e((string)($row['transaction_date'] ?? '—')) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Requested By</span>
                            <span class="value"><?= e($requestedByName) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Operator</span>
                            <span class="value"><?= e($operatorName) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Requested At</span>
                            <span class="value"><?= e((string)$row['requested_at']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Ledger Visible In Totals</span>
                            <span class="value"><?= e($visibleInTotals) ?></span>
                        </div>
                    </div>

                    <?php if (trim((string)$row['notes']) !== ''): ?>
                        <div class="note-box">
                            <div class="note-title">Transaction Note</div>
                            <div class="note-text"><?= nl2br(e((string)$row['notes'])) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isPending): ?>
                        <div class="review-box <?= $isAccepted ? 'accepted' : 'rejected' ?>">
                            <div class="review-grid">
                                <div class="info-item">
                                    <span class="label">Reviewed By</span>
                                    <span class="value"><?= e($reviewerName) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Reviewed At</span>
                                    <span class="value"><?= e((string)($row['reviewed_at'] ?? '—')) ?></span>
                                </div>
                            </div>

                            <div class="review-reason">
                                <span class="label"><?= $isAccepted ? 'Acceptance Note' : 'Rejection Reason' ?></span>
                                <div class="review-reason-text">
                                    <?= trim((string)$row['accountant_reason']) !== '' ? nl2br(e((string)$row['accountant_reason'])) : '—' ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($isPending): ?>
                        <div class="action-panels">
                            <div class="action-panel accept">
                                <div class="action-panel-head">
                                    <h4>Accept Transaction</h4>
                                    <p>This will activate the transaction in totals and move the non-cash value to accountant ledger.</p>
                                </div>

                                <form method="post" action="transaction_approval_review.php" class="review-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="request_id" value="<?= (int)$row['ID'] ?>">
                                    <input type="hidden" name="action_type" value="accept">

                                    <label for="accept_reason_<?= (int)$row['ID'] ?>">Note</label>
                                    <textarea
                                        id="accept_reason_<?= (int)$row['ID'] ?>"
                                        name="reason"
                                        rows="4"
                                        required
                                        placeholder="Enter note to confirm this amount was received..."
                                    ></textarea>

                                    <button
                                        type="submit"
                                        class="btn success full"
                                        onclick="return confirm('Accept this transaction approval request?');"
                                    >
                                        Accept Transaction
                                    </button>
                                </form>
                            </div>

                            <div class="action-panel reject">
                                <div class="action-panel-head">
                                    <h4>Reject Transaction</h4>
                                    <p>This will keep the transaction outside balances and reports.</p>
                                </div>

                                <form method="post" action="transaction_approval_review.php" class="review-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="request_id" value="<?= (int)$row['ID'] ?>">
                                    <input type="hidden" name="action_type" value="reject">

                                    <label for="reject_reason_<?= (int)$row['ID'] ?>">Reason</label>
                                    <textarea
                                        id="reject_reason_<?= (int)$row['ID'] ?>"
                                        name="reason"
                                        rows="4"
                                        required
                                        placeholder="Enter reason for rejection..."
                                    ></textarea>

                                    <button
                                        type="submit"
                                        class="btn danger full"
                                        onclick="return confirm('Reject this transaction approval request?');"
                                    >
                                        Reject Transaction
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>