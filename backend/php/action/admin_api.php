<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../Front-end/connectapi/security.php';
require_once __DIR__ . '/../../../Front-end/connectapi/db.php';
require_once __DIR__ . '/../../../Front-end/connectapi/app_config.php';
require_once __DIR__ . '/writing_quiz_normalizer.php';

securityStartSession();

function requireAdminApiSession(): void
{
    securityStartSession();

    $auth = currentAuth();
    $hasAdminSession = (($auth['type'] ?? null) === 'admin')
        || (!empty($_SESSION['admin']['admin_id']))
        || ((strtolower((string) ($_SESSION['auth']['type'] ?? '')) === 'admin') && !empty($_SESSION['auth']['id']));

    if ($hasAdminSession) {
        return;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน'], JSON_UNESCAPED_UNICODE);
    exit;
}

requireAdminApiSession();
header('Content-Type: application/json; charset=UTF-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function currentAdminId(): int
{
    $auth = currentAuth();
    return ($auth['type'] === 'admin' || $auth['type'] === 'member') ? max((int) ($auth['admin_id'] ?: $auth['id']), 0) : 0;
}

function requestData(): array
{
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0) {
        $data = $_POST;
        if (isset($data['questions']) && is_string($data['questions'])) {
            $data['questions'] = json_decode($data['questions'], true) ?: [];
        }
        return $data;
    }
    $json = json_decode((string) file_get_contents('php://input'), true);
    return is_array($json) ? $json : $_POST;
}

function uploadQuizAudio(?array $file): string
{
    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        respond(422, ['success' => false, 'message' => 'ไฟล์เสียงไม่ถูกต้องหรือมีขนาดเกิน 8 MB']);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) ($file['tmp_name'] ?? ''));
    $extensions = ['audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg', 'audio/webm' => 'webm', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a'];
    if (!isset($extensions[$mime])) {
        respond(422, ['success' => false, 'message' => 'รองรับเฉพาะไฟล์ MP3, WAV, OGG, WEBM หรือ M4A']);
    }
    $directory = dirname(__DIR__, 2) . '/uploads/quiz-audio';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        respond(500, ['success' => false, 'message' => 'ไม่สามารถสร้างโฟลเดอร์เก็บไฟล์เสียงได้']);
    }
    $fileName = 'quiz_audio_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $fileName)) {
        respond(500, ['success' => false, 'message' => 'ไม่สามารถบันทึกไฟล์เสียงได้']);
    }
    return appUrl('backend/uploads/quiz-audio/' . $fileName);
}

function tableExists(PDO $conn, string $table): bool
{
    $statement = $conn->prepare('SHOW TABLES LIKE :table');
    $statement->execute([':table' => $table]);
    return (bool) $statement->fetchColumn();
}

function getTableColumns(PDO $conn, string $table): array
{
    $statement = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    return array_map(static fn (array $column): string => (string) $column['Field'], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function valueText(array $data, string $key, int $limit = 500): string
{
    $value = normalizeWritingWhitespace((string) ($data[$key] ?? ''));
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
}

function writingQuizGroupExists(PDO $conn, int $groupId, ?int $excludeQuizSetId = null): bool
{
    if ($groupId <= 0) {
        return false;
    }

    $sql = 'SELECT 1 FROM quiz_sets WHERE quiz_kind = :quiz_kind AND group_id = :group_id';
    $params = [':quiz_kind' => 'writing', ':group_id' => $groupId];

    if ($excludeQuizSetId !== null && $excludeQuizSetId > 0) {
        $sql .= ' AND quiz_set_id <> :exclude_id';
        $params[':exclude_id'] = $excludeQuizSetId;
    }

    $sql .= ' LIMIT 1';

    $statement = $conn->prepare($sql);
    $statement->execute($params);
    return (bool) $statement->fetchColumn();
}

function validateQuizPayload(PDO $conn, array $data, ?int $excludeQuizSetId = null): array
{
    $title = valueText($data, 'title', 180);
    $description = valueText($data, 'description', 5000);
    
    // รองรับการไม่ส่ง alphabet_type และ quiz_kind มาจากฝั่ง Frontend (ให้ค่าปริยายเป็น writing)
    $alphabetType = strtolower(valueText($data, 'alphabet_type', 20));
    $quizKind = strtolower(valueText($data, 'quiz_kind', 20));
    if ($quizKind === '') $quizKind = 'writing';

    // จัดการเรื่องเวลา (รองรับทั้งระบบเดิมแบบนาที และระบบใหม่แบบวินาที)
    $timeLimitSeconds = isset($data['time_limit_seconds']) ? (int) $data['time_limit_seconds'] : (int)($data['time_limit_minutes'] ?? 0) * 60;
    $timeLimitMinutes = (int) floor($timeLimitSeconds / 60);
    $timeLimitMinutes = max(0, min(600, $timeLimitMinutes));

    $passScore = max(0, min(100, (int) ($data['pass_score'] ?? 70)));
    
    // รับค่าฟิลด์ใหม่จากแบบทดสอบเขียน
    $groupId = !empty($data['group_id']) ? (int) $data['group_id'] : null;
    $writingMode = normalizeWritingMode(valueText($data, 'writing_mode', 20));
    $role = strtolower(valueText($data, 'role', 20));
    if (!in_array($role, ['all', 'member', 'admin'], true)) {
        $role = 'all';
    }
    $orderType = valueText($data, 'order_type', 20);
    if ($orderType === '') $orderType = 'sequential';
    $isActive = isset($data['is_active']) ? (int) $data['is_active'] : 1;

    $questions = $data['questions'] ?? [];

    if ($title === '') {
        respond(422, ['success' => false, 'message' => 'กรุณากรอกชื่อชุดแบบทดสอบ']);
    }
    if (!in_array($quizKind, ['choice', 'writing'], true)) {
        respond(422, ['success' => false, 'message' => 'ประเภทแบบทดสอบไม่ถูกต้อง']);
    }
    if ($quizKind === 'writing' && $groupId !== null && writingQuizGroupExists($conn, $groupId, $excludeQuizSetId)) {
        respond(409, ['success' => false, 'message' => 'วรรคนี้มีแบบทดสอบการเขียนอยู่แล้ว ไม่สามารถสร้างซ้ำได้']);
    }
    if (!is_array($questions) || count($questions) < 1 || count($questions) > 100) {
        respond(422, ['success' => false, 'message' => 'ต้องมีคำถามอย่างน้อย 1 ข้อ และไม่เกิน 100 ข้อ']);
    }

    $characterStatement = $conn->prepare(
        'SELECT character_id, japanese_char, COALESCE(romaji, "") AS romaji,
                COALESCE(sound_file, "") AS sound_file, char_type
         FROM japanese_characters
         WHERE character_id = :id LIMIT 1'
    );
    
    $normalized = [];
    foreach ($questions as $index => $question) {
        if (!is_array($question)) {
            respond(422, ['success' => false, 'message' => 'รูปแบบคำถามไม่ถูกต้อง']);
        }
        $characterId = (int) ($question['character_id'] ?? 0);
        $characterStatement->execute([':id' => $characterId]);
        $character = $characterStatement->fetch(PDO::FETCH_ASSOC);
        
        if (!$character) {
            respond(422, ['success' => false, 'message' => 'พบตัวอักษรที่ไม่อยู่ในระบบ']);
        }

        // อนุมานประเภทตัวอักษรอัตโนมัติหากไม่ได้ส่งมา
        if ($alphabetType === '') {
            $alphabetType = strtolower((string)$character['char_type']);
        }

        $questionText = normalizeWritingQuestionText(valueText($question, 'question_text', 500));
        $correctAnswer = normalizeWritingAnswer(valueText($question, 'correct_answer', 255));
        $maxScore = max(1, (int) ($question['max_score'] ?? 1));

        if ($questionText === '') {
            $questionText = 'เลือกคำตอบที่ตรงกับ ' . $character['japanese_char'];
        }
        if ($correctAnswer === '') {
            $correctAnswer = normalizeWritingAnswer((string) $character['romaji']);
        }

        $options = [];
        if ($quizKind === 'choice') {
            $rawOptions = $question['choice_options'] ?? [];
            if (is_string($rawOptions)) {
                $rawOptions = array_filter(array_map('trim', explode(',', $rawOptions)), static fn ($item) => $item !== '');
            }
            if (!is_array($rawOptions)) {
                respond(422, ['success' => false, 'message' => 'ตัวเลือกคำตอบไม่ถูกต้อง']);
            }
            foreach ($rawOptions as $option) {
                $option = valueText(['option' => $option], 'option', 255);
                if ($option !== '' && !in_array($option, $options, true)) {
                    $options[] = $option;
                }
            }
            if (count($options) < 2 || !in_array($correctAnswer, $options, true)) {
                respond(422, ['success' => false, 'message' => 'ข้อสอบแบบเลือกตอบต้องมีอย่างน้อย 2 ตัวเลือก และต้องมีคำตอบที่ถูกต้อง']);
            }
        }

        $audioFile = valueText($question, 'audio_file', 500);
        $characterSound = (string) ($character['sound_file'] ?? '');
        if ($audioFile !== ''
            && $audioFile !== $characterSound
            && strpos($audioFile, appUrl('backend/uploads/quiz-audio/')) !== 0) {
            $audioFile = '';
        }
        if (isset($question['audio_upload_index'])) {
            $uploadIndex = (int) $question['audio_upload_index'];
            $audioFile = uploadQuizAudio($_FILES['audio_' . $uploadIndex] ?? null) ?: $audioFile;
        }

        $normalized[] = [
            'character_id' => (int) $character['character_id'],
            'max_score' => $maxScore,
            'question_text' => $questionText,
            'choice_options' => $options,
            'correct_answer' => $correctAnswer,
            'audio_file' => $audioFile,
            'sort_order' => $question['sequence_no'] ?? ($index + 1),
        ];
    }

    if (!in_array($alphabetType, ['hiragana', 'katakana'], true)) {
        $alphabetType = 'hiragana';
    }

    return compact('title', 'description', 'alphabetType', 'quizKind', 'groupId', 'writingMode', 'role', 'orderType', 'timeLimitMinutes', 'timeLimitSeconds', 'passScore', 'isActive', 'normalized');
}

function saveQuiz(PDO $conn, array $data, ?int $quizSetId = null): int
{
    $payload = validateQuizPayload($conn, $data, $quizSetId);
    $conn->beginTransaction();
    try {
        if ($quizSetId) {
            $update = $conn->prepare(
                'UPDATE quiz_sets SET title = :title, description = :description, alphabet_type = :alphabet_type,
                    quiz_kind = :quiz_kind, group_id = :group_id, writing_mode = :writing_mode, role = :role, order_type = :order_type,
                    time_limit_minutes = :time_limit_minutes, time_limit_seconds = :time_limit_seconds, 
                    pass_score = :pass_score, is_active = :is_active
                 WHERE quiz_set_id = :id'
            );
            $update->execute([
                ':title' => $payload['title'], ':description' => $payload['description'],
                ':alphabet_type' => $payload['alphabetType'], ':quiz_kind' => $payload['quizKind'],
                ':group_id' => $payload['groupId'], ':writing_mode' => $payload['writingMode'], ':role' => $payload['role'], ':order_type' => $payload['orderType'],
                ':time_limit_minutes' => $payload['timeLimitMinutes'], ':time_limit_seconds' => $payload['timeLimitSeconds'],
                ':pass_score' => $payload['passScore'], ':is_active' => $payload['isActive'],
                ':id' => $quizSetId,
            ]);
            if ($update->rowCount() === 0) {
                $exists = $conn->prepare('SELECT 1 FROM quiz_sets WHERE quiz_set_id = :id');
                $exists->execute([':id' => $quizSetId]);
                if (!$exists->fetchColumn()) {
                    throw new RuntimeException('ไม่พบชุดแบบทดสอบที่ต้องการแก้ไข');
                }
            }
            $delete = $conn->prepare('DELETE FROM quiz_set_questions WHERE quiz_set_id = :id');
            $delete->execute([':id' => $quizSetId]);
        } else {
            $insert = $conn->prepare(
                'INSERT INTO quiz_sets (title, description, alphabet_type, quiz_kind, group_id, writing_mode, role, order_type, time_limit_minutes, time_limit_seconds, pass_score, is_active, created_by)
                 VALUES (:title, :description, :alphabet_type, :quiz_kind, :group_id, :writing_mode, :role, :order_type, :time_limit_minutes, :time_limit_seconds, :pass_score, :is_active, :created_by)'
            );
            $insert->execute([
                ':title' => $payload['title'], ':description' => $payload['description'],
                ':alphabet_type' => $payload['alphabetType'], ':quiz_kind' => $payload['quizKind'],
                ':group_id' => $payload['groupId'], ':writing_mode' => $payload['writingMode'], ':role' => $payload['role'], ':order_type' => $payload['orderType'],
                ':time_limit_minutes' => $payload['timeLimitMinutes'], ':time_limit_seconds' => $payload['timeLimitSeconds'],
                ':pass_score' => $payload['passScore'], ':is_active' => $payload['isActive'],
                ':created_by' => currentAdminId(),
            ]);
            $quizSetId = (int) $conn->lastInsertId();
        }

        $questionInsert = $conn->prepare(
            'INSERT INTO quiz_set_questions (quiz_set_id, character_id, max_score, question_text, choice_options, correct_answer, audio_file, sort_order)
             VALUES (:quiz_set_id, :character_id, :max_score, :question_text, :choice_options, :correct_answer, :audio_file, :sort_order)'
        );
        foreach ($payload['normalized'] as $question) {
            $questionInsert->execute([
                ':quiz_set_id' => $quizSetId,
                ':character_id' => $question['character_id'],
                ':max_score' => $question['max_score'],
                ':question_text' => $question['question_text'],
                ':choice_options' => $question['choice_options'] ? json_encode($question['choice_options'], JSON_UNESCAPED_UNICODE) : null,
                ':correct_answer' => $question['correct_answer'], 
                ':audio_file' => $question['audio_file'],
                ':sort_order' => $question['sort_order'],
            ]);
        }
        $conn->commit();
        return $quizSetId;
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireSameOriginRequest();
    }
    $input = requestData();
    $action = strtolower(valueText(array_merge($_GET, $input), 'action', 50));

    if ($action === 'profile_get') {
        $adminId = currentAdminId();
        if ($adminId <= 0) {
            respond(401, ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน']);
        }

        respond(200, ['success' => true, 'item' => [
            'firstname' => (string) ($_SESSION['admin']['firstname'] ?? $_SESSION['auth']['name'] ?? ''),
            'lastname' => (string) ($_SESSION['admin']['lastname'] ?? ''),
            'email' => (string) ($_SESSION['admin']['email'] ?? ''),
        ]]);
    }

    if ($action === 'profile_update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(405, ['success' => false, 'message' => 'รองรับเฉพาะ POST']);
        }
        $adminId = currentAdminId();
        if ($adminId <= 0) {
            respond(401, ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน']);
        }
        $firstname = valueText($input, 'firstname', 100);
        $lastname = valueText($input, 'lastname', 100);
        $email = valueText($input, 'email', 255);
        if ($firstname === '' || $lastname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['success' => false, 'message' => 'กรุณากรอกชื่อ นามสกุล และอีเมลให้ถูกต้อง']);
        }
        $duplicate = $conn->prepare('SELECT admin_id FROM admin WHERE email = :email AND admin_id <> :id LIMIT 1');
        $duplicate->execute([':email' => $email, ':id' => $adminId]);
        if ($duplicate->fetchColumn()) {
            respond(422, ['success' => false, 'message' => 'อีเมลนี้ถูกใช้งานแล้ว']);
        }
        $name = trim($firstname . ' ' . $lastname);
        $update = $conn->prepare('UPDATE admin SET name = :name, email = :email WHERE admin_id = :id');
        $update->execute([':name' => $name, ':email' => $email, ':id' => $adminId]);
        $_SESSION['admin']['firstname'] = $firstname;
        $_SESSION['admin']['lastname'] = $lastname;
        $_SESSION['admin']['email'] = $email;
        if (!empty($_SESSION['auth'])) {
            $_SESSION['auth']['name'] = $firstname;
        }
        respond(200, ['success' => true, 'message' => 'บันทึกข้อมูลส่วนตัวเรียบร้อยแล้ว']);
    }

    if ($action === 'password_update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(405, ['success' => false, 'message' => 'รองรับเฉพาะ POST']);
        }
        $adminId = currentAdminId();
        if ($adminId <= 0) {
            respond(401, ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน']);
        }
        $current = (string) ($input['current_password'] ?? '');
        $new = (string) ($input['new_password'] ?? '');
        if (strlen($new) < 8) {
            respond(422, ['success' => false, 'message' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร']);
        }
        $passwordStatement = $conn->prepare('SELECT password FROM admin WHERE admin_id = :id LIMIT 1');
        $passwordStatement->execute([':id' => $adminId]);
        $stored = (string) $passwordStatement->fetchColumn();
        if ($stored === '' || !password_verify($current, $stored)) {
            respond(422, ['success' => false, 'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        }
        $update = $conn->prepare('UPDATE admin SET password = :password WHERE admin_id = :id');
        $update->execute([':password' => password_hash($new, PASSWORD_DEFAULT), ':id' => $adminId]);
        respond(200, ['success' => true, 'message' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว']);
    }

    if ($action === 'dashboard') {
        $hasAdminWritingPreview = tableExists($conn, 'admin_writing_test_users');
        $previewUserFilter = $hasAdminWritingPreview
            ? ' WHERE id NOT IN (SELECT user_id FROM admin_writing_test_users)'
            : '';
        $users = tableExists($conn, 'users') ? (int) $conn->query('SELECT COUNT(*) FROM users' . $previewUserFilter)->fetchColumn() : 0;
        $characters = tableExists($conn, 'japanese_characters') ? (int) $conn->query('SELECT COUNT(*) FROM japanese_characters')->fetchColumn() : 0;
        $quizSets = (int) $conn->query('SELECT COUNT(*) FROM quiz_sets WHERE is_active = 1')->fetchColumn();
        $today = 0;
        if (tableExists($conn, 'quizzes')) {
            $today += (int) $conn->query('SELECT COUNT(*) FROM quizzes WHERE DATE(completed_at) = CURDATE()')->fetchColumn();
        }
        if (tableExists($conn, 'writing_sessions')) {
            $previewSessionFilter = $hasAdminWritingPreview
                ? ' AND user_id NOT IN (SELECT user_id FROM admin_writing_test_users)'
                : '';
            $today += (int) $conn->query("SELECT COUNT(*) FROM writing_sessions WHERE practice_mode = 'test' AND DATE(completed_at) = CURDATE()" . $previewSessionFilter)->fetchColumn();
        }
        $average = tableExists($conn, 'quizzes') ? (float) $conn->query('SELECT COALESCE(AVG(percentage), 0) FROM quizzes')->fetchColumn() : 0;
        $newUsers = tableExists($conn, 'users') ? (int) $conn->query('SELECT COUNT(*) FROM users WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())' . ($hasAdminWritingPreview ? ' AND id NOT IN (SELECT user_id FROM admin_writing_test_users)' : ''))->fetchColumn() : 0;
        respond(200, ['success' => true, 'data' => compact('users', 'characters', 'quizSets', 'today', 'average', 'newUsers')]);
    }

    // แก้ไข: ดึงข้อมูลหมวดหมู่วรรคจากตาราง alphabet_groups โดยตรง
    if ($action === 'character_groups') {
        $type = strtolower(valueText(array_merge($_GET, $input), 'type', 20));
        if (!in_array($type, ['hiragana', 'katakana'], true)) {
            $type = 'katakana';
        }

        $statement = $conn->prepare(
            'SELECT g.group_id, g.group_code, g.group_name_th AS group_name,
                    COUNT(c.character_id) AS character_count
             FROM alphabet_groups g
             LEFT JOIN japanese_characters c
               ON LOWER(TRIM(c.char_group)) = LOWER(TRIM(g.group_code))
              AND LOWER(c.char_type) = :type
             GROUP BY g.group_id, g.group_code, g.group_name_th, g.sort_order
             ORDER BY g.sort_order ASC'
        );
        $statement->execute([':type' => $type]);
        respond(200, ['success' => true, 'items' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // แก้ไข: ดึงตัวอักษรตามวรรคจริงจากคอลัมน์ char_group เนื่องจาก japanese_characters ไม่มี group_id
    // พร้อมนับจำนวนกรณีเฉลย (variant_count) จากตาราง writing_svg_answer_references
    if ($action === 'characters') {
        $type = strtolower(valueText(array_merge($_GET, $input), 'type', 20));
        $groupId = isset($input['group_id']) ? (int)$input['group_id'] : (isset($_GET['group_id']) ? (int)$_GET['group_id'] : null);
        $groupCode = trim((string) (isset($input['group_code']) ? $input['group_code'] : ($_GET['group_code'] ?? '')));

        if (!in_array($type, ['hiragana', 'katakana'], true)) {
            respond(422, ['success' => false, 'message' => 'ประเภทตัวอักษรไม่ถูกต้อง']);
        }

        $resolvedGroupCode = $groupCode;
        if ($resolvedGroupCode === '' && $groupId) {
            $groupStatement = $conn->prepare('SELECT group_code FROM alphabet_groups WHERE group_id = :group_id LIMIT 1');
            $groupStatement->execute([':group_id' => $groupId]);
            $resolvedGroupCode = trim((string) $groupStatement->fetchColumn());
        }

        if ($resolvedGroupCode !== '') {
            $statement = $conn->prepare(
                "SELECT c.character_id AS id, c.japanese_char, c.romaji, c.sound_file, c.stroke_count, c.char_group,
                        COUNT(DISTINCT s.svg_reference_id) AS variant_count
                 FROM japanese_characters c
                 LEFT JOIN writing_svg_answer_references s ON s.character_id = c.character_id AND s.is_active = 1
                 WHERE LOWER(c.char_type) = :type AND LOWER(TRIM(c.char_group)) = LOWER(TRIM(:group_code))
                 GROUP BY c.character_id, c.japanese_char, c.romaji, c.sound_file, c.stroke_count, c.char_group
                      ORDER BY CASE LOWER(TRIM(c.char_group))
                          WHEN 'a' THEN 1 WHEN 'ka' THEN 2 WHEN 'sa' THEN 3 WHEN 'ta' THEN 4
                          WHEN 'na' THEN 5 WHEN 'ha' THEN 6 WHEN 'ma' THEN 7 WHEN 'ya' THEN 8
                          WHEN 'ra' THEN 9 WHEN 'wa' THEN 10 WHEN 'n' THEN 11 ELSE 99 END,
                          CASE LOWER(TRIM(c.romaji))
                          WHEN 'a' THEN 1 WHEN 'i' THEN 2 WHEN 'u' THEN 3 WHEN 'e' THEN 4 WHEN 'o' THEN 5
                          ELSE 99 END, LOWER(c.romaji), c.character_id"
            );
            $statement->execute([':type' => $type, ':group_code' => $resolvedGroupCode]);
        } else {
            $statement = $conn->prepare(
                "SELECT c.character_id AS id, c.japanese_char, c.romaji, c.sound_file, c.stroke_count, c.char_group,
                        COUNT(DISTINCT s.svg_reference_id) AS variant_count
                 FROM japanese_characters c
                 LEFT JOIN writing_svg_answer_references s ON s.character_id = c.character_id AND s.is_active = 1
                 WHERE LOWER(c.char_type) = :type
                 GROUP BY c.character_id, c.japanese_char, c.romaji, c.sound_file, c.stroke_count, c.char_group
                      ORDER BY CASE LOWER(TRIM(c.char_group))
                          WHEN 'a' THEN 1 WHEN 'ka' THEN 2 WHEN 'sa' THEN 3 WHEN 'ta' THEN 4
                          WHEN 'na' THEN 5 WHEN 'ha' THEN 6 WHEN 'ma' THEN 7 WHEN 'ya' THEN 8
                          WHEN 'ra' THEN 9 WHEN 'wa' THEN 10 WHEN 'n' THEN 11 ELSE 99 END,
                          CASE LOWER(TRIM(c.romaji))
                          WHEN 'a' THEN 1 WHEN 'i' THEN 2 WHEN 'u' THEN 3 WHEN 'e' THEN 4 WHEN 'o' THEN 5
                          ELSE 99 END, LOWER(c.romaji), c.character_id"
            );
            $statement->execute([':type' => $type]);
        }

        respond(200, ['success' => true, 'items' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'quiz_list') {
        $kind = strtolower(valueText(array_merge($_GET, $input), 'kind', 20));
        if (!in_array($kind, ['choice', 'writing'], true)) {
            respond(422, ['success' => false, 'message' => 'ประเภทแบบทดสอบไม่ถูกต้อง']);
        }
        $search = '%' . valueText(array_merge($_GET, $input), 'search', 180) . '%';
        $statement = $conn->prepare(
            'SELECT q.quiz_set_id AS id, q.title, q.description, q.alphabet_type, q.quiz_kind, q.group_id, q.writing_mode,
                    g.group_code, g.group_name_th, q.time_limit_minutes, q.pass_score, q.is_active,
                    q.updated_at, COUNT(qq.quiz_set_question_id) AS question_count
             FROM quiz_sets q
             LEFT JOIN alphabet_groups g ON g.group_id = q.group_id
             LEFT JOIN quiz_set_questions qq ON qq.quiz_set_id = q.quiz_set_id
             WHERE q.quiz_kind = :kind AND (q.title LIKE :search OR q.description LIKE :search OR g.group_name_th LIKE :search OR g.group_code LIKE :search)
             GROUP BY q.quiz_set_id, q.title, q.description, q.alphabet_type, q.quiz_kind, q.group_id, q.writing_mode,
                      g.group_code, g.group_name_th, q.time_limit_minutes, q.pass_score, q.is_active, q.updated_at
             ORDER BY q.updated_at DESC, q.quiz_set_id DESC'
        );
        $statement->execute([':kind' => $kind, ':search' => $search]);
        respond(200, ['success' => true, 'items' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'quiz_get') {
        $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
        $statement = $conn->prepare('SELECT quiz_set_id, quiz_set_id AS id, title, description, alphabet_type, quiz_kind, group_id, writing_mode, role, order_type, time_limit_minutes, time_limit_seconds, pass_score, is_active FROM quiz_sets WHERE quiz_set_id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $quiz = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$quiz) {
            respond(404, ['success' => false, 'message' => 'ไม่พบชุดแบบทดสอบ']);
        }
        $questions = $conn->prepare('SELECT character_id, max_score, question_text, choice_options, correct_answer, audio_file FROM quiz_set_questions WHERE quiz_set_id = :id ORDER BY sort_order, quiz_set_question_id');
        $questions->execute([':id' => $id]);
        $quiz['questions'] = array_map(static function (array $row): array {
            $row['choice_options'] = $row['choice_options'] ? json_decode($row['choice_options'], true) : [];
            return $row;
        }, $questions->fetchAll(PDO::FETCH_ASSOC));
        respond(200, ['success' => true, 'item' => $quiz]);
    }

    if ($action === 'quiz_create' || $action === 'quiz_update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(405, ['success' => false, 'message' => 'รองรับเฉพาะ POST']);
        }
        $id = $action === 'quiz_update' ? (int) ($input['id'] ?? 0) : null;
        if ($action === 'quiz_update' && !$id) {
            respond(422, ['success' => false, 'message' => 'ไม่พบรหัสชุดแบบทดสอบ']);
        }
        $savedId = saveQuiz($conn, $input, $id);
        respond(200, ['success' => true, 'id' => $savedId, 'message' => $id ? 'แก้ไขชุดแบบทดสอบเรียบร้อยแล้ว' : 'สร้างชุดแบบทดสอบเรียบร้อยแล้ว']);
    }

    if ($action === 'quiz_delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(405, ['success' => false, 'message' => 'รองรับเฉพาะ POST']);
        }
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            respond(422, ['success' => false, 'message' => 'ไม่พบรหัสชุดแบบทดสอบ']);
        }

        $deleteQuestions = $conn->prepare('DELETE FROM quiz_set_questions WHERE quiz_set_id = :id');
        $deleteQuestions->execute([':id' => $id]);

        $delete = $conn->prepare('DELETE FROM quiz_sets WHERE quiz_set_id = :id');
        $delete->execute([':id' => $id]);

        if ($delete->rowCount() === 0) {
            respond(404, ['success' => false, 'message' => 'ไม่พบชุดแบบทดสอบที่ต้องการลบ']);
        }

        respond(200, ['success' => true, 'message' => 'ลบชุดแบบทดสอบเรียบร้อยแล้ว']);
    }

    if ($action === 'member_list') {
        if (!tableExists($conn, 'users')) {
            respond(200, ['success' => true, 'items' => []]);
        }
        $previewMemberFilter = tableExists($conn, 'admin_writing_test_users')
            ? ' WHERE deleted_at IS NULL AND id NOT IN (SELECT user_id FROM admin_writing_test_users)'
            : ' WHERE deleted_at IS NULL';
        $users = $conn->query('SELECT id, firstname, lastname, username, email, created_at FROM users' . $previewMemberFilter . ' ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $quizCounts = [];
        $writingCounts = [];
        if (tableExists($conn, 'quizzes')) {
            $counts = $conn->query('SELECT user_id, COUNT(*) AS total FROM quizzes GROUP BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
            $quizCounts = array_map('intval', $counts);
        }
        if (tableExists($conn, 'writing_sessions')) {
            $counts = $conn->query("SELECT user_id, COUNT(*) AS total FROM writing_sessions WHERE practice_mode = 'test' GROUP BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);
            $writingCounts = array_map('intval', $counts);
        }
        foreach ($users as &$user) {
            $user['quiz_count'] = $quizCounts[(int) $user['id']] ?? 0;
            $user['writing_count'] = $writingCounts[(int) $user['id']] ?? 0;
        }
        unset($user);
        respond(200, ['success' => true, 'items' => $users]);
    }

    if ($action === 'member_delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(405, ['success' => false, 'message' => 'รองรับเฉพาะ POST']);
        }
        $memberId = (int) ($input['id'] ?? 0);
        if ($memberId < 1) {
            respond(422, ['success' => false, 'message' => 'ไม่พบรหัสสมาชิก']);
        }
        $anonEmail = 'deleted_' . $memberId . '_' . time() . '@deleted.local';
        $anonUsername = 'deleted_' . $memberId . '_' . time();
        $delete = $conn->prepare('UPDATE users SET username = :username, email = :email, deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
        $delete->execute([
            ':username' => $anonUsername,
            ':email' => $anonEmail,
            ':id' => $memberId,
        ]);
        if ($delete->rowCount() < 1) {
            respond(404, ['success' => false, 'message' => 'ไม่พบสมาชิกที่ต้องการลบ']);
        }
        respond(200, ['success' => true, 'message' => 'ลบสมาชิกและประวัติการทดสอบเรียบร้อยแล้ว']);
    }

    if ($action === 'result_detail') {
        $resultType = strtolower(valueText(array_merge($_GET, $input), 'type', 20));
        $resultId = (int) ($_GET['id'] ?? $input['id'] ?? 0);
        if ($resultId < 1 || !in_array($resultType, ['matching', 'writing'], true)) {
            respond(422, ['success' => false, 'message' => 'ข้อมูลผลการทดสอบไม่ถูกต้อง']);
        }

        if ($resultType === 'matching' && tableExists($conn, 'quizzes')) {
            $columns = getTableColumns($conn, 'quizzes');
            $answerDetails = in_array('answer_details', $columns, true) ? 'answer_details' : 'NULL AS answer_details';
            $statement = $conn->prepare(
                'SELECT q.id, q.quiz_type, q.score, q.total_questions, q.percentage, q.time_taken, q.completed_at,
                        u.firstname, u.lastname, u.email, ' . $answerDetails . '
                 FROM quizzes q INNER JOIN users u ON u.id = q.user_id
                 WHERE q.id = :id LIMIT 1'
            );
            $statement->execute([':id' => $resultId]);
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                respond(404, ['success' => false, 'message' => 'ไม่พบผลการทดสอบ']);
            }
            $details = json_decode((string) ($result['answer_details'] ?? ''), true);
            $result['answer_details'] = is_array($details) ? $details : [];
            respond(200, ['success' => true, 'type' => 'matching', 'item' => $result]);
        }

        if ($resultType === 'writing' && tableExists($conn, 'writing_sessions')) {
            $statement = $conn->prepare(
                'SELECT w.writing_session_id, w.writing_type, w.group_name, w.writing_mode, w.total_time_ms,
                        w.total_characters, w.completed_characters, w.started_at, w.completed_at,
                        u.firstname, u.lastname, u.email
                 FROM writing_sessions w INNER JOIN users u ON u.id = w.user_id
                 WHERE w.writing_session_id = :id AND w.practice_mode = \'test\' LIMIT 1'
            );
            $statement->execute([':id' => $resultId]);
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                respond(404, ['success' => false, 'message' => 'ไม่พบผลการทดสอบ']);
            }
            $result['attempts'] = [];
            if (tableExists($conn, 'writing_attempts')) {
                $attemptColumns = getTableColumns($conn, 'writing_attempts');
                $shapeAccuracy = in_array('shape_accuracy', $attemptColumns, true) ? 'shape_accuracy' : 'NULL';
                $positionAccuracy = in_array('position_accuracy', $attemptColumns, true)
                    ? 'position_accuracy'
                    : (in_array('alignment_score', $attemptColumns, true) ? 'alignment_score' : 'NULL');
                $startPosition = in_array('is_start_position_correct', $attemptColumns, true)
                    ? 'is_start_position_correct'
                    : 'NULL';
                $attempts = $conn->prepare(
                    'SELECT sequence_no, target_character, romaji, ' . $shapeAccuracy . ' AS shape_accuracy,
                            ' . $positionAccuracy . ' AS position_accuracy, ' . $startPosition . ' AS is_start_position_correct
                     FROM writing_attempts WHERE writing_session_id = :id ORDER BY sequence_no, writing_attempt_id'
                );
                $attempts->execute([':id' => $resultId]);
                $result['attempts'] = $attempts->fetchAll(PDO::FETCH_ASSOC);
            }
            respond(200, ['success' => true, 'type' => 'writing', 'item' => $result]);
        }

        respond(404, ['success' => false, 'message' => 'ไม่พบผลการทดสอบ']);
    }

    if ($action === 'results') {
        $rows = [];
        if (tableExists($conn, 'quizzes') && tableExists($conn, 'users')) {
            $result = $conn->query(
                'SELECT q.id AS result_id, \'matching\' AS result_type, q.user_id, u.firstname, u.lastname, u.email, q.quiz_type AS quiz_title, q.score, q.total_questions,
                        q.percentage, q.completed_at, "-" AS wrong_characters
                 FROM quizzes q INNER JOIN users u ON u.id = q.user_id'
            );
            $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        }
        if (tableExists($conn, 'writing_sessions') && tableExists($conn, 'users')) {
            $result = $conn->query(
                "SELECT w.writing_session_id AS result_id, 'writing' AS result_type, w.user_id, u.firstname, u.lastname, u.email,
                        CONCAT('แบบทดสอบเขียน ', UPPER(w.writing_type), IF(w.group_name <> '', CONCAT(' — ', w.group_name), '')) AS quiz_title,
                        w.completed_characters AS score, w.total_characters AS total_questions,
                        CASE WHEN w.total_characters > 0 THEN ROUND(w.completed_characters * 100 / w.total_characters, 1) ELSE 0 END AS percentage,
                        COALESCE(w.completed_at, w.started_at) AS completed_at, '-' AS wrong_characters
                 FROM writing_sessions w INNER JOIN users u ON u.id = w.user_id
                 WHERE w.practice_mode = 'test'"
                . (tableExists($conn, 'admin_writing_test_users') ? ' AND w.user_id NOT IN (SELECT user_id FROM admin_writing_test_users)' : '')
            );
            $rows = array_merge($rows, $result->fetchAll(PDO::FETCH_ASSOC));
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['completed_at'], (string) $a['completed_at']));
        $average = count($rows) ? round(array_sum(array_map(static fn (array $row): float => (float) $row['percentage'], $rows)) / count($rows), 1) : 0;
        $members = tableExists($conn, 'users') ? (int) $conn->query('SELECT COUNT(*) FROM users' . (tableExists($conn, 'admin_writing_test_users') ? ' WHERE id NOT IN (SELECT user_id FROM admin_writing_test_users)' : ''))->fetchColumn() : 0;
        respond(200, ['success' => true, 'summary' => ['average' => $average, 'tests' => count($rows), 'members' => $members], 'items' => array_slice($rows, 0, 200)]);
    }

    respond(404, ['success' => false, 'message' => 'ไม่พบ API ที่ร้องขอ']);
} catch (Throwable $exception) {
    error_log('admin_api.php: ' . $exception->getMessage());
    respond(500, ['success' => false, 'message' => 'ไม่สามารถดำเนินการกับข้อมูลได้']);
}
