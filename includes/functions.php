<?php
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_name('barakah_session');
    session_start();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function randomToken(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = randomToken(32);
    }
    return (string)$_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrfOrFail(): void
{
    $posted = (string)($_POST['csrf_token'] ?? '');
    $actual = (string)($_SESSION['csrf_token'] ?? '');
    if ($posted === '' || $actual === '' || !hash_equals($actual, $posted)) {
        http_response_code(419);
        exit('Security validation failed. Please go back and try again.');
    }
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function tableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function getClientIp(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function getClientUserAgent(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 500);
}

function getDeviceFingerprint(): string
{
    return hash('sha256', strtolower(getClientUserAgent()) . '|' . getClientIp());
}

function getUserAgentHash(): string
{
    return hash('sha256', strtolower(getClientUserAgent()));
}

function currentUser(PDO $pdo): ?array
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    static $cache = null;
    if ($cache !== null && (int)$cache['ID'] === $uid) {
        return $cache;
    }
    $fields = 'ID, name, username, admin, accountant, balance, active';
    if (columnExists($pdo, 'users', 'role')) {
        $fields .= ', role';
    }
    if (columnExists($pdo, 'users', 'phone')) {
        $fields .= ', phone';
    }
    if (columnExists($pdo, 'users', 'city')) {
        $fields .= ', city';
    }
    if (columnExists($pdo, 'users', 'preferred_language')) {
        $fields .= ', preferred_language';
    }
    $stmt = $pdo->prepare('SELECT ' . $fields . ' FROM users WHERE ID = ? LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user || (int)($user['active'] ?? 1) !== 1) {
        logoutUser();
        return null;
    }
    $cache = $user;
    return $user;
}

function currentRole(PDO $pdo): string
{
    $user = currentUser($pdo);
    if (!$user) {
        return 'guest';
    }
    if (!empty($user['role'])) {
        return (string)$user['role'];
    }
    if ((int)($user['admin'] ?? 0) === 1) {
        return 'admin';
    }
    if ((int)($user['accountant'] ?? 0) === 1) {
        return 'accountant';
    }
    return 'operator';
}

function roleLabel(string $role): string
{
    return match ($role) {
        'admin' => 'Admin',
        'accountant' => 'Accountant',
        default => 'Operator',
    };
}

function isAdmin(PDO $pdo): bool { return currentRole($pdo) === 'admin'; }
function isAccountant(PDO $pdo): bool { return in_array(currentRole($pdo), ['accountant','admin'], true); }
function isStrictAccountant(PDO $pdo): bool { return currentRole($pdo) === 'accountant'; }
function isOperator(PDO $pdo): bool { return currentRole($pdo) === 'operator'; }

function getLoggedInUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function getOperatorPersonId(): int
{
    return (int)($_SESSION['operator_person_id'] ?? 0);
}

function getUserPersonId(PDO $pdo, ?int $userId = null): int
{
    $userId = $userId ?: getLoggedInUserId();
    if ($userId <= 0) {
        return 0;
    }

    if ($userId === getLoggedInUserId()) {
        $sessionPersonId = (int)($_SESSION['operator_person_id'] ?? 0);
        if ($sessionPersonId > 0) {
            return $sessionPersonId;
        }
    }

    if (!columnExists($pdo, 'users', 'person_id')) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare('SELECT person_id FROM users WHERE ID = ? LIMIT 1');
        $stmt->execute([$userId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function requireLogin(PDO $pdo): void
{
    if (!isUserSessionValid($pdo)) {
        setFlash('error', 'Please log in to continue.');
        header('Location: login.php');
        exit;
    }
}

function requireAdmin(PDO $pdo): void
{
    requireLogin($pdo);
    if (!isAdmin($pdo)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function requireAccountant(PDO $pdo): void
{
    requireLogin($pdo);
    if (!isAccountant($pdo)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function requireRole(PDO $pdo, string|array $roles): void
{
    requireLogin($pdo);

    $allowed = is_array($roles) ? array_values($roles) : [$roles];
    $role = currentRole($pdo);

    $isAllowed = in_array($role, $allowed, true)
        || ($role === 'admin' && in_array('accountant', $allowed, true));

    if (!$isAllowed) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    setcookie('barakah_device', '', time() - 3600, '/', '', false, true);
    session_destroy();
}

function isUserSessionValid(PDO $pdo): bool
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    $user = currentUser($pdo);
    if (!$user) {
        return false;
    }

    $sessionKey = (string)($_SESSION['session_key'] ?? '');
    if ($sessionKey === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT session_key_hash FROM users WHERE ID = ? LIMIT 1');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row || empty($row['session_key_hash']) || !hash_equals((string)$row['session_key_hash'], hash('sha256', $sessionKey))) {
        return false;
    }

    $deviceId = (int)($_SESSION['device_id'] ?? 0);
    if ($deviceId > 0 && tableExists($pdo, 'auth_user_devices')) {
        $deviceStmt = $pdo->prepare('SELECT ID, active, user_agent_hash FROM auth_user_devices WHERE ID = ? AND user_id = ? LIMIT 1');
        $deviceStmt->execute([$deviceId, $uid]);
        $device = $deviceStmt->fetch();
        if (!$device || (int)$device['active'] !== 1) {
            return false;
        }
        if (!hash_equals((string)$device['user_agent_hash'], getUserAgentHash())) {
            return false;
        }
    }

    return true;
}

function loginAttemptsInWindow(PDO $pdo, string $username, string $ip): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM auth_login_attempts WHERE username = ? AND ip_address = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)');
    $stmt->execute([$username, $ip]);
    return (int)$stmt->fetchColumn();
}

function recordLoginAttempt(PDO $pdo, string $username, string $ip, bool $success): void
{
    $stmt = $pdo->prepare('INSERT INTO auth_login_attempts (username, ip_address, user_agent, success, attempted_at) VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([$username, $ip, getClientUserAgent(), $success ? 1 : 0]);
}

function currentRememberedDevice(PDO $pdo, int $userId): ?array
{
    if (!tableExists($pdo, 'auth_user_devices')) {
        return null;
    }
    $cookie = (string)($_COOKIE['barakah_device'] ?? '');
    if ($cookie === '' || !str_contains($cookie, ':')) {
        return null;
    }
    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM auth_user_devices WHERE user_id = ? AND selector = ? AND active = 1 LIMIT 1');
    $stmt->execute([$userId, $selector]);
    $device = $stmt->fetch();
    if (!$device) {
        return null;
    }
    if (!empty($device['expires_at']) && strtotime((string)$device['expires_at']) < time()) {
        return null;
    }
    if (!hash_equals((string)$device['validator_hash'], hash('sha256', $validator))) {
        return null;
    }
    if (!hash_equals((string)$device['user_agent_hash'], getUserAgentHash())) {
        return null;
    }
    return $device;
}

function registerCurrentDevice(PDO $pdo, int $userId, string $label, int $createdBy): int
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $validatorHash = hash('sha256', $validator);
    $uaHash = getUserAgentHash();
    $fingerprint = getDeviceFingerprint();
    $ip = getClientIp();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+180 days'));

    $stmt = $pdo->prepare('INSERT INTO auth_user_devices (user_id, device_label, selector, validator_hash, user_agent_hash, fingerprint_hash, first_ip, last_ip, active, last_seen_at, expires_at, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, NOW(), ?)');
    $stmt->execute([$userId, $label, $selector, $validatorHash, $uaHash, $fingerprint, $ip, $ip, $expiresAt, $createdBy]);
    $deviceId = (int)$pdo->lastInsertId();

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    setcookie('barakah_device', $selector . ':' . $validator, [
        'expires' => strtotime($expiresAt),
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return $deviceId;
}

function touchDevice(PDO $pdo, int $deviceId): void
{
    $stmt = $pdo->prepare('UPDATE auth_user_devices SET last_seen_at = NOW(), last_ip = ? WHERE ID = ?');
    $stmt->execute([getClientIp(), $deviceId]);
}

function establishLogin(PDO $pdo, array $user, ?int $deviceId): void
{
    session_regenerate_id(true);
    $sessionKey = bin2hex(random_bytes(32));
    $_SESSION['user_id'] = (int)$user['ID'];
    $_SESSION['operator_person_id'] = (int)($user['person_id'] ?? 0);
    $_SESSION['session_key'] = $sessionKey;
    $_SESSION['device_id'] = $deviceId;

    $stmt = $pdo->prepare('UPDATE users SET session_key_hash = ?, last_login_at = NOW(), last_login_ip = ? WHERE ID = ?');
    $stmt->execute([hash('sha256', $sessionKey), getClientIp(), (int)$user['ID']]);

    if ($deviceId !== null) {
        touchDevice($pdo, $deviceId);
    }
}

function verifyLogin(PDO $pdo, string $username, string $password): array
{
    $ip = getClientIp();
    if (loginAttemptsInWindow($pdo, $username, $ip) >= 5) {
        return ['ok' => false, 'message' => 'Too many failed attempts. Please wait 15 minutes and try again.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || (int)($user['active'] ?? 1) !== 1) {
        recordLoginAttempt($pdo, $username, $ip, false);
        return ['ok' => false, 'message' => 'Invalid username or password.'];
    }

    $passwordOk = false;
    if (!empty($user['password_hash'])) {
        $passwordOk = password_verify($password, (string)$user['password_hash']);
    } elseif (!empty($user['password'])) {
        $passwordOk = hash_equals((string)$user['password'], $password);
        if ($passwordOk) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare('UPDATE users SET password_hash = ?, password = NULL WHERE ID = ?');
            $upd->execute([$newHash, (int)$user['ID']]);
            $user['password_hash'] = $newHash;
        }
    }

    if (!$passwordOk) {
        recordLoginAttempt($pdo, $username, $ip, false);
        return ['ok' => false, 'message' => 'Invalid username or password.'];
    }

    if (!empty($user['password_hash']) && password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE ID = ?');
        $upd->execute([$newHash, (int)$user['ID']]);
        $user['password_hash'] = $newHash;
    }

    $device = currentRememberedDevice($pdo, (int)$user['ID']);
    $hasActiveDevice = false;
    if (tableExists($pdo, 'auth_user_devices')) {
        $hasAnyActiveDeviceStmt = $pdo->prepare('SELECT COUNT(*) FROM auth_user_devices WHERE user_id = ? AND active = 1');
        $hasAnyActiveDeviceStmt->execute([(int)$user['ID']]);
        $hasActiveDevice = (int)$hasAnyActiveDeviceStmt->fetchColumn() > 0;
    }

    if ($hasActiveDevice && !$device) {
        $deviceId = registerCurrentDevice($pdo, (int)$user['ID'], 'Auto enrolled browser', (int)$user['ID']);
        recordLoginAttempt($pdo, $username, $ip, true);
        establishLogin($pdo, $user, $deviceId);
        return ['ok' => true, 'user' => $user, 'device' => ['ID' => $deviceId, 'device_label' => 'Auto enrolled browser']];
    }

    recordLoginAttempt($pdo, $username, $ip, true);
    try {
        $clearStmt = $pdo->prepare('DELETE FROM auth_login_attempts WHERE username = ? AND ip_address = ? AND success = 0');
        $clearStmt->execute([$username, $ip]);
    } catch (Throwable $e) {
        // keep login success even if cleanup fails
    }
    establishLogin($pdo, $user, $device ? (int)$device['ID'] : null);
    if (!$hasActiveDevice && !$device && tableExists($pdo, 'auth_user_devices')) {
        $newDeviceId = registerCurrentDevice($pdo, (int)$user['ID'], 'Primary browser', (int)$user['ID']);
        $_SESSION['device_id'] = $newDeviceId;
    }
    return ['ok' => true, 'user' => $user, 'device' => $device];
}

function queryValue(PDO $pdo, string $sql, array $params = [], float $default = 0.0): float
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return $default;
    }
}

function money(float $value): string
{
    return '€' . number_format($value, 2);
}

function normalizePaymentMethod(string $method): string
{
    return match ($method) {
        'bank' => 'bank_transfer',
        'stripe', 'paypal', 'online' => 'online',
        default => $method,
    };
}

function currentMonthStart(): string { return date('Y-m-01 00:00:00'); }
function nextMonthStart(): string { return date('Y-m-01 00:00:00', strtotime('+1 month')); }
function previousMonthStart(): string { return date('Y-m-01 00:00:00', strtotime('-1 month')); }
function currentMonthLabel(): string { return date('F Y'); }
function previousMonthLabel(): string { return date('F Y', strtotime('-1 month')); }

function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    if (!tableExists($pdo, 'mosque_settings')) {
        return $default;
    }
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM mosque_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string)$value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    if (!tableExists($pdo, 'mosque_settings')) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO mosque_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
    $stmt->execute([$key, $value]);
}

function appName(PDO $pdo): string { return getSetting($pdo, 'mosque_name', 'BarakahFunds v4'); }
function defaultLanguage(PDO $pdo): string { return getSetting($pdo, 'default_language', 'en'); }
function currentLanguage(PDO $pdo): string
{
    $user = currentUser($pdo);
    return !empty($user['preferred_language']) ? (string)$user['preferred_language'] : defaultLanguage($pdo);
}

function systemLog(PDO $pdo, int $uid, string $module, string $action, string $details = '', ?int $relatedId = null): void
{
    try {
        if (tableExists($pdo, 'system_logs')) {
            $stmt = $pdo->prepare('INSERT INTO system_logs (user_id, module_name, action_name, details, related_id, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$uid, $module, $action, $details, $relatedId, getClientIp()]);
        }
    } catch (Throwable $e) {
    }
}

function personLog(PDO $pdo, int $uid, int $personId, string $action, string $details = ''): void
{
    try {
        if (tableExists($pdo, 'person_logs')) {
            $stmt = $pdo->prepare('INSERT INTO person_logs (person_id, user_id, action_name, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$personId, $uid, $action, $details, getClientIp()]);
        }
    } catch (Throwable $e) {
    }
}

function logAction(PDO $pdo, int $uid, string $action): void
{
    try {
        if (tableExists($pdo, 'logs')) {
            $stmt = $pdo->prepare('INSERT INTO logs (uid, action, created_at) VALUES (?, ?, ?)');
            $stmt->execute([$uid, $action, date('Y-m-d H:i:s')]);
        }
    } catch (Throwable $e) {
    }
    systemLog($pdo, $uid, 'legacy', 'action', $action);
}

function uploadReceipt(array $file, bool $required = true): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $required ? ['ok' => false, 'message' => 'Receipt upload is required.'] : ['ok' => true, 'filename' => null, 'path' => null];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed.'];
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Receipt must be 5MB or smaller.'];
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }

    $mime = @mime_content_type($tmp);
    if (!$mime || !isset($allowedMime[$mime])) {
        return ['ok' => false, 'message' => 'Only JPG, PNG, WEBP, and PDF files are allowed.'];
    }

    $ext = $allowedMime[$mime];
    $dir = dirname(__DIR__) . '/uploads/receipts';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'receipt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'message' => 'Failed to save uploaded receipt.'];
    }

    return ['ok' => true, 'filename' => $filename, 'path' => 'uploads/receipts/' . $filename];
}

function uploadReceiptLogo(array $file, bool $required = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $required ? ['ok' => false, 'message' => 'Logo upload is required.'] : ['ok' => true, 'filename' => null, 'path' => null];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Logo upload failed.'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Logo must be 2MB or smaller.'];
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid logo upload.'];
    }

    $mime = @mime_content_type($tmp);
    if (!$mime || !isset($allowedMime[$mime])) {
        return ['ok' => false, 'message' => 'Only JPG, PNG, and WEBP logos are allowed.'];
    }

    $ext = $allowedMime[$mime];
    $dir = dirname(__DIR__) . '/uploads/logos';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'receipt_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'message' => 'Failed to save uploaded logo.'];
    }

    return ['ok' => true, 'filename' => $filename, 'path' => 'uploads/logos/' . $filename];
}

function receiptLogoPath(PDO $pdo): string
{
    return getSetting($pdo, 'receipt_logo_path', '');
}

function getAccountantBankBalance(PDO $pdo): float
{
    if (!tableExists($pdo, 'accountant_ledger')) {
        return 0.0;
    }

    return queryValue($pdo, "SELECT COALESCE(SUM(CASE
        WHEN entry_type = 'bank_deposit' THEN amount
        WHEN payment_method IN ('bank_transfer','online','pos') THEN amount
        ELSE 0 END),0) FROM accountant_ledger");
}

function getAccountantCashOnHand(PDO $pdo): float
{
    if (!tableExists($pdo, 'accountant_ledger')) {
        return 0.0;
    }

    return queryValue($pdo, "SELECT COALESCE(SUM(CASE
        WHEN entry_type = 'bank_deposit' THEN -amount
        WHEN payment_method = 'cash' THEN amount
        ELSE 0 END),0) FROM accountant_ledger");
}


function donorDisplayName(array $person): string
{
    $name = trim((string)($person['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $phone = trim((string)($person['phone'] ?? ''));
    $city = trim((string)($person['city'] ?? ''));
    $id = (int)($person['ID'] ?? ($person['id'] ?? 0));
    if ($phone !== '' && $city !== '') {
        return $phone . ' • ' . $city;
    }
    if ($phone !== '') {
        return $phone;
    }
    if ($city !== '') {
        return $city;
    }
    return $id > 0 ? ('Donor #' . $id) : 'Unknown donor';
}


function parseLegacyPaidByName(string $notes): string
{
    $notes = trim($notes);
    if ($notes === '') return '';
    if (preg_match('/Paid by\s+(.+?)\s+from\s+/i', $notes, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/^\[legacy:transfer\]\s*(.+?)\s*\|\s*from user #/i', $notes, $m)) {
        $val = trim($m[1]);
        if ($val !== '') return $val;
    }
    return '';
}

function resolveTransferCounterpartyName(PDO $pdo, array $row): string
{
    $refId = (int)($row['reference_id'] ?? 0);
    $category = (string)($row['transaction_category'] ?? $row['category'] ?? '');
    if ($refId > 0 && tableExists($pdo, 'balance_transfers')) {
        try {
            $stmt = $pdo->prepare('SELECT t.*, fu.name AS from_name, tu.name AS to_name FROM balance_transfers t LEFT JOIN users fu ON fu.ID = t.from_user_id LEFT JOIN users tu ON tu.ID = t.to_user_id WHERE t.ID = ? LIMIT 1');
            $stmt->execute([$refId]);
            $tr = $stmt->fetch();
            if ($tr) {
                if ($category === 'transfer_in') {
                    $name = trim((string)($tr['from_name'] ?? ''));
                    if ($name !== '') return $name;
                }
                if ($category === 'transfer_out') {
                    $name = trim((string)($tr['to_name'] ?? ''));
                    if ($name !== '') return $name;
                }
                if (trim((string)($tr['notes'] ?? '')) !== '') {
                    $legacy = parseLegacyPaidByName((string)$tr['notes']);
                    if ($legacy !== '') return $legacy;
                }
            }
        } catch (Throwable $e) {
        }
    }

    $legacy = parseLegacyPaidByName((string)($row['notes'] ?? ''));
    if ($legacy !== '') return $legacy;

    if ($category === 'transfer_in' || $category === 'transfer_out') {
        $operatorName = trim((string)($row['operator_name'] ?? ''));
        if ($operatorName !== '') return $operatorName;
    }

    return '';
}

function resolveLedgerDonorName(PDO $pdo, array $row): string
{
    $joined = trim((string)($row['person'] ?? ''));
    if ($joined !== '') {
        return $joined;
    }
    $transferName = resolveTransferCounterpartyName($pdo, $row);
    if ($transferName !== '') {
        return $transferName;
    }
    $personId = (int)($row['person_id'] ?? 0);
    if ($personId > 0 && tableExists($pdo, 'people')) {
        try {
            $stmt = $pdo->prepare('SELECT ID, name, phone, city FROM people WHERE ID = ? LIMIT 1');
            $stmt->execute([$personId]);
            $person = $stmt->fetch();
            if ($person) {
                return donorDisplayName($person);
            }
        } catch (Throwable $e) {
        }
    }

    $category = (string)($row['category'] ?? $row['transaction_category'] ?? '');
    $refId = (int)($row['reference_id'] ?? 0);
    if ($refId > 0) {
        $lookup = null;
        if (in_array($category, ['monthly', 'one_time', 'donation'], true)) {
            if ($category === 'monthly' && tableExists($pdo, 'monthly')) {
                $lookup = 'SELECT p.ID, p.name, p.phone, p.city FROM monthly s JOIN people p ON p.ID = s.pid WHERE s.ID = ? LIMIT 1';
            } elseif (tableExists($pdo, 'one_time')) {
                $lookup = 'SELECT p.ID, p.name, p.phone, p.city FROM one_time s JOIN people p ON p.ID = s.pid WHERE s.ID = ? LIMIT 1';
            }
        } elseif ($category === 'event' && tableExists($pdo, 'event_details')) {
            $lookup = 'SELECT p.ID, p.name, p.phone, p.city FROM event_details s JOIN people p ON p.ID = s.pid WHERE s.ID = ? LIMIT 1';
        } elseif ($category === 'loan' && tableExists($pdo, 'loan_trans') && tableExists($pdo, 'loan')) {
            $lookup = 'SELECT p.ID, p.name, p.phone, p.city FROM loan_trans t JOIN loan l ON l.ID = t.lid JOIN people p ON p.ID = l.pid WHERE t.ID = ? LIMIT 1';
        }
        if ($lookup) {
            try {
                $stmt = $pdo->prepare($lookup);
                $stmt->execute([$refId]);
                $person = $stmt->fetch();
                if ($person) {
                    return donorDisplayName($person);
                }
            } catch (Throwable $e) {
            }
        }
    }

    return $personId > 0 ? ('Donor #' . $personId) : 'Unknown donor';
}

function getPeople(PDO $pdo, string $search = '', string $filter = 'all'): array
{
    $select = 'SELECT ID, name, phone, city';
    foreach (['life_membership','monthly_subscription','death_insurance_enabled','notes'] as $col) {
        if (columnExists($pdo, 'people', $col)) {
            $select .= ', ' . $col;
        }
    }
    $select .= ' FROM people';

    $where = [];
    $params = [];

    if ($filter === 'life' && columnExists($pdo, 'people', 'life_membership')) {
        $where[] = 'life_membership = 1';
    } elseif ($filter === 'monthly' && columnExists($pdo, 'people', 'monthly_subscription')) {
        $where[] = 'monthly_subscription = 1';
    } elseif ($filter === 'death' && columnExists($pdo, 'people', 'death_insurance_enabled')) {
        $where[] = 'death_insurance_enabled = 1';
    }

    if ($search !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR city LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($where) {
        $select .= ' WHERE ' . implode(' AND ', $where);
    }

    $select .= ' ORDER BY name ASC';

    if ($params) {
        $stmt = $pdo->prepare($select);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    return $pdo->query($select)->fetchAll();
}

function getSocieties(PDO $pdo): array
{
    if (!tableExists($pdo, 'societies')) {
        return [];
    }
    return $pdo->query('SELECT ID, name, city, is_default FROM societies WHERE active = 1 ORDER BY is_default DESC, name ASC')->fetchAll();
}

function ensureSociety(PDO $pdo, string $name, string $city = ''): int
{
    if (!tableExists($pdo, 'societies')) return 0;
    $name = trim($name);
    if ($name === '') return 0;
    $stmt = $pdo->prepare('SELECT ID FROM societies WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) return $id;
    $insert = $pdo->prepare('INSERT INTO societies (name, city, is_default, active, created_at) VALUES (?, ?, 0, 1, NOW())');
    $insert->execute([$name, $city]);
    return (int)$pdo->lastInsertId();
}

function getOperators(PDO $pdo): array
{
    if (columnExists($pdo, 'users', 'role')) {
        return $pdo->query("SELECT ID, name, username, role, active FROM users WHERE active = 1 AND role IN ('operator','accountant','admin') ORDER BY name ASC")->fetchAll();
    }
    return $pdo->query('SELECT ID, name, username, admin, accountant, active FROM users WHERE active = 1 ORDER BY name ASC')->fetchAll();
}

function getExpenseCategories(PDO $pdo): array
{
    if (tableExists($pdo, 'expense_categories')) {
        return $pdo->query('SELECT ID, category_name AS name FROM expense_categories WHERE status = 1 ORDER BY category_name ASC')->fetchAll();
    }
    try {
        return $pdo->query('SELECT ID, name FROM expense_cat WHERE status = 1 ORDER BY name ASC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getEvents(PDO $pdo): array
{
    if (!tableExists($pdo, 'events')) {
        return [];
    }
    $statusCol = columnExists($pdo, 'events', 'status') ? 'status' : (columnExists($pdo, 'events', 'active') ? 'active' : null);
    $sql = 'SELECT ID, name FROM events';
    if ($statusCol) {
        $sql .= ' WHERE ' . $statusCol . ' = 1';
    }
    $sql .= ' ORDER BY ID DESC';
    return $pdo->query($sql)->fetchAll();
}

function getLoans(PDO $pdo): array
{
    if (tableExists($pdo, 'loan')) {
        return $pdo->query('SELECT ID, name FROM loan ORDER BY ID DESC')->fetchAll();
    }
    return [];
}

function getPersonCurrentPlan(PDO $pdo, int $personId): ?array
{
    if (!tableExists($pdo, 'member_monthly_plans')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM member_monthly_plans WHERE member_id = ? ORDER BY active DESC, ID DESC LIMIT 1');
    $stmt->execute([$personId]);
    return $stmt->fetch() ?: null;
}

function getDonorCounts(PDO $pdo): array
{
    $counts = ['total' => 0, 'life' => 0, 'monthly' => 0, 'death' => 0];
    if (!tableExists($pdo, 'people')) return $counts;
    $counts['total'] = (int)queryValue($pdo, 'SELECT COUNT(*) FROM people');
    if (columnExists($pdo, 'people', 'life_membership')) {
        $counts['life'] = (int)queryValue($pdo, 'SELECT COUNT(*) FROM people WHERE life_membership = 1');
        $counts['monthly'] = (int)queryValue($pdo, 'SELECT COUNT(*) FROM people WHERE monthly_subscription = 1');
        $counts['death'] = (int)queryValue($pdo, 'SELECT COUNT(*) FROM people WHERE death_insurance_enabled = 1');
    }
    return $counts;
}


function getTotalCashInAllOperators(PDO $pdo): float
{
    if (!tableExists($pdo, 'operator_ledger')) {
        return 0.0;
    }

    return (float) queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger');
}

function allOperatorsCashInHand(PDO $pdo): float
{
    return getTotalCashInAllOperators($pdo);
}

function getTotalCollectionTillNow(PDO $pdo): float
{
    if (!tableExists($pdo, 'operator_ledger')) {
        return 0.0;
    }

    return queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE amount > 0');
}

function getTotalExpenseTillNow(PDO $pdo): float
{
    if (!tableExists($pdo, 'operator_ledger')) {
        return 0.0;
    }

    return abs(queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE amount < 0 AND transaction_category IN ("expense","transfer_out","loan_returned")'));
}

function getMonthlyAgreed(PDO $pdo): float
{
    if (tableExists($pdo, 'member_monthly_plans')) {
        return queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM member_monthly_plans WHERE active = 1');
    }
    return 0.0;
}

function getMonthlyCollected(PDO $pdo): float
{
    if (tableExists($pdo, 'operator_ledger')) {
        return queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE amount > 0 AND transaction_category = "monthly" AND created_at >= ? AND created_at < ?', [currentMonthStart(), nextMonthStart()]);
    }
    return 0.0;
}

function getMonthlyTotalCollected(PDO $pdo): float
{
    if (tableExists($pdo, 'operator_ledger')) {
        return queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE amount > 0 AND created_at >= ? AND created_at < ?', [currentMonthStart(), nextMonthStart()]);
    }
    return 0.0;
}

function getMonthlyExpense(PDO $pdo): float
{
    if (tableExists($pdo, 'operator_ledger')) {
        return abs(queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE amount < 0 AND transaction_category IN ("expense","transfer_out","loan_returned") AND created_at >= ? AND created_at < ?', [currentMonthStart(), nextMonthStart()]));
    }
    return 0.0;
}

function getAccountantCashInHand(PDO $pdo): float
{
    return getAccountantCashOnHand($pdo);
}

function getPendingMonthly(PDO $pdo): float
{
    return max(0, getMonthlyAgreed($pdo) - getMonthlyCollected($pdo));
}

function getPreviousMonthStripeAmount(PDO $pdo): float
{
    if (tableExists($pdo, 'member_monthly_dues')) {
        return queryValue($pdo, 'SELECT COALESCE(SUM(paid_amount),0) FROM member_monthly_dues WHERE due_month >= ? AND due_month < ? AND payment_source = "stripe" AND status = "paid"', [date('Y-m-01', strtotime('-1 month')), date('Y-m-01')]);
    }
    return 0.0;
}

function getCollectionByMethod(PDO $pdo, string $start, string $end): array
{
    $methods = ['cash' => 0.0, 'bank_transfer' => 0.0, 'pos' => 0.0, 'online' => 0.0, 'adjustment' => 0.0];
    if (tableExists($pdo, 'operator_ledger')) {
        try {
            $stmt = $pdo->prepare('SELECT payment_method, COALESCE(SUM(amount),0) AS total FROM operator_ledger WHERE amount > 0 AND created_at >= ? AND created_at < ? GROUP BY payment_method');
            $stmt->execute([$start, $end]);
            foreach ($stmt->fetchAll() as $row) {
                $key = (string)$row['payment_method'];
                if (!isset($methods[$key])) $methods[$key] = 0.0;
                $methods[$key] = (float)$row['total'];
            }
        } catch (Throwable $e) {
        }
    }
    return $methods;
}

function getDailyCollectionSeries(PDO $pdo): array
{
    $series = [];
    $today = (int)date('j');
    for ($d = 1; $d <= $today; $d++) {
        $date = date('Y-m-d', strtotime(date('Y-m-01') . ' +' . ($d - 1) . ' day'));
        $series[$date] = 0.0;
    }
    if (tableExists($pdo, 'operator_ledger')) {
        try {
            $stmt = $pdo->prepare('SELECT DATE(created_at) AS d, COALESCE(SUM(amount),0) AS total FROM operator_ledger WHERE amount > 0 AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)');
            $stmt->execute([currentMonthStart(), nextMonthStart()]);
            foreach ($stmt->fetchAll() as $row) {
                $series[$row['d']] = (float)$row['total'];
            }
        } catch (Throwable $e) {
        }
    }
    return $series;
}

function canEditOwnRecord(string $createdAt, int $createdBy, int $currentUserId, int $minutes = 10): bool
{
    if ($createdBy !== $currentUserId) return false;
    return strtotime($createdAt) >= strtotime('-' . $minutes . ' minutes');
}

function generateInvoiceNumber(PDO $pdo): string
{
    $prefix = getSetting($pdo, 'receipt_prefix', 'INV');
    $seq = 1 + (int)getSetting($pdo, 'receipt_counter', '0');
    setSetting($pdo, 'receipt_counter', (string)$seq);
    return sprintf('%s-%06d', $prefix, $seq);
}

function receiptVerificationToken(int $recordId, string $invoiceNo): string
{
    return hash_hmac('sha256', $recordId . '|' . $invoiceNo, 'barakahfunds-v4-receipt');
}

function findReceiptRecord(PDO $pdo, int $id): ?array
{
    if (!tableExists($pdo, 'operator_ledger')) return null;
    $stmt = $pdo->prepare('SELECT ol.*, p.name AS person_name, p.phone AS person_phone, u.name AS operator_name FROM operator_ledger ol LEFT JOIN people p ON p.ID = ol.person_id LEFT JOIN users u ON u.ID = ol.operator_id WHERE ol.ID = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $displayName = trim((string)($row['person_name'] ?? ''));
    if ($displayName === '') {
        $displayName = resolveLedgerDonorName($pdo, $row);
    }
    $row['display_party_name'] = $displayName;
    return $row;
}

function monthlyOutstandingMonths(PDO $pdo, int $personId): array
{
    $plan = getPersonCurrentPlan($pdo, $personId);
    if (!$plan || (float)$plan['amount'] <= 0) return [];
    $start = date('Y-m-01', strtotime((string)$plan['start_date'] ?: date('Y-m-01')));
    $months = [];
    $cursor = strtotime($start);
    $paid = 0.0;
    if (tableExists($pdo, 'operator_ledger')) {
        $paid = queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE person_id = ? AND transaction_category = "monthly"', [$personId]);
    }
    $amount = (float)$plan['amount'];
    $credits = (int)floor($paid / $amount);
    for ($i = 0; $i < 24; $i++) {
        $months[] = date('Y-m', strtotime('+' . $i . ' month', $cursor));
    }
    return array_slice($months, $credits);
}

function operatorBalance(PDO $pdo, int $userId): float
{
    if (!tableExists($pdo, 'operator_ledger')) return 0.0;
    return queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM operator_ledger WHERE operator_id = ?', [$userId]);
}

function operatorPendingTransfers(PDO $pdo, int $userId): float
{
    if (!tableExists($pdo, 'balance_transfers')) return 0.0;
    return queryValue($pdo, 'SELECT COALESCE(SUM(amount),0) FROM balance_transfers WHERE from_user_id = ? AND status = "pending"', [$userId]);
}

function activeEventsSummary(PDO $pdo): array
{
    if (!tableExists($pdo, 'events')) return [];
    $estimateCol = columnExists($pdo, 'events', 'estimate') ? 'estimate' : (columnExists($pdo, 'events', 'estimated') ? 'estimated' : '0');
    $nameCol = columnExists($pdo, 'events', 'name') ? 'name' : (columnExists($pdo, 'events', 'event_name') ? 'event_name' : 'name');
    $statusCol = columnExists($pdo, 'events', 'status') ? 'status' : '1';
    $eventDetailsExists = tableExists($pdo, 'event_details');
    $eventIdCol = $eventDetailsExists && columnExists($pdo, 'event_details', 'event_id') ? 'event_id' : ($eventDetailsExists && columnExists($pdo, 'event_details', 'eid') ? 'eid' : '');
    $collectedSql = ($eventDetailsExists && $eventIdCol !== '') ? 'COALESCE((SELECT SUM(amount) FROM event_details d WHERE d.' . $eventIdCol . ' = e.ID),0)' : '0';
    $sql = 'SELECT e.ID, e.' . $nameCol . ' AS name, e.' . $estimateCol . ' AS estimate, e.' . $statusCol . ' AS status, ' . $collectedSql . ' AS collected FROM events e';
    if ($statusCol !== '1') {
        $sql .= ' WHERE e.' . $statusCol . ' = 1';
    }
    $sql .= ' ORDER BY e.ID DESC LIMIT 10';
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$row) {
        $row['remaining'] = (float)$row['estimate'] - (float)$row['collected'];
    }
    return $rows;
}

function getPublicDonorByPhone(PDO $pdo, int $id, string $last3): ?array
{
    $stmt = $pdo->prepare('SELECT ID, name, phone, city FROM people WHERE ID = ? LIMIT 1');
    $stmt->execute([$id]);
    $person = $stmt->fetch();
    if (!$person) return null;
    $digits = preg_replace('/\D+/', '', (string)$person['phone']);
    if (substr($digits, -3) !== preg_replace('/\D+/', '', $last3)) return null;
    return $person;
}
