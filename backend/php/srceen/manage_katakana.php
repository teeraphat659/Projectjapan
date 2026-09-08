<?php
require_once __DIR__ . '/../protect.php';
header('Content-Type: text/html; charset=UTF-8');
readfile(__DIR__ . '/../../srceen/manage_katakana.html');
exit;
