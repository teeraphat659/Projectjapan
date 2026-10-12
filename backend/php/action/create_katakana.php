<?php
require_once __DIR__ . '/../protect.php';
require_once __DIR__ . '/character_image_upload.php';
require_once __DIR__ . '/character_sound_upload.php';
require_once __DIR__ . '/quiz_character_relink_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . appUrl('backend/php/srceen/create_katagana.php'));
    exit;
}

function normalize_group_code($group) {
    $group = trim($group);
    return preg_replace('/^\s*วรรค\s+/iu', '', $group);
}

function normalize_text($text) {
    return mb_strtolower(trim($text), 'UTF-8');
}

function is_sound_file_extension_valid($soundFile) {
    $path = parse_url(trim($soundFile), PHP_URL_PATH);
    if (!$path) {
        return false;
    }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'webm'], true);
}

function get_sound_file_name($soundFile) {
    $path = parse_url(trim($soundFile), PHP_URL_PATH);
    if (!$path) {
        return '';
    }
    return basename($path);
}

function sound_file_matches_character($soundFile, $japaneseChar, $romaji) {
    $fileName = normalize_text(get_sound_file_name($soundFile));
    $romaji = normalize_text($romaji);
    if ($romaji !== '' && mb_strpos($fileName, $romaji) !== false) {
        return true;
    }
    if ($japaneseChar !== '' && mb_strpos($fileName, normalize_text($japaneseChar)) !== false) {
        return true;
    }
    return false;
}

$japaneseChar = trim($_POST['japanese_char'] ?? '');
$charGroup = normalize_group_code($_POST['char_group'] ?? '');
$romaji = trim($_POST['romaji'] ?? '');
$strokeCount = isset($_POST['stroke_count']) ? (int)$_POST['stroke_count'] : 0;
$imageFile = trim($_POST['image_file'] ?? '');
$uploadedImage = upload_character_image('image_upload');
$imageFile = $uploadedImage !== '' ? $uploadedImage : $imageFile;
$soundFile = trim($_POST['sound_file'] ?? '');
$uploadedSound = upload_character_sound('sound_upload', '', $romaji);
$soundFile = $uploadedSound !== '' ? $uploadedSound : $soundFile;

$charType = ucfirst(strtolower(trim($_POST['char_type'] ?? 'Katakana')));
if (!in_array($charType, ['Katakana', 'Hiragana'], true)) {
    $charType = 'Katakana';
}

$allowedGroups = ['a', 'ka', 'sa', 'ta', 'na', 'ha', 'ma', 'ya', 'ra', 'wa', 'n'];

if ($japaneseChar === '' || mb_strlen($japaneseChar, 'UTF-8') !== 1 || $charGroup === '' || !in_array($charGroup, $allowedGroups, true)) {
    header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=invalid'));
    exit;
}

// ตรวจสอบความถูกต้องของตัวอักษรตามประเภท
$ord = mb_ord($japaneseChar, 'UTF-8');
$isHiragana = ($ord >= 0x3041 && $ord <= 0x3096);
$isKatakana = ($ord >= 0x30A1 && $ord <= 0x30FA) || ($ord === 0x30FC);

if ($charType === 'Katakana' && !$isKatakana) {
    header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=invalid_katakana'));
    exit;
}
if ($charType === 'Hiragana' && !$isHiragana) {
    header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=invalid_hiragana'));
    exit;
}

if (character_image_upload_error() !== '') {
    header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=image_upload_' . rawurlencode(character_image_upload_error())));
    exit;
}

if (character_sound_upload_error() !== '') {
    header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=sound_upload_' . rawurlencode(character_sound_upload_error())));
    exit;
}

$duplicateStmt = $conn->prepare('SELECT COUNT(*) FROM japanese_characters WHERE LOWER(char_type) = LOWER(:type) AND japanese_char = :japanese_char');
$duplicateStmt->execute([':type' => $charType, ':japanese_char' => $japaneseChar]);
if ((int)$duplicateStmt->fetchColumn() > 0) {
    header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=duplicate'));
    exit;
}

if ($soundFile !== '') {
    if (!is_sound_file_extension_valid($soundFile)) {
        header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=sound_ext'));
        exit;
    }
    if ($romaji === '') {
        header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=missing_romaji'));
        exit;
    }
    if ($uploadedSound === '' && !sound_file_matches_character($soundFile, $japaneseChar, $romaji)) {
        header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=sound_mismatch'));
        exit;
    }
}

// รับข้อมูลเส้น SVG และแอนิเมชันถ้ามีส่งมา
$strokesJson = trim($_POST['strokes_json'] ?? '');
$animationStyle = trim($_POST['animation_style'] ?? 'educational');
$strokeDuration = (float)($_POST['stroke_duration'] ?? 0.70);
$parsedStrokes = json_decode($strokesJson, true);

if (is_array($parsedStrokes) && count($parsedStrokes) > 0) {
    $strokeCount = count($parsedStrokes);
}

$conn->beginTransaction();
try {
    $insert = $conn->prepare('INSERT INTO japanese_characters (japanese_char, char_type, char_group, romaji, stroke_count, image_file, sound_file, stroke_id, created_by_admin_id, updated_by_admin_id) VALUES (:japanese_char, :char_type, :char_group, :romaji, :stroke_count, :image_file, :sound_file, :stroke_id, :created_by_admin_id, :updated_by_admin_id)');
    $insert->execute([
        ':japanese_char' => $japaneseChar,
        ':char_type' => $charType,
        ':char_group' => $charGroup,
        ':romaji' => $romaji,
        ':stroke_count' => $strokeCount,
        ':image_file' => $imageFile,
        ':sound_file' => $soundFile,
        ':stroke_id' => 0,
        ':created_by_admin_id' => (int)($_SESSION['admin']['admin_id'] ?? 0),
        ':updated_by_admin_id' => (int)($_SESSION['admin']['admin_id'] ?? 0),
    ]);
    $newCharId = (int)$conn->lastInsertId();

    if (is_array($parsedStrokes) && count($parsedStrokes) > 0) {
        $strokeInsert = $conn->prepare('INSERT INTO japanese_character_strokes (character_id, stroke_order, path_data, animation_style, stroke_duration, start_x, start_y) VALUES (:cid, 1, :path_data, :anim_style, :duration, :sx, :sy)');
        $strokeInsert->execute([
            ':cid' => $newCharId,
            ':path_data' => json_encode($parsedStrokes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':anim_style' => $animationStyle,
            ':duration' => $strokeDuration,
            ':sx' => $parsedStrokes[0]['start_x'] ?? null,
            ':sy' => $parsedStrokes[0]['start_y'] ?? null,
        ]);

        require_once __DIR__ . '/stroke_reference_helper.php';
        syncWritingReferencesForCharacter($conn, $newCharId, $parsedStrokes, (int)($_SESSION['admin']['admin_id'] ?? 0));
    }

    relinkQuizQuestionsForCharacter($conn, $newCharId, $japaneseChar, $romaji, $charType);
    addCharacterToWritingQuizSets($conn, $newCharId, $japaneseChar, $romaji, $charGroup);

    $conn->commit();
    header('Location: ' . appUrl('backend/php/srceen/manage_katakana.php?success=created'));
    exit;
} catch (Throwable $exception) {
    $conn->rollBack();
    error_log('create_katakana error: ' . $exception->getMessage());
    header('Location: ' . appUrl('backend/php/srceen/create_katagana.php?error=1'));
    exit;
}
