<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';

$search = trim((string)($_GET['q'] ?? ''));
$filter = trim((string)($_GET['filter'] ?? 'all'));
$allowedFilters = ['all', 'life', 'monthly', 'death'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$people = getPeople($pdo, $search, $filter);
$counts = getDonorCounts($pdo);
$resultCount = count($people);

$personTotals = [];
$personSponsoredTotals = [];

$personIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['ID'] ?? 0), $people)));

if ($personIds) {
    $placeholders = implode(',', array_fill(0, count($personIds), '?'));

    if (tableExists($pdo, 'operator_ledger')) {
        $stmt = $pdo->prepare("\n            SELECT person_id, SUM(amount) AS total_donations\n            FROM operator_ledger\n            WHERE person_id IN ($placeholders)\n              AND amount >= 0\n            GROUP BY person_id\n        ");
        $stmt->execute($personIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $personTotals[(int)$row['person_id']] = (float)($row['total_donations'] ?? 0);
        }
    }

    if (tableExists($pdo, 'expense')) {
        $hasDonationFlag = function_exists('columnExists') && columnExists($pdo, 'expense', 'donation');
        if ($hasDonationFlag) {
            $stmt = $pdo->prepare("\n                SELECT pid AS person_id, SUM(amount) AS sponsored_amount\n                FROM expense\n                WHERE pid IN ($placeholders)\n                  AND donation = 1\n                GROUP BY pid\n            ");
            $stmt->execute($personIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $personSponsoredTotals[(int)$row['person_id']] = (float)($row['sponsored_amount'] ?? 0);
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="title">Donors</h1>
<div class="donor-summary-grid" style="margin-bottom:18px;">
  <a class="card donor-summary-card donor-summary-link <?= $filter === 'all' ? 'is-active' : '' ?>" href="donors.php?filter=all<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
    <div class="muted">Total Donors</div>
    <div class="summary"><?= (int)$counts['total'] ?></div>
  </a>
  <a class="card donor-summary-card donor-summary-link <?= $filter === 'life' ? 'is-active' : '' ?>" href="donors.php?filter=life<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
    <div class="muted">Life Members</div>
    <div class="summary"><?= (int)$counts['life'] ?></div>
  </a>
  <a class="card donor-summary-card donor-summary-link <?= $filter === 'monthly' ? 'is-active' : '' ?>" href="donors.php?filter=monthly<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
    <div class="muted">Monthly Members</div>
    <div class="summary"><?= (int)$counts['monthly'] ?></div>
  </a>
  <a class="card donor-summary-card donor-summary-link <?= $filter === 'death' ? 'is-active' : '' ?>" href="donors.php?filter=death<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
    <div class="muted">Death Insurance</div>
    <div class="summary"><?= (int)$counts['death'] ?></div>
  </a>
</div>
<div class="card donor-list-shell">
  <form method="get" class="donor-search-form-v5">
  <input type="hidden" name="filter" value="<?= e($filter) ?>">

  <div class="donor-search-input-wrap-v5">
    <input
      type="text"
      name="q"
      value="<?= e($search) ?>"
      placeholder="Search by donor name, phone, city"
      class="donor-search-input-v5"
    >
    <?php if ($search !== ''): ?>
      <a
        href="donors.php?filter=<?= urlencode($filter) ?>"
        class="donor-search-clear-v5"
        aria-label="Clear search"
        title="Clear search"
      >×</a>
    <?php endif; ?>
  </div>

  <div class="donor-action-row-v5">
    <button class="btn donor-search-btn-v5" type="submit">Search</button>
    <a class="btn btn-primary donor-add-btn-v5" href="add_person.php">+ Add New Donor</a>
  </div>
</form>

  <div class="donor-results-bar-v5">
    <strong><?= (int)$resultCount ?></strong> result<?= $resultCount === 1 ? '' : 's' ?>
    <?php if ($filter !== 'all'): ?>
      <span class="muted">for <?= e(ucfirst($filter === 'death' ? 'death insurance' : $filter)) ?></span>
    <?php endif; ?>
    <?php if ($search !== ''): ?>
      <span class="muted">matching “<?= e($search) ?>”</span>
    <?php endif; ?>
  </div>

  <div class="donor-record-list">
    <?php foreach ($people as $person): ?>
      <?php
        $personId = (int)($person['ID'] ?? 0);
        $phoneRaw = trim((string)($person['phone'] ?? ''));
        $phoneDigits = preg_replace('/\D+/', '', $phoneRaw);
        $waDigits = $phoneDigits;
        if ($waDigits !== '' && substr($waDigits, 0, 2) === '39') {
            $waDigits = substr($waDigits, 2);
        }
        $totalDonations = (float)($personTotals[$personId] ?? 0);
        $sponsoredAmount = (float)($personSponsoredTotals[$personId] ?? 0);
      ?>
      <div class="donor-record-card-v5" onclick="window.location.href='person_profile.php?id=<?= $personId ?>'" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){window.location.href='person_profile.php?id=<?= $personId ?>'; event.preventDefault();}">
        <div class="donor-record-main-v5">
          <div class="donor-record-left-v5">
            <div class="donor-record-name-v5"><?= e((string)$person['name']) ?></div>
            <div class="donor-record-city-v5"><?= e((string)($person['city'] ?? '')) ?: '—' ?></div>
            <div class="donor-record-phone-mobile-v5">
              <?php if ($phoneDigits !== ''): ?>
                <a class="donor-record-phone-v5" href="https://wa.me/+39<?= e($waDigits) ?>" onclick="event.stopPropagation();" target="_blank"><?= e($phoneRaw) ?></a>
              <?php else: ?>
                <span class="donor-record-phone-v5 donor-record-phone-empty">—</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="donor-record-middle-v5">
            <div class="donor-metric-v5">
              <span class="donor-metric-label-v5">Total Donations</span>
              <strong class="donor-metric-value-v5"><?= money($totalDonations) ?></strong>
            </div>
            <div class="donor-metric-v5">
              <span class="donor-metric-label-v5" >Sponsored Amount</span>
              <strong class="donor-metric-value-v5"><?= money($sponsoredAmount) ?></strong>
            </div>
          </div>

          <div class="donor-record-right-v5">
            <?php if ($phoneDigits !== ''): ?>
              <a class="donor-record-phone-v5" href="https://wa.me/+39<?= e($waDigits) ?>" onclick="event.stopPropagation();" target="_blank"><?= e($phoneRaw) ?></a>
            <?php else: ?>
              <span class="donor-record-phone-v5 donor-record-phone-empty">—</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="donor-record-flags-v5">
          <?php if (!empty($person['monthly_subscription'])): ?><span class="tag orange">Monthly</span><?php endif; ?>
          <?php if (!empty($person['life_membership'])): ?><span class="tag blue">Life</span><?php endif; ?>
          <?php if (!empty($person['death_insurance_enabled'])): ?><span class="tag green">Death Insurance</span><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$people): ?><div class="muted">No donors found.</div><?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
