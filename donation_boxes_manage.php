<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, ['operator', 'accountant', 'admin']);

$uid = (int)($_SESSION['user_id'] ?? 0);
$errors = [];
$success = null;

function dbm_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $boxNumber = trim((string)($_POST['box_number'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($boxNumber === '') {
            $errors[] = 'Box number is required.';
        }

        $check = $pdo->prepare('SELECT ID FROM donation_boxes WHERE box_number = ? LIMIT 1');
        $check->execute([$boxNumber]);
        if ($check->fetchColumn()) {
            $errors[] = 'This box number already exists.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO donation_boxes (box_number, title, qr_token, active, notes, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $boxNumber,
                $title !== '' ? $title : null,
                bin2hex(random_bytes(8)),
                $active,
                $notes !== '' ? $notes : null,
                $uid ?: null,
            ]);
            $success = 'Donation box created successfully.';
        }
    }

    if ($action === 'toggle') {
        $boxId = (int)($_POST['box_id'] ?? 0);
        $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;

        if ($boxId > 0) {
            $stmt = $pdo->prepare('UPDATE donation_boxes SET active = ? WHERE ID = ?');
            $stmt->execute([$active, $boxId]);
            $success = 'Donation box status updated.';
        }
    }
}

$boxes = $pdo->query(
    'SELECT b.ID, b.box_number, b.title, b.active, b.notes, b.created_at,
            COALESCE((SELECT SUM(ac.amount) FROM anonymous_collections ac WHERE ac.box_id = b.ID), 0) AS total_collected
     FROM donation_boxes b
     ORDER BY b.box_number ASC, b.ID DESC'
)->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="anonymous_collection.css">

<div class="anonymous-page">
    <h1 class="page-title">Donation Boxes</h1>
    <p class="page-subtitle">Create donation boxes here. The anonymous collection page will automatically show active boxes in its dropdown.</p>

    <div class="anonymous-grid">
        <div class="ac-card">
            <h2>Create Donation Box</h2>

            <?php if ($success): ?>
                <div class="ac-alert success"><?php echo dbm_e($success); ?></div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="ac-alert error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo dbm_e($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create">

                <div class="ac-form-grid">
                    <div class="ac-field">
                        <label for="box_number">Box Number</label>
                        <input type="text" name="box_number" id="box_number" required placeholder="Example: DB-001">
                    </div>

                    <div class="ac-field">
                        <label for="title">Title</label>
                        <input type="text" name="title" id="title" placeholder="Example: Main Hall Box">
                    </div>

                    <div class="ac-field-full">
                        <label for="notes">Notes</label>
                        <textarea name="notes" id="notes" placeholder="Optional notes"></textarea>
                    </div>

                    <div class="ac-field-full">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="active" value="1" checked style="width:auto; min-height:auto;">
                            Active box
                        </label>
                    </div>
                </div>

                <div class="ac-btn-row">
                    <button type="submit" class="ac-btn">Create Box</button>
                    <a href="anonymous_collection_new.php" class="ac-btn-secondary">Back to Collection Page</a>
                </div>
            </form>
        </div>

        <div class="ac-card">
            <h2>How It Works</h2>
            <div class="ac-muted">Only active donation boxes appear in the dropdown on the anonymous collection page.</div>
            <div style="height:14px"></div>
            <div class="ac-muted">Collection types:</div>
            <div class="ac-btn-row">
                <span class="ac-badge">anonymous</span>
                <span class="ac-badge">donation box</span>
                <span class="ac-badge">jumma</span>
                <span class="ac-badge">misc</span>
            </div>
            <div style="height:14px"></div>
            <div class="ac-inline-note">Payment method on the collection entry page is fixed to Cash only.</div>
        </div>
    </div>

    <div class="ac-card" style="margin-top:20px;">
        <h2>Donation Box List</h2>
        <div class="ac-table-wrap">
            <table class="ac-table">
                <thead>
                    <tr>
                        <th>Box Number</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Total Collected</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$boxes): ?>
                        <tr>
                            <td colspan="6" class="ac-muted">No donation boxes found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($boxes as $box): ?>
                            <tr>
                                <td><?php echo dbm_e((string)$box['box_number']); ?></td>
                                <td>
                                    <strong><?php echo dbm_e((string)($box['title'] ?? '')); ?></strong>
                                    <?php if (!empty($box['notes'])): ?>
                                        <div class="ac-muted"><?php echo dbm_e((string)$box['notes']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ac-badge"><?php echo (int)$box['active'] === 1 ? 'active' : 'inactive'; ?></span>
                                </td>
                                <td>€<?php echo number_format((float)$box['total_collected'], 2); ?></td>
                                <td><?php echo dbm_e(date('d/m/Y H:i', strtotime((string)$box['created_at']))); ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="box_id" value="<?php echo (int)$box['ID']; ?>">
                                        <input type="hidden" name="active" value="<?php echo (int)$box['active'] === 1 ? 0 : 1; ?>">
                                        <button type="submit" class="ac-btn-secondary">
                                            <?php echo (int)$box['active'] === 1 ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
