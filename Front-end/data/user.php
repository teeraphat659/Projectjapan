<?php
session_start();
if (!isset($_SESSION['member'])) {
    header('Location: ../sceens/login.html');
    exit;
}
$member = $_SESSION['member'];
require_once __DIR__ . '/../connectapi/db.php';

// ดึงข้อมูลสถิติของผู้ใช้
$stats = [
    'totalTests' => 0,
    'passedTests' => 0,
    'averageScore' => 0,
    'totalPoints' => 0,
    'recentTests' => []
];

try {
    // นับแบบทดสอบทั้งหมด
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM quizzes WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $member['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['totalTests'] = $result['total'] ?? 0;
    
    // นับแบบทดสอบที่ผ่าน (score >= 70%)
    $stmt = $conn->prepare("SELECT COUNT(*) as passed FROM quizzes WHERE user_id = :user_id AND percentage >= 70");
    $stmt->execute([':user_id' => $member['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['passedTests'] = $result['passed'] ?? 0;
    
    // คำนวณคะแนนเฉลี่ย
    $stmt = $conn->prepare("SELECT AVG(percentage) as avg_score FROM quizzes WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $member['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['averageScore'] = round($result['avg_score'] ?? 0, 1);
    
    // คำนวณคะแนนสะสม (รวมจากคะแนนทั้งหมด)
    $stmt = $conn->prepare("SELECT SUM(score) as total_score FROM quizzes WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $member['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['totalPoints'] = $result['total_score'] ?? 0;
    
    // ดึงประวัติการทดสอบล่าสุด 5 อันดับ
    $stmt = $conn->prepare("SELECT quiz_type, score, total_questions, percentage, time_taken, completed_at FROM quizzes WHERE user_id = :user_id ORDER BY completed_at DESC LIMIT 5");
    $stmt->execute([':user_id' => $member['id']]);
    $stats['recentTests'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
}

$templatePath = __DIR__ . '/../sceens/member.html';
$template = file_get_contents($templatePath);

if ($template === false) {
    http_response_code(500);
    exit('Unable to load user page template.');
}

$recentTestsHtml = '';
if (!empty($stats['recentTests'])) {
    $rows = '';
    foreach ($stats['recentTests'] as $test) {
        $quizType = htmlspecialchars($test['quiz_type'] ?? 'แบบทดสอบ', ENT_QUOTES, 'UTF-8');
        $score = (int)($test['score'] ?? 0);
        $totalQuestions = (int)($test['total_questions'] ?? 0);
        $percentage = round((float)($test['percentage'] ?? 0), 1);
        $timeTaken = !empty($test['time_taken']) ? (int)$test['time_taken'] . 's' : '-';
        $completedAt = date('d/m/Y H:i', strtotime($test['completed_at']));
        $badgeColor = $percentage >= 70 ? '#d4edda' : '#f8d7da';
        $badgeTextColor = $percentage >= 70 ? '#155724' : '#721c24';

        $rows .= sprintf(
            '<tr style="border-bottom: 1px solid #eee; transition: background-color 0.3s ease;">' .
            '<td style="padding: 15px; color: var(--text-main);">%s</td>' .
            '<td style="text-align: center; padding: 15px; font-weight: 600;">%d / %d</td>' .
            '<td style="text-align: center; padding: 15px;">' .
            '<span style="background: %s; color: %s; padding: 6px 12px; border-radius: 8px; font-weight: 600;">%s%%</span>' .
            '</td>' .
            '<td style="text-align: center; padding: 15px;">%s</td>' .
            '<td style="text-align: right; padding: 15px; color: #999; font-size: 14px;">%s</td>' .
            '</tr>',
            $quizType,
            $score,
            $totalQuestions,
            $badgeColor,
            $badgeTextColor,
            $percentage,
            $timeTaken,
            $completedAt
        );
    }

    $recentTestsHtml = <<<HTML
        <section class="history-section" style="margin-top: 60px;">
            <h2 class="section-title">ประวัติการทดสอบล่าสุด</h2>
            <div class="history-table">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #ddd;">
                            <th style="text-align: left; padding: 15px; color: #666; font-weight: 600;">ประเภท</th>
                            <th style="text-align: center; padding: 15px; color: #666; font-weight: 600;">คะแนน</th>
                            <th style="text-align: center; padding: 15px; color: #666; font-weight: 600;">เปอร์เซ็นต์</th>
                            <th style="text-align: center; padding: 15px; color: #666; font-weight: 600;">เวลา</th>
                            <th style="text-align: right; padding: 15px; color: #666; font-weight: 600;">วันที่</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$rows}
                    </tbody>
                </table>
            </div>
        </section>
HTML;
} else {
    $recentTestsHtml = <<<HTML
        <section class="empty-state" style="margin-top: 60px; text-align: center; padding: 40px; background: var(--card-bg); border-radius: 16px; border: 1px dashed var(--border-color);">
            <i style="font-size: 48px; color: #ccc; margin-bottom: 15px; display: block;" class="fa-solid fa-clipboard"></i>
            <h3 style="color: var(--text-light); margin-bottom: 10px;">ยังไม่มีประวัติการทดสอบ</h3>
            <p style="color: #999;">เริ่มทำแบบทดสอบเพื่อดูประวัติและติดตามความก้าวหน้า</p>
        </section>
HTML;
}

$replacements = [
    '{{navUsername}}' => htmlspecialchars($member['firstname'] ?? 'ผู้ใช้', ENT_QUOTES, 'UTF-8'),
    '{{firstname}}' => htmlspecialchars($member['firstname'] ?? 'ผู้ใช้', ENT_QUOTES, 'UTF-8'),
    '{{totalTests}}' => (int)$stats['totalTests'],
    '{{passedTests}}' => (int)$stats['passedTests'],
    '{{averageScore}}' => (string)round((float)$stats['averageScore'], 1),
    '{{totalPoints}}' => (int)$stats['totalPoints'],
    '{{recentTestsHtml}}' => $recentTestsHtml,
];

require_once __DIR__ . '/member_page.php';
renderMemberPage($templatePath, $member, $replacements);
?>

