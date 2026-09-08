<?php
require_once __DIR__ . '/../protect.php';
header('Content-Type: text/html; charset=UTF-8');
$template = file_get_contents(__DIR__ . '/../../srceen/create_quiz.html');
echo strtr($template, ['{{pageTitle}}' => 'สร้างแบบทดสอบตัวเลือก', '{{heading}}' => 'สร้างแบบทดสอบตัวเลือกใหม่', '{{quizKind}}' => 'choice', '{{manageUrl}}' => '/projectjapan/backend/php/srceen/manage_choice_quiz.php']);
exit;
