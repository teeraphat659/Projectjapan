<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/security.php';

$memberId = (int) resolveCurrentMemberId();
if ($memberId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อน'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode((string)file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'รูปแบบข้อมูลไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($data['image']) || !is_string($data['image'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลรูปภาพไม่ครบถ้วน'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $data['image'], $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'รองรับเฉพาะรูป PNG'], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageBinary = base64_decode($matches[1], true);
if ($imageBinary === false || $imageBinary === '' || strlen($imageBinary) > 6 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ขนาดรูปภาพไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageInfo = @getimagesizefromstring($imageBinary);
if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== 'image/png') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลรูปภาพไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถสร้างโฟลเดอร์เก็บรูปได้'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fileName = 'assisted_' . (int)$memberId . '_' . bin2hex(random_bytes(12)) . '.png';
$filePath = $uploadDir . $fileName;
if (file_put_contents($filePath, $imageBinary, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถบันทึกไฟล์ภาพได้'], JSON_UNESCAPED_UNICODE);
    exit;
}

// This legacy endpoint stores the image only; database attempts are handled by writing_progress.php.
echo json_encode([
    'status' => 'success',
    'message' => 'บันทึกภาพสำเร็จ',
    'filename' => $fileName,
], JSON_UNESCAPED_UNICODE);
?>