<?php
// Put this immediately after new user INSERT succeeds.
// Purpose: every user should also have one donor/person row, linked with user_id.

$newUserId = (int)$pdo->lastInsertId();
$newUserName = trim((string)($_POST['name'] ?? ''));
$newUserPhone = trim((string)($_POST['phone'] ?? ''));
$newUserCity = trim((string)($_POST['city'] ?? ''));

$peopleColumns = [];
try {
    $stmt = $pdo->query('SHOW COLUMNS FROM `people`');
    $peopleColumns = array_map(static fn($row) => (string)$row['Field'], $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $peopleColumns = [];
}

if ($peopleColumns) {
    $linkColumn = null;
    foreach (['user_id', 'linked_user_id', 'account_user_id', 'member_user_id'] as $candidate) {
        if (in_array($candidate, $peopleColumns, true)) {
            $linkColumn = $candidate;
            break;
        }
    }

    if ($linkColumn) {
        $check = $pdo->prepare("SELECT ID FROM people WHERE `$linkColumn` = ? LIMIT 1");
        $check->execute([$newUserId]);
        $exists = $check->fetchColumn();

        if (!$exists) {
            $insertCols = ['name'];
            $insertVals = [$newUserName];
            $marks = ['?'];

            if (in_array('phone', $peopleColumns, true)) { $insertCols[] = 'phone'; $insertVals[] = $newUserPhone; $marks[] = '?'; }
            if (in_array('city', $peopleColumns, true)) { $insertCols[] = 'city'; $insertVals[] = $newUserCity; $marks[] = '?'; }
            $insertCols[] = $linkColumn; $insertVals[] = $newUserId; $marks[] = '?';

            $sql = 'INSERT INTO people (`' . implode('`,`', $insertCols) . '`) VALUES (' . implode(',', $marks) . ')';
            $pdo->prepare($sql)->execute($insertVals);
        }
    }
}
