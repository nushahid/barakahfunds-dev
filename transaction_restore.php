<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'accountant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: deleted_transactions.php');
    exit;
}

$ledgerId = (int)($_POST['ledger_id'] ?? 0);

if ($ledgerId <= 0 || !tableExists($pdo, 'operator_ledger')) {
    header('Location: deleted_transactions.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE operator_ledger
        SET is_removed = 0,
            removed_at = NULL,
            removed_by = NULL
        WHERE ID = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $ledgerId]);
} catch (Throwable $e) {
    // optionally log error
}

header('Location: deleted_transactions.php');
exit;