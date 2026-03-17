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

$rows = tableExists($pdo, 'expense_categories')
    ? $pdo->query('SELECT * FROM expense_categories ORDER BY category_name ASC')->fetchAll()
    : [];

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
foreach ($rows as $row) {
    if ((int)$row['ID'] === $editId) {
        $editRow = $row;
        break;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Expense Categories</h1>

<?php if ($errors): ?>
  <div class="alert error">
    <?php foreach ($errors as $er): ?>
      <div><?= e($er) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="expense-category-page-wrap">
  <section class="card stack expense-category-form-card">
    <div class="toolbar expense-category-card-head">
      <div>
        <h2 class="expense-category-subtitle"><?= $editRow ? 'Edit Category' : 'New Category' ?></h2>
        <p class="muted expense-category-helper">Create a new expense category or update an existing one.</p>
      </div>
      <?php if ($editRow): ?>
        <a class="btn" href="expense_categories.php">Cancel</a>
      <?php endif; ?>
    </div>

    <form method="post" class="stack expense-category-form-grid">
      <?= csrfField() ?>
      <input type="hidden" name="mode" value="<?= $editRow ? 'update' : 'create' ?>">
      <?php if ($editRow): ?>
        <input type="hidden" name="id" value="<?= (int)$editRow['ID'] ?>">
      <?php endif; ?>

      <div>
        <label for="expense-category-name">Category Name</label>
        <input id="expense-category-name" type="text" name="name" value="<?= e((string)($editRow['category_name'] ?? '')) ?>" required>
      </div>

      <div>
        <label for="expense-category-description">Description</label>
        <textarea id="expense-category-description" name="description" rows="5"><?= e((string)($editRow['description'] ?? '')) ?></textarea>
      </div>

      <div>
        <label>Status</label>
        <div class="payment-chip-group expense-status-group">
          <?php foreach ([[1, '🟢', 'Active'], [0, '⚪', 'Inactive']] as $s): ?>
            <label class="selector-label">
              <input class="selector-input" type="radio" name="status" value="<?= (int)$s[0] ?>" <?= (int)($editRow['status'] ?? 1) === (int)$s[0] ? 'checked' : '' ?>>
              <span class="payment-chip"><span><?= e($s[1]) ?></span><span><?= e($s[2]) ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="btn btn-primary expense-category-submit" type="submit">
        <?= $editRow ? 'Update Category' : 'Save Category' ?>
      </button>
    </form>
  </section>

  <section class="card stack expense-category-list-card">
    <div class="toolbar expense-category-card-head">
      <div>
        <h2 class="expense-category-subtitle">Category List</h2>
        <p class="muted expense-category-helper">Tap any category to load it into the form for editing.</p>
      </div>
      <span class="tag orange"><?= count($rows) ?> total</span>
    </div>

    <div class="search-results expense-category-results">
      <?php foreach ($rows as $row): ?>
        <a class="search-row expense-category-row <?= $editId === (int)$row['ID'] ? 'is-selected' : '' ?>" href="expense_categories.php?edit=<?= (int)$row['ID'] ?>">
          <div class="expense-category-row-main">
            <strong><?= e((string)$row['category_name']) ?></strong>
            <?php if (trim((string)$row['description']) !== ''): ?>
              <span class="muted expense-category-row-desc"><?= e((string)$row['description']) ?></span>
            <?php else: ?>
              <span class="muted expense-category-row-desc">No description added.</span>
            <?php endif; ?>
          </div>

          <div class="compact-actions expense-category-row-actions">
            <span class="tag <?= (int)$row['status'] === 1 ? 'green' : 'red' ?>">
              <?= (int)$row['status'] === 1 ? 'Active' : 'Inactive' ?>
            </span>
            <span class="btn">Edit</span>
          </div>
        </a>
      <?php endforeach; ?>

      <?php if (!$rows): ?>
        <div class="expense-category-empty muted">No categories yet.</div>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
