<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, ['admin', 'accountant']);

$uid = getLoggedInUserId();
$errors = [];
$estimateCol = columnExists($pdo, 'events', 'estimate') ? 'estimate' : (columnExists($pdo, 'events', 'estimated') ? 'estimated' : null);
$descCol = columnExists($pdo, 'events', 'description') ? 'description' : (columnExists($pdo, 'events', 'notes') ? 'notes' : null);
$statusCol = columnExists($pdo, 'events', 'status') ? 'status' : (columnExists($pdo, 'events', 'active') ? 'active' : null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? 'create');

    if ($action === 'toggle' && $statusCol) {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0) === 1 ? 1 : 0;
        if ($eventId > 0) {
            $stmt = $pdo->prepare('UPDATE events SET ' . $statusCol . ' = ? WHERE ID = ?');
            $stmt->execute([$newStatus, $eventId]);
            systemLog($pdo, $uid, 'event', $newStatus ? 'activate' : 'deactivate', 'Event #' . $eventId, $eventId);
            setFlash('success', $newStatus ? 'Event activated.' : 'Event deactivated.');
        }
        header('Location: event_page.php');
        exit;
    }

    if ($action === 'inline_update') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');

        if ($eventId <= 0) {
            $errors[] = 'Invalid event selected.';
        }

        if (!$errors) {
            if ($field === 'name') {
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') {
                    $errors[] = 'Event name is required.';
                } else {
                    $stmt = $pdo->prepare('UPDATE events SET name = ? WHERE ID = ?');
                    $stmt->execute([$name, $eventId]);
                }
            } elseif ($field === 'estimate' && $estimateCol) {
                $estimateRaw = trim((string)($_POST['estimate'] ?? '0'));
                $estimate = is_numeric($estimateRaw) ? (float)$estimateRaw : 0;
                $stmt = $pdo->prepare('UPDATE events SET ' . $estimateCol . ' = ? WHERE ID = ?');
                $stmt->execute([$estimate, $eventId]);
            } else {
                $errors[] = 'Invalid update request.';
            }
        }

        if (!$errors) {
            systemLog($pdo, $uid, 'event', 'update', 'Event #' . $eventId, $eventId);
            setFlash('success', 'Event updated.');
        }
        header('Location: event_page.php');
        exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $estimate = (float)($_POST['estimate'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));
    $status = isset($_POST['status']) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'Event name is required.';
    }

    if (!$errors) {
        $cols = ['name'];
        $vals = [$name];
        if ($estimateCol) { $cols[] = $estimateCol; $vals[] = $estimate; }
        if ($descCol) { $cols[] = $descCol; $vals[] = $description; }
        if ($statusCol) { $cols[] = $statusCol; $vals[] = $status; }
        if (columnExists($pdo, 'events', 'uid')) { $cols[] = 'uid'; $vals[] = $uid; }
        if (columnExists($pdo, 'events', 'created_at')) { $cols[] = 'created_at'; }

        $placeholders = [];
        foreach ($cols as $c) {
            $placeholders[] = $c === 'created_at' ? 'NOW()' : '?';
        }

        $sql = 'INSERT INTO events (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);

        systemLog($pdo, $uid, 'event', 'create', 'Event ' . $name);
        setFlash('success', 'Event saved.');
        header('Location: event_page.php');
        exit;
    }
}

$amountExpr = '0';
if (tableExists($pdo, 'event_details')) {
    $detailAmountCol = columnExists($pdo, 'event_details', 'amount') ? 'amount' : (columnExists($pdo, 'event_details', 'collected') ? 'collected' : '0');
    if ($detailAmountCol !== '0') {
        $amountExpr = '(SELECT COALESCE(SUM(' . $detailAmountCol . '),0) FROM event_details d WHERE d.event_id = e.ID)';
    }
}
$selectEstimate = $estimateCol ? ('e.' . $estimateCol) : '0';
$selectStatus = $statusCol ? ('e.' . $statusCol) : '1';
$events = $pdo->query('SELECT e.ID, e.name, ' . $selectEstimate . ' AS estimate, ' . $selectStatus . ' AS status, COALESCE(' . $amountExpr . ',0) AS collected FROM events e ORDER BY e.ID DESC')->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Events</h1>
<div class="grid-2">
  <div class="card stack">
    <?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create">
      <div><label>Event Name</label><input type="text" name="name" required></div>
      <div><label>Target Amount</label><input type="number" step="0.01" name="estimate"></div>
      <div><label>Description</label><textarea name="description"></textarea></div>
      <div class="switch-row">
        <div><strong>Active</strong><div class="muted">Only active events appear in operator donation forms.</div></div>
        <label><input type="checkbox" name="status" value="1" checked> Active</label>
      </div>
      <button class="btn btn-primary" type="submit">Save Event</button>
    </form>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Event List</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Target</th><th>Collected</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($events as $event): ?>
          <tr>
            <td>
              <form method="post" class="inline-edit-form js-inline-edit-form" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="inline_update">
                <input type="hidden" name="field" value="name">
                <input type="hidden" name="event_id" value="<?= (int)$event['ID'] ?>">
                <span class="js-inline-text"><?= e((string)$event['name']) ?></span>
                <input class="js-inline-input" type="text" name="name" value="<?= e((string)$event['name']) ?>" style="display:none;min-width:180px">
                <button type="button" class="btn js-inline-edit-toggle" title="Edit name" style="padding:4px 8px;line-height:1">✏️</button>
                <button class="btn btn-primary js-inline-save" type="submit" style="display:none">Save</button>
                <button class="btn js-inline-cancel" type="button" style="display:none">Cancel</button>
              </form>
            </td>
            <td>
              <form method="post" class="inline-edit-form js-inline-edit-form" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="inline_update">
                <input type="hidden" name="field" value="estimate">
                <input type="hidden" name="event_id" value="<?= (int)$event['ID'] ?>">
                <span class="js-inline-text"><?= money((float)$event['estimate']) ?></span>
                <input class="js-inline-input" type="number" step="0.01" name="estimate" value="<?= e((string)$event['estimate']) ?>" style="display:none;width:120px">
                <button type="button" class="btn js-inline-edit-toggle" title="Edit target" style="padding:4px 8px;line-height:1">✏️</button>
                <button class="btn btn-primary js-inline-save" type="submit" style="display:none">Save</button>
                <button class="btn js-inline-cancel" type="button" style="display:none">Cancel</button>
              </form>
            </td>
            <td><?= money((float)$event['collected']) ?></td>
            <td><span class="tag <?= (int)$event['status'] === 1 ? 'green' : 'red' ?>"><?= (int)$event['status'] === 1 ? 'Active' : 'Inactive' ?></span></td>
            <td>
              <?php if ($statusCol): ?>
              <form method="post" style="display:inline-block">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="event_id" value="<?= (int)$event['ID'] ?>">
                <input type="hidden" name="new_status" value="<?= (int)$event['status'] === 1 ? 0 : 1 ?>">
                <button class="btn" type="submit"><?= (int)$event['status'] === 1 ? 'Set Inactive' : 'Set Active' ?></button>
              </form>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$events): ?><tr><td colspan="5" class="muted">No events found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
document.addEventListener('click', function (event) {
  var toggle = event.target.closest('.js-inline-edit-toggle');
  if (toggle) {
    var form = toggle.closest('.js-inline-edit-form');
    if (!form) return;
    var text = form.querySelector('.js-inline-text');
    var input = form.querySelector('.js-inline-input');
    var save = form.querySelector('.js-inline-save');
    var cancel = form.querySelector('.js-inline-cancel');
    if (text) text.style.display = 'none';
    if (input) { input.style.display = ''; input.focus(); input.select(); }
    if (save) save.style.display = '';
    if (cancel) cancel.style.display = '';
    form.querySelectorAll('.js-inline-edit-toggle').forEach(function (el) { el.style.display = 'none'; });
    return;
  }

  var cancel = event.target.closest('.js-inline-cancel');
  if (cancel) {
    var form = cancel.closest('.js-inline-edit-form');
    if (!form) return;
    var text = form.querySelector('.js-inline-text');
    var input = form.querySelector('.js-inline-input');
    var save = form.querySelector('.js-inline-save');
    if (text) text.style.display = '';
    if (input) input.style.display = 'none';
    if (save) save.style.display = 'none';
    cancel.style.display = 'none';
    form.querySelectorAll('.js-inline-edit-toggle').forEach(function (el) { el.style.display = ''; });
  }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>


