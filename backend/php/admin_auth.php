<?php
require_once __DIR__ . '/../../Front-end/connectapi/security.php';
require_once __DIR__ . '/../../Front-end/connectapi/app_config.php';
securityStartSession();
addSecurityHeaders();
require_once __DIR__ . '/../../Front-end/connectapi/db.php';

$logFile = dirname(__DIR__) . '/logs/admin_auth.log';
function log_debug($msg) {
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $time = date('Y-m-d H:i:s');
    error_log("[$time] $msg\n", 3, $logFile);
}

function redirect_with_error($message, $context = '') {
    if ($context) {
        log_debug("redirect_with_error: $message | context: $context");
    } else {
        log_debug("redirect_with_error: $message");
    }
    header('Location: ' . appUrl('Front-end/sceens/login.html') . '?error=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . appUrl('Front-end/sceens/login.html'));
    exit;
}

$login = trim($_POST['login'] ?? '');
$password = $_POST['password'] ?? '';
$rateLimitIdentifier = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower($login);

// Only incorrect credentials count toward a lockout; successful use is not throttled.
enforceFailedLoginRateLimit('admin_login', $rateLimitIdentifier, 8, 900);

if ($login === '' || $password === '') {
    recordFailedLoginAttempt('admin_login', $rateLimitIdentifier, 900);
    redirect_with_error('กรุณากรอกอีเมลผู้ใช้และรหัสผ่านให้ครบถ้วน', 'empty_credentials');
}

try {
    $stmt = $conn->prepare('SELECT admin_id, username, password, name, email FROM admin WHERE username = :login OR email = :login LIMIT 1');
    $stmt->execute([':login' => $login]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        log_debug("Admin lookup failed for login={$login}");
        recordFailedLoginAttempt('admin_login', $rateLimitIdentifier, 900);
        redirect_with_error('ไม่พบบัญชีผู้ดูแลระบบหรือรหัสผ่านไม่ถูกต้อง', 'not_found');
    }

    $storedPassword = trim((string) $admin['password']);
    $storedPassword = preg_replace('/[\x00-\x1F\x7F]+/', '', $storedPassword);

    $isValid = passwordMatchesStoredValue($storedPassword, $password);

    if (!$isValid) {
        log_debug("Invalid password for login={$login}");
        recordFailedLoginAttempt('admin_login', $rateLimitIdentifier, 900);
        redirect_with_error('ไม่พบบัญชีผู้ดูแลระบบหรือรหัสผ่านไม่ถูกต้อง', 'invalid_password');
    }

    if (!password_get_info($storedPassword)['algo']) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $migrate = $conn->prepare('UPDATE admin SET password = :hash WHERE admin_id = :id LIMIT 1');
        $migrate->execute([':hash' => $hash, ':id' => (int) $admin['admin_id']]);
    }

    session_regenerate_id(true);
    clearFailedLoginRateLimit('admin_login', $rateLimitIdentifier);
    $_SESSION['admin'] = [
        'admin_id' => $admin['admin_id'],
        'firstname' => $admin['name'] ?? '',
        'lastname' => '',
        'email' => $admin['email'] ?? '',
    ];
    $_SESSION['auth'] = [
        'type' => 'admin',
        'id' => (int) $admin['admin_id'],
        'admin_id' => (int) $admin['admin_id'],
        'name' => trim((string) ($admin['name'] ?? '')),
    ];

    $entryTarget = $_POST['entry_target'] ?? 'backend';
    $allowedTargets = ['backend', 'front'];
    if (!in_array($entryTarget, $allowedTargets, true)) {
        $entryTarget = 'backend';
    }

    log_debug("Successful admin login for login={$login} admin_id={$admin['admin_id']} target={$entryTarget}");

    if ($entryTarget === 'front') {
        $_SESSION['member'] = [
            'id' => (int) $admin['admin_id'],
            'firstname' => $admin['name'] ?? '',
            'lastname' => '',
            'username' => $admin['username'],
            'email' => $admin['email'] ?? '',
            'role' => 'admin_view',
        ];
        header('Location: ' . appUrl('Front-end/data/member.php'));
    } else {
        header('Location: ' . appUrl('backend/php/srceen/index.php'));
    }
    exit;
} catch (PDOException $exception) {
    log_debug('PDOException: ' . $exception->getMessage());
    redirect_with_error('เกิดข้อผิดพลาดในการเชื่อมต่อระบบ ลองใหม่อีกครั้ง', 'pdo_exception');
}
