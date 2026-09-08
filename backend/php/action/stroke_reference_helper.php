<?php
declare(strict_types=1);

if (!function_exists('pathToSampledPoints')) {
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
}

if (!function_exists('syncWritingReferencesForCharacter')) {
    function syncWritingReferencesForCharacter(PDO $conn, int $characterId, array $strokes, int $adminId = 0): void
    {
        if (empty($strokes)) return;

        $referenceStrokes = [];
        foreach ($strokes as $idx => $st) {
            $pathData = (string)($st['path'] ?? '');
            if ($pathData === '') continue;
            $points = pathToSampledPoints($pathData);
            if (count($points) >= 2) {
                $referenceStrokes[] = [
                    'strokeNumber' => (int)($st['order'] ?? ($idx + 1)),
                    'points' => $points,
                ];
            }
        }

        if (!$referenceStrokes) return;

        $traj = [
            'canvas_width' => 620,
            'canvas_height' => 420,
            'strokes' => $referenceStrokes,
        ];

        // 1. writing_answer_references
        $checkTable = $conn->prepare("SHOW TABLES LIKE 'writing_answer_references'");
        $checkTable->execute();
        if ($checkTable->fetchColumn()) {
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

        // 2. writing_svg_answer_references
        $checkTableSvg = $conn->prepare("SHOW TABLES LIKE 'writing_svg_answer_references'");
        $checkTableSvg->execute();
        if ($checkTableSvg->fetchColumn()) {
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
                ':uid' => $adminId,
            ]);
        }
    }
}
