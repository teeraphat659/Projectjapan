<?php
require_once __DIR__ . '/../../../Front-end/connectapi/app_config.php';

function character_sound_upload_error(): string
{
    return (string)($GLOBALS['character_sound_upload_error'] ?? '');
}

function upload_character_sound(string $fieldName, string $fallback = '', string $romaji = ''): string
{
    $GLOBALS['character_sound_upload_error'] = '';

    if (empty($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $fallback;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        $GLOBALS['character_sound_upload_error'] = match ((int)$_FILES[$fieldName]['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'too_large',
            UPLOAD_ERR_PARTIAL => 'partial',
            default => 'upload_error',
        };
        return '';
    }

    $temporaryPath = (string)$_FILES[$fieldName]['tmp_name'];
    $fileSize = (int)$_FILES[$fieldName]['size'];
    $originalName = (string)($_FILES[$fieldName]['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $allowedExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'webm'];

    if ($fileSize < 1 || $fileSize > 10 * 1024 * 1024) {
        $GLOBALS['character_sound_upload_error'] = 'too_large';
        return '';
    }

    if (!in_array($extension, $allowedExtensions, true)) {
        $GLOBALS['character_sound_upload_error'] = 'invalid_type';
        return '';
    }

    $uploadDirectory = dirname(__DIR__, 3) . '/uploads/characters/audio';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        $GLOBALS['character_sound_upload_error'] = 'folder_error';
        return '';
    }

    $cleanRomaji = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($romaji));
    $prefix = $cleanRomaji !== '' ? $cleanRomaji . '_' : '';
    $fileName = $prefix . bin2hex(random_bytes(10)) . '.' . $extension;

    $moved = is_uploaded_file($temporaryPath)
        ? move_uploaded_file($temporaryPath, $uploadDirectory . '/' . $fileName)
        : copy($temporaryPath, $uploadDirectory . '/' . $fileName);

    if (!$moved) {
        $GLOBALS['character_sound_upload_error'] = 'save_error';
        return '';
    }

    return appUrl('uploads/characters/audio/' . $fileName);
}
