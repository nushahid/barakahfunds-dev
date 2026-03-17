<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$uid = getLoggedInUserId();
$errors = [];
$flash = function_exists('getFlash') ? getFlash() : null;
$success = is_array($flash) && (($flash['type'] ?? '') === 'success') ? (string)($flash['message'] ?? '') : '';
$errorFlash = is_array($flash) && (($flash['type'] ?? '') === 'error') ? (string)($flash['message'] ?? '') : '';

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$selectedDonorId = (int)($_POST['person_id'] ?? ($_GET['person_id'] ?? 0));
$selectedDonor = null;
$results = [];
$expectedCommitmentId = (int)($_POST['expected_commitment_id'] ?? ($_GET['expected_commitment_id'] ?? 0));
$collectNowMode = (int)($_GET['collect_now'] ?? 0) === 1;
$editingCommitment = null;
$formDefaults = [
    'category' => '',
    'amount' => '',
    'payment_method' => 'cash',
    'notes' => '',
    'transaction_date' => date('Y-m-d'),
    'event_id' => 0,
    'is_expected' => 0,
    'due_date' => '',
    'collected_for_others' => 0,
    'contributor_count' => '',
    'source_note' => '',
];

if ($selectedDonorId > 0) {
    $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
    $stmt->execute([$selectedDonorId]);
    $selectedDonor = $stmt->fetch() ?: null;
    if ($selectedDonor) {
        $q = (string)$selectedDonor['name'];
    }
}

if ($expectedCommitmentId > 0 && tableExists($pdo, 'donation_commitments')) {
    $hasExpectedSourceColumns =
        function_exists('columnExists')
        && columnExists($pdo, 'donation_commitments', 'is_collected_for_others')
        && columnExists($pdo, 'donation_commitments', 'contributor_count')
        && columnExists($pdo, 'donation_commitments', 'source_note');

    $sql = '
        SELECT
            dc.ID,
            dc.person_id,
            dc.category,
            dc.event_id,
            dc.expected_amount,
            dc.due_date,
            dc.status,
            dc.notes'
            . ($hasExpectedSourceColumns ? ',
            dc.is_collected_for_others,
            dc.contributor_count,
            dc.source_note' : ',
            0 AS is_collected_for_others,
            NULL AS contributor_count,
            NULL AS source_note') . '
        FROM donation_commitments dc
        WHERE dc.ID = ?
        LIMIT 1
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$expectedCommitmentId]);
    $editingCommitment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editingCommitment) {
        if ($selectedDonorId <= 0) {
            $selectedDonorId = (int)$editingCommitment['person_id'];
            $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
            $stmt->execute([$selectedDonorId]);
            $selectedDonor = $stmt->fetch() ?: null;
            if ($selectedDonor) {
                $q = (string)$selectedDonor['name'];
            }
        }

        $formDefaults = [
            'category' => (string)$editingCommitment['category'],
            'amount' => number_format((float)$editingCommitment['expected_amount'], 2, '.', ''),
            'payment_method' => 'cash',
            'notes' => (string)($editingCommitment['notes'] ?? ''),
            'transaction_date' => date('Y-m-d'),
            'event_id' => (int)($editingCommitment['event_id'] ?? 0),
            'is_expected' => $collectNowMode ? 0 : 1,
            'due_date' => (string)($editingCommitment['due_date'] ?? ''),
            'collected_for_others' => (int)($editingCommitment['is_collected_for_others'] ?? 0),
            'contributor_count' => (string)($editingCommitment['contributor_count'] ?? ''),
            'source_note' => (string)($editingCommitment['source_note'] ?? ''),
        ];
    }
}

if ($q !== '') {
    $stmt = $pdo->prepare("
        SELECT ID, name, city, phone
        FROM people
        WHERE name LIKE ? OR phone LIKE ? OR city LIKE ? OR CAST(ID AS CHAR) LIKE ?
        ORDER BY name ASC
        LIMIT 30
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like, $like]);
    $results = $stmt->fetchAll();
}

function tx_old(string $key, $default = '')
{
    global $formDefaults;

    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }

    return $formDefaults[$key] ?? $default;
}

function tx_payment_mode(string $paymentMethod): string
{
    return match ($paymentMethod) {
        'stripe' => 'stripe_auto',
        'bank', 'online' => 'bank_manual',
        default => 'cash_manual',
    };
}

function tx_save_monthly_agreement(PDO $pdo, int $personId, float $amount, string $paymentMethod, int $uid): void
{
    if ($personId <= 0 || $amount <= 0) {
        return;
    }

    if (
        function_exists('tableExists') &&
        function_exists('columnExists') &&
        tableExists($pdo, 'people') &&
        columnExists($pdo, 'people', 'monthly_subscription')
    ) {
        $pdo->prepare('UPDATE people SET monthly_subscription = 1, updated_by = ?, updated_at = NOW() WHERE ID = ?')
            ->execute([$uid, $personId]);
    }

    if (!function_exists('tableExists') || !tableExists($pdo, 'member_monthly_plans')) {
        return;
    }

    $mode = tx_payment_mode($paymentMethod);
    $existing = function_exists('getPersonCurrentPlan') ? getPersonCurrentPlan($pdo, $personId) : null;

    if ($existing && (int)($existing['active'] ?? 0) === 1) {
        $currentAmount = (float)($existing['amount'] ?? 0);

        if ($currentAmount <= 0) {
            $pdo->prepare('
                UPDATE member_monthly_plans
                SET amount = ?, payment_mode = ?, assigned_operator_id = ?, active = 1, stop_date = NULL
                WHERE ID = ?
            ')->execute([$amount, $mode, $uid, (int)$existing['ID']]);
        } elseif ((string)($existing['payment_mode'] ?? '') !== $mode) {
            $pdo->prepare('
                UPDATE member_monthly_plans
                SET payment_mode = ?, assigned_operator_id = ?, active = 1, stop_date = NULL
                WHERE ID = ?
            ')->execute([$mode, $uid, (int)$existing['ID']]);
        }

        return;
    }

    $pdo->prepare('
        INSERT INTO member_monthly_plans
        (member_id, amount, payment_mode, assigned_operator_id, active, start_date, notes, created_at)
        VALUES (?, ?, ?, ?, 1, CURDATE(), ?, NOW())
    ')->execute([$personId, $amount, $mode, $uid, 'Auto-created from transaction page']);
}

function tx_save_monthly_due(PDO $pdo, int $personId, float $amount, string $dueDate, int $uid, string $notes = ''): void
{
    if (
        !function_exists('tableExists') ||
        !function_exists('columnExists') ||
        !tableExists($pdo, 'member_monthly_dues')
    ) {
        return;
    }

    $dueMonth = date('Y-m-01', strtotime($dueDate));
    $columns = [];
    $values = [];
    $params = [];

    $baseData = [
        'member_id'        => $personId,
        'due_month'        => $dueMonth,
        'expected_amount'  => $amount,
        'status'           => 'pending',
        'created_at'       => date('Y-m-d H:i:s'),
    ];

    if (columnExists($pdo, 'member_monthly_dues', 'due_date')) {
        $baseData['due_date'] = $dueDate;
    }
    if (columnExists($pdo, 'member_monthly_dues', 'notes')) {
        $baseData['notes'] = $notes;
    }
    if (columnExists($pdo, 'member_monthly_dues', 'created_by')) {
        $baseData['created_by'] = $uid;
    }
    if (columnExists($pdo, 'member_monthly_dues', 'paid_amount')) {
        $baseData['paid_amount'] = 0;
    }

    foreach ($baseData as $column => $value) {
        if (columnExists($pdo, 'member_monthly_dues', $column)) {
            $columns[] = $column;
            $values[] = '?';
            $params[] = $value;
        }
    }

    if ($columns) {
        $sql = 'INSERT INTO member_monthly_dues (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        $pdo->prepare($sql)->execute($params);
    }
}

function tx_save_expected_commitment(
    PDO $pdo,
    int $personId,
    string $category,
    float $amount,
    string $dueDate,
    int $uid,
    int $eventId = 0,
    string $notes = '',
    bool $isCollectedForOthers = false,
    int $contributorCount = 0,
    string $sourceNote = ''
): int {
    if (!function_exists('tableExists') || !function_exists('columnExists') || !tableExists($pdo, 'donation_commitments')) {
        throw new RuntimeException('donation_commitments table not found. Run the SQL update first.');
    }

    $insertData = [
        'person_id'                => $personId,
        'category'                 => $category,
        'event_id'                 => $eventId > 0 ? $eventId : null,
        'expected_amount'          => $amount,
        'due_date'                 => $dueDate,
        'status'                   => 'pending',
        'notes'                    => $notes,
        'created_by'               => $uid,
        'created_at'               => date('Y-m-d H:i:s'),
        'updated_at'               => date('Y-m-d H:i:s'),
        'is_collected_for_others'  => $isCollectedForOthers ? 1 : 0,
        'contributor_count'        => $isCollectedForOthers && $contributorCount > 0 ? $contributorCount : null,
        'source_note'              => $isCollectedForOthers ? $sourceNote : null,
    ];

    $columns = [];
    $values = [];
    $params = [];

    foreach ($insertData as $column => $value) {
        if (columnExists($pdo, 'donation_commitments', $column)) {
            $columns[] = $column;
            $values[] = '?';
            $params[] = $value;
        }
    }

    if (!$columns) {
        throw new RuntimeException('donation_commitments table exists but expected columns are missing.');
    }

    $sql = 'INSERT INTO donation_commitments (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
    $pdo->prepare($sql)->execute($params);

    return (int)$pdo->lastInsertId();
}

function tx_update_expected_commitment(
    PDO $pdo,
    int $commitmentId,
    int $personId,
    string $category,
    float $amount,
    string $dueDate,
    int $uid,
    int $eventId = 0,
    string $notes = '',
    bool $isCollectedForOthers = false,
    int $contributorCount = 0,
    string $sourceNote = ''
): void {
    if (!function_exists('tableExists') || !function_exists('columnExists') || !tableExists($pdo, 'donation_commitments')) {
        throw new RuntimeException('donation_commitments table not found. Run the SQL update first.');
    }

    $updateData = [
        'person_id'               => $personId,
        'category'                => $category,
        'event_id'                => $eventId > 0 ? $eventId : null,
        'expected_amount'         => $amount,
        'due_date'                => $dueDate,
        'notes'                   => $notes,
        'updated_at'              => date('Y-m-d H:i:s'),
        'is_collected_for_others' => $isCollectedForOthers ? 1 : 0,
        'contributor_count'       => $isCollectedForOthers && $contributorCount > 0 ? $contributorCount : null,
        'source_note'             => $isCollectedForOthers ? $sourceNote : null,
    ];

    $sets = [];
    $params = [];

    foreach ($updateData as $column => $value) {
        if (columnExists($pdo, 'donation_commitments', $column)) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
    }

    if (!$sets) {
        throw new RuntimeException('No editable columns found in donation_commitments.');
    }

    $params[] = $commitmentId;
    $params[] = $personId;

    $sql = 'UPDATE donation_commitments SET ' . implode(', ', $sets) . ' WHERE ID = ? AND person_id = ?';
    $pdo->prepare($sql)->execute($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'collect') {
    verifyCsrfOrFail();

    $selectedDonorId = (int)($_POST['person_id'] ?? 0);
    $category = (string)($_POST['category'] ?? 'one_time');
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $paymentMethod = (string)($_POST['payment_method'] ?? 'cash');
    $notes = trim((string)($_POST['notes'] ?? ''));
    $date = trim((string)($_POST['transaction_date'] ?? date('Y-m-d')));
    $eventId = (int)($_POST['event_id'] ?? 0);

    $isExpected = (int)($_POST['is_expected'] ?? 0) === 1;
    $dueDate = trim((string)($_POST['due_date'] ?? ''));
    $isCollectedForOthers = (int)($_POST['collected_for_others'] ?? 0) === 1;
    $contributorCount = (int)($_POST['contributor_count'] ?? 0);
    $sourceNote = trim((string)($_POST['source_note'] ?? ''));
    $expectedCommitmentId = (int)($_POST['expected_commitment_id'] ?? 0);

    $allowedCategories = ['one_time', 'monthly', 'event'];
    $allowedMethods = ['cash', 'bank', 'pos', 'stripe', 'online'];

    if ($selectedDonorId <= 0) {
        $errors[] = 'Please select a donor.';
    }

    if (!in_array($category, $allowedCategories, true)) {
        $errors[] = 'Invalid category.';
    }

    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }

    if (!$isExpected && !in_array($paymentMethod, $allowedMethods, true)) {
        $errors[] = 'Invalid payment method.';
    }

    if ($date === '') {
        $errors[] = 'Date is required.';
    }

    if ($isExpected && $dueDate === '') {
        $errors[] = 'Due date is required for expected amount.';
    }

    if ($category === 'event' && $eventId <= 0) {
        $errors[] = 'Please select an event.';
    }

    if ($isCollectedForOthers && $contributorCount > 0 && $contributorCount < 2) {
        $errors[] = 'Contributor count must be at least 2.';
    }

    if ($selectedDonorId > 0 && !$selectedDonor) {
        $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
        $stmt->execute([$selectedDonorId]);
        $selectedDonor = $stmt->fetch() ?: null;
    }

    if ($selectedDonorId > 0 && !$selectedDonor) {
        $errors[] = 'Selected donor was not found.';
    }

    if ($expectedCommitmentId > 0 && tableExists($pdo, 'donation_commitments')) {
        $stmt = $pdo->prepare('SELECT ID, person_id, status FROM donation_commitments WHERE ID = ? LIMIT 1');
        $stmt->execute([$expectedCommitmentId]);
        $existingCommitment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingCommitment) {
            $errors[] = 'Expected record not found.';
        } elseif ((int)$existingCommitment['person_id'] !== $selectedDonorId) {
            $errors[] = 'Expected record does not belong to this donor.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($isExpected) {
                if ($expectedCommitmentId > 0) {
                    tx_update_expected_commitment(
                        $pdo,
                        $expectedCommitmentId,
                        $selectedDonorId,
                        $category,
                        $amount,
                        $dueDate,
                        $uid,
                        $eventId,
                        $notes,
                        $isCollectedForOthers,
                        $contributorCount,
                        $sourceNote
                    );
                    $commitmentId = $expectedCommitmentId;
                    $logAction = 'update';
                    $flashMessage = 'Expected amount updated successfully.';
                } else {
                    $commitmentId = tx_save_expected_commitment(
                        $pdo,
                        $selectedDonorId,
                        $category,
                        $amount,
                        $dueDate,
                        $uid,
                        $eventId,
                        $notes,
                        $isCollectedForOthers,
                        $contributorCount,
                        $sourceNote
                    );
                    $logAction = 'create';
                    $flashMessage = 'Expected amount saved successfully.';
                }

                if ($category === 'monthly') {
                    tx_save_monthly_agreement($pdo, $selectedDonorId, $amount, 'cash', $uid);
                    if ($expectedCommitmentId <= 0) {
                        tx_save_monthly_due($pdo, $selectedDonorId, $amount, $dueDate, $uid, $notes);
                    }
                }

                if (function_exists('systemLog')) {
                    systemLog(
                        $pdo,
                        $uid,
                        'donation_expectation',
                        $logAction,
                        ($expectedCommitmentId > 0 ? 'Updated' : 'Saved') . ' expected ' . $category . ' amount',
                        $commitmentId
                    );
                }

                if (function_exists('personLog')) {
                    $personAction = ($expectedCommitmentId > 0 ? 'Updated' : 'Expected') . ' ' . $category . ' donation of '
                        . number_format($amount, 2, '.', '')
                        . ' due on ' . $dueDate;

                    personLog($pdo, $uid, $selectedDonorId, 'donation_expectation', $personAction);
                }

                $pdo->commit();

                if (function_exists('setFlash')) {
                    setFlash('success', $flashMessage);
                }

                header('Location: person_profile.php?id=' . $selectedDonorId);
                exit;
            }

            $referenceId = null;
            $ledgerCategory = $category;
            $ledgerTransactionType = 'credit';
            $descriptionBits = [];

            if ($category === 'event') {
                $eventStmt = $pdo->prepare('SELECT ID, name FROM events WHERE ID = ? LIMIT 1');
                $eventStmt->execute([$eventId]);
                $eventRow = $eventStmt->fetch();

                if (!$eventRow) {
                    throw new RuntimeException('Selected event was not found.');
                }

                if (function_exists('tableExists') && tableExists($pdo, 'event_details')) {
                    $evDetailStmt = $pdo->prepare('
                        INSERT INTO event_details (pid, event_id, amount, notes, uid, created_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    $evDetailStmt->execute([
                        $selectedDonorId,
                        $eventId,
                        $amount,
                        $notes !== '' ? $notes : 'Event donation',
                        $uid,
                        $date . ' 00:00:00'
                    ]);
                    $referenceId = (int)$pdo->lastInsertId();
                } else {
                    $referenceId = $eventId;
                }

                $descriptionBits[] = 'Event: ' . (string)$eventRow['name'];
            }

            if ($category === 'monthly') {
                tx_save_monthly_agreement($pdo, $selectedDonorId, $amount, $paymentMethod, $uid);

                if (function_exists('tableExists') && tableExists($pdo, 'monthly')) {
                    $legacy = $pdo->prepare('
                        INSERT INTO monthly (pid, expected, collected, method, notes, uid, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ');
                    $legacy->execute([
                        $selectedDonorId,
                        (int)round($amount),
                        (int)round($amount),
                        $paymentMethod,
                        $notes,
                        $uid,
                        $date . ' 00:00:00'
                    ]);
                }

                $plan = function_exists('getPersonCurrentPlan') ? getPersonCurrentPlan($pdo, $selectedDonorId) : null;
                if ($plan && (float)($plan['amount'] ?? 0) > 0 && $amount > (float)$plan['amount']) {
                    $monthsCovered = (int)floor($amount / (float)$plan['amount']);
                    if ($monthsCovered > 1) {
                        $descriptionBits[] = 'Advance months: ' . $monthsCovered;
                    }
                }
            }

            if ($category === 'one_time' && function_exists('tableExists') && tableExists($pdo, 'one_time')) {
                $legacy = $pdo->prepare('
                    INSERT INTO one_time (pid, expected, collected, method, notes, uid, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $legacy->execute([
                    $selectedDonorId,
                    (int)round($amount),
                    (int)round($amount),
                    $paymentMethod,
                    $notes,
                    $uid,
                    $date . ' 00:00:00'
                ]);
                $referenceId = (int)$pdo->lastInsertId();
            }

            if ($isCollectedForOthers) {
                $descriptionBits[] = 'Collected on behalf of others';
                if ($contributorCount > 0) {
                    $descriptionBits[] = 'Contributor count: ' . $contributorCount;
                }
                if ($sourceNote !== '') {
                    $descriptionBits[] = 'Source note: ' . $sourceNote;
                }
            }

            if ($expectedCommitmentId > 0) {
                $descriptionBits[] = 'Converted from expected record #' . $expectedCommitmentId;
            }

            if (!function_exists('tableExists') || !tableExists($pdo, 'operator_ledger')) {
                throw new RuntimeException('operator_ledger table not found.');
            }

            $invoiceNo = generateInvoiceNumber($pdo);
            $receiptToken = function_exists('receiptVerificationToken')
                ? receiptVerificationToken(0, $invoiceNo)
                : randomToken(8);

            $finalNotes = trim(implode(' | ', array_filter(array_merge($descriptionBits, [$notes]))));

            $hasSourceColumns = function_exists('columnExists')
                && columnExists($pdo, 'operator_ledger', 'source_type')
                && columnExists($pdo, 'operator_ledger', 'contributor_count')
                && columnExists($pdo, 'operator_ledger', 'source_note');

            if ($hasSourceColumns) {
                $sourceType = $isCollectedForOthers ? 'group_collected' : 'self';

                $ledgerStmt = $pdo->prepare('
                    INSERT INTO operator_ledger
                    (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, notes, source_type, contributor_count, source_note, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $ledgerStmt->execute([
                    $uid,
                    $selectedDonorId,
                    $ledgerTransactionType,
                    $ledgerCategory,
                    $amount,
                    $paymentMethod,
                    'pending',
                    $referenceId,
                    $invoiceNo,
                    $receiptToken,
                    $finalNotes,
                    $sourceType,
                    $isCollectedForOthers && $contributorCount > 0 ? $contributorCount : null,
                    $isCollectedForOthers && $sourceNote !== '' ? $sourceNote : null,
                    $uid,
                    $date . ' 00:00:00'
                ]);
            } else {
                $ledgerStmt = $pdo->prepare('
                    INSERT INTO operator_ledger
                    (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, notes, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $ledgerStmt->execute([
                    $uid,
                    $selectedDonorId,
                    $ledgerTransactionType,
                    $ledgerCategory,
                    $amount,
                    $paymentMethod,
                    'pending',
                    $referenceId,
                    $invoiceNo,
                    $receiptToken,
                    $finalNotes,
                    $uid,
                    $date . ' 00:00:00'
                ]);
            }

            $ledgerId = (int)$pdo->lastInsertId();

            if (function_exists('receiptVerificationToken')) {
                $pdo->prepare('UPDATE operator_ledger SET receipt_token = ? WHERE ID = ?')
                    ->execute([receiptVerificationToken($ledgerId, $invoiceNo), $ledgerId]);
            }

            if ($expectedCommitmentId > 0 && tableExists($pdo, 'donation_commitments')) {
                $pdo->prepare('UPDATE donation_commitments SET status = ?, updated_at = NOW() WHERE ID = ? AND person_id = ?')
                    ->execute(['paid', $expectedCommitmentId, $selectedDonorId]);
            }

            if (function_exists('systemLog')) {
                systemLog(
                    $pdo,
                    $uid,
                    'donation',
                    'create',
                    'Collected ' . $category . ' donation',
                    $ledgerId
                );
            }

            if (function_exists('personLog')) {
                $personAction = 'Collected ' . $category . ' donation of ' . number_format($amount, 2, '.', '');
                if ($expectedCommitmentId > 0) {
                    $personAction .= ' from expected record #' . $expectedCommitmentId;
                }
                personLog($pdo, $uid, $selectedDonorId, 'donation', $personAction);
            }

            $pdo->commit();

            if (function_exists('setFlash')) {
                setFlash('success', 'Donation saved successfully.');
            }

            $profileUrl = 'person_profile.php?id=' . $selectedDonorId;
            $receiptUrl = 'receipt_print.php?id=' . $ledgerId . '&return=' . urlencode($profileUrl);
            header('Location: ' . $receiptUrl);
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$events = function_exists('getEvents') ? getEvents($pdo) : [];
$autoJumpToCategory = $selectedDonorId > 0 && $selectedDonor && $_SERVER['REQUEST_METHOD'] !== 'POST';
$isEditExpectation = $editingCommitment && !$collectNowMode;
$isCollectFromExpectation = $editingCommitment && $collectNowMode;

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title"><?= $isEditExpectation ? 'Edit Expected Donation' : 'Collect Donation' ?></h1>

<div class="card collect-card-v5 stack">
    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($errorFlash): ?>
        <div class="alert error"><?= e($errorFlash) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $er): ?>
                <div><?= e($er) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="collect-search-wrap-v5">
        <input type="hidden" name="expected_commitment_id" value="<?= (int)$expectedCommitmentId ?>">
        <?php if ($collectNowMode): ?><input type="hidden" name="collect_now" value="1"><?php endif; ?>
        <div class="collect-search-row-v5">
            <input
                type="text"
                name="q"
                value="<?= e($q) ?>"
                placeholder="Search donor by name, phone, city or donor ID"
                class="collect-search-input-v5"
            >
            <button type="submit" class="btn collect-search-btn-v5">Search</button>
        </div>
        <div class="collect-search-actions-v5">
            <a href="add_person.php" class="btn btn-primary collect-add-btn-v5">Add New Donor</a>
        </div>
    </form>

    <?php if ($q !== '' && !$selectedDonor): ?>
        <div id="donor_results" class="collect-results-v5">
            <?php if ($results): ?>
                <?php foreach ($results as $row): ?>
                    <button
                        type="button"
                        class="collect-donor-result-v5"
                        data-donor-id="<?= (int)$row['ID'] ?>"
                        data-donor-name="<?= e((string)$row['name']) ?>"
                        data-donor-city="<?= e((string)($row['city'] ?: '')) ?>"
                        data-donor-phone="<?= e((string)($row['phone'] ?: '')) ?>"
                    >
                        <span class="collect-donor-left-v5">
                            <strong><?= e((string)$row['name']) ?></strong>
                            <small>ID <?= (int)$row['ID'] ?><?= !empty($row['city']) ? ' · ' . e((string)$row['city']) : '' ?></small>
                        </span>
                        <span class="collect-donor-right-v5"><?= e((string)($row['phone'] ?: '—')) ?></span>
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="muted">No donor found.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" id="collect_form_v5" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="collect">
        <input type="hidden" name="person_id" id="person_id" value="<?= (int)($selectedDonor['ID'] ?? $selectedDonorId) ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <input type="hidden" name="expected_commitment_id" value="<?= (int)$expectedCommitmentId ?>">

        <div id="selected_donor_box" class="collect-selected-v5<?= $selectedDonor ? ' is-selected' : '' ?>">
            <div class="muted">Selected donor</div>
            <div id="selected_donor_name" class="collect-selected-name-v5">
                <?= e((string)($selectedDonor['name'] ?? 'No donor selected')) ?>
            </div>
            <div id="selected_donor_meta" class="collect-selected-meta-v5">
                <?php if ($selectedDonor): ?>
                    ID <?= (int)$selectedDonor['ID'] ?>
                    <?= !empty($selectedDonor['city']) ? ' · ' . e((string)$selectedDonor['city']) : '' ?>
                    <?= !empty($selectedDonor['phone']) ? ' · ' . e((string)$selectedDonor['phone']) : '' ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($editingCommitment): ?>
            <div class="alert success">
                <?= $collectNowMode ? 'This expected record is loaded for real collection.' : 'This expected record is loaded for editing.' ?>
            </div>
        <?php endif; ?>

        <div id="collect_fields_section" class="<?= $selectedDonor ? '' : 'is-disabled' ?>">
            <div class="collect-toggle-card-v5">
                <label class="collect-toggle-row-v5" for="is_expected">
                    <span class="collect-toggle-switch-v5">
                        <input type="checkbox" name="is_expected" id="is_expected" value="1" <?= tx_old('is_expected', '') ? 'checked' : '' ?>>
                        <span class="collect-toggle-slider-v5"></span>
                    </span>
                    <span class="collect-toggle-content-v5">
                        <span class="collect-toggle-title-v5">Expected Only</span>
                        <span class="collect-toggle-help-v5">Turn on to save this as commitment only. It will not add into final collected total until actually paid.</span>
                    </span>
                </label>
                <div id="expected_fields" class="stack compact collect-conditional-v5 collect-toggle-fields-v5<?= tx_old('is_expected', '') ? '' : ' hidden' ?>">
                    <div class="collect-date-notes-grid-v5">
                        <div>
                            <label>Due Date</label>
                            <input type="date" name="due_date" id="due_date" value="<?= e((string)tx_old('due_date', '')) ?>">
                        </div>
                        <div class="collect-inline-note-v5">
                            <div class="muted">
                                Expected mode will not create receipt and will not add this amount to final donation totals.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="category_start_v5" class="section-title">Category</div>
            <div class="collect-category-grid-v5" id="category_grid">
                <?php foreach ([
                    'one_time' => ['🎁', 'One Time'],
                    'monthly'  => ['🗓️', 'Monthly'],
                    'event'    => ['🎉', 'Event']
                ] as $value => [$icon, $label]): ?>
                    <label class="collect-card-option-v5">
                        <input type="radio" name="category" value="<?= e($value) ?>" <?= (string)tx_old('category', '') === $value ? 'checked' : '' ?>>
                        <span class="collect-card-pill-v5">
                            <span class="icon"><?= $icon ?></span>
                            <span><?= e($label) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div id="event_fields" class="stack compact collect-conditional-v5<?= (string)tx_old('category', '') === 'event' ? '' : ' hidden' ?>">
                <label>Event</label>
                <select name="event_id">
                    <option value="0">Select event</option>
                    <?php foreach ($events as $ev): ?>
                        <option value="<?= (int)$ev['ID'] ?>" <?= (int)tx_old('event_id', 0) === (int)$ev['ID'] ? 'selected' : '' ?>>
                            <?= e((string)$ev['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="section-title">Amount</div>
            <div class="stack compact">
                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    name="amount"
                    id="amount_input_v5"
                    value="<?= e((string)tx_old('amount', '')) ?>"
                    placeholder="0.00"
                >
                <div class="collect-amount-row-v5">
                    <?php foreach ([10, 20, 50, 100, 500, 1000] as $inc): ?>
                        <button type="button" class="collect-mini-card-v5 amount-add-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="payment_section" class="<?= tx_old('is_expected', '') ? 'hidden' : '' ?>">
                <div class="section-title">Payment Method</div>
                <div class="collect-payment-row-v5">
                    <?php foreach ([
                        'cash'   => ['💵', 'Cash'],
                        'bank'   => ['🏦', 'Bank'],
                        'pos'    => ['💳', 'POS'],
                        'stripe' => ['💠', 'Stripe'],
                        'online' => ['🌐', 'Online']
                    ] as $value => [$icon, $label]): ?>
                        <label class="collect-card-option-v5 collect-payment-option-v5">
                            <input type="radio" name="payment_method" value="<?= e($value) ?>" <?= (string)tx_old('payment_method', 'cash') === $value ? 'checked' : '' ?>>
                            <span class="collect-card-pill-v5">
                                <span class="icon"><?= $icon ?></span>
                                <span><?= e($label) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="collect-date-notes-grid-v5">
                <div>
                    <label id="transaction_date_label">Date</label>
                    <input type="date" name="transaction_date" id="transaction_date" value="<?= e((string)tx_old('transaction_date', date('Y-m-d'))) ?>">
                </div>
                <div>
                    <label>Notes</label>
                    <input type="text" name="notes" value="<?= e((string)tx_old('notes', '')) ?>" placeholder="Optional note">
                </div>
            </div>

            <div class="collect-toggle-card-v5 collect-toggle-card-end-v5">
                <label class="collect-toggle-row-v5" for="collected_for_others">
                    <span class="collect-toggle-switch-v5">
                        <input type="checkbox" name="collected_for_others" id="collected_for_others" value="1" <?= tx_old('collected_for_others', '') ? 'checked' : '' ?>>
                        <span class="collect-toggle-slider-v5"></span>
                    </span>
                    <span class="collect-toggle-content-v5">
                        <span class="collect-toggle-title-v5">Collected from Others</span>
                        <span class="collect-toggle-help-v5">Turn on if this donor collected the amount from other contributors and is submitting it together.</span>
                    </span>
                </label>
                <div id="source_fields" class="stack compact collect-conditional-v5 collect-toggle-fields-v5<?= tx_old('collected_for_others', '') ? '' : ' hidden' ?>">
                    <div class="collect-date-notes-grid-v5">
                        <div>
                            <label>Contributor Count</label>
                            <input type="number" min="2" name="contributor_count" value="<?= e((string)tx_old('contributor_count', '')) ?>" placeholder="e.g. 20">
                        </div>
                        <div>
                            <label>Source Note</label>
                            <input type="text" name="source_note" value="<?= e((string)tx_old('source_note', '')) ?>" placeholder="e.g. Collected by this person from a group">
                        </div>
                    </div>
                </div>
            </div>

            <div class="toolbar collect-toolbar-v5">
                <button type="submit" class="btn btn-primary" id="collect_submit_btn">
                    <?=
                        tx_old('is_expected', '')
                            ? ($expectedCommitmentId > 0 ? 'Update Expectation' : 'Save Expectation')
                            : 'Save Donation'
                    ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const resultWrap = document.getElementById('donor_results');
    const personInput = document.getElementById('person_id');
    const selectedBox = document.getElementById('selected_donor_box');
    const selectedName = document.getElementById('selected_donor_name');
    const selectedMeta = document.getElementById('selected_donor_meta');
    const fieldSection = document.getElementById('collect_fields_section');

    const sourceToggle = document.getElementById('collected_for_others');
    const sourceFields = document.getElementById('source_fields');

    const expectedToggle = document.getElementById('is_expected');
    const expectedFields = document.getElementById('expected_fields');
    const paymentSection = document.getElementById('payment_section');
    const dueDateInput = document.getElementById('due_date');
    const txDateLabel = document.getElementById('transaction_date_label');
    const txDateInput = document.getElementById('transaction_date');

    const saveButton = document.getElementById('collect_submit_btn');
    const expectedCommitmentId = <?= (int)$expectedCommitmentId ?>;

    function enableFields() {
        fieldSection.classList.remove('is-disabled');
        selectedBox.classList.add('is-selected');
    }

    function goCategory() {
        const target = document.getElementById('category_start_v5');
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function selectDonor(id, name, city, phone) {
        personInput.value = id;
        selectedName.textContent = name || 'Selected donor';
        selectedMeta.textContent = ['ID ' + id, city || '', phone || ''].filter(Boolean).join(' · ');
        enableFields();

        if (resultWrap) {
            resultWrap.innerHTML = '';
            resultWrap.style.display = 'none';
        }

        goCategory();
    }

    if (resultWrap) {
        resultWrap.addEventListener('click', function (e) {
            const btn = e.target.closest('.collect-donor-result-v5');
            if (!btn) return;

            e.preventDefault();
            selectDonor(
                btn.dataset.donorId || '',
                btn.dataset.donorName || '',
                btn.dataset.donorCity || '',
                btn.dataset.donorPhone || ''
            );
        });
    }

    document.querySelectorAll('.amount-add-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById('amount_input_v5');
            const add = parseFloat(btn.dataset.add || '0');
            const current = parseFloat(input.value || '0');
            input.value = (current + add).toFixed(2);
        });
    });

    function syncConditionalFields() {
        const selected = document.querySelector('input[name="category"]:checked');
        const val = selected ? selected.value : '';
        const eventFields = document.getElementById('event_fields');

        if (eventFields) {
            eventFields.classList.toggle('hidden', val !== 'event');
        }
    }

    function syncSourceFields() {
        if (!sourceToggle || !sourceFields) return;
        sourceFields.classList.toggle('hidden', !sourceToggle.checked);
    }

    function syncExpectedFields() {
        if (!expectedToggle) return;

        const isExpected = expectedToggle.checked;

        if (expectedFields) {
            expectedFields.classList.toggle('hidden', !isExpected);
        }
        if (paymentSection) {
            paymentSection.classList.toggle('hidden', isExpected);
        }
        if (dueDateInput) {
            dueDateInput.required = isExpected;
            if (isExpected && !dueDateInput.value && txDateInput && txDateInput.value) {
                dueDateInput.value = txDateInput.value;
            }
        }
        if (txDateLabel) {
            txDateLabel.textContent = isExpected ? 'Record Date' : 'Date';
        }
        if (saveButton) {
            if (isExpected) {
                saveButton.textContent = expectedCommitmentId > 0 ? 'Update Expectation' : 'Save Expectation';
            } else {
                saveButton.textContent = 'Save Donation';
            }
        }
    }

    document.querySelectorAll('input[name="category"]').forEach(function (radio) {
        radio.addEventListener('change', syncConditionalFields);
    });

    if (sourceToggle) {
        sourceToggle.addEventListener('change', syncSourceFields);
    }

    if (expectedToggle) {
        expectedToggle.addEventListener('change', syncExpectedFields);
    }

    syncConditionalFields();
    syncSourceFields();
    syncExpectedFields();

    if (<?= $autoJumpToCategory ? 'true' : 'false' ?>) {
        enableFields();
        window.setTimeout(goCategory, 120);
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>