<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAdmin($pdo);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$currentUser = currentUser($pdo);
$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
if ($userId <= 0) {
    setFlash('error', 'User not found.');
    header('Location: admin_users.php');
    exit;
}

function loadManagedUser(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare('SELECT u.*, p.name AS linked_person_name, p.phone AS linked_person_phone, p.city AS linked_person_city FROM users u LEFT JOIN people p ON p.ID = u.person_id WHERE u.ID = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function hasOtherAccountant(PDO $pdo, int $excludeId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'accountant' AND active = 1 AND ID <> ?");
    $stmt->execute([$excludeId]);
    return (int)$stmt->fetchColumn() > 0;
}

$user = loadManagedUser($pdo, $userId);
if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: admin_users.php');
    exit;
}

$errors = [];
$searchTerm = trim((string)($_POST['donor_search'] ?? ''));
$searchResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_user') {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $lang = (string)($_POST['preferred_language'] ?? 'en');
        $role = (string)($_POST['role'] ?? 'operator');
        $active = isset($_POST['active']) ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Full name is required.';
        }
        if (!in_array($lang, ['en', 'ur', 'it'], true)) {
            $lang = 'en';
        }
        if (!in_array($role, ['admin', 'accountant', 'operator'], true)) {
            $role = (string)$user['role'];
        }
        if ($role === 'accountant') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'accountant' AND ID <> ?");
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Only one accountant is allowed.';
            }
        }
        if ((int)$user['ID'] === (int)$currentUser['ID'] && $active !== 1) {
            $errors[] = 'You cannot disable your own account here.';
        }
        if ((string)$user['role'] === 'accountant' && $active !== 1 && !hasOtherAccountant($pdo, $userId)) {
            $errors[] = 'You cannot disable the only accountant account.';
        }

        if (!$errors) {
            $admin = $role === 'admin' ? 1 : 0;
            $accountant = $role === 'accountant' ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE users SET name = ?, phone = ?, city = ?, preferred_language = ?, role = ?, admin = ?, accountant = ?, active = ? WHERE ID = ?');
            $stmt->execute([$name, $phone, $city, $lang, $role, $admin, $accountant, $active, $userId]);
            systemLog($pdo, (int)$currentUser['ID'], 'user', 'update', 'Simple edit updated user #' . $userId, $userId);
            setFlash('success', 'User updated successfully.');
            header('Location: admin_user_simple_edit.php?id=' . $userId);
            exit;
        }
    }

    if ($action === 'reset_password') {
        $newPassword = (string)($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if (!$errors) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, password = NULL, session_key_hash = NULL WHERE ID = ?');
            $stmt->execute([$hash, $userId]);
            systemLog($pdo, (int)$currentUser['ID'], 'user', 'reset_password', 'Simple edit reset password for user #' . $userId, $userId);
            setFlash('success', 'Password reset successfully.');
            header('Location: admin_user_simple_edit.php?id=' . $userId);
            exit;
        }
    }

    if ($action === 'search_donor') {
        $term = $searchTerm;
        if ($term === '') {
            $errors[] = 'Enter donor name, phone, city or ID.';
        } else {
            $like = '%' . $term . '%';
            if (ctype_digit($term)) {
                $stmt = $pdo->prepare('SELECT ID, name, phone, city FROM people WHERE ID = ? OR name LIKE ? OR phone LIKE ? OR city LIKE ? ORDER BY name ASC LIMIT 50');
                $stmt->execute([(int)$term, $like, $like, $like]);
            } else {
                $stmt = $pdo->prepare('SELECT ID, name, phone, city FROM people WHERE name LIKE ? OR phone LIKE ? OR city LIKE ? ORDER BY name ASC LIMIT 50');
                $stmt->execute([$like, $like, $like]);
            }
            $searchResults = $stmt->fetchAll();
            if (!$searchResults) {
                $errors[] = 'No donor/person found.';
            }
        }
    }

    if ($action === 'link_donor') {
        $personId = (int)($_POST['selected_person_id'] ?? 0);
        if ($personId <= 0) {
            $errors[] = 'Select one donor/person first.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET person_id = ? WHERE ID = ?');
            $stmt->execute([$personId, $userId]);
            systemLog($pdo, (int)$currentUser['ID'], 'user', 'link_person', 'Linked donor/person #' . $personId . ' to user #' . $userId, $userId);
            setFlash('success', 'Donor/person linked successfully.');
            header('Location: admin_user_simple_edit.php?id=' . $userId);
            exit;
        }
    }

    if ($action === 'unlink_donor') {
        $stmt = $pdo->prepare('UPDATE users SET person_id = NULL WHERE ID = ?');
        $stmt->execute([$userId]);
        systemLog($pdo, (int)$currentUser['ID'], 'user', 'unlink_person', 'Removed donor/person link from user #' . $userId, $userId);
        setFlash('success', 'Donor/person link removed.');
        header('Location: admin_user_simple_edit.php?id=' . $userId);
        exit;
    }

    if ($action === 'create_and_link_donor') {
        $donorName = trim((string)($_POST['donor_name'] ?? ''));
        $donorPhone = trim((string)($_POST['donor_phone'] ?? ''));
        $donorCity = trim((string)($_POST['donor_city'] ?? ''));
        $donorNotes = trim((string)($_POST['donor_notes'] ?? ''));
        if ($donorName === '') {
            $errors[] = 'New donor name is required.';
        }
        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO people (name, phone, city, notes, uid, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$donorName, $donorPhone, $donorCity, $donorNotes, $userId, (int)$currentUser['ID'], (int)$currentUser['ID']]);
            $personId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('UPDATE users SET person_id = ? WHERE ID = ?');
            $stmt->execute([$personId, $userId]);
            systemLog($pdo, (int)$currentUser['ID'], 'user', 'create_link_person', 'Created and linked donor/person #' . $personId . ' to user #' . $userId, $userId);
            setFlash('success', 'New donor created and linked successfully.');
            header('Location: admin_user_simple_edit.php?id=' . $userId);
            exit;
        }
    }

    $user = loadManagedUser($pdo, $userId);
}

$flash = getFlash();
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Simple User Edit</h1>
<div class="toolbar">
  <div>
    <div class="muted"><a href="admin_users.php">← Back to user list</a></div>
    <h2 style="margin:4px 0 0 0"><?= e((string)$user['name']) ?> <span class="muted">@<?= e((string)$user['username']) ?></span></h2>
    <div class="muted">Editing selected user ID #<?= (int)$user['ID'] ?></div>
  </div>
  <div class="badge-list">
    <span class="tag <?= (string)$user['role']==='admin' ? 'red' : ((string)$user['role']==='accountant' ? 'green' : 'blue') ?>"><?= e(ucfirst((string)$user['role'])) ?></span>
    <span class="tag <?= (int)$user['active'] === 1 ? 'green' : 'red' ?>"><?= (int)$user['active'] === 1 ? 'Active' : 'Disabled' ?></span>
  </div>
</div>

<?php if ($flash): ?><div class="alert <?= e((string)$flash['type']) ?>"><?= e((string)$flash['message']) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="grid-2">
  <div class="card stack">
    <h2 style="margin-top:0">User Details</h2>
    <form method="post" class="stack">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_user">
      <input type="hidden" name="user_id" value="<?= (int)$user['ID'] ?>">
      <div><label>Full Name</label><input type="text" name="name" value="<?= e((string)$user['name']) ?>" required></div>
      <div class="inline-grid-2">
        <div><label>Phone</label><input type="text" name="phone" value="<?= e((string)($user['phone'] ?? '')) ?>"></div>
        <div><label>City</label><input type="text" name="city" value="<?= e((string)($user['city'] ?? '')) ?>"></div>
      </div>
      <div class="inline-grid-2">
        <div>
          <label>Preferred Language</label>
          <select name="preferred_language">
            <option value="en" <?= (string)($user['preferred_language'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
            <option value="ur" <?= (string)($user['preferred_language'] ?? '') === 'ur' ? 'selected' : '' ?>>Urdu</option>
            <option value="it" <?= (string)($user['preferred_language'] ?? '') === 'it' ? 'selected' : '' ?>>Italian</option>
          </select>
        </div>
        <div>
          <label>Role</label>
          <select name="role">
            <option value="operator" <?= (string)$user['role'] === 'operator' ? 'selected' : '' ?>>Operator</option>
            <option value="accountant" <?= (string)$user['role'] === 'accountant' ? 'selected' : '' ?>>Accountant</option>
            <option value="admin" <?= (string)$user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
          </select>
        </div>
      </div>
      <label><input type="checkbox" name="active" value="1" <?= (int)$user['active'] === 1 ? 'checked' : '' ?>> Active user</label>
      <button class="btn btn-primary" type="submit">Save User</button>
    </form>
  </div>

  <div class="card stack">
    <h2 style="margin-top:0">Password</h2>
    <form method="post" class="stack">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" value="<?= (int)$user['ID'] ?>">
      <div><label>New Password</label><input type="password" name="new_password" minlength="10" required></div>
      <button class="btn" type="submit">Reset Password</button>
    </form>
  </div>
</div>

<div class="grid-2" style="margin-top:16px;">
  <div class="card stack">
    <h2 style="margin-top:0">Linked Donor / Person</h2>
    <?php if ((int)($user['person_id'] ?? 0) > 0): ?>
      <div><strong>Current Linked ID:</strong> #<?= (int)$user['person_id'] ?></div>
      <div><strong>Name:</strong> <?= e((string)($user['linked_person_name'] ?? '')) ?></div>
      <div><strong>Phone:</strong> <?= e((string)($user['linked_person_phone'] ?? '')) ?></div>
      <div><strong>City:</strong> <?= e((string)($user['linked_person_city'] ?? '')) ?></div>
      <div style="margin-top:10px;"><a class="btn" href="person_profile.php?id=<?= (int)$user['person_id'] ?>">Open Donor Profile</a></div>
      <form method="post" style="margin-top:10px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="unlink_donor">
        <input type="hidden" name="user_id" value="<?= (int)$user['ID'] ?>">
        <button class="btn" type="submit">Remove Donor Link</button>
      </form>
    <?php else: ?>
      <div class="muted">No donor/person linked with this user.</div>
    <?php endif; ?>
  </div>

  <div class="card stack">
    <h2 style="margin-top:0">Search Existing Donor / Person</h2>
    <form method="post" class="stack">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="search_donor">
      <input type="hidden" name="user_id" value="<?= (int)$user['ID'] ?>">
      <div><label>Search by donor ID, name, phone or city</label><input type="text" name="donor_search" value="<?= e($searchTerm) ?>" placeholder="Example: 25 or Ahmad or 333"></div>
      <button class="btn" type="submit">Search</button>
    </form>

    <?php if ($searchResults): ?>
      <form method="post" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="link_donor">
        <input type="hidden" name="user_id" value="<?= (int)$user['ID'] ?>">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Select</th><th>ID</th><th>Name</th><th>Phone</th><th>City</th></tr></thead>
            <tbody>
              <?php foreach ($searchResults as $row): ?>
              <tr>
                <td><input type="radio" name="selected_person_id" value="<?= (int)$row['ID'] ?>"></td>
                <td>#<?= (int)$row['ID'] ?></td>
                <td><?= e((string)$row['name']) ?></td>
                <td><?= e((string)($row['phone'] ?? '')) ?></td>
                <td><?= e((string)($row['city'] ?? '')) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button class="btn btn-primary" type="submit">Link Selected Donor</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card stack" style="margin-top:16px;">
  <h2 style="margin-top:0">Create New Donor / Person and Link</h2>
  <form method="post" class="stack">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create_and_link_donor">
    <input type="hidden" name="user_id" value="<?= (int)$user['ID'] ?>">
    <div class="inline-grid-2">
      <div><label>Donor Name</label><input type="text" name="donor_name" value="<?= e((string)$user['name']) ?>" required></div>
      <div><label>Phone</label><input type="text" name="donor_phone" value="<?= e((string)($user['phone'] ?? '')) ?>"></div>
    </div>
    <div class="inline-grid-2">
      <div><label>City</label><input type="text" name="donor_city" value="<?= e((string)($user['city'] ?? '')) ?>"></div>
      <div><label>Notes</label><input type="text" name="donor_notes" placeholder="Optional note"></div>
    </div>
    <button class="btn btn-primary" type="submit">Create New Donor and Link</button>
  </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
