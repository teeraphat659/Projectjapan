<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../connectapi/db.php';

function respond(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function getCurrentActorId(): int {
    if (!empty($_SESSION['auth']['id'])) {
        return (int) $_SESSION['auth']['id'];
    }

    if (!empty($_SESSION['admin']['admin_id'])) {
        return (int) $_SESSION['admin']['admin_id'];
    }

    if (!empty($_SESSION['member']['id'])) {
        return (int) $_SESSION['member']['id'];
    }

    return 0;
}

function getColumnNames(PDO $connection, string $tableName): array {
    try {
        $statement = $connection->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tableName) . '`');
        if ($statement === false) {
            return [];
        }

        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $field = trim((string) ($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[] = strtolower($field);
            }
        }

        return $columns;
    } catch (Throwable $exception) {
        return [];
    }
}

function resolveQuizSetColumn(PDO $connection, array $candidates): ?string {
    $columns = getColumnNames($connection, 'writing_quiz_sets');
    foreach ($candidates as $candidate) {
        if (in_array(strtolower($candidate), $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function tableExists(PDO $connection, string $tableName): bool {
    try {
        $statement = $connection->prepare('SHOW TABLES LIKE :table_name');
        $statement->execute([':table_name' => $tableName]);
        return (bool) $statement->fetchColumn();
    } catch (Throwable $exception) {
        return false;
    }
}

$actorId = getCurrentActorId();
if ($actorId <= 0) {
    respond(401, ['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึง กรุณาเข้าสู่ระบบ']);
}

$data = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
$action = strtolower((string) ($data['action'] ?? $_GET['action'] ?? ''));

try {
    $quizSetColumns = getColumnNames($conn, 'writing_quiz_sets');
    $quizSetIdColumn = in_array('quiz_set_id', $quizSetColumns, true) ? 'quiz_set_id' : 'id';
    $groupColumn = resolveQuizSetColumn($conn, ['group_id', 'alphabet_group_id']);
    $orderColumn = resolveQuizSetColumn($conn, ['order_type', 'quiz_order_type']);
    $creatorColumn = resolveQuizSetColumn($conn, ['created_by_admin_id', 'created_by', 'admin_id']);

    if (in_array($action, ['create_quiz_set', 'quiz_create'], true)) {
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $groupId = isset($data['group_id']) || isset($data['alphabet_group_id'])
            ? ((int) ($data['group_id'] ?? $data['alphabet_group_id'] ?? 0))
            : 0;
        $passScore = max(0, min(100, (int) ($data['pass_score'] ?? 60)));
        $timeLimitSeconds = isset($data['time_limit_seconds']) ? max(0, (int) $data['time_limit_seconds']) : null;
        $isActive = !empty($data['is_active']) ? 1 : 1;

        if ($title === '') {
            respond(422, ['status' => 'error', 'message' => 'กรุณาระบุชื่อชุดแบบทดสอบ']);
        }

        $insertFields = ['title', 'description', 'pass_score', 'time_limit_seconds', 'is_active'];
        $insertValues = [':title', ':description', ':pass_score', ':time_limit_seconds', ':is_active'];
        $bindValues = [
            ':title' => $title,
            ':description' => $description,
            ':pass_score' => $passScore,
            ':time_limit_seconds' => $timeLimitSeconds,
            ':is_active' => $isActive,
        ];

        if ($groupColumn !== null) {
            $insertFields[] = $groupColumn;
            $insertValues[] = ':' . $groupColumn;
            $bindValues[':' . $groupColumn] = $groupId > 0 ? $groupId : null;
        }

        if ($orderColumn !== null) {
            $insertFields[] = $orderColumn;
            $insertValues[] = ':' . $orderColumn;
            $bindValues[':' . $orderColumn] = trim((string) ($data['order_type'] ?? 'sequential'));
        }

        if ($creatorColumn !== null) {
            $insertFields[] = $creatorColumn;
            $insertValues[] = ':' . $creatorColumn;
            $bindValues[':' . $creatorColumn] = $actorId;
        }

        $sql = 'INSERT INTO writing_quiz_sets (' . implode(', ', $insertFields) . ') VALUES (' . implode(', ', $insertValues) . ')';
        $stmt = $conn->prepare($sql);
        $stmt->execute($bindValues);

        $createdSetId = (int) $conn->lastInsertId();
        if (tableExists($conn, 'writing_quiz_questions')) {
            $questionCount = (int) ($data['question_count'] ?? 0);
            if ($questionCount > 0) {
                $conn->prepare('UPDATE writing_quiz_sets SET created_by_admin_id = :admin_id WHERE quiz_set_id = :id')
                    ->execute([':admin_id' => $actorId, ':id' => $createdSetId]);
            }
        }

        respond(201, [
            'status' => 'success',
            'message' => 'สร้างชุดแบบทดสอบสำเร็จ',
            'quiz_set_id' => (int) $conn->lastInsertId(),
        ]);
    }

    if (in_array($action, ['get_quiz_sets', 'quiz_get', 'quiz_list'], true)) {
        $quizId = (int) ($data['id'] ?? $_GET['id'] ?? 0);

        if ($quizId > 0) {
            $whereClause = $quizSetIdColumn === 'quiz_set_id' ? 'quiz_set_id = :id' : 'id = :id';
            $stmt = $conn->prepare('SELECT * FROM writing_quiz_sets WHERE ' . $whereClause . ' LIMIT 1');
            $stmt->execute([':id' => $quizId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                respond(404, ['status' => 'error', 'message' => 'ไม่พบชุดแบบทดสอบ']);
            }

            if (tableExists($conn, 'writing_quiz_questions')) {
                $questionStmt = $conn->prepare(
                    'SELECT * FROM writing_quiz_questions WHERE quiz_set_id = :quiz_set_id ORDER BY sequence_no ASC, question_id ASC'
                );
                $questionStmt->execute([':quiz_set_id' => $quizId]);
                $item['questions'] = $questionStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $item['questions'] = [];
            }

            respond(200, ['status' => 'success', 'item' => $item]);
        }

        $stmt = $conn->prepare('SELECT * FROM writing_quiz_sets ORDER BY ' . $quizSetIdColumn . ' DESC');
        $stmt->execute();
        respond(200, ['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if (in_array($action, ['quiz_update'], true)) {
        $quizId = (int) ($data['id'] ?? $_GET['id'] ?? 0);
        if ($quizId <= 0) {
            respond(422, ['status' => 'error', 'message' => 'ไม่พบรหัสชุดแบบทดสอบ']);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            respond(422, ['status' => 'error', 'message' => 'กรุณาระบุชื่อชุดแบบทดสอบ']);
        }

        $setClause = [
            'title = :title',
            'description = :description',
            'pass_score = :pass_score',
            'time_limit_seconds = :time_limit_seconds',
            'is_active = :is_active',
        ];
        $bindings = [
            ':title' => $title,
            ':description' => trim((string) ($data['description'] ?? '')),
            ':pass_score' => max(0, min(100, (int) ($data['pass_score'] ?? 60))),
            ':time_limit_seconds' => isset($data['time_limit_seconds']) ? max(0, (int) $data['time_limit_seconds']) : null,
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
            ':id' => $quizId,
        ];

        if ($groupColumn !== null) {
            $setClause[] = $groupColumn . ' = :' . $groupColumn;
            $bindings[':' . $groupColumn] = !empty($data['group_id'] ?? $data['alphabet_group_id'] ?? null) ? (int) ($data['group_id'] ?? $data['alphabet_group_id']) : null;
        }

        if ($orderColumn !== null) {
            $setClause[] = $orderColumn . ' = :' . $orderColumn;
            $bindings[':' . $orderColumn] = trim((string) ($data['order_type'] ?? 'sequential'));
        }

        $sql = 'UPDATE writing_quiz_sets SET ' . implode(', ', $setClause) . ' WHERE ' . $quizSetIdColumn . ' = :id';
        $stmt = $conn->prepare($sql);
        $stmt->execute($bindings);

        respond(200, ['status' => 'success', 'message' => 'อัปเดตชุดแบบทดสอบสำเร็จ']);
    }

    if (in_array($action, ['quiz_delete', 'delete_quiz_set'], true)) {
        $quizId = (int) ($data['id'] ?? $_GET['id'] ?? 0);
        if ($quizId <= 0) {
            respond(422, ['status' => 'error', 'message' => 'ไม่พบรหัสชุดแบบทดสอบ']);
        }

        if (tableExists($conn, 'writing_quiz_questions')) {
            $conn->prepare('DELETE FROM writing_quiz_questions WHERE quiz_set_id = :id')->execute([':id' => $quizId]);
        }

        $statement = $conn->prepare('DELETE FROM writing_quiz_sets WHERE ' . $quizSetIdColumn . ' = :id');
        $statement->execute([':id' => $quizId]);

        respond(200, ['status' => 'success', 'message' => 'ลบชุดแบบทดสอบสำเร็จ']);
    }

    respond(400, ['status' => 'error', 'message' => 'ไม่รู้จัก action']);
} catch (Throwable $e) {
    respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
