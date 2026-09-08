<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้ต้องรันจาก command line เท่านั้น\n");
}

require_once __DIR__ . '/db.php';

function pathToPoints(string $pathData): array
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

$conn->exec('CREATE TABLE IF NOT EXISTS writing_answer_references (
    answer_reference_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    character_id INT NOT NULL,
    version_name VARCHAR(100) NOT NULL DEFAULT \'default\',
    source_type VARCHAR(30) NOT NULL DEFAULT \'admin_seeded\',
    trajectory_json JSON NOT NULL,
    stroke_count SMALLINT UNSIGNED NOT NULL,
    canvas_width SMALLINT UNSIGNED NOT NULL DEFAULT 620,
    canvas_height SMALLINT UNSIGNED NOT NULL DEFAULT 420,
    shape_tolerance DECIMAL(6,3) NOT NULL DEFAULT 0.250,
    position_tolerance DECIMAL(6,3) NOT NULL DEFAULT 0.200,
    require_stroke_order TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (answer_reference_id),
    UNIQUE KEY uq_writing_answer_reference (character_id, version_name),
    KEY idx_writing_answer_active (character_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$characters = $conn->query("SELECT c.character_id, c.japanese_char, COUNT(s.stroke_id) AS stroke_count
    FROM japanese_characters c
    INNER JOIN japanese_character_strokes s ON s.character_id = c.character_id
    WHERE c.japanese_char IS NOT NULL
    GROUP BY c.character_id, c.japanese_char
    ORDER BY c.character_id")->fetchAll(PDO::FETCH_ASSOC);
$strokes = $conn->prepare('SELECT stroke_order, path_data FROM japanese_character_strokes WHERE character_id = :character_id ORDER BY stroke_order');
$save = $conn->prepare('INSERT INTO writing_answer_references
    (character_id, version_name, source_type, trajectory_json, stroke_count, canvas_width, canvas_height, shape_tolerance, position_tolerance, require_stroke_order, is_active)
    VALUES (:character_id, \'default\', \'admin_seeded\', :trajectory_json, :stroke_count, 620, 420, 0.250, 0.200, 1, 1)
    ON DUPLICATE KEY UPDATE trajectory_json = VALUES(trajectory_json), stroke_count = VALUES(stroke_count), is_active = 1');
$total = 0;
foreach ($characters as $character) {
    $strokes->execute([':character_id' => (int)$character['character_id']]);
    $referenceStrokes = [];
    foreach ($strokes->fetchAll(PDO::FETCH_ASSOC) as $stroke) {
        $storedPaths = json_decode((string)$stroke['path_data'], true);
        $paths = is_array($storedPaths) && isset($storedPaths[0]['path'])
            ? $storedPaths
            : [['order' => (int)$stroke['stroke_order'], 'path' => $stroke['path_data']]];
        foreach ($paths as $storedPath) {
            $points = pathToPoints((string)($storedPath['path'] ?? ''));
            if (count($points) >= 2) {
                $referenceStrokes[] = ['strokeNumber' => (int)($storedPath['order'] ?? count($referenceStrokes) + 1), 'points' => $points];
            }
        }
    }
    if (!$referenceStrokes) continue;
    $save->execute([
        ':character_id' => (int)$character['character_id'],
        ':trajectory_json' => json_encode(['canvas_width' => 620, 'canvas_height' => 420, 'strokes' => $referenceStrokes], JSON_UNESCAPED_UNICODE),
        ':stroke_count' => count($referenceStrokes),
    ]);
    $total++;
    echo "เพิ่มต้นแบบ {$character['japanese_char']} " . count($referenceStrokes) . " เส้น\n";
}
echo "เสร็จสิ้น: {$total} ตัวอักษร\n";
