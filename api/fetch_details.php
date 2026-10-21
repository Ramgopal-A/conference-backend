<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Get registration number from URL parameters
$registration_no = $_GET['registration_no'] ?? '';

if (empty($registration_no)) {
    echo json_encode(["error" => "Registration number is required"]);
    exit;
}

try {
    // 1. Fetch from Registration Table Function
    $stmt_reg = $pdo_moesnt->prepare("SELECT * FROM public.get_registration_detail(:reg_no)");
    $stmt_reg->execute(['reg_no' => $registration_no]);
    $registration = $stmt_reg->fetch(PDO::FETCH_ASSOC);

    // 2. Fetch from Payments Table Function
    $stmt_pay = $pdo_moesnt->prepare("SELECT * FROM public.get_payment_detail(:reg_no)");
    $stmt_pay->execute(['reg_no' => $registration_no]);
    $payment = $stmt_pay->fetch(PDO::FETCH_ASSOC);

    // 3. Fetch from Abstract Table Function
    $stmt_abs = $pdo_moesnt->prepare("SELECT * FROM public.get_abstract_detail(:reg_no)");
    $stmt_abs->execute(['reg_no' => $registration_no]);
    $abstract = $stmt_abs->fetch(PDO::FETCH_ASSOC);

    // Return combined JSON response
    echo json_encode([
        "success" => true,
        "registration_no" => $registration_no,
        "data" => [
            "registration" => $registration ?: null,
            "payment" => $payment ?: null,
            "abstract" => $abstract ?: null
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode([
        "error" => "Database execution failed: " . $e->getMessage()
    ]);
}
?>