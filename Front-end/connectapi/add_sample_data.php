<?php
/**
 * ไฟล์นี้ใช้สำหรับทดสอบและเพิ่มข้อมูลตัวอย่างเท่านั้น
 * ลบหรือเก็บไว้หลังจากทดสอบเสร็จแล้ว
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must run from CLI.\n");
}

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['member'])) {
    die('ยังไม่ได้เข้าสู่ระบบ');
}

$member_id = $_SESSION['member']['id'];

// สร้างตารางถ้ายังไม่มี
try {
    $createQuizzesTable = "CREATE TABLE IF NOT EXISTS quizzes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        quiz_type VARCHAR(100) NOT NULL,
        score INT NOT NULL,
        total_questions INT NOT NULL,
        percentage FLOAT NOT NULL,
        time_taken INT DEFAULT 0,
        answer_details LONGTEXT NULL,
        completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->exec($createQuizzesTable);
    echo "✓ ตารางถูกสร้างแล้ว<br>";
} catch (PDOException $e) {
    echo "✓ ตารางมีอยู่แล้ว<br>";
}

// เพิ่มข้อมูลตัวอย่าง
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sample'])) {
    $sample_tests = [
        ['quiz_type' => 'ตารางตัวอักษร Hiragana', 'score' => 8, 'total' => 10, 'time' => 120],
        ['quiz_type' => 'ตารางตัวอักษร Katakana', 'score' => 9, 'total' => 10, 'time' => 105],
        ['quiz_type' => 'แบบทดสอบจับคู่', 'score' => 7, 'total' => 10, 'time' => 150],
        ['quiz_type' => 'ตารางตัวอักษร Hiragana', 'score' => 10, 'total' => 10, 'time' => 90],
        ['quiz_type' => 'แบบทดสอบจับคู่', 'score' => 6, 'total' => 10, 'time' => 180],
    ];

    try {
        foreach ($sample_tests as $test) {
            $percentage = ($test['score'] / $test['total']) * 100;
            $stmt = $conn->prepare("
                INSERT INTO quizzes (user_id, quiz_type, score, total_questions, percentage, time_taken)
                VALUES (:user_id, :quiz_type, :score, :total_questions, :percentage, :time_taken)
            ");

            $stmt->execute([
                ':user_id' => $member_id,
                ':quiz_type' => $test['quiz_type'],
                ':score' => $test['score'],
                ':total_questions' => $test['total'],
                ':percentage' => $percentage,
                ':time_taken' => $test['time']
            ]);
        }
        echo "<div style='color: green; padding: 10px; background: #d4edda; border-radius: 5px; margin: 10px 0;'>";
        echo "✓ เพิ่มข้อมูลตัวอย่าง 5 รายการเรียบร้อย<br>";
        echo "ไปที่ <a href='../data/member.php'>หน้า Dashboard</a> เพื่อดูผล";
        echo "</div>";
    } catch (PDOException $e) {
        echo "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 5px;'>";
        echo "✗ เกิดข้อผิดพลาด: " . $e->getMessage();
        echo "</div>";
    }
}

// ดึงข้อมูลทดสอบที่มีอยู่
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM quizzes WHERE user_id = :user_id");
$stmt->execute([':user_id' => $member_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_tests = $result['total'];

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เพิ่มข้อมูลตัวอย่าง - JP Learning</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 40px 20px;
            max-width: 500px;
            margin: 0 auto;
        }
        .container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
        }
        .info {
            background: #f0f8ff;
            border: 1px solid #87ceeb;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            color: #333;
        }
        .button {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 0;
            width: 100%;
        }
        .button:hover {
            background: #0056b3;
        }
        a {
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ เพิ่มข้อมูลตัวอย่าง</h1>
        
        <div class="info">
            <strong>สถานะปัจจุบัน:</strong><br>
            คุณมีประวัติการทดสอบ <strong><?php echo $total_tests; ?></strong> รายการ
        </div>

        <p>กดปุ่มด้านล่างเพื่อเพิ่มข้อมูลตัวอย่าง 5 รายการเพื่อทดสอบระบบ</p>

        <form method="POST">
            <button type="submit" name="add_sample" class="button">
                ➕ เพิ่มข้อมูลตัวอย่าง (5 รายการ)
            </button>
        </form>

        <hr style="margin: 30px 0;">

        <p style="color: #666; font-size: 14px;">
            หลังจากเพิ่มข้อมูลแล้ว ไปที่ <a href="data/member.php">หน้า Dashboard</a> เพื่อดูสถิติและประวัติการทดสอบ
        </p>

        <p style="color: #999; font-size: 12px;">
            💡 ไฟล์นี้ใช้เพื่อการทดสอบเท่านั้น ลบออกหลังจากทดสอบเสร็จแล้ว
        </p>
    </div>
</body>
</html>
