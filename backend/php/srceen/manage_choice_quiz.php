<?php
require_once __DIR__ . '/../protect.php';
header('Content-Type: text/html; charset=UTF-8');
renderAdminTemplate(__DIR__ . '/../../srceen/manage_quiz.html', [
    '{{pageTitle}}' => 'จัดการแบบทดสอบตัวเลือก',
    '{{heading}}' => 'ชุดแบบทดสอบตัวเลือก',
    '{{quizKind}}' => 'choice',
    '{{createUrl}}' => '/projectjapan/backend/php/srceen/create_choice_quiz.php',
]);
exit;
