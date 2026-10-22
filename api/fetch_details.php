<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$registration_no = $_GET['registration_no'] ?? '';

if (empty($registration_no)) {
    echo json_encode(["error" => "Registration number is required"]);
    exit;
}

try {
    // Map NULL AS cname for regular registrations so columns match the spregistration table
    $stmt = $pdo_moesnt->prepare("
        SELECT 
            r.id,
            r.registration_no,
            r.fullname,
            r.email,
            r.mobile,
            r.cname,
            p.payment_id,
            p.amount,
            p.payment_status,
            p.payment_mode,
            p.paid_on,
            a.abstract_id,
            a.title,
            a.presentation_type
        FROM (
            SELECT id, registration_no, fullname, email, mobile, NULL AS cname FROM public.iwa2026_registration
            UNION ALL
            SELECT id, registration_no, fullname, email, mobile, cname FROM public.iwa2026_spregistration
        ) r
        LEFT JOIN public.iwa2026_payments p ON r.registration_no = p.registration_no
        LEFT JOIN public.iwa2026_abstract a ON r.registration_no = a.registration_no
        WHERE r.registration_no = :reg_no
    ");
    
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