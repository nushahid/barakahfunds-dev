<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'accountant');

$statusFilter = trim((string)($_GET['status'] ?? 'pending'));
$allowedStatuses = ['pending', 'accepted', 'rejected', 'all'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}

$params = [];
$where = [];

if ($statusFilter !== 'all') {
    $where[] = 'tdr.status = :status';
    $params['status'] = $statusFilter;
}

$sql = "
    SELECT 
        tdr.ID,
        tdr.ledger_id,
        tdr.requested_by,
        tdr.requested_at,
        tdr.status,
        tdr.accountant_id,
        tdr.accountant_reason,
        tdr.reviewed_at,

        ol.created_at AS transaction_date,
        ol.invoice_no,
        ol.amount,
        ol.transaction_category,
        ol.payment_method,
        ol.notes,
        ol.is_removed,
        ol.removed_at,
        ol.removed_reason,

        donor.name AS donor_name,
        requester.name AS requested_by_name,
        reviewer.name AS accountant_name,
        operator_user.name AS operator_user_name
    FROM transaction_delete_requests tdr
    INNER JOIN operator_ledger ol ON ol.ID = tdr.ledger_id
    LEFT JOIN people donor ON donor.ID = ol.person_id
    LEFT JOIN users requester ON requester.ID = tdr.requested_by
    LEFT JOIN users reviewer ON reviewer.ID = tdr.accountant_id
    LEFT JOIN users operator_user ON operator_user.ID = ol.operator_id
";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY 
            CASE WHEN tdr.status = 'pending' THEN 0 ELSE 1 END,
            tdr.ID DESC
          LIMIT 200";

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
    SELECT status, COUNT(*) AS total
    FROM transaction_delete_requests
    GROUP BY status
";
foreach ($pdo->query($countSql)->fetchAll() as $r) {
    $st = (string)$r['status'];
    $total = (int)$r['total'];
    if (isset($stats[$st])) {
        $stats[$st] = $total;
    }
    $stats['all'] += $total;
}

$success = trim((string)($_GET['success'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

require_once __DIR__ . '/includes/header.php';
?>

<div class="delete-requests-page">
    <div class="page-head">
        <div>
            <h1 class="title">Transaction Delete Requests</h1>
            <p class="page-subtitle">Review, approve, or reject operator delete requests.</p>
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
        <div class="alert danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <a class="stat-card <?= $statusFilter === 'all' ? 'active' : '' ?>" href="transaction_delete_requests.php?status=all">
            <div class="stat-label">All Requests</div>
            <div class="stat-value"><?= (int)$stats['all'] ?></div>
        </a>

        <a class="stat-card pending <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="transaction_delete_requests.php?status=pending">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?= (int)$stats['pending'] ?></div>
        </a>

        <a class="stat-card accepted <?= $statusFilter === 'accepted' ? 'active' : '' ?>" href="transaction_delete_requests.php?status=accepted">
            <div class="stat-label">Accepted</div>
            <div class="stat-value"><?= (int)$stats['accepted'] ?></div>
        </a>

        <a class="stat-card rejected <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="transaction_delete_requests.php?status=rejected">
            <div class="stat-label">Rejected</div>
            <div class="stat-value"><?= (int)$stats['rejected'] ?></div>
        </a>
    </div>

    <div class="card filter-bar-card">
        <div class="filter-bar">
            <div class="filter-bar-left">
                <span class="filter-label">Showing:</span>
                <span class="status-pill large <?= e($statusFilter) ?>">
                    <?= e(ucfirst($statusFilter)) ?>
                </span>
            </div>
            <div class="filter-bar-right">
                <a class="btn soft" href="transaction_delete_requests.php?status=pending">Pending</a>
                <a class="btn soft" href="transaction_delete_requests.php?status=accepted">Accepted</a>
                <a class="btn soft" href="transaction_delete_requests.php?status=rejected">Rejected</a>
                <a class="btn soft" href="transaction_delete_requests.php?status=all">All</a>
            </div>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state card">
            <div class="empty-icon">🗂️</div>
            <h3>No delete requests found</h3>
            <p>There are no transaction delete requests in this section right now.</p>
        </div>
    <?php else: ?>
        <div class="requests-grid">
            <?php foreach ($rows as $row): ?>
                <?php
                $status = (string)$row['status'];
                $isPending = $status === 'pending';
                $isAccepted = $status === 'accepted';
                $isRejected = $status === 'rejected';

                $categoryLabel = ucwords(str_replace('_', ' ', (string)$row['transaction_category']));
                $methodLabel = ucwords(str_replace('_', ' ', (string)$row['payment_method']));
                $invoiceNo = trim((string)$row['invoice_no']) !== '' ? (string)$row['invoice_no'] : '—';
                $donorName = trim((string)$row['donor_name']) !== '' ? (string)$row['donor_name'] : '—';
                $requestedByName = trim((string)$row['requested_by_name']) !== '' ? (string)$row['requested_by_name'] : ('User #' . (int)$row['requested_by']);
                $operatorName = trim((string)$row['operator_user_name']) !== '' ? (string)$row['operator_user_name'] : '—';
                $reviewerName = trim((string)$row['accountant_name']) !== '' ? (string)$row['accountant_name'] : '—';
                ?>
                <div class="request-card">
                    <div class="request-card-head">
                        <div class="request-head-left">
                            <div class="request-title-row">
                                <h3>Invoice: <?= e($invoiceNo) ?></h3>
                                <span class="status-pill <?= e($status) ?>">
                                    <?= e(ucfirst($status)) ?>
                                </span>
                            </div>
                            <div class="request-meta">
                                <span>Request #<?= (int)$row['ID'] ?></span>
                                <span>Ledger #<?= (int)$row['ledger_id'] ?></span>
                            </div>
                        </div>
                        <div class="amount-box">
                            <?= money((float)$row['amount']) ?>
                        </div>
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
                            <span class="label">Transaction Date</span>
                            <span class="value"><?= e((string)$row['transaction_date']) ?></span>
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
                            <span class="label">Removed From Calculations</span>
                            <span class="value">
                                <?= ((int)$row['is_removed'] === 1) ? 'Yes' : 'No' ?>
                            </span>
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
                                <span class="label"><?= $isAccepted ? 'Removal Reason' : 'Rejection Reason' ?></span>
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
                                    <h4>Accept & Remove</h4>
                                    <p>This will exclude this transaction from balances and reports.</p>
                                </div>

                                <form method="post" action="transaction_delete_review.php" class="review-form">
                                    <input type="hidden" name="request_id" value="<?= (int)$row['ID'] ?>">
                                    <input type="hidden" name="action_type" value="accept">

                                    <label for="accept_reason_<?= (int)$row['ID'] ?>">Reason</label>
                                    <textarea
                                        id="accept_reason_<?= (int)$row['ID'] ?>"
                                        name="reason"
                                        rows="4"
                                        required
                                        placeholder="Enter reason for removing this transaction..."
                                    ></textarea>

                                    <button
                                        type="submit"
                                        class="btn danger full"
                                        onclick="return confirm('Accept this delete request and remove transaction from calculations?');"
                                    >
                                        Accept & Remove
                                    </button>
                                </form>
                            </div>

                            <div class="action-panel reject">
                                <div class="action-panel-head">
                                    <h4>Reject Request</h4>
                                    <p>Transaction will remain active in balances and reports.</p>
                                </div>

                                <form method="post" action="transaction_delete_review.php" class="review-form">
                                    <input type="hidden" name="request_id" value="<?= (int)$row['ID'] ?>">
                                    <input type="hidden" name="action_type" value="reject">

                                    <label for="reject_reason_<?= (int)$row['ID'] ?>">Reason</label>
                                    <textarea
                                        id="reject_reason_<?= (int)$row['ID'] ?>"
                                        name="reason"
                                        rows="4"
                                        placeholder="Optional reason for rejection..."
                                    ></textarea>

                                    <button
                                        type="submit"
                                        class="btn secondary full"
                                        onclick="return confirm('Reject this delete request?');"
                                    >
                                        Reject Request
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

<style>
.delete-requests-page{
    display:block;
    width:100%;
}

.page-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:18px;
}

.page-subtitle{
    margin:6px 0 0;
    color:#6b7280;
    font-size:14px;
}

.alert{
    border-radius:14px;
    padding:14px 16px;
    margin:0 0 18px;
    font-weight:600;
    border:1px solid transparent;
}

.alert.success{
    background:#ecfdf3;
    border-color:#bbf7d0;
    color:#166534;
}

.alert.danger{
    background:#fef2f2;
    border-color:#fecaca;
    color:#991b1b;
}

.stats-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
    gap:14px;
    margin-bottom:18px;
}

.stat-card{
    display:block;
    text-decoration:none;
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-radius:18px;
    padding:18px;
    box-shadow:0 8px 22px rgba(15, 23, 42, 0.04);
    transition:all .18s ease;
    color:inherit;
}

.stat-card:hover{
    transform:translateY(-1px);
    box-shadow:0 12px 28px rgba(15, 23, 42, 0.08);
}

.stat-card.active{
    border-color:#f59e0b;
    box-shadow:0 0 0 3px rgba(245, 158, 11, 0.12);
}

.stat-card.pending{
    background:linear-gradient(180deg, #fffbeb 0%, #ffffff 100%);
}

.stat-card.accepted{
    background:linear-gradient(180deg, #ecfdf5 0%, #ffffff 100%);
}

.stat-card.rejected{
    background:linear-gradient(180deg, #fef2f2 0%, #ffffff 100%);
}

.stat-label{
    font-size:13px;
    color:#6b7280;
    margin-bottom:10px;
}

.stat-value{
    font-size:30px;
    font-weight:800;
    color:#111827;
    line-height:1;
}

.filter-bar-card{
    margin-bottom:18px;
    border-radius:18px;
}

.filter-bar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    flex-wrap:wrap;
}

.filter-bar-left,
.filter-bar-right{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.filter-label{
    font-size:14px;
    color:#6b7280;
    font-weight:600;
}

.requests-grid{
    display:grid;
    grid-template-columns:1fr;
    gap:18px;
}

.request-card{
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-radius:20px;
    padding:20px;
    box-shadow:0 10px 26px rgba(15, 23, 42, 0.05);
}

.request-card-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:14px;
    margin-bottom:18px;
    padding-bottom:16px;
    border-bottom:1px solid #f1f5f9;
}

.request-title-row{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.request-title-row h3{
    margin:0;
    font-size:20px;
    line-height:1.2;
    color:#111827;
}

.request-meta{
    margin-top:8px;
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    color:#6b7280;
    font-size:13px;
}

.amount-box{
    min-width:140px;
    text-align:right;
    font-size:24px;
    font-weight:800;
    color:#111827;
    background:#f8fafc;
    border:1px solid #e5e7eb;
    border-radius:16px;
    padding:14px 16px;
}

.info-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
    gap:14px;
    margin-bottom:16px;
}

.info-item{
    background:#fafafa;
    border:1px solid #eceff3;
    border-radius:14px;
    padding:12px 14px;
}

.info-item .label{
    display:block;
    color:#6b7280;
    font-size:12px;
    font-weight:700;
    margin-bottom:6px;
    text-transform:uppercase;
    letter-spacing:.03em;
}

.info-item .value{
    display:block;
    color:#111827;
    font-size:15px;
    font-weight:600;
    word-break:break-word;
}

.note-box,
.review-box{
    margin-top:16px;
    border-radius:16px;
    padding:16px;
}

.note-box{
    background:#fffaf0;
    border:1px solid #fde68a;
}

.note-title{
    font-size:13px;
    font-weight:800;
    color:#92400e;
    margin-bottom:8px;
    text-transform:uppercase;
    letter-spacing:.03em;
}

.note-text{
    color:#78350f;
    line-height:1.55;
}

.review-box.accepted{
    background:#ecfdf5;
    border:1px solid #bbf7d0;
}

.review-box.rejected{
    background:#fef2f2;
    border:1px solid #fecaca;
}

.review-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
    gap:12px;
    margin-bottom:14px;
}

.review-reason .label{
    display:block;
    color:#6b7280;
    font-size:12px;
    font-weight:700;
    margin-bottom:8px;
    text-transform:uppercase;
    letter-spacing:.03em;
}

.review-reason-text{
    background:#ffffff;
    border:1px solid rgba(0,0,0,0.06);
    border-radius:12px;
    padding:12px 14px;
    color:#111827;
    line-height:1.55;
    min-height:52px;
}

.action-panels{
    margin-top:18px;
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:16px;
}

.action-panel{
    border-radius:18px;
    padding:18px;
    border:1px solid #e5e7eb;
}

.action-panel.accept{
    background:linear-gradient(180deg, #fff7ed 0%, #ffffff 100%);
    border-color:#fed7aa;
}

.action-panel.reject{
    background:linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
    border-color:#dbe4ee;
}

.action-panel-head h4{
    margin:0 0 6px;
    font-size:18px;
    color:#111827;
}

.action-panel-head p{
    margin:0 0 14px;
    color:#6b7280;
    font-size:14px;
    line-height:1.5;
}

.review-form label{
    display:block;
    margin-bottom:8px;
    font-size:13px;
    font-weight:700;
    color:#374151;
}

.review-form textarea{
    width:100%;
    border:1px solid #d1d5db;
    border-radius:12px;
    padding:12px 14px;
    resize:vertical;
    outline:none;
    font:inherit;
    min-height:100px;
    background:#ffffff;
    margin-bottom:12px;
}

.review-form textarea:focus{
    border-color:#f59e0b;
    box-shadow:0 0 0 3px rgba(245, 158, 11, 0.14);
}

.status-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    padding:6px 12px;
    font-size:12px;
    font-weight:800;
    letter-spacing:.02em;
    text-transform:uppercase;
}

.status-pill.large{
    padding:8px 14px;
    font-size:12px;
}

.status-pill.pending{
    background:#fef3c7;
    color:#92400e;
}

.status-pill.accepted{
    background:#dcfce7;
    color:#166534;
}

.status-pill.rejected{
    background:#fee2e2;
    color:#991b1b;
}

.status-pill.all{
    background:#e5e7eb;
    color:#374151;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:42px;
    padding:10px 16px;
    border:none;
    border-radius:12px;
    text-decoration:none;
    cursor:pointer;
    font-weight:700;
    font-size:14px;
    transition:all .15s ease;
}

.btn:hover{
    transform:translateY(-1px);
}

.btn.full{
    width:100%;
}

.btn.soft{
    background:#f3f4f6;
    color:#111827;
}

.btn.soft:hover{
    background:#e5e7eb;
}

.btn.secondary{
    background:#e5e7eb;
    color:#111827;
}

.btn.secondary:hover{
    background:#d1d5db;
}

.btn.danger{
    background:#dc2626;
    color:#ffffff;
}

.btn.danger:hover{
    background:#b91c1c;
}

.empty-state{
    text-align:center;
    padding:40px 20px;
    border-radius:20px;
}

.empty-icon{
    font-size:38px;
    margin-bottom:10px;
}

.empty-state h3{
    margin:0 0 8px;
    font-size:22px;
    color:#111827;
}

.empty-state p{
    margin:0;
    color:#6b7280;
}

@media (max-width: 980px){
    .action-panels{
        grid-template-columns:1fr;
    }
}

@media (max-width: 760px){
    .request-card{
        padding:16px;
    }

    .request-card-head{
        flex-direction:column;
        align-items:stretch;
    }

    .amount-box{
        min-width:0;
        text-align:left;
    }

    .filter-bar{
        flex-direction:column;
        align-items:flex-start;
    }

    .filter-bar-right{
        width:100%;
    }

    .filter-bar-right .btn{
        flex:1 1 auto;
    }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
