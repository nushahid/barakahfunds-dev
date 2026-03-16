<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'accountant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: all_transactions.php');
    exit;
}

$ledgerId = (int)($_POST['ledger_id'] ?? 0);

if ($ledgerId <= 0 || !tableExists($pdo, 'operator_ledger')) {
    header('Location: all_transactions.php');
    exit;
}

$currentUserId = 0;
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $currentUserId = (int)($_SESSION['user']['ID'] ?? $_SESSION['user']['id'] ?? 0);
} else {
    $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE operator_ledger
        SET is_removed = 1,
            removed_at = NOW(),
            removed_by = :removed_by
        WHERE ID = :id
        LIMIT 1
    ");
    $stmt->execute([
        'id' => $ledgerId,
        'removed_by' => $currentUserId > 0 ? $currentUserId : null,
    ]);

    if (tableExists($pdo, 'transaction_delete_requests')) {
        $stmt2 = $pdo->prepare("
            UPDATE transaction_delete_requests
            SET status = 'accepted'
            WHERE ledger_id = :ledger_id
        ");
        $stmt2->execute(['ledger_id' => $ledgerId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

header('Location: all_transactions.php');
exit;