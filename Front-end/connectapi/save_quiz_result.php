<?php
require_once __DIR__ . '/security.php';
securityStartSession();
addSecurityHeaders();
header('Content-Type: application/json; charset=UTF-8');

$userId = resolveCurrentMemberId();
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ไม่พบรหัสผู้ใช้ใน session'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

requireSameOriginRequest();

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'ข้อมูลไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}

$required = ['quiz_type', 'score', 'total_questions'];
foreach ($required as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "ขาดฟิลด์: $field"], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

require_once __DIR__ . '/db.php';

try {
    // ยึดผู้ใช้จาก session เท่านั้น เพื่อป้องกันการปลอมผลทดสอบให้บัญชีอื่น
    $user_id = $userId;
    $quiz_type = trim($data['quiz_type']);
    $score = intval($data['score']);
    $total_questions = intval($data['total_questions']);
    $time_taken = intval($data['time_taken'] ?? 0);
    $percentage = ($total_questions > 0) ? ($score / $total_questions) * 100 : 0;
    $answer_details = null;
    if (isset($data['answer_details']) && is_array($data['answer_details'])) {
        $answer_details = json_encode($data['answer_details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($answer_details === false) {
            throw new InvalidArgumentException('รายละเอียดคำตอบไม่ถูกต้อง');
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO quizzes (user_id, quiz_type, score, total_questions, percentage, time_taken, answer_details)
        VALUES (:user_id, :quiz_type, :score, :total_questions, :percentage, :time_taken, :answer_details)
    ");

    $stmt->execute([
        ':user_id' => $user_id,
        ':quiz_type' => $quiz_type,
        ':score' => $score,
        ':total_questions' => $total_questions,
        ':percentage' => $percentage,
        ':time_taken' => $time_taken,
        ':answer_details' => $answer_details
    ]);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'บันทึกผลการทดสอบเรียบร้อย',
        'quiz_id' => (int) $conn->lastInsertId(),
        'user_id' => $user_id,
        'percentage' => round($percentage, 1)
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'เกิดข้อผิดพลาด: ' . $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
