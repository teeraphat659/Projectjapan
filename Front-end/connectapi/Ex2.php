<?php
// --- api.php ---
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../connectapi/db.php';

// รับค่าพารามิเตอร์ต่างๆ
$type = strtolower($_GET['type'] ?? 'katakana');
$groupOnly = isset($_GET['group']) && $_GET['group'] === '1'; // สำหรับโหมดมีตัวช่วย
$quizGroupOnly = isset($_GET['quiz_group']) && $_GET['quiz_group'] === '1'; // สำหรับโหมดไม่มีตัวช่วย
$groupId = $_GET['group_id'] ?? $_GET['quiz_set_id'] ?? '';
$quizSource = strtolower(trim((string)($_GET['quiz_source'] ?? '')));
$groupName = trim((string)($_GET['group_name'] ?? ''));

if ($groupName !== '') {
    $groupName = preg_replace('/^\s*วรรค\s*/u', '', $groupName);
    $groupName = trim((string)$groupName);
}

function attachStrokePaths(PDO $conn, array &$characters): void
{
    if (!$characters) return;

    $table = $conn->query("SHOW TABLES LIKE 'japanese_character_strokes'");
    if (!$table || !$table->fetchColumn()) return;

    $ids = array_values(array_filter(array_map(
        static fn (array $character): int => (int)($character['character_id'] ?? 0),
        $characters
    )));
    if (!$ids) return;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $conn->prepare(
        "SELECT character_id, stroke_order, path_data
         FROM japanese_character_strokes
         WHERE character_id IN ({$placeholders})
         ORDER BY character_id, stroke_order"
    );
    $statement->execute($ids);
    $pathsByCharacter = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $stroke) {
        $storedPaths = json_decode((string)$stroke['path_data'], true);
        if (is_array($storedPaths) && isset($storedPaths[0]['path'])) {
            foreach ($storedPaths as $storedPath) {
                $pathsByCharacter[(int)$stroke['character_id']][] = [
                    'order' => (int)($storedPath['order'] ?? count($pathsByCharacter[(int)$stroke['character_id']]) + 1),
                    'path' => (string)$storedPath['path'],
                ];
            }
            continue;
        }
        $pathsByCharacter[(int)$stroke['character_id']][] = [
            'order' => (int)$stroke['stroke_order'], 'path' => $stroke['path_data'],
        ];
    }

    foreach ($characters as &$character) {
        $character['stroke_paths'] = $pathsByCharacter[(int)($character['character_id'] ?? 0)] ?? [];
    }
    unset($character);
}

try {
    // ----------------------------------------------------
    // 1. ดึงรายชื่อ "วรรคตัวอักษร" สำหรับ Dropdown โหมดมีตัวช่วย
    // ----------------------------------------------------
    if ($groupOnly) {
        $query = "SELECT group_id, group_code, group_name_th AS group_name
                  FROM alphabet_groups
                  ORDER BY sort_order ASC";
                  
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($groups, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ----------------------------------------------------
    // 2. ดึงรายชื่อ "ชุดแบบทดสอบ" สำหรับ Dropdown โหมดไม่มีตัวช่วย
    // ----------------------------------------------------
    if ($quizGroupOnly && $groupId === '' && $groupName === '') {
                $query = "SELECT s.quiz_set_id AS group_id,
                                        CONVERT(s.title USING utf8mb4) COLLATE utf8mb4_unicode_ci AS group_name,
                  'assisted' AS writing_mode, 'writing' AS quiz_source
                  FROM writing_quiz_sets s
                  WHERE s.is_active = 1
                  UNION ALL
                                    SELECT s.quiz_set_id AS group_id,
                                                 CONVERT(s.title USING utf8mb4) COLLATE utf8mb4_unicode_ci AS group_name,
                    COALESCE(s.writing_mode, 'assisted') AS writing_mode, 'legacy' AS quiz_source
                  FROM quiz_sets s
                  WHERE s.quiz_kind = 'writing' AND s.is_active = 1
                  ORDER BY group_name ASC, group_id ASC";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($groups, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ชุดแบบทดสอบเขียนใช้ตาราง writing_quiz_* ซึ่งเป็นคนละโครงสร้างกับชุดจับคู่เก่า
    if ($quizGroupOnly && $groupId !== '' && $quizSource !== 'legacy') {
        $query = "SELECT
                    q.character_id AS id,
                    q.character_id AS character_id,
                    COALESCE(q.question_text, '') AS question_text,
                    COALESCE(q.target_character, c.japanese_char) AS character_jp,
                    COALESCE(q.target_romaji, c.romaji) AS romaji,
                    c.stroke_count AS stroke_count,
                    c.image_file AS stroke_image,
                    c.sound_file AS sound_file,
                    q.sequence_no AS sort_order
                  FROM writing_quiz_questions q
                  INNER JOIN writing_quiz_sets s ON q.quiz_set_id = s.quiz_set_id
                  INNER JOIN japanese_characters c ON q.character_id = c.character_id
                  WHERE q.quiz_set_id = :quiz_set_id AND s.is_active = 1
                  ORDER BY q.sequence_no ASC, q.character_id ASC";

        $stmt = $conn->prepare($query);
        $stmt->execute([':quiz_set_id' => $groupId]);
        $chars = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$chars) {
                        $query = "SELECT
                                                q.quiz_set_question_id AS id,
                                                c.character_id AS character_id,
                                                q.question_text,
                                                q.correct_answer,
                                                COALESCE(c.japanese_char, q.question_text) AS character_jp,
                                                COALESCE(c.romaji, q.correct_answer) AS romaji,
                                                c.stroke_count AS stroke_count,
                                                c.image_file AS stroke_image,
                                                c.sound_file AS sound_file,
                                                q.sort_order
                                            FROM quiz_set_questions q
                                            INNER JOIN quiz_sets s ON q.quiz_set_id = s.quiz_set_id
                                            INNER JOIN japanese_characters c ON q.character_id = c.character_id
                                            WHERE q.quiz_set_id = :quiz_set_id
                                                AND s.quiz_kind = 'writing' AND s.is_active = 1
                                            ORDER BY q.sort_order ASC, q.quiz_set_question_id ASC";
                        $stmt = $conn->prepare($query);
                        $stmt->execute([':quiz_set_id' => $groupId]);
                        $chars = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

        attachStrokePaths($conn, $chars);
        echo json_encode($chars, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ----------------------------------------------------
    // 3. ดึงเนื้อหาโจทย์แบบทดสอบ / ตัวอักษร
    // ----------------------------------------------------
    if ($groupId !== '' || $groupName !== '') {
        if ($groupId !== '') {
            $query = "SELECT
                        MIN(q.quiz_set_question_id) AS id,
                        c.character_id AS character_id,
                        MIN(q.question_text) AS question_text,
                        MIN(q.correct_answer) AS correct_answer,
                        c.japanese_char AS character_jp,
                        c.romaji AS romaji,
                        c.stroke_count AS stroke_count,
                        c.image_file AS stroke_image,
                        c.sound_file AS sound_file,
                        MIN(q.sort_order) AS sort_order
                      FROM quiz_set_questions q
                      LEFT JOIN quiz_sets s ON q.quiz_set_id = s.quiz_set_id
                      INNER JOIN japanese_characters c ON q.character_id = c.character_id
                      WHERE (s.group_id = :group_id OR q.quiz_set_id = :quiz_set_id)
                        AND s.quiz_kind = 'writing' AND s.is_active = 1
                      GROUP BY c.character_id, c.japanese_char, c.romaji, c.stroke_count, c.image_file, c.sound_file
                      ORDER BY MIN(q.sort_order) ASC, c.character_id ASC";

            $stmt = $conn->prepare($query);
            $stmt->execute([
                ':group_id'    => $groupId,
                ':quiz_set_id' => $groupId
            ]);
        } else {
            $query = "SELECT 
                        q.quiz_set_question_id AS id,
                        c.character_id AS character_id,
                        q.question_text,
                        q.correct_answer,
                        COALESCE(c.japanese_char, q.question_text) AS character_jp,
                        COALESCE(c.romaji, q.correct_answer) AS romaji,
                        c.stroke_count AS stroke_count,
                        c.image_file AS stroke_image,
                        c.sound_file AS sound_file,
                        q.sort_order
                      FROM quiz_set_questions q
                      LEFT JOIN quiz_sets s ON q.quiz_set_id = s.quiz_set_id
                      LEFT JOIN alphabet_groups g ON g.group_id = s.group_id
                      LEFT JOIN japanese_characters c ON q.character_id = c.character_id
                      WHERE (g.group_name_th = :group_name
                         OR g.group_code = :group_name
                         OR g.group_name_th = :group_name_raw)
                        AND s.quiz_kind = 'writing' AND s.is_active = 1
                      ORDER BY q.sort_order ASC, q.quiz_set_question_id ASC";

            $stmt = $conn->prepare($query);
            $stmt->execute([
                ':group_name' => $groupName,
                ':group_name_raw' => 'วรรค ' . $groupName,
            ]);
        }

        $chars = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: ถ้าไม่พบโจทย์แบบทดสอบ ให้ดึงตัวอักษรในวรรคมาแสดง (สำหรับโหมดมีตัวช่วย)
        if (!$chars && $quizSource !== 'writing') {
            $groupCode = '';
            if ($groupId !== '') {
                $groupStatement = $conn->prepare('SELECT group_code FROM alphabet_groups WHERE group_id = :group_id LIMIT 1');
                $groupStatement->execute([':group_id' => $groupId]);
                $groupCode = trim((string)$groupStatement->fetchColumn());
            } elseif ($groupName !== '') {
                $groupStatement = $conn->prepare(
                    'SELECT group_code FROM alphabet_groups
                     WHERE group_name_th = :group_name OR group_code = :group_name
                        OR group_name_th = :group_name_raw LIMIT 1'
                );
                $groupStatement->execute([
                    ':group_name' => $groupName,
                    ':group_name_raw' => 'วรรค ' . $groupName,
                ]);
                $groupCode = trim((string)$groupStatement->fetchColumn());
            }

            if ($groupCode !== '') {
                $fallbackStatement = $conn->prepare(
                    'SELECT character_id AS id, character_id, japanese_char AS character_jp,
                            COALESCE(romaji, "") AS romaji, stroke_count,
                            image_file AS stroke_image, sound_file
                     FROM japanese_characters
                     WHERE LOWER(char_type) = :type AND char_group = :group_code
                     ORDER BY character_id ASC'
                );
                $fallbackStatement->execute([
                    ':type' => $type,
                    ':group_code' => $groupCode,
                ]);
                $chars = $fallbackStatement->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        attachStrokePaths($conn, $chars);
        echo json_encode($chars, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ----------------------------------------------------
    // 4. กรณีดึงโจทย์แบบทดสอบทั้งหมด (ไม่ได้ระบุกลุ่ม)
    // ----------------------------------------------------
    $query = "SELECT 
                q.quiz_set_question_id AS id,
                c.character_id AS character_id,
                q.question_text,
                q.correct_answer,
                COALESCE(c.japanese_char, q.question_text) AS character_jp,
                COALESCE(c.romaji, q.correct_answer) AS romaji,
                c.stroke_count AS stroke_count,
                c.image_file AS stroke_image,
                c.sound_file AS sound_file
              FROM quiz_set_questions q
              LEFT JOIN japanese_characters c ON q.character_id = c.character_id
              ORDER BY q.sort_order ASC";
              
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $chars = $stmt->fetchAll(PDO::FETCH_ASSOC);
    attachStrokePaths($conn, $chars);
    echo json_encode($chars, JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'ดึงข้อมูลล้มเหลว: ' . $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>