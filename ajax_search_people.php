<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

$q = trim($_GET['q'] ?? '');

if ($q === '') {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
SELECT ID,name,city,phone
FROM people
WHERE name LIKE ?
OR phone LIKE ?
OR city LIKE ?
OR CAST(ID AS CHAR) LIKE ?
ORDER BY name
LIMIT 20
");

$like = "%$q%";
$stmt->execute([$like,$like,$like,$like]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));