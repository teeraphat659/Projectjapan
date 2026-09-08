<?php
session_start();
if (!isset($_SESSION['member'])) {
    header('Location: ../sceens/login.html');
    exit;
}

require_once __DIR__ . '/member_page.php';
renderMemberPage(__DIR__ . '/../sceens/kanatable.html', $_SESSION['member']);
?>
