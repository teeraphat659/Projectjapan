<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้ต้องรันจาก command line เท่านั้น\n");
}

require_once __DIR__ . '/db.php';

$rows = $conn->query('SELECT character_id, stroke_order, path_data FROM japanese_character_strokes ORDER BY character_id, stroke_order')->fetchAll(PDO::FETCH_ASSOC);
$grouped = [];
foreach ($rows as $row) {
    $characterId = (int)$row['character_id'];
    $grouped[$characterId][] = [
        'order' => (int)$row['stroke_order'],
        'path' => (string)$row['path_data'],
    ];
}

$conn->beginTransaction();
try {
    $conn->exec('DELETE FROM japanese_character_strokes');
    $insert = $conn->prepare('INSERT INTO japanese_character_strokes (character_id, stroke_order, path_data) VALUES (:character_id, 1, :path_data)');
    foreach ($grouped as $characterId => $strokes) {
        $insert->execute([
            ':character_id' => $characterId,
            ':path_data' => json_encode($strokes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
    $conn->commit();
    echo 'รวมข้อมูลสำเร็จ: ' . count($grouped) . " ตัวอักษร\n";
} catch (Throwable $exception) {
    $conn->rollBack();
    throw $exception;
}
