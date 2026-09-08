<?php
require_once __DIR__ . '/../protect.php';
header('Content-Type: text/html; charset=UTF-8');
$template = file_get_contents(__DIR__ . '/../../srceen/manage_quiz.html');
echo strtr($template, ['{{pageTitle}}' => 'จัดการแบบทดสอบตัวเลือก', '{{heading}}' => 'ชุดแบบทดสอบตัวเลือก', '{{quizKind}}' => 'choice', '{{createUrl}}' => '/projectjapan/backend/php/srceen/create_choice_quiz.php']);
exit;
