<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Migration must run from CLI.\n");
}

require_once __DIR__ . '/db.php';

$migrations = [
    'create_users_table.sql',
    'create_quizzes_table.sql',
    'create_password_reset_tokens.sql',
    'create_quiz_sets.sql',
    'create_writing_tables.sql',
    'create_writing_reference_tables.sql',
    'create_writing_answer_references.sql',
    'create_writing_svg_answer_references.sql',
    'create_writing_scoring_tables.sql',
        'japanese_character_strokes.sql',
        'migrate_quiz_answer_details.sql',
        'migrate_quiz_question_audio.sql',
        'migrate_quiz_user_id.sql',
        'migrate_writing_quiz_question_scoring.sql',
        'migrate_writing_trajectory.sql',
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'action' . DIRECTORY_SEPARATOR . 'migrate_writing_modes.sql',
];

foreach ($migrations as $migration) {
        $path = str_contains($migration, DIRECTORY_SEPARATOR)
            ? $migration
            : __DIR__ . DIRECTORY_SEPARATOR . $migration;
    if (!is_file($path)) {
        throw new RuntimeException("Missing migration: {$migration}");
    }
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Empty migration: {$migration}");
    }
    $conn->exec($sql);
    echo "Applied {$migration}\n";
}

echo "Migrations complete.\n";
