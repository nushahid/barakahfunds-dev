<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

requireRole($pdo, ['accountant', 'admin']);

$uid = getLoggedInUserId();
$errors = [];
$statusCol = columnExists($pdo, 'events', 'status');
$editId = (int)($_GET['edit'] ?? 0);

$formData = [
    'name' => '',
    'estimate' => '',
    'description' => '',
    'status' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();

    $action = (string)($_POST['action'] ?? 'create');
    $eventId = (int)($_POST['event_id'] ?? 0);

    if ($action === 'toggle' && $statusCol && $eventId > 0) {
        $newStatus = (int)($_POST['new_status'] ?? 1) === 1 ? 1 : 0;

        $pdo->prepare('UPDATE events SET status = ? WHERE ID = ?')
            ->execute([$newStatus, $eventId]);

        systemLog($pdo, $uid, 'event', 'toggle', 'Event status ' . $newStatus, $eventId);
        setFlash('success', 'Event status updated.');

        $redirect = 'event_page.php';
        if ((int)($_POST['return_edit'] ?? 0) > 0) {
            $redirect .= '?edit=' . $eventId;
        }

        header('Location: ' . $redirect);
        exit;
    }

  $name = trim((string)($_POST['name'] ?? ''));
$estimated = (float)($_POST['estimate'] ?? 0);
$notes = trim((string)($_POST['description'] ?? ''));
$status = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;

$formData = [
    'name' => $name,
    'estimate' => (string)($_POST['estimate'] ?? ''),
    'description' => $notes,
    'status' => $status,
];

    if ($name === '') {
        $errors[] = 'Event name is required.';
    }

    if (!$errors) {
        if ($action === 'update' && $eventId > 0) {
            if ($statusCol) {
                $pdo->prepare('UPDATE events SET name = ?, estimated = ?, notes = ?, status = ? WHERE ID = ?')
                    ->execute([$name, $estimated, $notes, $status, $eventId]);
            } else {
                $pdo->prepare('UPDATE events SET name = ?, estimated = ?, notes = ? WHERE ID = ?')
                    ->execute([$name, $estimated, $notes, $eventId]);
            }

            systemLog($pdo, $uid, 'event', 'update', $name, $eventId);
            setFlash('success', 'Event updated.');
        } else {
            if ($statusCol) {
                $pdo->prepare('INSERT INTO events (name, estimated, notes, status, uid, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
                    ->execute([$name, $estimated, $notes, $status, $uid]);
            } else {
                $pdo->prepare('INSERT INTO events (name, estimated, notes, uid, created_at) VALUES (?, ?, ?, ?, NOW())')
                    ->execute([$name, $estimated, $notes, $uid]);
            }

            systemLog($pdo, $uid, 'event', 'create', $name);
            setFlash('success', 'Event saved.');
        }

        header('Location: event_page.php');
        exit;
    }
}

$events = $pdo->query('
    SELECT 
        e.*,
        COALESCE((
            SELECT SUM(amount)
            FROM operator_ledger ol
            WHERE ol.reference_id = e.ID
              AND ol.transaction_category = "event"
        ), 0) AS collected
    FROM events e
    ORDER BY e.ID DESC
')->fetchAll();

$editRow = null;
foreach ($events as $event) {
    if ((int)$event['ID'] === $editId) {
        $editRow = $event;
        break;
    }
}

if ($editRow && !$errors) {
    $formData = [
        'name' => (string)($editRow['name'] ?? ''),
        'estimate' => (string)($editRow['estimated'] ?? ''),
        'description' => (string)($editRow['notes'] ?? ''),
        'status' => (int)($editRow['status'] ?? 1),
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Events</h1>

<?php if ($errors): ?>
  <div class="alert error">
    <?php foreach ($errors as $er): ?>
      <div><?= e($er) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="event-page-wrap">
  <section class="card stack event-form-card">
    <div class="toolbar event-card-head">
      <div>
        <h2 class="event-subtitle"><?= $editRow ? 'Edit Event' : 'New Event' ?></h2>
        <p class="muted event-helper">Create a new event or update an existing one.</p>
      </div>
      <?php if ($editRow): ?>
        <a class="btn" href="event_page.php">Cancel</a>
      <?php endif; ?>
    </div>

    <form method="post" class="stack event-form-grid">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">

      <?php if ($editRow): ?>
        <input type="hidden" name="event_id" value="<?= (int)$editRow['ID'] ?>">
      <?php endif; ?>

      <div>
        <label for="event-name">Event Name</label>
        <input id="event-name" type="text" name="name" value="<?= e($formData['name']) ?>" required>
      </div>

      <div>
        <label for="event-estimate">Target Amount</label>
        <input id="event-estimate" type="number" step="0.01" name="estimate" value="<?= e($formData['estimate']) ?>">
      </div>

      <div>
        <label for="event-description">Description</label>
        <textarea id="event-description" name="description" rows="5"><?= e($formData['description']) ?></textarea>
      </div>

      <?php if ($statusCol): ?>
    <div>
        <label>Status</label>
        <div class="event-status-inline">
            <label class="event-switch" aria-label="Event status toggle">
                <input type="hidden" name="status" value="0">
                <input type="checkbox" name="status" value="1" <?= (int)$formData['status'] === 1 ? 'checked' : '' ?>>
                <span class="event-switch-slider"></span>
            </label>
            <span class="event-switch-text <?= (int)$formData['status'] === 1 ? 'is-active' : 'is-inactive' ?>">
                <?= (int)$formData['status'] === 1 ? 'Active' : 'Inactive' ?>
            </span>
        </div>
        <div class="muted small">Turn off to keep the event in history without using it actively.</div>
    </div>
<?php endif; ?>

      <button class="btn btn-primary event-submit" type="submit">
        <?= $editRow ? 'Update Event' : 'Save Event' ?>
      </button>
    </form>
  </section>

  <section class="card stack event-list-card">
    <div class="toolbar event-card-head">
      <div>
        <h2 class="event-subtitle">Event List</h2>
        <p class="muted event-helper">Tap any event to load it into the form for editing.</p>
      </div>
      <span class="tag orange"><?= count($events) ?> total</span>
    </div>

    <div class="search-results event-results">
      <?php foreach ($events as $event): ?>
        <?php $isEditing = (int)$event['ID'] === $editId; ?>
        <div class="search-row event-row <?= $isEditing ? 'is-selected' : '' ?>">
          <a class="event-row-link" href="event_page.php?edit=<?= (int)$event['ID'] ?>">
            <div class="event-row-main">
              <div class="event-row-top">
                <strong><?= e((string)$event['name']) ?></strong>
                <?php if ($statusCol): ?>
                  <span class="tag <?= (int)$event['status'] === 1 ? 'green' : 'red' ?>">
                    <?= (int)$event['status'] === 1 ? 'Active' : 'Inactive' ?>
                  </span>
                <?php endif; ?>
              </div>

              <?php if (trim((string)($event['notes'] ?? '')) !== ''): ?>
                <span class="muted event-row-desc"><?= e((string)$event['notes']) ?></span>
              <?php else: ?>
                <span class="muted event-row-desc">No description added.</span>
              <?php endif; ?>

              <div class="event-row-stats">
                <span class="tag blue">Target <?= money((float)($event['estimated'] ?? 0)) ?></span>
                <span class="tag orange">Collected <?= money((float)($event['collected'] ?? 0)) ?></span>
              </div>
            </div>
          </a>

          <div class="compact-actions event-row-actions">
            <a class="btn <?= $isEditing ? 'btn-primary' : '' ?>" href="event_page.php?edit=<?= (int)$event['ID'] ?>">
              <?= $isEditing ? 'Editing' : 'Edit' ?>
            </a>

            <?php if ($statusCol): ?>
              <form method="post" class="event-toggle-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="event_id" value="<?= (int)$event['ID'] ?>">
                <input type="hidden" name="new_status" value="<?= (int)$event['status'] === 1 ? 0 : 1 ?>">
                <input type="hidden" name="return_edit" value="<?= $isEditing ? 1 : 0 ?>">
                <label class="event-switch compact" aria-label="Toggle event status">
                  <input type="checkbox" <?= (int)$event['status'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
                  <span class="event-switch-slider"></span>
                </label>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (!$events): ?>
        <div class="event-empty muted">No events yet.</div>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
