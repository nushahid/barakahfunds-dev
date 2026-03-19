<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

$uid = getLoggedInUserId();
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$editing = $id > 0;
$errors = [];
$nameLocked = false;
$originalPersonName = '';

$societies = getSocieties($pdo);
$defaultSocietyId = 0;
foreach ($societies as $soc) {
    if ((int)($soc['is_default'] ?? 0) === 1) {
        $defaultSocietyId = (int)$soc['ID'];
    }
}
if ($defaultSocietyId === 0 && $societies) {
    $defaultSocietyId = (int)$societies[0]['ID'];
}

function ensureMonthlyPlanBankMode(PDO $pdo): void {
    static $checked = false;
    if ($checked || !tableExists($pdo, 'member_monthly_plans')) return;
    $checked = true;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM member_monthly_plans LIKE 'payment_mode'")->fetch();
        $type = (string)($col['Type'] ?? '');
        if ($type !== '' && stripos($type, 'bank_manual') === false) {
            $pdo->exec("ALTER TABLE member_monthly_plans MODIFY payment_mode ENUM('stripe_auto','cash_manual','bank_manual') NOT NULL DEFAULT 'cash_manual'");
        }
    } catch (Throwable $e) {
        // keep current schema if the database user cannot alter tables
    }
}
ensureMonthlyPlanBankMode($pdo);

function personHasTransactions(PDO $pdo, int $personId): bool
{
    if ($personId <= 0 || !tableExists($pdo, 'operator_ledger')) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM operator_ledger
            WHERE person_id = ?
              AND is_removed = 0
              AND amount >= 0
            LIMIT 1
        ");
        $stmt->execute([$personId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$form = [
    'name' => '',
    'phone' => '',
    'city' => '',
    'notes' => '',
    'life_membership' => 0,
    'life_membership_amount' => '0.00',
    'life_membership_start_date' => date('Y-m-d'),
    'monthly_subscription' => 0,
    'monthly_amount' => '20.00',
    'payment_mode' => 'cash_manual',
    'monthly_start_date' => date('Y-m-01'),
    'death_insurance_enabled' => 0,
    'death_insurance_society_id' => $defaultSocietyId,
    'death_membership_no' => '',
    'death_start_date' => date('Y-m-d'),
    'home_country_reference_name' => '',
    'home_town_address' => '',
    'home_town_phone' => '',
    'italy_reference_name' => '',
    'italy_reference_address' => '',
    'italy_reference_phone' => '',
    'religion_sect' => '',
    'organ_donor_status' => 'unknown',
];

if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM people WHERE ID = ? LIMIT 1');
    $stmt->execute([$id]);
    $person = $stmt->fetch();
    if (!$person) {
        setFlash('error', 'Donor not found.');
        header('Location: donors.php');
        exit;
    }

    $originalPersonName = (string)($person['name'] ?? '');

    foreach ($form as $k => $v) {
        if (isset($person[$k])) {
            $form[$k] = $person[$k];
        }
    }

    $plan = getPersonCurrentPlan($pdo, $id);
    if ($plan) {
        $form['monthly_subscription'] = (int)$plan['active'];
        $form['monthly_amount'] = $plan['amount'];
        $form['payment_mode'] = $plan['payment_mode'];
        $form['monthly_start_date'] = $plan['start_date'];
    }

    $nameLocked = personHasTransactions($pdo, $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();

    $saveAction = (string)($_POST['save_action'] ?? 'save_only');
    if (!in_array($saveAction, ['save_only', 'save_and_add_donation'], true)) {
        $saveAction = 'save_only';
    }

    $existingOrganDonorStatus = (string)$form['organ_donor_status'];

    foreach (array_keys($form) as $key) {
        if (in_array($key, ['life_membership', 'monthly_subscription', 'death_insurance_enabled'], true)) {
            $form[$key] = isset($_POST[$key]) ? 1 : 0;
        } else {
            $form[$key] = trim((string)($_POST[$key] ?? ''));
        }
    }

    if (!isset($_POST['organ_donor_status'])) {
        $form['organ_donor_status'] = $existingOrganDonorStatus !== '' ? $existingOrganDonorStatus : 'unknown';
    }

    if ($editing) {
        $stmt = $pdo->prepare('SELECT name FROM people WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $originalPersonName = (string)($row['name'] ?? '');
        $nameLocked = personHasTransactions($pdo, $id);

        if ($nameLocked) {
            $form['name'] = $originalPersonName;
        }
    }

    $allowedPaymentModes = ['cash_manual', 'bank_manual', 'stripe_auto'];
    if (!in_array($form['payment_mode'], $allowedPaymentModes, true)) {
        $form['payment_mode'] = 'cash_manual';
    }

    if ($form['name'] === '') {
        $errors[] = 'Name is required.';
    }
    if ($form['phone'] === '') {
        $errors[] = 'Phone is required.';
    }
    if ($form['monthly_subscription'] && (float)$form['monthly_amount'] <= 0) {
        $errors[] = 'Monthly amount must be greater than zero.';
    }

    if (!$errors) {
        $params = [
            $form['name'],
            $form['phone'],
            $form['city'],
            $form['notes'],
            (int)$form['life_membership'],
            (float)$form['life_membership_amount'],
            $form['life_membership_start_date'] ?: null,
            (int)$form['monthly_subscription'],
            (int)$form['death_insurance_enabled'],
            $form['death_insurance_enabled'] ? (int)$form['death_insurance_society_id'] : null,
            $form['death_membership_no'],
            $form['death_start_date'] ?: null,
            $form['home_country_reference_name'],
            $form['home_town_address'],
            $form['home_town_phone'],
            $form['italy_reference_name'],
            $form['italy_reference_address'],
            $form['italy_reference_phone'],
            $form['religion_sect'],
            $form['organ_donor_status']
        ];

        if ($editing) {
            $stmt = $pdo->prepare('
                UPDATE people
                SET
                    name=?,
                    phone=?,
                    city=?,
                    notes=?,
                    life_membership=?,
                    life_membership_amount=?,
                    life_membership_start_date=?,
                    monthly_subscription=?,
                    death_insurance_enabled=?,
                    death_insurance_society_id=?,
                    death_membership_no=?,
                    death_start_date=?,
                    home_country_reference_name=?,
                    home_town_address=?,
                    home_town_phone=?,
                    italy_reference_name=?,
                    italy_reference_address=?,
                    italy_reference_phone=?,
                    religion_sect=?,
                    organ_donor_status=?,
                    updated_by=?,
                    updated_at=NOW()
                WHERE ID=?
            ');
            $stmt->execute(array_merge($params, [$uid, $id]));
            $personId = $id;

            systemLog($pdo, $uid, 'donor', 'update', 'Updated donor ' . $form['name'], $personId);
            personLog($pdo, $uid, $personId, 'update', 'Donor profile updated');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO people (
                    name, phone, city, notes,
                    life_membership, life_membership_amount, life_membership_start_date,
                    monthly_subscription,
                    death_insurance_enabled, death_insurance_society_id, death_membership_no, death_start_date,
                    home_country_reference_name, home_town_address, home_town_phone,
                    italy_reference_name, italy_reference_address, italy_reference_phone,
                    religion_sect, organ_donor_status,
                    created_by, updated_by, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, NOW(), NOW()
                )
            ');
            $stmt->execute(array_merge($params, [$uid, $uid]));
            $personId = (int)$pdo->lastInsertId();

            systemLog($pdo, $uid, 'donor', 'create', 'Created donor ' . $form['name'], $personId);
            personLog($pdo, $uid, $personId, 'create', 'Donor created');
        }

        if (tableExists($pdo, 'member_monthly_plans')) {
            $plan = getPersonCurrentPlan($pdo, $personId);

            if ((int)$form['monthly_subscription'] === 1) {
                if ($plan) {
                    $stmt = $pdo->prepare('
                        UPDATE member_monthly_plans
                        SET amount=?, payment_mode=?, assigned_operator_id=?, active=1, start_date=?, stop_date=NULL
                        WHERE ID=?
                    ');
                    $stmt->execute([
                        (float)$form['monthly_amount'],
                        $form['payment_mode'],
                        $uid,
                        $form['monthly_start_date'] ?: date('Y-m-01'),
                        (int)$plan['ID']
                    ]);
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO member_monthly_plans
                        (member_id, amount, payment_mode, assigned_operator_id, active, start_date, notes, created_at)
                        VALUES (?, ?, ?, ?, 1, ?, ?, NOW())
                    ');
                    $stmt->execute([
                        $personId,
                        (float)$form['monthly_amount'],
                        $form['payment_mode'],
                        $uid,
                        $form['monthly_start_date'] ?: date('Y-m-01'),
                        'Managed from donor form'
                    ]);
                }
            } elseif ($plan) {
                $pdo->prepare('UPDATE member_monthly_plans SET active=0, stop_date=CURDATE() WHERE ID=?')
                    ->execute([(int)$plan['ID']]);
            }
        }

        setFlash('success', $editing ? 'Donor updated successfully.' : 'Donor added successfully.');

        if ($saveAction === 'save_and_add_donation') {
            header('Location: transaction_page.php?person_id=' . $personId . '#category_start_v5');
            exit;
        }

        header('Location: person_profile.php?id=' . $personId);
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="title"><?= $editing ? 'Edit Donor' : 'Add New Donor' ?></h1>

<div class="card stack donor-form-card-v5">
    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $er): ?>
                <div><?= e($er) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="stack compact donor-form-v5" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="inline-grid-2 donor-main-grid-v5">
            <div>
                <label>Name</label>
                <input
                    type="text"
                    name="name"
                    value="<?= e((string)$form['name']) ?>"
                    <?= $nameLocked ? 'readonly' : '' ?>
                    required
                >
                <?php if ($nameLocked): ?>
                    <div class="muted" style="margin-top:6px;">
                        Name cannot be changed because visible donor transactions exist.
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <label>Phone</label>
                <input type="text" name="phone" value="<?= e((string)$form['phone']) ?>" required>
            </div>
        </div>

        <div class="inline-grid-2 donor-main-grid-v5">
            <div class="city-autocomplete-wrap">
                <label for="city_autocomplete">City</label>
                <input
                    type="text"
                    id="city_autocomplete"
                    name="city"
                    value="<?= e((string)$form['city']) ?>"
                    autocomplete="off"
                    placeholder="Start typing city name"
                >
                <div id="city_autocomplete_list" class="city-autocomplete-list" hidden></div>
                <div id="city_autocomplete_meta" class="city-autocomplete-meta"></div>
            </div>
            <div>
                <label>Notes</label>
                <input type="text" name="notes" value="<?= e((string)$form['notes']) ?>">
            </div>
        </div>

        <div class="switch-row">
            <div>
                <strong>Life Membership</strong>
                <div class="muted">Turn on only if the donor is a life member.</div>
            </div>
            <label>
                <input
                    id="life_membership"
                    data-toggle-target="life_fields"
                    type="checkbox"
                    name="life_membership"
                    value="1"
                    <?= !empty($form['life_membership']) ? 'checked' : '' ?>
                > Active
            </label>
        </div>

        <div id="life_fields" class="inline-grid-2 donor-main-grid-v5">
            <div>
                <label class="label-with-info">
                    Life Membership Amount
                    <span class="info-icon" onclick="toggleInfo(this)">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="white" stroke-width="2"/>
                            <line x1="12" y1="10" x2="12" y2="16" stroke="white" stroke-width="2"/>
                            <circle cx="12" cy="7" r="1.5" fill="white"/>
                        </svg>
                    </span>
                    <span class="info-tooltip">
                        This amount is only stored for record purposes. It does not create a payment or automatic collection.
                    </span>
                </label>

                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="life_membership_amount"
                    value="<?= e((string)$form['life_membership_amount']) ?>"
                >
            </div>
            <div>
                <label>Start Date</label>
                <input type="date" name="life_membership_start_date" value="<?= e((string)$form['life_membership_start_date']) ?>">
            </div>
        </div>

        <div class="switch-row">
            <div>
                <strong>Monthly Subscription</strong>
                <div class="muted">Flexible monthly amount with card-style payment selection.</div>
            </div>
            <label>
                <input
                    id="monthly_subscription"
                    data-toggle-target="monthly_fields"
                    type="checkbox"
                    name="monthly_subscription"
                    value="1"
                    <?= !empty($form['monthly_subscription']) ? 'checked' : '' ?>
                > Active
            </label>
        </div>

        <div id="monthly_fields" class="stack compact">
            <div>
                <label class="label-with-info">
                    Monthly Amount
                    <span class="info-icon" onclick="toggleInfo(this)">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="white" stroke-width="2"/>
                            <line x1="12" y1="10" x2="12" y2="16" stroke="white" stroke-width="2"/>
                            <circle cx="12" cy="7" r="1.5" fill="white"/>
                        </svg>
                    </span>
                    <span class="info-tooltip">
                        This is the expected monthly contribution amount for this member.
                    </span>
                </label>

                <input
                    type="number"
                    id="monthly_amount"
                    step="0.01"
                    min="0"
                    name="monthly_amount"
                    value="<?= e((string)$form['monthly_amount']) ?>"
                >

                <div class="donor-amount-suggestions-v5">
                    <button type="button" class="btn" onclick="document.getElementById('monthly_amount').value='20.00'">20</button>
                    <button type="button" class="btn" onclick="document.getElementById('monthly_amount').value='25.00'">25</button>
                    <button type="button" class="btn" onclick="document.getElementById('monthly_amount').value='50.00'">50</button>
                    <button type="button" class="btn" onclick="document.getElementById('monthly_amount').value='100.00'">100</button>
                </div>
            </div>

            <div class="stack compact">
                <div>
                    <label>Payment Mode</label>
                    <div class="donor-payment-row-v5">
                        <label class="donor-payment-option-v5">
                            <input type="radio" name="payment_mode" value="cash_manual" <?= $form['payment_mode'] === 'cash_manual' ? 'checked' : '' ?>>
                            <span class="donor-payment-pill-v5">
                                <span class="donor-payment-icon-v5">💵</span>
                                <span>Cash</span>
                            </span>
                        </label>

                        <label class="donor-payment-option-v5">
                            <input type="radio" name="payment_mode" value="bank_manual" <?= $form['payment_mode'] === 'bank_manual' ? 'checked' : '' ?>>
                            <span class="donor-payment-pill-v5">
                                <span class="donor-payment-icon-v5">🏦</span>
                                <span>Bank</span>
                            </span>
                        </label>

                        <label class="donor-payment-option-v5">
                            <input type="radio" name="payment_mode" value="stripe_auto" <?= $form['payment_mode'] === 'stripe_auto' ? 'checked' : '' ?>>
                            <span class="donor-payment-pill-v5">
                                <span class="donor-payment-icon-v5">💳</span>
                                <span>Stripe</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div>
                    <label>Start Month</label>
                    <input type="date" name="monthly_start_date" value="<?= e((string)$form['monthly_start_date']) ?>">
                </div>
            </div>
        </div>

        <div class="switch-row">
            <div>
                <strong>Death Insurance</strong>
                <div class="muted">When turned on, emergency contact fields will appear.</div>
            </div>
            <label>
                <input
                    id="death_insurance_enabled"
                    data-toggle-target="death_fields"
                    type="checkbox"
                    name="death_insurance_enabled"
                    value="1"
                    <?= !empty($form['death_insurance_enabled']) ? 'checked' : '' ?>
                > Active
            </label>
        </div>

        <div id="death_fields" class="stack compact">
            <div class="inline-grid-2 donor-main-grid-v5">
                <div>
                    <label>Society</label>
                    <select name="death_insurance_society_id">
                        <?php foreach ($societies as $soc): ?>
                            <option value="<?= (int)$soc['ID'] ?>" <?= (int)$form['death_insurance_society_id'] === (int)$soc['ID'] ? 'selected' : '' ?>>
                                <?= e((string)$soc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Membership No.</label>
                    <input type="text" name="death_membership_no" value="<?= e((string)$form['death_membership_no']) ?>">
                </div>
            </div>

            <div class="inline-grid-2 donor-main-grid-v5">
                <div>
                    <label>Start Date</label>
                    <input type="date" name="death_start_date" value="<?= e((string)$form['death_start_date']) ?>">
                </div>
                <div>
                    <label>Religion / Sect</label>
                    <input type="text" name="religion_sect" value="<?= e((string)$form['religion_sect']) ?>">
                </div>
            </div>

            <div class="inline-grid-2 donor-main-grid-v5">
                <div>
                    <label>Home Country Reference Person</label>
                    <input type="text" name="home_country_reference_name" value="<?= e((string)$form['home_country_reference_name']) ?>">
                </div>
                <div>
                    <label>Home Town Phone</label>
                    <input type="text" name="home_town_phone" value="<?= e((string)$form['home_town_phone']) ?>">
                </div>
            </div>

            <div>
                <label>Home Town Address</label>
                <input type="text" name="home_town_address" value="<?= e((string)$form['home_town_address']) ?>">
            </div>

            <div class="inline-grid-2 donor-main-grid-v5">
                <div>
                    <label>Italy Reference Person</label>
                    <input type="text" name="italy_reference_name" value="<?= e((string)$form['italy_reference_name']) ?>">
                </div>
                <div>
                    <label>Italy Reference Phone</label>
                    <input type="text" name="italy_reference_phone" value="<?= e((string)$form['italy_reference_phone']) ?>">
                </div>
            </div>

            <div>
                <label>Italy Reference Address</label>
                <input type="text" name="italy_reference_address" value="<?= e((string)$form['italy_reference_address']) ?>">
            </div>
        </div>

        <div class="toolbar donor-toolbar-v5">
            <a class="btn" href="donors.php">Back</a>

            <button class="btn btn-primary" type="submit" name="save_action" value="save_only">
                <?= $editing ? 'Update Donor' : 'Save Donor' ?>
            </button>

            <button class="btn btn-primary" type="submit" name="save_action" value="save_and_add_donation">
                <?= $editing ? 'Update Donor & Add Donation' : 'Save Donor & Add Donation' ?>
            </button>
        </div>
    </form>
</div>

<script>
function toggleInfo(el){
    const tooltip = el.parentElement.querySelector('.info-tooltip');

    document.querySelectorAll('.info-tooltip').forEach(function(t){
        if(t !== tooltip){
            t.style.display = 'none';
        }
    });

    tooltip.style.display = (tooltip.style.display === 'block') ? 'none' : 'block';
}

document.addEventListener('click', function(e){
    if(!e.target.closest('.label-with-info')){
        document.querySelectorAll('.info-tooltip').forEach(function(t){
            t.style.display = 'none';
        });
    }
});
</script>

<script src="assets/city-autocomplete.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>