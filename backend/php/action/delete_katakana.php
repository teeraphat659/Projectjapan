<?php
require_once __DIR__ . '/../protect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . appUrl('backend/php/srceen/manage_katakana.php?error=method'));
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    header('Location: ' . appUrl('backend/php/srceen/manage_katakana.php?error=1'));
    exit;
}

try {
    $delete = $conn->prepare('DELETE FROM japanese_characters WHERE character_id = :id AND LOWER(char_type) = LOWER(:char_type)');
    $delete->execute([':id' => $id, ':char_type' => 'katakana']);
    header('Location: ' . appUrl('backend/php/srceen/manage_katakana.php?success=deleted'));
    exit;
} catch (PDOException $exception) {
    header('Location: ' . appUrl('backend/php/srceen/manage_katakana.php?error=1'));
    exit;
}
