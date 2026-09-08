<?php
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();
// If the request is an XHR/fetch (expects JSON), return JSON instead of redirect
$isXhr = false;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $isXhr = true;
}
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
if (!$isXhr && stripos($accept, 'application/json') !== false) $isXhr = true;

if ($isXhr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'loggedOut' => true], JSON_UNESCAPED_UNICODE);
    exit;
} else {
// แก้ไขจากเดิม
header('Location: ../sceens/login.html?success=loggedout');
exit;
}
