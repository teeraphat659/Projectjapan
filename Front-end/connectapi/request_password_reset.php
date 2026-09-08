<?php
require_once __DIR__ . '/security.php';
securityStartSession();
addSecurityHeaders();
require_once __DIR__ . '/db.php';

function recovery_redirect($query) {
    header('Location: ../sceens/forgot_password.html?' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    recovery_redirect('error=' . rawurlencode('ไม่รองรับคำขอนี้'));
}

enforceRateLimit('password_reset', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 900);

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    recovery_redirect('error=' . rawurlencode('กรุณากรอกอีเมลให้ถูกต้อง'));
}

try {
    $stmt = $conn->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $userId = $stmt->fetchColumn();

    if ($userId) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 1800);

        $conn->beginTransaction();
        $delete = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id OR expires_at < NOW()');
        $delete->execute([':user_id' => (int) $userId]);
        $insert = $conn->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
        $insert->execute([
            ':user_id' => (int) $userId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);
        $conn->commit();

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resetUrl = $scheme . '://' . $host . appUrl('Front-end/sceens/reset_password.html') . '?token=' . rawurlencode($token);
        $subject = 'ลิงก์ตั้งรหัสผ่านใหม่สำหรับ JP Notes';
        $message = "สวัสดี\n\nมีคำขอตั้งรหัสผ่านใหม่สำหรับบัญชี JP Notes ของคุณ\nเปิดลิงก์นี้ภายใน 30 นาที:\n{$resetUrl}\n\nหากคุณไม่ได้เป็นผู้ขอ สามารถละเว้นอีเมลนี้ได้\n";
        $headers = "From: " . (getenv('PROJECTJAPAN_MAIL_FROM') ?: 'no-reply@jpnotes.local') . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers);
    }
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('password reset request failed: ' . $exception->getMessage());
}

recovery_redirect('sent=1');
