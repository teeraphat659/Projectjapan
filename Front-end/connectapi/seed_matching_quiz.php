<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้ใช้ผ่าน CLI เท่านั้น\n");
}

require_once __DIR__ . '/db.php';

$sql = file_get_contents(__DIR__ . '/seed_matching_quiz.sql');
if ($sql === false) {
    http_response_code(500);
    exit("ไม่พบไฟล์ seed_matching_quiz.sql\n");
}

try {
    $conn->exec($sql);
    $statement = $conn->query(
        "SELECT s.quiz_set_id, s.title, COUNT(q.quiz_set_question_id) AS question_count
         FROM quiz_sets s
         LEFT JOIN quiz_set_questions q ON q.quiz_set_id = s.quiz_set_id
         WHERE s.title = 'จับคู่คาตาคานะพื้นฐาน 10 ข้อ'
         GROUP BY s.quiz_set_id, s.title
         ORDER BY s.quiz_set_id DESC
         LIMIT 1"
    );
    $result = $statement->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $result
        ? "สร้างชุด {$result['title']} จำนวน {$result['question_count']} ข้อ เรียบร้อยแล้ว\n"
        : "ไม่พบชุดแบบทดสอบหลังนำเข้า\n";
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('seed_matching_quiz.php: ' . $exception->getMessage());
    echo "นำเข้าชุดแบบทดสอบไม่สำเร็จ\n";
}
