<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

// 1. Security Check: Only logged-in users can search donors
if (!getLoggedInUserId()) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$query = trim((string)($_GET['q'] ?? ''));
$results = [];

if (strlen($query) >= 2 && tableExists($pdo, 'people')) {
    try {
        // 2. Search by Name or Phone
        // We limit to 10 results for speed and clean UI
        $stmt = $pdo->prepare("
            SELECT ID, name, phone, city 
            FROM people 
            WHERE name LIKE ? OR phone LIKE ? 
            ORDER BY name ASC 
            LIMIT 10
        ");
        
        $searchTerm = "%$query%";
        $stmt->execute([$searchTerm, $searchTerm]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $results[] = [
                'id'    => $row['ID'],
                'name'  => $row['name'],
                // Formatting the label for the dropdown
                'phone' => $row['phone'] ?: 'No Phone',
                'city'  => $row['city'] ?: ''
            ];
        }
    } catch (Throwable $e) {
        // Log error internally if needed
    }
}

// 3. Return JSON response
header('Content-Type: application/json');
echo json_encode($results);