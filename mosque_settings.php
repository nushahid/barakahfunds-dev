<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireAdmin($pdo);

$errors = [];
$logoPath = receiptLogoPath($pdo);
$values = [
    'mosque_name' => getSetting($pdo, 'mosque_name', 'BarakahFunds v4'),
    'mosque_address' => getSetting($pdo, 'mosque_address', ''),
    'mosque_phone' => getSetting($pdo, 'mosque_phone', ''),
    'receipt_prefix' => getSetting($pdo, 'receipt_prefix', 'INV'),
    'default_language' => getSetting($pdo, 'default_language', 'en'),
    'receipt_footer' => getSetting($pdo, 'receipt_footer', 'Thank you for supporting the mosque.'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();

    foreach ($values as $key => $old) {
        $values[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if ($values['mosque_name'] === '') {
        $errors[] = 'Mosque name is required.';
    }
    if ($values['receipt_prefix'] === '') {
        $errors[] = 'Receipt prefix is required.';
    }

    $upload = uploadReceiptLogo($_FILES['receipt_logo'] ?? [], false);
    if (!$upload['ok']) {
        $errors[] = $upload['message'];
    }

    if (!$errors) {
        foreach ($values as $key => $value) {
            setSetting($pdo, $key, $value);
        }
        if (!empty($upload['path'])) {
            setSetting($pdo, 'receipt_logo_path', $upload['path']);
            $logoPath = $upload['path'];
        }
        setFlash('success', 'Mosque settings saved.');
        header('Location: mosque_settings.php');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Mosque Settings</h1>
<div class="card stack">
  <?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="stack compact">
    <?= csrfField() ?>
    <div class="inline-grid-2">
      <div><label>Mosque Name</label><input type="text" name="mosque_name" value="<?= e($values['mosque_name']) ?>" required></div>
      <div><label>Mosque Phone</label><input type="text" name="mosque_phone" value="<?= e($values['mosque_phone']) ?>"></div>
    </div>
    <div><label>Mosque Address</label><input type="text" name="mosque_address" value="<?= e($values['mosque_address']) ?>"></div>
    <div class="inline-grid-2">
      <div><label>Receipt Prefix</label><input type="text" name="receipt_prefix" value="<?= e($values['receipt_prefix']) ?>"></div>
      <div><label>Default Language</label><select name="default_language"><option value="en" <?= $values['default_language']==='en'?'selected':'' ?>>English</option><option value="ur" <?= $values['default_language']==='ur'?'selected':'' ?>>Urdu</option><option value="it" <?= $values['default_language']==='it'?'selected':'' ?>>Italian</option></select></div>
    </div>
    <div>
      <label>Receipt Logo</label>
      <input type="file" name="receipt_logo" accept="image/jpeg,image/png,image/webp">
      <div class="muted">This logo will appear at the top of printed receipts.</div>
      <?php if ($logoPath !== ''): ?><div style="margin-top:10px;"><img src="<?= e($logoPath) ?>" alt="Receipt Logo" style="max-height:90px;max-width:220px"></div><?php endif; ?>
    </div>
    <div><label>Receipt Footer</label><textarea name="receipt_footer"><?= e($values['receipt_footer']) ?></textarea></div>
    <button class="btn btn-primary" type="submit">Save Settings</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
