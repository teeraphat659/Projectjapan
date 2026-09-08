<?php

declare(strict_types=1);

function normalizeWritingWhitespace(?string $value): string
{
    $normalized = preg_replace('/[\x{00A0}\s]+/u', ' ', (string) ($value ?? ''));
    return trim((string) $normalized);
}

function normalizeWritingAnswer(?string $value): string
{
    return normalizeWritingWhitespace($value);
}

function normalizeWritingQuestionText(?string $value): string
{
    return normalizeWritingWhitespace($value);
}

function normalizeWritingMode(?string $value): string
{
    $normalized = strtolower(trim((string) ($value ?? '')));
    return in_array($normalized, ['assisted', 'unassisted'], true) ? $normalized : 'assisted';
}
