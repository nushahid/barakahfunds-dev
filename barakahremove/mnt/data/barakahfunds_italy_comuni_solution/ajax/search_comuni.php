<?php
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
require_once __DIR__ . '/../includes/db.php';
requireRole($pdo, 'operator');

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
$limit = max(5, min(20, (int)($_GET['limit'] ?? 10)));

if ($q === '') {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $q) ?: $q;
$normalized = strtolower($normalized);
$normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?: $normalized;
$normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?: $normalized);

$sql = "SELECT id, istat_code, comune_name, province_code, province_name, region_name, cap
        FROM italy_comuni
        WHERE is_active = 1
          AND (
                comune_name LIKE :prefix
                OR comune_name LIKE :contains
                OR comune_name_normalized LIKE :normalized_prefix
                OR comune_name_normalized LIKE :normalized_contains
              )
        ORDER BY
            CASE WHEN comune_name_normalized LIKE :normalized_prefix THEN 0 ELSE 1 END,
            comune_name ASC
        LIMIT {$limit}";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':prefix' => $q . '%',
    ':contains' => '%' . $q . '%',
    ':normalized_prefix' => $normalized . '%',
    ':normalized_contains' => '%' . $normalized . '%',
]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
