<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

requireRole($pdo, ['accountant','admin']);

$uid = getLoggedInUserId();
$errors = [];
$statusCol = columnExists($pdo, 'events', 'status');
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();

    $action = (string)($_POST['action'] ?? 'create');
    $eventId = (int)($_POST['event_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $estimated = (float)($_POST['estimate'] ?? 0);
    $notes = trim((string)($_POST['description'] ?? ''));
    $status = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;

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
        } elseif ($action === 'toggle' && $statusCol && $eventId > 0) {
            $newStatus = (int)($_POST['new_status'] ?? 1) === 1 ? 1 : 0;

            $pdo->prepare('UPDATE events SET status = ? WHERE ID = ?')
                ->execute([$newStatus, $eventId]);

            systemLog($pdo, $uid, 'event', 'toggle', 'Event status ' . $newStatus, $eventId);
            setFlash('success', 'Event status updated.');
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

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Events</h1>

<div class="grid-2 event-page-layout">
    <div class="card stack event-form-card">
        <?php if ($errors): ?>
            <div class="alert error">
                <?php foreach ($errors as $er): ?>
                    <div><?= e($er) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="toolbar">
            <h2 class="event-page-subtitle"><?= $editRow ? 'Edit Event' : 'New Event' ?></h2>
            <?php if ($editRow): ?>
                <a class="btn" href="event_page.php">Cancel</a>
            <?php endif; ?>
        </div>

        <form method="post" class="stack">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">

            <?php if ($editRow): ?>
                <input type="hidden" name="event_id" value="<?= (int)$editRow['ID'] ?>">
            <?php endif; ?>

            <div>
                <label>Event Name</label>
                <input type="text" name="name" value="<?= e((string)($editRow['name'] ?? '')) ?>" required>
            </div>

            <div>
                <label>Target Amount</label>
                <input type="number" step="0.01" name="estimate" value="<?= e((string)($editRow['estimated'] ?? '')) ?>">
            </div>

            <div>
                <label>Description</label>
                <textarea name="description"><?= e((string)($editRow['notes'] ?? '')) ?></textarea>
            </div>

            <?php if ($statusCol): ?>
                <div>
                    <label>Status</label>
                    <div class="payment-chip-group event-status-group">
                        <?php foreach ([[1,'🟢','Active'], [0,'⚪','Inactive']] as $s): ?>
                            <label class="selector-label">
                                <input
                                    class="selector-input"
                                    type="radio"
                                    name="status"
                                    value="<?= (int)$s[0] ?>"
                                    <?= (int)($editRow['status'] ?? 1) === (int)$s[0] ? 'checked' : '' ?>
                                >
                                <span class="payment-chip">
                                    <span><?= e($s[1]) ?></span>
                                    <span><?= e($s[2]) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <button class="btn btn-primary" type="submit">
                <?= $editRow ? 'Update Event' : 'Save Event' ?>
            </button>
        </form>
    </div>

    <div class="card event-list-card">
        <div class="toolbar">
            <h2 class="event-page-subtitle">Event List</h2>
            <span class="tag blue"><?= count($events) ?> total</span>
        </div>

        <div class="search-results event-results">
            <?php foreach ($events as $event): ?>
                <a class="search-row event-search-row" href="event_page.php?edit=<?= (int)$event['ID'] ?>">
                    <div class="event-row-main">
                        <strong><?= e((string)$event['name']) ?></strong>
                        <div class="muted"><?= e((string)($event['notes'] ?? '')) ?></div>

                        <div class="event-row-stats">
                            <span class="tag blue">Target <?= money((float)($event['estimated'] ?? 0)) ?></span>
                            <span class="tag orange">Collected <?= money((float)($event['collected'] ?? 0)) ?></span>
                        </div>
                    </div>

                    <div class="compact-actions event-row-actions">
                        <?php if ($statusCol): ?>
                            <span class="tag <?= (int)$event['status'] === 1 ? 'green' : 'red' ?>">
                                <?= (int)$event['status'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        <?php endif; ?>

                        <span class="btn">Edit</span>

                        <?php if ($statusCol): ?>
                            <form method="post" class="event-toggle-form" onclick="event.stopPropagation();">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="event_id" value="<?= (int)$event['ID'] ?>">
                                <input type="hidden" name="new_status" value="<?= (int)$event['status'] === 1 ? 0 : 1 ?>">
                                <button class="btn" type="submit">
                                    <?= (int)$event['status'] === 1 ? 'Set Inactive' : 'Set Active' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>

            <?php if (!$events): ?>
                <div class="muted">No events found.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>