<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$id = max(0, (int)($_GET['id'] ?? 0));
$row = findReceiptRecord($pdo, $id);
if (!$row) {
    exit('Receipt not found.');
}

function receiptAbsoluteUrl(string $path): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');
    if ($base === '' || $base === '.') {
        $base = '';
    }
    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

function receiptEventName(PDO $pdo, array $row): string
{
    if ((string)($row['transaction_category'] ?? '') !== 'event') {
        return '';
    }

    $referenceId = (int)($row['reference_id'] ?? 0);
    if ($referenceId > 0 && tableExists($pdo, 'event_details')) {
        $eventIdCol = columnExists($pdo, 'event_details', 'event_id') ? 'event_id' : (columnExists($pdo, 'event_details', 'eid') ? 'eid' : '');
        if ($eventIdCol !== '') {
            $sql = 'SELECT e.name
                    FROM event_details d
                    INNER JOIN events e ON e.ID = d.' . $eventIdCol . '
                    WHERE d.ID = ?
                    LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$referenceId]);
            $name = trim((string)$stmt->fetchColumn());
            if ($name !== '') {
                return $name;
            }
        }
    }

    $notes = (string)($row['notes'] ?? '');
    if (preg_match('/Event:\s*([^|]+)/i', $notes, $m)) {
        return trim($m[1]);
    }

    return '';
}

function receiptDonationType(PDO $pdo, array $row): string
{
    $category = (string)($row['transaction_category'] ?? 'one_time');
    if ($category === 'monthly') return 'Monthly';
    if ($category === 'loan') return 'Loan';
    if ($category === 'event') {
        $eventName = receiptEventName($pdo, $row);
        return $eventName !== '' ? $eventName : 'Event';
    }
    return 'One Time';
}

function receiptDescription(PDO $pdo, array $row): string
{
    $category = (string)($row['transaction_category'] ?? 'one_time');
    if ($category === 'event') {
        $eventName = receiptEventName($pdo, $row);
        return $eventName !== '' ? $eventName : 'Event Donation';
    }
    if ($category === 'monthly') return 'Monthly Donation';
    if ($category === 'loan') return 'Loan Donation';
    return 'One Time Donation';
}

function receiptMonthlyAdvanceText(PDO $pdo, array $row): string
{
    if ((string)($row['transaction_category'] ?? '') !== 'monthly') {
        return '';
    }

    $personId = (int)($row['person_id'] ?? 0);
    if ($personId <= 0 || !function_exists('getPersonCurrentPlan')) {
        return '';
    }

    $plan = getPersonCurrentPlan($pdo, $personId);
    $planAmount = (float)($plan['amount'] ?? 0);
    $paidAmount = (float)($row['amount'] ?? 0);
    if ($planAmount <= 0 || $paidAmount <= 0) {
        return '';
    }

    $months = (int)round($paidAmount / $planAmount);
    if ($months <= 1) {
        return '';
    }

    return ' This monthly donation has been received in advance for <strong>' . $months . ' months</strong>.';
}

function receiptDonorAddress(array $row): string
{
    $choices = [
        (string)($row['italy_reference_address'] ?? ''),
        (string)($row['home_town_address'] ?? ''),
        (string)($row['city'] ?? ''),
    ];
    foreach ($choices as $value) {
        $value = trim($value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function receiptSourceInfo(array $row): array
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
        'source_type' => $sourceType !== '' ? $sourceType : 'self',
        'is_collected_for_others' => $isCollectedForOthers,
        'label' => $isCollectedForOthers ? 'Collected on behalf of others' : 'Own donation',
        'contributor_count' => $contributorCount,
        'source_note' => $sourceNote,
    ];
}

$personId = (int)($row['person_id'] ?? 0);
$extraPerson = [];
if ($personId > 0 && tableExists($pdo, 'people')) {
    $personStmt = $pdo->prepare('SELECT ID, name, phone, city, italy_reference_address, home_town_address FROM people WHERE ID = ? LIMIT 1');
    $personStmt->execute([$personId]);
    $extraPerson = $personStmt->fetch() ?: [];
    if ($extraPerson) {
        $row = array_merge($extraPerson, $row);
    }
}

$invoice = (string)($row['invoice_no'] ?? 'INV-000000');
$token = (string)($row['receipt_token'] ?? receiptVerificationToken($id, $invoice));
$verifyUrl = receiptAbsoluteUrl('receipt_verify.php?id=' . $id . '&token=' . urlencode($token));
$verifyQr = 'https://quickchart.io/qr?size=140&text=' . urlencode($verifyUrl);
$logoPath = receiptLogoPath($pdo);
$donorName = trim((string)($row['display_party_name'] ?? $row['person_name'] ?? $row['name'] ?? ''));
$donorPhone = trim((string)($row['person_phone'] ?? $row['phone'] ?? ''));
$donorAddress = receiptDonorAddress($row);
$operatorName = trim((string)($row['operator_name'] ?? 'Donation Team'));
$orgName = trim((string)appName($pdo));
$orgAddress = trim((string)getSetting($pdo, 'mosque_address', ''));
$orgPhone = trim((string)getSetting($pdo, 'mosque_phone', ''));
$orgEmail = trim((string)getSetting($pdo, 'mosque_email', ''));
$orgWebsite = trim((string)getSetting($pdo, 'mosque_website', ''));
$createdAt = strtotime((string)($row['created_at'] ?? 'now'));
$receiptNo = $invoice;
$donationType = receiptDonationType($pdo, $row);
$descriptionText = receiptDescription($pdo, $row);
$advanceText = receiptMonthlyAdvanceText($pdo, $row);
$sourceInfo = receiptSourceInfo($row);
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?= e($invoice) ?></title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; background:#eef2f7; margin:0; color:#0f172a; }
    .toolbar { max-width: 920px; margin: 18px auto 0; display:flex; justify-content:flex-end; }
    .toolbar button { border:0; background:#0f172a; color:#fff; padding:10px 16px; border-radius:10px; font-size:14px; cursor:pointer; }
    .page { max-width: 920px; margin: 14px auto 28px; background:#fff; box-shadow:0 12px 32px rgba(15,23,42,.10); border-radius:16px; overflow:hidden; }
    .inner { padding: 26px 30px 30px; }
    .header { display:flex; gap:18px; align-items:flex-start; border-bottom:2px solid #dbe4f0; padding-bottom:18px; }
    .logo-wrap { width:94px; flex:0 0 94px; }
    .logo-wrap img { max-width:94px; max-height:94px; object-fit:contain; }
    .brand { flex:1; }
    .brand h1 { margin:0; font-size:30px; line-height:1.15; font-weight:800; color:#0b3b66; }
    .brand .quote { margin-top:6px; font-size:17px; font-weight:700; color:#0f172a; }
    .titlebar { margin-top:16px; display:flex; justify-content:space-between; align-items:center; gap:12px; }
    .titlebar .slip { font-size:28px; font-weight:800; color:#0b3b66; letter-spacing:.4px; }
    .titlebar .no { font-size:15px; color:#334155; }
    .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-top:18px; }
    .panel { border:1px solid #dbe4f0; border-radius:14px; padding:16px 18px; }
    .panel h3 { margin:0 0 12px; font-size:16px; text-transform:uppercase; letter-spacing:.5px; color:#0b3b66; }
    .line { margin:0 0 7px; font-size:15px; }
    .line strong { color:#0f172a; }
    .message { margin-top:20px; border:1px solid #dbe4f0; border-radius:14px; padding:18px; background:#fafcff; font-size:16px; line-height:1.7; }
    .message p { margin:0; }
    .source-box { margin-top:18px; border:1px solid #dbe4f0; border-radius:14px; padding:16px 18px; background:#f8fbff; }
    .source-box h3 { margin:0 0 10px; font-size:16px; text-transform:uppercase; letter-spacing:.5px; color:#0b3b66; }
    table { width:100%; border-collapse:collapse; margin-top:18px; }
    th, td { border:1px solid #dbe4f0; padding:12px 10px; text-align:left; font-size:15px; }
    th { background:#f8fbff; color:#0b3b66; font-weight:800; }
    .amount { white-space:nowrap; font-weight:800; }
    .footer { margin-top:20px; display:flex; justify-content:space-between; align-items:flex-end; gap:16px; }
    .received { font-size:16px; font-weight:700; }
    .qr { text-align:right; }
    .qr img { width:110px; height:110px; display:block; margin-left:auto; }
    .qr .small { margin-top:6px; font-size:11px; color:#64748b; max-width:210px; word-break:break-all; }
    @media print {
        body { background:#fff; }
        .toolbar { display:none; }
        .page { box-shadow:none; margin:0; max-width:none; border-radius:0; }
    }
    @media (max-width: 700px) {
        .header, .info-grid, .footer, .titlebar { display:block; }
        .logo-wrap { margin-bottom:12px; }
        .titlebar .no { margin-top:6px; }
        .qr { margin-top:16px; text-align:left; }
        .qr img { margin-left:0; }
    }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Print Receipt</button></div>
<div class="page">
    <div class="inner">
        <div class="header">
            <?php if ($logoPath !== ''): ?>
                <div class="logo-wrap"><img src="<?= e($logoPath) ?>" alt="Logo"></div>
            <?php endif; ?>
            <div class="brand">
                <h1><?= e($orgName !== '' ? $orgName : 'Center Donation Receipt') ?></h1>
                <div class="quote">“Save yourself from hellfire by giving even half a date-fruit in charity.”</div>
            </div>
        </div>

        <div class="titlebar">
            <div class="slip">DONATION RECEIPT</div>
            <div class="no"><strong>Receipt No:</strong> <?= e($receiptNo) ?></div>
        </div>

        <div class="info-grid">
            <div class="panel">
                <h3>Donor Information</h3>
                <?php if ($donorName !== ''): ?><div class="line"><strong>Name:</strong> <?= e($donorName) ?></div><?php endif; ?>
                <?php if ($donorPhone !== ''): ?><div class="line"><strong>Phone:</strong> <?= e($donorPhone) ?></div><?php endif; ?>
                <?php if ($donorAddress !== ''): ?><div class="line"><strong>Address:</strong> <?= e($donorAddress) ?></div><?php endif; ?>
                <?php if ($personId > 0): ?><div class="line"><strong>Donor ID:</strong> <?= (int)$personId ?></div><?php endif; ?>
            </div>
            <div class="panel">
                <h3>Community Center Information</h3>
                <?php if ($orgName !== ''): ?><div class="line"><strong>Name:</strong> <?= e($orgName) ?></div><?php endif; ?>
                <?php if ($orgAddress !== ''): ?><div class="line"><strong>Address:</strong> <?= e($orgAddress) ?></div><?php endif; ?>
                <?php if ($orgPhone !== ''): ?><div class="line"><strong>Phone:</strong> <?= e($orgPhone) ?></div><?php endif; ?>
                <?php if ($orgEmail !== ''): ?><div class="line"><strong>Email:</strong> <?= e($orgEmail) ?></div><?php endif; ?>
                <?php if ($orgWebsite !== ''): ?><div class="line"><strong>Website:</strong> <?= e($orgWebsite) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="message">
            <p><strong>Assalam-u-Alikum,</strong><br>
            This is a confirmation receipt of your kind donation towards <strong><?= e($orgName) ?></strong> <strong><?= e($donationType) ?></strong> donation. Charity does not decrease wealth; it increases blessings in this life and the next. Thank You very much, Jazak-a-Allah Khayr.</br><?= $advanceText ?> <strong><?= e($orgName) ?></strong> Team member.</p>
        </div>
        <?php if ($sourceInfo['is_collected_for_others']): ?>
        <div class="source-box">
            <h3>Donation Source</h3>
            <div class="line"><strong>Type:</strong> <?= e($sourceInfo['label']) ?></div>
            <?php if ((int)$sourceInfo['contributor_count'] > 0): ?>
                <div class="line"><strong>Contributor Count:</strong> <?= (int)$sourceInfo['contributor_count'] ?></div>
            <?php endif; ?>
            <?php if ($sourceInfo['source_note'] !== ''): ?>
                <div class="line"><strong>Source Note:</strong> <?= e($sourceInfo['source_note']) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th style="width:22%">Date</th>
                    <th>Description</th>
                    <th style="width:18%">Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= e(date('d/m/Y', $createdAt)) ?></td>
                    <td><?= e($descriptionText) ?></td>
                    <td class="amount">€<?= e(number_format((float)$row['amount'], 2, '.', '')) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            <div>
                <div class="received">Received by: <?= e($operatorName !== '' ? $operatorName : 'Operator') ?></div>
            </div>
            <div class="qr">
                <img src="<?= e($verifyQr) ?>" alt="Receipt QR">
                <div class="small">Receipt verification QR</div>
            </div>
        </div>
    </div>
</div>
</body>
</html>