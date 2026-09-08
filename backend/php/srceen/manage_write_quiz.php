<?php
require_once __DIR__ . '/../protect.php';
header('Content-Type: text/html; charset=UTF-8');
$template = file_get_contents(__DIR__ . '/../../srceen/manage_write_quiz.html');
echo strtr($template, ['{{pageTitle}}' => 'จัดการแบบทดสอบการเขียน', '{{heading}}' => 'ชุดแบบทดสอบข้อเขียน', '{{quizKind}}' => 'writing', '{{createUrl}}' => '/projectjapan/backend/php/srceen/create_write_quiz.php']);
exit;
