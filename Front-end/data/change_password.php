<?php
require_once __DIR__ . '/../connectapi/security.php';
securityStartSession();
addSecurityHeaders();
if (!isset($_SESSION['member'])) {
    header('Location: ../sceens/login.html');
    exit;
}

require_once __DIR__ . '/../connectapi/db.php';
$member = $_SESSION['member'];

$statusMessage = '';
$statusType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $statusMessage = '<div class="member-alert error">กรุณากรอกข้อมูลให้ครบถ้วน</div>';
        $statusType = 'error';
    } elseif (mb_strlen($newPassword) < 8) {
        $statusMessage = '<div class="member-alert error">รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร</div>';
        $statusType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $statusMessage = '<div class="member-alert error">ยืนยันรหัสผ่านไม่ตรงกับรหัสผ่านใหม่</div>';
        $statusType = 'error';
    } else {
        try {
            $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $member['id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($currentPassword, (string)$user['password_hash'])) {
                $statusMessage = '<div class="member-alert error">รหัสผ่านปัจจุบันไม่ถูกต้อง</div>';
                $statusType = 'error';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $conn->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $update->execute([':hash' => $newHash, ':id' => $member['id']]);
                $statusMessage = '<div class="member-alert success">เปลี่ยนรหัสผ่านเรียบร้อยแล้ว</div>';
                $statusType = 'success';
            }
        } catch (PDOException $e) {
            error_log('change_password error: ' . $e->getMessage());
            $statusMessage = '<div class="member-alert error">เกิดข้อผิดพลาดในการอัปเดตรหัสผ่าน</div>';
            $statusType = 'error';
        }
    }
}

$templatePath = __DIR__ . '/../sceens/change_password.html';
$template = file_get_contents($templatePath);
if ($template === false) {
    http_response_code(500);
    exit('Unable to load change password template.');
}

$displayName = htmlspecialchars((string)($member['firstname'] ?? $member['username'] ?? 'ผู้ใช้งาน'), ENT_QUOTES, 'UTF-8');
$template = str_replace('{{navUsername}}', $displayName, $template);
$template = str_replace('{{statusMessage}}', $statusMessage, $template);

require_once __DIR__ . '/member_page.php';
renderMemberPage($templatePath, $member, [
    '{{navUsername}}' => $displayName,
    '{{statusMessage}}' => $statusMessage,
    '{{csrfToken}}' => htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'),
]);
