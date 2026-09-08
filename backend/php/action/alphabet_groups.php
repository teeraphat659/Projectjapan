<?php
header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/../../../Front-end/connectapi/db.php';
if (!file_exists($dbPath)) {
    echo json_encode([
        'success' => false,
        'message' => 'ไม่พบไฟล์ db.php ในเส้นทางที่กำหนด'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

require_once $dbPath;

try {
    $action = $_GET['action'] ?? 'get_groups';

    if ($action === 'get_groups') {
        $sql = "SELECT group_id, group_code, group_name_th, sort_order
                FROM alphabet_groups
                ORDER BY sort_order ASC, group_id ASC";

        if (!isset($conn) || !($conn instanceof PDO)) {
            throw new RuntimeException('ไม่พบการเชื่อมต่อ PDO ใน db.php');
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $groups
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode([
        'success' => false,
        'message' => 'Action ไม่ถูกต้อง'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('alphabet groups failed: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'ไม่สามารถโหลดข้อมูลหมวดหมู่ได้'
    ], JSON_UNESCAPED_UNICODE);
}
