<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/app_config.php';
addSecurityHeaders();

// Database settings come from the server environment; the defaults keep local XAMPP usable.
$host = getenv('PROJECTJAPAN_DB_HOST') ?: 'localhost';
$db_name = getenv('PROJECTJAPAN_DB_NAME') ?: 'jp_learning';
$username = getenv('PROJECTJAPAN_DB_USER') ?: 'root';
$password = getenv('PROJECTJAPAN_DB_PASSWORD') ?: '';

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name . ";charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(array("error" => "ไม่สามารถเชื่อมต่อฐานข้อมูลได้"), JSON_UNESCAPED_UNICODE);
    exit(); 
}
?>