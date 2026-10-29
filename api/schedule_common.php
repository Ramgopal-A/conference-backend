<?php
// api/schedule_common.php

header('Content-Type: application/json');

// Include your main database configuration file (adjust path as needed for your project structure)
require_once __DIR__ . '/../config/database.php';

try {
    // If your main config uses $pdo_moesnt or $pdo, use it here:
    global $pdo_moesnt;
    $dbConnection = isset($pdo_moesnt) ? $pdo_moesnt : $pdo;

    $stmt = $dbConnection->query("
        SELECT id, conf_date, from_time, to_time, venue, program_name 
        FROM public.iwa2026_schedule 
        WHERE presenter_id IS NULL OR presenter_id = '' 
        ORDER BY conf_date ASC, from_time ASC
    ");
    $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $schedule
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Failed to fetch schedule: " . $e->getMessage()
    ]);
}
?>