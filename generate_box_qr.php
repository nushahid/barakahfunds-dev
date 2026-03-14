<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    http_response_code(400);
    exit('Missing token');
}

$targetUrl =
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
    . $_SERVER['HTTP_HOST']
    . '/anonymous_collection_entry.php?token=' . urlencode($token);

// Make sure this file exists in your project
require_once __DIR__ . '/phpqrcode/qrlib.php';

header('Content-Type: image/png');
\QRcode::png($targetUrl, false, QR_ECLEVEL_M, 6, 2);
exit;