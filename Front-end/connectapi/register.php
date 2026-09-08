<?php
session_start();
require_once __DIR__ . '/db.php';

function redirect_with_error($message) {
    header('Location: ../sceens/register.html?error=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../sceens/register.html');
    exit;
}

$firstname = trim($_POST['firstname'] ?? '');
$lastname = trim($_POST['lastname'] ?? '');
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if ($firstname === '' || $lastname === '' || $username === '' || $email === '' || $password === '' || $confirmPassword === '') {
    redirect_with_error('กรุณากรอกข้อมูลให้ครบถ้วน');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_error('รูปแบบอีเมลไม่ถูกต้อง');
}

if ($password !== $confirmPassword) {
    redirect_with_error('รหัสผ่านทั้งสองช่องไม่ตรงกัน');
}

if (mb_strlen($password) < 8) {
    redirect_with_error('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
}

try {
    $checkStmt = $conn->prepare('SELECT id FROM users WHERE (username = :username OR email = :email) AND deleted_at IS NULL LIMIT 1');
    $checkStmt->execute([
        ':username' => $username,
        ':email' => $email,
    ]);

    if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
        redirect_with_error('ชื่อผู้ใช้งานหรืออีเมลนี้ถูกใช้งานแล้ว');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insertStmt = $conn->prepare('INSERT INTO users (firstname, lastname, username, email, password_hash, deleted_at) VALUES (:firstname, :lastname, :username, :email, :password_hash, NULL)');
    $insertStmt->execute([
        ':firstname' => $firstname,
        ':lastname' => $lastname,
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $passwordHash,
    ]);

    header('Location: ../sceens/login.html?success=registered');
    exit;

} catch (PDOException $exception) {
    redirect_with_error('เกิดข้อผิดพลาดระหว่างบันทึกข้อมูล');
}