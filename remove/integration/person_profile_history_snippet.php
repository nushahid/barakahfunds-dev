<?php
// Put this in person_profile.php where you load combined history for one donor/person.
// Goal: after job/role change, the same person profile still shows donation + operator expense history.

$personId = (int)($_GET['id'] ?? 0);
$history = [];

if ($personId > 0) {
    // Donations linked directly to person/donor profile.
    if (tableExists($pdo, 'transactions')) {
        $stmt = $pdo->prepare("SELECT created_at, amount, payment_method, notes, 'donation' AS entry_kind FROM transactions WHERE person_id = ?");
        $stmt->execute([$personId]);
        $history = array_merge($history, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Operator expenses also linked to same person/donor profile.
    if (tableExists($pdo, 'operator_ledger')) {
        $stmt = $pdo->prepare("SELECT created_at, amount, payment_method, notes, 'operator_expense' AS entry_kind FROM operator_ledger WHERE person_id = ? AND transaction_type = 'expense'");
        $stmt->execute([$personId]);
        $history = array_merge($history, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    usort($history, static function ($a, $b) {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
}
