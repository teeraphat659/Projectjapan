<?php
session_start();
if (!isset($_SESSION['member'])) {
    header('Location: ../sceens/login.html');
    exit;
}

$member = $_SESSION['member'];
require_once __DIR__ . '/../connectapi/db.php';

function getMemberStats(PDO $conn, int $userId): array {
    $stats = [
        'totalTests' => 0,
        'passedTests' => 0,
        'averageScore' => 0,
    ];

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM quizzes WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);
    $stats['totalTests'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $stmt = $conn->prepare('SELECT COUNT(*) AS passed FROM quizzes WHERE user_id = :user_id AND percentage >= 70');
    $stmt->execute([':user_id' => $userId]);
    $stats['passedTests'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['passed'] ?? 0);

    $stmt = $conn->prepare('SELECT AVG(percentage) AS avg_score FROM quizzes WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);
    $stats['averageScore'] = round((float)($stmt->fetch(PDO::FETCH_ASSOC)['avg_score'] ?? 0), 1);

    return $stats;
}

$stats = getMemberStats($conn, (int)$member['id']);
$displayName = htmlspecialchars((string)($member['firstname'] ?? $member['username'] ?? 'ผู้ใช้งาน'), ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars((string)($member['username'] ?? '-'), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string)($member['email'] ?? '-'), ENT_QUOTES, 'UTF-8');
$joinedAtValue = (string)($member['created_at'] ?? '');
if ($joinedAtValue === '') {
    $joinedStatement = $conn->prepare('SELECT created_at FROM users WHERE id = :user_id LIMIT 1');
    $joinedStatement->execute([':user_id' => (int)$member['id']]);
    $joinedAtValue = (string)($joinedStatement->fetchColumn() ?: '');
}
$joinedAt = $joinedAtValue !== '' ? date('d/m/Y', strtotime($joinedAtValue)) : 'ไม่ระบุ';
$statsPayload = [
    '{{navUsername}}' => $displayName,
    '{{memberName}}' => $displayName,
    '{{memberUsername}}' => $username,
    '{{memberEmail}}' => $email,
    '{{memberJoined}}' => $joinedAt,
    '{{statTotalTests}}' => (string)$stats['totalTests'],
    '{{statPassedTests}}' => (string)$stats['passedTests'],
    '{{statAverageScore}}' => (string)$stats['averageScore'],
];

$templatePath = __DIR__ . '/../sceens/profile.html';
$template = file_get_contents($templatePath);
if ($template === false) {
    http_response_code(500);
    exit('Unable to load profile page template.');
}

require_once __DIR__ . '/member_page.php';
renderMemberPage($templatePath, $member, $statsPayload);
