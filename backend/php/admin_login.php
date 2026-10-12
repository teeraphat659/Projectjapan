<?php
session_start();
if (isset($_SESSION['admin'])) {
  header('Location: ' . appUrl('backend/php/srceen/index.php'));
  exit;
}
$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>เข้าสู่ระบบผู้ดูแลระบบ</title>
  <style>
    body { margin: 0; font-family: 'Prompt', sans-serif; background: #f6f8fb; color: #222; }
    .login-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
    .login-card { width: min(460px, 100%); padding: 32px; border-radius: 20px; background: #ffffff; box-shadow: 0 24px 60px rgba(16, 24, 40, 0.08); }
    .login-card h1 { margin: 0 0 16px; font-size: 1.85rem; }
    .login-card p { margin: 0 0 24px; color: #667085; }
    .form-group { margin-bottom: 18px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #344054; }
    input[type="text"], input[type="password"] { width: 100%; padding: 12px 14px; border: 1px solid #d0d5dd; border-radius: 12px; background: #f8fafc; font-size: 1rem; }
    button { width: 100%; padding: 14px 16px; border: none; border-radius: 12px; background: #2563eb; color: #fff; font-size: 1rem; cursor: pointer; }
    button:hover { background: #1d4ed8; }
    .alert { margin-bottom: 18px; padding: 12px 16px; border-radius: 12px; font-size: 0.95rem; }
    .alert.error { background: #fee2e2; color: #b91c1c; }
    .alert.success { background: #dcfce7; color: #157a44; }
    .login-footer { margin-top: 20px; text-align: center; color: #667085; font-size: 0.95rem; }
    .login-footer a { color: #2563eb; text-decoration: none; }
  </style>
</head>
<body>
  <div class="login-page">
    <div class="login-card">
      <h1>เข้าสู่ระบบหลังบ้าน</h1>
      <p>กรุณาเข้าสู่ระบบด้วยบัญชีผู้ดูแลระบบที่มีสิทธิ์เข้าใช้งานหลังบ้านเท่านั้น</p>
      <?php if ($error): ?>
        <div class="alert error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <form action="admin_auth.php" method="POST" autocomplete="off">
        <div class="form-group">
          <label for="login">อีเมลผู้ดูแลระบบ</label>
          <input type="email" id="login" name="login" placeholder="admin@example.com" required autocomplete="username" />
        </div>
        <div class="form-group">
          <label for="password">รหัสผ่าน</label>
          <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password" />
        </div>
        <div class="form-group">
          <label for="entry_target">เลือกหน้าเริ่มต้น</label>
          <select id="entry_target" name="entry_target" style="width: 100%; padding: 12px 14px; border: 1px solid #d0d5dd; border-radius: 12px; background: #f8fafc; font-size: 1rem; color: #222;">
            <option value="backend">เข้าสู่หลังบ้าน (Dashboard)</option>
            <option value="front">เข้าสู่หน้า สมาชิก</option>
          </select>
        </div>
        <button type="submit">เข้าสู่ระบบ</button>
      </form>
      <div class="login-footer">หากไม่มีบัญชีผู้ดูแลระบบ ให้ติดต่อผู้ดูแลระบบหลักเพื่อสร้างบัญชีใหม่</div>
    </div>
  </div>
</body>
</html>
