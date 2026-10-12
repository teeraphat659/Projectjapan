<?php
require_once __DIR__ . '/../protect.php';
header('Content-Type: text/html; charset=UTF-8');
renderAdminTemplate(__DIR__ . '/../../srceen/manage_write_quiz.html', [
    '{{pageTitle}}' => 'จัดการแบบทดสอบการเขียน',
    '{{heading}}' => 'ชุดแบบทดสอบข้อเขียน',
    '{{quizKind}}' => 'writing',
    '{{createUrl}}' => '/projectjapan/backend/php/srceen/create_write_quiz.php',
]);
exit;
