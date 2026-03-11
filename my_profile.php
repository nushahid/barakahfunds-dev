<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$uid = getLoggedInUserId();
$errors = [];
$editableFields = [
    'name' => ['label' => 'Full Name', 'type' => 'text', 'max' => 100],
    'phone' => ['label' => 'Phone', 'type' => 'text', 'max' => 60],
    'city' => ['label' => 'City', 'type' => 'text', 'max' => 120],
    'preferred_language' => ['label' => 'Preferred Language', 'type' => 'select', 'options' => ['en' => 'English', 'it' => 'Italiano', 'ur' => 'Urdu', 'ar' => 'Arabic']],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_profile_field') {
    verifyCsrfOrFail();
    $field = (string)($_POST['field'] ?? '');
    $value = trim((string)($_POST['value'] ?? ''));

    if (!isset($editableFields[$field])) {
        $errors[] = 'Invalid field selected.';
    } else {
        $config = $editableFields[$field];
        if ($config['type'] === 'select') {
            if (!array_key_exists($value, $config['options'])) {
                $errors[] = 'Invalid value selected.';
            }
        } else {
            $value = mb_substr($value, 0, (int)$config['max']);
            if ($field === 'name' && $value === '') {
                $errors[] = 'Name cannot be empty.';
            }
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE users SET ' . $field . ' = ? WHERE ID = ? LIMIT 1');
        $stmt->execute([$value !== '' ? $value : null, $uid]);
        if (function_exists('systemLog')) {
            systemLog($pdo, $uid, 'profile', 'update', 'Updated own profile field: ' . $field, $uid);
        }
        setFlash('success', $editableFields[$field]['label'] . ' updated successfully.');
        header('Location: my_profile.php');
        exit;
    }
}

$user = currentUser($pdo);
$role = currentRole($pdo);
$collected = queryValue($pdo,'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE operator_id = ? AND amount > 0',[$uid]);
$expenses = abs(queryValue($pdo,'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE operator_id = ? AND transaction_category = "expense"',[$uid]));
$pendingSent = operatorPendingTransfers($pdo,$uid);
$acceptedSent = tableExists($pdo,'balance_transfers') ? queryValue($pdo,'SELECT COALESCE(SUM(amount),0) FROM balance_transfers WHERE from_user_id = ? AND status = "accepted"',[$uid]) : 0;
$acceptedReceived = tableExists($pdo,'balance_transfers') ? queryValue($pdo,'SELECT COALESCE(SUM(amount),0) FROM balance_transfers WHERE to_user_id = ? AND status = "accepted"',[$uid]) : 0;
$logs = tableExists($pdo,'system_logs') ? $pdo->prepare('SELECT * FROM system_logs WHERE user_id = ? ORDER BY ID DESC LIMIT 20') : null;
if($logs){ $logs->execute([$uid]); $logs = $logs->fetchAll(); } else { $logs=[]; }

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">My Profile</h1>
<div class="grid-2 profile-layout-v6">
  <div class="stack">
    <div class="card profile-card-v6">
      <div class="profile-head-v6">
        <div>
          <h2 class="profile-name-v6"><?= e((string)$user['name']) ?></h2>
          <div class="muted">@<?= e((string)$user['username']) ?> · <?= e(roleLabel($role)) ?></div>
        </div>
      </div>

      <?php if ($errors): ?>
        <div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
      <?php endif; ?>

      <?php foreach ($editableFields as $field => $config): ?>
        <?php $currentValue = (string)($user[$field] ?? ''); ?>
        <div class="profile-field-row-v6" data-profile-field="<?= e($field) ?>">
          <div class="profile-field-view-v6">
            <div>
              <div class="profile-field-label-v6"><?= e($config['label']) ?></div>
              <div class="profile-field-value-v6"><?= $currentValue !== '' ? e($config['type'] === 'select' ? ($config['options'][$currentValue] ?? $currentValue) : $currentValue) : '<span class="muted">Not set</span>' ?></div>
            </div>
            <button class="icon-btn profile-edit-btn-v6" type="button" onclick="toggleProfileField('<?= e($field) ?>', true)" aria-label="Edit <?= e($config['label']) ?>">✏️</button>
          </div>
          <form method="post" class="profile-field-form-v6" hidden>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_profile_field">
            <input type="hidden" name="field" value="<?= e($field) ?>">
            <?php if ($config['type'] === 'select'): ?>
              <select name="value" required>
                <?php foreach ($config['options'] as $optValue => $optLabel): ?>
                  <option value="<?= e($optValue) ?>" <?= $currentValue === $optValue ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="text" name="value" value="<?= e($currentValue) ?>" maxlength="<?= (int)$config['max'] ?>" <?= $field === 'name' ? 'required' : '' ?>>
            <?php endif; ?>
            <div class="profile-field-actions-v6">
              <button class="btn btn-primary" type="submit">Save</button>
              <button class="btn" type="button" onclick="toggleProfileField('<?= e($field) ?>', false)">Cancel</button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h2 style="margin-top:0">Performance</h2>
      <div class="stat-list">
        <div class="stat-box"><strong>Collected</strong><span><?= money($collected) ?></span></div>
        <div class="stat-box"><strong>Expenses</strong><span><?= money($expenses) ?></span></div>
        <div class="stat-box"><strong>Accepted Sent</strong><span><?= money($acceptedSent) ?></span></div>
        <div class="stat-box"><strong>Accepted Received</strong><span><?= money($acceptedReceived) ?></span></div>
        <div class="stat-box"><strong>Pending Transfer</strong><span><?= money($pendingSent) ?></span></div>
        <div class="stat-box"><strong>Current Balance</strong><span><?= money($role==='operator'?operatorBalance($pdo,$uid):getAccountantCashInHand($pdo)) ?></span></div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Recent Activity</h2>
    <?php foreach($logs as $row): ?>
      <div class="meter-row">
        <div><strong><?= e((string)$row['module_name']) ?></strong><br><span class="muted"><?= e((string)$row['action_name']) ?></span></div>
        <div><?= e((string)$row['created_at']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if(!$logs): ?><div class="muted">No activity logs.</div><?php endif; ?>
  </div>
</div>
<script>
function toggleProfileField(field, open) {
  const row = document.querySelector('[data-profile-field="' + field + '"]');
  if (!row) return;
  const form = row.querySelector('.profile-field-form-v6');
  const view = row.querySelector('.profile-field-view-v6');
  if (!form || !view) return;
  form.hidden = !open;
  view.hidden = open;
  if (open) {
    const input = form.querySelector('input, select');
    if (input) input.focus();
  }
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
