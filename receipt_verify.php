<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

$id = max(0, (int)($_GET['id'] ?? 0));
$token = (string)($_GET['token'] ?? '');
$row = findReceiptRecord($pdo, $id);
$valid = false;
$reason = 'Receipt not found.';

function verifySourceInfo(array $row): array
{
    $sourceType = (string)($row['source_type'] ?? '');
    $contributorCount = (int)($row['contributor_count'] ?? 0);
    $sourceNote = trim((string)($row['source_note'] ?? ''));

    if ($sourceType === '' && !empty($row['notes'])) {
        $notes = (string)$row['notes'];
        if (stripos($notes, 'Collected on behalf of others') !== false) {
            $sourceType = 'group_collected';
            if ($contributorCount <= 0 && preg_match('/Contributor count:\s*(\d+)/i', $notes, $m)) {
                $contributorCount = (int)$m[1];
            }
            if ($sourceNote === '' && preg_match('/Source note:\s*([^|]+)/i', $notes, $m)) {
                $sourceNote = trim($m[1]);
            }
        } else {
            $sourceType = 'self';
        }
    }

    $isCollectedForOthers = ($sourceType === 'group_collected');

    return [
        'label' => $isCollectedForOthers ? 'Collected on behalf of others' : 'Own donation',
        'is_collected_for_others' => $isCollectedForOthers,
        'contributor_count' => $contributorCount,
        'source_note' => $sourceNote,
    ];
}

if ($row) {
    $invoice = (string)($row['invoice_no'] ?? '');
    $real = (string)($row['receipt_token'] ?? receiptVerificationToken($id, $invoice));

    if (hash_equals($real, $token)) {
        if (strtotime((string)$row['created_at'] . ' +30 days') >= time()) {
            $valid = true;
        } else {
            $reason = 'This QR/receipt has expired after 30 days. Please contact mosque management for manual verification.';
        }
    } else {
        $reason = 'Receipt token is invalid.';
    }
}

$sourceInfo = $valid ? verifySourceInfo($row) : null;
?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/style.css">
    <title>Receipt Verification</title>
</head>
<body>
<main class="main">
    <div class="card">
        <?php if ($valid): ?>
            <h1 class="title">Receipt Verified</h1>

            <div class="meter-row"><div><strong>Invoice</strong></div><div><?= e((string)$row['invoice_no']) ?></div></div>
            <div class="meter-row"><div><strong>Donor</strong></div><div><?= e((string)($row['person_name'] ?? $row['name'] ?? '')) ?></div></div>
            <div class="meter-row"><div><strong>Amount</strong></div><div><?= money((float)$row['amount']) ?></div></div>
            <div class="meter-row"><div><strong>Date</strong></div><div><?= e((string)$row['created_at']) ?></div></div>
            <div class="meter-row"><div><strong>Donation Source</strong></div><div><?= e((string)$sourceInfo['label']) ?></div></div>

            <?php if ($sourceInfo['is_collected_for_others'] && (int)$sourceInfo['contributor_count'] > 0): ?>
                <div class="meter-row"><div><strong>Contributor Count</strong></div><div><?= (int)$sourceInfo['contributor_count'] ?></div></div>
            <?php endif; ?>

            <?php if ($sourceInfo['is_collected_for_others'] && $sourceInfo['source_note'] !== ''): ?>
                <div class="meter-row"><div><strong>Source Note</strong></div><div><?= e((string)$sourceInfo['source_note']) ?></div></div>
            <?php endif; ?>

        <?php else: ?>
            <h1 class="title">Verification Failed</h1>
            <div class="alert error"><?= e($reason) ?></div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>