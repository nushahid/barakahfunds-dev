<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, ['accountant', 'admin']);

$accountantId = getLoggedInUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: transaction_approval_requests.php');
    exit;
}

verifyCsrfOrFail();

$requestId = (int)($_POST['request_id'] ?? 0);
$actionType = trim((string)($_POST['action_type'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));

if ($requestId <= 0 || !in_array($actionType, ['accept', 'reject'], true)) {
    header('Location: transaction_approval_requests.php?error=invalid');
    exit;
}

$stmt = $pdo->prepare("
    SELECT tar.*, ol.amount, ol.payment_method, ol.transaction_category, ol.person_id, ol.invoice_no, ol.created_at
    FROM transaction_approval_requests tar
    INNER JOIN operator_ledger ol ON ol.ID = tar.ledger_id
    WHERE tar.ID = :id AND tar.status = 'pending'
    LIMIT 1
");
$stmt->execute(['id' => $requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    header('Location: transaction_approval_requests.php?error=notfound');
    exit;
}

if ($reason === '') {
    header('Location: transaction_approval_requests.php?error=' . urlencode('Reason is required.'));
    exit;
}

$pdo->beginTransaction();

try {
    if ($actionType === 'accept') {
        $stmt = $pdo->prepare("
            UPDATE transaction_approval_requests
            SET status = 'accepted',
                accountant_id = :accountant_id,
                accountant_reason = :reason,
                reviewed_at = NOW()
            WHERE ID = :id
        ");
        $stmt->execute([
            'accountant_id' => $accountantId,
            'reason' => $reason,
            'id' => $requestId
        ]);

        $stmt = $pdo->prepare("
            UPDATE operator_ledger
            SET settlement_status = 'accepted',
                is_removed = 0,
                removed_at = NULL,
                removed_by = NULL,
                removed_reason = NULL
            WHERE ID = :ledger_id
        ");
        $stmt->execute(['ledger_id' => $request['ledger_id']]);

        if (tableExists($pdo, 'accountant_ledger')) {
            $paymentMethod = function_exists('normalizeAccountantCollectionMethod')
                ? normalizeAccountantCollectionMethod((string)$request['payment_method'])
                : (string)$request['payment_method'];

            $note = 'Accepted ' . (string)$request['payment_method']
                . ' transaction invoice ' . (string)($request['invoice_no'] ?: ('#' . $request['ledger_id']))
                . ' | ' . $reason;

            $existsStmt = $pdo->prepare("
                SELECT ID
                FROM accountant_ledger
                WHERE reference_id = ?
                  AND entry_type = 'transaction_approval'
                LIMIT 1
            ");
            $existsStmt->execute([(int)$request['ledger_id']]);

            if (!$existsStmt->fetchColumn()) {
               $cols = [
    'entry_type',
    'transaction_type', // ✅ REQUIRED FIX
    'amount',
    'payment_method',
    'notes',
    'created_by',
    'created_at'
];

$vals = [
    'transaction_approval',
    'collection', // ✅ REQUIRED FIX
    (float)$request['amount'],
    $paymentMethod,
    $note,
    $accountantId,
    date('Y-m-d H:i:s')
];
                if (columnExists($pdo, 'accountant_ledger', 'reference_id')) {
                    $cols[] = 'reference_id';
                    $vals[] = (int)$request['ledger_id'];
                }
                if (columnExists($pdo, 'accountant_ledger', 'transaction_category')) {
                    $cols[] = 'transaction_category';
                    $vals[] = (string)$request['transaction_category'];                }

                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $stmt = $pdo->prepare('INSERT INTO accountant_ledger (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')');
                $stmt->execute($vals);
            }
        }

        if (function_exists('systemLog')) {
            systemLog($pdo, $accountantId, 'transaction_approval', 'accept', 'Accepted ledger #' . (int)$request['ledger_id'], (int)$request['ledger_id']);
        }
        if (function_exists('personLog') && (int)$request['person_id'] > 0) {
            personLog($pdo, $accountantId, (int)$request['person_id'], 'transaction_approval_accept', 'Accepted non-cash transaction #' . (int)$request['ledger_id']);
        }
    } else {
        $stmt = $pdo->prepare("
            UPDATE transaction_approval_requests
            SET status = 'rejected',
                accountant_id = :accountant_id,
                accountant_reason = :reason,
                reviewed_at = NOW()
            WHERE ID = :id
        ");
        $stmt->execute([
            'accountant_id' => $accountantId,
            'reason' => $reason,
            'id' => $requestId
        ]);

        $stmt = $pdo->prepare("
            UPDATE operator_ledger
            SET settlement_status = 'rejected',
                is_removed = 1,
                removed_at = NOW(),
                removed_by = :removed_by,
                removed_reason = :reason
            WHERE ID = :ledger_id
        ");
        $stmt->execute([
            'removed_by' => $accountantId,
            'reason' => $reason,
            'ledger_id' => $request['ledger_id']
        ]);

        if (function_exists('systemLog')) {
            systemLog($pdo, $accountantId, 'transaction_approval', 'reject', 'Rejected ledger #' . (int)$request['ledger_id'], (int)$request['ledger_id']);
        }
        if (function_exists('personLog') && (int)$request['person_id'] > 0) {
            personLog($pdo, $accountantId, (int)$request['person_id'], 'transaction_approval_reject', 'Rejected non-cash transaction #' . (int)$request['ledger_id']);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: transaction_approval_requests.php?error=' . urlencode($e->getMessage()));
    exit;
}

header('Location: transaction_approval_requests.php?success=done');
exit;
