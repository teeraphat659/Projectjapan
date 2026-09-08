<?php
require_once __DIR__ . '/../../../Front-end/connectapi/app_config.php';

function character_image_upload_error(): string
{
    return (string)($GLOBALS['character_image_upload_error'] ?? '');
}

function upload_character_image(string $fieldName, string $fallback = ''): string
{
    $GLOBALS['character_image_upload_error'] = '';

    if (empty($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $fallback;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        $GLOBALS['character_image_upload_error'] = match ((int)$_FILES[$fieldName]['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'too_large',
            UPLOAD_ERR_PARTIAL => 'partial',
            default => 'upload_error',
        };
        return '';
    }

    $temporaryPath = $_FILES[$fieldName]['tmp_name'];
    $fileSize = (int)$_FILES[$fieldName]['size'];
    $imageInfo = @getimagesize($temporaryPath);
    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
    ];

    if ($fileSize < 1 || $fileSize > 5 * 1024 * 1024 || !$imageInfo || !isset($allowedMimeTypes[$imageInfo['mime']])) {
        $GLOBALS['character_image_upload_error'] = $fileSize > 5 * 1024 * 1024 ? 'too_large' : 'invalid_type';
        return '';
    }

    $uploadDirectory = dirname(__DIR__, 3) . '/uploads/characters';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        $GLOBALS['character_image_upload_error'] = 'folder_error';
        return '';
    }

    $fileName = bin2hex(random_bytes(12)) . '.' . $allowedMimeTypes[$imageInfo['mime']];
    if (!move_uploaded_file($temporaryPath, $uploadDirectory . '/' . $fileName)) {
        $GLOBALS['character_image_upload_error'] = 'save_error';
        return '';
    }

    return appUrl('uploads/characters/' . $fileName);
}