<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
securityStartSession();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';

function dashboardResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function formatWritingTitle(array $row): string
{
    $type = strtolower((string)($row['writing_type'] ?? '')) === 'katakana' ? 'Katakana' : 'Hiragana';
    $group = trim((string)($row['group_name'] ?? ''));
    return 'แบบทดสอบเขียน ' . $type . ($group !== '' ? ' — ' . $group : '');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    dashboardResponse(405, ['success' => false, 'message' => 'รองรับเฉพาะคำขอ GET']);
}

$userId = resolveCurrentMemberId();
if ($userId <= 0) {
    dashboardResponse(401, ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน']);
}

try {
    $userStatement = $conn->prepare(
        'SELECT firstname, username, email, created_at
         FROM users
         WHERE id = :user_id
         LIMIT 1'
    );
    $userStatement->execute([':user_id' => $userId]);
    $userRecord = $userStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $quizStatsStatement = $conn->prepare(
        'SELECT COUNT(*) AS total_tests,
                COALESCE(AVG(percentage), 0) AS average_score,
                COALESCE(SUM(score), 0) AS total_points
         FROM quizzes WHERE user_id = :user_id'
    );
    $quizStatsStatement->execute([':user_id' => $userId]);
    $quizStats = $quizStatsStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $writingStatsStatement = $conn->prepare(
        'SELECT COUNT(*) AS total_tests,
                COALESCE(SUM(CASE WHEN total_characters > 0 AND completed_characters >= total_characters THEN 1 ELSE 0 END), 0) AS completed_tests
         FROM writing_sessions
         WHERE user_id = :user_id
           AND practice_mode = :practice_mode
           AND completed_at IS NOT NULL'
    );
    $writingStatsStatement->execute([
        ':user_id' => $userId,
        ':practice_mode' => 'test',
    ]);
    $writingStats = $writingStatsStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $quizHistoryStatement = $conn->prepare(
        'SELECT id as quiz_id, quiz_type, score, total_questions, completed_at
         FROM quizzes
         WHERE user_id = :user_id
         ORDER BY completed_at DESC, id DESC
         LIMIT 5'
    );
    $quizHistoryStatement->execute([':user_id' => $userId]);
    $recentTests = [];
    foreach ($quizHistoryStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentTests[] = [
            'quiz_id' => $row['quiz_id'],
            'title' => $row['quiz_type'] ?: 'แบบทดสอบจับคู่',
            'score' => (int)$row['score'],
            'maxScore' => (int)$row['total_questions'],
            'date' => $row['completed_at'],
            'url' => '../data/matchhistorydetail.php?quiz_id=' . rawurlencode((string)$row['quiz_id']),
        ];
    }

    $writingHistoryStatement = $conn->prepare(
        'SELECT writing_session_id, writing_type, group_name, completed_characters, total_characters, completed_at
         FROM writing_sessions
         WHERE user_id = :user_id
           AND practice_mode = :practice_mode
           AND completed_at IS NOT NULL
         ORDER BY completed_at DESC, writing_session_id DESC
         LIMIT 5'
    );
    $writingHistoryStatement->execute([
        ':user_id' => $userId,
        ':practice_mode' => 'test',
    ]);
    foreach ($writingHistoryStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentTests[] = [
            'title' => formatWritingTitle($row),
            'score' => (int)$row['completed_characters'],
            'maxScore' => (int)$row['total_characters'],
            'date' => $row['completed_at'],
            'url' => '../data/writingresult.php?session_id=' . rawurlencode((string)$row['writing_session_id']),
        ];
    }

    usort($recentTests, static function (array $left, array $right): int {
        return strcmp((string)$right['date'], (string)$left['date']);
    });
    $recentTests = array_slice($recentTests, 0, 5);

    $firstname = (string)($userRecord['firstname'] ?? $_SESSION['member']['firstname'] ?? $_SESSION['member']['username'] ?? 'ผู้ใช้งาน');
    $username = (string)($userRecord['username'] ?? $_SESSION['member']['username'] ?? '');
    $email = (string)($userRecord['email'] ?? $_SESSION['member']['email'] ?? '');
    $joinedDate = !empty($userRecord['created_at'])
        ? date('d/m/Y', strtotime((string)$userRecord['created_at']))
        : '';
    dashboardResponse(200, [
        'success' => true,
        'data' => [
            'user' => [
                'firstname' => $firstname,
                'username' => $username,
                'email' => $email,
                'joinedDate' => $joinedDate,
                'navUsername' => $firstname,
            ],
            'stats' => [
                'totalTests' => (int)($quizStats['total_tests'] ?? 0) + (int)($writingStats['total_tests'] ?? 0),
                'completedTests' => (int)($quizStats['total_tests'] ?? 0) + (int)($writingStats['completed_tests'] ?? 0),
                'averageScore' => round((float)($quizStats['average_score'] ?? 0), 1),
                'totalPoints' => (int)($quizStats['total_points'] ?? 0),
            ],
            'recentTests' => $recentTests,
        ],
    ]);
} catch (PDOException $exception) {
    error_log('dashboard_stats.php: ' . $exception->getMessage());
    dashboardResponse(500, ['success' => false, 'message' => 'ไม่สามารถคำนวณข้อมูลหน้า Member ได้']);
}
