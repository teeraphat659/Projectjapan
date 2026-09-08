<?php
declare(strict_types=1);

require_once __DIR__ . '/../protect.php';
require_once __DIR__ . '/../../../Front-end/connectapi/app_config.php';

header('Content-Type: application/json; charset=UTF-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function requestData(): array
{
    $json = json_decode((string) file_get_contents('php://input'), true);
    return is_array($json) ? $json : $_POST;
}

function scoreValue(array $data, string $key): ?float
{
    if (!array_key_exists($key, $data) || $data[$key] === '') {
        return null;
    }

    return max(0.0, min(100.0, (float) $data[$key]));
}

$request = requestData();
$action = (string) ($_GET['action'] ?? $request['action'] ?? 'report');

try {
    if ($action === 'label_save') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            respond(405, ['success' => false, 'message' => 'การบันทึก label ต้องใช้ POST']);
        }

        $data = $request;
        $attemptId = (int) ($data['writing_attempt_id'] ?? 0);
        $humanScore = scoreValue($data, 'human_score');
        $humanShape = scoreValue($data, 'human_shape_score');
        $humanAlignment = scoreValue($data, 'human_alignment_score');
        $humanStrokeOrder = scoreValue($data, 'human_stroke_order_score');
        $feedback = trim((string) ($data['human_feedback'] ?? ''));

        if ($attemptId < 1 || $humanScore === null) {
            respond(422, ['success' => false, 'message' => 'ต้องระบุรหัสตัวอย่างและคะแนนผู้ตรวจ 0-100']);
        }

        $attemptCheck = $conn->prepare(
            'SELECT writing_attempt_id FROM writing_attempts
             WHERE writing_attempt_id = :attempt_id LIMIT 1'
        );
        $attemptCheck->execute([':attempt_id' => $attemptId]);
        if (!$attemptCheck->fetchColumn()) {
            respond(404, ['success' => false, 'message' => 'ไม่พบตัวอย่างการเขียน']);
        }

        $statement = $conn->prepare(
            'INSERT INTO writing_training_labels
                (writing_attempt_id, label_source, human_score, human_stroke_order_score,
                 human_shape_score, human_alignment_score, human_feedback, label_status,
                 labeled_by, labeled_at)
             VALUES
                (:attempt_id, "human", :human_score, :human_stroke_order_score,
                 :human_shape_score, :human_alignment_score, :human_feedback, "completed",
                 :labeled_by, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                label_source = VALUES(label_source), human_score = VALUES(human_score),
                human_stroke_order_score = VALUES(human_stroke_order_score),
                human_shape_score = VALUES(human_shape_score),
                human_alignment_score = VALUES(human_alignment_score),
                human_feedback = VALUES(human_feedback), label_status = "completed",
                labeled_by = VALUES(labeled_by), labeled_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            ':attempt_id' => $attemptId,
            ':human_score' => $humanScore,
            ':human_stroke_order_score' => $humanStrokeOrder,
            ':human_shape_score' => $humanShape,
            ':human_alignment_score' => $humanAlignment,
            ':human_feedback' => $feedback,
            ':labeled_by' => (int) $_SESSION['admin']['admin_id'],
        ]);

        respond(200, ['success' => true, 'message' => 'บันทึกผลตรวจมนุษย์แล้ว']);
    }

    if ($action === 'samples') {
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 25)));
        $statement = $conn->prepare(
            'SELECT a.writing_attempt_id, a.target_character, a.romaji, a.image_file,
                    e.evaluator_type, e.total_score AS ai_score, e.is_passed AS ai_passed,
                    l.human_score, l.human_shape_score, l.human_alignment_score,
                    l.label_status, l.human_feedback, l.labeled_at
             FROM writing_attempts a
             INNER JOIN writing_attempt_evaluations e ON e.writing_attempt_id = a.writing_attempt_id
             LEFT JOIN writing_training_labels l ON l.writing_attempt_id = a.writing_attempt_id
             ORDER BY (l.label_status = "completed") ASC, a.writing_attempt_id DESC
             LIMIT ' . $limit
        );
        $statement->execute();
        $samples = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($samples as &$sample) {
            $sample['image_url'] = appUrl('Front-end/uploads/writing/' . rawurlencode((string) $sample['image_file']));
            unset($sample['image_file']);
        }
        unset($sample);

        respond(200, ['success' => true, 'samples' => $samples]);
    }

    if ($action !== 'report') {
        respond(400, ['success' => false, 'message' => 'ไม่พบ action ที่ระบุ']);
    }

    $statement = $conn->query(
        'SELECT e.total_score AS ai_score, e.is_passed AS ai_passed,
                l.human_score
         FROM writing_attempt_evaluations e
         INNER JOIN writing_training_labels l ON l.writing_attempt_id = e.writing_attempt_id
                 WHERE e.evaluator_type = "ai+rules"
                     AND l.label_status = "completed" AND l.human_score IS NOT NULL'
    );
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $count = count($rows);
    $correct = 0;
    $falsePositive = 0;
    $falseNegative = 0;
    $absoluteError = 0.0;
    $aiTotal = 0.0;
    $humanTotal = 0.0;

    foreach ($rows as $row) {
        $aiScore = (float) $row['ai_score'];
        $humanScore = (float) $row['human_score'];
        $aiPassed = $aiScore >= 60.0;
        $humanPassed = $humanScore >= 60.0;
        $correct += $aiPassed === $humanPassed ? 1 : 0;
        $falsePositive += $aiPassed && !$humanPassed ? 1 : 0;
        $falseNegative += !$aiPassed && $humanPassed ? 1 : 0;
        $absoluteError += abs($aiScore - $humanScore);
        $aiTotal += $aiScore;
        $humanTotal += $humanScore;
    }

    respond(200, [
        'success' => true,
        'report' => [
            'labeled_samples' => $count,
            'accuracy' => $count ? round($correct / $count * 100, 2) : null,
            'mae' => $count ? round($absoluteError / $count, 2) : null,
            'false_positive_rate' => $count ? round($falsePositive / $count * 100, 2) : null,
            'false_negative_rate' => $count ? round($falseNegative / $count * 100, 2) : null,
            'average_ai_score' => $count ? round($aiTotal / $count, 2) : null,
            'average_human_score' => $count ? round($humanTotal / $count, 2) : null,
            'pass_threshold' => 60,
        ],
    ]);
} catch (PDOException $exception) {
    error_log('writing_accuracy.php: ' . $exception->getMessage());
    respond(500, ['success' => false, 'message' => 'ยังไม่ได้ติดตั้งตารางประเมิน หรือฐานข้อมูลไม่พร้อมใช้งาน']);
}
