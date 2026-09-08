<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/app_config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

$member = $_SESSION['member'] ?? null;
$admin = $_SESSION['admin'] ?? null;
$auth = currentAuth();
$loggedIn = !empty($member) || !empty($admin) || $auth['id'] > 0;
$username = '';
$dashboardUrl = '';
$role = '';

if (!empty($admin)) {
    $username = $admin['firstname'] ?? $admin['username'] ?? $admin['Admin_Name'] ?? 'Admin';
    $dashboardUrl = appUrl('backend/php/srceen/index.php');
    $role = 'admin';
} elseif (!empty($member)) {
    $username = $member['firstname'] ?? $member['username'] ?? 'ผู้ใช้';
    $dashboardUrl = '../data/member.php';
    $role = 'member';
}

echo json_encode([
    'loggedIn' => $loggedIn,
    'username' => $username,
    'email' => !empty($admin) ? ($admin['email'] ?? '') : (!empty($member) ? ($member['email'] ?? '') : ''),
    'dashboardUrl' => $dashboardUrl,
    'role' => $role,
    'guest' => !$loggedIn
], JSON_UNESCAPED_UNICODE);
?>
