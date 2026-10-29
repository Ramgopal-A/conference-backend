<?php
// api/user_schedule.php

header('Content-Type: application/json');

// Include your main database configuration file
require_once __DIR__ . '/../config/database.php';

$registrationNo = isset($_GET['registration_no']) ? trim($_GET['registration_no']) : '';

if (empty($registrationNo)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "Registration number is required."
    ]);
    exit;
}

try {
    global $pdo_moesnt;
    $dbConnection = isset($pdo_moesnt) ? $pdo_moesnt : $pdo;

    $stmt = $dbConnection->prepare("
        SELECT id, conf_date, from_time, to_time, venue, program_name, presenter_id 
        FROM public.iwa2026_schedule 
        WHERE presenter_id = ?
    ");
    $stmt->execute([$registrationNo]);
    $presentation = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($presentation) {
        echo json_encode([
            "success" => true,
            "data" => $presentation
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "error" => "No presentation slot found for registration number: " . $registrationNo
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database query failed: " . $e->getMessage()
    ]);
}
?>