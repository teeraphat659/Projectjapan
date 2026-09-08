<?php
declare(strict_types=1);

function relinkQuizQuestionsForCharacter(PDO $conn, int $characterId, string $japaneseChar, string $romaji, string $charType): int
{
    $tableCheck = $conn->query("SHOW TABLES LIKE 'quiz_set_questions'");
    if (!$tableCheck || !$tableCheck->fetchColumn()) {
        return 0;
    }

    $statement = $conn->prepare(
        'UPDATE quiz_set_questions q
         LEFT JOIN japanese_characters old_character ON old_character.character_id = q.character_id
         INNER JOIN quiz_sets s ON s.quiz_set_id = q.quiz_set_id
         SET q.character_id = :new_character_id
         WHERE old_character.character_id IS NULL
           AND LOWER(s.alphabet_type) = LOWER(:char_type)
           AND (TRIM(q.correct_answer) = TRIM(:romaji)
                OR q.question_text LIKE :character_pattern)'
    );
    $statement->execute([
        ':new_character_id' => $characterId,
        ':char_type' => $charType,
        ':romaji' => $romaji,
        ':character_pattern' => '%' . $japaneseChar . '%',
    ]);

    return $statement->rowCount();
}

function addCharacterToWritingQuizSets(PDO $conn, int $characterId, string $japaneseChar, string $romaji, string $charGroup): int
{
    $tableCheck = $conn->query("SHOW TABLES LIKE 'quiz_set_questions'");
    $setTableCheck = $conn->query("SHOW TABLES LIKE 'quiz_sets'");
    $groupTableCheck = $conn->query("SHOW TABLES LIKE 'alphabet_groups'");
    if (!$tableCheck || !$tableCheck->fetchColumn() || !$setTableCheck || !$setTableCheck->fetchColumn() || !$groupTableCheck || !$groupTableCheck->fetchColumn()) {
        return 0;
    }

    $sets = $conn->prepare(
        'SELECT s.quiz_set_id
         FROM quiz_sets s
         INNER JOIN alphabet_groups g ON g.group_id = s.group_id
         WHERE s.quiz_kind = "writing" AND s.is_active = 1
           AND LOWER(TRIM(g.group_code)) = LOWER(TRIM(:char_group))'
    );
    $sets->execute([':char_group' => $charGroup]);
    $quizSetIds = $sets->fetchAll(PDO::FETCH_COLUMN);
    if (!$quizSetIds) {
        return 0;
    }

    $exists = $conn->prepare('SELECT 1 FROM quiz_set_questions WHERE quiz_set_id = :quiz_set_id AND character_id = :character_id LIMIT 1');
    $nextOrder = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM quiz_set_questions WHERE quiz_set_id = :quiz_set_id');
    $insert = $conn->prepare(
        'INSERT INTO quiz_set_questions (quiz_set_id, character_id, max_score, question_text, correct_answer, sort_order)
         VALUES (:quiz_set_id, :character_id, 5, :question_text, :correct_answer, :sort_order)'
    );

    $added = 0;
    foreach ($quizSetIds as $quizSetId) {
        $exists->execute([':quiz_set_id' => (int)$quizSetId, ':character_id' => $characterId]);
        if ($exists->fetchColumn()) {
            continue;
        }

        $nextOrder->execute([':quiz_set_id' => (int)$quizSetId]);
        $insert->execute([
            ':quiz_set_id' => (int)$quizSetId,
            ':character_id' => $characterId,
            ':question_text' => 'ฝึกเขียน ' . $japaneseChar,
            ':correct_answer' => $romaji,
            ':sort_order' => (int)$nextOrder->fetchColumn(),
        ]);
        $added++;
    }

    return $added;
}
