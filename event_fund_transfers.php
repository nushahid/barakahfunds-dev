<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'accountant');

$uid = getLoggedInUserId();
$errors = [];

function ensureEventFundTransfersTable(PDO $pdo): void
{
    $sql = "CREATE TABLE IF NOT EXISTS event_fund_transfers (
        ID bigint(20) NOT NULL AUTO_INCREMENT,
        event_id int(11) NOT NULL,
        direction varchar(30) NOT NULL,
        amount decimal(12,2) NOT NULL DEFAULT 0.00,
        notes varchar(500) DEFAULT NULL,
        approved_by int(11) DEFAULT NULL,
        created_by int(11) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (ID),
        KEY idx_event_fund_transfers_event (event_id),
        KEY idx_event_fund_transfers_direction (direction),
        KEY idx_event_fund_transfers_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $pdo->exec($sql);
}

function loadEventFundRows(PDO $pdo): array
{
    if (!tableExists($pdo, 'events')) {
        return [];
    }

    $estimateCol = columnExists($pdo, 'events', 'estimate') ? 'estimate' : (columnExists($pdo, 'events', 'estimated') ? 'estimated' : null);
    $statusCol = columnExists($pdo, 'events', 'status') ? 'status' : (columnExists($pdo, 'events', 'active') ? 'active' : null);
    $selectEstimate = $estimateCol ? ('COALESCE(e.' . $estimateCol . ',0)') : '0';
    $selectStatus = $statusCol ? ('COALESCE(e.' . $statusCol . ',1)') : '1';

    $sql = "SELECT
        e.ID,
        e.name,
        {$selectEstimate} AS target_amount,
        {$selectStatus} AS status,
        COALESCE((SELECT SUM(d.amount) FROM event_details d WHERE d.event_id = e.ID),0) AS donations,
        COALESCE((SELECT SUM(x.amount) FROM events_expense x WHERE x.event_id = e.ID),0) AS expenses,
        COALESCE((SELECT SUM(t.amount) FROM event_fund_transfers t WHERE t.event_id = e.ID AND t.direction = 'mosque_to_event'),0) AS transfer_in,
        COALESCE((SELECT SUM(t.amount) FROM event_fund_transfers t WHERE t.event_id = e.ID AND t.direction = 'event_to_mosque'),0) AS transfer_out
        FROM events e
        ORDER BY e.ID DESC";

    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['reserved_balance'] = (float)$row['donations'] - (float)$row['expenses'] + (float)$row['transfer_in'] - (float)$row['transfer_out'];
    }
    unset($row);
    return $rows;
}

function findEventRow(array $rows, int $eventId): ?array
{
    foreach ($rows as $row) {
        if ((int)$row['ID'] === $eventId) {
            return $row;
        }
    }
    return null;
}

ensureEventFundTransfersTable($pdo);
$eventRows = loadEventFundRows($pdo);
$totalMosqueFunds = getTotalCashInAllOperators($pdo);
$totalReservedForEvents = 0.0;
foreach ($eventRows as $row) {
    $totalReservedForEvents += (float)$row['reserved_balance'];
}
$generalMosqueReserve = $totalMosqueFunds - $totalReservedForEvents;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'save_transfer') {
    verifyCsrfOrFail();
    $eventId = (int)($_POST['event_id'] ?? 0);
    $direction = (string)($_POST['direction'] ?? 'event_to_mosque');
    $amount = (float)($_POST['amount'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    $allowedDirections = ['event_to_mosque', 'mosque_to_event'];
    $eventRow = findEventRow($eventRows, $eventId);
    if ($eventId <= 0 || !$eventRow) $errors[] = 'Please select a valid event.';
    if (!in_array($direction, $allowedDirections, true)) $errors[] = 'Invalid transfer direction.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';

    if ($eventRow) {
        $eventBalance = (float)$eventRow['reserved_balance'];
        if ($direction === 'event_to_mosque' && $amount > $eventBalance) $errors[] = 'This event does not have enough reserved balance for that transfer.';
        if ($direction === 'mosque_to_event' && $amount > $generalMosqueReserve) $errors[] = 'General mosque reserve is not enough for that transfer.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO event_fund_transfers (event_id, direction, amount, notes, approved_by, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$eventId, $direction, $amount, $notes !== '' ? $notes : null, $uid, $uid]);
        systemLog($pdo, $uid, 'event_transfer', 'create', ($direction === 'event_to_mosque' ? 'Event to mosque' : 'Mosque to event') . ' transfer for event #' . $eventId . ' amount ' . number_format($amount, 2, '.', ''), $eventId);
        setFlash('success', 'Event transfer saved successfully.');
        header('Location: event_fund_transfers.php');
        exit;
    }
}

$recentTransfers = [];
if (tableExists($pdo, 'event_fund_transfers')) {
    $stmt = $pdo->query('SELECT t.*, e.name AS event_name, u.name AS accountant_name FROM event_fund_transfers t LEFT JOIN events e ON e.ID = t.event_id LEFT JOIN users u ON u.ID = t.created_by ORDER BY t.ID DESC LIMIT 50');
    $recentTransfers = $stmt->fetchAll();
}

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Event Fund Transfers</h1>
<div class="event-transfer-note-v6">This page is only for accountant use. User-to-user transfer stays on the normal transfer page to keep both flows clear.</div>

<div class="event-transfer-stats-v6">
  <div class="event-stat-card-v6"><div class="muted">Total mosque funds</div><div class="summary"><?= money($totalMosqueFunds) ?></div></div>
  <div class="event-stat-card-v6"><div class="muted">Reserved for events</div><div class="summary"><?= money($totalReservedForEvents) ?></div></div>
  <div class="event-stat-card-v6"><div class="muted">General mosque reserve</div><div class="summary"><?= money($generalMosqueReserve) ?></div></div>
</div>

<div class="grid-2 event-transfer-layout-v6">
  <div class="card event-transfer-form-card-v6">
    <?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
    <div class="event-transfer-head-v6">
      <h2>Move reserve</h2>
      <div class="helper">Use Event → Mosque when event has extra reserve. Use Mosque → Event when event needs approved support.</div>
    </div>

    <form method="post" class="event-transfer-form-v6">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="save_transfer">

      <div class="event-transfer-field-v6">
        <label>Event</label>
        <select name="event_id" required>
          <option value="">Select event</option>
          <?php foreach ($eventRows as $row): ?>
            <option value="<?= (int)$row['ID'] ?>" <?= (int)($_POST['event_id'] ?? 0) === (int)$row['ID'] ? 'selected' : '' ?>><?= e((string)$row['name']) ?> — balance <?= e(number_format((float)$row['reserved_balance'], 2)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="event-transfer-field-v6">
        <label>Transfer Type</label>
        <div class="event-direction-grid-v6">
          <label class="event-direction-card-v6"><input type="radio" name="direction" value="event_to_mosque" <?= (($_POST['direction'] ?? 'event_to_mosque') === 'event_to_mosque') ? 'checked' : '' ?>><span>Event → Mosque</span></label>
          <label class="event-direction-card-v6"><input type="radio" name="direction" value="mosque_to_event" <?= (($_POST['direction'] ?? '') === 'mosque_to_event') ? 'checked' : '' ?>><span>Mosque → Event</span></label>
        </div>
      </div>

      <div class="event-transfer-field-v6">
        <label>Amount</label>
        <input type="number" step="0.01" min="0.01" name="amount" value="<?= e((string)($_POST['amount'] ?? '')) ?>" required>
      </div>

      <div class="event-transfer-field-v6">
        <label>Reason / Notes</label>
        <textarea name="notes" rows="3" placeholder="Approval note, reason, or cabinet reference"><?= e((string)($_POST['notes'] ?? '')) ?></textarea>
      </div>

      <button class="btn btn-primary event-transfer-submit-v6" type="submit">Save Event Transfer</button>
    </form>
  </div>

  <div class="card event-transfer-balance-card-v6">
    <div class="event-transfer-head-v6">
      <h2>Event balances</h2>
      <div class="helper">Collected event reserve after donations, expenses, and earlier transfers.</div>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Event</th><th>Donations</th><th>Expenses</th><th>From Mosque</th><th>To Mosque</th><th>Reserved</th></tr></thead>
        <tbody>
          <?php foreach ($eventRows as $row): ?>
            <tr>
              <td><?= e((string)$row['name']) ?></td>
              <td><?= money((float)$row['donations']) ?></td>
              <td><?= money((float)$row['expenses']) ?></td>
              <td><?= money((float)$row['transfer_in']) ?></td>
              <td><?= money((float)$row['transfer_out']) ?></td>
              <td><strong><?= money((float)$row['reserved_balance']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$eventRows): ?><tr><td colspan="6" class="muted">No events found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card event-transfer-history-card-v6">
  <div class="event-transfer-head-v6">
    <h2>Recent event transfers</h2>
    <div class="helper">Separate history so normal operator transfers do not get mixed with event reserve movement.</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Event</th><th>Direction</th><th>Amount</th><th>Notes</th><th>By</th></tr></thead>
      <tbody>
        <?php foreach ($recentTransfers as $row): ?>
          <tr>
            <td><?= e((string)$row['created_at']) ?></td>
            <td><?= e((string)($row['event_name'] ?? ('Event #' . (int)$row['event_id']))) ?></td>
            <td><?= e($row['direction'] === 'event_to_mosque' ? 'Event → Mosque' : 'Mosque → Event') ?></td>
            <td><?= money((float)$row['amount']) ?></td>
            <td><?= e((string)$row['notes']) ?></td>
            <td><?= e((string)$row['accountant_name']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recentTransfers): ?><tr><td colspan="6" class="muted">No event transfers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
