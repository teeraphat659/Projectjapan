<?php
// --- api.php ---
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../connectapi/db.php';

// รับค่า type (katakana / hiragana)
$type = strtolower($_GET['type'] ?? 'katakana');

try {
    // ตารางหลักที่ใช้เก็บตัวอักษรทั้งหมด
    $preferredTable = 'japanese_characters';
    $legacyHiragana = 'hiragana_chars';
    $legacyKatakana = 'katakana_chars';

    $table = null;
    $stmt = $conn->prepare("SHOW TABLES LIKE :table");

    $stmt->execute([':table' => $preferredTable]);
    if ($stmt->fetchColumn()) {
        $table = $preferredTable;
    } else {
        $table = ($type === 'hiragana') ? $legacyHiragana : $legacyKatakana;
        $stmt->execute([':table' => $table]);
        if (!$stmt->fetchColumn() && $type === 'hiragana') {
            $table = $legacyKatakana;
        }
    }

    // Check for optional media columns
    $soundField = '';
    $soundColumn = $conn->prepare("SHOW COLUMNS FROM {$table} LIKE 'sound_file'");
    $soundColumn->execute();
    if ($soundColumn->fetch(PDO::FETCH_ASSOC)) {
        $soundField = ', sound_file';
    }

    $videoField = '';
    $videoColumn = $conn->prepare("SHOW COLUMNS FROM {$table} LIKE 'video_url'");
    $videoColumn->execute();
    if ($videoColumn->fetch(PDO::FETCH_ASSOC)) {
        $videoField = ', video_url';
    }

    $groupOnly = isset($_GET['group']) && $_GET['group'] === '1';
    $groupName = trim($_GET['group_name'] ?? '');
    $normGroupName = preg_replace('/^\s*วรรค\s*/iu', '', $groupName);

    // ----------------------------------------------------
    // กรณีที่ 1: ดึงเฉพาะตัวอักษรในวรรคที่ระบุ
    // ----------------------------------------------------
    if ($groupName !== '') {
        $query = "SELECT character_id AS id, japanese_char AS character_jp, romaji, image_file AS stroke_image{$soundField}{$videoField}
                  FROM {$table} 
                  WHERE (char_group = :group OR char_group = :norm_group)
                    AND LOWER(char_type) = LOWER(:type)
                                    ORDER BY CASE LOWER(TRIM(REPLACE(char_group, 'วรรค ', '')))
                                        WHEN 'a' THEN 1 WHEN 'ka' THEN 2 WHEN 'sa' THEN 3 WHEN 'ta' THEN 4
                                        WHEN 'na' THEN 5 WHEN 'ha' THEN 6 WHEN 'ma' THEN 7 WHEN 'ya' THEN 8
                                        WHEN 'ra' THEN 9 WHEN 'wa' THEN 10 WHEN 'n' THEN 11 ELSE 99 END,
                                        CASE LOWER(TRIM(romaji))
                                        WHEN 'a' THEN 1 WHEN 'i' THEN 2 WHEN 'u' THEN 3 WHEN 'e' THEN 4 WHEN 'o' THEN 5
                                        ELSE 99 END, LOWER(romaji), character_id"; // เรียงตามตารางโกจูออน
                  
        $stmt = $conn->prepare($query);
        $stmt->execute([
            ':group'      => $groupName,
            ':norm_group' => $normGroupName,
            ':type'       => $type
        ]);

        $chars = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($chars, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ----------------------------------------------------
    // กรณีที่ 2: ดึงรายชื่อวรรคสำหรับสร้าง Dropdown (group=1)
    // ----------------------------------------------------
    if ($groupOnly) {
        // ใช้ CASE บังคับเรียงลำดับตามระบบตัวอักษรภาษาญี่ปุ่น
        $query = "SELECT DISTINCT char_group AS group_name 
                  FROM {$table} 
                  WHERE char_group IS NOT NULL 
                    AND TRIM(char_group) <> '' 
                    AND LOWER(char_type) = LOWER(:type)
                                    ORDER BY CASE LOWER(TRIM(REPLACE(char_group, 'วรรค ', '')))
                                        WHEN 'a' THEN 1 WHEN 'ka' THEN 2 WHEN 'sa' THEN 3 WHEN 'ta' THEN 4
                                        WHEN 'na' THEN 5 WHEN 'ha' THEN 6 WHEN 'ma' THEN 7 WHEN 'ya' THEN 8
                                        WHEN 'ra' THEN 9 WHEN 'wa' THEN 10 WHEN 'n' THEN 11 ELSE 99 END,
                                        LOWER(char_group)";
                  
        $stmt = $conn->prepare($query);
        $stmt->execute([':type' => $type]);
    } 
    // ----------------------------------------------------
    // กรณีที่ 3: ดึงตัวอักษรทั้งหมดตามประเภท
    // ----------------------------------------------------
    else {
        $query = "SELECT character_id AS id, japanese_char AS character_jp, romaji, image_file AS stroke_image{$soundField}{$videoField}
                  FROM {$table} 
                  WHERE LOWER(char_type) = LOWER(:type)
                                    ORDER BY CASE LOWER(TRIM(REPLACE(char_group, 'วรรค ', '')))
                                        WHEN 'a' THEN 1 WHEN 'ka' THEN 2 WHEN 'sa' THEN 3 WHEN 'ta' THEN 4
                                        WHEN 'na' THEN 5 WHEN 'ha' THEN 6 WHEN 'ma' THEN 7 WHEN 'ya' THEN 8
                                        WHEN 'ra' THEN 9 WHEN 'wa' THEN 10 WHEN 'n' THEN 11 ELSE 99 END,
                                        CASE LOWER(TRIM(romaji))
                                        WHEN 'a' THEN 1 WHEN 'i' THEN 2 WHEN 'u' THEN 3 WHEN 'e' THEN 4 WHEN 'o' THEN 5
                                        ELSE 99 END, LOWER(romaji), character_id";
                  
        $stmt = $conn->prepare($query);
        $stmt->execute([':type' => $type]);
    }

    $chars = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($chars, JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {
    http_response_code(500);
    error_log('character api failed: ' . $exception->getMessage());
    echo json_encode(['error' => 'ไม่สามารถโหลดข้อมูลได้'], JSON_UNESCAPED_UNICODE);
}
?>
