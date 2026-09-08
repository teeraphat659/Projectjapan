<?php
require_once __DIR__ . '/../protect.php';
header('Content-Type: application/json; charset=UTF-8');

$action = strtolower(trim($_GET['action'] ?? 'list'));

function sendJson($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $type = 'Katakana';
    $table = 'japanese_characters';

    function normalize_group_param($group) {
        $group = trim($group);
        if ($group === '') {
            return '';
        }
        $group = preg_replace('/^\s*วรรค\s*/iu', '', $group);
        return trim($group);
    }

    if ($action === 'get') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            sendJson(['error' => 'ID ไม่ถูกต้อง'], 400);
        }

        $stmt = $conn->prepare('SELECT character_id AS id, japanese_char, char_group, romaji, stroke_count, image_file, sound_file, char_type FROM ' . $table . ' WHERE character_id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            sendJson(['error' => 'ไม่พบข้อมูลตัวอักษรนี้'], 404);
        }

        // แปลงเป็น Unicode Hex เช่น U+30A2
        $char = (string)($item['japanese_char'] ?? '');
        $codePoint = '';
        if ($char !== '') {
            $codePoint = 'U+' . strtoupper(str_pad(dechex(mb_ord($char, 'UTF-8')), 4, '0', STR_PAD_LEFT));
        }
        $item['code_point'] = $codePoint;

        // ดึงข้อมูลเส้นจาก japanese_character_strokes
        $parsedStrokes = [];
        $animStyle = 'educational';
        $duration = 0.70;

        $checkTable = $conn->query("SHOW TABLES LIKE 'japanese_character_strokes'");
        if ($checkTable && $checkTable->fetchColumn()) {
            $strokeStmt = $conn->prepare('SELECT stroke_id, stroke_order, path_data, animation_style, stroke_duration, start_x, start_y FROM japanese_character_strokes WHERE character_id = :id ORDER BY stroke_order');
            $strokeStmt->execute([':id' => $id]);
            $strokeRows = $strokeStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($strokeRows)) {
                $animStyle = !empty($strokeRows[0]['animation_style']) ? $strokeRows[0]['animation_style'] : 'educational';
                $duration = isset($strokeRows[0]['stroke_duration']) ? (float)$strokeRows[0]['stroke_duration'] : 0.70;

                foreach ($strokeRows as $sRow) {
                    $decoded = json_decode((string)$sRow['path_data'], true);
                    if (is_array($decoded) && isset($decoded[0]['path'])) {
                        foreach ($decoded as $dIdx => $dItem) {
                            $parsedStrokes[] = [
                                'order' => (int)($dItem['order'] ?? ($dIdx + 1)),
                                'path' => (string)$dItem['path'],
                                'start_x' => isset($dItem['start_x']) ? (float)$dItem['start_x'] : null,
                                'start_y' => isset($dItem['start_y']) ? (float)$dItem['start_y'] : null,
                            ];
                        }
                    } else {
                        $parsedStrokes[] = [
                            'order' => (int)$sRow['stroke_order'],
                            'path' => (string)$sRow['path_data'],
                            'start_x' => isset($sRow['start_x']) ? (float)$sRow['start_x'] : null,
                            'start_y' => isset($sRow['start_y']) ? (float)$sRow['start_y'] : null,
                        ];
                    }
                }
            }
        }

        $item['strokes'] = $parsedStrokes;
        $item['animation_style'] = $animStyle;
        $item['stroke_duration'] = $duration;
        $item['has_svg'] = !empty($parsedStrokes);

        sendJson($item);
    }

    if ($action === 'stats') {
        $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM ' . $table . ' WHERE LOWER(char_type) = LOWER(:type)');
        $countStmt->execute([':type' => $type]);
        $total = (int)$countStmt->fetchColumn();

        $groupStmt = $conn->prepare('SELECT COUNT(DISTINCT char_group) AS groups FROM ' . $table . ' WHERE LOWER(char_type) = LOWER(:type)');
        $groupStmt->execute([':type' => $type]);
        $groups = (int)$groupStmt->fetchColumn();

        $imageStmt = $conn->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE LOWER(char_type) = LOWER(:type) AND TRIM(image_file) <> ""');
        $imageStmt->execute([':type' => $type]);
        $imageCount = (int)$imageStmt->fetchColumn();

        $soundStmt = $conn->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE LOWER(char_type) = LOWER(:type) AND TRIM(sound_file) <> ""');
        $soundStmt->execute([':type' => $type]);
        $soundCount = (int)$soundStmt->fetchColumn();

        sendJson([
            'total' => $total,
            'groups' => $groups,
            'with_image' => $imageCount,
            'with_sound' => $soundCount,
        ]);
    }

    $search = trim($_GET['search'] ?? '');
    $group = trim($_GET['group'] ?? '');
    $sort = trim($_GET['sort'] ?? 'order_asc');

    $conditions = ['LOWER(char_type) = LOWER(:type)'];
    $params = [':type' => $type];

    if ($search !== '') {
        $conditions[] = '(LOWER(japanese_char) LIKE LOWER(:search) OR LOWER(romaji) LIKE LOWER(:search) OR LOWER(char_group) LIKE LOWER(:search))';
        $params[':search'] = '%' . $search . '%';
    }

    if ($group !== '') {
        $group = normalize_group_param($group);
        if ($group !== '') {
            $conditions[] = 'LOWER(TRIM(char_group)) = LOWER(:group)';
            $params[':group'] = $group;
        }
    }

    // เรียงตามโกจูออน ไม่ใช่ลำดับที่เพิ่มข้อมูลเข้าฐานข้อมูล
    $gojuonOrder = "CASE LOWER(TRIM(char_group))
        WHEN 'a' THEN 1 WHEN 'ka' THEN 2 WHEN 'sa' THEN 3 WHEN 'ta' THEN 4
        WHEN 'na' THEN 5 WHEN 'ha' THEN 6 WHEN 'ma' THEN 7 WHEN 'ya' THEN 8
        WHEN 'ra' THEN 9 WHEN 'wa' THEN 10 WHEN 'n' THEN 11
        ELSE 99 END,
        CASE LOWER(TRIM(romaji))
        WHEN 'a' THEN 1 WHEN 'i' THEN 2 WHEN 'u' THEN 3 WHEN 'e' THEN 4 WHEN 'o' THEN 5
        ELSE 99 END,
        LOWER(romaji) ASC, character_id ASC";
    $order = $gojuonOrder;
    if ($sort === 'romaji_asc') {
        $order = 'LOWER(romaji) ASC';
    } elseif ($sort === 'stroke_asc') {
        $order = 'stroke_count ASC, character_id ASC';
    }

    $query = 'SELECT character_id AS id, japanese_char, char_group, romaji, stroke_count, image_file, sound_file FROM ' . $table . ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY ' . $order;
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendJson(['items' => $rows]);
} catch (PDOException $exception) {
    error_log('katakana api failed: ' . $exception->getMessage());
    sendJson(['error' => 'ไม่สามารถโหลดข้อมูลได้'], 500);
}
