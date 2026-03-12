<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAccountant($pdo);
$uid = getLoggedInUserId();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $mode = (string)($_POST['mode'] ?? 'create');
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $status = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;
    if ($name === '') {
        $errors[] = 'Category name is required.';
    } else {
        if ($mode === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('UPDATE expense_categories SET category_name = ?, description = ?, status = ? WHERE ID = ?')->execute([$name, $desc, $status, $id]);
            systemLog($pdo, $uid, 'expense_category', 'update', $name, $id);
            setFlash('success', 'Category updated.');
        } else {
            $pdo->prepare('INSERT INTO expense_categories (category_name, description, status, created_by, created_at) VALUES (?, ?, ?, ?, NOW())')->execute([$name, $desc, $status, $uid]);
            systemLog($pdo, $uid, 'expense_category', 'create', $name);
            setFlash('success', 'Category saved.');
        }
        header('Location: expense_categories.php');
        exit;
    }
}
$rows = tableExists($pdo,'expense_categories') ? $pdo->query('SELECT * FROM expense_categories ORDER BY category_name ASC')->fetchAll() : [];
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
foreach ($rows as $row) { if ((int)$row['ID'] === $editId) { $editRow = $row; break; } }
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Expense Categories</h1>
<?php if ($errors): ?><div class="alert error"><?php foreach($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="grid-2 expense-category-layout">
  <div class="card stack expense-category-form-card">
    <div class="toolbar">
      <h2 class="expense-category-subtitle"><?= $editRow ? 'Edit Category' : 'New Category' ?></h2>
      <?php if ($editRow): ?><a class="btn" href="expense_categories.php">Cancel</a><?php endif; ?>
    </div>
    <form method="post" class="stack">
      <?= csrfField() ?>
      <input type="hidden" name="mode" value="<?= $editRow ? 'update' : 'create' ?>">
      <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['ID'] ?>"><?php endif; ?>
      <div><label>Category Name</label><input type="text" name="name" value="<?= e((string)($editRow['category_name'] ?? '')) ?>" required></div>
      <div><label>Description</label><textarea name="description"><?= e((string)($editRow['description'] ?? '')) ?></textarea></div>
      <div>
        <label>Status</label>
        <div class="payment-chip-group expense-status-group">
          <?php foreach ([[1,'🟢','Active'],[0,'⚪','Inactive']] as $s): ?>
            <label class="selector-label">
              <input class="selector-input" type="radio" name="status" value="<?= (int)$s[0] ?>" <?= (int)($editRow['status'] ?? 1) === (int)$s[0] ? 'checked' : '' ?>>
              <span class="payment-chip"><span><?= e($s[1]) ?></span><span><?= e($s[2]) ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <button class="btn btn-primary" type="submit"><?= $editRow ? 'Update Category' : 'Save Category' ?></button>
    </form>
  </div>

  <div class="card stack expense-category-list-card">
    <div class="toolbar">
      <h2 class="expense-category-subtitle">Category List</h2>
      <span class="tag blue"><?= count($rows) ?> total</span>
    </div>
    <div class="search-results">
      <?php foreach($rows as $row): ?>
        <a class="search-row expense-category-row" href="expense_categories.php?edit=<?= (int)$row['ID'] ?>">
          <div>
            <strong><?= e((string)$row['category_name']) ?></strong><br>
            <span class="muted"><?= e((string)$row['description']) ?></span>
          </div>
          <div class="compact-actions">
            <span class="tag <?= (int)$row['status']===1?'green':'red' ?>"><?= (int)$row['status']===1?'Active':'Inactive' ?></span>
            <span class="btn">Edit</span>
          </div>
        </a>
      <?php endforeach; ?>
      <?php if (!$rows): ?><div class="muted">No categories yet.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
