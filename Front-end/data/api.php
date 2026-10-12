<?php
// --- api.php ---
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../connectapi/db.php';

// 犧｣犧ｱ犧壟ｸ・ｹ謂ｸｲ type (katakana / hiragana)
$type = strtolower($_GET['type'] ?? 'katakana');

try {
    // 犧歩ｸｲ犧｣犧ｲ犧・ｸｫ犧･犧ｱ犧≒ｸ伶ｸｵ犹謂ｹ・ｸ癌ｹ霞ｹ犧≒ｹ・ｸ壟ｸ歩ｸｱ犧ｧ犧ｭ犧ｱ犧≒ｸｩ犧｣犧伶ｸｱ犹霞ｸ・ｸｫ犧｡犧・
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
        $soundField = ", {$table}.sound_file";
    }

    $videoField = '';
    $videoColumn = $conn->prepare("SHOW COLUMNS FROM {$table} LIKE 'video_url'");
    $videoColumn->execute();
    if ($videoColumn->fetch(PDO::FETCH_ASSOC)) {
        $videoField = ", {$table}.video_url";
    }

    $groupOnly = isset($_GET['group']) && $_GET['group'] === '1';
    $groupName = trim($_GET['group_name'] ?? '');
    $normGroupName = preg_replace('/^\s*犧ｧ犧｣犧｣犧Ыs*/iu', '', $groupName);

    // ----------------------------------------------------
    // 犧≒ｸ｣犧内ｸｵ犧伶ｸｵ犹・1: 犧扉ｸｶ犧・ｹ犧霞ｸ樅ｸｲ犧ｰ犧歩ｸｱ犧ｧ犧ｭ犧ｱ犧≒ｸｩ犧｣犹・ｸ吭ｸｧ犧｣犧｣犧・ｸ伶ｸｵ犹謂ｸ｣犧ｰ犧壟ｸｸ
    // ----------------------------------------------------
    if ($groupName !== '') {
        $query = "SELECT {$table}.character_id AS id, {$table}.japanese_char AS character_jp, {$table}.romaji, {$table}.image_file AS stroke_image{$soundField}{$videoField}, s.path_data AS strokes_json, s.animation_style, s.stroke_duration
                  FROM {$table} 
                   LEFT JOIN japanese_character_strokes s ON {$table}.character_id = s.character_id
                  WHERE ({$table}.char_group = :group OR {$table}.char_group = :norm_group)
                    AND LOWER({$table}.char_type) = LOWER(:type)
                                    ORDER BY CASE LOWER(TRIM(REPLACE({$table}.char_group, '犧ｧ犧｣犧｣犧・', '')))
                                        WHEN 'a' THEN 1 WHEN 'ka' THEN 2 WHEN 'sa' THEN 3 WHEN 'ta' THEN 4
                                        WHEN 'na' THEN 5 WHEN 'ha' THEN 6 WHEN 'ma' THEN 7 WHEN 'ya' THEN 8
                                        WHEN 'ra' THEN 9 WHEN 'wa' THEN 10 WHEN 'n' THEN 11 ELSE 99 END,
                                        CASE LOWER(TRIM({$table}.romaji))
                                        WHEN 'wa' THEN 1 WHEN 'wo' THEN 2 WHEN 'n' THEN 3 ELSE 99 END,
                                        CASE LOWER(TRIM({$table}.romaji))
                                        WHEN 'a' THEN 1 WHEN 'i' THEN 2 WHEN 'u' THEN 3 WHEN 'e' THEN 4 WHEN 'o' THEN 5
                                        ELSE 99 END, LOWER({$table}.romaji), {$table}.character_id"; // 犹犧｣犧ｵ犧｢犧・ｸ歩ｸｲ犧｡犧歩ｸｲ犧｣犧ｲ犧・ｹもｸ≒ｸ謂ｸｹ犧ｭ犧ｭ犧・
                  
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
    // 犧≒ｸ｣犧内ｸｵ犧伶ｸｵ犹・2: 犧扉ｸｶ犧・ｸ｣犧ｲ犧｢犧癌ｸｷ犹謂ｸｭ犧ｧ犧｣犧｣犧・ｸｪ犧ｳ犧ｫ犧｣犧ｱ犧壟ｸｪ犧｣犹霞ｸｲ犧・Dropdown (group=1)
    // ----------------------------------------------------
    if ($groupOnly) {
        // 犹・ｸ癌ｹ・CASE 犧壟ｸｱ犧・ｸ・ｸｱ犧壟ｹ犧｣犧ｵ犧｢犧・ｸ･犧ｳ犧扉ｸｱ犧壟ｸ歩ｸｲ犧｡犧｣犧ｰ犧壟ｸ壟ｸ歩ｸｱ犧ｧ犧ｭ犧ｱ犧≒ｸｩ犧｣犧犧ｲ犧ｩ犧ｲ犧財ｸｵ犹謂ｸ巵ｸｸ犹謂ｸ・
        $query = "SELECT DISTINCT char_group AS group_name 
                  FROM {$table} 
                   LEFT JOIN japanese_character_strokes s ON {$table}.character_id = s.character_id
                  WHERE {$table}.char_group IS NOT NULL 
                    AND TRIM({$table}.char_group) <> '' 
                    AND LOWER({$table}.char_type) = LOWER(:type)
                                    ORDER BY CASE LOWER(TRIM(REPLACE({$table}.char_group, '犧ｧ犧｣犧｣犧・', '')))
                                        WHEN 'a' THEN 1 WHEN 'ka' THEN 2 WHEN 'sa' THEN 3 WHEN 'ta' THEN 4
                                        WHEN 'na' THEN 5 WHEN 'ha' THEN 6 WHEN 'ma' THEN 7 WHEN 'ya' THEN 8
                                        WHEN 'ra' THEN 9 WHEN 'wa' THEN 10 WHEN 'n' THEN 11 ELSE 99 END,
                                        LOWER({$table}.char_group)";
                  
        $stmt = $conn->prepare($query);
        $stmt->execute([':type' => $type]);
    } 
    // ----------------------------------------------------
    // 犧≒ｸ｣犧内ｸｵ犧伶ｸｵ犹・3: 犧扉ｸｶ犧・ｸ歩ｸｱ犧ｧ犧ｭ犧ｱ犧≒ｸｩ犧｣犧伶ｸｱ犹霞ｸ・ｸｫ犧｡犧扉ｸ歩ｸｲ犧｡犧巵ｸ｣犧ｰ犹犧犧・
    // ----------------------------------------------------
    else {
        $query = "SELECT {$table}.character_id AS id, {$table}.japanese_char AS character_jp, {$table}.romaji, {$table}.image_file AS stroke_image{$soundField}{$videoField}, s.path_data AS strokes_json, s.animation_style, s.stroke_duration
                  FROM {$table} 
                   LEFT JOIN japanese_character_strokes s ON {$table}.character_id = s.character_id
                  WHERE LOWER({$table}.char_type) = LOWER(:type)
                                    ORDER BY CASE LOWER(TRIM(REPLACE({$table}.char_group, '犧ｧ犧｣犧｣犧・', '')))
                                        WHEN 'a' THEN 1 WHEN 'ka' THEN 2 WHEN 'sa' THEN 3 WHEN 'ta' THEN 4
                                        WHEN 'na' THEN 5 WHEN 'ha' THEN 6 WHEN 'ma' THEN 7 WHEN 'ya' THEN 8
                                        WHEN 'ra' THEN 9 WHEN 'wa' THEN 10 WHEN 'n' THEN 11 ELSE 99 END,
                                        CASE LOWER(TRIM({$table}.romaji))
                                        WHEN 'wa' THEN 1 WHEN 'wo' THEN 2 WHEN 'n' THEN 3 ELSE 99 END,
                                        CASE LOWER(TRIM({$table}.romaji))
                                        WHEN 'a' THEN 1 WHEN 'i' THEN 2 WHEN 'u' THEN 3 WHEN 'e' THEN 4 WHEN 'o' THEN 5
                                        ELSE 99 END, LOWER({$table}.romaji), {$table}.character_id";
                  
        $stmt = $conn->prepare($query);
        $stmt->execute([':type' => $type]);
    }

    $chars = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($chars, JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {
    http_response_code(500);
    error_log('character api failed: ' . $exception->getMessage());
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>


