<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

requireAccountant($pdo);
if (currentRole($pdo) !== 'accountant' && currentRole($pdo) !== 'admin') {
    exit('Forbidden');
}

$uid = getLoggedInUserId();
$month = (string)($_GET['month'] ?? date('Y-m', strtotime('-1 month')));
$start = $month . '-01';
$errors = [];
$mode = 'edit';

function stripeMonthStart(string $month): string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return date('Y-m-01', strtotime('-1 month'));
    }

    return $month . '-01';
}

function normalizeStripeDecision($value): string
{
    $value = strtolower(trim((string)$value));
    if ($value === 'accept') {
        return 'accept';
    }
    if ($value === 'reject') {
        return 'reject';
    }
    return 'skip';
}

function buildDecisionBuckets(array $rows, array $postedDecisions): array
{
    $byPerson = [];
    foreach ($rows as $row) {
        $byPerson[(int)$row['person_id']] = $row;
    }

    $accepted = [];
    $rejected = [];
    $skipped = [];
    $decisions = [];

    foreach ($rows as $row) {
        $personId = (int)$row['person_id'];
        $decision = normalizeStripeDecision($postedDecisions[$personId] ?? 'accept');
        $decisions[$personId] = $decision;

        if ($decision === 'accept') {
            $accepted[] = $row;
        } elseif ($decision === 'reject') {
            $rejected[] = $row;
        } else {
            $skipped[] = $row;
        }
    }

    return [
        'by_person'  => $byPerson,
        'decisions'  => $decisions,
        'accepted'   => $accepted,
        'rejected'   => $rejected,
        'skipped'    => $skipped,
    ];
}

$rows = [];
if (tableExists($pdo, 'member_monthly_plans')) {
    $sql = '
        SELECT
            p.ID AS person_id,
            p.name,
            p.phone,
            m.ID AS plan_id,
            m.amount,
            m.payment_mode,
            m.active,
            m.notes
        FROM member_monthly_plans m
        JOIN people p ON p.ID = m.member_id
        WHERE m.active = 1
          AND m.payment_mode = "stripe_auto"
        ORDER BY p.name ASC
    ';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

$decisionState = [];
foreach ($rows as $row) {
    $decisionState[(int)$row['person_id']] = 'accept';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();

    $month = (string)($_POST['month'] ?? $month);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m', strtotime('-1 month'));
        $errors[] = 'Invalid month selected. Defaulted to previous month.';
    }
    $start = stripeMonthStart($month);

    $postedDecisions = is_array($_POST['decision'] ?? null) ? $_POST['decision'] : [];
    $bucketed = buildDecisionBuckets($rows, $postedDecisions);
    $decisionState = $bucketed['decisions'];

    $action = (string)($_POST['action'] ?? 'preview');

    if ($action === 'confirm') {
        $acceptedIds = array_map(static function ($row) {
            return (int)$row['person_id'];
        }, $bucketed['accepted']);

        $rejectedIds = array_map(static function ($row) {
            return (int)$row['person_id'];
        }, $bucketed['rejected']);

        foreach ($acceptedIds as $personId) {
            try {
                $pdo->beginTransaction();

                $plan = getPersonCurrentPlan($pdo, $personId);
                if (!$plan) {
                    $pdo->rollBack();
                    continue;
                }

                $planId = (int)$plan['ID'];
                $amount = (float)$plan['amount'];

                $existingDue = $pdo->prepare('
                    SELECT ID, status, ledger_id
                    FROM member_monthly_dues
                    WHERE plan_id = ? AND due_month = ?
                    LIMIT 1
                ');
                $existingDue->execute([$planId, $start]);
                $due = $existingDue->fetch(PDO::FETCH_ASSOC);

                if ($due && isset($due['status']) && $due['status'] === 'paid') {
                    $pdo->commit();
                    continue;
                }

                $stmt = $pdo->prepare('
                    INSERT INTO accountant_ledger
                    (
                        entry_type,
                        transaction_type,
                        transaction_category,
                        amount,
                        payment_method,
                        reference_id,
                        notes,
                        operator_id,
                        created_by,
                        created_at
                    )
                    VALUES
                    (
                        "stripe_reconciliation",
                        "collection",
                        "monthly",
                        ?,
                        "stripe",
                        ?,
                        ?,
                        NULL,
                        ?,
                        NOW()
                    )
                ');
                $stmt->execute([
                    $amount,
                    $personId,
                    'Stripe reconciliation for donor #' . $personId . ' / ' . $month,
                    $uid
                ]);
                $ledgerId = (int)$pdo->lastInsertId();

                if ($due) {
                    $upd = $pdo->prepare('
                        UPDATE member_monthly_dues
                        SET
                            expected_amount = ?,
                            status = "paid",
                            paid_amount = ?,
                            paid_at = NOW(),
                            payment_source = "stripe",
                            operator_id = NULL,
                            notes = ?,
                            ledger_id = ?
                        WHERE ID = ?
                    ');
                    $upd->execute([
                        $amount,
                        $amount,
                        'Posted from Stripe reconciliation',
                        $ledgerId,
                        (int)$due['ID']
                    ]);
                } else {
                    $ins = $pdo->prepare('
                        INSERT INTO member_monthly_dues
                        (
                            plan_id,
                            due_month,
                            expected_amount,
                            status,
                            paid_amount,
                            paid_at,
                            payment_source,
                            operator_id,
                            notes,
                            ledger_id
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            "paid",
                            ?,
                            NOW(),
                            "stripe",
                            NULL,
                            ?,
                            ?
                        )
                    ');
                    $ins->execute([
                        $planId,
                        $start,
                        $amount,
                        $amount,
                        'Posted from Stripe reconciliation',
                        $ledgerId
                    ]);
                }

                personLog(
                    $pdo,
                    $uid,
                    $personId,
                    'stripe_paid',
                    'Stripe marked paid for ' . $month . ' by accountant reconciliation'
                );

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Failed to post Stripe payment for donor ID ' . $personId . ': ' . $e->getMessage();
            }
        }

        foreach ($rejectedIds as $personId) {
            try {
                $pdo->beginTransaction();

                $plan = getPersonCurrentPlan($pdo, $personId);
                if (!$plan) {
                    $pdo->rollBack();
                    continue;
                }

                $planId = (int)$plan['ID'];
                $amount = (float)$plan['amount'];

                $existingDue = $pdo->prepare('
                    SELECT ID
                    FROM member_monthly_dues
                    WHERE plan_id = ? AND due_month = ?
                    LIMIT 1
                ');
                $existingDue->execute([$planId, $start]);
                $due = $existingDue->fetch(PDO::FETCH_ASSOC);

                if ($due) {
                    $upd = $pdo->prepare('
                        UPDATE member_monthly_dues
                        SET
                            expected_amount = ?,
                            status = "failed",
                            paid_amount = 0,
                            paid_at = NULL,
                            payment_source = "stripe",
                            operator_id = NULL,
                            notes = ?,
                            ledger_id = NULL
                        WHERE ID = ?
                    ');
                    $upd->execute([
                        $amount,
                        'Stripe failed for ' . $month,
                        (int)$due['ID']
                    ]);
                } else {
                    $ins = $pdo->prepare('
                        INSERT INTO member_monthly_dues
                        (
                            plan_id,
                            due_month,
                            expected_amount,
                            status,
                            paid_amount,
                            payment_source,
                            notes
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            "failed",
                            0,
                            "stripe",
                            ?
                        )
                    ');
                    $ins->execute([
                        $planId,
                        $start,
                        $amount,
                        'Stripe failed for ' . $month
                    ]);
                }

                $pdo->prepare('
                    UPDATE member_monthly_plans
                    SET active = 0, notes = ?
                    WHERE ID = ?
                ')->execute([
                    'Suspended after failed Stripe payment in ' . $month,
                    $planId
                ]);

                personLog(
                    $pdo,
                    $uid,
                    $personId,
                    'stripe_failed',
                    'Stripe failed and plan suspended for ' . $month
                );

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Failed to mark Stripe failure for donor ID ' . $personId . ': ' . $e->getMessage();
            }
        }

        systemLog(
            $pdo,
            $uid,
            'stripe',
            'reconcile',
            'Month ' . $month
            . ' reconciled. Accepted: ' . count($acceptedIds)
            . ', Rejected: ' . count($rejectedIds)
            . ', Skipped: ' . count($bucketed['skipped'])
        );

        if ($errors) {
            setFlash('error', implode(' | ', $errors));
        } else {
            setFlash(
                'success',
                'Stripe reconciliation completed. Accepted: '
                . count($acceptedIds)
                . ', Rejected: '
                . count($rejectedIds)
                . ', Skipped: '
                . count($bucketed['skipped'])
                . '.'
            );
        }

        header('Location: stripe_reconciliation.php?month=' . urlencode($month));
        exit;
    }

    $mode = 'preview';
}

$bucketed = buildDecisionBuckets($rows, $decisionState);

require_once __DIR__ . '/includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
    <h1 class="title" style="margin:0;">Stripe Reconciliation</h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn soft" href="transaction_approval_requests.php?status=pending&method=all&month=<?= urlencode($month) ?>">All Transaction Approvals</a>
        <a class="btn soft" href="transaction_delete_requests.php?status=pending">Delete Requests</a>
    </div>
</div>

<div class="helper stripe-helper-gap">
    All active Stripe donors are listed for the selected month.
    First review decisions, then verify the summary, then confirm posting.
    Rejected donors will be marked failed and their monthly plan will be suspended.
</div>

<?php if ($mode === 'preview'): ?>
    <div class="card" style="margin-bottom:16px;">
        <h2 style="margin-top:0;">Reconciliation Summary</h2>

        <div class="stripe-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
            <div class="stripe-summary-card" style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
                <div class="muted">Month</div>
                <div><strong><?= e($month) ?></strong></div>
            </div>
            <div class="stripe-summary-card" style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
                <div class="muted">Accepted</div>
                <div><strong><?= count($bucketed['accepted']) ?></strong></div>
            </div>
            <div class="stripe-summary-card" style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
                <div class="muted">Rejected</div>
                <div><strong><?= count($bucketed['rejected']) ?></strong></div>
            </div>
            <div class="stripe-summary-card" style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
                <div class="muted">Skipped</div>
                <div><strong><?= count($bucketed['skipped']) ?></strong></div>
            </div>
        </div>

        <?php if ($bucketed['rejected']): ?>
            <div class="helper" style="margin-bottom:10px;">
                Please verify the rejected records once again before final confirmation.
                These donors will be marked failed and their plan will be suspended.
            </div>

            <div class="table-wrap" style="margin-bottom:16px;">
                <table>
                    <thead>
                        <tr>
                            <th>Donor</th>
                            <th>Phone</th>
                            <th>Agreed Amount</th>
                            <th>Mode</th>
                            <th>Effect</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bucketed['rejected'] as $row): ?>
                            <tr>
                                <td><?= e((string)$row['name']) ?></td>
                                <td><?= e((string)$row['phone']) ?></td>
                                <td><?= money((float)$row['amount']) ?></td>
                                <td><?= e((string)$row['payment_mode']) ?></td>
                                <td>Mark failed + suspend plan</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="helper" style="margin-bottom:16px;">
                No rejected records in this submission.
            </div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="month" value="<?= e($month) ?>">
                <?php foreach ($bucketed['decisions'] as $personId => $decision): ?>
                    <input type="hidden" name="decision[<?= (int)$personId ?>]" value="<?= e($decision) ?>">
                <?php endforeach; ?>
                <button class="btn btn-primary" type="submit" name="action" value="confirm">Confirm and Post Reconciliation</button>
            </form>

            <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="month" value="<?= e($month) ?>">
                <?php foreach ($bucketed['decisions'] as $personId => $decision): ?>
                    <input type="hidden" name="decision[<?= (int)$personId ?>]" value="<?= e($decision) ?>">
                <?php endforeach; ?>
                <button class="btn soft" type="submit" name="action" value="back">Go Back and Edit</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <form method="post" class="stack" id="stripeReconciliationForm">
        <?= csrfField() ?>

        <div class="inline-grid-2">
            <div>
                <label>Month</label>
                <input type="month" name="month" value="<?= e($month) ?>">
            </div>
            <div class="muted stripe-align-end">
                Use bulk actions first, then adjust donors one by one.
            </div>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0;">
            <div class="stripe-bulk-actions" style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn soft" onclick="setAllDecision('accept')">Accept All</button>
                <button type="button" class="btn soft" onclick="setAllDecision('reject')">Reject All</button>
                <button type="button" class="btn soft" onclick="setAllDecision('skip')">Clear All</button>
            </div>

            <div class="helper" id="stripeCounters">
                Accepted: <strong id="countAccept"><?= count($bucketed['accepted']) ?></strong>
                &nbsp; | &nbsp;
                Rejected: <strong id="countReject"><?= count($bucketed['rejected']) ?></strong>
                &nbsp; | &nbsp;
                Skipped: <strong id="countSkip"><?= count($bucketed['skipped']) ?></strong>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="min-width:220px;">Decision</th>
                        <th>Donor</th>
                        <th>Phone</th>
                        <th>Agreed Amount</th>
                        <th>Mode</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $personId = (int)$row['person_id'];
                        $decision = $bucketed['decisions'][$personId] ?? 'accept';
                        $rowClass = $decision === 'accept'
                            ? 'row-accepted'
                            : ($decision === 'reject' ? 'row-rejected' : 'row-skipped');
                        ?>
                        <tr class="<?= e($rowClass) ?>" data-person-id="<?= $personId ?>">
                            <td class="stripe-decision-cell" style="white-space:nowrap;">
                                <label style="display:inline-flex;align-items:center;gap:5px;margin-right:10px;">
                                    <input
                                        type="radio"
                                        name="decision[<?= $personId ?>]"
                                        value="accept"
                                        <?= $decision === 'accept' ? 'checked' : '' ?>
                                        onchange="updateDecisionState(this)"
                                    >
                                    Accept
                                </label>

                                <label style="display:inline-flex;align-items:center;gap:5px;margin-right:10px;">
                                    <input
                                        type="radio"
                                        name="decision[<?= $personId ?>]"
                                        value="reject"
                                        <?= $decision === 'reject' ? 'checked' : '' ?>
                                        onchange="updateDecisionState(this)"
                                    >
                                    Reject
                                </label>

                                <label style="display:inline-flex;align-items:center;gap:5px;">
                                    <input
                                        type="radio"
                                        name="decision[<?= $personId ?>]"
                                        value="skip"
                                        <?= $decision === 'skip' ? 'checked' : '' ?>
                                        onchange="updateDecisionState(this)"
                                    >
                                    Skip
                                </label>
                            </td>
                            <td><?= e((string)$row['name']) ?></td>
                            <td><?= e((string)$row['phone']) ?></td>
                            <td><?= money((float)$row['amount']) ?></td>
                            <td><?= e((string)$row['payment_mode']) ?></td>
                            <td class="decision-label">
                                <?php if ($decision === 'accept'): ?>
                                    <span>Accepted</span>
                                <?php elseif ($decision === 'reject'): ?>
                                    <span>Rejected</span>
                                <?php else: ?>
                                    <span>Skipped</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="6" class="muted">No active Stripe donors found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($rows): ?>
            <div class="stripe-sticky-actions" style="position:sticky;bottom:0;background:#fff;border-top:1px solid #e5e7eb;padding:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                <div class="helper">
                    Review selections carefully. Rejected donors will have their plan suspended after confirmation.
                </div>
                <button class="btn btn-primary" type="submit" name="action" value="preview">Review Summary</button>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
function setAllDecision(value) {
    document.querySelectorAll('input[type="radio"][name^="decision["][value="' + value + '"]').forEach(function (el) {
        el.checked = true;
    });
    refreshDecisionRows();
}

function updateDecisionState(el) {
    var row = el.closest('tr');
    if (!row) return;

    row.classList.remove('row-accepted', 'row-rejected', 'row-skipped');

    var labelCell = row.querySelector('.decision-label');
    if (el.value === 'accept') {
        row.classList.add('row-accepted');
        if (labelCell) labelCell.textContent = 'Accepted';
    } else if (el.value === 'reject') {
        row.classList.add('row-rejected');
        if (labelCell) labelCell.textContent = 'Rejected';
    } else {
        row.classList.add('row-skipped');
        if (labelCell) labelCell.textContent = 'Skipped';
    }

    updateCounters();
}

function refreshDecisionRows() {
    document.querySelectorAll('tr[data-person-id]').forEach(function (row) {
        var checked = row.querySelector('input[type="radio"]:checked');
        if (checked) {
            updateDecisionState(checked);
        }
    });
}

function updateCounters() {
    var accept = document.querySelectorAll('input[type="radio"][name^="decision["][value="accept"]:checked').length;
    var reject = document.querySelectorAll('input[type="radio"][name^="decision["][value="reject"]:checked').length;
    var skip = document.querySelectorAll('input[type="radio"][name^="decision["][value="skip"]:checked').length;

    var acceptEl = document.getElementById('countAccept');
    var rejectEl = document.getElementById('countReject');
    var skipEl = document.getElementById('countSkip');

    if (acceptEl) acceptEl.textContent = accept;
    if (rejectEl) rejectEl.textContent = reject;
    if (skipEl) skipEl.textContent = skip;
}

document.addEventListener('DOMContentLoaded', function () {
    refreshDecisionRows();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>