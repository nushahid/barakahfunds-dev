<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

requireAccountant($pdo);

$uid = getLoggedInUserId();
$errors = [];
$success = null;

$allowedTypes = ['Provide', 'Receive'];
$allowedMethods = ['cash', 'bank', 'pos', 'stripe', 'online'];

function loan_page_is_valid_date(string $date): bool
{
    if ($date === '') {
        return false;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

function loan_page_old(array $data, string $key, $default = '')
{
    return $data[$key] ?? $default;
}

function loan_page_normalize_method(string $method): string
{
    $method = trim(strtolower($method));

    return match ($method) {
        'cash' => 'cash',
        'bank', 'bank_transfer', 'transfer' => 'bank',
        'pos', 'card' => 'pos',
        'stripe' => 'stripe',
        'online' => 'online',
        default => 'cash',
    };
}

function loan_page_get_selected_donor(PDO $pdo, int $personId): ?array
{
    if ($personId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT ID, name, city, phone FROM people WHERE ID = ? LIMIT 1");
    $stmt->execute([$personId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function loan_page_build_created_at(string $date): string
{
    return $date . ' 00:00:00';
}

function loan_page_create_loan(
    PDO $pdo,
    int $personId,
    string $personName,
    string $loanType,
    string $loanTitle,
    float $amount,
    string $paymentMethod,
    string $receivedDate,
    string $returnDate,
    string $notes,
    int $uid
): array {
    if ($personId <= 0) {
        throw new RuntimeException('Select donor.');
    }

    if ($loanTitle === '') {
        throw new RuntimeException('Loan title / purpose required.');
    }

    if ($amount <= 0) {
        throw new RuntimeException('Amount must be greater than 0.');
    }

    if (!in_array($loanType, ['Provide', 'Receive'], true)) {
        throw new RuntimeException('Invalid loan type.');
    }

    if (!function_exists('tableExists') || !tableExists($pdo, 'loan')) {
        throw new RuntimeException('loan table not found.');
    }

    if (!function_exists('tableExists') || !tableExists($pdo, 'loan_trans')) {
        throw new RuntimeException('loan_trans table not found.');
    }

    if (!function_exists('tableExists') || !tableExists($pdo, 'operator_ledger')) {
        throw new RuntimeException('operator_ledger table not found.');
    }

    $createdAt = loan_page_build_created_at($receivedDate);

    $loanStmt = $pdo->prepare("
        INSERT INTO loan
        (pid, name, amount, received_date, return_date, returned, method, type, notes, uid, created_at)
        VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)
    ");
    $loanStmt->execute([
        $personId,
        $loanTitle,
        $amount,
        $receivedDate,
        $returnDate !== '' ? $returnDate : null,
        $paymentMethod,
        $loanType,
        $notes,
        $uid,
        $createdAt
    ]);

    $loanId = (int)$pdo->lastInsertId();

    $loanTransNotes = [];
    $loanTransNotes[] = $loanType === 'Provide' ? 'INITIAL_PROVIDE' : 'INITIAL_RECEIVE';
    $loanTransNotes[] = 'Person: ' . $personName;
    $loanTransNotes[] = 'Title: ' . $loanTitle;
    if ($notes !== '') {
        $loanTransNotes[] = 'Notes: ' . $notes;
    }

    $loanTransStmt = $pdo->prepare("
        INSERT INTO loan_trans
        (lid, amount, method, notes, uid, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $loanTransStmt->execute([
        $loanId,
        $amount,
        $paymentMethod,
        implode(' | ', $loanTransNotes),
        $uid,
        $createdAt
    ]);

    $loanTransId = (int)$pdo->lastInsertId();

    $ledgerType = $loanType === 'Provide' ? 'debit' : 'credit';
    $invoiceNo = generateInvoiceNumber($pdo);
    $receiptToken = function_exists('receiptVerificationToken')
        ? receiptVerificationToken(0, $invoiceNo)
        : randomToken(8);

    $ledgerNotes = [];
    $ledgerNotes[] = 'Loan Type: ' . $loanType;
    $ledgerNotes[] = 'Loan Title: ' . $loanTitle;
    $ledgerNotes[] = 'Person: ' . $personName;
    if ($returnDate !== '') {
        $ledgerNotes[] = 'Expected Return: ' . $returnDate;
    }
    if ($notes !== '') {
        $ledgerNotes[] = $notes;
    }

    $ledgerStmt = $pdo->prepare("
        INSERT INTO operator_ledger
        (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, notes, created_by, created_at)
        VALUES (?, ?, ?, 'loan', ?, ?, 'pending', ?, ?, ?, ?, ?, ?)
    ");
    $ledgerStmt->execute([
        $uid,
        $personId,
        $ledgerType,
        $amount,
        $paymentMethod,
        $loanTransId,
        $invoiceNo,
        $receiptToken,
        implode(' | ', $ledgerNotes),
        $uid,
        $createdAt
    ]);

    $ledgerId = (int)$pdo->lastInsertId();

    if (function_exists('receiptVerificationToken')) {
        $pdo->prepare('UPDATE operator_ledger SET receipt_token = ? WHERE ID = ?')
            ->execute([receiptVerificationToken($ledgerId, $invoiceNo), $ledgerId]);
    }

    if (function_exists('systemLog')) {
        systemLog($pdo, $uid, 'loan', 'create', 'Loan created: ' . $loanTitle . ' (' . $loanType . ')', $loanId);
    }

    if (function_exists('personLog')) {
        personLog($pdo, $uid, $personId, 'loan', 'Loan ' . $loanType . ' of ' . number_format($amount, 2, '.', ''));
    }

    return [
        'loan_id' => $loanId,
        'loan_trans_id' => $loanTransId,
        'ledger_id' => $ledgerId,
    ];
}

function loan_page_get_loan_trans(PDO $pdo, int $loanId): array
{
    $stmt = $pdo->prepare("
        SELECT ID, lid, amount, method, notes, uid, created_at
        FROM loan_trans
        WHERE lid = ?
        ORDER BY ID ASC
    ");
    $stmt->execute([$loanId]);
    return $stmt->fetchAll() ?: [];
}

function loan_page_returned_total_from_trans(array $transRows): float
{
    if (!$transRows) {
        return 0.0;
    }

    $returned = 0.0;
    foreach ($transRows as $index => $tr) {
        if ($index === 0) {
            continue;
        }
        $returned += (float)$tr['amount'];
    }

    return round($returned, 2);
}

function loan_page_add_repayment(
    PDO $pdo,
    array $loanRow,
    float $returnAmount,
    string $paymentMethod,
    string $returnDate,
    string $notes,
    int $uid
): array {
    $loanId = (int)$loanRow['ID'];
    $personId = (int)$loanRow['pid'];
    $personName = (string)($loanRow['person_name'] ?? '');
    $loanType = (string)$loanRow['type'];
    $loanTitle = (string)$loanRow['name'];
    $loanAmount = (float)$loanRow['amount'];

    if ($loanId <= 0) {
        throw new RuntimeException('Invalid loan.');
    }

    if ($returnAmount <= 0) {
        throw new RuntimeException('Return amount must be greater than 0.');
    }

    $transRows = loan_page_get_loan_trans($pdo, $loanId);
    $returnedBefore = loan_page_returned_total_from_trans($transRows);
    $remainingBefore = round($loanAmount - $returnedBefore, 2);

    if ($remainingBefore <= 0) {
        throw new RuntimeException('This loan is already completed.');
    }

    if ($returnAmount > $remainingBefore) {
        throw new RuntimeException('Return amount cannot be greater than remaining loan.');
    }

    $createdAt = loan_page_build_created_at($returnDate);

    $transNotes = [];
    $transNotes[] = $loanType === 'Provide' ? 'RETURN_FROM_DONOR' : 'PAYBACK_TO_DONOR';
    $transNotes[] = 'Title: ' . $loanTitle;
    if ($personName !== '') {
        $transNotes[] = 'Person: ' . $personName;
    }
    if ($notes !== '') {
        $transNotes[] = 'Notes: ' . $notes;
    }

    $loanTransStmt = $pdo->prepare("
        INSERT INTO loan_trans
        (lid, amount, method, notes, uid, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $loanTransStmt->execute([
        $loanId,
        $returnAmount,
        $paymentMethod,
        implode(' | ', $transNotes),
        $uid,
        $createdAt
    ]);

    $loanTransId = (int)$pdo->lastInsertId();

    $ledgerType = $loanType === 'Provide' ? 'credit' : 'debit';

    $invoiceNo = generateInvoiceNumber($pdo);
    $receiptToken = function_exists('receiptVerificationToken')
        ? receiptVerificationToken(0, $invoiceNo)
        : randomToken(8);

    $ledgerNotes = [];
    $ledgerNotes[] = $loanType === 'Provide' ? 'Loan return received by mosque' : 'Loan repayment paid by mosque';
    $ledgerNotes[] = 'Loan Title: ' . $loanTitle;
    if ($personName !== '') {
        $ledgerNotes[] = 'Person: ' . $personName;
    }
    if ($notes !== '') {
        $ledgerNotes[] = $notes;
    }

    $ledgerStmt = $pdo->prepare("
        INSERT INTO operator_ledger
        (operator_id, person_id, transaction_type, transaction_category, amount, payment_method, settlement_status, reference_id, invoice_no, receipt_token, notes, created_by, created_at)
        VALUES (?, ?, ?, 'loan', ?, ?, 'pending', ?, ?, ?, ?, ?, ?)
    ");
    $ledgerStmt->execute([
        $uid,
        $personId,
        $ledgerType,
        $returnAmount,
        $paymentMethod,
        $loanTransId,
        $invoiceNo,
        $receiptToken,
        implode(' | ', $ledgerNotes),
        $uid,
        $createdAt
    ]);

    $ledgerId = (int)$pdo->lastInsertId();

    if (function_exists('receiptVerificationToken')) {
        $pdo->prepare('UPDATE operator_ledger SET receipt_token = ? WHERE ID = ?')
            ->execute([receiptVerificationToken($ledgerId, $invoiceNo), $ledgerId]);
    }

    $returnedAfter = round($returnedBefore + $returnAmount, 2);
    $remainingAfter = round($loanAmount - $returnedAfter, 2);

    if ($remainingAfter <= 0.00001) {
        $remainingAfter = 0.0;
        $pdo->prepare("UPDATE loan SET returned = 1 WHERE ID = ?")->execute([$loanId]);
    }

    if (function_exists('systemLog')) {
        systemLog(
            $pdo,
            $uid,
            'loan',
            'repay',
            'Loan repayment saved: ' . $loanTitle . ' amount ' . number_format($returnAmount, 2, '.', ''),
            $loanId
        );
    }

    if (function_exists('personLog')) {
        $logText = $loanType === 'Provide'
            ? 'Loan return received of ' . number_format($returnAmount, 2, '.', '')
            : 'Loan repayment paid of ' . number_format($returnAmount, 2, '.', '');

        personLog($pdo, $uid, $personId, 'loan', $logText);
    }

    return [
        'loan_trans_id' => $loanTransId,
        'ledger_id' => $ledgerId,
        'returned_after' => $returnedAfter,
        'remaining_after' => $remainingAfter,
        'completed' => $remainingAfter <= 0.0,
    ];
}

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$selectedDonorId = (int)($_POST['person_id'] ?? ($_GET['person_id'] ?? 0));
$selectedDonor = loan_page_get_selected_donor($pdo, $selectedDonorId);

$formData = [
    'person_id'      => (int)($selectedDonor['ID'] ?? $selectedDonorId),
    'name'           => '',
    'amount'         => '',
    'received_date'  => date('Y-m-d'),
    'return_date'    => date('Y-m-d', strtotime('+30 days')),
    'type'           => 'Provide',
    'method'         => 'cash',
    'notes'          => '',
];

if ($selectedDonor && $q === '') {
    $q = (string)$selectedDonor['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();

    $action = (string)($_POST['action'] ?? 'create_loan');

    if ($action === 'create_loan') {
        $selectedDonorId = (int)($_POST['person_id'] ?? 0);
        $selectedDonor = loan_page_get_selected_donor($pdo, $selectedDonorId);

        $formData = [
            'person_id'      => $selectedDonorId,
            'name'           => trim((string)($_POST['name'] ?? '')),
            'amount'         => trim((string)($_POST['amount'] ?? '')),
            'received_date'  => (string)($_POST['received_date'] ?? date('Y-m-d')),
            'return_date'    => (string)($_POST['return_date'] ?? date('Y-m-d', strtotime('+30 days'))),
            'type'           => (string)($_POST['type'] ?? 'Provide'),
            'method'         => loan_page_normalize_method((string)($_POST['method'] ?? 'cash')),
            'notes'          => trim((string)($_POST['notes'] ?? '')),
        ];

        $amount = (float)$formData['amount'];

        if ($formData['person_id'] <= 0) {
            $errors[] = 'Select donor.';
        }

        if ($formData['person_id'] > 0 && !$selectedDonor) {
            $errors[] = 'Selected donor was not found.';
        }

        if ($formData['name'] === '') {
            $errors[] = 'Loan title / purpose required.';
        }

        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than 0.';
        }

        if (!in_array($formData['type'], $allowedTypes, true)) {
            $errors[] = 'Invalid loan type selected.';
        }

        if (!in_array($formData['method'], $allowedMethods, true)) {
            $errors[] = 'Invalid payment method selected.';
        }

        if (!loan_page_is_valid_date($formData['received_date'])) {
            $errors[] = 'Received date is invalid.';
        }

        if (!loan_page_is_valid_date($formData['return_date'])) {
            $errors[] = 'Return date is invalid.';
        }

        if (
            loan_page_is_valid_date($formData['received_date']) &&
            loan_page_is_valid_date($formData['return_date']) &&
            $formData['return_date'] < $formData['received_date']
        ) {
            $errors[] = 'Return date cannot be earlier than received date.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                loan_page_create_loan(
                    $pdo,
                    $formData['person_id'],
                    (string)$selectedDonor['name'],
                    $formData['type'],
                    $formData['name'],
                    $amount,
                    $formData['method'],
                    $formData['received_date'],
                    $formData['return_date'],
                    $formData['notes'],
                    $uid
                );

                $pdo->commit();

                setFlash('success', 'Loan saved successfully.');
                header('Location: loan_page.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }

    if ($action === 'add_return') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $returnAmount = round((float)($_POST['return_amount'] ?? 0), 2);
        $returnMethod = loan_page_normalize_method((string)($_POST['return_method'] ?? 'cash'));
        $returnDate = trim((string)($_POST['return_date'] ?? date('Y-m-d')));
        $returnNotes = trim((string)($_POST['return_notes'] ?? ''));

        if ($loanId <= 0) {
            $errors[] = 'Invalid loan selected.';
        }

        if ($returnAmount <= 0) {
            $errors[] = 'Return amount must be greater than 0.';
        }

        if (!in_array($returnMethod, $allowedMethods, true)) {
            $errors[] = 'Invalid return method selected.';
        }

        if (!loan_page_is_valid_date($returnDate)) {
            $errors[] = 'Return date is invalid.';
        }

        if (!$errors) {
            $loanStmt = $pdo->prepare("
                SELECT l.*, p.name AS person_name, p.city AS person_city, p.phone AS person_phone
                FROM loan l
                LEFT JOIN people p ON p.ID = l.pid
                WHERE l.ID = ?
                LIMIT 1
            ");
            $loanStmt->execute([$loanId]);
            $loanRow = $loanStmt->fetch() ?: null;

            if (!$loanRow) {
                $errors[] = 'Loan not found.';
            } elseif ((int)$loanRow['returned'] === 1) {
                $errors[] = 'This loan is already completed.';
            } else {
                try {
                    $pdo->beginTransaction();

                    loan_page_add_repayment(
                        $pdo,
                        $loanRow,
                        $returnAmount,
                        $returnMethod,
                        $returnDate,
                        $returnNotes,
                        $uid
                    );

                    $pdo->commit();

                    setFlash('success', 'Loan return saved successfully.');
                    header('Location: loan_page.php');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

$results = [];
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
    $results = $stmt->fetchAll() ?: [];
}

$loanRows = $pdo->query("
    SELECT l.*, p.name AS person_name, p.city AS person_city, p.phone AS person_phone
    FROM loan l
    LEFT JOIN people p ON p.ID = l.pid
    WHERE l.returned = 0
    ORDER BY l.ID DESC
")->fetchAll() ?: [];

$activeLoans = [];
$totalOriginalActive = 0.0;
$totalReturnedActive = 0.0;
$totalRemainingActive = 0.0;

foreach ($loanRows as $row) {
    $loanId = (int)$row['ID'];
    $loanAmount = round((float)$row['amount'], 2);

    $transRows = loan_page_get_loan_trans($pdo, $loanId);
    $returnedTotal = loan_page_returned_total_from_trans($transRows);
    $remaining = round($loanAmount - $returnedTotal, 2);

    if ($remaining <= 0.00001) {
        $remaining = 0.0;
        if ((int)$row['returned'] !== 1) {
            $pdo->prepare("UPDATE loan SET returned = 1 WHERE ID = ?")->execute([$loanId]);
        }
        continue;
    }

    $row['returned_total'] = $returnedTotal;
    $row['remaining_total'] = $remaining;
    $row['trans_count'] = count($transRows);

    $activeLoans[] = $row;

    $totalOriginalActive += $loanAmount;
    $totalReturnedActive += $returnedTotal;
    $totalRemainingActive += $remaining;
}

$flash = function_exists('getFlash') ? getFlash() : null;
if (is_array($flash) && (($flash['type'] ?? '') === 'success')) {
    $success = (string)($flash['message'] ?? '');
}

$autoJumpToForm = $selectedDonorId > 0 && $selectedDonor && $_SERVER['REQUEST_METHOD'] !== 'POST';

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Loan Management</h1>

<div class="card loan-card-v5 stack">
    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $er): ?>
                <div><?= e($er) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="loan-search-wrap-v5">
        <div class="loan-search-row-v5">
            <input
                type="text"
                name="q"
                value="<?= e($q) ?>"
                placeholder="Search donor by name, phone, city or donor ID"
                class="loan-search-input-v5"
            >
            <button type="submit" class="btn loan-search-btn-v5">Search</button>
        </div>
    </form>

    <?php if ($q !== '' && !$selectedDonor): ?>
        <div id="loan_donor_results" class="loan-results-v5">
            <?php if ($results): ?>
                <?php foreach ($results as $row): ?>
                    <button
                        type="button"
                        class="loan-donor-result-v5"
                        data-donor-id="<?= (int)$row['ID'] ?>"
                        data-donor-name="<?= e((string)$row['name']) ?>"
                        data-donor-city="<?= e((string)($row['city'] ?? '')) ?>"
                        data-donor-phone="<?= e((string)($row['phone'] ?? '')) ?>"
                    >
                        <span class="loan-donor-left-v5">
                            <strong><?= e((string)$row['name']) ?></strong>
                            <small>ID <?= (int)$row['ID'] ?><?= !empty($row['city']) ? ' · ' . e((string)$row['city']) : '' ?></small>
                        </span>
                        <span class="loan-donor-right-v5"><?= e((string)($row['phone'] ?: '—')) ?></span>
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="loan-empty-v5 muted">No donor found.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="loan-form-wrap-v5 stack" id="loan_form_v5">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_loan">
        <input type="hidden" name="person_id" id="loan_person_id" value="<?= (int)loan_page_old($formData, 'person_id', 0) ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">

        <div id="loan_selected_donor_box" class="loan-selected-v5<?= $selectedDonor ? ' is-selected' : '' ?>">
            <div class="muted">Selected donor</div>
            <div id="loan_selected_donor_name" class="loan-selected-name-v5">
                <?= e((string)($selectedDonor['name'] ?? 'No donor selected')) ?>
            </div>
            <div id="loan_selected_donor_meta" class="loan-selected-meta-v5">
                <?php if ($selectedDonor): ?>
                    ID <?= (int)$selectedDonor['ID'] ?>
                    <?= !empty($selectedDonor['city']) ? ' · ' . e((string)$selectedDonor['city']) : '' ?>
                    <?= !empty($selectedDonor['phone']) ? ' · ' . e((string)$selectedDonor['phone']) : '' ?>
                <?php else: ?>
                    Select a donor from search results.
                <?php endif; ?>
            </div>
        </div>

        <div id="loan_fields_section" class="<?= $selectedDonor ? '' : 'is-disabled' ?>">
            <div class="loan-toolbar-v5">
                <h2 class="loan-subtitle">New Loan</h2>
            </div>

            <div>
                <label>Loan Title / Purpose</label>
                <input type="text" name="name" value="<?= e((string)loan_page_old($formData, 'name', '')) ?>" required>
            </div>

            <div>
                <label>Amount</label>
                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    name="amount"
                    id="loan_amount_input_v5"
                    value="<?= e((string)loan_page_old($formData, 'amount', '')) ?>"
                    required
                >
                <div class="loan-amount-row-v5">
                    <?php foreach ([10, 20, 50, 100, 500, 1000] as $inc): ?>
                        <button type="button" class="loan-mini-card-v5 loan-amount-add-btn" data-add="<?= $inc ?>">+<?= $inc ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="loan-date-grid-v5">
                <div>
                    <label>Received Date</label>
                    <input type="date" name="received_date" value="<?= e((string)loan_page_old($formData, 'received_date', date('Y-m-d'))) ?>" required>
                </div>
                <div>
                    <label>Expected Return Date</label>
                    <input type="date" name="return_date" value="<?= e((string)loan_page_old($formData, 'return_date', date('Y-m-d', strtotime('+30 days')))) ?>" required>
                </div>
            </div>

            <div>
                <div class="section-title">Loan Type</div>
                <div class="loan-type-row-v5">
                    <?php
                    $typeOptions = [
                        ['Provide', '⬅️', 'Provide', 'Mosque gives money out'],
                        ['Receive', '➡️', 'Receive', 'Mosque receives loan'],
                    ];
                    ?>
                    <?php foreach ($typeOptions as $t): ?>
                        <label class="loan-option-v5">
                            <input
                                type="radio"
                                name="type"
                                value="<?= e($t[0]) ?>"
                                <?= (string)loan_page_old($formData, 'type', 'Provide') === $t[0] ? 'checked' : '' ?>
                            >
                            <span class="loan-pill-v5 loan-type-pill-v5">
                                <span class="icon"><?= e($t[1]) ?></span>
                                <span><?= e($t[2]) ?></span>
                                <small><?= e($t[3]) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <div class="section-title">Method</div>
                <div class="loan-payment-row-v5">
                    <?php
                    $methodOptions = [
                        ['cash', '💵', 'Cash'],
                        ['bank', '🏦', 'Bank'],
                        ['pos', '💳', 'POS'],
                        ['stripe', '💠', 'Stripe'],
                        ['online', '🌐', 'Online'],
                    ];
                    ?>
                    <?php foreach ($methodOptions as $m): ?>
                        <label class="loan-option-v5">
                            <input
                                type="radio"
                                name="method"
                                value="<?= e($m[0]) ?>"
                                <?= (string)loan_page_old($formData, 'method', 'cash') === $m[0] ? 'checked' : '' ?>
                            >
                            <span class="loan-pill-v5">
                                <span class="icon"><?= e($m[1]) ?></span>
                                <span><?= e($m[2]) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label>Notes</label>
                <textarea name="notes"><?= e((string)loan_page_old($formData, 'notes', '')) ?></textarea>
            </div>

            <div class="loan-submit-wrap-v5">
                <button class="btn btn-primary" type="submit">Save Loan</button>
            </div>
        </div>
    </form>
</div>

<div class="loan-summary-grid-v5">
    <div class="card loan-summary-card-v5">
        <div class="loan-summary-label-v5">Active Loans</div>
        <div class="loan-summary-value-v5"><?= count($activeLoans) ?></div>
    </div>
    <div class="card loan-summary-card-v5">
        <div class="loan-summary-label-v5">Total Loan Amount</div>
        <div class="loan-summary-value-v5"><?= money($totalOriginalActive) ?></div>
    </div>
    <div class="card loan-summary-card-v5">
        <div class="loan-summary-label-v5">Returned So Far</div>
        <div class="loan-summary-value-v5"><?= money($totalReturnedActive) ?></div>
    </div>
    <div class="card loan-summary-card-v5">
        <div class="loan-summary-label-v5">Remaining Balance</div>
        <div class="loan-summary-value-v5"><?= money($totalRemainingActive) ?></div>
    </div>
</div>

<div class="card loan-list-card-v5">
    <div class="loan-toolbar-v5">
        <h2 class="loan-subtitle">Active Loans</h2>
        <span class="tag orange"><?= count($activeLoans) ?> active</span>
    </div>

    <div class="loan-table-wrap-v5 table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Person</th>
                    <th>Loan</th>
                    <th>Type</th>
                    <th>Total Loan</th>
                    <th>Returned</th>
                    <th>Remaining</th>
                    <th>Received</th>
                    <th>Expected Return</th>
                    <th>Method</th>
                    <th>Repayment</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($activeLoans): ?>
                    <?php foreach ($activeLoans as $row): ?>
                        <tr>
                            <td><?= e((string)$row['person_name']) ?></td>
                            <td><?= e((string)$row['name']) ?></td>
                            <td>
                                <span class="tag <?= (string)$row['type'] === 'Provide' ? 'orange' : 'blue' ?>">
                                    <?= e((string)$row['type']) ?>
                                </span>
                            </td>
                            <td><?= money((float)$row['amount']) ?></td>
                            <td><?= money((float)$row['returned_total']) ?></td>
                            <td><strong><?= money((float)$row['remaining_total']) ?></strong></td>
                            <td><?= e((string)$row['received_date']) ?></td>
                            <td><?= e((string)$row['return_date']) ?></td>
                            <td><?= e((string)$row['method']) ?></td>
                            <td>
                                <button type="button" class="btn loan-toggle-return-btn-v5" data-loan-toggle="<?= (int)$row['ID'] ?>">
                                    Add Return
                                </button>
                            </td>
                        </tr>
                        <tr class="loan-return-row-v5 hidden" id="loan_return_row_<?= (int)$row['ID'] ?>">
                            <td colspan="10">
                                <form method="post" class="loan-return-form-v5">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="add_return">
                                    <input type="hidden" name="loan_id" value="<?= (int)$row['ID'] ?>">

                                    <div class="loan-return-header-v5">
                                        <div>
                                            <strong><?= e((string)$row['name']) ?></strong>
                                            <div class="muted">
                                                <?= e((string)$row['person_name']) ?> · Remaining <?= money((float)$row['remaining_total']) ?>
                                            </div>
                                        </div>
                                        <div class="loan-return-badge-v5">
                                            <?= (string)$row['type'] === 'Provide'
                                                ? 'Money returning to mosque'
                                                : 'Money paid back to donor' ?>
                                        </div>
                                    </div>

                                    <div class="loan-return-grid-v5">
                                        <div>
                                            <label>Return Amount</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                max="<?= e(number_format((float)$row['remaining_total'], 2, '.', '')) ?>"
                                                name="return_amount"
                                                placeholder="0.00"
                                                required
                                            >
                                        </div>
                                        <div>
                                            <label>Method</label>
                                            <select name="return_method" required>
                                                <option value="cash">Cash</option>
                                                <option value="bank">Bank</option>
                                                <option value="pos">POS</option>
                                                <option value="stripe">Stripe</option>
                                                <option value="online">Online</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label>Return Date</label>
                                            <input type="date" name="return_date" value="<?= e(date('Y-m-d')) ?>" required>
                                        </div>
                                        <div>
                                            <label>Quick Fill</label>
                                            <button type="button" class="btn loan-fill-remaining-btn-v5" data-fill-remaining="<?= e(number_format((float)$row['remaining_total'], 2, '.', '')) ?>">
                                                Full Remaining
                                            </button>
                                        </div>
                                    </div>

                                    <div class="loan-return-notes-v5">
                                        <label>Notes</label>
                                        <input type="text" name="return_notes" placeholder="Optional note for this return">
                                    </div>

                                    <div class="loan-return-actions-v5">
                                        <button type="submit" class="btn btn-primary">Save Return</button>
                                        <button type="button" class="btn loan-close-return-btn-v5" data-loan-close="<?= (int)$row['ID'] ?>">Close</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="muted">No active loans.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const resultWrap = document.getElementById('loan_donor_results');
    const personInput = document.getElementById('loan_person_id');
    const selectedBox = document.getElementById('loan_selected_donor_box');
    const selectedName = document.getElementById('loan_selected_donor_name');
    const selectedMeta = document.getElementById('loan_selected_donor_meta');
    const fieldSection = document.getElementById('loan_fields_section');
    const amountInput = document.getElementById('loan_amount_input_v5');

    function enableFields() {
        if (fieldSection) fieldSection.classList.remove('is-disabled');
        if (selectedBox) selectedBox.classList.add('is-selected');
    }

    function goForm() {
        if (fieldSection) {
            fieldSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function selectDonor(id, name, city, phone) {
        if (personInput) personInput.value = id;
        if (selectedName) selectedName.textContent = name || 'No donor selected';
        if (selectedMeta) {
            selectedMeta.textContent = ['ID ' + id, city || '', phone || ''].filter(Boolean).join(' · ');
        }
        enableFields();

        if (resultWrap) {
            resultWrap.innerHTML = '';
            resultWrap.style.display = 'none';
        }

        goForm();
    }

    if (resultWrap) {
        resultWrap.addEventListener('click', function (e) {
            const btn = e.target.closest('.loan-donor-result-v5');
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

    document.querySelectorAll('.loan-amount-add-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!amountInput) return;
            const add = parseFloat(btn.dataset.add || '0');
            const current = parseFloat(amountInput.value || '0');
            amountInput.value = (current + add).toFixed(2);
        });
    });

    document.querySelectorAll('[data-loan-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-loan-toggle');
            const row = document.getElementById('loan_return_row_' + id);
            if (!row) return;

            row.classList.toggle('hidden');
            if (!row.classList.contains('hidden')) {
                row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    });

    document.querySelectorAll('[data-loan-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-loan-close');
            const row = document.getElementById('loan_return_row_' + id);
            if (!row) return;
            row.classList.add('hidden');
        });
    });

    document.querySelectorAll('.loan-fill-remaining-btn-v5').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const form = btn.closest('form');
            if (!form) return;

            const input = form.querySelector('input[name="return_amount"]');
            if (!input) return;

            input.value = btn.getAttribute('data-fill-remaining') || '';
            input.focus();
        });
    });

    if (<?= $autoJumpToForm ? 'true' : 'false' ?>) {
        enableFields();
        window.setTimeout(goForm, 120);
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>