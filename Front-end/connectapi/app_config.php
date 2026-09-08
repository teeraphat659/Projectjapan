<?php
declare(strict_types=1);

function appBasePath(): string
{
    $configured = trim((string) (getenv('PROJECTJAPAN_BASE_PATH') ?: '/projectjapan'));
    $normalized = '/' . trim($configured, '/');
    return $normalized === '/' ? '' : $normalized;
}

function appUrl(string $path = ''): string
{
    return appBasePath() . '/' . ltrim($path, '/');
}

