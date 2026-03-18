<?php
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
$limit = max(5, min(20, (int)($_GET['limit'] ?? 10)));

if ($q === '') {
    echo json_encode([
        'ok' => true,
        'items' => []
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$items = [];

/*
 * Step 1:
 * If exact active comune exists, return only exact match(es).
 */
$sqlExact = "
    SELECT
        id,
        comune_name,
        province_code,
        province_name,
        region_name,
        cap
    FROM italy_comuni
    WHERE is_active = 1
      AND comune_name = :exact_name
    ORDER BY comune_name ASC
    LIMIT $limit
";

$stmtExact = $pdo->prepare($sqlExact);
$stmtExact->execute([
    ':exact_name' => $q
]);

$rows = $stmtExact->fetchAll(PDO::FETCH_ASSOC) ?: [];

/*
 * Step 2:
 * If no exact match found, then do normal search.
 */
if (!$rows) {
    $prefix = $q . '%';
    $contains = '%' . $q . '%';

    $sqlSearch = "
        SELECT
            id,
            comune_name,
            province_code,
            province_name,
            region_name,
            cap
        FROM italy_comuni
        WHERE is_active = 1
          AND (
                comune_name LIKE :comune_prefix
                OR comune_name LIKE :comune_contains
                OR province_name LIKE :province_contains
                OR region_name LIKE :region_contains
                OR cap LIKE :cap_prefix
              )
        ORDER BY
            CASE
                WHEN comune_name LIKE :order_prefix THEN 0
                ELSE 1
            END,
            comune_name ASC
        LIMIT $limit
    ";

    $stmtSearch = $pdo->prepare($sqlSearch);
    $stmtSearch->execute([
        ':comune_prefix' => $prefix,
        ':comune_contains' => $contains,
        ':province_contains' => $contains,
        ':region_contains' => $contains,
        ':cap_prefix' => $prefix,
        ':order_prefix' => $prefix,
    ]);

    $rows = $stmtSearch->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

foreach ($rows as $row) {
    $comune = trim((string)($row['comune_name'] ?? ''));
    $provinceCode = trim((string)($row['province_code'] ?? ''));
    $provinceName = trim((string)($row['province_name'] ?? ''));
    $regionName = trim((string)($row['region_name'] ?? ''));
    $cap = trim((string)($row['cap'] ?? ''));

    $labelParts = [];
    if ($comune !== '') $labelParts[] = $comune;
    if ($provinceName !== '') $labelParts[] = '- ' . $provinceName;
    if ($provinceCode !== '') $labelParts[] = '(' . $provinceCode . ')';
    if ($regionName !== '') $labelParts[] = '- ' . $regionName;
    if ($cap !== '') $labelParts[] = '- CAP ' . $cap;

    $items[] = [
        'id' => (int)($row['id'] ?? 0),
        'value' => $comune,
        'comune' => $comune,
        'province_code' => $provinceCode,
        'province_name' => $provinceName,
        'region_name' => $regionName,
        'cap' => $cap,
        'label' => trim(implode(' ', $labelParts))
    ];
}

echo json_encode([
    'ok' => true,
    'items' => $items
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);