<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSameOriginRequest();
}

function getGeminiApiKey(): string
{
    $environmentKey = trim((string)(getenv('GEMINI_API_KEY') ?: ''));
    if ($environmentKey !== '') {
        return $environmentKey;
    }

    $localConfigPath = __DIR__ . '/gemini_config.local.php';
    if (is_file($localConfigPath)) {
        $localConfig = require $localConfigPath;
        if (is_array($localConfig)) {
            return trim((string)($localConfig['api_key'] ?? ''));
        }
    }

    return '';
}

function respond(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function inputText(array $data, string $key, int $maxLength = 255): string
{
    $value = trim((string)($data[$key] ?? ''));
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength, 'UTF-8')
        : substr($value, 0, $maxLength);
}

function savePng(string $dataUrl, int $userId): array
{
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $matches)) {
        throw new RuntimeException('รูปที่วาดต้องเป็นไฟล์ PNG');
    }

    $binary = base64_decode($matches[1], true);
    if ($binary === false || $binary === '' || strlen($binary) > 6 * 1024 * 1024) {
        throw new RuntimeException('ขนาดรูปที่วาดไม่ถูกต้อง');
    }

    $imageInfo = @getimagesizefromstring($binary);
    if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== 'image/png') {
        throw new RuntimeException('ข้อมูลรูปที่วาดไม่ถูกต้อง');
    }

    $uploadDirectory = dirname(__DIR__) . '/uploads/writing';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์เก็บรูปได้');
    }

    $fileName = sprintf('writing_u%d_%s.png', $userId, bin2hex(random_bytes(12)));
    $filePath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (file_put_contents($filePath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('ไม่สามารถบันทึกรูปที่วาดได้');
    }

    return [$fileName, $filePath];
}

function upscaleImageForAi(string $dataUrl): string
{
    if (!function_exists('imagecreatefromstring')) return $dataUrl;
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $matches)) return $dataUrl;
    $binary = base64_decode($matches[1], true);
    $source = $binary === false ? false : @imagecreatefromstring($binary);
    if ($source === false) return $dataUrl;

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $scale = min(2.0, 1600 / max(1, $sourceWidth), 1600 / max(1, $sourceHeight));
    if ($scale <= 1) {
        imagedestroy($source);
        return $dataUrl;
    }

    $target = imagecreatetruecolor((int)round($sourceWidth * $scale), (int)round($sourceHeight * $scale));
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefill($target, 0, 0, $white);
    imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $sourceWidth, $sourceHeight);
    ob_start();
    imagepng($target, null, 6);
    $encoded = base64_encode(ob_get_clean());
    imagedestroy($source);
    imagedestroy($target);
    return 'data:image/png;base64,' . $encoded;
}

function getTableColumns(PDO $connection, string $tableName): array
{
    $statement = $connection->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tableName) . '`');
    if ($statement === false) {
        return [];
    }

    $columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[] = strtolower($field);
        }
    }

    return $columns;
}

function tableExists(PDO $connection, string $tableName): bool
{
    $statement = $connection->prepare('SHOW TABLES LIKE :table_name');
    $statement->execute([':table_name' => $tableName]);
    return (bool)$statement->fetchColumn();
}

function resolveWritingAttemptSchema(PDO $connection): array
{
    $columns = getTableColumns($connection, 'writing_attempts');
    $characterColumn = in_array('template_character_id', $columns, true)
        ? 'template_character_id'
        : (in_array('question_id', $columns, true) ? 'question_id' : 'template_character_id');

    return [
        'columns' => $columns,
        'character_column' => $characterColumn,
        'shape_column' => in_array('shape_accuracy', $columns, true) ? 'shape_accuracy' : null,
        'alignment_column' => in_array('alignment_score', $columns, true)
            ? 'alignment_score'
            : (in_array('position_accuracy', $columns, true) ? 'position_accuracy' : null),
        'feedback_column' => in_array('ai_feedback', $columns, true) ? 'ai_feedback' : null,
        'completed_column' => in_array('is_completed', $columns, true) ? 'is_completed' : null,
        'passed_column' => in_array('is_passed', $columns, true) ? 'is_passed' : null,
        'completed_at_column' => in_array('completed_at', $columns, true) ? 'completed_at' : null,
        'average_score_column' => in_array('average_score', $columns, true) ? 'average_score' : null,
        'trajectory_column' => in_array('trajectory_json', $columns, true) ? 'trajectory_json' : null,
    ];
}

function resolveCurrentActor(): array
{
    if (!empty($_SESSION['auth']['type']) && !empty($_SESSION['auth']['id'])) {
        return ['type' => (string) $_SESSION['auth']['type'], 'id' => (int) $_SESSION['auth']['id']];
    }

    $adminId = (int)($_SESSION['admin']['admin_id'] ?? 0);
    if ($adminId > 0) {
        return ['type' => 'admin', 'id' => $adminId];
    }

    $memberId = (int)($_SESSION['member']['id'] ?? $_SESSION['member']['Member_ID'] ?? 0);
    if ($memberId > 0) {
        return ['type' => 'member', 'id' => $memberId];
    }

    return ['type' => null, 'id' => 0];
}

function resolveWritingActorId(PDO $connection): int
{
    $actor = resolveCurrentActor();
    $adminId = $actor['type'] === 'admin' ? (int)$actor['id'] : 0;
    if ($adminId < 1) {
        $memberId = $actor['type'] === 'member' ? (int)$actor['id'] : 0;
        if ($memberId < 1) {
            respond(401, ['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนบันทึกผลการฝึกเขียน']);
        }
        $memberStatement = $connection->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $memberStatement->execute([':id' => $memberId]);
        if (!$memberStatement->fetchColumn()) {
            respond(403, ['status' => 'error', 'message' => 'ไม่พบบัญชีสมาชิกสำหรับบันทึกผลการฝึกเขียน']);
        }
        return $memberId;
    }

    if (!tableExists($connection, 'admin_writing_test_users')) {
        respond(500, ['status' => 'error', 'message' => 'ระบบทดสอบแอดมินยังไม่พร้อม กรุณาติดตั้งฐานข้อมูลส่วน admin preview']);
    }

    $mappingStatement = $connection->prepare(
        'SELECT m.user_id
         FROM admin_writing_test_users m
         INNER JOIN users u ON u.id = m.user_id
         WHERE m.admin_id = :admin_id LIMIT 1'
    );
    $mappingStatement->execute([':admin_id' => $adminId]);
    $previewUserId = (int)$mappingStatement->fetchColumn();
    if ($previewUserId > 0) {
        return $previewUserId;
    }

    // The hidden account is only a foreign-key owner for preview attempts. It
    // has no usable password and cannot be used to sign in as a learner.
    $username = '__admin_write_preview_' . $adminId;
    $email = 'admin-write-preview-' . $adminId . '@system.local';
    $name = trim((string)($_SESSION['admin']['firstname'] ?? 'Administrator'));
    try {
        $connection->beginTransaction();
        $createUser = $connection->prepare(
            'INSERT INTO users (firstname, lastname, username, email, password_hash)
             VALUES (:firstname, :lastname, :username, :email, :password_hash)'
        );
        $createUser->execute([
            ':firstname' => $name !== '' ? $name : 'Administrator',
            ':lastname' => 'Preview',
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        ]);
        $previewUserId = (int)$connection->lastInsertId();
        $createMapping = $connection->prepare(
            'INSERT INTO admin_writing_test_users (admin_id, user_id) VALUES (:admin_id, :user_id)'
        );
        $createMapping->execute([':admin_id' => $adminId, ':user_id' => $previewUserId]);
        $connection->commit();
        return $previewUserId;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Unable to create admin writing preview account: ' . $exception->getMessage());
        respond(500, ['status' => 'error', 'message' => 'ไม่สามารถเตรียมโหมดทดสอบสำหรับแอดมินได้']);
    }
}

function clampScore(float $score): float
{
    return round(max(0.0, min(100.0, $score)), 2);
}

function normalizeAnalysisProfile(mixed $value): array
{
    if (is_string($value)) {
        $value = json_decode($value, true);
    }
    $profile = is_array($value) ? $value : [];
    $defaults = [
        'confidence_threshold' => 75.0,
        'ai_threshold' => 60.0,
        'dtw_enabled' => 1,
        'weight_order' => 15.0,
        'weight_shape' => 45.0,
        'weight_alignment' => 20.0,
        'weight_start' => 10.0,
        'weight_completeness' => 10.0,
    ];
    foreach ($defaults as $key => $default) {
        $profile[$key] = is_numeric($profile[$key] ?? null) ? (float)$profile[$key] : $default;
        if ($key !== 'dtw_enabled') {
            $profile[$key] = max(0.0, min(100.0, $profile[$key]));
        } else {
            $profile[$key] = $profile[$key] > 0 ? 1.0 : 0.0;
        }
    }
    if ($profile['weight_order'] === 20.0 && $profile['weight_shape'] === 40.0
        && $profile['weight_alignment'] === 20.0 && $profile['weight_start'] === 10.0
        && $profile['weight_completeness'] === 10.0) {
        $profile['weight_order'] = $defaults['weight_order'];
        $profile['weight_shape'] = $defaults['weight_shape'];
    }
    $weightTotal = $profile['weight_order'] + $profile['weight_shape'] + $profile['weight_alignment']
        + $profile['weight_start'] + $profile['weight_completeness'];
    if ($weightTotal <= 0) {
        $profile['weight_order'] = $defaults['weight_order'];
        $profile['weight_shape'] = $defaults['weight_shape'];
        $profile['weight_alignment'] = $defaults['weight_alignment'];
        $profile['weight_start'] = $defaults['weight_start'];
        $profile['weight_completeness'] = $defaults['weight_completeness'];
    }
    return $profile;
}

function getWritingResultLevel(float $score, bool $hasReference): string
{
    if (!$hasReference) return 'ไม่พบต้นแบบ';
    if ($score >= 85) return 'ดีมาก';
    if ($score >= 60) return 'พอใช้';
    return 'ควรฝึกเพิ่ม';
}

function extractReferencePoints(string $pathData): array
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
        $points[] = ['x' => (float)$point['x'], 'y' => (float)$point['y']];
    };
    $readNumber = static function () use (&$tokens, &$index): ?float {
        if (!isset($tokens[$index]) || preg_match('/[a-zA-Z]/', $tokens[$index])) return null;
        return (float)$tokens[$index++];
    };
    $isRelative = static fn (string $value): bool => strtolower($value) === $value;
    $makePoint = static function (float $x, float $y, bool $relative, array $origin): array {
        return $relative ? ['x' => $origin['x'] + $x, 'y' => $origin['y'] + $y] : ['x' => $x, 'y' => $y];
    };
    $addLine = static function (array $from, array $to) use ($addPoint): void {
        for ($step = 1; $step <= 8; $step++) {
            $ratio = $step / 8;
            $addPoint(['x' => $from['x'] + (($to['x'] - $from['x']) * $ratio), 'y' => $from['y'] + (($to['y'] - $from['y']) * $ratio)]);
        }
    };
    $addCubic = static function (array $from, array $controlOne, array $controlTwo, array $to) use ($addPoint): void {
        for ($step = 1; $step <= 12; $step++) {
            $t = $step / 12;
            $inverse = 1 - $t;
            $addPoint([
                'x' => ($inverse ** 3 * $from['x']) + (3 * $inverse ** 2 * $t * $controlOne['x']) + (3 * $inverse * $t ** 2 * $controlTwo['x']) + ($t ** 3 * $to['x']),
                'y' => ($inverse ** 3 * $from['y']) + (3 * $inverse ** 2 * $t * $controlOne['y']) + (3 * $inverse * $t ** 2 * $controlTwo['y']) + ($t ** 3 * $to['y']),
            ]);
        }
    };
    $addQuadratic = static function (array $from, array $control, array $to) use ($addPoint): void {
        for ($step = 1; $step <= 12; $step++) {
            $t = $step / 12;
            $inverse = 1 - $t;
            $addPoint([
                'x' => ($inverse ** 2 * $from['x']) + (2 * $inverse * $t * $control['x']) + ($t ** 2 * $to['x']),
                'y' => ($inverse ** 2 * $from['y']) + (2 * $inverse * $t * $control['y']) + ($t ** 2 * $to['y']),
            ]);
        }
    };
    while ($index < count($tokens)) {
        if (preg_match('/[a-zA-Z]/', $tokens[$index])) {
            $command = $tokens[$index++];
        }
        if ($command === '') break;
        $upperCommand = strtoupper($command);
        if (!isset($parameterCounts[$upperCommand])) break;
        if ($upperCommand === 'Z') {
            $addLine($current, $start);
            $current = $start;
            $command = '';
            continue;
        }
        $count = $parameterCounts[$upperCommand];
        $values = [];
        for ($parameter = 0; $parameter < $count; $parameter++) {
            $value = $readNumber();
            if ($value === null) {
                $command = '';
                break 2;
            }
            $values[] = $value;
        }
        $relative = $isRelative($command);
        $origin = $current;
            if ($upperCommand === 'M' || $upperCommand === 'L' || $upperCommand === 'T') {
            $target = $makePoint($values[0], $values[1], $relative, $origin);
                if ($upperCommand === 'M') {
                    $addPoint($target);
                } else {
                    $addLine($current, $target);
                }
            $current = $target;
            if ($upperCommand === 'M') {
                $start = $current;
                $command = $relative ? 'l' : 'L';
            }
        } elseif ($upperCommand === 'H' || $upperCommand === 'V') {
            $target = $current;
            if ($upperCommand === 'H') $target['x'] = $relative ? $current['x'] + $values[0] : $values[0];
            if ($upperCommand === 'V') $target['y'] = $relative ? $current['y'] + $values[0] : $values[0];
            $addLine($current, $target);
            $current = $target;
        } elseif ($upperCommand === 'C') {
            $controlOne = $makePoint($values[0], $values[1], $relative, $origin);
            $controlTwo = $makePoint($values[2], $values[3], $relative, $origin);
            $target = $makePoint($values[4], $values[5], $relative, $origin);
            $addCubic($current, $controlOne, $controlTwo, $target);
            $current = $target;
        } elseif ($upperCommand === 'Q') {
            $control = $makePoint($values[0], $values[1], $relative, $origin);
            $target = $makePoint($values[2], $values[3], $relative, $origin);
            $addQuadratic($current, $control, $target);
            $current = $target;
        } else {
            $target = $makePoint($values[$count - 2], $values[$count - 1], $relative, $origin);
            $addLine($current, $target);
            $current = $target;
        }
    }
    return $points;
}

function normalizePoints(array $points): array
{
    if (!$points) return [];
    $xs = array_column($points, 'x');
    $ys = array_column($points, 'y');
    $minX = min($xs); $maxX = max($xs); $minY = min($ys); $maxY = max($ys);
    $width = max(1.0, $maxX - $minX);
    $height = max(1.0, $maxY - $minY);
    $scale = max($width, $height);
    $centerX = ($minX + $maxX) / 2;
    $centerY = ($minY + $maxY) / 2;
    return array_map(static fn (array $point): array => [
        'x' => (((float)$point['x'] - $centerX) / $scale) + 0.5,
        'y' => (((float)$point['y'] - $centerY) / $scale) + 0.5,
    ], $points);
}

function compareStrokeGeometry(array $userStrokes, array $referenceStrokes, float $shapeTolerance = 0.25): float
{
    if (!$userStrokes || !$referenceStrokes || count($userStrokes) !== count($referenceStrokes)) return 0.0;
    $allUserPoints = [];
    $allReferencePoints = [];
    foreach ($userStrokes as $stroke) {
        $allUserPoints = array_merge($allUserPoints, $stroke['points'] ?? []);
    }
    foreach ($referenceStrokes as $stroke) {
        $allReferencePoints = array_merge($allReferencePoints, $stroke);
    }
    $normalizeSet = static function (array $points): array {
        if (!$points) return ['points' => [], 'minX' => 0, 'minY' => 0, 'width' => 1, 'height' => 1];
        $xs = array_map(static fn (array $point): float => (float)($point['x'] ?? 0), $points);
        $ys = array_map(static fn (array $point): float => (float)($point['y'] ?? 0), $points);
        $minX = min($xs); $minY = min($ys);
        $width = max(1.0, max($xs) - $minX);
        $height = max(1.0, max($ys) - $minY);
        $scale = max($width, $height);
        $centerX = $minX + ($width / 2);
        $centerY = $minY + ($height / 2);
        return [
            'points' => array_map(static fn (array $point): array => [
            'x' => (((float)($point['x'] ?? 0) - $centerX) / $scale) + 0.5,
            'y' => (((float)($point['y'] ?? 0) - $centerY) / $scale) + 0.5,
            ], $points),
            'minX' => $minX, 'minY' => $minY, 'width' => $width, 'height' => $height,
        ];
    };
    $normalizedUserSet = $normalizeSet($allUserPoints);
    $normalizedReferenceSet = $normalizeSet($allReferencePoints);
    if (!$normalizedUserSet['points'] || !$normalizedReferenceSet['points']) return 0.0;

    $resample = static function (array $points, int $sampleCount): array {
        if (count($points) < 2) return $points;
        $lengths = [0.0];
        for ($index = 1; $index < count($points); $index++) {
            $previous = $points[$index - 1];
            $current = $points[$index];
            $lengths[] = $lengths[$index - 1] + sqrt(
                ($current['x'] - $previous['x']) ** 2 + ($current['y'] - $previous['y']) ** 2
            );
        }
        $totalLength = $lengths[count($lengths) - 1];
        if ($totalLength <= 0.0) return array_fill(0, $sampleCount, $points[0]);

        $samples = [];
        for ($sample = 0; $sample < $sampleCount; $sample++) {
            $targetLength = $totalLength * $sample / ($sampleCount - 1);
            $segment = 1;
            while ($segment < count($lengths) - 1 && $lengths[$segment] < $targetLength) {
                $segment++;
            }
            $start = $points[$segment - 1];
            $end = $points[$segment];
            $segmentLength = $lengths[$segment] - $lengths[$segment - 1];
            $ratio = $segmentLength > 0.0
                ? ($targetLength - $lengths[$segment - 1]) / $segmentLength
                : 0.0;
            $samples[] = [
                'x' => $start['x'] + (($end['x'] - $start['x']) * $ratio),
                'y' => $start['y'] + (($end['y'] - $start['y']) * $ratio),
            ];
        }
        return $samples;
    };

    $dtwDistance = static function (array $first, array $second): float {
        $firstCount = count($first);
        $secondCount = count($second);
        if ($firstCount === 0 || $secondCount === 0) return 1.0;
        $previous = array_fill(0, $secondCount + 1, INF);
        $previous[0] = 0.0;
        for ($firstIndex = 1; $firstIndex <= $firstCount; $firstIndex++) {
            $current = array_fill(0, $secondCount + 1, INF);
            for ($secondIndex = 1; $secondIndex <= $secondCount; $secondIndex++) {
                $distance = sqrt(
                    ($first[$firstIndex - 1]['x'] - $second[$secondIndex - 1]['x']) ** 2
                    + ($first[$firstIndex - 1]['y'] - $second[$secondIndex - 1]['y']) ** 2
                );
                $current[$secondIndex] = $distance + min(
                    $previous[$secondIndex],
                    $current[$secondIndex - 1],
                    $previous[$secondIndex - 1]
                );
            }
            $previous = $current;
        }
        return $previous[$secondCount] / ($firstCount + $secondCount);
    };

    $strokeCentroid = static function (array $points): array {
        if (!$points) return ['x' => 0.0, 'y' => 0.0];
        return [
            'x' => array_sum(array_map(static fn (array $point): float => (float)($point['x'] ?? 0), $points)) / count($points),
            'y' => array_sum(array_map(static fn (array $point): float => (float)($point['y'] ?? 0), $points)) / count($points),
        ];
    };
    $scores = [];
    foreach ($userStrokes as $index => $userStroke) {
        // Stroke number is part of the handwriting rule. Do not remap a misplaced
        // stroke to the nearest reference stroke and accidentally hide order errors.
        $referenceIndex = $index;
        $userStart = 0;
        foreach (array_slice($userStrokes, 0, $index) as $previousStroke) {
            $userStart += count($previousStroke['points'] ?? []);
        }
        $referenceStart = 0;
        foreach (array_slice($referenceStrokes, 0, $referenceIndex) as $previousStroke) {
            $referenceStart += count($previousStroke);
        }
        $userPoints = array_slice($normalizedUserSet['points'], $userStart, count($userStroke['points'] ?? []));
        $referencePoints = array_slice($normalizedReferenceSet['points'], $referenceStart, count($referenceStrokes[$referenceIndex] ?? []));
        if (count($userPoints) < 3 || count($referencePoints) < 2) {
            $scores[] = 0.0;
            continue;
        }
        $sampleCount = 48;
        $userSample = $resample($userPoints, $sampleCount);
        $referenceSample = $resample($referencePoints, $sampleCount);
        $forwardDistance = 0.0;
        foreach ($userSample as $userPoint) {
            $forwardDistance += min(array_map(static fn (array $referencePoint): float => sqrt(
                ($userPoint['x'] - $referencePoint['x']) ** 2
                + ($userPoint['y'] - $referencePoint['y']) ** 2
            ), $referenceSample));
        }
        $reverseDistance = 0.0;
        foreach ($referenceSample as $referencePoint) {
            $reverseDistance += min(array_map(static fn (array $userPoint): float => sqrt(
                ($referencePoint['x'] - $userPoint['x']) ** 2
                + ($referencePoint['y'] - $userPoint['y']) ** 2
            ), $userSample));
        }
        $averageDistance = ($forwardDistance / $sampleCount + $reverseDistance / $sampleCount) / 2;
        $warpedDistance = $dtwDistance($userSample, $referenceSample);
        $averageDistance = ($averageDistance * 0.35) + ($warpedDistance * 0.65);
        $userLength = 0.0;
        $referenceLength = 0.0;
        for ($sampleIndex = 1; $sampleIndex < $sampleCount; $sampleIndex++) {
            $userLength += sqrt(
                ($userSample[$sampleIndex]['x'] - $userSample[$sampleIndex - 1]['x']) ** 2
                + ($userSample[$sampleIndex]['y'] - $userSample[$sampleIndex - 1]['y']) ** 2
            );
            $referenceLength += sqrt(
                ($referenceSample[$sampleIndex]['x'] - $referenceSample[$sampleIndex - 1]['x']) ** 2
                + ($referenceSample[$sampleIndex]['y'] - $referenceSample[$sampleIndex - 1]['y']) ** 2
            );
        }
        $lengthRatio = $referenceLength > 0.0 ? $userLength / $referenceLength : 0.0;
        $lengthPenalty = min(1.0, abs(1.0 - $lengthRatio));
        $distanceTolerance = max(0.05, min(1.0, $shapeTolerance));
        $freeDistanceTolerance = min(0.08, $distanceTolerance * 0.35);
        $distanceAfterTolerance = max(0.0, $averageDistance - $freeDistanceTolerance);
        $scoredDistanceTolerance = max(0.01, $distanceTolerance - $freeDistanceTolerance);
        $distanceScore = 1.0 - min(1.0, $distanceAfterTolerance / $scoredDistanceTolerance);
        $freeLengthTolerance = 0.15;
        $lengthAfterTolerance = max(0.0, $lengthPenalty - $freeLengthTolerance);
        $lengthScore = 1.0 - min(1.0, $lengthAfterTolerance / (1.0 - $freeLengthTolerance));
        $scores[] = clampScore(($distanceScore * 0.95 + ($lengthScore * 0.05)) * 100);
    }
    return $scores ? array_sum($scores) / count($scores) : 0.0;
}

function evaluateTrajectory(
    array $trajectory,
    int $expectedStrokeCount,
    array $referenceStrokes = [],
    float $shapeTolerance = 0.25,
    float $positionTolerance = 0.20,
    bool $requireStrokeOrder = true
): array
{
    $strokes = array_values(array_filter($trajectory['strokes'] ?? [], 'is_array'));
    $points = [];
    foreach ($strokes as $stroke) {
        foreach (($stroke['points'] ?? []) as $point) {
            if (isset($point['x'], $point['y']) && is_numeric($point['x']) && is_numeric($point['y'])) {
                $points[] = ['x' => (float)$point['x'], 'y' => (float)$point['y']];
            }
        }
    }

    $actualStrokeCount = count($strokes);
    $strokeCountScore = $expectedStrokeCount > 0
        ? clampScore(100 - abs($actualStrokeCount - $expectedStrokeCount) * 25)
        : ($actualStrokeCount > 0 ? 100.0 : 0.0);
    if (!$points) {
        return [
            'stroke_order_score' => 0.0, 'shape_accuracy_score' => 0.0,
            'alignment_score' => 0.0, 'start_position_score' => 0.0,
            'stroke_count_score' => $strokeCountScore, 'completeness_score' => 0.0,
        ];
    }

    $xs = array_column($points, 'x');
    $ys = array_column($points, 'y');
    $centerX = (min($xs) + max($xs)) / 2;
    $centerY = (min($ys) + max($ys)) / 2;
    $canvasWidth = max(1.0, (float)($trajectory['canvas_width'] ?? 620));
    $canvasHeight = max(1.0, (float)($trajectory['canvas_height'] ?? 420));
    $canvasCenterX = $canvasWidth / 2;
    $canvasCenterY = $canvasHeight / 2;
    $alignmentScore = clampScore(100 - (abs($centerX - $canvasCenterX) / $canvasCenterX * 35)
        - (abs($centerY - $canvasCenterY) / $canvasCenterY * 25));
    $firstPoint = $points[0];
    $startPositionScore = clampScore(100 - (abs($firstPoint['x'] - $canvasCenterX) / $canvasCenterX * 25)
        - (abs($firstPoint['y'] - $canvasCenterY) / $canvasCenterY * 25));
    $completenessScore = $expectedStrokeCount > 0
        ? clampScore($actualStrokeCount / $expectedStrokeCount * 100)
        : 100.0;
    $strokeOrderScore = $actualStrokeCount > 0
        ? clampScore(count(array_filter($strokes, static function (array $stroke, int $index): bool {
            return (int)($stroke['strokeNumber'] ?? ($index + 1)) === $index + 1;
        }, ARRAY_FILTER_USE_BOTH)) / $actualStrokeCount * 100)
        : 0.0;

    $geometryScore = compareStrokeGeometry(
        $strokes,
        $referenceStrokes,
        $shapeTolerance
    );
    $normalizedUserPoints = normalizePoints($points);
    $referencePoints = [];
    foreach ($referenceStrokes as $referenceStroke) {
        $referencePoints = array_merge($referencePoints, $referenceStroke);
    }
    $normalizedReferencePoints = normalizePoints($referencePoints);
    if ($referenceStrokes) {
        if ($normalizedUserPoints && $normalizedReferencePoints) {
            $startDistance = sqrt(
                ($normalizedUserPoints[0]['x'] - $normalizedReferencePoints[0]['x']) ** 2
                + ($normalizedUserPoints[0]['y'] - $normalizedReferencePoints[0]['y']) ** 2
            );
            $startPositionScore = clampScore((1 - min(1.0, $startDistance / 0.25)) * 100);
        }
    }
    $hasEnoughPoints = count($points) >= max(12, $expectedStrokeCount * 3);
    $hardFailure = !$hasEnoughPoints || !$referenceStrokes;
    $referenceGatePassed = !$hardFailure && $actualStrokeCount === $expectedStrokeCount
        && (!$requireStrokeOrder || $strokeOrderScore >= 100) && $geometryScore >= 55;
    $shapeScore = $hardFailure
        ? 0.0
        : clampScore(($geometryScore * 0.80) + ($strokeCountScore * 0.10) + ($completenessScore * 0.10));

    $strokeMetrics = [];
    foreach ($strokes as $index => $stroke) {
        $userStrokePoints = array_values(array_filter($stroke['points'] ?? [], static fn ($point): bool =>
            is_array($point) && isset($point['x'], $point['y']) && is_numeric($point['x']) && is_numeric($point['y'])));
        $referenceStrokePoints = $referenceStrokes[$index] ?? [];
        if (count($userStrokePoints) < 2 || count($referenceStrokePoints) < 2) continue;

        $userStartIndex = 0;
        foreach (array_slice($strokes, 0, $index) as $previousStroke) {
            $userStartIndex += count($previousStroke['points'] ?? []);
        }
        $referenceStartIndex = 0;
        foreach (array_slice($referenceStrokes, 0, $index) as $previousStroke) {
            $referenceStartIndex += count($previousStroke);
        }
        $userStroke = array_slice($normalizedUserPoints, $userStartIndex, count($userStrokePoints));
        $referenceStroke = array_slice($normalizedReferencePoints, $referenceStartIndex, count($referenceStrokePoints));
        $userStart = $userStroke[0];
        $userEnd = $userStroke[count($userStroke) - 1];
        $referenceStart = $referenceStroke[0];
        $referenceEnd = $referenceStroke[count($referenceStroke) - 1];
        $startDistance = sqrt(($userStart['x'] - $referenceStart['x']) ** 2 + ($userStart['y'] - $referenceStart['y']) ** 2);
        $endDistance = sqrt(($userEnd['x'] - $referenceEnd['x']) ** 2 + ($userEnd['y'] - $referenceEnd['y']) ** 2);
        $userDirection = atan2($userEnd['y'] - $userStart['y'], $userEnd['x'] - $userStart['x']);
        $referenceDirection = atan2($referenceEnd['y'] - $referenceStart['y'], $referenceEnd['x'] - $referenceStart['x']);
        $directionDifference = abs($userDirection - $referenceDirection);
        $directionDifference = min($directionDifference, (2 * M_PI) - $directionDifference);
        $directionScore = clampScore((1 - min(1.0, $directionDifference / M_PI)) * 100);
        $positionScore = clampScore((1 - min(1.0, sqrt(
            (($userStart['x'] + $userEnd['x']) / 2 - ($referenceStart['x'] + $referenceEnd['x']) / 2) ** 2
            + (($userStart['y'] + $userEnd['y']) / 2 - ($referenceStart['y'] + $referenceEnd['y']) / 2) ** 2
        ) / max(0.05, min(1.0, $positionTolerance)))) * 100);
        $orderScore = ((int)($stroke['strokeNumber'] ?? ($index + 1)) === $index + 1) ? 100.0 : 0.0;
        $userLength = 0.0;
        $referenceLength = 0.0;
        for ($pointIndex = 1; $pointIndex < count($userStroke); $pointIndex++) {
            $userLength += sqrt(($userStroke[$pointIndex]['x'] - $userStroke[$pointIndex - 1]['x']) ** 2
                + ($userStroke[$pointIndex]['y'] - $userStroke[$pointIndex - 1]['y']) ** 2);
        }
        for ($pointIndex = 1; $pointIndex < count($referenceStroke); $pointIndex++) {
            $referenceLength += sqrt(($referenceStroke[$pointIndex]['x'] - $referenceStroke[$pointIndex - 1]['x']) ** 2
                + ($referenceStroke[$pointIndex]['y'] - $referenceStroke[$pointIndex - 1]['y']) ** 2);
        }
        $lengthScore = $referenceLength > 0.0
            ? clampScore((1 - min(1.0, abs(1 - ($userLength / $referenceLength)) / 0.75)) * 100)
            : 0.0;
        $strokeShapeScore = compareStrokeGeometry(
            [['points' => $userStrokePoints]],
            [$referenceStrokePoints],
            $shapeTolerance
        );
        $strokeMetrics[] = [
            'stroke_number' => $index + 1,
            'start_position_score' => clampScore((1 - min(1.0, $startDistance / 0.25)) * 100),
            'end_position_score' => clampScore((1 - min(1.0, $endDistance / 0.25)) * 100),
            'direction_score' => $directionScore,
            'shape_score' => $strokeShapeScore,
            'length_score' => $lengthScore,
            'position_score' => $positionScore,
            'stroke_count_score' => $strokeCountScore,
            'order_score' => $requireStrokeOrder ? $orderScore : 100.0,
        ];
    }
    $strokeDetailScore = $strokeMetrics
        ? array_sum(array_map(static fn (array $metric): float =>
            ($metric['start_position_score'] * 0.10)
            + ($metric['end_position_score'] * 0.10)
            + ($metric['direction_score'] * 0.15)
            + ($metric['shape_score'] * 0.35)
            + ($metric['length_score'] * 0.10)
            + ($metric['position_score'] * 0.10)
            + ($metric['stroke_count_score'] * 0.05)
            + ($metric['order_score'] * 0.05), $strokeMetrics)) / count($strokeMetrics)
        : 0.0;
    $strokeShapeAverage = $strokeMetrics
        ? array_sum(array_column($strokeMetrics, 'shape_score')) / count($strokeMetrics)
        : $geometryScore;
    $minimumStrokeScore = $strokeMetrics
        ? min(array_map(static fn (array $metric): float => min(
            (float)$metric['shape_score'],
            (float)$metric['direction_score'],
            (float)$metric['start_position_score'],
            (float)$metric['end_position_score']
        ), $strokeMetrics))
        : 0.0;
    $shapeScore = $hardFailure ? 0.0 : clampScore(
        ($strokeShapeAverage * 0.70) + ($strokeDetailScore * 0.30)
    );
    $referenceGatePassed = $referenceGatePassed
        && $minimumStrokeScore >= 45;

    return [
        'stroke_order_score' => $strokeOrderScore,
        'shape_accuracy_score' => $shapeScore,
        'geometry_score' => clampScore($geometryScore),
        'hard_failure' => $hardFailure,
        'quality_gate_passed' => $referenceGatePassed,
        'alignment_score' => $alignmentScore,
        'start_position_score' => $startPositionScore,
        'stroke_count_score' => $strokeCountScore,
        'completeness_score' => $completenessScore,
        'minimum_stroke_score' => clampScore($minimumStrokeScore),
        'stroke_metrics' => $strokeMetrics,
        'stroke_detail_score' => clampScore($strokeDetailScore),
    ];
}

function getMasteryLevel(float $score, int $attempts): string
{
    if ($score >= 85 && $attempts >= 3) {
        return 'mastered';
    }
    if ($score >= 70) {
        return 'familiar';
    }
    if ($attempts > 0) {
        return 'learning';
    }
    return 'new';
}

// === ฟังก์ชันยิง API ไปยัง Gemini AI ===
function evaluateWithGemini(string $base64Image, string $targetCharacter, int $expectedStrokeCount, array $trajectory = [], array $referenceStrokes = []): array
{
    $apiKey = getGeminiApiKey();
    if ($apiKey === '') {
        return [];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
    $cleanBase64 = preg_replace('#^data:image/\w+;base64,#i', '', $base64Image);

        $actualStrokeCount = count(array_filter($trajectory['strokes'] ?? [], 'is_array'));
        $characterFamily = preg_match('/[ァ-ヶ]/u', $targetCharacter) ? 'คาตาคานะ' : 'ฮิรางานะ';
        $referenceSummary = [];
        foreach ($referenceStrokes as $index => $stroke) {
            $points = array_values(array_filter($stroke, static fn ($point): bool => is_array($point) && isset($point['x'], $point['y'])));
            if (!$points) continue;
            $sampleIndexes = array_unique([0, (int)floor((count($points) - 1) / 2), count($points) - 1]);
            $referenceSummary[] = [
                'stroke_number' => $index + 1,
                'points' => array_map(static fn (int $pointIndex): array => [
                    'x' => round((float)$points[$pointIndex]['x'], 1),
                    'y' => round((float)$points[$pointIndex]['y'], 1),
                ], $sampleIndexes),
            ];
        }
        $referenceText = $referenceSummary
            ? json_encode($referenceSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'ไม่มีข้อมูลเส้นต้นแบบ';
        $writingKnowledge = $characterFamily === 'คาตาคานะ'
            ? 'ความรู้คาตาคานะ: คาตาคานะเป็นตัวอักษรที่ใช้เส้นตรงและเส้นโค้งอย่างชัดเจน ต้องรักษาทิศทางการลากเส้นจากต้นทางไปปลายทาง ไม่รวมหลายเส้นเป็นเส้นเดียว และไม่เพิ่มเส้นที่ไม่มีในต้นแบบ; เส้นสั้น จุด และขีดเฉียงต้องมีความยาว มุม และตำแหน่งสัมพันธ์กับตัวอักษรทั้งตัว; ตัวอักษรที่มีเส้นคล้ายกันต้องแยกด้วยจำนวนเส้น ลำดับ และจุดเชื่อม ไม่ตัดสินจากหน้าตาโดยรวมเพียงอย่างเดียว'
            : 'ความรู้ฮิรางานะ: ฮิรางานะมีเส้นโค้งและการลากต่อเนื่องที่สำคัญ ต้องรักษาทิศทาง จุดเริ่ม จุดจบ จำนวนเส้น และสัดส่วนของแต่ละส่วน ไม่รวมหลายเส้นเป็นเส้นเดียว และไม่เพิ่มเส้นที่ไม่มีในต้นแบบ';
        $prompt = "คุณคือผู้เชี่ยวชาญการตรวจคัดลายมือภาษาญี่ปุ่น จงวิเคราะห์เฉพาะหมึกสีเข้มในภาพอย่างเข้มงวด "
            . "เปรียบเทียบกับตัวอักษรเป้าหมายภาษาญี่ปุ่น: '{$targetCharacter}' "
            . "ตัวอักษรนี้เป็น{$characterFamily}และมีจำนวนเส้นมาตรฐาน {$expectedStrokeCount} เส้น ผู้ใช้วาดจริง {$actualStrokeCount} เส้น "
            . "ข้อมูลจุดเริ่ม-กลาง-จบของเส้นต้นแบบจาก Admin คือ {$referenceText} "
            . "{$writingKnowledge} "
            . "ให้คะแนนตามเกณฑ์: รูปทรง 0-100 (ความใกล้เคียงของเส้นและสัดส่วน), ตำแหน่ง 0-100 (การวางตัวอักษรในกรอบ), "
            . "ถ้าจำนวนเส้นผิดให้ลดคะแนนอย่างชัดเจน แต่ถ้ารูปทรงส่วนใหญ่ถูกอย่าตัดเป็น 0; ให้ 0 เฉพาะกรณีไม่ใช่ตัวอักษรเป้าหมายหรือไม่มีรูปทรงที่ตรวจได้ "
            . "ห้ามให้คะแนนจากความคล้ายของ romaji หรือจากตำแหน่งข้อความ ให้ดูรูปทรง ทิศทาง ความยาว สัดส่วน และตำแหน่งของเส้นเท่านั้น "
            . "หากภาพไม่ใช่ตัวอักษรเป้าหมายหรือรูปร่างผิดชัดเจน ให้คะแนน 0 ไม่ควรเดาคะแนนสูง "
            . "ให้ประเมินความแม่นยำของรูปทรง (shape_accuracy 0-100) และความสมดุลของการจัดตำแหน่ง (alignment_score 0-100) "
            . "ระบุเหตุผลที่ผิดเป็นรายการสั้นๆ ใน error_reasons เช่น 'เส้นที่ 2 สั้นเกินไป' หรือ 'จุดเริ่มต้นสูงเกินไป' "
            . "วิเคราะห์แต่ละเส้นใน stroke_analysis โดยให้ stroke_number, reason, suggestion, score (0-100) และ severity (low/medium/high) "
            . "และอธิบายวิธีแก้ใน ai_explanation พร้อมคำแนะนำสั้นๆ ใน ai_feedback";

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt],
                    ["inline_data" => ["mime_type" => "image/png", "data" => $cleanBase64]]
                ]
            ]
        ],
        "generationConfig" => [
            "response_mime_type" => "application/json",
            "response_schema" => [
                "type" => "OBJECT",
                "properties" => [
                    "shape_accuracy" => ["type" => "NUMBER"],
                    "alignment_score" => ["type" => "NUMBER"],
                    "ai_feedback" => ["type" => "STRING"]
                    ,"error_reasons" => ["type" => "ARRAY", "items" => ["type" => "STRING"]]
                    ,"ai_explanation" => ["type" => "STRING"]
                    ,"stroke_analysis" => ["type" => "ARRAY", "items" => ["type" => "OBJECT", "properties" => [
                        "stroke_number" => ["type" => "INTEGER"], "reason" => ["type" => "STRING"], "suggestion" => ["type" => "STRING"],
                        "score" => ["type" => "NUMBER"], "severity" => ["type" => "STRING"]
                    ]]]
                ],
                "required" => ["shape_accuracy", "alignment_score", "ai_feedback", "error_reasons", "ai_explanation", "stroke_analysis"]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_LOW_SPEED_LIMIT => 100,
        CURLOPT_LOW_SPEED_TIME => 4,
        CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError !== '' || $httpStatus < 200 || $httpStatus >= 300) {
        error_log('Gemini evaluation failed: HTTP ' . $httpStatus . ($curlError !== '' ? ' ' . $curlError : ''));
        return [];
    }

    if (is_string($response) && $response !== '') {
        $data = json_decode($response, true);
        $jsonText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($jsonText) {
            return json_decode($jsonText, true) ?? [];
        }
    }
    return [];
}

$actor = resolveCurrentActor();
if (($actor['type'] ?? null) === null || $actor['id'] <= 0) {
    respond(401, ['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนบันทึกผลการฝึกเขียน']);
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'GET') {
    $data = $_GET;
} elseif ($requestMethod === 'POST') {
    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        respond(400, ['status' => 'error', 'message' => 'รูปแบบข้อมูลไม่ถูกต้อง']);
    }
} else {
    respond(405, ['status' => 'error', 'message' => 'ไม่รองรับวิธีการร้องขอนี้']);
}

$userId = resolveWritingActorId($conn);
$action = inputText($data, 'action', 40);

if (!in_array($action, ['get_session_summary', 'get_test_history', 'get_test_history_detail', 'get_matching_history_detail'], true) && $requestMethod !== 'POST') {
    respond(405, ['status' => 'error', 'message' => 'คำสั่งนี้รองรับเฉพาะ POST']);
}

try {
    if ($action === 'get_question_profiles') {
        $quizSetId = (int)($data['quiz_set_id'] ?? 0);
        if ($quizSetId < 1 || !tableExists($conn, 'writing_quiz_questions')) {
            respond(200, ['status' => 'success', 'profiles' => []]);
        }

        $statement = $conn->prepare(
              'SELECT q.character_id, q.analysis_profile, q.position_tolerance, q.stroke_order_required,
                    live_feedback_enabled, memory_check_enabled
               FROM writing_quiz_questions q
               INNER JOIN writing_quiz_sets s ON s.quiz_set_id = q.quiz_set_id
               WHERE q.quiz_set_id = :quiz_set_id AND s.is_active = 1
             ORDER BY sequence_no, character_id'
        );
        $statement->execute([':quiz_set_id' => $quizSetId]);
        $profiles = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $question) {
            $profile = normalizeAnalysisProfile($question['analysis_profile'] ?? null);
            $profile['position_tolerance'] = (float)($question['position_tolerance'] ?? 20);
            $profile['stroke_order_required'] = !empty($question['stroke_order_required']) ? 1 : 0;
            $profile['live_feedback_enabled'] = !empty($question['live_feedback_enabled']) ? 1 : 0;
            $profile['memory_check_enabled'] = !empty($question['memory_check_enabled']) ? 1 : 0;
            $profiles[(string)(int)$question['character_id']] = $profile;
        }
        respond(200, ['status' => 'success', 'profiles' => $profiles]);
    }

    if ($action === 'start_session') {
        $writingType = strtolower(inputText($data, 'writing_type', 20));
        $practiceMode = strtolower(inputText($data, 'practice_mode', 20));
        $writingMode = strtolower(inputText($data, 'writing_mode', 20));
        $groupName = inputText($data, 'group_name', 100);
        $totalCharacters = max(0, min(1000, (int)($data['total_characters'] ?? 0)));

        if (!in_array($writingType, ['hiragana', 'katakana'], true)) {
            respond(422, ['status' => 'error', 'message' => 'ประเภทตัวอักษรไม่ถูกต้อง']);
        }
        if (!in_array($practiceMode, ['guide', 'test'], true)) {
            $practiceMode = 'guide';
        }
        if (!in_array($writingMode, ['assisted', 'unassisted'], true)) {
            $writingMode = 'assisted';
        }

        $sessionColumns = getTableColumns($conn, 'writing_sessions');
        $hasWritingModeColumn = in_array('writing_mode', $sessionColumns, true);
        $insertColumns = 'user_id, writing_type, group_name, practice_mode' . ($hasWritingModeColumn ? ', writing_mode' : '') . ', total_characters';
        $insertValues = ':user_id, :writing_type, :group_name, :practice_mode' . ($hasWritingModeColumn ? ', :writing_mode' : '') . ', :total_characters';
        $statement = $conn->prepare("INSERT INTO writing_sessions ({$insertColumns}) VALUES ({$insertValues})");
        $parameters = [
            ':user_id' => $userId,
            ':writing_type' => $writingType,
            ':group_name' => $groupName,
            ':practice_mode' => $practiceMode,
            ':total_characters' => $totalCharacters,
        ];
        if ($hasWritingModeColumn) {
            $parameters[':writing_mode'] = $writingMode;
        }
        $statement->execute($parameters);

        respond(201, [
            'status' => 'success',
            'writing_session_id' => (int)$conn->lastInsertId(),
        ]);
    }

    if ($action === 'get_test_history') {
                        $sessionColumns = getTableColumns($conn, 'writing_sessions');
                        $writingModeSelect = in_array('writing_mode', $sessionColumns, true)
                            ? 'writing_mode'
                            : "'assisted' AS writing_mode";
                $historyStatement = $conn->prepare(
                                                "SELECT writing_session_id, writing_type, group_name, practice_mode, {$writingModeSelect}, total_time_ms,
                                        total_characters, completed_characters, started_at, completed_at
             FROM writing_sessions
             WHERE user_id = :user_id
                         ORDER BY COALESCE(completed_at, started_at) DESC, writing_session_id DESC
                         LIMIT 100"
        );
                $historyStatement->execute([':user_id' => $userId]);

        $history = $historyStatement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($history as &$writingRecord) {
            $writingRecord['record_type'] = 'writing';
        }
        unset($writingRecord);

        if (tableExists($conn, 'quizzes')) {
            $quizColumns = getTableColumns($conn, 'quizzes');
            $answerDetailsSelect = in_array('answer_details', $quizColumns, true)
                ? 'answer_details'
                : 'NULL AS answer_details';
            $quizStatement = $conn->prepare(
                'SELECT id AS quiz_id, quiz_type, score, total_questions, percentage, time_taken, ' . $answerDetailsSelect . ', completed_at
                 FROM quizzes
                 WHERE user_id = :user_id
                 ORDER BY completed_at DESC, id DESC
                 LIMIT 100'
            );
            $quizStatement->execute([':user_id' => $userId]);
            foreach ($quizStatement->fetchAll(PDO::FETCH_ASSOC) as $quizRecord) {
                $details = json_decode((string)($quizRecord['answer_details'] ?? ''), true);
                $details = is_array($details) ? $details : [];
                $history[] = [
                    'record_type' => 'matching',
                    'quiz_id' => (int)$quizRecord['quiz_id'],
                    'quiz_type' => (string)$quizRecord['quiz_type'],
                    'writing_type' => strtolower((string)($details['type'] ?? 'katakana')),
                    'group_name' => (string)($details['group_name'] ?? ''),
                    'writing_mode' => 'assisted',
                    'match_mode' => (string)($details['mode'] ?? 'pair'),
                    'quiz_set_id' => (string)($details['quiz_set_id'] ?? ''),
                    'score' => (int)$quizRecord['score'],
                    'total_questions' => (int)$quizRecord['total_questions'],
                    'percentage' => (float)$quizRecord['percentage'],
                    'total_time_ms' => (int)$quizRecord['time_taken'] * 1000,
                    'started_at' => $quizRecord['completed_at'],
                    'completed_at' => $quizRecord['completed_at'],
                    'answer_details' => is_array($details['questions'] ?? null) ? $details['questions'] : [],
                ];
            }
        }

        usort($history, static function (array $left, array $right): int {
            return strcmp((string)($right['completed_at'] ?? $right['started_at'] ?? ''), (string)($left['completed_at'] ?? $left['started_at'] ?? ''));
        });

        respond(200, [
            'status' => 'success',
            'history' => array_slice($history, 0, 100),
        ]);
    }

    if ($action === 'get_matching_history_detail') {
        $quizId = (int)($data['quiz_id'] ?? 0);
        if ($quizId < 1 || !tableExists($conn, 'quizzes')) {
            respond(404, ['status' => 'error', 'message' => 'ไม่พบผลแบบจับคู่ที่ต้องการ']);
        }

        $quizColumns = getTableColumns($conn, 'quizzes');
        $answerDetailsSelect = in_array('answer_details', $quizColumns, true)
            ? 'answer_details'
            : 'NULL AS answer_details';
        $quizStatement = $conn->prepare(
            'SELECT id AS quiz_id, quiz_type, score, total_questions, percentage, time_taken, ' . $answerDetailsSelect . ', completed_at
             FROM quizzes
             WHERE id = :quiz_id AND user_id = :user_id
             LIMIT 1'
        );
        $quizStatement->execute([':quiz_id' => $quizId, ':user_id' => $userId]);
        $quiz = $quizStatement->fetch(PDO::FETCH_ASSOC);
        if (!$quiz) {
            respond(404, ['status' => 'error', 'message' => 'ไม่พบผลแบบจับคู่ที่ต้องการ']);
        }

        $details = json_decode((string)($quiz['answer_details'] ?? ''), true);
        $details = is_array($details) ? $details : [];
        respond(200, [
            'status' => 'success',
            'quiz' => [
                'quiz_id' => (int)$quiz['quiz_id'],
                'quiz_type' => (string)$quiz['quiz_type'],
                'writing_type' => strtolower((string)($details['type'] ?? 'katakana')),
                'group_name' => (string)($details['group_name'] ?? ''),
                'match_mode' => (string)($details['mode'] ?? 'pair'),
                'quiz_set_id' => (string)($details['quiz_set_id'] ?? ''),
                'score' => (int)$quiz['score'],
                'total_questions' => (int)$quiz['total_questions'],
                'percentage' => (float)$quiz['percentage'],
                'time_taken' => (int)$quiz['time_taken'],
                'completed_at' => $quiz['completed_at'],
                'answers' => is_array($details['questions'] ?? null) ? $details['questions'] : [],
            ],
        ]);
    }

    $sessionId = (int)($data['writing_session_id'] ?? 0);
    if ($sessionId < 1) {
        respond(422, ['status' => 'error', 'message' => 'ไม่พบรหัสรอบการฝึกเขียน']);
    }

    $sessionStatement = $conn->prepare(
        'SELECT writing_session_id, completed_at FROM writing_sessions
         WHERE writing_session_id = :session_id AND user_id = :user_id LIMIT 1'
    );
    $sessionStatement->execute([':session_id' => $sessionId, ':user_id' => $userId]);
    $sessionRecord = $sessionStatement->fetch(PDO::FETCH_ASSOC);
    if (!$sessionRecord) {
        respond(404, ['status' => 'error', 'message' => 'ไม่พบรอบการฝึกเขียน']);
    }

    if ($action === 'abandon_session') {
        $attemptCountStatement = $conn->prepare(
            'SELECT COUNT(*) FROM writing_attempts
             WHERE writing_session_id = :session_id AND is_completed = 1'
        );
        $attemptCountStatement->execute([':session_id' => $sessionId]);
        $completedCharacters = (int)$attemptCountStatement->fetchColumn();

        $preserveStatement = $conn->prepare(
            'UPDATE writing_sessions
             SET completed_characters = :completed_characters
             WHERE writing_session_id = :session_id AND user_id = :user_id AND completed_at IS NULL'
        );
        $preserveStatement->execute([
            ':completed_characters' => $completedCharacters,
            ':session_id' => $sessionId,
            ':user_id' => $userId,
        ]);
        respond(200, [
            'status' => 'success',
            'message' => 'บันทึกความคืบหน้ารอบการฝึกเรียบร้อยแล้ว',
            'completed_characters' => $completedCharacters,
        ]);
    }

    if ($action === 'ai_evaluate_attempt') {
        $attemptId = (int)($data['writing_attempt_id'] ?? 0);
        $attemptSchema = resolveWritingAttemptSchema($conn);
        $trajectorySelect = !empty($attemptSchema['trajectory_column'])
            ? 'a.' . $attemptSchema['trajectory_column']
            : 'NULL AS trajectory_json';
        $attemptStatement = $conn->prepare(
            'SELECT a.writing_attempt_id, a.template_character_id, a.image_file, ' . $trajectorySelect . ', a.target_character,
                    a.stroke_count, c.stroke_count AS expected_stroke_count,
                    e.raw_result
             FROM writing_attempts a
             INNER JOIN japanese_characters c ON c.character_id = a.template_character_id
             LEFT JOIN writing_attempt_evaluations e ON e.writing_attempt_id = a.writing_attempt_id
             WHERE a.writing_attempt_id = :attempt_id AND a.writing_session_id = :session_id
             LIMIT 1'
        );
        $attemptStatement->execute([':attempt_id' => $attemptId, ':session_id' => $sessionId]);
        $attempt = $attemptStatement->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            respond(404, ['status' => 'error', 'message' => 'ไม่พบผลการเขียนสำหรับวิเคราะห์ AI']);
        }
        $imagePath = dirname(__DIR__) . '/uploads/writing/' . basename((string)$attempt['image_file']);
        $imageBinary = is_file($imagePath) ? file_get_contents($imagePath) : false;
        $trajectory = json_decode((string)$attempt['trajectory_json'], true) ?: ['strokes' => []];
        // AI receives the closest current case too, rather than a possibly unrelated
        // most-recent case. This mirrors the immediate rule-based score.
        $referenceCandidates = [];
        if (tableExists($conn, 'writing_answer_references')) {
            $referenceStatement = $conn->prepare(
                'SELECT trajectory_json, stroke_count, shape_tolerance, "canonical" AS reference_source
                 FROM writing_answer_references
                 WHERE character_id = :character_id AND is_active = 1
                 ORDER BY (version_name = "default") DESC, answer_reference_id DESC'
            );
            $referenceStatement->execute([':character_id' => (int)$attempt['template_character_id']]);
            $referenceCandidates = $referenceStatement->fetchAll(PDO::FETCH_ASSOC);
        }
        if (tableExists($conn, 'writing_svg_answer_references')) {
            $referenceStatement = $conn->prepare(
                'SELECT trajectory_json, stroke_count, shape_tolerance, "admin_variant" AS reference_source
                 FROM writing_svg_answer_references
                 WHERE character_id = :character_id AND is_active = 1
                 ORDER BY (version_name = "default") DESC, svg_reference_id DESC'
            );
            $referenceStatement->execute([':character_id' => (int)$attempt['template_character_id']]);
            $referenceCandidates = array_merge($referenceCandidates, $referenceStatement->fetchAll(PDO::FETCH_ASSOC));
        }
        $referenceStrokes = [];
        $bestReferenceScore = -1.0;
        $actualStrokeCount = count(array_filter($trajectory['strokes'] ?? [], 'is_array'));
        foreach ($referenceCandidates as $candidate) {
            $candidateData = json_decode((string)($candidate['trajectory_json'] ?? ''), true);
            $candidateStrokes = is_array($candidateData['strokes'] ?? null)
                ? array_map(static fn (array $stroke): array => $stroke['points'] ?? [], $candidateData['strokes'])
                : [];
            if (!$candidateStrokes) continue;
            $candidateStrokeCount = (int)($candidate['stroke_count'] ?? count($candidateStrokes));
            $countPriority = $candidateStrokeCount === (int)$attempt['expected_stroke_count'] ? 1000000.0 : 0.0;
            $canonicalPriority = ($candidate['reference_source'] ?? '') === 'canonical' ? 0.01 : 0.0;
            $candidateScore = $countPriority + $canonicalPriority + ($candidateStrokeCount === $actualStrokeCount
                ? compareStrokeGeometry($trajectory['strokes'] ?? [], $candidateStrokes, (float)($candidate['shape_tolerance'] ?? 0.25))
                : 0.0);
            if (!$referenceStrokes || $candidateScore > $bestReferenceScore) {
                $referenceStrokes = $candidateStrokes;
                $bestReferenceScore = $candidateScore;
            }
        }
        if ($imageBinary === false) {
            respond(422, ['status' => 'error', 'message' => 'ไม่พบภาพการเขียนสำหรับวิเคราะห์ AI']);
        }
        $aiResult = evaluateWithGemini(
            'data:image/png;base64,' . base64_encode($imageBinary),
            (string)$attempt['target_character'],
            (int)$attempt['expected_stroke_count'],
            $trajectory,
            $referenceStrokes
        );
        if (!isset($aiResult['shape_accuracy'], $aiResult['alignment_score'])) {
            respond(200, ['status' => 'success', 'ai_available' => false]);
        }
        $rawResult = json_decode((string)$attempt['raw_result'], true) ?: [];
        $rules = is_array($rawResult['rules'] ?? null) ? $rawResult['rules'] : [];
        $qualityGatePassed = ($rules['quality_gate_passed'] ?? false) === true;
        $shapeAccuracy = clampScore((clampScore((float)$aiResult['shape_accuracy']) * 0.35)
            + ((float)($rules['shape_accuracy_score'] ?? 0) * 0.65));
        $alignmentScore = clampScore((clampScore((float)$aiResult['alignment_score']) * 0.55)
            + ((float)($rules['alignment_score'] ?? 0) * 0.45));
        $strokeDetailScore = (float)($rules['stroke_detail_score'] ?? 0);
        $totalScore = clampScore(($strokeDetailScore * 0.70) + ($alignmentScore * 0.15)
            + ((float)($rules['stroke_order_score'] ?? 0) * 0.10)
            + ((float)($rules['completeness_score'] ?? 0) * 0.05));
        if (!$qualityGatePassed) {
            $shapeAccuracy = 0.0;
            $totalScore = 0.0;
        }
        $feedback = (string)($aiResult['ai_feedback'] ?? '');
        $updateEvaluation = $conn->prepare(
            'UPDATE writing_attempt_evaluations
             SET evaluator_type = "ai+rules", model_name = "gemini-2.5-flash",
                 shape_accuracy_score = :shape_score, alignment_score = :alignment_score,
                 total_score = :total_score, is_passed = :is_passed,
                 feedback = :feedback, error_reasons = :error_reasons,
                 ai_explanation = :ai_explanation, raw_result = :raw_result,
                 evaluated_at = CURRENT_TIMESTAMP
             WHERE writing_attempt_id = :attempt_id'
        );
        $updateEvaluation->execute([
            ':shape_score' => $shapeAccuracy,
            ':alignment_score' => $alignmentScore,
            ':total_score' => $totalScore,
            ':is_passed' => $qualityGatePassed && $totalScore >= 60 ? 1 : 0,
            ':feedback' => $feedback,
            ':error_reasons' => json_encode($aiResult['error_reasons'] ?? [], JSON_UNESCAPED_UNICODE),
            ':ai_explanation' => (string)($aiResult['ai_explanation'] ?? ''),
            ':raw_result' => json_encode(['rules' => $rules, 'ai' => $aiResult], JSON_UNESCAPED_UNICODE),
            ':attempt_id' => $attemptId,
        ]);
        respond(200, [
            'status' => 'success',
            'ai_available' => true,
            'writing_attempt_id' => $attemptId,
            'ai_evaluation' => [
                'shape_accuracy' => $shapeAccuracy,
                'alignment_score' => $alignmentScore,
                'total_score' => $totalScore,
                'evaluation_source' => 'ai+rules',
                'ai_feedback' => $feedback,
                'error_reasons' => is_array($aiResult['error_reasons'] ?? null) ? $aiResult['error_reasons'] : [],
                'ai_explanation' => (string)($aiResult['ai_explanation'] ?? ''),
                'stroke_analysis' => is_array($aiResult['stroke_analysis'] ?? null) ? $aiResult['stroke_analysis'] : [],
                'stroke_metrics' => $rules['stroke_metrics'] ?? [],
            ],
        ]);
    }

    if ($action === 'get_session_summary' || $action === 'get_test_history_detail') {
        $sessionColumns = getTableColumns($conn, 'writing_sessions');
        $writingModeSelect = in_array('writing_mode', $sessionColumns, true)
            ? 'writing_mode'
            : "'assisted' AS writing_mode";
        $summaryStatement = $conn->prepare(
            'SELECT writing_session_id, writing_type, group_name, practice_mode, ' . $writingModeSelect . ', started_at, completed_at,
                    total_time_ms, total_characters, completed_characters
             FROM writing_sessions
             WHERE writing_session_id = :session_id AND user_id = :user_id LIMIT 1'
        );
        $summaryStatement->execute([':session_id' => $sessionId, ':user_id' => $userId]);
        $session = $summaryStatement->fetch(PDO::FETCH_ASSOC);

        $attemptSchema = resolveWritingAttemptSchema($conn);
        $attemptColumns = [
            'writing_attempt_id',
            'writing_session_id',
            $attemptSchema['character_column'],
            'target_character',
            'romaji',
            'sequence_no',
            'image_file',
            'time_spent_ms',
            'stroke_count',
            'is_completed',
        ];

        if (!empty($attemptSchema['shape_column'])) {
            $attemptColumns[] = $attemptSchema['shape_column'];
        }
        if (!empty($attemptSchema['alignment_column'])) {
            if ($attemptSchema['alignment_column'] === 'position_accuracy') {
                $attemptColumns[] = 'position_accuracy AS alignment_score';
            } else {
                $attemptColumns[] = 'alignment_score';
            }
        }
        if (!empty($attemptSchema['average_score_column'])) {
            $attemptColumns[] = 'average_score';
        }
        if (!empty($attemptSchema['feedback_column'])) {
            $attemptColumns[] = $attemptSchema['feedback_column'];
        }
        if (!empty($attemptSchema['trajectory_column'])) {
            $attemptColumns[] = $attemptSchema['trajectory_column'];
        }

        $attemptAlignmentColumn = !empty($attemptSchema['alignment_column'])
            ? 'a.' . $attemptSchema['alignment_column']
            : '0';

        $attemptsStatement = $conn->prepare(
              'SELECT ' . implode(', ', array_map(static fn (string $column): string => 'a.' . $column, $attemptColumns)) . ',
                    COALESCE(e.stroke_order_score, 0) AS evaluated_stroke_order_score,
                    COALESCE(e.shape_accuracy_score, a.shape_accuracy, 0) AS evaluated_shape_accuracy,
                    COALESCE(e.alignment_score, ' . $attemptAlignmentColumn . ', 0) AS evaluated_alignment,
                    COALESCE(e.start_position_score, 0) AS evaluated_start_position,
                    COALESCE(e.completeness_score, 0) AS evaluated_completeness,
                    COALESCE(e.total_score, 0) AS evaluated_total_score,
                    COALESCE(e.is_passed, 0) AS evaluated_is_passed,
                    e.evaluator_type AS evaluation_source,
                    e.feedback AS evaluation_feedback,
                    e.error_reasons AS evaluation_error_reasons,
                    e.ai_explanation AS evaluation_explanation,
                    e.raw_result AS evaluation_raw_result
               FROM writing_attempts a
               LEFT JOIN writing_attempt_evaluations e ON e.writing_attempt_id = a.writing_attempt_id
               WHERE a.writing_session_id = :session_id
               ORDER BY a.sequence_no, a.writing_attempt_id'
        );
        $attemptsStatement->execute([':session_id' => $sessionId]);
        $attempts = $attemptsStatement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attempts as &$attempt) {
            $attempt['shape_accuracy'] = (float)$attempt['evaluated_shape_accuracy'];
            $attempt['alignment_score'] = (float)$attempt['evaluated_alignment'];
            $attempt['total_score'] = (float)$attempt['evaluated_total_score'];
            $attempt['is_passed'] = (int)$attempt['evaluated_is_passed'];
            $attempt['feedback'] = (string)($attempt['evaluation_feedback'] ?? $attempt['ai_feedback'] ?? '');
            $attempt['error_reasons'] = json_decode((string)($attempt['evaluation_error_reasons'] ?? '[]'), true) ?: [];
            $attempt['ai_explanation'] = (string)($attempt['evaluation_explanation'] ?? '');
            $rawEvaluation = json_decode((string)($attempt['evaluation_raw_result'] ?? ''), true);
            $attempt['stroke_analysis'] = is_array($rawEvaluation['ai']['stroke_analysis'] ?? null)
                ? $rawEvaluation['ai']['stroke_analysis'] : [];
            $rules = is_array($rawEvaluation['rules'] ?? null) ? $rawEvaluation['rules'] : [];
            $attempt['stroke_metrics'] = is_array($rules['stroke_metrics'] ?? null) ? $rules['stroke_metrics'] : [];
            $attempt['stroke_detail_score'] = (float)($rules['stroke_detail_score'] ?? 0);
            if (!$attempt['stroke_analysis']) {
                $attempt['stroke_analysis'] = array_map(static fn (array $metric): array => [
                    'stroke_number' => $metric['stroke_number'] ?? 0,
                    'score' => round((float)($metric['shape_score'] ?? 0), 2),
                    'reason' => 'ตรวจด้วยกฎรายเส้น',
                    'suggestion' => 'รักษาทิศทาง จุดเริ่ม และจุดจบของเส้น',
                ], $attempt['stroke_metrics']);
            }
            $attempt['score_breakdown'] = [
                ['label' => 'คุณภาพรายเส้น', 'score' => $attempt['stroke_detail_score'], 'weight' => 70],
                ['label' => 'ตำแหน่งรวม', 'score' => (float)$attempt['alignment_score'], 'weight' => 15],
                ['label' => 'ลำดับเส้น', 'score' => (float)($rules['stroke_order_score'] ?? 0), 'weight' => 10],
                ['label' => 'ความครบถ้วน', 'score' => (float)($rules['completeness_score'] ?? 0), 'weight' => 5],
            ];
            $attempt['image_url'] = appUrl('Front-end/uploads/writing/' . rawurlencode((string)($attempt['image_file'] ?? '')));
            unset($attempt['image_file']);
            $attempt['trajectory'] = !empty($attempt['trajectory_json'])
                ? (json_decode($attempt['trajectory_json'], true) ?: ['strokes' => []])
                : ['strokes' => []];
            unset($attempt['trajectory_json']);
        }
        unset($attempt);

        respond(200, [
            'status' => 'success',
            'session' => $session,
            'attempts' => $attempts,
        ]);
    }

    if ($action === 'save_attempt') {
        if (!empty($sessionRecord['completed_at'])) {
            respond(409, ['status' => 'error', 'message' => 'รอบการฝึกนี้เสร็จสิ้นแล้ว ไม่สามารถบันทึกเพิ่มได้']);
        }

        $characterId = (int)($data['template_character_id'] ?? 0);
        $sequenceNo = max(1, min(1000, (int)($data['sequence_no'] ?? 1)));
        $timeSpent = max(0, min(86400000, (int)($data['time_spent_ms'] ?? 0)));
        $strokeCount = max(0, min(10000, (int)($data['stroke_count'] ?? 0)));
        $image = (string)($data['image'] ?? '');
        $trajectory = $data['trajectory'] ?? ['strokes' => []];
        if (!is_array($trajectory) || !isset($trajectory['strokes']) || !is_array($trajectory['strokes'])) {
            respond(422, ['status' => 'error', 'message' => 'ข้อมูลเส้นที่วาดไม่ถูกต้อง']);
        }
        $analysisProfile = normalizeAnalysisProfile($data['analysis_profile'] ?? null);
        $strokeCount = count(array_filter($trajectory['strokes'], 'is_array'));
        $trajectoryJson = json_encode($trajectory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($trajectoryJson === false || strlen($trajectoryJson) > 4 * 1024 * 1024) {
            respond(422, ['status' => 'error', 'message' => 'ข้อมูลเส้นที่วาดไม่ถูกต้อง']);
        }

        // รับค่าพิกัดเส้นแรกจาก Frontend
        $firstStrokeX = isset($data['first_stroke_x']) ? (float)$data['first_stroke_x'] : null;
        $firstStrokeY = isset($data['first_stroke_y']) ? (float)$data['first_stroke_y'] : null;
        $isStartCorrect = !empty($data['is_start_position_correct']) ? 1 : 0;

        if ($characterId < 1 || $image === '') {
            respond(422, ['status' => 'error', 'message' => 'ข้อมูลตัวอักษรหรือรูปที่วาดไม่ครบ']);
        }

        $characterStatement = $conn->prepare(
            'SELECT character_id, japanese_char, COALESCE(romaji, "") AS romaji, COALESCE(stroke_count, 0) AS stroke_count
             FROM japanese_characters WHERE character_id = :character_id LIMIT 1'
        );
        $characterStatement->execute([':character_id' => $characterId]);
        $character = $characterStatement->fetch(PDO::FETCH_ASSOC);
        if (!$character) {
            respond(422, ['status' => 'error', 'message' => 'ไม่พบตัวอักษรที่ต้องการบันทึก']);
        }

        $quizSetId = (int)($data['quiz_set_id'] ?? 0);
        if ($quizSetId > 0 && tableExists($conn, 'writing_quiz_questions') && tableExists($conn, 'writing_quiz_sets')) {
            $profileStatement = $conn->prepare(
                'SELECT q.analysis_profile
                 FROM writing_quiz_questions q
                 INNER JOIN writing_quiz_sets s ON s.quiz_set_id = q.quiz_set_id
                 WHERE q.quiz_set_id = :quiz_set_id AND q.character_id = :character_id
                   AND s.is_active = 1
                 ORDER BY q.sequence_no
                 LIMIT 1'
            );
            $profileStatement->execute([
                ':quiz_set_id' => $quizSetId,
                ':character_id' => (int)$character['character_id'],
            ]);
            $storedProfile = $profileStatement->fetchColumn();
            if ($storedProfile !== false) {
                $analysisProfile = normalizeAnalysisProfile($storedProfile);
            }
        }

        $referenceStrokes = [];
        $answerReference = [];
        $bestReferenceScore = -1.0;
        $actualStrokeCount = count(array_filter($trajectory['strokes'] ?? [], 'is_array'));
        // Always include the canonical imported template and every active admin
        // variant. The user is scored against the closest valid handwriting case.
        $referenceCandidates = [];
        if (tableExists($conn, 'writing_answer_references')) {
            $referenceStatement = $conn->prepare(
                'SELECT trajectory_json, stroke_count, shape_tolerance, position_tolerance, require_stroke_order,
                        version_name, "canonical" AS reference_source
                   FROM writing_answer_references
                   WHERE character_id = :character_id AND is_active = 1
                  ORDER BY (version_name = "default") DESC, answer_reference_id DESC'
            );
            $referenceStatement->execute([':character_id' => $characterId]);
            $referenceCandidates = $referenceStatement->fetchAll(PDO::FETCH_ASSOC);
        }
        if (tableExists($conn, 'writing_svg_answer_references')) {
            $referenceStatement = $conn->prepare(
                'SELECT trajectory_json, stroke_count, shape_tolerance, position_tolerance, require_stroke_order,
                        version_name, "admin_variant" AS reference_source
                 FROM writing_svg_answer_references
                 WHERE character_id = :character_id AND is_active = 1
                 ORDER BY (version_name = "default") DESC, svg_reference_id DESC'
            );
            $referenceStatement->execute([':character_id' => $characterId]);
            $referenceCandidates = array_merge($referenceCandidates, $referenceStatement->fetchAll(PDO::FETCH_ASSOC));
        }
        foreach ($referenceCandidates as $candidate) {
            $candidateData = json_decode((string)($candidate['trajectory_json'] ?? ''), true);
            $candidateStrokes = is_array($candidateData['strokes'] ?? null)
                ? array_map(static fn (array $stroke): array => $stroke['points'] ?? [], $candidateData['strokes'])
                : [];
            if (!$candidateStrokes) continue;
            $candidateStrokeCount = (int)($candidate['stroke_count'] ?? count($candidateStrokes));
            // Prefer the canonical stroke count. Retain the reference when the
            // user has an extra/missing stroke so count and shape errors stay distinct.
            $countPriority = $candidateStrokeCount === (int)$character['stroke_count'] ? 1000000.0 : 0.0;
            $canonicalPriority = ($candidate['reference_source'] ?? '') === 'canonical' ? 0.01 : 0.0;
            $candidateScore = $countPriority + $canonicalPriority + (($candidateStrokeCount === $actualStrokeCount)
                ? compareStrokeGeometry(
                    $trajectory['strokes'] ?? [],
                    $candidateStrokes,
                    (float)$candidate['shape_tolerance']
                )
                : 0.0);
            if (!$referenceStrokes || $candidateScore > $bestReferenceScore) {
                $referenceStrokes = $candidateStrokes;
                $answerReference = $candidate;
                $bestReferenceScore = $candidateScore;
            }
        }

        [$fileName, $filePath] = savePng($image, $userId);

        $ruleResult = evaluateTrajectory(
            $trajectory,
            (int)$character['stroke_count'],
            $referenceStrokes,
            (float)($answerReference['shape_tolerance'] ?? 0.25),
            (float)($answerReference['position_tolerance'] ?? 0.20),
            (bool)($answerReference['require_stroke_order'] ?? true)
        );

        // Return the local score immediately; Gemini feedback is requested separately.
        $aiResult = [];
        $usingAi = false;
        $shapeAccuracy = $ruleResult['shape_accuracy_score'];
        $alignmentScore = $ruleResult['alignment_score'];
        if ($referenceStrokes) {
            $shapeAccuracy = min($shapeAccuracy, (float)$ruleResult['geometry_score']);
        }
        $errorReasons = [];
        if (!$ruleResult['quality_gate_passed']) {
            if (!$referenceStrokes) $errorReasons[] = 'ยังไม่มีต้นแบบสำหรับตรวจตัวอักษรนี้';
            if ($strokeCount !== (int)$character['stroke_count']) $errorReasons[] = 'จำนวนเส้นยังไม่ตรงกับต้นแบบ';
            if (($ruleResult['geometry_score'] ?? 0) < 55) $errorReasons[] = 'รูปทรงหรือทิศทางของเส้นยังต่างจากต้นแบบ';
            if (($ruleResult['minimum_stroke_score'] ?? 0) < 45) $errorReasons[] = 'มีเส้นที่ควรปรับจุดเริ่ม จุดจบ หรือทิศทาง';
        }
        $errorReasons = array_values(array_unique(array_map('strval', $errorReasons)));
        $aiExplanation = (string)($aiResult['ai_explanation'] ?? '');
        $strokeAnalysis = is_array($aiResult['stroke_analysis'] ?? null) ? array_values($aiResult['stroke_analysis']) : [];
        if (!$strokeAnalysis) {
            $strokeAnalysis = array_map(static fn (array $metric): array => [
                'stroke_number' => $metric['stroke_number'],
                'score' => round(($metric['shape_score'] * 0.55) + ($metric['direction_score'] * 0.20)
                    + ($metric['start_position_score'] * 0.10) + ($metric['end_position_score'] * 0.10)
                    + ($metric['length_score'] * 0.05)),
                'reason' => ((float)$metric['shape_score'] >= 70) ? 'รูปทรงเส้นนี้อยู่ในเกณฑ์ดี' : 'รูปทรงหรือทิศทางของเส้นนี้ยังคลาดเคลื่อน',
                'suggestion' => ((float)$metric['shape_score'] >= 70) ? 'รักษาจุดเริ่มและทิศทางแบบเดิม' : 'ลองเริ่มและลากเส้นตามทิศทางของตัวอย่างอีกครั้ง',
                'severity' => 'low',
            ], $ruleResult['stroke_metrics'] ?? []);
        }
        $aiFeedback = (string)($aiResult['ai_feedback'] ?? ($errorReasons ? 'จุดที่ควรปรับ: ' . implode(' • ', array_slice($errorReasons, 0, 2)) : 'ทำได้ดี รูปทรงและตำแหน่งอยู่ในเกณฑ์'));

        $attemptSchema = resolveWritingAttemptSchema($conn);
        $insertColumns = [
            'writing_session_id',
            $attemptSchema['character_column'],
            'target_character',
            'romaji',
            'sequence_no',
            'image_file',
            'time_spent_ms',
            'stroke_count',
        ];

        if (!empty($attemptSchema['shape_column'])) {
            $insertColumns[] = $attemptSchema['shape_column'];
        }
        if (!empty($attemptSchema['alignment_column'])) {
            $insertColumns[] = $attemptSchema['alignment_column'];
        }
        if (!empty($attemptSchema['average_score_column'])) {
            $insertColumns[] = $attemptSchema['average_score_column'];
        }
        if (!empty($attemptSchema['completed_column'])) {
            $insertColumns[] = $attemptSchema['completed_column'];
        }
        if (!empty($attemptSchema['passed_column'])) {
            $insertColumns[] = $attemptSchema['passed_column'];
        }
        if (!empty($attemptSchema['feedback_column'])) {
            $insertColumns[] = $attemptSchema['feedback_column'];
        }
        if (!empty($attemptSchema['trajectory_column'])) {
            $insertColumns[] = $attemptSchema['trajectory_column'];
        }

        $placeholders = [];
        foreach ($insertColumns as $column) {
            $placeholders[] = ':' . str_replace('`', '', $column);
        }

        $updateAssignments = [];
        foreach ($insertColumns as $column) {
            if ($column === 'writing_session_id') {
                continue;
            }
            $updateAssignments[] = '`' . $column . '` = VALUES(`' . $column . '`)';
        }
        if (!empty($attemptSchema['completed_column'])) {
            $updateAssignments[] = '`' . $attemptSchema['completed_column'] . '` = 1';
        }
        if (!empty($attemptSchema['completed_at_column'])) {
            $updateAssignments[] = '`' . $attemptSchema['completed_at_column'] . '` = CURRENT_TIMESTAMP';
        }

        try {
            $averageScore = round(($shapeAccuracy + $alignmentScore) / 2, 2);
            $bindValues = [
                ':writing_session_id' => $sessionId,
                ':' . str_replace('`', '', $attemptSchema['character_column']) => (int)$character['character_id'],
                ':target_character' => $character['japanese_char'],
                ':romaji' => $character['romaji'],
                ':sequence_no' => $sequenceNo,
                ':image_file' => $fileName,
                ':time_spent_ms' => $timeSpent,
                ':stroke_count' => $strokeCount,
            ];

            if (!empty($attemptSchema['trajectory_column'])) {
                $bindValues[':' . $attemptSchema['trajectory_column']] = $trajectoryJson;
            }

            if (!empty($attemptSchema['shape_column'])) {
                $bindValues[':' . str_replace('`', '', $attemptSchema['shape_column'])] = $shapeAccuracy;
            }
            if (!empty($attemptSchema['alignment_column'])) {
                $bindValues[':' . str_replace('`', '', $attemptSchema['alignment_column'])] = $alignmentScore;
            }
            if (!empty($attemptSchema['average_score_column'])) {
                $bindValues[':' . str_replace('`', '', $attemptSchema['average_score_column'])] = $averageScore;
            }
            if (!empty($attemptSchema['completed_column'])) {
                $bindValues[':' . str_replace('`', '', $attemptSchema['completed_column'])] = 1;
            }
            if (!empty($attemptSchema['passed_column'])) {
                $bindValues[':' . str_replace('`', '', $attemptSchema['passed_column'])] = ($ruleResult['quality_gate_passed'] ?? false)
                    && $shapeAccuracy >= 60 && $alignmentScore >= 60 ? 1 : 0;
            }
            if (!empty($attemptSchema['feedback_column'])) {
                $bindValues[':' . str_replace('`', '', $attemptSchema['feedback_column'])] = $aiFeedback;
            }

            $attemptStatement = $conn->prepare(
                'INSERT INTO writing_attempts
                    (`' . implode('`, `', $insertColumns) . '`) 
                 VALUES
                    (' . implode(', ', $placeholders) . ')
                 ON DUPLICATE KEY UPDATE
                    ' . implode(', ', $updateAssignments)
            );
            $attemptStatement->execute($bindValues);

            $attemptIdStatement = $conn->prepare(
                'SELECT writing_attempt_id FROM writing_attempts
                 WHERE writing_session_id = :session_id AND ' . $attemptSchema['character_column'] . ' = :character_id LIMIT 1'
            );
            $attemptIdStatement->execute([
                ':session_id' => $sessionId,
                ':character_id' => (int)$character['character_id'],
            ]);
            $attemptId = (int)$attemptIdStatement->fetchColumn();
            if ($attemptId > 0) {
                $weightTotal = $analysisProfile['weight_order'] + $analysisProfile['weight_shape']
                    + $analysisProfile['weight_alignment'] + $analysisProfile['weight_start']
                    + $analysisProfile['weight_completeness'];
                $strokeDetailScore = (float)($ruleResult['stroke_detail_score'] ?? 0.0);
                $totalScore = clampScore((($ruleResult['stroke_order_score'] * $analysisProfile['weight_order'])
                    + ($shapeAccuracy * $analysisProfile['weight_shape'])
                    + ($alignmentScore * $analysisProfile['weight_alignment'])
                    + ($ruleResult['start_position_score'] * $analysisProfile['weight_start'])
                    + ($ruleResult['completeness_score'] * $analysisProfile['weight_completeness']))
                    / max(1.0, $weightTotal));
                if (!empty($ruleResult['hard_failure'])) {
                    $shapeAccuracy = 0.0;
                    $totalScore = 0.0;
                    $aiFeedback = 'รูปทรงไม่ตรงกับตัวอักษรต้นแบบ คะแนนเป็น 0 กรุณาเขียนตามลำดับเส้นและรูปแบบตัวอย่าง';
                }
                $evaluationStatement = $conn->prepare(
                    'INSERT INTO writing_attempt_evaluations
                        (writing_attempt_id, evaluator_type, model_name, stroke_order_score,
                         shape_accuracy_score, alignment_score, start_position_score,
                         stroke_count_score, completeness_score, total_score, is_passed,
                         feedback, error_reasons, ai_explanation, raw_result)
                     VALUES
                        (:attempt_id, :evaluator_type, :model_name, :stroke_order_score,
                         :shape_score, :alignment_score, :start_score, :stroke_count_score,
                         :completeness_score, :total_score, :is_passed, :feedback, :error_reasons,
                         :ai_explanation, :raw_result)
                     ON DUPLICATE KEY UPDATE
                        evaluator_type = VALUES(evaluator_type), model_name = VALUES(model_name),
                        stroke_order_score = VALUES(stroke_order_score), shape_accuracy_score = VALUES(shape_accuracy_score),
                        alignment_score = VALUES(alignment_score), start_position_score = VALUES(start_position_score),
                        stroke_count_score = VALUES(stroke_count_score), completeness_score = VALUES(completeness_score),
                        total_score = VALUES(total_score), is_passed = VALUES(is_passed), feedback = VALUES(feedback),
                        error_reasons = VALUES(error_reasons), ai_explanation = VALUES(ai_explanation),
                        raw_result = VALUES(raw_result), evaluated_at = CURRENT_TIMESTAMP'
                );
                $evaluationStatement->execute([
                    ':attempt_id' => $attemptId,
                    ':evaluator_type' => $usingAi ? 'ai+rules' : 'rules',
                    ':model_name' => $usingAi ? 'gemini-2.5-flash' : null,
                    ':stroke_order_score' => $ruleResult['stroke_order_score'],
                    ':shape_score' => $shapeAccuracy,
                    ':alignment_score' => $alignmentScore,
                    ':start_score' => $ruleResult['start_position_score'],
                    ':stroke_count_score' => $ruleResult['stroke_count_score'],
                    ':completeness_score' => $ruleResult['completeness_score'],
                    ':total_score' => $totalScore,
                    ':is_passed' => $totalScore >= 60 ? 1 : 0,
                    ':feedback' => $aiFeedback,
                    ':error_reasons' => json_encode($errorReasons, JSON_UNESCAPED_UNICODE),
                    ':ai_explanation' => $aiExplanation,
                    ':raw_result' => json_encode(['rules' => $ruleResult, 'ai' => $aiResult], JSON_UNESCAPED_UNICODE),
                ]);

                $masterySelect = $conn->prepare(
                    'SELECT total_attempts, successful_attempts, mastery_score
                     FROM writing_character_mastery
                     WHERE user_id = :user_id AND character_id = :character_id LIMIT 1'
                );
                $masterySelect->execute([
                    ':user_id' => $userId,
                    ':character_id' => (int)$character['character_id'],
                ]);
                $mastery = $masterySelect->fetch(PDO::FETCH_ASSOC) ?: [
                    'total_attempts' => 0, 'successful_attempts' => 0, 'mastery_score' => 0,
                ];
                $masteryAttempts = (int)$mastery['total_attempts'] + 1;
                $masterySuccesses = (int)$mastery['successful_attempts'] + ($totalScore >= 60 ? 1 : 0);
                $masteryScore = round((((float)$mastery['mastery_score'] * (int)$mastery['total_attempts']) + $totalScore) / $masteryAttempts, 2);
                $masteryLevel = getMasteryLevel($masteryScore, $masteryAttempts);
                $masteryStatement = $conn->prepare(
                    'INSERT INTO writing_character_mastery
                        (user_id, character_id, total_attempts, successful_attempts, mastery_score,
                         mastery_level, is_remembered, last_score, last_feedback, last_practiced_at)
                     VALUES
                        (:user_id, :character_id, :total_attempts, :successful_attempts, :mastery_score,
                         :mastery_level, :is_remembered, :last_score, :last_feedback, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE
                        total_attempts = VALUES(total_attempts), successful_attempts = VALUES(successful_attempts),
                        mastery_score = VALUES(mastery_score), mastery_level = VALUES(mastery_level),
                        is_remembered = VALUES(is_remembered), last_score = VALUES(last_score),
                        last_feedback = VALUES(last_feedback), last_practiced_at = CURRENT_TIMESTAMP'
                );
                $masteryStatement->execute([
                    ':user_id' => $userId,
                    ':character_id' => (int)$character['character_id'],
                    ':total_attempts' => $masteryAttempts,
                    ':successful_attempts' => $masterySuccesses,
                    ':mastery_score' => $masteryScore,
                    ':mastery_level' => $masteryLevel,
                    ':is_remembered' => $masteryLevel === 'mastered' ? 1 : 0,
                    ':last_score' => $totalScore,
                    ':last_feedback' => $aiFeedback,
                ]);
            }
        } catch (Throwable $exception) {
            @unlink($filePath);
            throw $exception;
        }

        // คืนค่าคะแนนให้ Frontend ไปแสดงผล
        respond(200, [
            'status' => 'success',
            'image_url' => appUrl('Front-end/uploads/writing/' . rawurlencode($fileName)),
            'writing_attempt_id' => $attemptId,
            'ai_evaluation' => [
                'shape_accuracy' => $shapeAccuracy,
                'alignment_score' => $alignmentScore,
                'total_score' => $totalScore ?? 0,
                'result_level' => getWritingResultLevel((float)($totalScore ?? 0), (bool)$referenceStrokes),
                'evaluation_source' => $usingAi ? 'ai+rules' : 'rules',
                'mastery_level' => $masteryLevel ?? 'new',
                'is_remembered' => ($masteryLevel ?? '') === 'mastered',
                'error_reasons' => $errorReasons,
                'ai_explanation' => $aiExplanation,
                'stroke_analysis' => $strokeAnalysis,
                'stroke_metrics' => $ruleResult['stroke_metrics'] ?? [],
                'stroke_detail_score' => $ruleResult['stroke_detail_score'] ?? 0,
                'score_breakdown' => [
                    ['label' => 'ลำดับเส้น', 'score' => $ruleResult['stroke_order_score'], 'weight' => $analysisProfile['weight_order']],
                    ['label' => 'รูปทรง', 'score' => $shapeAccuracy, 'weight' => $analysisProfile['weight_shape']],
                    ['label' => 'ตำแหน่งรวม', 'score' => $alignmentScore, 'weight' => $analysisProfile['weight_alignment']],
                    ['label' => 'จุดเริ่มต้น', 'score' => $ruleResult['start_position_score'], 'weight' => $analysisProfile['weight_start']],
                    ['label' => 'ความครบถ้วน', 'score' => $ruleResult['completeness_score'], 'weight' => $analysisProfile['weight_completeness']],
                ],
                'analysis_profile' => $analysisProfile,
                'ai_feedback' => $aiFeedback
            ]
        ]);
    }

    if ($action === 'complete_session') {
        $totalTime = max(0, min(86400000, (int)($data['total_time_ms'] ?? 0)));
        $totalCharacters = max(0, min(1000, (int)($data['total_characters'] ?? 0)));

        $completedStatement = $conn->prepare(
            'SELECT COUNT(*) FROM writing_attempts
             WHERE writing_session_id = :session_id AND is_completed = 1'
        );
        $completedStatement->execute([':session_id' => $sessionId]);
        $completedCharacters = (int)$completedStatement->fetchColumn();

        $updateStatement = $conn->prepare(
            'UPDATE writing_sessions
             SET completed_at = CURRENT_TIMESTAMP,
                 total_time_ms = :total_time_ms,
                 total_characters = :total_characters,
                 completed_characters = :completed_characters
             WHERE writing_session_id = :session_id AND user_id = :user_id'
        );
        $updateStatement->execute([
            ':total_time_ms' => $totalTime,
            ':total_characters' => $totalCharacters,
            ':completed_characters' => $completedCharacters,
            ':session_id' => $sessionId,
            ':user_id' => $userId,
        ]);

        respond(200, [
            'status' => 'success',
            'completed_characters' => $completedCharacters,
        ]);
    }

    respond(400, ['status' => 'error', 'message' => 'ไม่รู้จักคำสั่งที่ร้องขอ']);
} catch (Throwable $exception) {
    error_log('writing_progress.php: ' . $exception->getMessage());
    respond(500, ['status' => 'error', 'message' => 'ไม่สามารถบันทึกผลการฝึกเขียนได้ กรุณาลองใหม่']);
}
