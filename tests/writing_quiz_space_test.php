<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/php/action/writing_quiz_normalizer.php';

$normalized = normalizeWritingAnswer("  ka  ta  ka  na  ");
if ($normalized !== 'ka ta ka na') {
    throw new RuntimeException('normalizeWritingAnswer failed to collapse repeated spaces');
}

$questionText = normalizeWritingQuestionText("  จงเขียนคำว่า  あ  ");
if ($questionText !== 'จงเขียนคำว่า あ') {
    throw new RuntimeException('normalizeWritingQuestionText failed to trim and normalize spaces');
}

if (normalizeWritingMode('UNASSISTED') !== 'unassisted') {
    throw new RuntimeException('normalizeWritingMode failed to normalize assisted flag');
}

if (normalizeWritingMode('random') !== 'assisted') {
    throw new RuntimeException('normalizeWritingMode failed to default invalid values');
}

echo "writing quiz normalization test passed\n";
