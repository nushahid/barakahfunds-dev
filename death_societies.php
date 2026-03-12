<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAccountant($pdo);
$uid = getLoggedInUserId();
$errors = [];
$rows = getSocieties($pdo);
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
foreach ($rows as $row) { if ((int)$row['ID'] === $editId) { $editRow = $row; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? 'create');
    $name = trim((string)($_POST['name'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    $active = isset($_POST['active']) ? 1 : 0;
    $id = (int)($_POST['id'] ?? 0);

    if ($name === '') {
        $errors[] = 'Society name is required.';
    } else {
        $check = $pdo->prepare($action === 'update' ? 'SELECT COUNT(*) FROM societies WHERE name = ? AND ID <> ?' : 'SELECT COUNT(*) FROM societies WHERE name = ?');
        $check->execute($action === 'update' ? [$name, $id] : [$name]);
        if ((int)$check->fetchColumn() > 0) {
            $errors[] = 'Society name already exists.';
        }
    }

    if (!$errors) {
        if ($isDefault) { $pdo->exec('UPDATE societies SET is_default = 0'); }
        if ($action === 'update' && $id > 0) {
            $pdo->prepare('UPDATE societies SET name = ?, city = ?, is_default = ?, active = ? WHERE ID = ?')->execute([$name, $city, $isDefault, $active, $id]);
            systemLog($pdo, $uid, 'society', 'update', $name, $id);
            setFlash('success', 'Society updated.');
        } else {
            $pdo->prepare('INSERT INTO societies (name, city, is_default, active, created_at) VALUES (?, ?, ?, ?, NOW())')->execute([$name, $city, $isDefault, 1]);
            systemLog($pdo, $uid, 'society', 'create', $name);
            setFlash('success', 'Society saved.');
        }
        header('Location: death_societies.php');
        exit;
    }
}

$rows = getSocieties($pdo);
foreach ($rows as $row) { if ((int)$row['ID'] === $editId) { $editRow = $row; break; } }
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Death Insurance Societies</h1>
<div class="grid-2 society-layout">
  <div class="card stack">
    <?php if($errors): ?><div class="alert error"><?php foreach($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
    <div class="toolbar">
      <h2 class="society-subtitle"><?= $editRow ? 'Edit Society' : 'New Society' ?></h2>
      <?php if ($editRow): ?><a class="btn" href="death_societies.php">Cancel</a><?php endif; ?>
    </div>
    <form method="post" class="stack">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
      <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['ID'] ?>"><?php endif; ?>
      <div><label>Name</label><input type="text" name="name" value="<?= e((string)($editRow['name'] ?? '')) ?>" required></div>
      <div><label>City</label><input type="text" name="city" value="<?= e((string)($editRow['city'] ?? '')) ?>"></div>
      <div class="switch-row"><div><strong>Default Society</strong><div class="muted">Use this as the automatic default for new records.</div></div><label><input type="checkbox" name="is_default" value="1" <?= (int)($editRow['is_default'] ?? 0) === 1 ? 'checked' : '' ?>> Default</label></div>
      <div class="switch-row"><div><strong>Active</strong><div class="muted">Inactive societies stay in history but are hidden from normal use.</div></div><label><input type="checkbox" name="active" value="1" <?= !$editRow || (int)($editRow['active'] ?? 1) === 1 ? 'checked' : '' ?>> Active</label></div>
      <button class="btn btn-primary" type="submit"><?= $editRow ? 'Update Society' : 'Save Society' ?></button>
    </form>
  </div>
  <div class="card">
    <div class="toolbar"><h2 class="society-subtitle">Society List</h2><span class="tag blue"><?= count($rows) ?> total</span></div>
    <div class="search-results">
      <?php foreach($rows as $row): ?>
        <div class="search-row">
          <div><strong><?= e((string)$row['name']) ?></strong><br><span class="muted"><?= e((string)$row['city']) ?></span></div>
          <div class="compact-actions">
            <?php if((int)$row['is_default']===1): ?><span class="tag blue">Default</span><?php endif; ?>
            <span class="tag <?= (int)$row['active']===1 ? 'green' : 'red' ?>"><?= (int)$row['active']===1 ? 'Active' : 'Inactive' ?></span>
            <a class="btn" href="death_societies.php?edit=<?= (int)$row['ID'] ?>">Edit</a>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if(!$rows): ?><div class="muted">No societies yet.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
