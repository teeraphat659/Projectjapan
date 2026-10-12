# Chen ER Diagram — jp_learning

จัดทำจาก `jp_learning.sql` เพื่ออธิบายโครงสร้างเท่านั้น ไม่มีการแก้โค้ดหรือฐานข้อมูล

สัญลักษณ์แบบ Chen: **สี่เหลี่ยม** = Entity/ตาราง, **ข้าวหลามตัด** = Relationship, **วงรี** = Attribute, และเลข `1`, `N` บนเส้น = Cardinality

## 1) ผู้ใช้และการฝึกเขียน

```mermaid
flowchart LR
    U[users]
    WS[writing_sessions]
    WA[writing_attempts]
    EV[writing_attempt_evaluations]
    TL[writing_training_labels]
    MA[writing_character_mastery]
    JC[japanese_characters]

    R1{เริ่มการฝึก}
    R2{บันทึกความพยายาม}
    R3{ประเมินผล}
    R4{ติดป้ายกำกับ}
    R5{มีความชำนาญ}
    R6{วัดความชำนาญของ}

    U ---|1| R1 ---|N| WS
    WS ---|1| R2 ---|N| WA
    WA ---|1| R3 ---|0..1| EV
    WA ---|1| R4 ---|0..1| TL
    U ---|1| R5 ---|N| MA
    JC ---|1| R6 ---|N| MA

    U --- U1([PK: id])
    U --- U2([username])
    WS --- WS1([PK: writing_session_id])
    WS --- WS2([score, started_at, completed_at])
    WA --- WA1([PK: writing_attempt_id])
    WA --- WA2([average_score, is_passed])
    EV --- EV1([PK: evaluation_id])
    EV --- EV2([total_score, evaluation_status])
    TL --- TL1([PK: training_label_id])
    TL --- TL2([human_score, label_status])
    MA --- MA1([PK: mastery_id])
    MA --- MA2([mastery_score, mastery_level])
```

## 2) ข้อมูลตัวอักษรญี่ปุ่นและสื่อประกอบ

```mermaid
flowchart LR
    CG[character_groups]
    JC[japanese_characters]
    ST[japanese_character_strokes]
    RV[writing_reference_versions]
    SR[writing_svg_answer_references]
    AW[assisted_writing]
    CSI[character_stroke_images]

    R1{จัดอยู่ในกลุ่ม}
    R2{มีลำดับเส้น}
    R3{มีเส้นอ้างอิง}
    R4{มี SVG อ้างอิง}
    R5{ใช้เป็นตัวอักษรต้นแบบ}
    R6{มีภาพลำดับเส้น}

    CG ---|1| R1 ---|N| JC
    JC ---|1| R2 ---|N| ST
    JC ---|1| R3 ---|N| RV
    JC ---|1| R4 ---|N| SR
    JC ---|1| R5 ---|N| AW
    JC -. "1 : N (logical only)" .-> R6
    R6 -.-> CSI

    CG --- CG1([PK: id])
    CG --- CG2([name, sort_order])
    JC --- JC1([PK: character_id])
    JC --- JC2([japanese_char, romaji, char_type])
    ST --- ST1([PK: stroke_id])
    ST --- ST2([stroke_order, path_data])
    RV --- RV1([PK: reference_version_id])
    RV --- RV2([version_name, trajectory_json])
    SR --- SR1([PK: svg_reference_id])
    SR --- SR2([version_name, svg_content])
    AW --- AW1([PK: assisted_writing_id])
    CSI --- CSI1([PK: character_stroke_image_id])
```

## 3) ชุดข้อสอบ

```mermaid
flowchart LR
    AG[alphabet_groups]
    WQS[writing_quiz_sets]
    WQQ[writing_quiz_questions]
    JC[japanese_characters]
    QS[quiz_sets]
    QSQ[quiz_set_questions]
    U[users]
    QZ[quizzes]

    R1{จัดชุดข้อสอบเขียนตามกลุ่ม}
    R2{ประกอบด้วยคำถามเขียน}
    R3{ถามตัวอักษร}
    R4{ประกอบด้วยคำถามทั่วไป}
    R5{ทำแบบทดสอบ}

    AG ---|1| R1 ---|N| WQS
    WQS ---|1| R2 ---|N| WQQ
    JC ---|1| R3 ---|N| WQQ
    QS ---|1| R4 ---|N| QSQ
    U ---|1| R5 ---|N| QZ

    AG --- AG1([PK: group_id])
    AG --- AG2([group_code, group_name_th])
    WQS --- WQS1([PK: quiz_set_id])
    WQS --- WQS2([title, pass_score])
    WQQ --- WQQ1([PK: question_id])
    WQQ --- WQQ2([sequence_no, max_score])
    QS --- QS1([PK: quiz_set_id])
    QSQ --- QSQ1([PK: quiz_set_question_id])
    QZ --- QZ1([PK: id])
    QZ --- QZ2([quiz_type, score, completed_at])
```

## 4) ความสัมพันธ์เดิมที่ปลายทางหายไปจากไฟล์ SQL

```mermaid
flowchart LR
    M[member — ไม่พบ CREATE TABLE ใน dump]
    AW[assisted_writing]
    EA[essay_answers]
    R1{ทำแบบฝึกเขียนมีตัวช่วย}
    R2{ส่งคำตอบข้อเขียน}

    M ---|1| R1 ---|N| AW
    M ---|1| R2 ---|N| EA

    M --- M1([PK: Member_ID])
    AW --- AW1([PK: assisted_writing_id])
    EA --- EA1([PK: answer_id])
    EA --- EA2([exam_set_id, essay_id, evaluation_score])
```

`member` ถูกอ้างอิงด้วย Foreign Key ใน `assisted_writing` และ `essay_answers` แต่ไม่มีคำสั่งสร้างตารางนี้ใน dump จึงควรตรวจสอบไฟล์ export ต้นทางหากต้องการ ER Diagram ที่ครบถ้วน

## 5) ตารางสนับสนุนหรือความสัมพันธ์ที่ SQL ยังไม่บังคับ

| ตาราง | หมายเหตุ |
|---|---|
| `admin` | ถูกอ้างอิงโดยฟิลด์ `created_by_admin_id` / `updated_by_admin_id` หลายจุด แต่ไม่มี Foreign Key |
| `admin_writing_test_users` | `user_id` อ้างถึง `users` จริง; `admin_id` เป็น PK แต่ไม่มี FK ไป `admin` |
| `character_stroke_images` | มี `character_id` และ `created_by_admin_id` แต่ไม่มี Foreign Key |
| `quiz_format` | ตารางกำหนดรูปแบบแบบทดสอบ ไม่มี Foreign Key |
| `writing_answer_references` | มี `character_id` แต่ไม่มี Foreign Key |
| `writing_scoring_settings` | ตารางตั้งค่าน้ำหนักคะแนน ไม่มี Foreign Key |
| `writing_svg_stroke_images` | เชื่อมเชิงตรรกะด้วย `character_id` และ `version_name` แต่ไม่มี Foreign Key |
| `writing_variant_styles` | ตารางรายการสไตล์ ไม่มี Foreign Key |

เส้นประในแผนภาพหมายถึงชื่อคอลัมน์บ่งชี้ความสัมพันธ์ แต่ฐานข้อมูลยังไม่ได้ประกาศ Foreign Key จึงไม่ควรตีความว่าเป็นความสัมพันธ์ที่ระบบบังคับอยู่จริง
