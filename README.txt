Replace these files in your project:
- includes/functions.php
- expense_page.php
- includes/header.php
- account_expense.php

What fixed:
1) expense_page.php now reads users.person_id and auto-selects linked donor/person for operator.
2) login/session functions now store and re-read person_id correctly.
3) even if old session missed person_id, page re-checks database again.
4) added Account Expense link in left sidebar for accountant/admin.
