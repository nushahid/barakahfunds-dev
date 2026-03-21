<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
require_once __DIR__ . '/includes/db.php';
requireRole($pdo, 'operator','accountant','admin');

// 1. Get Filters
$reportType = $_GET['type'] ?? 'active_donors';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

$results = [];
$reportTitle = "Donor Report";

// 2. Logic for reports (Enhanced to fetch extra details for the click-expand feature)
switch ($reportType) {
    case 'active_donors':
        $reportTitle = "All Active Donors";
        $stmt = $pdo->prepare("SELECT * FROM people WHERE is_removed = 0 AND name != 'remove' ORDER BY name ASC");
        $stmt->execute();
        $results = $stmt->fetchAll();
        break;

    case 'with_donations':
        $reportTitle = "Donations Received ($startDate to $endDate)";
        $stmt = $pdo->prepare("
            SELECT p.*, SUM(ol.amount) as total_collected, COUNT(ol.ID) as trans_count
            FROM people p
            JOIN operator_ledger ol ON p.ID = ol.person_id
            WHERE ol.created_at BETWEEN ? AND ? 
              AND ol.amount > 0 AND COALESCE(ol.is_removed, 0) = 0
            GROUP BY p.ID ORDER BY total_collected DESC
        ");
        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $results = $stmt->fetchAll();
        break;

    case 'with_expenses':
        $reportTitle = "Expense Entries ($startDate to $endDate)";
        $stmt = $pdo->prepare("
            SELECT p.*, SUM(ABS(ol.amount)) as total_expense
            FROM people p
            JOIN operator_ledger ol ON p.ID = ol.person_id
            WHERE ol.created_at BETWEEN ? AND ? 
              AND ol.transaction_type = 'expense' AND COALESCE(ol.is_removed, 0) = 0
            GROUP BY p.ID ORDER BY total_expense DESC
        ");
        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $results = $stmt->fetchAll();
        break;

    case 'expected_commitments':
    case 'overdue_commitments':
        $isOverdue = ($reportType == 'overdue_commitments');
        $reportTitle = $isOverdue ? "Overdue Commitments (Action Required)" : "Pending Commitments";
        $dateLimit = $isOverdue ? "AND dc.due_date < CURRENT_DATE" : "";
        
        $stmt = $pdo->prepare("
            SELECT p.*, dc.expected_amount, dc.due_date, dc.category as commitment_cat, dc.status as commitment_status
            FROM donation_commitments dc
            JOIN people p ON dc.person_id = p.ID
            WHERE dc.status IN ('pending', 'partial') 
              AND p.name != 'removed' $dateLimit
            ORDER BY dc.due_date ASC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        break;

    case 'expense_categories':
        $reportTitle = "Expense Breakdown by Category";
        $stmt = $pdo->prepare("
            SELECT transaction_category as name, COUNT(*) as record_count, SUM(ABS(amount)) as total_expense, 'N/A' as city
            FROM operator_ledger
            WHERE transaction_type = 'expense' AND created_at BETWEEN ? AND ? AND is_removed = 0
            GROUP BY transaction_category ORDER BY total_expense DESC
        ");
        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $results = $stmt->fetchAll();
        break;

        // --- ADD THIS TO YOUR SWITCH BLOCK ---
 case 'donations_by_city':
    $cityFilter = trim((string)($_GET['city_filter'] ?? ''));
    $reportTitle = "Donations Summary by City";
    
    // Base SQL
    $sql = "SELECT 
                p.city as name, 
                COUNT(DISTINCT p.ID) as total_donors,
                SUM(ol.amount) as total_collected
            FROM people p
            JOIN operator_ledger ol ON p.ID = ol.person_id
            WHERE ol.created_at BETWEEN ? AND ? 
              AND ol.amount > 0 
              AND COALESCE(ol.is_removed, 0) = 0
              AND p.city != '' ";

    $params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];

    // ADD THE FILTER LOGIC HERE
    if ($cityFilter !== '') {
        $sql .= " AND p.city = ? ";
        $params[] = $cityFilter;
    }

    $sql .= " GROUP BY p.city ORDER BY total_collected DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    break;
}

require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="donor_reports.css">

<div class="reports-container page-donors">
    <div class="reports-header">
        <h1 class="title"><i class="fas fa-chart-pie"></i> Reports Center</h1>
        <button class="btn btn-primary no-print" onclick="window.print()"><i class="fas fa-print"></i> Print PDF</button>
    </div>

    <?php 
    $grandTotal = 0;
    foreach($results as $r) {
        $grandTotal += ($r['total_collected'] ?? $r['total_expense'] ?? $r['expected_amount'] ?? 0);
    }
    ?>

   <div class="donor-summary-grid no-print">
    <div class="card donor-summary-card">
        <div class="card-body">
            <label class="muted small uppercase d-block mb-1">Total Records</label>
            <h3 class="mb-0"><?= count($results) ?></h3>
        </div>
    </div>
    <div class="card donor-summary-card">
        <div class="card-body">
            <label class="muted small uppercase d-block mb-1">Grand Total</label>
            <h3 class="mb-0"><?= money($grandTotal ?? 0) ?></h3>
        </div>
    </div>

    <?php if ($reportType == 'donations_by_city' && !empty($results)): ?>
    <div class="card donor-summary-card city-graph-card" style="grid-column: span 2;">
        <div class="card-body">
            <label class="muted small uppercase d-block mb-2">Revenue by City (Top 5)</label>
            <div class="city-bar-container">
                <?php 
                $topCities = array_slice($results, 0, 5); 
                $maxVal = $topCities[0]['total_collected'] ?? 1;
                foreach($topCities as $city): 
                    $percent = ($city['total_collected'] / $maxVal) * 100;
                ?>
                <div class="city-bar-row" style="margin-bottom: 8px;">
                    <div class="city-bar-label" style="font-size: 12px; display:flex; justify-content: space-between;">
                        <span><?= e($city['name']) ?></span>
                        <strong><?= money($city['total_collected']) ?></strong>
                    </div>
                    <div class="city-bar-bg" style="background: #eee; height: 8px; border-radius: 4px; overflow: hidden;">
                        <div class="city-bar-fill" style="background: var(--primary-color, #2563eb); width: <?= $percent ?>%; height: 100%;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

    <div class="card reports-filter-card no-print">
        <form method="GET" class="reports-form">
            <div class="filter-group">
                <label>Report Type</label>
                <select name="type">
                    <optgroup label="Financials">
                        <option value="with_donations" <?= $reportType == 'with_donations' ? 'selected' : '' ?>>Donations Received</option>
                        <option value="with_expenses" <?= $reportType == 'with_expenses' ? 'selected' : '' ?>>Expenses Paid</option>
                        <option value="expense_categories" <?= $reportType == 'expense_categories' ? 'selected' : '' ?>>Expense Categories</option>
                    </optgroup>
                    <optgroup label="Commitments">
                        <option value="expected_commitments" <?= $reportType == 'expected_commitments' ? 'selected' : '' ?>>Pending Dues</option>
                        <option value="overdue_commitments" <?= $reportType == 'overdue_commitments' ? 'selected' : '' ?>>⚠️ Overdue Dues</option>
                    </optgroup>
                    <optgroup label="Lists">
                        <option value="active_donors" <?= $reportType == 'active_donors' ? 'selected' : '' ?>>All Active Donors</option>
                    </optgroup>
                    <optgroup label="Geographic Reports">
                        <option value="donations_by_city" <?= $reportType == 'donations_by_city' ? 'selected' : '' ?>>Donations by City</option>
                </optgroup>
                </select>
            </div>
                <div id="city-filter-container" class="filter-group city-autocomplete-wrap" style="display: none; position: relative;">
    <label for="city_autocomplete">City Filter</label>
    <input 
        type="text" 
        id="city_autocomplete" 
        name="city_filter" 
        value="<?= e($_GET['city_filter'] ?? '') ?>" 
        placeholder="Search city..."
        autocomplete="off"
        style="width: 100%;"
    >
    <div id="city_autocomplete_list" 
         style="position: absolute; top: 100%; left: 0; width: 100%; background: white; border: 1px solid #ccc; z-index: 9999; display: none; max-height: 200px; overflow-y: auto; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
    </div>
</div>
            <div class="filter-group">
                <label>From</label>
                <input type="date" name="start_date" value="<?= e($startDate) ?>">
            </div>
            <div class="filter-group">
                <label>To</label>
                <input type="date" name="end_date" value="<?= e($endDate) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Generate</button>
                <a href="donor_reports.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="card results-card">
        <h2 class="report-subtitle"><?= e($reportTitle) ?></h2>
        <p class="muted no-print" style="font-size: 0.8rem; margin-bottom: 10px;">Click any row to see full details.</p>
        
        <div class="table-wrap">
            <table class="report-table">
    <thead>
        <tr>
            <th width="30"></th>
            <th><?= ($reportType == 'donations_by_city') ? 'City Name' : 'Donor Name' ?></th>
            <th class="hide-mobile"><?= ($reportType == 'donations_by_city') ? 'No. of Donors' : 'Location' ?></th>
            <th>Details</th>
            <th class="amount-cell">Amount</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($results as $index => $row): ?>
            <tr class="clickable-row" onclick="toggleReportDetails('row-<?= $index ?>')" style="cursor: pointer;">
                <td><i class="fas fa-chevron-right toggle-icon" id="icon-row-<?= $index ?>"></i></td>
                <td><strong><?= e($row['name'] ?? 'N/A') ?></strong></td>
                <td class="hide-mobile">
                    <?= ($reportType == 'donations_by_city') ? e($row['total_donors'] ?? 0) : e($row['city'] ?? '—') ?>
                </td>
                <td>
                    <?php if($reportType == 'donations_by_city'): ?>
                        <span class="tag blue">Geographic</span>
                    <?php else: ?>
                        <?php if(!empty($row['monthly_subscription'])): ?><span class="tag orange">Monthly</span><?php endif; ?>
                        <?php if(!empty($row['life_membership'])): ?><span class="tag blue">Life</span><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="amount-cell">
                    <?php 
                        $val = $row['total_collected'] ?? $row['total_expense'] ?? $row['expected_amount'] ?? 0;
                        echo money($val);
                    ?>
                </td>
            </tr>
            
            <tr id="row-<?= $index ?>" class="detail-row" style="display:none; background-color: #fcfcfc;">
                <td colspan="5">
                    <div class="expanded-info" style="padding: 15px; border-left: 4px solid #2563eb;">
                        <?php if($reportType == 'donations_by_city'): ?>
                            <p>City Summary: Total collection from <strong><?= e($row['total_donors']) ?></strong> registered donors in this city.</p>
                        <?php else: ?>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div><strong>Phone:</strong> <?= e($row['phone'] ?? 'N/A') ?></div>
                                <div><strong>Status:</strong> Active Donor</div>
                                <div><strong>Notes:</strong> <?= e($row['notes'] ?? 'None') ?></div>
                                <div><strong>Member ID:</strong> #<?= e($row['ID'] ?? 'N/A') ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if (!$results): ?>
            <tr><td colspan="5" class="muted text-center">No records found for selected filters.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
        </div>
    </div>
</div>

<script>
function toggleReportDetails(id) {
    const detailRow = document.getElementById(id);
    const icon = document.getElementById('icon-' + id);
    
    if (detailRow.style.display === 'none') {
        detailRow.style.display = 'table-row';
        if(icon) icon.style.transform = 'rotate(90deg)';
    } else {
        detailRow.style.display = 'none';
        if(icon) icon.style.transform = 'rotate(0deg)';
    }
}
</script>

<script src="assets/city-autocomplete.js"></script>

<script>
/**
 * Row Toggle Logic
 */
function toggleReportDetails(id) {
    const detailRow = document.getElementById(id);
    if (!detailRow) return;

    const mainRow = detailRow.previousElementSibling;
    const icon = mainRow.querySelector('.toggle-icon');
    
    if (detailRow.style.display === 'none' || detailRow.style.display === '') {
        detailRow.style.display = 'table-row';
        if (icon) icon.classList.replace('fa-chevron-right', 'fa-chevron-down');
        mainRow.style.backgroundColor = '#fff7ed';
    } else {
        detailRow.style.display = 'none';
        if (icon) icon.classList.replace('fa-chevron-down', 'fa-chevron-right');
        mainRow.style.backgroundColor = '';
    }
}

/**
 * Filter & Autocomplete Logic
 */
document.addEventListener('DOMContentLoaded', function() {
    const typeSelect = document.querySelector('select[name="type"]');
    const cityContainer = document.getElementById('city-filter-container');
    const cityInput = document.getElementById('city_autocomplete');
    const cityList = document.getElementById('city_autocomplete_list');

    // 1. Show/Hide City Filter based on Selection
    function toggleCityVisibility() {
        if (typeSelect && typeSelect.value === 'donations_by_city') {
            cityContainer.style.display = 'block';
        } else if (cityContainer) {
            cityContainer.style.display = 'none';
        }
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', toggleCityVisibility);
        toggleCityVisibility(); // Run on page load
    }

    // 2. City Autocomplete AJAX Logic
    if (cityInput && cityList) {
        cityInput.addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length < 2) {
                cityList.style.display = 'none';
                return;
            }

            fetch(`ajax_search_cities.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.length === 0) {
                        cityList.style.display = 'none';
                        return;
                    }

                    cityList.innerHTML = '';
                    cityList.style.display = 'block';

                    data.forEach(item => {
                        const cityName = typeof item === 'object' ? item.name : item;
                        const div = document.createElement('div');
                        div.className = 'city-autocomplete-item'; // Use your CSS class
                        div.style.padding = '10px';
                        div.style.cursor = 'pointer';
                        div.style.borderBottom = '1px solid #eee';
                        div.innerHTML = `<strong>${cityName}</strong>`;
                        
                        div.onclick = function() {
                            cityInput.value = cityName;
                            cityList.style.display = 'none';
                            cityInput.closest('form').submit(); 
                        };
                        
                        div.onmouseover = () => div.style.background = '#f0f7ff';
                        div.onmouseout = () => div.style.background = 'white';
                        
                        cityList.appendChild(div);
                    });
                })
                .catch(err => console.error("City Search Error:", err));
        });

        // Close list if user clicks away
        document.addEventListener('click', (e) => {
            if (e.target !== cityInput) cityList.style.display = 'none';
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>