<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireLogin($pdo);

$uid = getLoggedInUserId();
$role = currentRole($pdo);
$errors = [];

$canCreate = in_array($role, ['operator', 'accountant', 'admin'], true);
$canManageStatus = in_array($role, ['accountant', 'admin', 'operator'], true);
$canView = in_array($role, ['operator', 'accountant', 'admin'], true);
$canEdit = in_array($role, ['operator', 'accountant', 'admin'], true);

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editBox = null;

if ($canView && $editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM donation_boxes WHERE ID = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editBox = $stmt->fetch();
    if (!$editBox) {
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canView) {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_box' && $canCreate) {
        $boxNumber = trim((string)($_POST['box_number'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($boxNumber === '') {
            $errors[] = 'Box number is required.';
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT ID FROM donation_boxes WHERE box_number = ? LIMIT 1');
            $check->execute([$boxNumber]);
            if ($check->fetch()) {
                $errors[] = 'This box number already exists.';
            }
        }

        if (!$errors) {
            $qrToken = randomToken(18);

            $stmt = $pdo->prepare('
                INSERT INTO donation_boxes
                    (box_number, title, qr_token, active, notes, created_by, created_at)
                VALUES
                    (?, ?, ?, 1, ?, ?, NOW())
            ');

            $stmt->execute([
                $boxNumber,
                $title !== '' ? $title : null,
                $qrToken,
                $notes !== '' ? $notes : null,
                $uid ?: null,
            ]);

            setFlash('success', 'Donation box created successfully.');
            header('Location: donation_boxes.php');
            exit;
        }
    }

    if ($action === 'update_box' && $canEdit) {
        $boxId = (int)($_POST['box_id'] ?? 0);
        $boxNumber = trim((string)($_POST['box_number'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($boxId <= 0) {
            $errors[] = 'Invalid donation box selected.';
        }

        if ($boxNumber === '') {
            $errors[] = 'Box number is required.';
        }

        if (!$errors) {
            $check = $pdo->prepare('
                SELECT ID
                FROM donation_boxes
                WHERE box_number = ?
                  AND ID <> ?
                LIMIT 1
            ');
            $check->execute([$boxNumber, $boxId]);
            if ($check->fetch()) {
                $errors[] = 'This box number already exists.';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare('
                UPDATE donation_boxes
                SET box_number = ?, title = ?, notes = ?
                WHERE ID = ?
                LIMIT 1
            ');
            $stmt->execute([
                $boxNumber,
                $title !== '' ? $title : null,
                $notes !== '' ? $notes : null,
                $boxId
            ]);

            setFlash('success', 'Donation box updated successfully.');
            header('Location: donation_boxes.php');
            exit;
        } else {
            $stmt = $pdo->prepare('SELECT * FROM donation_boxes WHERE ID = ? LIMIT 1');
            $stmt->execute([$boxId]);
            $editBox = $stmt->fetch();
            $editId = $boxId;
        }
    }

    if ($action === 'toggle_box' && $canManageStatus) {
        $boxId = (int)($_POST['box_id'] ?? 0);

        if ($boxId > 0) {
            $stmt = $pdo->prepare('
                UPDATE donation_boxes
                SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END
                WHERE ID = ?
                LIMIT 1
            ');
            $stmt->execute([$boxId]);

            setFlash('success', 'Donation box status updated.');
            header('Location: donation_boxes.php');
            exit;
        }
    }
}

$boxes = [];
if ($canView) {
    $stmt = $pdo->prepare('
        SELECT
            b.*,
            COALESCE((SELECT SUM(ac.amount) FROM anonymous_collections ac WHERE ac.box_id = b.ID), 0) AS total_collected,
            COALESCE((SELECT COUNT(*) FROM anonymous_collections ac WHERE ac.box_id = b.ID), 0) AS entries_count
        FROM donation_boxes b
        ORDER BY b.active DESC, b.box_number ASC, b.ID ASC
    ');
    $stmt->execute();
    $boxes = $stmt->fetchAll();
}

$formAction = $editBox ? 'update_box' : 'create_box';
$formTitle = $editBox ? 'Update Donation Box' : 'Create Donation Box';
$formButton = $editBox ? 'Update Donation Box' : 'Create Donation Box';

$boxNumberValue = $_POST['box_number'] ?? ($editBox['box_number'] ?? '');
$titleValue = $_POST['title'] ?? ($editBox['title'] ?? '');
$notesValue = $_POST['notes'] ?? ($editBox['notes'] ?? '');

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="title">Donation Boxes</h1>
        <p class="muted anon-subtitle">
            Create and manage donation boxes used in anonymous collection entry.
        </p>
    </div>
    <a class="btn" href="anonymous_collections.php">Back to Anonymous Collections</a>
</div>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$canView): ?>
    <div class="card operator-info-card">
        <h2>Donation Boxes</h2>
        <p class="muted">You do not have permission to access this page.</p>
    </div>
<?php else: ?>

<div class="donation-box-layout">

    <?php if ($canCreate || $canEdit): ?>
    <section class="card stack">
        <div class="section-head">
            <h2><?= e($formTitle) ?></h2>
            <?php if ($editBox): ?>
                <a class="btn btn-small" href="donation_boxes.php">Cancel</a>
            <?php endif; ?>
        </div>

        <form method="post" class="stack">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= e($formAction) ?>">

            <?php if ($editBox): ?>
                <input type="hidden" name="box_id" value="<?= (int)$editBox['ID'] ?>">
            <?php endif; ?>

            <div class="two-col-grid">
                <div>
                    <label class="field-label" for="box_number">Box Number</label>
                    <input
                        type="text"
                        name="box_number"
                        id="box_number"
                        required
                        value="<?= e((string)$boxNumberValue) ?>"
                    >
                </div>

                <div>
                    <label class="field-label" for="title">Title</label>
                    <input
                        type="text"
                        name="title"
                        id="title"
                        placeholder="Optional title"
                        value="<?= e((string)$titleValue) ?>"
                    >
                </div>
            </div>

            <div>
                <label class="field-label" for="notes">Notes</label>
                <textarea
                    name="notes"
                    id="notes"
                    rows="4"
                    placeholder="Optional notes"
                ><?= e((string)$notesValue) ?></textarea>
            </div>

            <button class="btn btn-primary" type="submit"><?= e($formButton) ?></button>
        </form>
    </section>
    <?php endif; ?>

    <section class="card">
        <div class="section-head">
            <h2>All Donation Boxes</h2>
            <span class="muted"><?= count($boxes) ?> total</span>
        </div>

        <div class="table-wrap donation-box-table-wrap">
            <table class="responsive-table">
                <thead>
                    <tr>
                        <th>Box</th>
                        <th>QR Code</th>
                        <th>Status</th>
                        <th>Entries</th>
                        <th>Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($boxes as $box): ?>
<?php
    $boxId = (int)$box['ID'];
    $isActive = (int)$box['active'] === 1;
    $qrToken = trim((string)($box['qr_token'] ?? ''));

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $scheme . '://' . $host . '/';

    $scanUrl = $baseUrl . 'donation_box_quick_entry.php?box=' . urlencode($qrToken);
    $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($scanUrl);
?>




                        <tr>
                            <td data-label="Box">
                                <strong><?= e((string)$box['box_number']) ?></strong>

                                <?php if (trim((string)($box['title'] ?? '')) !== ''): ?>
                                    <div class="muted"><?= e((string)$box['title']) ?></div>
                                <?php endif; ?>

                                <?php if (trim((string)($box['notes'] ?? '')) !== ''): ?>
                                    <div class="muted small-note"><?= e((string)$box['notes']) ?></div>
                                <?php endif; ?>
                            </td>

                            <td data-label="QR Code">
                                <?php if ($qrToken !== ''): ?>
                                    <div class="qr-box">
                                        <img
                                            src="<?= e($qrImageUrl) ?>"
                                            alt="QR for box <?= e((string)$box['box_number']) ?>"
                                            style="width:110px;height:110px;object-fit:contain;border:1px solid #ddd;padding:6px;background:#fff;border-radius:8px;"
                                        >
                                    </div>
                                <?php else: ?>
                                    <span class="muted">No QR</span>
                                <?php endif; ?>
                            </td>

                            <td data-label="Status">
                                <span class="status-badge <?= $isActive ? 'status-active' : 'status-inactive' ?>">
                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>

                            <td data-label="Entries"><?= (int)$box['entries_count'] ?></td>
                            <td data-label="Total"><?= money((float)$box['total_collected']) ?></td>

                            <td data-label="Action">
                                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                    <?php if ($canEdit): ?>
                                        <a
                                            class="btn btn-small btn-icon"
                                            href="donation_boxes.php?edit=<?= $boxId ?>"
                                            title="Edit donation box"
                                            aria-label="Edit donation box"
                                        >✏️</a>
                                    <?php endif; ?>

                                    <?php if ($canManageStatus): ?>
                                        <form method="post" class="inline-form">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_box">
                                            <input type="hidden" name="box_id" value="<?= $boxId ?>">
                                            <button class="btn btn-small" type="submit">
                                                <?= $isActive ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">No status access</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$boxes): ?>
                        <tr>
                            <td colspan="6" class="muted">No donation boxes created yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
