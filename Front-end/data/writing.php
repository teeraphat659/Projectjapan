<?php
session_start();

if (!isset($_SESSION['member'])) {
    if (isset($_GET['mode']) && $_GET['mode'] === 'user') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['name' => 'ผู้ใช้', 'authorized' => false]);
        exit;
    }

    if (isset($_GET['mode']) && $_GET['mode'] === 'check') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['authorized' => false]);
        exit;
    }

    header('Location: ../sceens/login.html');
    exit;
}

$member = $_SESSION['member'];
$displayName = (string)($member['firstname'] ?? $member['username'] ?? 'ผู้ใช้');

if (isset($_GET['mode']) && $_GET['mode'] === 'user') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['name' => $displayName, 'authorized' => true]);
    exit;
}

if (isset($_GET['mode']) && $_GET['mode'] === 'check') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['authorized' => true]);
    exit;
}

require_once __DIR__ . '/member_page.php';
renderMemberPage(__DIR__ . '/../sceens/writing.html', $member);
?>
