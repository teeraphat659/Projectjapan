<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=UTF-8');

$tableCheck = $conn->query("SHOW TABLES LIKE 'japanese_character_strokes'");
if (!$tableCheck || !$tableCheck->fetchColumn()) {
    exit("ยังไม่มีตาราง japanese_character_strokes กรุณารัน japanese_character_strokes.sql ก่อน\n");
}

$characters = $conn->query(
    "SELECT character_id, japanese_char
     FROM japanese_characters
    WHERE LOWER(char_type) IN ('katakana', 'hiragana')
       AND japanese_char IS NOT NULL
       AND CHAR_LENGTH(japanese_char) = 1
     ORDER BY character_id"
)->fetchAll(PDO::FETCH_ASSOC);

$insert = $conn->prepare(
    'INSERT INTO japanese_character_strokes (character_id, stroke_order, path_data)
     VALUES (:character_id, :stroke_order, :path_data)
     ON DUPLICATE KEY UPDATE path_data = VALUES(path_data)'
);
$total = 0;

function fetchSvg(string $url): string|false
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'projectjapan-katakana-stroke-importer/1.0',
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return $status === 200 && is_string($body) && $body !== '' ? $body : false;
}

foreach ($characters as $character) {
    $symbol = trim((string)$character['japanese_char']);
    $codePoint = strtoupper(str_pad(dechex(mb_ord($symbol, 'UTF-8')), 5, '0', STR_PAD_LEFT));
    $baseUrl = 'https://raw.githubusercontent.com/KanjiVG/kanjivg/master/kanji/';
    $svg = fetchSvg($baseUrl . strtolower($codePoint) . '.svg');
    if ($svg === false) {
        $svg = fetchSvg($baseUrl . $codePoint . '.svg');
    }
    if ($svg === false) {
        echo "ข้าม {$symbol}: ไม่พบ SVG ({$codePoint})\n";
        continue;
    }

    preg_match_all('/<path[^>]+d="([^"]+)"[^>]+id="[^"]*-s(\d+)"/i', $svg, $matches);
    if (empty($matches[1])) {
        preg_match_all('/<path[^>]+id="[^"]*-s(\d+)"[^>]+d="([^"]+)"/i', $svg, $reverseMatches);
        $matches = [null, $reverseMatches[2] ?? [], $reverseMatches[1] ?? []];
    }
    if (empty($matches[2])) {
        echo "ข้าม {$symbol}: ไม่พบ path\n";
        continue;
    }

    $conn->beginTransaction();
    try {
        $delete = $conn->prepare('DELETE FROM japanese_character_strokes WHERE character_id = :character_id');
        $delete->execute([':character_id' => (int)$character['character_id']]);
        $storedPaths = [];
        foreach ($matches[1] as $index => $pathData) {
            $storedPaths[] = [
                'order' => (int)$matches[2][$index],
                'path' => $pathData,
            ];
        }
        $insert->execute([
            ':character_id' => (int)$character['character_id'],
            ':stroke_order' => 1,
            ':path_data' => json_encode($storedPaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $total += count($storedPaths);
        $conn->commit();
        echo "เพิ่ม {$symbol}: " . count($matches[1]) . " strokes\n";
    } catch (Throwable $exception) {
        $conn->rollBack();
        echo "ผิดพลาด {$symbol}: {$exception->getMessage()}\n";
    }
}

echo "เสร็จสิ้น: {$total} strokes\n";