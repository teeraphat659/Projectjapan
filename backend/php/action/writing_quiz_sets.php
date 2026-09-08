<?php
require_once __DIR__ . '/../protect.php';

// ตั้งค่า Header สำหรับ API
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, DELETE");

// รับค่า JSON Payload
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// รับ Action
$action = $input['action'] ?? $_GET['action'] ?? '';

$admin_id = (int) $_SESSION['admin']['admin_id'];

function encodeAnalysisProfile(array $question): ?string
{
    $profile = $question['analysis_profile'] ?? [];
    if (is_string($profile)) {
        $decoded = json_decode($profile, true);
        $profile = is_array($decoded) ? $decoded : [];
    }
    if (!isset($profile['feedback_style']) && isset($question['feedback_style'])) {
        $profile['feedback_style'] = (string)$question['feedback_style'];
    }
    return $profile ? json_encode($profile, JSON_UNESCAPED_UNICODE) : null;
}

try {
    switch ($action) {
        
        // ----------------------------------------------------
        // 1. ดึงข้อมูลแบบทดสอบ 1 ชุด (พร้อมคำถามย่อย)
        // ----------------------------------------------------
        case 'quiz_get':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception("กรุณาระบุ ID แบบทดสอบ");

            // ดึงข้อมูลตารางหลัก
            $stmt = $conn->prepare("SELECT * FROM writing_quiz_sets WHERE quiz_set_id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) throw new Exception("ไม่พบข้อมูลแบบทดสอบ");

            // ดึงข้อมูลคำถามย่อยจากตารางใหม่ (เรียงตามลำดับข้อ)
            $stmtQ = $conn->prepare("SELECT * FROM writing_quiz_questions WHERE quiz_set_id = ? ORDER BY sequence_no ASC");
            $stmtQ->execute([$id]);
            $item['questions'] = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'item' => $item]);
            break;

        // ----------------------------------------------------
        // 2. สร้างชุดแบบทดสอบใหม่
        // ----------------------------------------------------
        case 'quiz_create':
            $conn->beginTransaction();

            // เพิ่ม order_type สำหรับตารางหลัก
            $sql = "INSERT INTO writing_quiz_sets 
                    (title, description, group_id, order_type, pass_score, time_limit_seconds, is_active, created_by_admin_id) 
                    VALUES (:title, :description, :group_id, :order_type, :pass_score, :time_limit, :is_active, :admin_id)";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':title'       => $input['title'],
                ':description' => $input['description'] ?? null,
                ':group_id'    => $input['group_id'] ?: null,
                ':order_type'  => $input['order_type'] ?? 'sequential',
                ':pass_score'  => $input['pass_score'] ?? 70,
                ':time_limit'  => $input['time_limit_seconds'] ?? 0,
                ':is_active'   => $input['is_active'] ?? 1,
                ':admin_id'    => $admin_id
            ]);

            $quiz_set_id = $conn->lastInsertId();

            // เพิ่มข้อมูลคำถามย่อยลงตาราง writing_quiz_questions
            if (!empty($input['questions'])) {
                $sqlQ = "INSERT INTO writing_quiz_questions
                         (quiz_set_id, character_id, target_character, target_romaji, expected_stroke_count,
                          difficulty_level, position_tolerance, stroke_order_required, live_feedback_enabled,
                          memory_check_enabled, feedback_beginner, feedback_intermediate, feedback_advanced,
                          analysis_profile, sequence_no, max_score, question_text, created_by_admin_id, updated_by_admin_id)
                         VALUES (:q_set_id, :char_id, :target_character, :target_romaji, :expected_stroke_count,
                          :difficulty_level, :position_tolerance, :stroke_order_required, :live_feedback_enabled,
                          :memory_check_enabled, :feedback_beginner, :feedback_intermediate, :feedback_advanced,
                          :analysis_profile, :seq_no, :max_score, :q_text, :created_by_admin_id, :updated_by_admin_id)";
                $stmtQ = $conn->prepare($sqlQ);
                
                foreach ($input['questions'] as $index => $q) {
                    $stmtQ->execute([
                        ':q_set_id'  => $quiz_set_id,
                        ':char_id'   => $q['character_id'],
                        ':target_character' => $q['target_character'] ?? null,
                        ':target_romaji' => $q['target_romaji'] ?? null,
                        ':expected_stroke_count' => isset($q['expected_stroke_count']) ? (int)$q['expected_stroke_count'] : null,
                        ':difficulty_level' => $q['difficulty_level'] ?? 'normal',
                        ':position_tolerance' => $q['position_tolerance'] ?? 20,
                        ':stroke_order_required' => !empty($q['stroke_order_required']) ? 1 : 0,
                        ':live_feedback_enabled' => array_key_exists('live_feedback_enabled', $q) ? (!empty($q['live_feedback_enabled']) ? 1 : 0) : 1,
                        ':memory_check_enabled' => array_key_exists('memory_check_enabled', $q) ? (!empty($q['memory_check_enabled']) ? 1 : 0) : 1,
                        ':feedback_beginner' => $q['feedback_beginner'] ?? null,
                        ':feedback_intermediate' => $q['feedback_intermediate'] ?? null,
                        ':feedback_advanced' => $q['feedback_advanced'] ?? null,
                        ':analysis_profile' => encodeAnalysisProfile($q),
                        ':seq_no'    => $q['sequence_no'] ?? ($index + 1),
                        ':max_score' => $q['max_score'] ?? 5,
                        ':q_text'    => $q['question_text'] ?? null,
                        ':created_by_admin_id' => $admin_id,
                        ':updated_by_admin_id' => $admin_id
                    ]);
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'สร้างแบบทดสอบสำเร็จ', 'id' => $quiz_set_id]);
            break;

        // ----------------------------------------------------
        // 3. แก้ไขชุดแบบทดสอบ
        // ----------------------------------------------------
        case 'quiz_update':
            if (empty($input['id'])) throw new Exception("ไม่พบ ID ที่ต้องการแก้ไข");
            
            $conn->beginTransaction();

            $sql = "UPDATE writing_quiz_sets 
                    SET title = :title, 
                        description = :description, 
                        group_id = :group_id, 
                        order_type = :order_type,
                        pass_score = :pass_score, 
                        time_limit_seconds = :time_limit, 
                        is_active = :is_active 
                    WHERE quiz_set_id = :id";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':title'       => $input['title'],
                ':description' => $input['description'] ?? null,
                ':group_id'    => $input['group_id'] ?: null,
                ':order_type'  => $input['order_type'] ?? 'sequential',
                ':pass_score'  => $input['pass_score'] ?? 70,
                ':time_limit'  => $input['time_limit_seconds'] ?? 0,
                ':is_active'   => $input['is_active'] ?? 1,
                ':id'          => $input['id']
            ]);

            // ลบคำถามเก่าออกทั้งหมด และ Insert คำถามใหม่
            if (isset($input['questions'])) {
                $conn->prepare("DELETE FROM writing_quiz_questions WHERE quiz_set_id = ?")->execute([$input['id']]);

                if (!empty($input['questions'])) {
                    $sqlQ = "INSERT INTO writing_quiz_questions
                             (quiz_set_id, character_id, target_character, target_romaji, expected_stroke_count,
                              difficulty_level, position_tolerance, stroke_order_required, live_feedback_enabled,
                              memory_check_enabled, feedback_beginner, feedback_intermediate, feedback_advanced,
                              analysis_profile, sequence_no, max_score, question_text, created_by_admin_id, updated_by_admin_id)
                             VALUES (:q_set_id, :char_id, :target_character, :target_romaji, :expected_stroke_count,
                              :difficulty_level, :position_tolerance, :stroke_order_required, :live_feedback_enabled,
                              :memory_check_enabled, :feedback_beginner, :feedback_intermediate, :feedback_advanced,
                              :analysis_profile, :seq_no, :max_score, :q_text, :created_by_admin_id, :updated_by_admin_id)";
                    $stmtQ = $conn->prepare($sqlQ);
                    
                    foreach ($input['questions'] as $index => $q) {
                        $stmtQ->execute([
                            ':q_set_id'  => $input['id'],
                            ':char_id'   => $q['character_id'],
                            ':target_character' => $q['target_character'] ?? null,
                            ':target_romaji' => $q['target_romaji'] ?? null,
                            ':expected_stroke_count' => isset($q['expected_stroke_count']) ? (int)$q['expected_stroke_count'] : null,
                            ':difficulty_level' => $q['difficulty_level'] ?? 'normal',
                            ':position_tolerance' => $q['position_tolerance'] ?? 20,
                            ':stroke_order_required' => !empty($q['stroke_order_required']) ? 1 : 0,
                            ':live_feedback_enabled' => array_key_exists('live_feedback_enabled', $q) ? (!empty($q['live_feedback_enabled']) ? 1 : 0) : 1,
                            ':memory_check_enabled' => array_key_exists('memory_check_enabled', $q) ? (!empty($q['memory_check_enabled']) ? 1 : 0) : 1,
                            ':feedback_beginner' => $q['feedback_beginner'] ?? null,
                            ':feedback_intermediate' => $q['feedback_intermediate'] ?? null,
                            ':feedback_advanced' => $q['feedback_advanced'] ?? null,
                            ':analysis_profile' => encodeAnalysisProfile($q),
                            ':seq_no'    => $q['sequence_no'] ?? ($index + 1),
                            ':max_score' => $q['max_score'] ?? 5,
                            ':q_text'    => $q['question_text'] ?? null,
                            ':created_by_admin_id' => $admin_id,
                            ':updated_by_admin_id' => $admin_id
                        ]);
                    }
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'อัปเดตแบบทดสอบสำเร็จ']);
            break;

        // ----------------------------------------------------
        // 4. ลบชุดแบบทดสอบ
        // ----------------------------------------------------
        case 'quiz_delete':
            $id = $input['id'] ?? $_GET['id'] ?? null;
            if (!$id) throw new Exception("กรุณาระบุ ID ที่ต้องการลบ");

            // การลบตัวตารางหลัก (ถ้าตั้ง Foreign Key เป็น ON DELETE CASCADE จะลบคำถามย่อยให้อัตโนมัติ)
            $stmt = $conn->prepare("DELETE FROM writing_quiz_sets WHERE quiz_set_id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'ลบแบบทดสอบสำเร็จ']);
            break;

        default:
            throw new Exception("ไม่พบ Action ที่ระบุ");
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('writing_quiz_sets.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถดำเนินการกับข้อมูลได้'], JSON_UNESCAPED_UNICODE);
}
