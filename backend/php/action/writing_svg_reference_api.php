<?php
declare(strict_types=1);

require_once __DIR__ . '/../protect.php';
header('Content-Type: application/json; charset=UTF-8');

function referenceResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function referenceInput(): array
{
    $json = json_decode((string)file_get_contents('php://input'), true);
    return is_array($json) ? $json : $_POST;
}

function tableExists(PDO $connection, string $tableName): bool
{
    $statement = $connection->prepare('SHOW TABLES LIKE :table_name');
    $statement->execute([':table_name' => $tableName]);
    return (bool)$statement->fetchColumn();
}

function validReferenceTrajectory(mixed $value): ?array
{
    if (is_string($value)) {
        $value = json_decode($value, true);
    }
    if (!is_array($value) || !is_array($value['strokes'] ?? null) || count($value['strokes']) < 1) {
        return null;
    }

    $strokes = [];
    foreach (array_values($value['strokes']) as $index => $stroke) {
        if (!is_array($stroke) || !is_array($stroke['points'] ?? null) || count($stroke['points']) < 2) {
            return null;
        }
        $points = [];
        foreach ($stroke['points'] as $point) {
            if (!is_array($point) || !is_numeric($point['x'] ?? null) || !is_numeric($point['y'] ?? null)) {
                return null;
            }
            $points[] = ['x' => round((float)$point['x'], 2), 'y' => round((float)$point['y'], 2)];
        }
        $strokes[] = ['strokeNumber' => $index + 1, 'points' => $points];
    }

    return ['canvas_width' => 620, 'canvas_height' => 420, 'strokes' => $strokes];
}

function svgPathToTrajectoryPoints(string $pathData): array
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

function resampleStroke(array $points, int $n = 16): array {
    if (count($points) < 2) return array_fill(0, $n, $points[0] ?? ['x' => 0, 'y' => 0]);
    $totalLen = 0.0;
    $lengths = [0.0];
    for ($i = 1; $i < count($points); $i++) {
        $dx = (float)$points[$i]['x'] - (float)$points[$i-1]['x'];
        $dy = (float)$points[$i]['y'] - (float)$points[$i-1]['y'];
        $totalLen += sqrt($dx * $dx + $dy * $dy);
        $lengths[] = $totalLen;
    }
    if ($totalLen <= 0.0001) {
        return array_fill(0, $n, ['x' => (float)$points[0]['x'], 'y' => (float)$points[0]['y']]);
    }
    $interval = $totalLen / ($n - 1);
    $resampled = [['x' => (float)$points[0]['x'], 'y' => (float)$points[0]['y']]];
    $currentIdx = 0;
    for ($step = 1; $step < $n - 1; $step++) {
        $targetDist = $step * $interval;
        while ($currentIdx < count($lengths) - 1 && $lengths[$currentIdx + 1] < $targetDist) {
            $currentIdx++;
        }
        $segStartDist = $lengths[$currentIdx];
        $segEndDist = $lengths[$currentIdx + 1];
        $segDist = max(0.0001, $segEndDist - $segStartDist);
        $ratio = ($targetDist - $segStartDist) / $segDist;
        $resampled[] = [
            'x' => round((float)$points[$currentIdx]['x'] + $ratio * ((float)$points[$currentIdx + 1]['x'] - (float)$points[$currentIdx]['x']), 2),
            'y' => round((float)$points[$currentIdx]['y'] + $ratio * ((float)$points[$currentIdx + 1]['y'] - (float)$points[$currentIdx]['y']), 2),
        ];
    }
    $last = end($points);
    $resampled[] = ['x' => (float)$last['x'], 'y' => (float)$last['y']];
    return $resampled;
}

function normalizeTrajectoryForComparison(array $trajectory): array
{
    $allPoints = [];
    foreach ($trajectory['strokes'] ?? [] as $stroke) {
        foreach ($stroke['points'] ?? [] as $point) {
            if (is_numeric($point['x'] ?? null) && is_numeric($point['y'] ?? null)) $allPoints[] = $point;
        }
    }
    if (!$allPoints) return $trajectory;

    $minX = min(array_column($allPoints, 'x'));
    $maxX = max(array_column($allPoints, 'x'));
    $minY = min(array_column($allPoints, 'y'));
    $maxY = max(array_column($allPoints, 'y'));
    $centerX = ($minX + $maxX) / 2;
    $centerY = ($minY + $maxY) / 2;
    $scale = max(1.0, $maxX - $minX, $maxY - $minY);

    foreach ($trajectory['strokes'] ?? [] as $strokeIndex => $stroke) {
        foreach ($stroke['points'] ?? [] as $pointIndex => $point) {
            $trajectory['strokes'][$strokeIndex]['points'][$pointIndex] = [
                'x' => round((((float)$point['x'] - $centerX) / $scale) * 300 + 310, 2),
                'y' => round((((float)$point['y'] - $centerY) / $scale) * 300 + 210, 2),
            ];
        }
    }
    return $trajectory;
}

function calculateTrajectorySimilarity(array $trajA, array $trajB): float {
    // Reference data can come from an SVG viewBox or from the 620×420 canvas.
    // Compare the handwriting shape, not its stored size or screen position.
    $trajA = normalizeTrajectoryForComparison($trajA);
    $trajB = normalizeTrajectoryForComparison($trajB);
    $strokesA = $trajA['strokes'] ?? [];
    $strokesB = $trajB['strokes'] ?? [];
    if (!$strokesA || !$strokesB) return 0.0;

    $countA = count($strokesA);
    $countB = count($strokesB);

    if ($countA === $countB) {
        $totalDist = 0.0;
        $sampleCount = 16;
        $pointTotal = 0;
        for ($s = 0; $s < $countA; $s++) {
            $ptsA = resampleStroke($strokesA[$s]['points'] ?? [], $sampleCount);
            $ptsB = resampleStroke($strokesB[$s]['points'] ?? [], $sampleCount);
            for ($p = 0; $p < $sampleCount; $p++) {
                $dx = (float)$ptsA[$p]['x'] - (float)$ptsB[$p]['x'];
                $dy = (float)$ptsA[$p]['y'] - (float)$ptsB[$p]['y'];
                $totalDist += sqrt($dx * $dx + $dy * $dy);
                $pointTotal++;
            }
        }
        $avgDist = $pointTotal > 0 ? $totalDist / $pointTotal : 999.0;
        return max(0.0, min(1.0, 1.0 - ($avgDist / 80.0)));
    } else {
        $ptsA = [];
        $ptsB = [];
        foreach ($strokesA as $s) {
            $ptsA = array_merge($ptsA, resampleStroke($s['points'] ?? [], 12));
        }
        foreach ($strokesB as $s) {
            $ptsB = array_merge($ptsB, resampleStroke($s['points'] ?? [], 12));
        }
        $ptsA = resampleStroke($ptsA, 24);
        $ptsB = resampleStroke($ptsB, 24);
        $totalDist = 0.0;
        for ($p = 0; $p < 24; $p++) {
            $dx = (float)$ptsA[$p]['x'] - (float)$ptsB[$p]['x'];
            $dy = (float)$ptsA[$p]['y'] - (float)$ptsB[$p]['y'];
            $totalDist += sqrt($dx * $dx + $dy * $dy);
        }
        $avgDist = $totalDist / 24;
        return max(0.0, min(0.78, 1.0 - ($avgDist / 80.0)));
    }
}

function synthesizeVariation(array $baseTrajectory, string $style, float $intensity = 1.0, int $seed = 0): array {
    $strokes = $baseTrajectory['strokes'] ?? [];
    if (!$strokes) return $baseTrajectory;

    $allPts = [];
    foreach ($strokes as $s) {
        foreach ($s['points'] ?? [] as $pt) {
            $allPts[] = $pt;
        }
    }
    if (!$allPts) return $baseTrajectory;

    $minX = min(array_column($allPts, 'x'));
    $maxX = max(array_column($allPts, 'x'));
    $minY = min(array_column($allPts, 'y'));
    $maxY = max(array_column($allPts, 'y'));
    $cx = ($minX + $maxX) / 2;
    $cy = ($minY + $maxY) / 2;
    // Every generated answer keeps the same number and order of strokes as the
    // original.  A variation is a natural change in handwriting, not a new way
    // to write the character; merging strokes makes both the preview and scoring
    // incorrect (for example, ア must remain two strokes).
    $direction = ($seed % 2 === 0) ? 1.0 : -1.0;
    $phase = (($seed % 7) - 3) * 0.38;
    $config = match ($style) {
        'cursive' => ['rotation' => 0.014, 'shear' => 0.026, 'scale_x' => 1.015, 'scale_y' => 0.985, 'curve' => 4.0],
        'flowing' => ['rotation' => -0.018, 'shear' => 0.012, 'scale_x' => 1.020, 'scale_y' => 1.005, 'curve' => 6.5],
        'tilted_brush' => ['rotation' => 0.034, 'shear' => 0.058, 'scale_x' => 0.985, 'scale_y' => 1.025, 'curve' => 3.2],
        default => ['rotation' => -0.010, 'shear' => -0.018, 'scale_x' => 0.935, 'scale_y' => 0.945, 'curve' => 2.8],
    };
    $rotation = $config['rotation'] * $intensity * $direction;
    $cos = cos($rotation);
    $sin = sin($rotation);
    $scaleX = 1 + (($config['scale_x'] - 1) * $intensity);
    $scaleY = 1 + (($config['scale_y'] - 1) * $intensity);
    $shear = $config['shear'] * $intensity * $direction;
    $newStrokes = [];
    foreach ($strokes as $idx => $stroke) {
        $points = $stroke['points'] ?? [];
        if (count($points) < 2) continue;
        $pts = resampleStroke($points, max(16, min(36, count($points))));
        $first = $pts[0];
        $last = $pts[count($pts) - 1];
        $dx = (float)$last['x'] - (float)$first['x'];
        $dy = (float)$last['y'] - (float)$first['y'];
        $length = max(1.0, sqrt($dx * $dx + $dy * $dy));
        $normalX = -$dy / $length;
        $normalY = $dx / $length;
        $strokeDirection = ($idx % 2 === 0) ? 1.0 : -1.0;

        foreach ($pts as $pointIndex => &$point) {
            $t = $pointIndex / max(1, count($pts) - 1);
            // Smooth perpendicular movement keeps endpoints stable and avoids the
            // sharp hooks and broken-looking paths produced by the old generator.
            $envelope = sin(M_PI * $t);
            $offset = $envelope * ($config['curve'] * $intensity)
                * (1 + 0.22 * sin((2 * M_PI * $t) + $phase + $idx))
                * $strokeDirection * $direction;
            $x = (float)$point['x'] + ($normalX * $offset);
            $y = (float)$point['y'] + ($normalY * $offset);
            $relX = $x - $cx;
            $relY = $y - $cy;
            $point['x'] = round($cx + (($relX * $cos - $relY * $sin) * $scaleX) + ($relY * $shear), 2);
            $point['y'] = round($cy + (($relX * $sin + $relY * $cos) * $scaleY), 2);
        }
        unset($point);
        $newStrokes[] = ['strokeNumber' => $idx + 1, 'points' => $pts];
    }

    return [
        'canvas_width' => $baseTrajectory['canvas_width'] ?? 620,
        'canvas_height' => $baseTrajectory['canvas_height'] ?? 420,
        'strokes' => $newStrokes
    ];
}

function generateGuaranteedDistinctVariant(array $baseTrajectory, array $existingVariants, string $requestedStyle = 'auto'): array {
    $styles = ['cursive', 'flowing', 'tilted_brush', 'compact'];
    if ($requestedStyle !== 'auto' && in_array($requestedStyle, $styles, true)) {
        $styles = array_diff($styles, [$requestedStyle]);
        array_unshift($styles, $requestedStyle);
    }

    $bestCandidate = null;
    $bestMaxSim = 1.0;
    $bestStyleUsed = '';

    foreach ($styles as $styleIndex => $style) {
        for ($intensity = 1.0; $intensity <= 2.2; $intensity += 0.3) {
            $candidate = synthesizeVariation($baseTrajectory, $style, $intensity, count($existingVariants) + ($styleIndex * 17) + (int)round($intensity * 10));
            $maxSim = 0.0;
            foreach ($existingVariants as $variant) {
                $traj = $variant['trajectory'] ?? null;
                if (!$traj) continue;
                $sim = calculateTrajectorySimilarity($candidate, $traj);
                if ($sim > $maxSim) {
                    $maxSim = $sim;
                }
            }
            if ($maxSim < $bestMaxSim) {
                $bestMaxSim = $maxSim;
                $bestCandidate = $candidate;
                $bestStyleUsed = $style;
            }
            // A natural handwriting variation need not be distorted to be useful.
            // At least 10% geometric difference avoids duplicates while preserving
            // a clean, recognisable character.
            if ($maxSim < 0.90) {
                break 2;
            }
        }
    }

    $styleLabels = [
        'cursive' => 'แบบกึ่งหวัดลื่นไหล (คงลำดับเส้น)',
        'flowing' => 'แบบตวัดหางและเส้นโค้งพลิ้ว (Calligraphic Flow)',
        'tilted_brush' => 'แบบเอียงพลิ้ว (Calligraphic Slant)',
        'compact' => 'แบบเขียนเร็วรวบรัด (Fast Shorthand)'
    ];

    return [
        'trajectory' => $bestCandidate ?: $baseTrajectory,
        'style' => $bestStyleUsed ?: 'flowing',
        'style_label' => $styleLabels[$bestStyleUsed] ?? 'รูปแบบทางเลือกใหม่',
        'max_similarity' => round($bestMaxSim, 4),
        'difference_percentage' => round((1.0 - $bestMaxSim) * 100, 1),
        'is_duplicate' => ($bestMaxSim >= 0.90),
    ];
}

$input = referenceInput();
$action = strtolower(trim((string)($input['action'] ?? $_GET['action'] ?? '')));

try {
    if ($action === 'characters') {
        $type = strtolower(trim((string)($_GET['type'] ?? 'katakana')));
        if (!in_array($type, ['hiragana', 'katakana'], true)) {
            referenceResponse(422, ['success' => false, 'message' => 'ประเภทตัวอักษรไม่ถูกต้อง']);
        }
        $statement = $conn->prepare(
            'SELECT c.character_id AS id, c.japanese_char, c.romaji, c.char_group, c.stroke_count
             FROM japanese_characters c
             LEFT JOIN alphabet_groups g ON LOWER(TRIM(g.group_code)) = LOWER(TRIM(c.char_group))
             WHERE LOWER(c.char_type) = :type
             ORDER BY g.sort_order, c.japanese_char'
        );
        $statement->execute([':type' => $type]);
        referenceResponse(200, ['success' => true, 'items' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'list') {
        $type = strtolower(trim((string)($_GET['type'] ?? '')));
        $where = $type !== '' && in_array($type, ['hiragana', 'katakana'], true)
            ? 'WHERE LOWER(c.char_type) = :type'
            : '';
        $statement = $conn->prepare(
            "SELECT c.character_id AS id, c.japanese_char, c.romaji, c.char_type,
                    c.char_group, c.stroke_count, g.group_name_th,
                    COUNT(s.svg_reference_id) AS variant_count,
                    MAX(s.updated_at) AS reference_updated_at
             FROM japanese_characters c
             LEFT JOIN alphabet_groups g ON LOWER(TRIM(g.group_code)) = LOWER(TRIM(c.char_group))
             LEFT JOIN writing_svg_answer_references s
                    ON s.character_id = c.character_id
                   AND s.is_active = 1
             {$where}
             GROUP BY c.character_id, c.japanese_char, c.romaji, c.char_type, c.char_group,
                      c.stroke_count, g.group_name_th, g.sort_order
             ORDER BY c.char_type, g.sort_order, c.japanese_char"
        );
        if ($where !== '') $statement->execute([':type' => $type]);
        else $statement->execute();
        referenceResponse(200, ['success' => true, 'items' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'get') {
        $characterId = (int)($input['character_id'] ?? $_GET['character_id'] ?? 0);
        $statement = $conn->prepare(
            'SELECT svg_reference_id, character_id, version_name, svg_file, view_box,
                    trajectory_json, stroke_count, canvas_width, canvas_height,
                    shape_tolerance, position_tolerance, require_stroke_order
             FROM writing_svg_answer_references
             WHERE character_id = :character_id AND version_name = :version_name AND is_active = 1
             LIMIT 1'
        );
        $statement->execute([':character_id' => $characterId, ':version_name' => (string)($input['version_name'] ?? $_GET['version_name'] ?? 'default')]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$item) referenceResponse(200, ['success' => true, 'item' => null]);
        $item['trajectory'] = json_decode((string)$item['trajectory_json'], true) ?: null;
        unset($item['trajectory_json']);
        referenceResponse(200, ['success' => true, 'item' => $item]);
    }

    if ($action === 'all_variants') {
        $characterId = (int)($input['character_id'] ?? $_GET['character_id'] ?? 0);
        $charStmt = $conn->prepare('SELECT character_id AS id, japanese_char, romaji, char_type, char_group, stroke_count FROM japanese_characters WHERE character_id = :id LIMIT 1');
        $charStmt->execute([':id' => $characterId]);
        $character = $charStmt->fetch(PDO::FETCH_ASSOC);

        if (!$character) {
            referenceResponse(404, ['success' => false, 'message' => 'ไม่พบตัวอักษรที่ระบุ']);
        }

        $statement = $conn->prepare(
            'SELECT svg_reference_id, character_id, version_name, glif_file, stroke_count,
                    trajectory_json, canvas_width, canvas_height, shape_tolerance,
                    position_tolerance, require_stroke_order, created_at, updated_at
             FROM writing_svg_answer_references
             WHERE character_id = :character_id AND is_active = 1
             ORDER BY (version_name = "default") DESC, svg_reference_id ASC'
        );
        $statement->execute([':character_id' => $characterId]);
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);

        $decodedItems = [];
        foreach ($items as $item) {
            $item['trajectory'] = json_decode((string)$item['trajectory_json'], true) ?: null;
            unset($item['trajectory_json']);
            $decodedItems[] = $item;
        }

        // Fallback to writing_answer_references if no svg references exist yet
        if (empty($decodedItems) && tableExists($conn, 'writing_answer_references')) {
            $fallbackStmt = $conn->prepare(
                'SELECT answer_reference_id AS svg_reference_id, character_id, version_name, stroke_count,
                        trajectory_json, canvas_width, canvas_height, shape_tolerance, position_tolerance,
                        require_stroke_order, created_at
                 FROM writing_answer_references
                 WHERE character_id = :character_id AND is_active = 1
                 ORDER BY answer_reference_id ASC'
            );
            $fallbackStmt->execute([':character_id' => $characterId]);
            foreach ($fallbackStmt->fetchAll(PDO::FETCH_ASSOC) as $fbItem) {
                $fbItem['trajectory'] = json_decode((string)$fbItem['trajectory_json'], true) ?: null;
                unset($fbItem['trajectory_json']);
                $decodedItems[] = $fbItem;
            }
        }

        referenceResponse(200, [
            'success' => true,
            'character' => $character,
            'total_variants' => count($decodedItems),
            'items' => $decodedItems
        ]);
    }

    if ($action === 'generate' || $action === 'generate_variant') {
        $characterId = (int)($input['character_id'] ?? $_GET['character_id'] ?? 0);
        $charStmt = $conn->prepare('SELECT character_id, japanese_char, romaji, char_type, stroke_count FROM japanese_characters WHERE character_id = :character_id LIMIT 1');
        $charStmt->execute([':character_id' => $characterId]);
        $character = $charStmt->fetch(PDO::FETCH_ASSOC);

        if (!$character) {
            referenceResponse(404, ['success' => false, 'message' => 'ไม่พบตัวอักษรที่ระบุ']);
        }

        $expectedStrokeCount = (int)$character['stroke_count'];
        $requestedStyle = trim((string)($input['style'] ?? $_GET['style'] ?? 'auto'));
        $mode = trim((string)($input['mode'] ?? $_GET['mode'] ?? 'distinct'));
        $shouldSave = !empty($input['save']);

        // 1. ดึงกรณีที่มีอยู่เดิมทั้งหมดของตัวอักษรนี้
        $existingVariants = [];
        $svgStmt = $conn->prepare('SELECT version_name, trajectory_json, stroke_count FROM writing_svg_answer_references WHERE character_id = :character_id AND is_active = 1');
        $svgStmt->execute([':character_id' => $characterId]);
        foreach ($svgStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $traj = json_decode((string)$row['trajectory_json'], true);
            if (is_array($traj['strokes'] ?? null) && $traj['strokes']) {
                $existingVariants[] = ['version_name' => $row['version_name'], 'trajectory' => $traj, 'stroke_count' => (int)$row['stroke_count']];
            }
        }

        // 2. ดึงเส้นต้นแบบมาตรฐานจาก KanjiVG ที่ระบบนำเข้าไว้ก่อนเสมอ
        // เพื่อไม่ให้กรณีที่แอดมินวาดพลาดหรือเจนผิดในอดีตกลายเป็นต้นแบบใหม่
        // เมื่อไม่มีต้นแบบมาตรฐานจริง ๆ จึงค่อยใช้กรณีที่บันทึกไว้เป็น fallback.
        $baseTrajectory = null;
        $baseSource = 'saved_reference';
        if (tableExists($conn, 'writing_answer_references')) {
            $arStmt = $conn->prepare('SELECT trajectory_json FROM writing_answer_references WHERE character_id = :character_id AND is_active = 1 ORDER BY (stroke_count = :count) DESC, answer_reference_id DESC LIMIT 1');
            $arStmt->execute([':character_id' => $characterId, ':count' => $expectedStrokeCount]);
            $rawJson = $arStmt->fetchColumn();
            if ($rawJson) {
                $decoded = json_decode((string)$rawJson, true);
                if (is_array($decoded['strokes'] ?? null) && $decoded['strokes']) {
                    $baseTrajectory = $decoded;
                    $baseSource = 'official_stroke_template';
                }
            }
        }

        if (!$baseTrajectory && !empty($existingVariants)) {
            // Prefer a correctly stroked default; do not use a malformed case
            // unless it is the only available fallback.
            foreach ($existingVariants as $var) {
                if ($var['version_name'] === 'default' && (int)$var['stroke_count'] === $expectedStrokeCount) {
                    $baseTrajectory = $var['trajectory'];
                    break;
                }
            }
            if (!$baseTrajectory) {
                foreach ($existingVariants as $var) {
                    if ((int)$var['stroke_count'] === $expectedStrokeCount) {
                        $baseTrajectory = $var['trajectory'];
                        break;
                    }
                }
            }
            if (!$baseTrajectory) $baseTrajectory = $existingVariants[0]['trajectory'];
        }

        // Fallback 1: ตรวจสอบจากตาราง japanese_character_strokes
        if (!$baseTrajectory && tableExists($conn, 'japanese_character_strokes')) {
            $jcsStmt = $conn->prepare('SELECT path_data, stroke_order FROM japanese_character_strokes WHERE character_id = :cid ORDER BY stroke_order');
            $jcsStmt->execute([':cid' => $characterId]);
            $jcsRows = $jcsStmt->fetchAll(PDO::FETCH_ASSOC);
            $candidateStrokes = [];
            foreach ($jcsRows as $jcsRow) {
                $decoded = json_decode((string)$jcsRow['path_data'], true);
                $pList = is_array($decoded) && isset($decoded[0]['path']) ? $decoded : [['path' => $jcsRow['path_data'], 'order' => $jcsRow['stroke_order']]];
                foreach ($pList as $pItem) {
                    $pts = svgPathToTrajectoryPoints((string)($pItem['path'] ?? ''));
                    if (count($pts) >= 2) {
                        $candidateStrokes[] = [
                            'strokeNumber' => (int)($pItem['order'] ?? count($candidateStrokes) + 1),
                            'points' => $pts,
                        ];
                    }
                }
            }
            if (!empty($candidateStrokes)) {
                $baseTrajectory = normalizeTrajectoryForComparison(['canvas_width' => 620, 'canvas_height' => 420, 'strokes' => $candidateStrokes]);
                $baseSource = 'character_strokes_table';
            }
        }

        // Fallback 2: ดึงเส้นต้นแบบมาตรฐานจาก KanjiVG Repository โดยตรง
        if (!$baseTrajectory && !empty($character['japanese_char'])) {
            $codePoint = strtoupper(str_pad(dechex(mb_ord((string)$character['japanese_char'], 'UTF-8')), 5, '0', STR_PAD_LEFT));
            $vgUrls = [
                'https://raw.githubusercontent.com/KanjiVG/kanjivg/master/kanji/' . strtolower($codePoint) . '.svg',
                'https://raw.githubusercontent.com/KanjiVG/kanjivg/master/kanji/' . $codePoint . '.svg'
            ];
            foreach ($vgUrls as $vgUrl) {
                $vgXml = @file_get_contents($vgUrl);
                if ($vgXml) {
                    $vgDom = new DOMDocument();
                    if (@$vgDom->loadXML($vgXml)) {
                        $pNodes = $vgDom->getElementsByTagName('path');
                        $vgStrokes = [];
                        foreach ($pNodes as $idx => $pNode) {
                            $d = trim($pNode->getAttribute('d'));
                            if ($d === '') continue;
                            $pts = svgPathToTrajectoryPoints($d);
                            if (count($pts) >= 2) {
                                $vgStrokes[] = ['strokeNumber' => $idx + 1, 'points' => $pts];
                            }
                        }
                        if (!empty($vgStrokes)) {
                            $baseTrajectory = normalizeTrajectoryForComparison(['canvas_width' => 620, 'canvas_height' => 420, 'strokes' => $vgStrokes]);
                            $baseSource = 'kanjivg_auto_fetched';
                            break;
                        }
                    }
                }
            }
        }

        if (!$baseTrajectory) {
            referenceResponse(404, ['success' => false, 'message' => 'ยังไม่มีข้อมูลเส้นต้นแบบของตัวอักษรนี้ กรุณาวาดเส้นเริ่มต้นหรือนำเข้าไฟล์ GLIF ก่อน']);
        }

        // กรณีเลือกดึงเส้นมาตรฐานเดิม (Canonical mode) และไม่มี variant อื่น
        if ($mode === 'canonical' && empty($existingVariants)) {
            referenceResponse(200, [
                'success' => true,
                'source' => 'canonical',
                'version_name' => 'default',
                'trajectory' => $baseTrajectory,
                'is_duplicate' => false,
                'difference_percentage' => 0,
                'message' => 'ดึงเส้นต้นแบบมาตรฐานเรียบร้อยแล้ว'
            ]);
        }

        // 3. เจนกรณีรูปแบบใหม่ที่รับประกันว่า "จะไม่วาดซ้ำกับรูปแบบที่มีอยู่แล้ว"
        $generated = generateGuaranteedDistinctVariant($baseTrajectory, $existingVariants, $requestedStyle);
        if ($generated['is_duplicate']) {
            referenceResponse(422, [
                'success' => false,
                'message' => 'ยังสร้างกรณีใหม่ที่แตกต่างชัดเจนพอไม่ได้ (คล้ายกรณีเดิม ' . round($generated['max_similarity'] * 100, 1) . '%) ระบบจึงไม่บันทึกข้อมูลซ้ำ กรุณาลองกดสร้างอีกครั้งหรือเลือกรูปแบบอื่น'
            ]);
        }

        // ตั้งชื่อเวอร์ชันอัตโนมัติให้สื่อความหมายและไม่ซ้ำ
        $timestampSuffix = date('Ymd-His');
        $suggestedVersionName = trim((string)($input['version_name'] ?? ''));
        if ($suggestedVersionName === '' || $suggestedVersionName === 'default') {
            $suggestedVersionName = 'variant-' . $generated['style'] . '-' . date('md-His');
        }

        // Make sure suggestedVersionName doesn't already exist in existingVariants
        $existingVersionNames = array_column($existingVariants, 'version_name');
        $finalVersionName = $suggestedVersionName;
        $counter = 2;
        while (in_array($finalVersionName, $existingVersionNames, true)) {
            $finalVersionName = $suggestedVersionName . '-' . $counter;
            $counter++;
        }

        // บันทึกลงฐานข้อมูลทันทีหากมีการระบุ save: true
        if ($shouldSave) {
            $saveStmt = $conn->prepare(
                'INSERT INTO writing_svg_answer_references
                    (character_id, version_name, trajectory_json, stroke_count, canvas_width, canvas_height,
                     shape_tolerance, position_tolerance, require_stroke_order, is_active, created_by)
                 VALUES
                    (:character_id, :version_name, :trajectory_json, :stroke_count, 620, 420,
                     0.25, 0.20, 1, 1, :created_by)
                 ON DUPLICATE KEY UPDATE
                    trajectory_json = VALUES(trajectory_json),
                    stroke_count = VALUES(stroke_count),
                    is_active = 1'
            );
            $saveStmt->execute([
                ':character_id' => $characterId,
                ':version_name' => $finalVersionName,
                ':trajectory_json' => json_encode($generated['trajectory'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':stroke_count' => count($generated['trajectory']['strokes']),
                ':created_by' => (int)($_SESSION['admin']['admin_id'] ?? 0)
            ]);
        }

        referenceResponse(200, [
            'success' => true,
            'character_id' => $characterId,
            'source' => $baseSource,
            'version_name' => $finalVersionName,
            'style' => $generated['style'],
            'style_label' => $generated['style_label'],
            'trajectory' => $generated['trajectory'],
            'stroke_count' => count($generated['trajectory']['strokes']),
            'difference_percentage' => $generated['difference_percentage'],
            'max_similarity' => $generated['max_similarity'],
            'is_duplicate' => $generated['is_duplicate'],
            'compared_variants_count' => count($existingVariants),
            'saved' => $shouldSave,
            'message' => 'สร้างกรณีตัวอักษรใหม่สำเร็จ (' . $generated['style_label'] . ' แตกต่างจากกรณีเดิม ' . $generated['difference_percentage'] . '% ไม่ซ้ำ)'
        ]);
    }

    if ($action === 'variants') {
        $characterId = (int)($input['character_id'] ?? $_GET['character_id'] ?? 0);
        $statement = $conn->prepare(
            'SELECT svg_reference_id, version_name, glif_file, stroke_count,
                    shape_tolerance, position_tolerance, require_stroke_order, updated_at
             FROM writing_svg_answer_references
             WHERE character_id = :character_id AND is_active = 1
             ORDER BY version_name'
        );
        $statement->execute([':character_id' => $characterId]);
        referenceResponse(200, ['success' => true, 'items' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'delete_variant') {
        $characterId = (int)($input['character_id'] ?? 0);
        $versionName = trim((string)($input['version_name'] ?? ''));

        if ($characterId < 1 || $versionName === '') {
            referenceResponse(422, ['success' => false, 'message' => 'ข้อมูลกรณีเฉลยไม่ครบถ้วน']);
        }
        $delStmt = $conn->prepare('DELETE FROM writing_svg_answer_references WHERE character_id = :cid AND version_name = :vname');
        $delStmt->execute([':cid' => $characterId, ':vname' => $versionName]);
        if ($delStmt->rowCount() < 1) {
            referenceResponse(404, ['success' => false, 'message' => 'ไม่พบกรณีเฉลยที่ต้องการลบ']);
        }

        referenceResponse(200, ['success' => true, 'message' => 'ลบกรณีเฉลย "' . $versionName . '" เรียบร้อยแล้ว']);
    }

    if ($action === 'save') {
        $characterId = (int)($input['character_id'] ?? 0);
        $trajectory = validReferenceTrajectory($input['trajectory'] ?? null);
        if ($characterId < 1 || !$trajectory) {
            referenceResponse(422, ['success' => false, 'message' => 'กรุณาเลือกตัวอักษรและวาดเฉลยอย่างน้อย 1 เส้น']);
        }

        $characterStatement = $conn->prepare('SELECT character_id FROM japanese_characters WHERE character_id = :id LIMIT 1');
        $characterStatement->execute([':id' => $characterId]);
        if (!$characterStatement->fetchColumn()) {
            referenceResponse(422, ['success' => false, 'message' => 'ไม่พบตัวอักษรที่เลือก']);
        }

        $statement = $conn->prepare(
            'INSERT INTO writing_svg_answer_references
                (character_id, version_name, svg_file, glif_file, glif_content, svg_content, view_box, trajectory_json,
                 stroke_count, canvas_width, canvas_height, shape_tolerance, position_tolerance,
                 require_stroke_order, is_active, created_by)
             VALUES
                (:character_id, :version_name, :svg_file, :glif_file, :glif_content, :svg_content, :view_box, :trajectory_json,
                 :stroke_count, :canvas_width, :canvas_height, :shape_tolerance, :position_tolerance,
                 :require_stroke_order, 1, :created_by)
             ON DUPLICATE KEY UPDATE
                svg_file = VALUES(svg_file), glif_file = VALUES(glif_file), glif_content = VALUES(glif_content), svg_content = VALUES(svg_content), view_box = VALUES(view_box),
                trajectory_json = VALUES(trajectory_json), stroke_count = VALUES(stroke_count),
                canvas_width = VALUES(canvas_width), canvas_height = VALUES(canvas_height),
                shape_tolerance = VALUES(shape_tolerance), position_tolerance = VALUES(position_tolerance),
                require_stroke_order = VALUES(require_stroke_order), is_active = 1,
                created_by = VALUES(created_by)'
        );
        $statement->execute([
            ':character_id' => $characterId,
            ':version_name' => trim((string)($input['version_name'] ?? 'default')) ?: 'default',
            ':svg_file' => trim((string)($input['svg_file'] ?? '')) ?: null,
            ':glif_file' => trim((string)($input['glif_file'] ?? '')) ?: null,
            ':glif_content' => (string)($input['glif_content'] ?? '') ?: null,
            ':svg_content' => null,
            ':view_box' => trim((string)($input['view_box'] ?? '0 0 620 420')) ?: '0 0 620 420',
            ':trajectory_json' => json_encode($trajectory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':stroke_count' => count($trajectory['strokes']),
            ':canvas_width' => 620,
            ':canvas_height' => 420,
            ':shape_tolerance' => max(0.05, min(1.0, (float)($input['shape_tolerance'] ?? 0.25))),
            ':position_tolerance' => max(0.05, min(1.0, (float)($input['position_tolerance'] ?? 0.20))),
            ':require_stroke_order' => !empty($input['require_stroke_order']) ? 1 : 0,
            ':created_by' => (int)($_SESSION['admin']['admin_id'] ?? 0),
        ]);
        referenceResponse(200, ['success' => true, 'message' => 'บันทึกเฉลยเส้นเรียบร้อยแล้ว']);
    }

    referenceResponse(404, ['success' => false, 'message' => 'ไม่พบ action ที่ร้องขอ']);
} catch (Throwable $exception) {
    error_log('writing_svg_reference_api.php: ' . $exception->getMessage());
    referenceResponse(500, ['success' => false, 'message' => 'ไม่สามารถจัดการข้อมูลเฉลยเส้นได้']);
}
