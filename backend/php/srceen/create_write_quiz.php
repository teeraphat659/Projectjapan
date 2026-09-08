<?php
require_once __DIR__ . '/../protect.php';
header('Content-Type: text/html; charset=UTF-8');
$template = file_get_contents(__DIR__ . '/../../srceen/create_write_quiz.html');
echo strtr($template, ['{{pageTitle}}' => 'สร้างแบบทดสอบการเขียน', '{{heading}}' => 'สร้างแบบทดสอบการเขียนใหม่', '{{quizKind}}' => 'writing', '{{manageUrl}}' => '/projectjapan/backend/php/srceen/manage_write_quiz.php']);
exit;
