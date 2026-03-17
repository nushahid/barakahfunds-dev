<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();

$personId = (int)($_GET['person_id'] ?? $_POST['profile_person_id'] ?? 0);
$target = 'add_expense.php';

if ($personId > 0) {
    $target .= '?person_id=' . $personId;
}

header('Location: ' . $target);
exit;
