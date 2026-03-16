<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'accountant');

function bfColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function bfDateTime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    $ts = strtotime($value);
    if (!$ts) {
        return (string)$value;
    }

    return date('d/m/Y H:i', $ts);
}

$hasRemovedAt = bfColumnExists($pdo, 'operator_ledger', 'removed_at');
$hasRemovedBy = bfColumnExists($pdo, 'operator_ledger', 'removed_by');

// Filters
$method           = trim($_GET['method'] ?? '');
$category         = trim($_GET['category'] ?? '');
$operatorId       = trim($_GET['operator_id'] ?? '');
$deletedById      = trim($_GET['deleted_by'] ?? '');
$txnDateFrom      = trim($_GET['txn_date_from'] ?? '');
$txnDateTo        = trim($_GET['txn_date_to'] ?? '');
$deletedDateFrom  = trim($_GET['deleted_date_from'] ?? '');
$deletedDateTo    = trim($_GET['deleted_date_to'] ?? '');

$where = ['COALESCE(ol.is_removed, 0) = 1'];
$params = [];

if ($method !== '') {
    $where[] = 'ol.payment_method = :method';
    $params['method'] = $method;
}

if ($category !== '') {
    $where[] = 'ol.transaction_category = :category';
    $params['category'] = $category;
}

if ($operatorId !== '') {
    $where[] = 'ol.operator_id = :operator_id';
    $params['operator_id'] = $operatorId;
}

if ($txnDateFrom !== '') {
    $where[] = 'DATE(ol.created_at) >= :txn_date_from';
    $params['txn_date_from'] = $txnDateFrom;
}

if ($txnDateTo !== '') {
    $where[] = 'DATE(ol.created_at) <= :txn_date_to';
    $params['txn_date_to'] = $txnDateTo;
}

if ($hasRemovedAt && $deletedDateFrom !== '') {
    $where[] = 'DATE(ol.removed_at) >= :deleted_date_from';
    $params['deleted_date_from'] = $deletedDateFrom;
}

if ($hasRemovedAt && $deletedDateTo !== '') {
    $where[] = 'DATE(ol.removed_at) <= :deleted_date_to';
    $params['deleted_date_to'] = $deletedDateTo;
}

if ($hasRemovedBy && $deletedById !== '') {
    $where[] = 'ol.removed_by = :deleted_by';
    $params['deleted_by'] = $deletedById;
}

$rows = [];
if (tableExists($pdo, 'operator_ledger')) {
    $sql = "
        SELECT
            ol.ID,
            ol.person_id,
            ol.reference_id,
            ol.operator_id,
            ol.created_at,
            " . ($hasRemovedAt ? "ol.removed_at," : "NULL AS removed_at,") . "
            " . ($hasRemovedBy ? "ol.removed_by," : "NULL AS removed_by,") . "
            p.name AS person,
            ol.transaction_category AS category,
            COALESCE(ev.name, ln.name, '—') AS reference_name,
            ol.amount,
            ol.payment_method AS method,
            ol.notes,
            ol.invoice_no,
            u.name AS operator_name,
            rb.name AS removed_by_name
        FROM operator_ledger ol
        LEFT JOIN people p ON p.ID = ol.person_id
        LEFT JOIN events ev ON ev.ID = ol.reference_id
        LEFT JOIN loan ln ON ln.ID = ol.reference_id
        LEFT JOIN users u ON u.ID = ol.operator_id
        LEFT JOIN users rb ON rb.ID = ol.removed_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY " . ($hasRemovedAt ? "ol.removed_at DESC, " : "") . "ol.ID DESC
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$methods = [];
$categories = [];
$operators = [];
$deletedByUsers = [];

if (tableExists($pdo, 'operator_ledger')) {
    $methods = $pdo->query("
        SELECT DISTINCT payment_method
        FROM operator_ledger
        WHERE payment_method IS NOT NULL
          AND payment_method <> ''
        ORDER BY payment_method
    ")->fetchAll(PDO::FETCH_COLUMN);

    $categories = $pdo->query("
        SELECT DISTINCT transaction_category
        FROM operator_ledger
        WHERE transaction_category IS NOT NULL
          AND transaction_category <> ''
        ORDER BY transaction_category
    ")->fetchAll(PDO::FETCH_COLUMN);

    $operators = $pdo->query("
        SELECT DISTINCT u.ID, u.name
        FROM users u
        INNER JOIN operator_ledger ol ON ol.operator_id = u.ID
        ORDER BY u.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($hasRemovedBy) {
        $deletedByUsers = $pdo->query("
            SELECT DISTINCT u.ID, u.name
            FROM users u
            INNER JOIN operator_ledger ol ON ol.removed_by = u.ID
            WHERE COALESCE(ol.is_removed, 0) = 1
            ORDER BY u.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Deleted Transactions</h1>
<div class="muted" style="margin-bottom:16px;">
    Accountant can review deleted entries from all operators and restore them if needed.
</div>

<div class="card" style="margin-bottom:16px;">
    <form method="get" class="filters-grid">

        <div class="filter-card">
            <label>Operator</label>
            <select name="operator_id">
                <option value="">All Operators</option>
                <?php foreach ($operators as $op): ?>
                    <option value="<?= (int)$op['ID'] ?>" <?= $operatorId === (string)$op['ID'] ? 'selected' : '' ?>>
                        <?= e($op['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-card">
            <label>Payment Method</label>
            <select name="method">
                <option value="">All</option>
                <?php foreach ($methods as $m): ?>
                    <option value="<?= e($m) ?>" <?= $method === $m ? 'selected' : '' ?>>
                        <?= e(ucwords(str_replace('_', ' ', $m))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-card">
            <label>Category</label>
            <select name="category">
                <option value="">All</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>" <?= $category === $c ? 'selected' : '' ?>>
                        <?= e(ucwords(str_replace('_', ' ', $c))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-card">
            <label>Transaction Date From</label>
            <input type="date" name="txn_date_from" value="<?= e($txnDateFrom) ?>">
        </div>

        <div class="filter-card">
            <label>Transaction Date To</label>
            <input type="date" name="txn_date_to" value="<?= e($txnDateTo) ?>">
        </div>

        <?php if ($hasRemovedBy): ?>
        <div class="filter-card">
            <label>Deleted By</label>
            <select name="deleted_by">
                <option value="">All</option>
                <?php foreach ($deletedByUsers as $rb): ?>
                    <option value="<?= (int)$rb['ID'] ?>" <?= $deletedById === (string)$rb['ID'] ? 'selected' : '' ?>>
                        <?= e($rb['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="filter-card actions">
            <label>&nbsp;</label>
            <div>
                <button type="submit" class="btn">Filter</button>
                <a href="deleted_transactions.php" class="btn secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Deleted At</th>
                    <th>Transaction Date</th>
                    <th>Invoice</th>
                    <th>Operator</th>
                    <th>Donor</th>
                    <th>Category</th>
                    <th>Reference</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Deleted By</th>
                    <th>Receipt</th>
                    <th>Restore</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e(bfDateTime($row['removed_at'] ?? null)) ?></td>
                            <td><?= e(bfDateTime($row['created_at'] ?? null)) ?></td>
                            <td><?= e((string)($row['invoice_no'] ?? '')) ?></td>
                            <td><?= e((string)($row['operator_name'] ?? '—')) ?></td>
                            <td><?= e(function_exists('resolveLedgerDonorName') ? resolveLedgerDonorName($pdo, $row) : ((string)($row['person'] ?? '—'))) ?></td>
                            <td><span class="tag orange"><?= e(ucwords(str_replace('_', ' ', (string)($row['category'] ?? '')))) ?></span></td>
                            <td><?= e((string)($row['reference_name'] ?? '—')) ?></td>
                            <td><?= e((string)($row['method'] ?? '')) ?></td>
                            <td><?= function_exists('money') ? money((float)($row['amount'] ?? 0)) : e(number_format((float)($row['amount'] ?? 0), 2)) ?></td>
                            <td><?= e((string)($row['removed_by_name'] ?? '—')) ?></td>
                            <td>
                                <a class="btn" target="_blank" href="receipt_print.php?id=<?= (int)$row['ID'] ?>">Receipt</a>
                            </td>
                            <td>
                                <form method="post" action="transaction_restore.php" onsubmit="return confirm('Restore this deleted transaction?');">
                                    <input type="hidden" name="ledger_id" value="<?= (int)$row['ID'] ?>">
                                    <button type="submit" class="btn success btn-small">Restore</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="12" class="muted">No deleted transactions found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.filters-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:12px;
}
.filter-card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:12px;
}
.filter-card label{
    display:block;
    font-size:13px;
    margin-bottom:6px;
    color:#555;
}
.filter-card select,
.filter-card input{
    width:100%;
    padding:10px;
    border:1px solid #d1d5db;
    border-radius:8px;
}
.filter-card.actions > div{
    display:flex;
    gap:8px;
}
.btn.secondary{
    background:#f3f4f6;
    color:#111;
}
.btn.success{
    background:#059669;
    color:#fff;
}
.btn-small{
    padding:4px 8px;
    font-size:12px;
    line-height:1.2;
    min-height:auto;
    border-radius:6px;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>