<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["HTTP_ORIGIN"] = "http://localhost";
session_start();
$_SESSION["member"]["id"] = 12;
$json = json_encode(["quiz_type" => "?????? ????????", "score" => 10, "total_questions" => 10, "time_taken" => 20]);
file_put_contents("test_input.json", $json);
// We cannot easily mock php://input without stream wrappers, so we just modify save_quiz_result temporarily?

