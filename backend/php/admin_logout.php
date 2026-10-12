<?php
require_once __DIR__ . '/../../Front-end/connectapi/app_config.php';

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
header('Location: ' . appUrl('Front-end/sceens/login.html?success=' . rawurlencode('ออกจากระบบเรียบร้อยแล้ว')));
exit;
