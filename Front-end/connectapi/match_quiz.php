<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/security.php';

$userId = resolveCurrentMemberId();
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';

$type = strtolower(trim((string) ($_GET['type'] ?? 'katakana')));
$group = trim((string) ($_GET['group_name'] ?? ''));
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'pair')));
$quizSetId = (int) ($_GET['quiz_set_id'] ?? 0);
if (!in_array($type, ['hiragana', 'katakana'], true) || !in_array($mode, ['pair', 'listen'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'พารามิเตอร์เกมไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanChoiceLabel(string $value): string
{
    return trim((string) (preg_replace('/^\d+\s*/u', '', $value) ?? $value));
}

try {
    if (isset($_GET['list_sets'])) {
        $setType = strtolower(trim((string) ($_GET['set_type'] ?? '')));
        $setTypeCondition = in_array($setType, ['hiragana', 'katakana'], true)
            ? ' AND LOWER(alphabet_type) = :set_type'
            : '';
        $statement = $conn->prepare(
            'SELECT quiz_set_id AS id, title, description, alphabet_type, time_limit_seconds
             FROM quiz_sets
             WHERE quiz_kind = "choice" AND is_active = 1' . $setTypeCondition . '
             ORDER BY updated_at DESC, quiz_set_id DESC'
        );
        $statement->execute($setTypeCondition ? [':set_type' => $setType] : []);
        echo json_encode(['success' => true, 'sets' => $statement->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($quizSetId > 0) {
        $statement = $conn->prepare(
            'SELECT c.japanese_char, c.sound_file, q.question_text, q.choice_options, q.correct_answer, q.audio_file
             FROM quiz_sets s
             INNER JOIN quiz_set_questions q ON q.quiz_set_id = s.quiz_set_id
             INNER JOIN japanese_characters c ON c.character_id = q.character_id
             WHERE s.quiz_set_id = :quiz_set_id AND s.quiz_kind = "choice" AND s.is_active = 1
               AND LOWER(s.alphabet_type) = :type
             ORDER BY q.sort_order, q.quiz_set_question_id'
        );
        $statement->execute([':quiz_set_id' => $quizSetId, ':type' => $type]);
        $configuredQuestions = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!$configuredQuestions) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'ไม่พบชุดแบบทดสอบที่เปิดใช้งาน หรือชนิดตัวอักษรไม่ตรงกัน'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $questions = [];
        foreach ($configuredQuestions as $configuredQuestion) {
            $options = json_decode((string) $configuredQuestion['choice_options'], true);
            $options = is_array($options) ? $options : [];
            $correctAnswer = cleanChoiceLabel((string) $configuredQuestion['correct_answer']);
            $questions[] = [
                'question' => (string) ($configuredQuestion['question_text'] ?: $configuredQuestion['japanese_char']),
                'sound_file' => (string) ($configuredQuestion['audio_file'] ?: $configuredQuestion['sound_file']),
                'choices' => array_map(static function (string $choice) use ($correctAnswer): array {
                    $label = cleanChoiceLabel($choice);
                    return ['text' => $label, 'isCorrect' => $label === $correctAnswer];
                }, $options),
            ];
        }
        echo json_encode(['success' => true, 'questions' => $questions], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = 'SELECT character_id, japanese_char, COALESCE(romaji, "") AS romaji, COALESCE(sound_file, "") AS sound_file
            FROM japanese_characters WHERE LOWER(char_type) = :type AND COALESCE(romaji, "") <> ""';
    $params = [':type' => $type];
    if ($group !== '') {
        $sql .= ' AND char_group = :group';
        $params[':group'] = $group;
    }
    $statement = $conn->prepare($sql);
    $statement->execute($params);
    $characters = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($characters) < 4) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'ต้องมีตัวอักษรที่มีคำอ่านอย่างน้อย 4 ตัวจึงจะเริ่มเกมได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    shuffle($characters);
    $targets = array_slice($characters, 0, min(10, count($characters)));
    $questions = [];
    foreach ($targets as $target) {
        $pool = $characters;
        shuffle($pool);
        $targetRomaji = cleanChoiceLabel((string) $target['romaji']);
        $choices = [$targetRomaji];
        foreach ($pool as $candidate) {
            if (count($choices) >= 4) {
                break;
            }
            $candidateRomaji = cleanChoiceLabel((string) $candidate['romaji']);
            if (!in_array($candidateRomaji, $choices, true)) {
                $choices[] = $candidateRomaji;
            }
        }
        shuffle($choices);
        $questions[] = [
            'question' => $target['japanese_char'],
            'sound_file' => $target['sound_file'],
            'choices' => array_map(static fn (string $choice): array => ['text' => $choice, 'isCorrect' => $choice === $targetRomaji], $choices),
        ];
    }
    echo json_encode(['success' => true, 'questions' => $questions], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    error_log('match_quiz.php: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถโหลดคำถามจากฐานข้อมูลได้'], JSON_UNESCAPED_UNICODE);
}
