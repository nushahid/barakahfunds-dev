<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'accountant');

$accountantId = getLoggedInUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: transaction_delete_requests.php');
    exit;
}

$requestId = (int)($_POST['request_id'] ?? 0);
$actionType = trim($_POST['action_type'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if ($requestId <= 0 || !in_array($actionType, ['accept', 'reject'], true)) {
    header('Location: transaction_delete_requests.php?error=invalid');
    exit;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM transaction_delete_requests
    WHERE ID = :id AND status = 'pending'
    LIMIT 1
");
$stmt->execute(['id' => $requestId]);
$request = $stmt->fetch();

if (!$request) {
    header('Location: transaction_delete_requests.php?error=notfound');
    exit;
}

$pdo->beginTransaction();

try {
    if ($actionType === 'accept') {
        if ($reason === '') {
            throw new Exception('Reason is required for acceptance.');
        }

        // mark request accepted
        $stmt = $pdo->prepare("
            UPDATE transaction_delete_requests
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

        // soft remove transaction
        $stmt = $pdo->prepare("
            UPDATE operator_ledger
            SET is_removed = 1,
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
    } else {
        $stmt = $pdo->prepare("
            UPDATE transaction_delete_requests
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
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: transaction_delete_requests.php?error=' . urlencode($e->getMessage()));
    exit;
}

header('Location: transaction_delete_requests.php?success=done');
exit;
