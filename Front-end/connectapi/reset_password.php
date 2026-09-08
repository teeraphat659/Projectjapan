<?php
require_once __DIR__ . '/security.php';
securityStartSession();
addSecurityHeaders();
require_once __DIR__ . '/db.php';

function reset_redirect($query) {
    header('Location: ../sceens/reset_password.html?' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reset_redirect('error=' . rawurlencode('ไม่รองรับคำขอนี้'));
}

requireSameOriginRequest();
enforceRateLimit('password_reset_complete', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 900);

$token = trim((string) ($_POST['token'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    reset_redirect('error=' . rawurlencode('ลิงก์รีเซ็ตไม่ถูกต้องหรือหมดอายุแล้ว'));
}
if (mb_strlen($password) < 8) {
    reset_redirect('token=' . rawurlencode($token) . '&error=' . rawurlencode('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'));
}
if ($password !== $confirmPassword) {
    reset_redirect('token=' . rawurlencode($token) . '&error=' . rawurlencode('ยืนยันรหัสผ่านไม่ตรงกัน'));
}

try {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT id, user_id FROM password_reset_tokens WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    $stmt->execute([':token_hash' => $tokenHash]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset) {
        reset_redirect('error=' . rawurlencode('ลิงก์รีเซ็ตไม่ถูกต้องหรือหมดอายุแล้ว'));
    }

    $conn->beginTransaction();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $update = $conn->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
    $update->execute([':password_hash' => $passwordHash, ':user_id' => (int) $reset['user_id']]);
    $useToken = $conn->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
    $useToken->execute([':id' => (int) $reset['id']]);
    $conn->commit();

    session_regenerate_id(true);
    reset_redirect('token=' . rawurlencode($token) . '&success=1');
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('password reset failed: ' . $exception->getMessage());
    reset_redirect('token=' . rawurlencode($token) . '&error=' . rawurlencode('เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง'));
}
