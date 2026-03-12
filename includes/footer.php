    </main>
</div>
<?php if (isset($role) && $role !== 'guest'): ?>
<div class="mobile-quickbar">
    <?php if ($role === 'operator'): ?>
        <a class="quick-link" href="index.php"><span>🏠</span><small>Dashboard</small></a>
        <a class="quick-link" href="expense_page.php"><span>➕💸</span><small>Expense</small></a>
        <a class="quick-link" href="transaction_page.php"><span>➕💝</span><small>Donation</small></a>
        <a class="quick-link" href="donors.php"><span>👥</span><small>Donor</small></a>
        <a class="quick-link" href="transfer_requests.php"><span>🔄</span><small>Transfer</small></a>
    <?php else: ?>
        <a class="quick-link" href="index.php"><span>🏠</span><small>Dashboard</small></a>
        <a class="quick-link" href="accounts_report.php"><span>📊</span><small>Reports</small></a>
        <a class="quick-link" href="event_fund_transfers.php"><span>🔁</span><small>Event Transfer</small></a>
        <a class="quick-link" href="logout.php"><span>⎋</span><small>Logout</small></a>
    <?php endif; ?>
</div>
<?php endif; ?>
<script src="assets/app.js"></script>
</body>
</html>
