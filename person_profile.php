
<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'operator');

$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0) {
    setFlash('error', 'Select a donor first.');
    header('Location: donors.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT p.*, s.name AS society_name
    FROM people p
    LEFT JOIN societies s ON s.ID = p.death_insurance_society_id
    WHERE p.ID = ?
    LIMIT 1
');
$stmt->execute([$id]);
$person = $stmt->fetch();

if (!$person) {
    setFlash('error', 'Donor not found.');
    header('Location: donors.php');
    exit;
}

$plan = getPersonCurrentPlan($pdo, $id);
$transactions = [];
$mobileTransactions = [];
$mobileCutoff = date('Y-m-d H:i:s', strtotime('-6 months'));

if (tableExists($pdo, 'operator_ledger')) {
    $stmt = $pdo->prepare('
        SELECT
            ID,
            created_at AS date,
            transaction_category AS cat,
            amount,
            payment_method AS method,
            notes,
            invoice_no
        FROM operator_ledger
        WHERE person_id = ?
        ORDER BY ID DESC
        LIMIT 100
    ');
    $stmt->execute([$id]);
    $transactions = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT
            ID,
            created_at AS date,
            transaction_category AS cat,
            amount,
            payment_method AS method,
            notes,
            invoice_no
        FROM operator_ledger
        WHERE person_id = ?
          AND created_at >= ?
        ORDER BY ID DESC
        LIMIT 100
    ');
    $stmt->execute([$id, $mobileCutoff]);
    $mobileTransactions = $stmt->fetchAll();
}

$totalIn = 0;
$totalOut = 0;
foreach ($transactions as $t) {
    if ((float)$t['amount'] >= 0) {
        $totalIn += (float)$t['amount'];
    } else {
        $totalOut += abs((float)$t['amount']);
    }
}

$dueMonths = monthlyOutstandingMonths($pdo, $id);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$publicPath = ($basePath !== '' ? $basePath : '') . '/public_donor_report.php?id=' . (int)$person['ID'];
$publicUrl = ($host !== '' ? ($scheme . '://' . $host . $publicPath) : ('public_donor_report.php?id=' . (int)$person['ID']));
$publicQr = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($publicUrl);

function profileMethodLabel(string $method): string {
    return match ($method) {
        'cash_manual', 'cash' => 'Cash',
        'bank_manual', 'bank' => 'Bank',
        'pos' => 'POS',
        'stripe_auto', 'stripe' => 'Stripe',
        'online' => 'Online',
        default => ucwords(str_replace('_', ' ', $method)),
    };
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Donation &amp; Activity Ledger</h1>

<div class="person-profile-v5">
    <div class="person-profile-main-v5">
        <div class="card person-hero-card-v5">
            <div class="toolbar person-hero-top-v5">
                <div>
                    <h2 class="person-name-v5"><?= e((string)$person['name']) ?></h2>
                    <div class="muted">
                        <?= e((string)$person['phone']) ?><?= !empty($person['city']) ? ' · ' . e((string)$person['city']) : '' ?>
                    </div>
                </div>
                <a class="btn" href="add_person.php?id=<?= (int)$person['ID'] ?>">Edit Donor</a>
            </div>

            <div class="badge-list person-badges-v5">
                <?php if ((int)$person['life_membership'] === 1): ?><span class="tag blue">Life Membership</span><?php endif; ?>
                <?php if ((int)$person['monthly_subscription'] === 1): ?><span class="tag orange">Monthly</span><?php endif; ?>
                <?php if ((int)$person['death_insurance_enabled'] === 1): ?><span class="tag green">Death Insurance</span><?php endif; ?>
            </div>

            <div class="person-actions-v5">
                <a class="btn btn-primary" href="transaction_page.php?person_id=<?= (int)$person['ID'] ?>#category_start_v5">Collect Donation</a>
                <a class="btn" href="expense_page.php?person_id=<?= (int)$person['ID'] ?>">Add Expense</a>
                <a class="btn" href="public_donor_report.php?id=<?= (int)$person['ID'] ?>">Open Public Report</a>
            </div>
        </div>

        <div class="person-summary-grid-v5">
            <div class="card person-summary-card-v5">
                <div class="muted">Total Donations</div>
                <div class="summary"><?= money($totalIn) ?></div>
            </div>
            <div class="card person-summary-card-v5">
                <div class="muted">Total Expenses</div>
                <div class="summary"><?= money($totalOut) ?></div>
            </div>
            <div class="card person-summary-card-v5">
                <div class="muted">Net</div>
                <div class="summary"><?= money($totalIn - $totalOut) ?></div>
            </div>
            <div class="card person-summary-card-v5">
                <div class="muted">Monthly Agreed</div>
                <div class="summary"><?= money((float)($plan['amount'] ?? 0)) ?></div>
            </div>
        </div>

        <?php if ($plan): ?>
        <div class="card">
            <h2 class="section-head-v5">Monthly Plan</h2>
            <div class="person-info-grid-v5">
                <div class="person-info-row-v5"><strong>Mode</strong><span><?= e(profileMethodLabel((string)$plan['payment_mode'])) ?></span></div>
                <div class="person-info-row-v5"><strong>Amount</strong><span><?= money((float)$plan['amount']) ?></span></div>
                <div class="person-info-row-v5"><strong>Status</strong><span><span class="tag <?= (int)$plan['active'] === 1 ? 'green' : 'red' ?>"><?= (int)$plan['active'] === 1 ? 'Active' : 'Suspended' ?></span></span></div>
                <div class="person-info-row-v5"><strong>Due / Pending Months</strong><span><?= e(implode(', ', array_slice($dueMonths, 0, 6))) ?: 'None' ?></span></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ((int)$person['death_insurance_enabled'] === 1): ?>
        <div class="card">
            <h2 class="section-head-v5">Death Insurance Details</h2>
            <div class="person-info-grid-v5">
                <div class="person-info-row-v5"><strong>Society</strong><span><?= e((string)($person['society_name'] ?? 'Current Mosque')) ?></span></div>
                <div class="person-info-row-v5"><strong>Religion / Sect</strong><span><?= e((string)$person['religion_sect']) ?></span></div>
                <div class="person-info-row-v5"><strong>Home Country Contact</strong><span><?= e((string)$person['home_country_reference_name']) ?><?= $person['home_town_phone'] ? ' · ' . e((string)$person['home_town_phone']) : '' ?></span></div>
                <div class="person-info-row-v5"><strong>Italy Contact</strong><span><?= e((string)$person['italy_reference_name']) ?><?= $person['italy_reference_phone'] ? ' · ' . e((string)$person['italy_reference_phone']) : '' ?></span></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="person-profile-side-v5">
        <div class="card qr-card-v5">
            <h2 class="section-head-v5">Public QR / Report</h2>
            <div class="qr-wrap-v5">
                <img src="<?= e($publicQr) ?>" alt="Donor public report QR" width="220" height="220">
            </div>
            <div class="helper">If the QR does not scan on some phones, use the public link below. Anyone can open the page and verify with the last 3 digits of the donor phone number.</div>
            <div class="public-link-box-v5">
                <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"><?= e($publicUrl) ?></a>
            </div>
        </div>

        <div class="card desktop-activity-card-v5">
            <div class="toolbar">
                <h2 class="section-head-v5" style="margin:0">Full Activity</h2>
                <span class="tag blue">Member ID #<?= (int)$person['ID'] ?></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Invoice</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $row): ?>
                        <tr>
                            <td><?= e((string)$row['date']) ?></td>
                            <td><?= e((string)($row['invoice_no'] ?? '—')) ?></td>
                            <td><span class="tag orange"><?= e((string)$row['cat']) ?></span></td>
                            <td><?= money((float)$row['amount']) ?></td>
                            <td><?= e(profileMethodLabel((string)$row['method'])) ?></td>
                            <td><?= e((string)$row['notes']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$transactions): ?>
                        <tr><td colspan="6" class="muted">No activity found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mobile-activity-card-v5">
            <div class="toolbar">
                <h2 class="section-head-v5" style="margin:0">Recent Activity</h2>
                <span class="tag blue">Last 6 months</span>
            </div>
            <div class="helper">Full activity ledger is shown on desktop view.</div>

            <div class="mobile-activity-list-v5">
                <?php foreach ($mobileTransactions as $row): ?>
                <div class="mobile-activity-item-v5">
                    <div class="mobile-activity-top-v5">
                        <span class="tag orange"><?= e((string)$row['cat']) ?></span>
                        <strong><?= money((float)$row['amount']) ?></strong>
                    </div>
                    <div class="muted"><?= e((string)$row['date']) ?></div>
                    <div class="mobile-activity-meta-v5"><?= e(profileMethodLabel((string)$row['method'])) ?><?= !empty($row['invoice_no']) ? ' · ' . e((string)$row['invoice_no']) : '' ?></div>
                    <?php if (!empty($row['notes'])): ?><div class="mobile-activity-notes-v5"><?= e((string)$row['notes']) ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (!$mobileTransactions): ?><div class="muted">No activity found in the last 6 months.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
