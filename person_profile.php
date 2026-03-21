<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'operator','accountant','admin');

$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0) {
    setFlash('error', 'Select a donor first.');
    header('Location: donors.php');
    exit;
}

$uid = function_exists('getLoggedInUserId') ? (int)getLoggedInUserId() : 0;

function profileMethodLabel(string $method): string {
    return match ($method) {
        'cash_manual', 'cash' => 'Cash',
        'bank_manual', 'bank' => 'Bank',
        'pos' => 'POS',
        'stripe_auto', 'stripe' => 'Stripe',
        'online' => 'Online',
        'no-cash' => 'No Cash',
        default => $method !== '' ? ucwords(str_replace('_', ' ', $method)) : '—',
    };
}

function profileCommitmentLabel(array $row): string {
    $category = (string)($row['category'] ?? '');
    return match ($category) {
        'monthly' => 'Monthly Expected',
        'event' => !empty($row['event_name']) ? 'Event · ' . (string)$row['event_name'] : 'Event Expected',
        default => 'One-Time Expected',
    };
}

function profileCommitmentStatusClass(string $status): string {
    return match ($status) {
        'paid' => 'green',
        'partial' => 'blue',
        'cancelled', 'expired' => 'red',
        default => 'orange',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && tableExists($pdo, 'donation_commitments')) {
    verifyCsrfOrFail();

    $action = (string)($_POST['commitment_action'] ?? '');
    $commitmentId = (int)($_POST['commitment_id'] ?? 0);

    if ($commitmentId > 0 && in_array($action, ['mark_paid', 'cancel'], true)) {
        $stmt = $pdo->prepare('
            SELECT ID, person_id, category, expected_amount, due_date, status, event_id
            FROM donation_commitments
            WHERE ID = ? AND person_id = ?
            LIMIT 1
        ');
        $stmt->execute([$commitmentId, $id]);
        $commitment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$commitment) {
            setFlash('error', 'Expected record not found.');
            header('Location: person_profile.php?id=' . $id);
            exit;
        }

        if ($action === 'mark_paid') {
            $stmt = $pdo->prepare('UPDATE donation_commitments SET status = ?, updated_at = NOW() WHERE ID = ?');
            $stmt->execute(['paid', $commitmentId]);

            if (function_exists('systemLog')) {
                systemLog($pdo, $uid, 'donation_expectation', 'mark_paid', 'Marked expected donation as paid', $commitmentId);
            }

            if (function_exists('personLog')) {
                personLog(
                    $pdo,
                    $uid,
                    $id,
                    'donation_expectation',
                    'Marked expected ' . $commitment['category'] . ' donation of ' . number_format((float)$commitment['expected_amount'], 2, '.', '') . ' as paid'
                );
            }

            setFlash('success', 'Expected record marked as paid.');
            header('Location: person_profile.php?id=' . $id);
            exit;
        }

        if ($action === 'cancel') {
            $stmt = $pdo->prepare('UPDATE donation_commitments SET status = ?, updated_at = NOW() WHERE ID = ?');
            $stmt->execute(['cancelled', $commitmentId]);

            if (function_exists('systemLog')) {
                systemLog($pdo, $uid, 'donation_expectation', 'cancel', 'Cancelled expected donation', $commitmentId);
            }

            if (function_exists('personLog')) {
                personLog(
                    $pdo,
                    $uid,
                    $id,
                    'donation_expectation',
                    'Cancelled expected ' . $commitment['category'] . ' donation of ' . number_format((float)$commitment['expected_amount'], 2, '.', '')
                );
            }

            setFlash('success', 'Expected record cancelled.');
            header('Location: person_profile.php?id=' . $id);
            exit;
        }
    }
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

$totalIn = 0;
$totalOut = 0;
$selfDonationTotal = 0;
$collectedDonationTotal = 0;
$localDonationValueTotal = 0;

$expectedRows = [];

$hasSourceColumns = function_exists('columnExists')
    && tableExists($pdo, 'operator_ledger')
    && columnExists($pdo, 'operator_ledger', 'source_type')
    && columnExists($pdo, 'operator_ledger', 'contributor_count')
    && columnExists($pdo, 'operator_ledger', 'source_note');

$donationRows = [];
if (tableExists($pdo, 'operator_ledger')) {
    if ($hasSourceColumns) {
        $stmt = $pdo->prepare('
            SELECT
                ID,
                created_at AS date,
                transaction_category AS cat,
                amount,
                payment_method AS method,
                notes,
                invoice_no,
                source_type,
                contributor_count,
                source_note
            FROM operator_ledger
            WHERE person_id = ?
              AND amount >= 0
              AND is_removed = 0
            ORDER BY ID DESC
            LIMIT 100
        ');
        $stmt->execute([$id]);
        $donationRows = $stmt->fetchAll();
    } else {
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
              AND amount >= 0
              AND is_removed = 0
            ORDER BY ID DESC
            LIMIT 100
        ');
        $stmt->execute([$id]);
        $donationRows = $stmt->fetchAll();
    }
}

foreach ($donationRows as $t) {
    $amountValue = (float)$t['amount'];
    $totalIn += $amountValue;

    if ($hasSourceColumns && ($t['source_type'] ?? 'self') === 'group_collected') {
        $collectedDonationTotal += $amountValue;
    } else {
        $selfDonationTotal += $amountValue;
    }
}

$expenseRows = [];
if (tableExists($pdo, 'expense')) {
    $hasPaymentMethod = function_exists('columnExists') && columnExists($pdo, 'expense', 'payment_method');
    $hasExpenseDate = function_exists('columnExists') && columnExists($pdo, 'expense', 'expense_date');
    $hasDonationFlag = function_exists('columnExists') && columnExists($pdo, 'expense', 'donation');

    $methodSelect = $hasPaymentMethod ? 'e.payment_method' : "''";
    $dateSelect = $hasExpenseDate ? 'e.expense_date' : 'DATE(e.created_at)';
    $donationSelect = $hasDonationFlag ? 'e.donation' : '0';

    $stmt = $pdo->prepare("
        SELECT
            e.ID,
            {$dateSelect} AS date,
            COALESCE(ec.category_name, CONCAT('Category #', e.exp_cat)) AS cat,
            e.name AS item_name,
            e.amount,
            {$methodSelect} AS method,
            e.notes,
            NULL AS invoice_no,
            {$donationSelect} AS donation_flag,
            e.created_at
        FROM expense e
        LEFT JOIN expense_categories ec ON ec.ID = e.exp_cat
        WHERE e.pid = ?
        ORDER BY e.created_at DESC, e.ID DESC
        LIMIT 100
    ");
    $stmt->execute([$id]);
    $expenseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($expenseRows as $row) {
    $isSponsored = (int)($row['donation_flag'] ?? 0) === 1;
    if ($isSponsored) {
        $localDonationValueTotal += (float)$row['amount'];
    } else {
        $totalOut += (float)$row['amount'];
    }
}

if (tableExists($pdo, 'donation_commitments')) {
    $hasExpectedSourceColumns =
        function_exists('columnExists')
        && columnExists($pdo, 'donation_commitments', 'is_collected_for_others')
        && columnExists($pdo, 'donation_commitments', 'contributor_count')
        && columnExists($pdo, 'donation_commitments', 'source_note');

    if ($hasExpectedSourceColumns) {
        $stmt = $pdo->prepare('
            SELECT
                dc.ID,
                dc.category,
                dc.event_id,
                dc.expected_amount,
                dc.due_date,
                dc.status,
                dc.notes,
                dc.created_at,
                dc.is_collected_for_others,
                dc.contributor_count,
                dc.source_note,
                ev.name AS event_name
            FROM donation_commitments dc
            LEFT JOIN events ev ON ev.ID = dc.event_id
            WHERE dc.person_id = ?
            ORDER BY
                CASE
                    WHEN dc.status = "pending" THEN 0
                    WHEN dc.status = "partial" THEN 1
                    WHEN dc.status = "paid" THEN 2
                    WHEN dc.status = "cancelled" THEN 3
                    WHEN dc.status = "expired" THEN 4
                    ELSE 5
                END,
                dc.due_date ASC,
                dc.ID DESC
            LIMIT 100
        ');
        $stmt->execute([$id]);
        $expectedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare('
            SELECT
                dc.ID,
                dc.category,
                dc.event_id,
                dc.expected_amount,
                dc.due_date,
                dc.status,
                dc.notes,
                dc.created_at,
                0 AS is_collected_for_others,
                NULL AS contributor_count,
                NULL AS source_note,
                ev.name AS event_name
            FROM donation_commitments dc
            LEFT JOIN events ev ON ev.ID = dc.event_id
            WHERE dc.person_id = ?
            ORDER BY
                CASE
                    WHEN dc.status = "pending" THEN 0
                    WHEN dc.status = "partial" THEN 1
                    WHEN dc.status = "paid" THEN 2
                    WHEN dc.status = "cancelled" THEN 3
                    WHEN dc.status = "expired" THEN 4
                    ELSE 5
                END,
                dc.due_date ASC,
                dc.ID DESC
            LIMIT 100
        ');
        $stmt->execute([$id]);
        $expectedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$transactions = array_merge(
    array_map(static function (array $row): array {
        return [
            'date' => $row['date'],
            'invoice_no' => $row['invoice_no'] ?? null,
            'cat' => $row['cat'],
            'amount' => (float)$row['amount'],
            'method' => $row['method'] ?? '',
            'notes' => $row['notes'] ?? '',
            'sort_date' => strtotime((string)$row['date']) ?: 0,
        ];
    }, $donationRows),
    array_map(static function (array $row): array {
        $isSponsored = (int)($row['donation_flag'] ?? 0) === 1;
        return [
            'date' => $row['date'],
            'invoice_no' => null,
            'cat' => $isSponsored ? 'Sponsored Expense' : 'Expense',
            'amount' => (float)$row['amount'],
            'method' => $row['method'] ?? ($isSponsored ? 'no-cash' : ''),
            'notes' => trim((string)($row['item_name'] ?? '') . (!empty($row['notes']) ? ' | ' . (string)$row['notes'] : '')),
            'sort_date' => strtotime((string)$row['date']) ?: 0,
        ];
    }, $expenseRows)
);

usort($transactions, static function (array $a, array $b): int {
    return ($b['sort_date'] <=> $a['sort_date']);
});

$mobileTransactions = array_values(array_filter($transactions, static function (array $row) use ($mobileCutoff): bool {
    return strtotime((string)$row['date']) >= strtotime($mobileCutoff);
}));
$mobileTransactions = array_slice($mobileTransactions, 0, 100);

$dueMonths = monthlyOutstandingMonths($pdo, $id);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$publicPath = ($basePath !== '' ? $basePath : '') . '/public_donor_report.php?id=' . (int)$person['ID'];
$publicUrl = ($host !== '' ? ($scheme . '://' . $host . $publicPath) : ('public_donor_report.php?id=' . (int)$person['ID']));
$publicQr = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($publicUrl);

$pageClass = 'page-person-profile';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title">Donor Profile &amp; Activity Ledger</h1>

<div class="person-profile-v5">
    <div class="person-profile-top-v5">
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
                    <a class="btn btn-primary" href="transaction_page.php?person_id=<?= (int)$person['ID'] ?>#selected_donor_position_v5">Collect Donation</a>
                    <a class="btn" href="add_expense.php?person_id=<?= (int)$person['ID'] ?>">Add Expense / Sponsored Expense</a>
                </div>

                <div class="person-info-note-v5">
                    <strong>Reference donor note:</strong> Expenses added from this profile are operator-handled records. The selected donor is only a reference for reporting. This does not mean the donor personally paid or made the expense.
                </div>
            </div>

            <div class="person-summary-grid-v5">
                <div class="card person-summary-card-v5">
                    <div class="muted">Total Donations</div>
                    <div class="summary"><?= money($totalIn) ?></div>
                </div>
                <div class="card person-summary-card-v5">
                    <div class="muted">Own Donations</div>
                    <div class="summary"><?= money($selfDonationTotal) ?></div>
                </div>
                <div class="card person-summary-card-v5">
                    <div class="muted">Collected from Others</div>
                    <div class="summary"><?= money($collectedDonationTotal) ?></div>
                </div>
                <div class="card person-summary-card-v5">
                    <div class="muted">Operator-handled Expense</div>
                    <div class="summary"><?= money($totalOut) ?></div>
                </div>
                <div class="card person-summary-card-v5">
                    <div class="muted">Sponsored / Informational Value</div>
                    <div class="summary"><?= money($localDonationValueTotal) ?></div>
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
                <div class="helper person-side-note-v5">Sponsored expense values linked to this donor are reference-only records for reports. They do not mean this donor directly paid cash for the expense.</div>
                <div class="public-link-box-v5">
                    <span class="public-link-text" 
                        onclick="copyPublicLink(this)" 
                        data-link="<?= e($publicUrl) ?>">
                        <?= e($publicUrl) ?>
                    </span>

                    <a class="btn-open-report" 
                    href="<?= e($publicUrl) ?>" 
                    target="_blank" 
                    rel="noopener">
                    Open
                    </a>
                </div>
            </div>

            <?php if ($expectedRows): ?>
            <div class="card">
                <div class="toolbar">
                    <h2 class="section-head-v5" style="margin:0">Expected Commitments</h2>
                    <span class="tag blue"><?= count($expectedRows) ?> record<?= count($expectedRows) === 1 ? '' : 's' ?></span>
                </div>

                <div class="person-expected-list-v5">
                    <?php foreach ($expectedRows as $row): ?>
                        <?php
                            $status = (string)($row['status'] ?? 'pending');
                            $dueDate = (string)($row['due_date'] ?? '');
                            $isOverdue = in_array($status, ['pending', 'partial'], true) && $dueDate !== '' && strtotime($dueDate) < strtotime(date('Y-m-d'));
                            $notes = trim((string)($row['notes'] ?? ''));
                            $extraBits = [];

                            if ((int)($row['is_collected_for_others'] ?? 0) === 1) {
                                $extraBits[] = 'Collected for others';
                            }
                            if (!empty($row['contributor_count'])) {
                                $extraBits[] = 'Contributors: ' . (int)$row['contributor_count'];
                            }
                            if (!empty($row['source_note'])) {
                                $extraBits[] = (string)$row['source_note'];
                            }
                            if ($notes !== '') {
                                $extraBits[] = $notes;
                            }

                            $canAct = in_array($status, ['pending', 'partial'], true);
                            $collectUrl = 'transaction_page.php?person_id=' . (int)$person['ID']
                                . '&expected_commitment_id=' . (int)$row['ID']
                                . '&collect_now=1#category_start_v5';
                            $editUrl = 'transaction_page.php?person_id=' . (int)$person['ID']
                                . '&expected_commitment_id=' . (int)$row['ID']
                                . '#category_start_v5';
                        ?>
                        <div class="person-expected-item-v5<?= $isOverdue ? ' is-overdue' : '' ?>">
                            <div class="person-expected-top-v5">
                                <div>
                                    <strong><?= e(profileCommitmentLabel($row)) ?></strong>
                                    <div class="muted">Created: <?= e((string)$row['created_at']) ?></div>
                                </div>
                                <div class="person-expected-right-v5">
                                    <span class="tag <?= e(profileCommitmentStatusClass($status)) ?>">
                                        <?= e(ucfirst($status)) ?>
                                    </span>
                                    <?php if ($isOverdue): ?>
                                        <span class="tag red">Overdue</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="person-expected-grid-v5">
                                <div class="person-expected-cell-v5">
                                    <span class="muted">Expected Amount</span>
                                    <strong><?= money((float)$row['expected_amount']) ?></strong>
                                </div>
                                <div class="person-expected-cell-v5">
                                    <span class="muted">Due Date</span>
                                    <strong><?= e($dueDate !== '' ? $dueDate : '—') ?></strong>
                                </div>
                            </div>

                            <?php if ($extraBits): ?>
                                <div class="person-expected-notes-v5"><?= e(implode(' | ', $extraBits)) ?></div>
                            <?php endif; ?>

                            <div class="person-expected-actions-v5">
                                <?php if ($canAct): ?>
                                    <a class="btn btn-primary" href="<?= e($collectUrl) ?>">Collect Now</a>
                                    <a class="btn" href="<?= e($editUrl) ?>">Edit</a>

                                    <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this expected record?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="commitment_action" value="cancel">
                                        <input type="hidden" name="commitment_id" value="<?= (int)$row['ID'] ?>">
                                        <button type="submit" class="btn">Cancel</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">No action available for this status.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card person-profile-full-v5 desktop-activity-card-v5">
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
                        <th>Amount / Value</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $i => $row): ?>
                    <?php $rowNotes = trim((string)($row['notes'] ?? '')); ?>
                    <tr class="activity-row-v5<?= $rowNotes !== '' ? ' has-notes-v5' : '' ?>"<?= $rowNotes !== '' ? ' tabindex="0" role="button" aria-expanded="false"' : '' ?> data-detail-id="activity-detail-<?= (int)$i ?>">
                        <td><?= e((string)$row['date']) ?></td>
                        <td><?= e((string)($row['invoice_no'] ?? '—')) ?></td>
                        <td><span class="tag orange"><?= e((string)$row['cat']) ?></span></td>
                        <td><?= money((float)$row['amount']) ?></td>
                        <td><?= e(profileMethodLabel((string)$row['method'])) ?></td>
                    </tr>
                    <?php if ($rowNotes !== ''): ?>
                    <tr class="activity-detail-row-v5" id="activity-detail-<?= (int)$i ?>" hidden>
                        <td colspan="5">
                            <div class="activity-detail-box-v5">
                                <strong>Notes / Clarification</strong>
                                <div><?= nl2br(e($rowNotes)) ?></div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$transactions): ?>
                    <tr><td colspan="5" class="muted">No activity found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card person-profile-full-v5 mobile-activity-card-v5">
        <div class="toolbar">
            <h2 class="section-head-v5" style="margin:0">Recent Activity</h2>
            <span class="tag blue">Last 6 months</span>
        </div>
        <div class="helper">Full activity ledger is shown on desktop view. Sponsored expense entries shown here are reference-only and do not mean the donor directly paid the expense.</div>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.activity-row-v5.has-notes-v5').forEach(function (row) {
        var detailId = row.getAttribute('data-detail-id');
        var detailRow = detailId ? document.getElementById(detailId) : null;
        if (!detailRow) return;

        var toggle = function () {
            var isHidden = detailRow.hasAttribute('hidden');
            if (isHidden) {
                detailRow.removeAttribute('hidden');
                row.setAttribute('aria-expanded', 'true');
                row.classList.add('is-open-v5');
            } else {
                detailRow.setAttribute('hidden', 'hidden');
                row.setAttribute('aria-expanded', 'false');
                row.classList.remove('is-open-v5');
            }
        };

        row.addEventListener('click', function () {
            toggle();
        });

        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle();
            }
        });
    });
});
</script>
<script>
function copyPublicLink(el){
    const link = el.getAttribute('data-link');

    navigator.clipboard.writeText(link).then(()=>{
        const original = el.innerText;
        el.innerText = "Copied ✓";
        
        setTimeout(()=>{
            el.innerText = original;
        }, 1500);
    });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>