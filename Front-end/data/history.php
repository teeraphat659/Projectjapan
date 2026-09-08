<?php
session_start();

if (!isset($_SESSION['member'])) {
    header('Location: ../sceens/login.html');
    exit;
}

$member = $_SESSION['member'];
require_once __DIR__ . '/member_page.php';
renderMemberPage(__DIR__ . '/../sceens/history.html', $member);
?>
