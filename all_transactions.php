<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'accountant');

// Filters
$method     = trim($_GET['method'] ?? '');
$category   = trim($_GET['category'] ?? '');
$dateFrom   = trim($_GET['date_from'] ?? '');
$dateTo     = trim($_GET['date_to'] ?? '');
$operatorId = trim($_GET['operator_id'] ?? '');

$where = ['COALESCE(ol.is_removed, 0) = 0'];
$params = [];

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

if ($operatorId !== '') {
    $where[] = 'ol.operator_id = :operator_id';
    $params['operator_id'] = $operatorId;
}

$rows = [];
if (tableExists($pdo, 'operator_ledger')) {
    $sql = "
        SELECT 
            ol.ID,
            ol.person_id,
            ol.reference_id,
            ol.operator_id,
            ol.created_at AS date,
            p.name AS person,
            ol.transaction_category AS category,
            COALESCE(ev.name, ln.name, '—') AS reference_name,
            ol.amount,
            ol.payment_method AS method,
            ol.notes,
            ol.invoice_no,
            u.name AS operator_name
        FROM operator_ledger ol
        LEFT JOIN people p ON p.ID = ol.person_id
        LEFT JOIN events ev ON ev.ID = ol.reference_id
        LEFT JOIN loan ln ON ln.ID = ol.reference_id
        LEFT JOIN users u ON u.ID = ol.operator_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ol.ID DESC
        LIMIT 300
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// filter dropdowns
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
    $operators = $pdo->query($operatorsSql)->fetchAll();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">All Transactions</h1>

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
        <a href="all_transactions.php" class="btn secondary">Reset</a>
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
          <th>Operator</th>
          <th>Donor</th>
          <th>Category</th>
          <th>Reference</th>
          <th>Method</th>
          <th>Amount</th>
          <th>Receipt</th>
          <th>Delete</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= e((string)$row['date']) ?></td>
          <td><?= e((string)$row['invoice_no']) ?></td>
          <td><?= e((string)($row['operator_name'] ?? '—')) ?></td>
          <td><?= e(resolveLedgerDonorName($pdo, $row)) ?></td>
          <td><span class="tag orange"><?= e(ucwords(str_replace('_', ' ', (string)$row['category']))) ?></span></td>
          <td><?= e((string)$row['reference_name']) ?></td>
          <td><?= e((string)$row['method']) ?></td>
          <td><?= money((float)$row['amount']) ?></td>
          <td>
            <a class="btn" target="_blank" href="receipt_print.php?id=<?= (int)$row['ID'] ?>">Receipt</a>
          </td>
          <td>
            <form method="post" action="transaction_delete_direct.php" onsubmit="return confirm('Are you sure you want to delete this transaction? This action cannot be undone from the list.');">
              <input type="hidden" name="ledger_id" value="<?= (int)$row['ID'] ?>">
              <button type="submit" class="btn danger btn-small">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$rows): ?>
        <tr><td colspan="10" class="muted">No transactions found.</td></tr>
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
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>