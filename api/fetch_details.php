<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$registration_no = $_GET['registration_no'] ?? '';

if (empty($registration_no)) {
    echo json_encode(["error" => "Registration number is required"]);
    exit;
}

try {
    // Calls your single database function
    $stmt = $pdo_moesnt->prepare("SELECT * FROM public.get_conference_details(:reg_no::VARCHAR)");
    $stmt->execute(['reg_no' => $registration_no]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        echo json_encode([
            "success" => true,
            "data" => $result
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "No records found for this registration number."
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        "error" => "Database execution failed: " . $e->getMessage()
    ]);
}
?>