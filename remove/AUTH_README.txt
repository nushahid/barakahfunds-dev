BarakahFunds Authentication Upgrade

Files added:
- login.php
- logout.php
- admin_users.php
- AUTH_README.txt

Main security changes:
- Password hashing with PHP password_hash/password_verify
- Login rate limiting using auth_login_attempts table
- Session hardening and session regeneration on login
- CSRF protection on POST forms
- Prepared statements retained for user input operations
- Device enrollment using secure cookie + browser fingerprint checks

Important note about MAC address locking:
Standard web browsers do not expose the client MAC address to PHP or JavaScript.
Because of that, true MAC-based login restriction is not possible for a normal PHP web app.
This implementation uses enrolled-device authentication instead.

Deployment steps:
1. Run the SQL patch file: barakahfunds_auth_patch.sql
2. Update includes/db.php if your database name is not correct.
3. Upload/unzip the code over your current app.
4. Log in with:
   username: admin
   password: ChangeMe123!
5. Open Admin Users and enroll the current browser/device for admin.
6. Change the default admin password immediately by creating a new password flow in future, or updating password_hash directly.

Recommended follow-up:
- Force HTTPS on the website.
- Store database password securely.
- Add password change screen and optional 2FA.
