<?php
declare(strict_types=1);

require_once __DIR__ . '/../protect.php';
require_once __DIR__ . '/quiz_character_relink_helper.php';
header('Content-Type: application/json; charset=UTF-8');

function strokeManagerResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function strokeManagerInput(): array
{
    $json = json_decode((string)file_get_contents('php://input'), true);
    return is_array($json) ? $json : $_POST;
}

function isTableAvailable(PDO $conn, string $tableName): bool
{
    $stmt = $conn->prepare('SHOW TABLES LIKE :name');
    $stmt->execute([':name' => $tableName]);
    return (bool)$stmt->fetchColumn();
}

function pathToSampledPoints(string $pathData): array
{
    preg_match_all('/[a-zA-Z]|-?\d+(?:\.\d+)?/', $pathData, $matches);
    $tokens = $matches[0] ?? [];
    $points = [];
    $current = ['x' => 0.0, 'y' => 0.0];
    $start = $current;
    $command = '';
    $index = 0;
    $parameterCounts = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Q' => 4, 'T' => 2, 'Z' => 0];

    $addPoint = static function (array $point) use (&$points): void {
        $points[] = ['x' => round((float)$point['x'], 2), 'y' => round((float)$point['y'], 2)];
    };
    $readNumber = static function () use (&$tokens, &$index): ?float {
        if (!isset($tokens[$index]) || preg_match('/[a-zA-Z]/', $tokens[$index])) return null;
        return (float)$tokens[$index++];
    };
    $makePoint = static function (float $x, float $y, bool $relative, array $origin): array {
        return $relative ? ['x' => $origin['x'] + $x, 'y' => $origin['y'] + $y] : ['x' => $x, 'y' => $y];
    };
    $addLine = static function (array $from, array $to) use ($addPoint): void {
        for ($step = 1; $step <= 8; $step++) {
            $ratio = $step / 8;
            $addPoint(['x' => $from['x'] + (($to['x'] - $from['x']) * $ratio), 'y' => $from['y'] + (($to['y'] - $from['y']) * $ratio)]);
        }
    };
    $addCurve = static function (array $from, array $controlOne, array $controlTwo, array $to) use ($addPoint): void {
        for ($step = 1; $step <= 12; $step++) {
            $t = $step / 12;
            $inverse = 1 - $t;
            $addPoint([
                'x' => ($inverse ** 3 * $from['x']) + (3 * $inverse ** 2 * $t * $controlOne['x']) + (3 * $inverse * $t ** 2 * $controlTwo['x']) + ($t ** 3 * $to['x']),
                'y' => ($inverse ** 3 * $from['y']) + (3 * $inverse ** 2 * $t * $controlOne['y']) + (3 * $inverse * $t ** 2 * $controlTwo['y']) + ($t ** 3 * $to['y']),
            ]);
        }
    };

    while ($index < count($tokens)) {
        if (preg_match('/[a-zA-Z]/', $tokens[$index])) $command = $tokens[$index++];
        if ($command === '') break;
        $upper = strtoupper($command);
        if (!isset($parameterCounts[$upper])) break;
        if ($upper === 'Z') {
            $addLine($current, $start);
            $current = $start;
            $command = '';
            continue;
        }
        $values = [];
        for ($parameter = 0; $parameter < $parameterCounts[$upper]; $parameter++) {
            $value = $readNumber();
            if ($value === null) break 2;
            $values[] = $value;
        }
        $relative = strtolower($command) === $command;
        $origin = $current;
        if ($upper === 'M' || $upper === 'L' || $upper === 'T') {
            $current = $makePoint($values[0], $values[1], $relative, $origin);
            if ($upper === 'M') $start = $current;
            $addPoint($current);
            if ($upper === 'M') $command = $relative ? 'l' : 'L';
        } elseif ($upper === 'H' || $upper === 'V') {
            $current = $upper === 'H'
                ? ['x' => $relative ? $current['x'] + $values[0] : $values[0], 'y' => $current['y']]
                : ['x' => $current['x'], 'y' => $relative ? $current['y'] + $values[0] : $values[0]];
            $addPoint($current);
        } elseif ($upper === 'C') {
            $controlOne = $makePoint($values[0], $values[1], $relative, $origin);
            $controlTwo = $makePoint($values[2], $values[3], $relative, $origin);
            $target = $makePoint($values[4], $values[5], $relative, $origin);
            $addCurve($current, $controlOne, $controlTwo, $target);
            $current = $target;
        } elseif ($upper === 'Q') {
            $control = $makePoint($values[0], $values[1], $relative, $origin);
            $target = $makePoint($values[2], $values[3], $relative, $origin);
            $previous = $current;
            for ($step = 1; $step <= 12; $step++) {
                $t = $step / 12;
                $inverse = 1 - $t;
                $current = ['x' => ($inverse ** 2 * $previous['x']) + (2 * $inverse * $t * $control['x']) + ($t ** 2 * $target['x']), 'y' => ($inverse ** 2 * $previous['y']) + (2 * $inverse * $t * $control['y']) + ($t ** 2 * $target['y'])];
                $addPoint($current);
            }
        }
    }
    return $points;
}

function fetchKanjiVgSvgContent(string $symbol): string|false
{
    $codePoint = strtoupper(str_pad(dechex(mb_ord($symbol, 'UTF-8')), 5, '0', STR_PAD_LEFT));
    $baseUrl = 'https://raw.githubusercontent.com/KanjiVG/kanjivg/master/kanji/';
    $candidates = [
        $baseUrl . strtolower($codePoint) . '.svg',
        $baseUrl . $codePoint . '.svg',
    ];
    
    foreach ($candidates as $url) {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'ProjectJapan-StrokeManager/1.0']]);
            $res = @file_get_contents($url, false, $ctx);
            if (is_string($res) && $res !== '') return $res;
            continue;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'ProjectJapan-StrokeManager/1.0',
        ]);
        $body = curl_exec($curl);
        $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($code === 200 && is_string($body) && $body !== '') {
            return $body;
        }
    }

    return false;
}

$input = strokeManagerInput();
$action = strtolower(trim((string)($input['action'] ?? $_GET['action'] ?? '')));

try {
    // -------------------------------------------------------------
    // 1. ตรวจสอบความซ้ำของตัวอักษรแบบ Real-time (Check Duplicate)
    // -------------------------------------------------------------
    if ($action === 'check_duplicate') {
        $char = trim((string)($_GET['char'] ?? $input['char'] ?? ''));
        $type = trim((string)($_GET['type'] ?? $input['type'] ?? 'Katakana'));

        if ($char === '') {
            strokeManagerResponse(200, ['exists' => false]);
        }

        $stmt = $conn->prepare(
            'SELECT character_id, japanese_char, char_type, char_group, romaji, stroke_count 
             FROM japanese_characters 
             WHERE LOWER(char_type) = LOWER(:type) AND japanese_char = :char 
             LIMIT 1'
        );
        $stmt->execute([':type' => $type, ':char' => $char]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        strokeManagerResponse(200, [
            'exists' => (bool)$row,
            'existing_character' => $row ?: null
        ]);
    }

    // -------------------------------------------------------------
    // 2. ระบบออกแบบ SVG ให้อัตโนมัติทันที (Auto-Generate SVG Strokes)
    // -------------------------------------------------------------
    if ($action === 'auto_generate_svg') {
        $char = trim((string)($_GET['char'] ?? $input['char'] ?? ''));
        if (mb_strlen($char, 'UTF-8') !== 1) {
            strokeManagerResponse(422, ['success' => false, 'message' => 'กรุณาระบุตัวอักษร 1 ตัวเท่านั้น']);
        }

        // ตรวจสอบช่วง Unicode ของภาษาญี่ปุ่น
        $ord = mb_ord($char, 'UTF-8');
        $isHiragana = ($ord >= 0x3041 && $ord <= 0x3096);
        $isKatakana = ($ord >= 0x30A1 && $ord <= 0x30FA) || ($ord === 0x30FC);
        $isKanji = ($ord >= 0x4E00 && $ord <= 0x9FAF);

        if (!$isHiragana && !$isKatakana && !$isKanji) {
            strokeManagerResponse(422, [
                'success' => false,
                'message' => 'ตัวอักษรไม่อยู่ในกลุ่มภาษาญี่ปุ่น (Hiragana, Katakana หรือ Kanji)'
            ]);
        }

        $svgXml = fetchKanjiVgSvgContent($char);
        if ($svgXml === false) {
            $hex = strtoupper(dechex($ord));
            strokeManagerResponse(404, [
                'success' => false,
                'message' => "ไม่พบเส้น Vector มาตรฐานของตัวอักษร '{$char}' (U+{$hex}) ใน KanjiVG Repository"
            ]);
        }

        // สกัดข้อมูล Path ทั้งหมดด้วย DOMDocument
        $strokes = [];
        $dom = new DOMDocument();
        if (@$dom->loadXML($svgXml)) {
            $pathNodes = $dom->getElementsByTagName('path');
            foreach ($pathNodes as $idx => $pNode) {
                $id = $pNode->getAttribute('id');
                $d = trim($pNode->getAttribute('d'));
                if ($d === '') continue;

                $order = $idx + 1;
                if (preg_match('/-s(\d+)$/', $id, $om)) {
                    $order = (int)$om[1];
                }

                $startX = null;
                $startY = null;
                if (preg_match('/^[Mm]\s*(-?[\d.]+)[,\s]+(-?[\d.]+)/', $d, $mPoint)) {
                    $startX = round((float)$mPoint[1], 2);
                    $startY = round((float)$mPoint[2], 2);
                }

                $strokes[] = [
                    'order' => $order,
                    'path' => $d,
                    'start_x' => $startX,
                    'start_y' => $startY,
                ];
            }
        }

        // Fallback ด้วย Regex หาก DOM โหลดไม่ได้
        if (empty($strokes)) {
            preg_match_all('/<path[^>]+(?:id="[^"]*-s(\d+)"[^>]+d="([^"]+)"|d="([^"]+)"[^>]+id="[^"]*-s(\d+)")/i', $svgXml, $matches, PREG_SET_ORDER);
            foreach ($matches as $idx => $m) {
                $order = !empty($m[1]) ? (int)$m[1] : (!empty($m[4]) ? (int)$m[4] : ($idx + 1));
                $d = !empty($m[2]) ? $m[2] : (!empty($m[3]) ? $m[3] : '');
                if ($d === '') continue;

                $startX = null;
                $startY = null;
                if (preg_match('/^[Mm]\s*(-?[\d.]+)[,\s]+(-?[\d.]+)/', trim($d), $mPoint)) {
                    $startX = round((float)$mPoint[1], 2);
                    $startY = round((float)$mPoint[2], 2);
                }

                $strokes[] = [
                    'order' => $order,
                    'path' => $d,
                    'start_x' => $startX,
                    'start_y' => $startY,
                ];
            }
        }

        // เรียงลำดับเส้นตาม stroke_order
        usort($strokes, static fn($a, $b) => $a['order'] <=> $b['order']);

        if (empty($strokes)) {
            strokeManagerResponse(500, ['success' => false, 'message' => 'ไม่สามารถแยกข้อมูลเส้นจากไฟล์ SVG ได้']);
        }

        strokeManagerResponse(200, [
            'success' => true,
            'character' => $char,
            'code_point' => 'U+' . strtoupper(str_pad(dechex($ord), 4, '0', STR_PAD_LEFT)),
            'detected_type' => $isKatakana ? 'Katakana' : ($isHiragana ? 'Hiragana' : 'Kanji'),
            'stroke_count' => count($strokes),
            'strokes' => $strokes,
            'view_box' => '0 0 109 109',
        ]);
    }

    // -------------------------------------------------------------
    // 3. บันทึกตัวอักษรใหม่ + เส้น SVG พร้อมกัน (Save Character with Strokes)
    // -------------------------------------------------------------
    if ($action === 'create_character_with_strokes') {
        $japaneseChar = trim((string)($input['japanese_char'] ?? ''));
        $charType = trim((string)($input['char_type'] ?? 'Katakana'));
        $charGroup = trim((string)($input['char_group'] ?? ''));
        $romaji = strtolower(trim((string)($input['romaji'] ?? '')));
        $imageFile = trim((string)($input['image_file'] ?? ''));
        $soundFile = trim((string)($input['sound_file'] ?? ''));
        $animationStyle = trim((string)($input['animation_style'] ?? 'educational'));
        $strokeDuration = max(0.2, min(3.0, (float)($input['stroke_duration'] ?? 0.70)));
        $strokes = $input['strokes'] ?? [];

        // 1. ตรวจสอบความยาวตัวอักษร
        if (mb_strlen($japaneseChar, 'UTF-8') !== 1) {
            strokeManagerResponse(422, ['success' => false, 'message' => 'ตัวอักษรต้องมีความยาว 1 ตัวอักษรเท่านั้น']);
        }

        // 2. ตรวจสอบประเภทและช่วง Unicode
        $ord = mb_ord($japaneseChar, 'UTF-8');
        $isHiragana = ($ord >= 0x3041 && $ord <= 0x3096);
        $isKatakana = ($ord >= 0x30A1 && $ord <= 0x30FA) || ($ord === 0x30FC);

        if (strtolower($charType) === 'katakana' && !$isKatakana) {
            strokeManagerResponse(422, ['success' => false, 'message' => "ตัวอักษร '{$japaneseChar}' ไม่ใช่อักษร Katakana (ห้ามใส่ภาษาไทย, ตัวเลข หรืออังกฤษ)"]);
        } elseif (strtolower($charType) === 'hiragana' && !$isHiragana) {
            strokeManagerResponse(422, ['success' => false, 'message' => "ตัวอักษร '{$japaneseChar}' ไม่ใช่อักษร Hiragana"]);
        } elseif (!in_array(strtolower($charType), ['katakana', 'hiragana'], true)) {
            strokeManagerResponse(422, ['success' => false, 'message' => 'ประเภทตัวอักษรต้องเป็น Katakana หรือ Hiragana']);
        }

        // 3. ตรวจสอบโรมาจิ
        if (!preg_match('/^[a-z]{1,6}$/', $romaji)) {
            strokeManagerResponse(422, ['success' => false, 'message' => 'คำอ่านโรมาจิต้องเป็นตัวอักษรภาษาอังกฤษพิมพ์เล็ก 1-6 ตัว']);
        }

        // 4. ตรวจสอบวรรค
        $allowedGroups = ['a', 'ka', 'sa', 'ta', 'na', 'ha', 'ma', 'ya', 'ra', 'wa', 'n'];
        $cleanGroup = preg_replace('/^\s*วรรค\s+/iu', '', $charGroup);
        if ($cleanGroup === '' || !in_array($cleanGroup, $allowedGroups, true)) {
            strokeManagerResponse(422, ['success' => false, 'message' => 'วรรคของตัวอักษรไม่ถูกต้อง']);
        }

        // 5. ตรวจสอบข้อมูลเส้น SVG
        if (!is_array($strokes) || count($strokes) < 1) {
            strokeManagerResponse(422, ['success' => false, 'message' => 'ต้องมีข้อมูลเส้น SVG อย่างน้อย 1 เส้น (กรุณากดออกแบบ SVG ก่อน)']);
        }

        foreach ($strokes as $idx => $stroke) {
            $pathData = trim((string)($stroke['path'] ?? ''));
            if ($pathData === '' || !preg_match('/^[MmLlHhVvCcSsQqTtAaZz0-9\s,.\-]+$/', $pathData) || !preg_match('/^[Mm]/', $pathData)) {
                strokeManagerResponse(422, ['success' => false, 'message' => "เส้นที่ " . ($idx + 1) . " มีรูปแบบ SVG Path ไม่ถูกต้อง"]);
            }
        }

        // 6. ตรวจสอบไฟล์เสียง (ถ้ามี)
        if ($soundFile !== '') {
            $soundExt = strtolower(pathinfo((string)parse_url($soundFile, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (!in_array($soundExt, ['mp3', 'wav', 'ogg', 'm4a', 'webm'], true)) {
                strokeManagerResponse(422, ['success' => false, 'message' => 'นามสกุลไฟล์เสียงต้องเป็น .mp3, .wav, .ogg หรือ .m4a']);
            }
        }

        // 7. ตรวจสอบความซ้ำในฐานข้อมูล
        $dupStmt = $conn->prepare('SELECT COUNT(*) FROM japanese_characters WHERE LOWER(char_type) = LOWER(:type) AND japanese_char = :char');
        $dupStmt->execute([':type' => $charType, ':char' => $japaneseChar]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            strokeManagerResponse(409, [
                'success' => false,
                'message' => "ตัวอักษร '{$japaneseChar}' ในหมวด {$charType} มีอยู่แล้วในระบบ ไม่สามารถเพิ่มซ้ำได้"
            ]);
        }

        // 8. เริ่มต้นบันทึกด้วย Database Transaction
        $conn->beginTransaction();
        try {
            // A. เพิ่มใน japanese_characters
            $charInsert = $conn->prepare(
                'INSERT INTO japanese_characters (japanese_char, char_type, char_group, romaji, stroke_count, image_file, sound_file, stroke_id)
                 VALUES (:char, :type, :group, :romaji, :count, :img, :snd, 0)'
            );
            $charInsert->execute([
                ':char' => $japaneseChar,
                ':type' => ucfirst(strtolower($charType)),
                ':group' => $cleanGroup,
                ':romaji' => $romaji,
                ':count' => count($strokes),
                ':img' => $imageFile !== '' ? $imageFile : null,
                ':snd' => $soundFile !== '' ? $soundFile : null,
            ]);
            $characterId = (int)$conn->lastInsertId();

            // B. เพิ่มใน japanese_character_strokes
            // ตามฟอร์แมตใน phpMyAdmin: path_data เก็บ JSON array ของ object เส้นทั้งหมด
            $formattedStrokes = [];
            foreach ($strokes as $idx => $st) {
                $formattedStrokes[] = [
                    'order' => (int)($st['order'] ?? ($idx + 1)),
                    'path' => (string)$st['path'],
                    'start_x' => isset($st['start_x']) ? (float)$st['start_x'] : null,
                    'start_y' => isset($st['start_y']) ? (float)$st['start_y'] : null,
                ];
            }

            $strokeInsert = $conn->prepare(
                'INSERT INTO japanese_character_strokes 
                    (character_id, stroke_order, path_data, animation_style, stroke_duration, start_x, start_y)
                 VALUES 
                    (:cid, 1, :path_data, :anim_style, :duration, :sx, :sy)'
            );
            $strokeInsert->execute([
                ':cid' => $characterId,
                ':path_data' => json_encode($formattedStrokes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':anim_style' => $animationStyle,
                ':duration' => $strokeDuration,
                ':sx' => $formattedStrokes[0]['start_x'] ?? null,
                ':sy' => $formattedStrokes[0]['start_y'] ?? null,
            ]);

            // C. เพิ่ม Default Reference ลงใน writing_answer_references และ writing_svg_answer_references เพื่อให้ระบบตรวจข้อสอบใช้งานได้ทันที
            $referenceStrokes = [];
            foreach ($formattedStrokes as $idx => $st) {
                $points = pathToSampledPoints($st['path']);
                if (count($points) >= 2) {
                    $referenceStrokes[] = [
                        'strokeNumber' => (int)($st['order'] ?? ($idx + 1)),
                        'points' => $points,
                    ];
                }
            }

            if ($referenceStrokes) {
                $traj = [
                    'canvas_width' => 620,
                    'canvas_height' => 420,
                    'strokes' => $referenceStrokes,
                ];

                if (isTableAvailable($conn, 'writing_answer_references')) {
                    $arStmt = $conn->prepare(
                        'INSERT INTO writing_answer_references 
                            (character_id, version_name, source_type, trajectory_json, stroke_count, canvas_width, canvas_height, shape_tolerance, position_tolerance, require_stroke_order, is_active)
                         VALUES 
                            (:cid, "default", "admin_seeded", :traj, :cnt, 620, 420, 0.250, 0.200, 1, 1)
                         ON DUPLICATE KEY UPDATE trajectory_json = VALUES(trajectory_json), stroke_count = VALUES(stroke_count), is_active = 1'
                    );
                    $arStmt->execute([
                        ':cid' => $characterId,
                        ':traj' => json_encode($traj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':cnt' => count($referenceStrokes),
                    ]);
                }

                if (isTableAvailable($conn, 'writing_svg_answer_references')) {
                    $svgRefStmt = $conn->prepare(
                        'INSERT INTO writing_svg_answer_references
                            (character_id, version_name, view_box, trajectory_json, stroke_count, canvas_width, canvas_height, shape_tolerance, position_tolerance, require_stroke_order, is_active, created_by)
                         VALUES
                            (:cid, "default", "0 0 109 109", :traj, :cnt, 620, 420, 0.250, 0.200, 1, 1, :uid)
                         ON DUPLICATE KEY UPDATE trajectory_json = VALUES(trajectory_json), stroke_count = VALUES(stroke_count), is_active = 1'
                    );
                    $svgRefStmt->execute([
                        ':cid' => $characterId,
                        ':traj' => json_encode($traj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':cnt' => count($referenceStrokes),
                        ':uid' => (int)($_SESSION['admin']['admin_id'] ?? 0),
                    ]);
                }
            }

            relinkQuizQuestionsForCharacter($conn, $characterId, $japaneseChar, $romaji, $charType);
   function addCharacterToWritingQuizSets(
    PDO $conn,
    int $characterId,
    string $japaneseChar,
    string $romaji,
    string $charGroup
): void {
    // ใช้ชุดแบบทดสอบ writing ที่มีอยู่ของกลุ่มเดียวกัน
    $stmt = $conn->prepare(
        'SELECT id
         FROM writing_quiz_sets
         WHERE LOWER(char_group) = LOWER(:char_group)
         LIMIT 1'
    );
    $stmt->execute([':char_group' => $charGroup]);

    $quizSetId = $stmt->fetchColumn();

    if (!$quizSetId) {
        return;
    }

    $insert = $conn->prepare(
        'INSERT INTO writing_quiz_characters (quiz_set_id, character_id)
         SELECT :quiz_set_id, :character_id
         WHERE NOT EXISTS (
             SELECT 1
             FROM writing_quiz_characters
             WHERE quiz_set_id = :quiz_set_id_check
               AND character_id = :character_id_check
         )'
    );

    $insert->execute([
        ':quiz_set_id' => (int)$quizSetId,
        ':character_id' => $characterId,
        ':quiz_set_id_check' => (int)$quizSetId,
        ':character_id_check' => $characterId,
    ]);
}
            $conn->commit();

            strokeManagerResponse(200, [
                'success' => true,
                'message' => "เพิ่มตัวอักษร '{$japaneseChar}' พร้อมเส้น SVG เรียบร้อยแล้ว",
                'character_id' => $characterId,
                'stroke_count' => count($formattedStrokes),
            ]);
        } catch (Throwable $e) {
            $conn->rollBack();
            error_log('create_character_with_strokes error: ' . $e->getMessage());
            strokeManagerResponse(500, [
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในการบันทึกฐานข้อมูล: ' . $e->getMessage(),
            ]);
        }
    }

    strokeManagerResponse(404, ['success' => false, 'message' => 'ไม่พบ action ที่ร้องขอ']);
} catch (Throwable $e) {
    error_log('character_stroke_manager_api exception: ' . $e->getMessage());
    strokeManagerResponse(500, ['success' => false, 'message' => 'เกิดข้อผิดพลาดของระบบ: ' . $e->getMessage()]);
}
