<?php
declare(strict_types=1);

function appBasePath(): string
{
    $configured = getenv('PROJECTJAPAN_BASE_PATH');
    if ($configured !== false) {
        $normalized = '/' . trim(trim($configured), '/');
        return $normalized === '/' ? '' : $normalized;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (['/Front-end/', '/backend/'] as $marker) {
        $position = stripos($scriptName, $marker);
        if ($position !== false) {
            return substr($scriptName, 0, $position);
        }
    }

    return '';
}

function appUrl(string $path = ''): string
{
    return appBasePath() . '/' . ltrim($path, '/');
}
