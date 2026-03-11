<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAdmin($pdo);

$currentUser = currentUser($pdo);
$errors = [];
$editUserId = (int)($_GET['edit'] ?? $_POST['edit_user_id'] ?? 0);
$editSearch = trim((string)($_GET['link_q'] ?? $_POST['link_q'] ?? ''));

function adminUsersRedirect(?int $editUserId = null, string $extra = ''): void
{
    $url = 'admin_users.php';
    if ($editUserId && $editUserId > 0) {
        $url .= '?edit=' . $editUserId;
        if ($extra !== '') {
            $url .= '&' . $extra;
        }
        $url .= '#edit-user-' . $editUserId;
    }
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $name = trim((string)($_POST['name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'operator');
        $phone = trim((string)($_POST['phone'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $lang = (string)($_POST['preferred_language'] ?? 'en');

        if ($name === '' || $username === '' || $password === '') {
            $errors[] = 'Name, username and password are required.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }

        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $exists->execute([$username]);
        if ((int)$exists->fetchColumn() > 0) {
            $errors[] = 'Username already exists.';
        }

        if ($role === 'accountant') {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'accountant'")->fetchColumn();
            if ($count >= 1) {
                $errors[] = 'Only one accountant is allowed in this mosque installation.';
            }
        }

        if (!$errors) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $admin = $role === 'admin' ? 1 : 0;
            $acc = $role === 'accountant' ? 1 : 0;
            $sql = 'INSERT INTO users (name, username, password_hash, admin, accountant, role, phone, city, preferred_language, balance, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NOW())';
            $pdo->prepare($sql)->execute([$name, $username, $hash, $admin, $acc, $role, $phone, $city, $lang]);
            systemLog($pdo, (int)$currentUser['ID'], 'user', 'create', 'Created ' . $username);
            setFlash('success', 'User created.');
            header('Location: admin_users.php');
            exit;
        }
    }

    if ($action === 'toggle_active') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0) === 1 ? 1 : 0;
        if ($userId > 0 && $userId !== (int)$currentUser['ID']) {
            $stmt = $pdo->prepare('UPDATE users SET active = ? WHERE ID = ?');
            $stmt->execute([$newStatus, $userId]);
            systemLog($pdo, (int)$currentUser['ID'], 'user', $newStatus ? 'activate' : 'deactivate', 'User #' . $userId, $userId);
            setFlash('success', $newStatus ? 'User activated.' : 'User deactivated.');
        }
        adminUsersRedirect($editUserId > 0 ? $editUserId : null);
    }

    if ($action === 'save_inline_user') {
        $userId = (int)($_POST['edit_user_id'] ?? 0);
        $name = trim((string)($_POST['edit_name'] ?? ''));
        $phone = trim((string)($_POST['edit_phone'] ?? ''));
        $city = trim((string)($_POST['edit_city'] ?? ''));
        $lang = (string)($_POST['edit_preferred_language'] ?? 'en');
        $role = (string)($_POST['edit_role'] ?? 'operator');
        $active = (int)($_POST['edit_active'] ?? 1) === 1 ? 1 : 0;
        $selectedPersonId = (int)($_POST['selected_person_id'] ?? 0);
        $newPassword = (string)($_POST['edit_password'] ?? '');

        if ($userId <= 0) {
            $errors[] = 'Invalid user selected.';
        }
        if ($name === '') {
            $errors[] = 'Full name is required.';
        }
        if (!in_array($role, ['admin', 'accountant', 'operator'], true)) {
            $errors[] = 'Invalid role selected.';
        }
        if ($newPassword !== '' && strlen($newPassword) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if ($role === 'accountant') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'accountant' AND ID <> ?");
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() >= 1) {
                $errors[] = 'Only one accountant is allowed in this mosque installation.';
            }
        }
        if ($selectedPersonId > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM people WHERE ID = ?');
            $stmt->execute([$selectedPersonId]);
            if ((int)$stmt->fetchColumn() === 0) {
                $errors[] = 'Selected donor/person was not found.';
            }
        }

        if (!$errors) {
            $admin = $role === 'admin' ? 1 : 0;
            $acc = $role === 'accountant' ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE users SET name = ?, phone = ?, city = ?, preferred_language = ?, role = ?, admin = ?, accountant = ?, active = ?, person_id = ? WHERE ID = ?');
            $stmt->execute([$name, $phone, $city, $lang, $role, $admin, $acc, $active, $selectedPersonId > 0 ? $selectedPersonId : null, $userId]);

            if ($newPassword !== '') {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE ID = ?');
                $stmt->execute([$hash, $userId]);
            }

            systemLog($pdo, (int)$currentUser['ID'], 'user', 'update', 'Updated user #' . $userId, $userId);
            setFlash('success', 'User updated successfully.');
            adminUsersRedirect($userId);
        }
        $editUserId = $userId;
        $editSearch = trim((string)($_POST['link_q'] ?? ''));
    }
}

$accountantCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'accountant'")->fetchColumn();
$users = $pdo->query('SELECT u.ID, u.name, u.username, u.role, u.phone, u.city, u.person_id, u.preferred_language, u.active, u.last_login_at, p.name AS linked_person_name FROM users u LEFT JOIN people p ON p.ID = u.person_id ORDER BY u.ID DESC')->fetchAll();

$editUser = null;
$linkResults = [];
if ($editUserId > 0) {
    $stmt = $pdo->prepare('SELECT u.*, p.name AS linked_person_name FROM users u LEFT JOIN people p ON p.ID = u.person_id WHERE u.ID = ? LIMIT 1');
    $stmt->execute([$editUserId]);
    $editUser = $stmt->fetch();

    if ($editUser && $editSearch !== '') {
        $like = '%' . $editSearch . '%';
        if (ctype_digit($editSearch)) {
            $stmt = $pdo->prepare('SELECT ID, name, phone, city FROM people WHERE ID = ? OR name LIKE ? OR phone LIKE ? OR city LIKE ? ORDER BY name ASC LIMIT 25');
            $stmt->execute([(int)$editSearch, $like, $like, $like]);
        } else {
            $stmt = $pdo->prepare('SELECT ID, name, phone, city FROM people WHERE name LIKE ? OR phone LIKE ? OR city LIKE ? ORDER BY name ASC LIMIT 25');
            $stmt->execute([$like, $like, $like]);
        }
        $linkResults = $stmt->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.admin-users-grid{display:grid;grid-template-columns:minmax(320px,430px) minmax(0,1fr);gap:20px}.admin-user-inline{background:#f8fbff;border:1px solid #d9e4f2;border-radius:20px;padding:18px;margin:12px 0}.admin-user-inline .inline-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.admin-link-search-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center}.admin-link-search-result{display:flex;justify-content:space-between;align-items:center;border:1px solid #e5e7eb;border-radius:16px;padding:12px 14px;background:#fff;margin-top:10px;gap:12px}.admin-link-search-result strong{display:block}.admin-current-link{font-weight:700;margin:8px 0 12px}.admin-inline-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.admin-selected-pill{display:inline-flex;align-items:center;gap:8px;background:#eef6ff;border:1px solid #cfe2ff;border-radius:999px;padding:8px 12px;font-weight:600;margin-top:10px}.admin-users-table td{vertical-align:top}.admin-users-table .btn{margin-right:8px}.admin-user-cell small{display:block;color:#64748b}.admin-edit-btn{white-space:nowrap}@media (max-width:960px){.admin-users-grid{grid-template-columns:1fr}.admin-user-inline .inline-grid-2,.admin-link-search-row{grid-template-columns:1fr}}
</style>
<h1 class="title">Users</h1>
<div class="admin-users-grid">
  <div class="card stack">
    <?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($flash = getFlash('success')): ?><div class="alert success"><?= e($flash) ?></div><?php endif; ?>
    <div class="helper">Only one accountant can exist. Operators are the only users allowed to work with donors and collect donations.</div>
    <form method="post" class="stack">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_user">
      <div class="inline-grid-2">
        <div><label>Full Name</label><input type="text" name="name" required></div>
        <div><label>Username</label><input type="text" name="username" required></div>
      </div>
      <div class="inline-grid-2">
        <div><label>Password</label><input type="password" name="password" minlength="10" required></div>
        <div>
          <label>Role</label>
          <select name="role">
            <option value="operator">Operator</option>
            <?php if ($accountantCount === 0): ?><option value="accountant">Accountant</option><?php endif; ?>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="inline-grid-2">
        <div><label>Phone</label><input type="text" name="phone"></div>
        <div><label>City</label><input type="text" name="city"></div>
      </div>
      <div><label>Preferred Language</label><select name="preferred_language"><option value="en">English</option><option value="ur">Urdu</option><option value="it">Italian</option></select></div>
      <button class="btn btn-primary" type="submit">Create User</button>
    </form>
  </div>
  <div class="card">
    <h2 style="margin-top:0">User List</h2>
    <div class="table-wrap">
      <table class="admin-users-table">
        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Phone</th><th>Status</th><th>Last Login</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
          <tr id="user-row-<?= (int)$user['ID'] ?>">
            <td class="admin-user-cell"><strong><?= e((string)$user['name']) ?></strong><?php if (!empty($user['linked_person_name'])): ?><small>Linked donor: <?= e((string)$user['linked_person_name']) ?> #<?= (int)$user['person_id'] ?></small><?php endif; ?></td>
            <td><?= e((string)$user['username']) ?></td>
            <td><span class="tag blue"><?= e(roleLabel((string)$user['role'])) ?></span></td>
            <td><?= e((string)$user['phone']) ?></td>
            <td><span class="tag <?= (int)$user['active'] === 1 ? 'green' : 'red' ?>"><?= (int)$user['active'] === 1 ? 'Active' : 'Disabled' ?></span></td>
            <td><?= e((string)($user['last_login_at'] ?? '—')) ?></td>
            <td>
              <a class="btn admin-edit-btn" href="admin_users.php?edit=<?= (int)$user['ID'] ?>#edit-user-<?= (int)$user['ID'] ?>">✏ Edit</a>
              <?php if ((int)$user['ID'] !== (int)$currentUser['ID']): ?>
              <form method="post" style="display:inline-block">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= (int)$user['ID'] ?>">
                <input type="hidden" name="new_status" value="<?= (int)$user['active'] === 1 ? 0 : 1 ?>">
                <button class="btn" type="submit"><?= (int)$user['active'] === 1 ? 'Disable' : 'Enable' ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($editUser && (int)$editUser['ID'] === (int)$user['ID']): ?>
          <tr id="edit-user-<?= (int)$user['ID'] ?>">
            <td colspan="7">
              <div class="admin-user-inline">
                <h3 style="margin-top:0">Edit <?= e((string)$editUser['name']) ?> (#<?= (int)$editUser['ID'] ?>)</h3>
                <form method="post" action="admin_users.php?edit=<?= (int)$editUser['ID'] ?>#edit-user-<?= (int)$editUser['ID'] ?>" class="stack">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="save_inline_user">
                  <input type="hidden" name="edit_user_id" value="<?= (int)$editUser['ID'] ?>">
                  <div class="inline-grid-2">
                    <div><label>Full Name</label><input type="text" name="edit_name" value="<?= e((string)$editUser['name']) ?>" required></div>
                    <div><label>Phone</label><input type="text" name="edit_phone" value="<?= e((string)($editUser['phone'] ?? '')) ?>"></div>
                  </div>
                  <div class="inline-grid-2">
                    <div><label>City</label><input type="text" name="edit_city" value="<?= e((string)($editUser['city'] ?? '')) ?>"></div>
                    <div><label>Preferred Language</label><select name="edit_preferred_language"><option value="en" <?= (string)$editUser['preferred_language'] === 'en' ? 'selected' : '' ?>>English</option><option value="ur" <?= (string)$editUser['preferred_language'] === 'ur' ? 'selected' : '' ?>>Urdu</option><option value="it" <?= (string)$editUser['preferred_language'] === 'it' ? 'selected' : '' ?>>Italian</option></select></div>
                  </div>
                  <div class="inline-grid-2">
                    <div><label>Role</label><select name="edit_role"><option value="operator" <?= (string)$editUser['role'] === 'operator' ? 'selected' : '' ?>>Operator</option><option value="accountant" <?= (string)$editUser['role'] === 'accountant' ? 'selected' : '' ?>>Accountant</option><option value="admin" <?= (string)$editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select></div>
                    <div><label>Status</label><select name="edit_active"><option value="1" <?= (int)$editUser['active'] === 1 ? 'selected' : '' ?>>Active</option><option value="0" <?= (int)$editUser['active'] !== 1 ? 'selected' : '' ?>>Disabled</option></select></div>
                  </div>
                  <div><label>New Password</label><input type="password" name="edit_password" minlength="10" placeholder="Leave blank to keep current password"></div>

                  <div>
                    <label>Connect user with donor/person</label>
                    <div class="helper">Search by donor ID, name, phone or city. Select one result, then save changes.</div>
                    <div class="admin-current-link">Current link: <?php if ((int)($editUser['person_id'] ?? 0) > 0): ?>#<?= (int)$editUser['person_id'] ?> · <?= e((string)($editUser['linked_person_name'] ?? 'Linked donor')) ?><?php else: ?>No donor linked<?php endif; ?></div>
                    <input type="hidden" name="selected_person_id" id="selected_person_id_<?= (int)$editUser['ID'] ?>" value="<?= (int)($editUser['person_id'] ?? 0) ?>">
                    <div class="admin-link-search-row">
                      <input type="text" name="link_q" value="<?= e($editSearch) ?>" placeholder="Search donor by ID, name, phone or city">
                      <button class="btn btn-primary" type="submit" name="action" value="search_link_person">Search</button>
                    </div>
                    <?php if ((int)($editUser['person_id'] ?? 0) > 0): ?>
                      <div class="admin-selected-pill" id="selected_person_label_<?= (int)$editUser['ID'] ?>">Selected donor: #<?= (int)$editUser['person_id'] ?> · <?= e((string)($editUser['linked_person_name'] ?? '')) ?></div>
                    <?php else: ?>
                      <div class="admin-selected-pill" id="selected_person_label_<?= (int)$editUser['ID'] ?>" style="display:none"></div>
                    <?php endif; ?>
                    <?php if ($editSearch !== ''): ?>
                      <div style="margin-top:12px">
                        <?php if ($linkResults): foreach ($linkResults as $person): ?>
                          <div class="admin-link-search-result">
                            <div>
                              <strong>#<?= (int)$person['ID'] ?> · <?= e((string)$person['name']) ?></strong>
                              <small><?= e((string)($person['phone'] ?: '—')) ?><?= !empty($person['city']) ? ' · ' . e((string)$person['city']) : '' ?></small>
                            </div>
                            <button type="button" class="btn" onclick="selectLinkedPerson(<?= (int)$editUser['ID'] ?>, <?= (int)$person['ID'] ?>, <?= json_encode('#' . (int)$person['ID'] . ' · ' . (string)$person['name']) ?>)">Select</button>
                          </div>
                        <?php endforeach; else: ?>
                          <div class="muted" style="margin-top:10px">No donor/person found for your search.</div>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="admin-inline-actions">
                    <button class="btn btn-primary" type="submit" name="action" value="save_inline_user">Save Changes</button>
                    <a class="btn" href="admin_users.php">Close</a>
                  </div>
                </form>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
function selectLinkedPerson(userId, personId, label) {
  var input = document.getElementById('selected_person_id_' + userId);
  var box = document.getElementById('selected_person_label_' + userId);
  if (input) input.value = personId;
  if (box) {
    box.style.display = 'inline-flex';
    box.textContent = 'Selected donor: ' + label;
  }
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
