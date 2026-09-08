<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/app_config.php';
securityStartSession();
addSecurityHeaders();
require_once __DIR__ . '/db.php';

$logFile = dirname(__DIR__, 2) . '/backend/logs/admin_auth.log';
function log_debug_login($msg) {
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $time = date('Y-m-d H:i:s');
    error_log("[$time] $msg\n", 3, $logFile);
}

function redirect_with_error($message) {
    log_debug_login('login_error: ' . $message);
    header('Location: ../sceens/login.html?error=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../sceens/login.html');
    exit;
}

$login = trim($_POST['login'] ?? '');
$password = $_POST['password'] ?? '';
$rateLimitIdentifier = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower($login);

// Limit failed credentials only. Successful logins must never consume the quota.
enforceFailedLoginRateLimit('member_login', $rateLimitIdentifier, 8, 900);

log_debug_login("login_attempt: login={$login}");

if ($login === '' || $password === '') {
    recordFailedLoginAttempt('member_login', $rateLimitIdentifier, 900);
    redirect_with_error('กรุณากรอกชื่อผู้ใช้งานหรืออีเมล และรหัสผ่าน');
}

try {
    // 1. ค้นหาในตาราง users (สมาชิกทั่วไป)
    $query = 'SELECT id, firstname, lastname, username, email, created_at, password_hash FROM users WHERE (username = :login OR email = :login) AND deleted_at IS NULL LIMIT 1';
    $stmt = $conn->prepare($query);
    $stmt->execute([':login' => $login]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        $stored = trim((string) $member['password_hash']);
        $stored = preg_replace('/[\x00-\x1F\x7F]+/', '', $stored);

        if (passwordMatchesStoredValue($stored, $password)) {
            clearFailedLoginRateLimit('member_login', $rateLimitIdentifier);
            unset($member['password_hash']);
            log_debug_login("member_login_success: id={$member['id']} login={$login}");
            session_regenerate_id(true);
            $_SESSION['member'] = $member;
            $_SESSION['auth'] = [
                'type' => 'member',
                'id' => (int) $member['id'],
                'user_id' => (int) $member['id'],
                'name' => trim((string) ($member['firstname'] ?? $member['username'] ?? '')),
            ];
            if (!password_get_info($stored)['algo']) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmtHash = $conn->prepare('UPDATE users SET password_hash = :hash WHERE id = :id LIMIT 1');
                $stmtHash->execute([':hash' => $hash, ':id' => (int) $member['id']]);
            }
            header('Location: ' . appUrl('Front-end/data/member.php'));
            exit;
        }
    }

// 2. ค้นหาในตาราง admin ด้วย schema เดียวกับระบบหลังบ้าน
    $stmt2 = $conn->prepare('SELECT admin_id, username, password, name, email FROM admin WHERE username = :login OR email = :login LIMIT 1');
    $stmt2->execute([':login' => $login]);
    $admin = $stmt2->fetch(PDO::FETCH_ASSOC);

    if ($admin) {
        log_debug_login("admin_lookup: found admin_id={$admin['admin_id']} for login={$login}");

        $stored = trim((string) $admin['password']);
        $stored = preg_replace('/[\x00-\x1F\x7F]+/', '', $stored); // ลบอักขระซ่อน

        if (passwordMatchesStoredValue($stored, $password)) {
            clearFailedLoginRateLimit('member_login', $rateLimitIdentifier);
            log_debug_login("admin_login_success: admin_id={$admin['admin_id']} login={$login}");
            session_regenerate_id(true);
            $_SESSION['admin'] = [
                'admin_id' => (int) $admin['admin_id'],
                'firstname' => $admin['name'] ?? '',
                'lastname' => '',
                'email' => $admin['email'] ?? ''
            ];
            $_SESSION['auth'] = [
                'type' => 'admin',
                'id' => (int) $admin['admin_id'],
                'admin_id' => (int) $admin['admin_id'],
                'name' => trim((string) ($admin['name'] ?? '')),
            ];
            if (!password_get_info($stored)['algo']) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmtHash = $conn->prepare('UPDATE admin SET password = :hash WHERE admin_id = :id LIMIT 1');
                $stmtHash->execute([':hash' => $hash, ':id' => (int) $admin['admin_id']]);
            }
            header('Location: ' . appUrl('backend/php/srceen/index.php'));
            exit;
        }
    }

    log_debug_login("login_failed: login={$login}");
    recordFailedLoginAttempt('member_login', $rateLimitIdentifier, 900);
    redirect_with_error('ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง');

} catch (PDOException $exception) {
    log_debug_login('pdo_exception: ' . $exception->getMessage());
    redirect_with_error('เกิดปัญหาในระบบ กรุณาลองใหม่อีกครั้ง');
}
