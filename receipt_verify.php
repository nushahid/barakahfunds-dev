<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

// 1. Get parameters from the URL (provided by the QR code)
$id = max(0, (int)($_GET['id'] ?? 0));
$token = (string)($_GET['token'] ?? '');

// 2. Fetch the record from the database
// Ensure findReceiptRecord selects the 'is_removed' column
$row = findReceiptRecord($pdo, $id);

$valid = false;
$reason = 'Receipt not found.';

if ($row) {
    // 3. CHECK: If the transaction is marked as removed, verification MUST fail
    if (isset($row['is_removed']) && (int)$row['is_removed'] === 1) {
        $reason = 'This transaction has been removed/deleted and is no longer valid.';
        $valid = false;
    } 
    // 4. CHECK: Verify the security token (if your system uses one)
    // Adjust 'verification_token' to match your actual column name
    elseif (!empty($token) && isset($row['verification_token']) && $token !== $row['verification_token']) {
        $reason = 'Invalid verification token.';
        $valid = false;
    }
    else {
        // Receipt is found, not removed, and token is valid
        $valid = true;
    }
}

// 5. Helper function for display (optional/existing)
function verifySourceInfo(array $row): array
{
    $sourceType = (string)($row['source_type'] ?? '');
    return [
        'label' => ($sourceType === 'group_collected') ? 'Collected on behalf of others' : 'Individual Donation',
        'is_collected_for_others' => ($sourceType === 'group_collected'),
        'contributor_count' => (int)($row['contributor_count'] ?? 0),
        'source_note' => trim((string)($row['source_note'] ?? ''))
    ];
}

if ($valid) {
    $sourceInfo = verifySourceInfo($row);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt Verification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f8fafc; font-family: sans-serif; padding: 20px; }
        .card { max-width: 500px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .title { text-align: center; margin-bottom: 20px; color: #1e293b; }
        .meter-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .success-head { color: #16a34a; text-align: center; font-weight: bold; font-size: 1.2rem; }
        .error-head { color: #dc2626; text-align: center; font-weight: bold; font-size: 1.2rem; }
        .alert { padding: 15px; border-radius: 8px; margin-top: 10px; text-align: center; }
        .error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>

<main>
    <div class="card">
        <?php if ($valid): ?>
            <div class="success-head">✓ Receipt Verified</div>
            <h1 class="title">Official Record</h1>

            <div class="meter-row"><div><strong>Invoice #</strong></div><div><?= e((string)$row['invoice_no']) ?></div></div>
            <div class="meter-row"><div><strong>Name</strong></div><div><?= e((string)($row['person_name'] ?? $row['name'] ?? 'N/A')) ?></div></div>
            <div class="meter-row"><div><strong>Amount</strong></div><div><?= money((float)$row['amount']) ?></div></div>
            <div class="meter-row"><div><strong>Date</strong></div><div><?= e(date('d/m/Y H:i', strtotime($row['created_at']))) ?></div></div>
            <div class="meter-row"><div><strong>Status</strong></div><div><span style="color:green">Active</span></div></div>

            <?php if ($sourceInfo['is_collected_for_others']): ?>
                <div class="meter-row"><div><strong>Note</strong></div><div><?= e($sourceInfo['source_note']) ?></div></div>
            <?php endif; ?>

        <?php else: ?>
            <div class="error-head">✕ Verification Failed</div>
            <div class="alert error">
                <strong>Error:</strong> <?= e($reason) ?>
            </div>
            <p style="text-align:center; font-size: 0.9rem; color: #64748b; margin-top: 20px;">
                If you believe this is a mistake, please contact the administration.
            </p>
        <?php endif; ?>
    </div>
</main>

</body>
</html>