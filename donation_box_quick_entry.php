<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);


require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, ['operator', 'accountant', 'admin']);

$uid = (int)($_SESSION['user_id'] ?? 0);
$errors = [];
$success = null;

function qb_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function qb_money(float $amount): string
{
    return '€' . number_format($amount, 2);
}

function qb_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
        LIMIT 1
    ');
    $stmt->execute([$table]);

    return $cache[$table] = (bool)$stmt->fetchColumn();
}

function qb_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare('
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
        LIMIT 1
    ');
    $stmt->execute([$table, $column]);

    return $cache[$key] = (bool)$stmt->fetchColumn();
}

function qb_generate_invoice(): string
{
    return 'AC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function qb_ensure_donation_box_person(PDO $pdo, int $uid = 0): int
{
    $name = 'Donation Box Collection';

    $find = $pdo->prepare('SELECT ID FROM people WHERE name = ? LIMIT 1');
    $find->execute([$name]);
    $existingId = (int)$find->fetchColumn();

    if ($existingId > 0) {
        return $existingId;
    }

    $hasCreatedBy = qb_column_exists($pdo, 'people', 'created_by');
    $hasUpdatedBy = qb_column_exists($pdo, 'people', 'updated_by');
    $hasUid = qb_column_exists($pdo, 'people', 'uid');
    $hasCreatedAt = qb_column_exists($pdo, 'people', 'created_at');
    $hasUpdatedAt = qb_column_exists($pdo, 'people', 'updated_at');
    $hasCity = qb_column_exists($pdo, 'people', 'city');
    $hasPhone = qb_column_exists($pdo, 'people', 'phone');
    $hasNotes = qb_column_exists($pdo, 'people', 'notes');

    $columns = ['name'];
    $placeholders = ['?'];
    $values = [$name];

    if ($hasPhone) { $columns[] = 'phone'; $placeholders[] = '?'; $values[] = null; }
    if ($hasCity) { $columns[] = 'city'; $placeholders[] = '?'; $values[] = 'System'; }
    if ($hasNotes) { $columns[] = 'notes'; $placeholders[] = '?'; $values[] = 'Auto generated default collection donor'; }
    if ($hasCreatedBy) { $columns[] = 'created_by'; $placeholders[] = '?'; $values[] = $uid ?: null; }
    if ($hasUpdatedBy) { $columns[] = 'updated_by'; $placeholders[] = '?'; $values[] = $uid ?: null; }
    if ($hasUid) { $columns[] = 'uid'; $placeholders[] = '?'; $values[] = $uid ?: null; }
    if ($hasCreatedAt) { $columns[] = 'created_at'; $placeholders[] = 'NOW()'; }
    if ($hasUpdatedAt) { $columns[] = 'updated_at'; $placeholders[] = 'NOW()'; }

    $sql = 'INSERT INTO people (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $pdo->prepare($sql)->execute($values);

    return (int)$pdo->lastInsertId();
}

$boxToken = trim((string)($_GET['box'] ?? $_POST['box_token'] ?? ''));
$box = null;
$boxId = 0;
$boxLabel = '';

if ($boxToken !== '' && qb_table_exists($pdo, 'donation_boxes')) {
    $stmt = $pdo->prepare('
        SELECT ID, box_number, title, active, qr_token
        FROM donation_boxes
        WHERE qr_token = ?
          AND active = 1
        LIMIT 1
    ');
    $stmt->execute([$boxToken]);
    $box = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($box) {
        $boxId = (int)$box['ID'];
        $boxLabel = (string)$box['box_number'];

        if (trim((string)($box['title'] ?? '')) !== '') {
            $boxLabel .= ' - ' . (string)$box['title'];
        }
    } else {
        $errors[] = 'Invalid or inactive donation box QR code.';
    }
} else {
    $errors[] = 'Missing donation box QR token.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    verifyCsrfOrFail();

    $amount = (float)($_POST['amount'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));
    $paymentMethod = 'cash';
    $collectionType = 'donation_box';

    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than 0.';
    }

    if ($boxId <= 0) {
        $errors[] = 'Donation box not found.';
    }

    if (!$errors) {
        $personId = qb_ensure_donation_box_person($pdo, $uid);

        $insert = $pdo->prepare('
            INSERT INTO anonymous_collections
                (person_id, box_id, collection_type, amount, payment_method, notes, created_by, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $insert->execute([
            $personId > 0 ? $personId : null,
            $boxId,
            $collectionType,
            $amount,
            $paymentMethod,
            $notes !== '' ? $notes : null,
            $uid ?: null,
        ]);

        if (qb_table_exists($pdo, 'operator_ledger')) {
            $referenceId = (int)$pdo->lastInsertId();
            $invoiceNo = qb_generate_invoice();
            $receiptToken = bin2hex(random_bytes(8));
            $ledgerNotes = 'DONATION_BOX' . ($notes !== '' ? ' | ' . $notes : '');

            $ledger = $pdo->prepare('
                INSERT INTO operator_ledger (
                    operator_id, person_id, transaction_type, transaction_category, amount, payment_method,
                    settlement_status, reference_id, invoice_no, receipt_token, notes, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $ledger->execute([
                $uid,
                $personId > 0 ? $personId : null,
                'collection',
                'one_time',
                $amount,
                'cash',
                'confirmed',
                $referenceId,
                $invoiceNo,
                $receiptToken,
                $ledgerNotes,
                $uid,
            ]);
        }

        $success = 'Donation box collection saved successfully.';
        $_POST = [];
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.qb-page {
    max-width: 560px;
    margin: 24px auto;
    padding: 0 16px;
}

.qb-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
}

.qb-title {
    margin: 0 0 6px;
    font-size: 24px;
    font-weight: 700;
}

.qb-subtitle {
    margin: 0 0 18px;
    color: #6b7280;
    font-size: 14px;
}

.qb-alert {
    padding: 12px 14px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 14px;
}

.qb-alert.success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
}

.qb-alert.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.qb-box-info {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 18px;
}

.qb-box-label {
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 4px;
}

.qb-box-name {
    font-size: 18px;
    font-weight: 700;
    color: #111827;
}

.qb-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
}

.qb-field label {
    font-size: 14px;
    font-weight: 600;
}

.qb-field input,
.qb-field textarea {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 15px;
    box-sizing: border-box;
}

.qb-field textarea {
    min-height: 100px;
    resize: vertical;
}

.qb-inline-note {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 16px;
}

.qb-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.qb-btn,
.qb-btn-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    padding: 11px 16px;
    text-decoration: none;
    cursor: pointer;
    border: 0;
    font-size: 14px;
    font-weight: 600;
}

.qb-btn {
    background: #111827;
    color: #fff;
}

.qb-btn-secondary {
    background: #f3f4f6;
    color: #111827;
    border: 1px solid #e5e7eb;
}
</style>

<div class="qb-page">
    <div class="qb-card">
        <h1 class="qb-title">Donation Box Entry</h1>
        <p class="qb-subtitle">Quick cash entry for scanned donation box QR codes.</p>

        <?php if ($success): ?>
            <div class="qb-alert success"><?= qb_e($success) ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="qb-alert error">
                <?php foreach ($errors as $error): ?>
                    <div><?= qb_e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($boxId > 0): ?>
            <div class="qb-box-info">
                <div class="qb-box-label">Donation Box</div>
                <div class="qb-box-name"><?= qb_e($boxLabel) ?></div>
            </div>

            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="box_token" value="<?= qb_e($boxToken) ?>">

                <div class="qb-field">
                    <label for="amount">Amount</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0.01"
                        name="amount"
                        id="amount"
                        required
                        autofocus
                        value="<?= qb_e((string)($_POST['amount'] ?? '')) ?>"
                    >
                </div>

                <div class="qb-inline-note">
                    Payment method: <strong>Cash only</strong>
                </div>

                <div class="qb-field">
                    <label for="notes">Notes</label>
                    <textarea
                        name="notes"
                        id="notes"
                        placeholder="Optional notes"
                    ><?= qb_e((string)($_POST['notes'] ?? '')) ?></textarea>
                </div>

                <div class="qb-actions">
                    <button type="submit" class="qb-btn">Save Collection</button>
                    <a href="anonymous_collections.php" class="qb-btn-secondary">Open Full Page</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
