<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$role = currentRole($pdo);
if ($role === 'admin') {
    exit('Admins do not process transfers.');
}

$uid = getLoggedInUserId();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'send') {
        $toUser = (int)($_POST['to_user_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $method = normalizePaymentMethod((string)($_POST['payment_method'] ?? 'cash'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($toUser <= 0 || $toUser === $uid) {
            $errors[] = 'Choose another user.';
        }
        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }
        if ((operatorBalance($pdo, $uid) - $amount) < -10000) {
            $errors[] = 'Transfer would break the -10,000 limit.';
        }

        if (!$errors) {
            $pdo->prepare('INSERT INTO balance_transfers (from_user_id, to_user_id, amount, payment_method, notes, status, requested_at, requested_by) VALUES (?, ?, ?, ?, ?, "pending", NOW(), ?)')
                ->execute([$uid, $toUser, $amount, $method, $notes, $uid]);
            systemLog($pdo, $uid, 'transfer', 'request', 'Transfer request amount ' . number_format($amount, 2));
            setFlash('success', 'Transfer request sent.');
            header('Location: transfer_requests.php');
            exit;
        }
    }

    if (in_array($action, ['accept', 'refuse'], true)) {
        $transferId = (int)($_POST['transfer_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM balance_transfers WHERE ID = ? LIMIT 1');
        $stmt->execute([$transferId]);
        $transfer = $stmt->fetch();

        if ($transfer && (int)$transfer['to_user_id'] === $uid && $transfer['status'] === 'pending') {
            $newStatus = $action === 'accept' ? 'accepted' : 'refused';
            $pdo->prepare('UPDATE balance_transfers SET status = ?, responded_at = NOW(), responded_by = ? WHERE ID = ?')
                ->execute([$newStatus, $uid, $transferId]);

            if ($newStatus === 'accepted') {
                $pdo->prepare('INSERT INTO operator_ledger (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, notes, created_by, created_at) VALUES (?, NULL, "transfer", "transfer_out", ?, ?, ?, ?, NOW())')
                    ->execute([(int)$transfer['from_user_id'], -(float)$transfer['amount'], $transfer['payment_method'], 'Approved transfer to user #' . $uid, $uid]);
                if ($role === 'accountant') {
                    $pdo->prepare('INSERT INTO accountant_ledger (entry_type, amount, payment_method, notes, created_by, created_at) VALUES ("transfer_in", ?, ?, ?, ?, NOW())')
                        ->execute([(float)$transfer['amount'], $transfer['payment_method'], 'Approved transfer from user #' . (int)$transfer['from_user_id'], $uid]);
                } else {
                    $pdo->prepare('INSERT INTO operator_ledger (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, notes, created_by, created_at) VALUES (?, NULL, "transfer", "transfer_in", ?, ?, ?, ?, NOW())')
                        ->execute([$uid, (float)$transfer['amount'], $transfer['payment_method'], 'Accepted transfer from user #' . (int)$transfer['from_user_id'], $uid]);
                }
            }

            systemLog($pdo, $uid, 'transfer', $newStatus, 'Transfer #' . $transferId, $transferId);
            setFlash('success', 'Transfer ' . $newStatus . '.');
            header('Location: transfer_requests.php');
            exit;
        }
    }
}

$users = getOperators($pdo);
$users = array_values(array_filter($users, fn($u) => (int)$u['ID'] !== $uid && (($role === 'operator' && in_array(($u['role'] ?? ((int)$u['accountant']===1?'accountant':((int)$u['admin']===1?'admin':'operator'))), ['operator','accountant'], true)) || $role === 'accountant')));

$incomingStmt = $pdo->prepare('SELECT t.*, fu.name AS from_name, tu.name AS to_name FROM balance_transfers t LEFT JOIN users fu ON fu.ID=t.from_user_id LEFT JOIN users tu ON tu.ID=t.to_user_id WHERE t.to_user_id = ? ORDER BY t.ID DESC');
$incomingStmt->execute([$uid]);
$incoming = $incomingStmt->fetchAll();

$sentStmt = $pdo->prepare('SELECT t.*, fu.name AS from_name, tu.name AS to_name FROM balance_transfers t LEFT JOIN users fu ON fu.ID=t.from_user_id LEFT JOIN users tu ON tu.ID=t.to_user_id WHERE t.from_user_id = ? ORDER BY t.ID DESC');
$sentStmt->execute([$uid]);
$sent = $sentStmt->fetchAll();

$myBalance = operatorBalance($pdo, $uid);
$pendingAmount = operatorPendingTransfers($pdo, $uid);

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Transfers</h1>

<div class="transfer-hero-grid-v6">
  <div class="transfer-hero-card-v6">
    <div class="transfer-hero-label-v6">Current Balance</div>
    <div class="transfer-hero-value-v6"><?= money($myBalance) ?></div>
    <div class="transfer-hero-note-v6">Available before new request.</div>
  </div>
  <div class="transfer-hero-card-v6">
    <div class="transfer-hero-label-v6">Pending Sent</div>
    <div class="transfer-hero-value-v6"><?= money($pendingAmount) ?></div>
    <div class="transfer-hero-note-v6">Still waiting for approval.</div>
  </div>
  <?php if ($role === 'accountant'): ?>
    <a class="transfer-hero-card-v6 transfer-hero-link-v6" href="event_fund_transfers.php">
      <div class="transfer-hero-label-v6">Event Fund Transfers</div>
      <div class="transfer-hero-value-v6">🔁</div>
      <div class="transfer-hero-note-v6">Open separate event ↔ mosque transfer page.</div>
    </a>
  <?php endif; ?>
</div>

<div class="grid-2 transfer-layout-v6">
  <div class="card transfer-form-card-v6">
    <?php if ($errors): ?><div class="alert error"><?php foreach($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
    <div class="transfer-section-head-v6">
      <h2>Request a transfer</h2>
      <div class="helper">Keep user-to-user transfer here. Event reserve movement stays on the separate accountant page.</div>
    </div>

    <form method="post" class="transfer-form-v6">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="send">

      <div class="transfer-field-v6">
        <label>Transfer To</label>
        <select name="to_user_id" required>
          <option value="">Select user</option>
          <?php foreach($users as $u): ?>
            <option value="<?= (int)$u['ID'] ?>"><?= e((string)$u['name']) ?> (<?= e(roleLabel((string)($u['role'] ?? ((int)($u['accountant'] ?? 0)===1?'accountant':((int)($u['admin'] ?? 0)===1?'admin':'operator'))))) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="transfer-amount-grid-v6">
        <label class="transfer-choice-card-v6">
          <span class="transfer-choice-title-v6">Amount</span>
          <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00">
        </label>
      </div>

      <div class="transfer-field-v6">
        <label>Payment Method</label>
        <div class="transfer-method-grid-v6">
          <label class="transfer-method-card-v6"><input type="radio" name="payment_method" value="cash" checked><span>💵 Cash</span></label>
          <label class="transfer-method-card-v6"><input type="radio" name="payment_method" value="bank_transfer"><span>🏦 Bank Transfer</span></label>
        </div>
      </div>

      <div class="transfer-field-v6">
        <label>Notes</label>
        <textarea name="notes" rows="3" placeholder="Optional reason or message for receiver"></textarea>
      </div>

      <button class="btn btn-primary transfer-submit-btn-v6" type="submit">Send Transfer Request</button>
    </form>
  </div>

  <div class="stack">
    <div class="card transfer-list-card-v6">
      <div class="transfer-section-head-v6">
        <h2>Incoming Requests</h2>
        <div class="helper">Only accepted requests affect balances.</div>
      </div>
      <div class="transfer-request-list-v6">
        <?php foreach($incoming as $row): ?>
          <div class="transfer-request-item-v6">
            <div>
              <div class="transfer-request-name-v6"><?= e((string)$row['from_name']) ?></div>
              <div class="muted"><?= money((float)$row['amount']) ?> · <?= e((string)$row['payment_method']) ?></div>
            </div>
            <div class="transfer-request-actions-v6">
              <?php if ($row['status']==='pending'): ?>
                <form method="post" class="transfer-inline-form-v6">
                  <?= csrfField() ?>
                  <input type="hidden" name="transfer_id" value="<?= (int)$row['ID'] ?>">
                  <button class="btn btn-primary" name="action" value="accept">Accept</button>
                  <button class="btn" name="action" value="refuse">Refuse</button>
                </form>
              <?php else: ?>
                <span class="tag <?= $row['status']==='accepted'?'green':'red' ?>"><?= e((string)$row['status']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if(!$incoming): ?><div class="muted">No incoming requests.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card transfer-history-card-v6">
  <div class="transfer-section-head-v6">
    <h2>Sent Requests</h2>
    <div class="helper">Track approvals, refusals, and pending requests.</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>To</th><th>Amount</th><th>Method</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>
        <?php foreach($sent as $row): ?>
          <tr>
            <td><?= e((string)$row['requested_at']) ?></td>
            <td><?= e((string)$row['to_name']) ?></td>
            <td><?= money((float)$row['amount']) ?></td>
            <td><?= e((string)$row['payment_method']) ?></td>
            <td><span class="tag <?= $row['status']==='accepted'?'green':($row['status']==='refused'?'red':'orange') ?>"><?= e((string)$row['status']) ?></span></td>
            <td><?= e((string)$row['notes']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$sent): ?><tr><td colspan="6" class="muted">No sent requests yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
