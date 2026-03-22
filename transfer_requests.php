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

/**
 * POST ACTIONS HANDLER
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = trim((string)($_POST['action'] ?? ''));

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

        if (!in_array($method, ['cash', 'bank_transfer'], true)) {
    $errors[] = 'Invalid payment method.';
}

if ($role === 'accountant' && $method !== 'cash') {
    $errors[] = 'Accountant can send transfers by cash only.';
}

       if ($role === 'accountant') {
    $accountantCash = getAccountantCashOnHand($pdo);
    if ($amount > $accountantCash) {
        $errors[] = 'Transfer amount cannot exceed accountant cash in hand (' . money($accountantCash) . ').';
    }
} else {
    if ((operatorBalance($pdo, $uid) - $amount) < -10000) {
        $errors[] = 'Transfer would break the -10,000 limit.';
    }
}

        $userCheck = $pdo->prepare('
            SELECT ID, name, role, accountant, admin, active
            FROM users
            WHERE ID = ?
            LIMIT 1
        ');
        $userCheck->execute([$toUser]);
        $targetUser = $userCheck->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
    $errors[] = 'Selected user was not found.';
} else {
    $targetRole = (string)($targetUser['role']
        ?? ((int)($targetUser['accountant'] ?? 0) === 1
            ? 'accountant'
            : ((int)($targetUser['admin'] ?? 0) === 1 ? 'admin' : 'operator')));

    $targetActive = (int)($targetUser['active'] ?? 1);

    if ($targetActive !== 1) {
        $errors[] = 'Selected user is inactive.';
    }

    if ($targetRole === 'admin') {
        $errors[] = 'Transfers to admin are not allowed.';
    }

    if ($role === 'operator' && !in_array($targetRole, ['operator', 'accountant'], true)) {
        $errors[] = 'You can only transfer to an operator or accountant.';
    }

    if ($role === 'accountant' && $targetRole !== 'operator') {
        $errors[] = 'Accountant can send transfers to operator only.';
    }
}

        if (!$errors) {
            $stmt = $pdo->prepare('
                INSERT INTO balance_transfers
                    (from_user_id, to_user_id, amount, payment_method, notes, status, requested_at, requested_by)
                VALUES
                    (?, ?, ?, ?, ?, "pending", NOW(), ?)
            ');
            $stmt->execute([$uid, $toUser, $amount, $method, $notes, $uid]);

            systemLog(
                $pdo,
                $uid,
                'transfer',
                'request',
                'Transfer request ' . number_format($amount, 2) . ' to user #' . $toUser
            );

            setFlash('success', 'Transfer request sent.');
            header('Location: transfer_requests.php');
            exit;
        }
    } elseif ($action === 'bank_deposit' && $role === 'accountant') {
        $amount = (float)($_POST['amount'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $cashOnHandNow = getAccountantCashOnHand($pdo);

        if ($amount > 0 && $amount <= $cashOnHandNow) {
            $stmt = $pdo->prepare('
    INSERT INTO accountant_ledger
        (entry_type, transaction_type, transaction_category, amount, payment_method, reference_id, invoice_no, receipt_token, receipt_path, notes, operator_id, reference_notes, created_by, created_at)
    VALUES
        ("bank_deposit", "debit", "bank_deposit", ?, "bank_transfer", NULL, NULL, NULL, NULL, ?, NULL, "Cash moved to bank", ?, NOW())
');
$stmt->execute([
    -1 * abs($amount),
    $notes !== '' ? $notes : 'Cash moved to bank',
    $uid
]);

            systemLog($pdo, $uid, 'accountant', 'bank_deposit', 'Bank deposit ' . number_format($amount, 2));
            setFlash('success', 'Cash moved to bank successfully.');
            header('Location: transfer_requests.php');
            exit;
        } else {
            $errors[] = 'Deposit amount must be positive and cannot exceed your cash in hand (' . money($cashOnHandNow) . ').';
        }
        } elseif (in_array($action, ['accept', 'refuse'], true)) {
        $transferId = (int)($_POST['transfer_id'] ?? 0);

        $stmt = $pdo->prepare('
            SELECT t.*, fu.name AS from_name, tu.name AS to_name
            FROM balance_transfers t
            LEFT JOIN users fu ON fu.ID = t.from_user_id
            LEFT JOIN users tu ON tu.ID = t.to_user_id
            WHERE t.ID = ?
            LIMIT 1
        ');
        $stmt->execute([$transferId]);
        $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transfer) {
            $errors[] = 'Transfer request not found.';
        } elseif ((int)$transfer['to_user_id'] !== $uid) {
            $errors[] = 'Unauthorized action.';
        } elseif ((string)$transfer['status'] !== 'pending') {
            $errors[] = 'Already processed.';
        } else {
            $newStatus = ($action === 'accept') ? 'accepted' : 'refused';

            try {
                $pdo->beginTransaction();

                $upd = $pdo->prepare('
                    UPDATE balance_transfers
                    SET status = ?, responded_at = NOW(), responded_by = ?
                    WHERE ID = ? AND status = "pending"
                ');
                $upd->execute([$newStatus, $uid, $transferId]);

                if ($upd->rowCount() !== 1) {
                    throw new RuntimeException('Transfer status update failed.');
                }

               if ($newStatus === 'accepted') {
    // Detect actual sender/receiver roles from DB
    $roleStmt = $pdo->prepare('
        SELECT ID, role, accountant, admin
        FROM users
        WHERE ID IN (?, ?)
    ');
    $roleStmt->execute([
        (int)$transfer['from_user_id'],
        (int)$transfer['to_user_id']
    ]);
    $roleRows = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

    $fromRole = 'operator';
    $toRole   = 'operator';

    foreach ($roleRows as $r) {
        $resolvedRole = (string)($r['role']
            ?? ((int)($r['accountant'] ?? 0) === 1
                ? 'accountant'
                : ((int)($r['admin'] ?? 0) === 1 ? 'admin' : 'operator')));

        if ((int)$r['ID'] === (int)$transfer['from_user_id']) {
            $fromRole = $resolvedRole;
        }
        if ((int)$r['ID'] === (int)$transfer['to_user_id']) {
            $toRole = $resolvedRole;
        }
    }

    // Correct transfer type
    if ($fromRole === 'operator' && $toRole === 'accountant') {
        $transferType = 'operator_to_bank';
    } elseif ($fromRole === 'accountant' && $toRole === 'operator') {
        $transferType = 'bank_to_operator';
    } else {
        $transferType = 'operator_to_operator';
    }

    $rawMethod = (string)($transfer['payment_method'] ?? 'cash');
    $ledgerMethod = 'cash';

if (in_array($rawMethod, ['bank_transfer', 'bank'], true)) {
    $ledgerMethod = 'bank_transfer';
} elseif (in_array($rawMethod, ['cash', 'pos', 'stripe', 'online'], true)) {
    $ledgerMethod = $rawMethod;
}

    // 1) General transfer log
    $logStmt = $pdo->prepare('
        INSERT INTO transfer_ledger
            (transfer_request_id, from_user_id, to_user_id, amount, payment_method, transfer_type, notes, created_by, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    $logStmt->execute([
        $transferId,
        (int)$transfer['from_user_id'],
        (int)$transfer['to_user_id'],
        (float)$transfer['amount'],
        $rawMethod === 'bank' ? 'bank_transfer' : $rawMethod,
        $transferType,
        (string)($transfer['notes'] ?? ''),
        $uid
    ]);

    // 2) Deduct sender
    if ($fromRole === 'operator') {
        $deductStmt = $pdo->prepare('
            INSERT INTO operator_ledger
                (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, receipt_path, notes, created_by, created_at, source_type, contributor_count, source_note)
            VALUES
                (?, NULL, ?, ?, ?, ?, NULL, ?, NULL, NULL, NULL, ?, ?, NOW(), "self", NULL, NULL)
        ');
        $deductStmt->execute([
            (int)$transfer['from_user_id'],
            'debit',
            'transfer_out',
            -1 * abs((float)$transfer['amount']),
            $ledgerMethod,
            $transferId,
            'Accepted transfer #' . $transferId . ' to user #' . (int)$transfer['to_user_id'],
            $uid
        ]);
    } elseif ($fromRole === 'accountant') {
        // IMPORTANT FIX: accountant sending out money must reduce accountant cash
        $accOutStmt = $pdo->prepare('
    INSERT INTO accountant_ledger
        (entry_type, transaction_type, transaction_category, amount, payment_method, reference_id, invoice_no, receipt_token, receipt_path, notes, operator_id, reference_notes, created_by, created_at)
    VALUES
        (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, NOW())
');
$accOutStmt->execute([
    'transfer',
    'debit',
    'bank_to_operator',
    -1 * abs((float)$transfer['amount']),
    $rawMethod === 'bank' ? 'bank_transfer' : $rawMethod,
    $transferId,
    'Accepted transfer #' . $transferId . ' sent to operator #' . (int)$transfer['to_user_id'],
    (int)$transfer['to_user_id'],
    'Outgoing transfer to operator',
    $uid
]);
    }

    // 3) Credit receiver
    if ($toRole === 'operator') {
        $creditStmt = $pdo->prepare('
            INSERT INTO operator_ledger
                (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, receipt_path, notes, created_by, created_at, source_type, contributor_count, source_note)
            VALUES
                (?, NULL, ?, ?, ?, ?, NULL, ?, NULL, NULL, NULL, ?, ?, NOW(), "self", NULL, NULL)
        ');
        $creditStmt->execute([
            (int)$transfer['to_user_id'],
            'credit',
            'transfer_in',
            abs((float)$transfer['amount']),
            $ledgerMethod,
            $transferId,
            'Accepted incoming transfer #' . $transferId . ' from user #' . (int)$transfer['from_user_id'],
            $uid
        ]);
    } elseif ($toRole === 'accountant') {
        // optional but correct: accountant receiving from operator should increase accountant cash
        $accInStmt = $pdo->prepare('
    INSERT INTO accountant_ledger
        (entry_type, transaction_type, transaction_category, amount, payment_method, reference_id, invoice_no, receipt_token, receipt_path, notes, operator_id, reference_notes, created_by, created_at)
    VALUES
        (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, NOW())
');
$accInStmt->execute([
    'transfer',
    'credit',
    'operator_to_bank',
    abs((float)$transfer['amount']),
    $rawMethod === 'bank' ? 'bank_transfer' : $rawMethod,
    $transferId,
    'Accepted transfer #' . $transferId . ' received from operator #' . (int)$transfer['from_user_id'],
    (int)$transfer['from_user_id'],
    'Incoming transfer from operator',
    $uid
]);
    }
}   

                systemLog($pdo, $uid, 'transfer', $newStatus, 'Transfer #' . $transferId, $transferId);
                $pdo->commit();

                setFlash('success', 'Transfer ' . $newStatus . '.');
                header('Location: transfer_requests.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Failed to process transfer: ' . $e->getMessage();
            }
        }
    }
}

/**
 * DATA FETCHING
 */

// 1. Get List of Users for the Dropdown
$users = getOperators($pdo);
$users = array_values(array_filter($users, function ($u) use ($uid) {
    $userRole = (string)($u['role']
        ?? ((int)($u['accountant'] ?? 0) === 1
            ? 'accountant'
            : ((int)($u['admin'] ?? 0) === 1 ? 'admin' : 'operator')));

    return (
        (int)$u['ID'] !== $uid &&
        (int)($u['active'] ?? 1) === 1 &&
        $userRole !== 'admin'
    );
}));

// 2. Logic for Balance Display
$operatorsCash = getTotalCashInAllOperators($pdo);
$accountantCash = getAccountantCashOnHand($pdo);

$totalCashInHand = getMosqueCashInHand($pdo);
$bankBalance = getMosqueBankBalance($pdo);
$totalFunds = getMosqueTotalFunds($pdo);

if ($role === 'accountant') {
    $cashOnHand = $accountantCash;
    $myBalance = $accountantCash;
} else {
    $cashOnHand = 0.0;
    $myBalance = operatorBalance($pdo, $uid);
}

$pendingAmount = operatorPendingTransfers($pdo, $uid);

$transferSummary = getTransferRequestSummary($pdo);

// 3. Incoming & Sent Requests
$incomingStmt = $pdo->prepare('
    SELECT t.*, fu.name AS from_name
    FROM balance_transfers t
    LEFT JOIN users fu ON fu.ID = t.from_user_id
    WHERE t.to_user_id = ?
    ORDER BY t.ID DESC
');
$incomingStmt->execute([$uid]);
$incoming = $incomingStmt->fetchAll(PDO::FETCH_ASSOC);

$sentStmt = $pdo->prepare('
    SELECT t.*, tu.name AS to_name
    FROM balance_transfers t
    LEFT JOIN users tu ON tu.ID = t.to_user_id
    WHERE t.from_user_id = ?
    ORDER BY t.ID DESC
');
$sentStmt->execute([$uid]);
$sent = $sentStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Accepted Logs
$acceptedLogStmt = $pdo->prepare('
    SELECT tl.*, fu.name AS from_name, tu.name AS to_name
    FROM transfer_ledger tl
    LEFT JOIN users fu ON fu.ID = tl.from_user_id
    LEFT JOIN users tu ON tu.ID = tl.to_user_id
    WHERE tl.from_user_id = ? OR tl.to_user_id = ?
    ORDER BY tl.id DESC
    LIMIT 50
');
$acceptedLogStmt->execute([$uid, $uid]);
$acceptedTransfers = $acceptedLogStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Transfers</h1>

<div class="transfer-hero-grid-v6">
    <div class="transfer-hero-card-v6">
        <div class="transfer-hero-label-v6">Total Cash in Hand</div>
        <div class="transfer-hero-value-v6"><?= money($totalCashInHand) ?></div>
        <div class="transfer-hero-note-v6">Operators cash + accountant cash</div>
    </div>

    <div class="transfer-hero-card-v6">
        <div class="transfer-hero-label-v6">Bank Balance</div>
        <div class="transfer-hero-value-v6"><?= money($bankBalance) ?></div>
        <div class="transfer-hero-note-v6">Deposited and bank-tracked funds</div>
    </div>

    <div class="transfer-hero-card-v6">
        <div class="transfer-hero-label-v6">Total Mosque Funds</div>
        <div class="transfer-hero-value-v6"><?= money($totalFunds) ?></div>
        <div class="transfer-hero-note-v6">Cash in hand + bank balance</div>
    </div>

    <div class="transfer-hero-card-v6">
        <div class="transfer-hero-label-v6"><?= ($role === 'accountant') ? 'My Cash in Hand' : 'My Current Balance' ?></div>
        <div class="transfer-hero-value-v6"><?= money($myBalance) ?></div>
        <div class="transfer-hero-note-v6"><?= ($role === 'accountant') ? 'Cash currently with accountant' : 'Available before new request' ?></div>
    </div>

    <div class="transfer-hero-card-v6">
        <div class="transfer-hero-label-v6">Pending Sent</div>
        <div class="transfer-hero-value-v6"><?= money($pendingAmount) ?></div>
        <div class="transfer-hero-note-v6">Waiting for receiver approval</div>
    </div>

    <?php if ($role === 'accountant'): ?>
        <a class="transfer-hero-card-v6 transfer-hero-link-v6" href="event_fund_transfers.php">
            <div class="transfer-hero-label-v6">Event Fund Transfers</div>
            <div class="transfer-hero-value-v6">🔁</div>
            <div class="transfer-hero-note-v6">Open Event ↔ Mosque transfers</div>
        </a>
    <?php endif; ?>
</div>

<div class="grid-2 transfer-layout-v6">
    <div class="card transfer-form-card-v6">
        <?php if ($errors): ?>
            <div class="alert error">
                <?php foreach ($errors as $er): ?>
                    <div><?= e($er) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="transfer-section-head-v6">
            <h2>Request a transfer</h2>
            <div class="helper">Moves funds between users. These are not direct donation or expense entries.</div>
        </div>

        <form method="post" class="transfer-form-v6">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send">

            <div class="transfer-field-v6">
                <label>Transfer To</label>
                <select name="to_user_id" required>
                    <option value="">Select user</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['ID'] ?>"><?= e((string)$u['name']) ?></option>
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
        <label class="transfer-method-card-v6">
            <input type="radio" name="payment_method" value="cash" checked>
            <span>💵 Cash</span>
        </label>

        <?php if ($role !== 'accountant'): ?>
            <label class="transfer-method-card-v6">
                <input type="radio" name="payment_method" value="bank_transfer">
                <span>🏦 Bank</span>
            </label>
        <?php endif; ?>
    </div>

    <?php if ($role === 'accountant'): ?>
        <div class="helper" style="margin-top:8px;">
            Accountant can send money to operator by cash only in current workflow.
        </div>
    <?php endif; ?>
</div>

            <div class="transfer-field-v6">
                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Optional reason..."></textarea>
            </div>

            <button class="btn btn-primary transfer-submit-btn-v6" type="submit">Send Request</button>
        </form>
    </div>

    <div class="stack">
        <div class="card transfer-list-card-v6">
            <div class="transfer-section-head-v6"><h2>Incoming Requests</h2></div>
            <div class="transfer-request-list-v6">
                <?php foreach ($incoming as $row): ?>
                    <div class="transfer-request-item-v6">
                        <div>
                            <div class="transfer-request-name-v6"><?= e((string)$row['from_name']) ?></div>
                            <div class="muted"><?= money((float)$row['amount']) ?> · <?= e((string)$row['payment_method']) ?></div>
                        </div>
                        <div class="transfer-request-actions-v6">
                            <?php if ((string)$row['status'] === 'pending'): ?>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="transfer_id" value="<?= (int)$row['ID'] ?>">
                                    <button class="btn btn-primary" name="action" value="accept">Accept</button>
                                    <button class="btn" name="action" value="refuse">Refuse</button>
                                </form>
                            <?php else: ?>
                                <span class="tag <?= $row['status'] === 'accepted' ? 'green' : 'red' ?>">
                                    <?= e((string)$row['status']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!$incoming): ?>
                    <div class="muted">No incoming requests.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card transfer-history-card-v6">
    <div class="transfer-section-head-v6"><h2>Sent Requests History</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>To</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sent as $row): ?>
                    <tr>
                        <td><?= e((string)$row['requested_at']) ?></td>
                        <td><?= e((string)$row['to_name']) ?></td>
                        <td><?= money((float)$row['amount']) ?></td>
                        <td><?= e((string)$row['payment_method']) ?></td>
                        <td>
                            <span class="tag <?= $row['status'] === 'accepted' ? 'green' : ($row['status'] === 'refused' ? 'red' : 'orange') ?>">
                                <?= e((string)$row['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$sent): ?>
                    <tr><td colspan="5" class="muted">No sent requests yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card transfer-history-card-v6">
    <div class="transfer-section-head-v6"><h2>Accepted Transfer Log</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($acceptedTransfers as $row): ?>
                    <tr>
                        <td><?= e((string)$row['created_at']) ?></td>
                        <td><?= e((string)$row['from_name']) ?></td>
                        <td><?= e((string)$row['to_name']) ?></td>
                        <td class="muted"><?= e((string)$row['transfer_type']) ?></td>
                        <td><strong><?= money((float)$row['amount']) ?></strong></td>
                        <td><?= e((string)$row['notes']) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$acceptedTransfers): ?>
                    <tr><td colspan="6" class="muted">No accepted transfer logs yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($role === 'accountant'): ?>
<div class="card" style="margin-top:24px; border-top: 4px solid var(--primary);">
    <div class="transfer-section-head-v6">
        <h2 style="margin-top:0">🏦 Move Cash to Bank</h2>
        <div class="helper"><strong>Cash on Hand: <?= money($cashOnHand) ?></strong></div>
    </div>

    <form method="post" class="stack compact">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bank_deposit">

        <div class="grid-2">
            <div class="transfer-field-v6">
                <label>Amount to Deposit</label>
                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    max="<?= (float)$cashOnHand ?>"
                    name="amount"
                    required
                    placeholder="0.00"
                >
            </div>

            <div class="transfer-field-v6">
                <label>Deposit Notes</label>
                <input type="text" name="notes" placeholder="e.g. Branch ATM Deposit">
            </div>
        </div>

        <button class="btn btn-primary" type="submit" style="width:auto; padding: 10px 24px;">
            Confirm Bank Deposit
        </button>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>