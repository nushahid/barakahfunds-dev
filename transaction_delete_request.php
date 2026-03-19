<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'operator','accountant','admin');

$uid = getLoggedInUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: transactions.php');
    exit;
}

$ledgerId = (int)($_POST['ledger_id'] ?? 0);
if ($ledgerId <= 0) {
    header('Location: transactions.php?error=invalid');
    exit;
}

// check transaction belongs to operator and is not removed
$stmt = $pdo->prepare("
    SELECT ID
    FROM operator_ledger
    WHERE ID = :id
      AND operator_id = :uid
      AND COALESCE(is_removed, 0) = 0
    LIMIT 1
");
$stmt->execute([
    'id' => $ledgerId,
    'uid' => $uid
]);
$tx = $stmt->fetch();

if (!$tx) {
    header('Location: transactions.php?error=notfound');
    exit;
}

// check if pending request already exists
$stmt = $pdo->prepare("
    SELECT ID
    FROM transaction_delete_requests
    WHERE ledger_id = :ledger_id
      AND status = 'pending'
    LIMIT 1
");
$stmt->execute(['ledger_id' => $ledgerId]);

if (!$stmt->fetch()) {
    $stmt = $pdo->prepare("
        INSERT INTO transaction_delete_requests (ledger_id, requested_by, status)
        VALUES (:ledger_id, :requested_by, 'pending')
    ");
    $stmt->execute([
        'ledger_id' => $ledgerId,
        'requested_by' => $uid
    ]);
}

header('Location: transactions.php?success=request_sent');
exit;
