<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAdmin($pdo);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$currentUser = currentUser($pdo);
$requestedUserId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0);

function managedUserRow(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT ID, name, username, role, admin, accountant, phone, city, preferred_language, active, last_login_at FROM users WHERE ID = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function managedPendingTransfers(PDO $pdo, int $userId): int
{
    if (!tableExists($pdo, 'balance_transfers')) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM balance_transfers WHERE (from_user_id = ? OR to_user_id = ?) AND status = "pending"');
    $stmt->execute([$userId, $userId]);
    return (int)$stmt->fetchColumn();
}

function otherAccountantExists(PDO $pdo, int $excludeId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'accountant' AND active = 1 AND ID <> ?");
    $stmt->execute([$excludeId]);
    return (int)$stmt->fetchColumn() > 0;
}

$userId = $requestedUserId;

if ($userId <= 0) {
    setFlash('error', 'User not found.');
    header('Location: admin_users.php');
    exit;
}

$user = managedUserRow($pdo, $userId);
if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: admin_users.php');
    exit;
}

$errors = [];
$isAdminAccount = (string)$user['role'] === 'admin' || (int)($user['admin'] ?? 0) === 1;
$isCurrentUser = (int)$user['ID'] === (int)$currentUser['ID'];
$operatorBalance = operatorBalance($pdo, (int)$user['ID']);
$pendingTransfers = managedPendingTransfers($pdo, (int)$user['ID']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_user') {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $lang = (string)($_POST['preferred_language'] ?? 'en');
        $newRole = (string)($_POST['role'] ?? $user['role']);

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!in_array($lang, ['en', 'ur', 'it'], true)) {
            $lang = 'en';
        }
        if (!in_array($newRole, ['admin', 'accountant', 'operator'], true)) {
            $newRole = (string)$user['role'];
        }

        if ($isAdminAccount && $newRole !== 'admin') {
            $errors[] = 'Admin account role is locked and cannot be changed.';
        }

        if (!$isAdminAccount && $newRole === 'accountant') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'accountant' AND ID <> ?");
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Only one accountant is allowed.';
            }
        }

        if ((string)$user['role'] === 'operator' && $newRole !== 'operator') {
            if (abs($operatorBalance) > 0.009) {
                $errors[] = 'This operator still has balance. Transfer it first.';
            }
            if ($pendingTransfers > 0) {
                $errors[] = 'This operator still has pending transfers.';
            }
        }

        if ((string)$user['role'] === 'accountant' && $newRole !== 'accountant' && !otherAccountantExists($pdo, $userId)) {
            $errors[] = 'You must keep one separate accountant account active.';
        }

        if (!$errors) {
            $admin = $newRole === 'admin' ? 1 : 0;
            $acc = $newRole === 'accountant' ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE users SET name = ?, phone = ?, city = ?, preferred_language = ?, role = ?, admin = ?, accountant = ? WHERE ID = ?');
            $stmt->execute([$name, $phone, $city, $lang, $newRole, $admin, $acc, $userId]);
            systemLog($pdo, (int)$currentUser['ID'], 'user', 'update', 'Updated user #' . $userId, $userId);
            setFlash('success', 'User updated.');
            header('Location: admin_user_edit.php?id=' . $userId . '&saved=1');
            exit;
        }
    }

    if ($action === 'toggle_active') {
        $newStatus = (int)($_POST['new_status'] ?? 0) === 1 ? 1 : 0;
        if ($isCurrentUser) {
            $errors[] = 'You cannot disable your own admin account here.';
        }
        if ((string)$user['role'] === 'accountant' && $newStatus === 0 && !otherAccountantExists($pdo, $userId)) {
            $errors[] = 'You cannot disable the only accountant account.';
        }
        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE users SET active = ? WHERE ID = ?');
            $stmt->execute([$newStatus, $userId]);
            systemLog($pdo, (int)$currentUser['ID'], 'user', $newStatus ? 'activate' : 'deactivate', 'Changed active status for user #' . $userId, $userId);
            setFlash('success', $newStatus ? 'User enabled.' : 'User disabled.');
            header('Location: admin_user_edit.php?id=' . $userId . '&active=1');
            exit;
        }
    }

    if ($action === 'reset_password') {
        $password = (string)($_POST['new_password'] ?? '');
        if (strlen($password) < 10) {
            $errors[] = 'New password must be at least 10 characters.';
        }
        if (!$errors) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, password = NULL, session_key_hash = NULL WHERE ID = ?');
            $stmt->execute([$hash, $userId]);
            systemLog($pdo, (int)$currentUser['ID'], 'user', 'reset_password', 'Reset password for user #' . $userId, $userId);
            setFlash('success', 'Password reset successfully.');
            header('Location: admin_user_edit.php?id=' . $userId . '&pwd=1');
            exit;
        }
    }

    $user = managedUserRow($pdo, $userId);
    $isAdminAccount = (string)$user['role'] === 'admin' || (int)($user['admin'] ?? 0) === 1;
    $isCurrentUser = (int)$user['ID'] === (int)$currentUser['ID'];
    $operatorBalance = operatorBalance($pdo, (int)$user['ID']);
    $pendingTransfers = managedPendingTransfers($pdo, (int)$user['ID']);
}

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Manage User</h1>
<div class="helper" style="margin-bottom:12px">This page now always loads the selected user from the <code>id</code> in the URL or submitted form. It does not auto-switch to the logged-in admin.</div>
<div class="toolbar">
  <div>
    <div class="muted"><a href="admin_users.php">← Back to user list</a></div>
    <h2 style="margin:4px 0 0 0"><?= e((string)$user['name']) ?> <span class="muted">@<?= e((string)$user['username']) ?></span></h2>
    <div class="muted">Editing user ID #<?= (int)$user['ID'] ?><?php if ($requestedUserId > 0): ?> · Requested ID #<?= (int)$requestedUserId ?><?php endif; ?></div>
  </div>
  <div class="badge-list">
    <span class="tag <?= (string)$user['role']==='admin' ? 'red' : ((string)$user['role']==='accountant' ? 'green' : 'blue') ?>"><?= e(ucfirst((string)$user['role'])) ?></span>
    <span class="tag <?= (int)$user['active'] === 1 ? 'green' : 'red' ?>"><?= (int)$user['active'] === 1 ? 'Active' : 'Disabled' ?></span>
  </div>
</div>

<?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="grid-2">
  <div class="card stack">
    <h2 style="margin-top:0">User Details</h2>
    <div class="helper">
      <?php if ($isAdminAccount): ?>
        This is an admin account. Its role is locked for safety.
      <?php elseif ((string)$user['role'] === 'operator'): ?>
        Role change is blocked until operator balance is zero and pending transfers are resolved.
      <?php else: ?>
        Open this page only when you need to make a real change.
      <?php endif; ?>
    </div>
    <form method="post" class="stack" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_user">
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
            <option value="en" <?= (string)($user['preferred_language'] ?? 'en')==='en'?'selected':'' ?>>English</option>
            <option value="ur" <?= (string)($user['preferred_language'] ?? '')==='ur'?'selected':'' ?>>Urdu</option>
            <option value="it" <?= (string)($user['preferred_language'] ?? '')==='it'?'selected':'' ?>>Italian</option>
          </select>
        </div>
        <div>
          <label>Role</label>
          <select name="role" <?= $isAdminAccount ? 'disabled' : '' ?>>
            <option value="operator" <?= (string)$user['role']==='operator'?'selected':'' ?>>Operator</option>
            <option value="accountant" <?= (string)$user['role']==='accountant'?'selected':'' ?>>Accountant</option>
            <option value="admin" <?= (string)$user['role']==='admin'?'selected':'' ?>>Admin</option>
          </select>
          <?php if ($isAdminAccount): ?><input type="hidden" name="role" value="admin"><?php endif; ?>
        </div>
      </div>
      <button class="btn btn-primary" type="submit">Save Details</button>
    </form>
  </div>

  <div class="card stack">
    <h2 style="margin-top:0">Safety & Actions</h2>
    <div class="stat-list">
      <div class="stat-box"><strong>User ID</strong><span>#<?= (int)$user['ID'] ?></span></div>
      <div class="stat-box"><strong>Username</strong><span style="font-size:18px"><?= e((string)$user['username']) ?></span></div>
      <div class="stat-box"><strong>Operator Balance</strong><span><?= money($operatorBalance) ?></span></div>
      <div class="stat-box"><strong>Pending Transfers</strong><span><?= (int)$pendingTransfers ?></span></div>
      <div class="stat-box"><strong>Last Login</strong><span style="font-size:18px"><?= e((string)($user['last_login_at'] ?? '—')) ?></span></div>
    </div>

    <form method="post" class="stack compact" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="toggle_active">
      <input type="hidden" name="user_id" value="<?= (int)$user['ID'] ?>">
      <input type="hidden" name="new_status" value="<?= (int)$user['active'] === 1 ? 0 : 1 ?>">
      <button class="btn" type="submit"><?= (int)$user['active'] === 1 ? 'Disable This User' : 'Enable This User' ?></button>
    </form>

    <form method="post" class="stack compact" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" value="<?= (int)$user['ID'] ?>">
      <div><label>Reset Password for @<?= e((string)$user['username']) ?></label><input type="password" name="new_password" minlength="10" placeholder="Enter new password" required></div>
      <button class="btn btn-primary" type="submit">Reset Password Now</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
