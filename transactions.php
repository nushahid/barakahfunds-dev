<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'operator');

$uid = getLoggedInUserId();

// Filters
$method   = trim($_GET['method'] ?? '');
$category = trim($_GET['category'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

$where = ['ol.operator_id = :uid', 'COALESCE(ol.is_removed, 0) = 0'];
$params = ['uid' => $uid];

if ($method !== '') {
    $where[] = 'ol.payment_method = :method';
    $params['method'] = $method;
}

if ($category !== '') {
    $where[] = 'ol.transaction_category = :category';
    $params['category'] = $category;
}

if ($dateFrom !== '') {
    $where[] = 'DATE(ol.created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'DATE(ol.created_at) <= :date_to';
    $params['date_to'] = $dateTo;
}

$rows = [];
if (tableExists($pdo, 'operator_ledger')) {
    $sql = "
        SELECT 
            ol.ID,
            ol.person_id,
            ol.reference_id,
            ol.created_at AS date,
            p.name AS person,
            ol.transaction_category AS category,
            COALESCE(e.name, l.name, '—') AS reference_name,
            ol.amount,
            ol.payment_method AS method,
            ol.notes,
            ol.invoice_no,
            (
                SELECT tdr.status
                FROM transaction_delete_requests tdr
                WHERE tdr.ledger_id = ol.ID
                ORDER BY tdr.ID DESC
                LIMIT 1
            ) AS delete_request_status
        FROM operator_ledger ol
        LEFT JOIN people p ON p.ID = ol.person_id
        LEFT JOIN events e ON e.ID = ol.reference_id
        LEFT JOIN loan l ON l.ID = ol.reference_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ol.ID DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// for filter dropdowns
$methods = tableExists($pdo, 'operator_ledger')
    ? $pdo->query("SELECT DISTINCT payment_method FROM operator_ledger WHERE payment_method IS NOT NULL AND payment_method <> '' ORDER BY payment_method")->fetchAll(PDO::FETCH_COLUMN)
    : [];

$categories = tableExists($pdo, 'operator_ledger')
    ? $pdo->query("SELECT DISTINCT transaction_category FROM operator_ledger WHERE transaction_category IS NOT NULL AND transaction_category <> '' ORDER BY transaction_category")->fetchAll(PDO::FETCH_COLUMN)
    : [];

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Transactions</h1>

<div class="card" style="margin-bottom:16px;">
  <form method="get" class="filters-grid">
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
      <label>Date From</label>
      <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
    </div>

    <div class="filter-card">
      <label>Date To</label>
      <input type="date" name="date_to" value="<?= e($dateTo) ?>">
    </div>

    <div class="filter-card actions">
      <label>&nbsp;</label>
      <div>
        <button type="submit" class="btn">Filter</button>
        <a href="transactions.php" class="btn secondary">Reset</a>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Invoice</th>
          <th>Donor</th>
          <th>Category</th>
          <th>Reference</th>
          <th>Method</th>
          <th>Amount</th>
          <th>Delete Request</th>
          <th>Receipt</th>
        </tr>
      </thead>
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
          <td>
            <?php if ($row['delete_request_status'] === 'pending'): ?>
              <span class="tag">Pending</span>
            <?php elseif ($row['delete_request_status'] === 'accepted'): ?>
              <span class="tag red">Removed</span>
            <?php else: ?>
              <form method="post" action="transaction_delete_request.php" onsubmit="return confirm('Send delete request to accountant?');">
                <input type="hidden" name="ledger_id" value="<?= (int)$row['ID'] ?>">
                <button type="submit" class="btn danger btn-small">Delete Request</button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <a class="btn" target="_blank" href="receipt_print.php?id=<?= (int)$row['ID'] ?>">Receipt</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="muted">No transactions found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
  
  .btn-small{
  padding:4px 8px;
  font-size:12px;
  line-height:1.2;
  min-height:auto;
  border-radius:6px;
}

.filters-grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
.btn.danger{
  background:#dc2626;
  color:#fff;
}
.tag.red{
  background:#fee2e2;
  color:#b91c1c;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
