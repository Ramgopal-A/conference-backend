<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$registration_no = $_GET['registration_no'] ?? '';

if (empty($registration_no)) {
    echo json_encode(["error" => "Registration number is required"]);
    exit;
}

try {
    // 1. Try fetching from regular registration table first
    $stmt = $pdo_moesnt->prepare("
        SELECT 
            r.*,
            NULL AS cname,
            p.payment_id,
            p.txnid,
            p.amount AS payment_amount,
            p.payment_status,
            p.payment_mode,
            p.paid_on,
            p.bank_ref_num,
            p.bank_code,
            a.abstract_id,
            a.subtheme_id,
            a.division_id,
            a.title AS abstract_title,
            a.author_name,
            a.affiliation,
            a.email AS abstract_email,
            a.coauthors,
            a.abstract_text,
            a.keyword,
            a.remarks,
            a.presentation_type,
            a.reviewed_by,
            a.reviewed_date
        FROM public.iwa2026_registration r
        LEFT JOIN public.iwa2026_payments p ON r.registration_no = p.registration_no
        LEFT JOIN public.iwa2026_abstract a ON r.registration_no = a.registration_no
        WHERE r.registration_no = :reg_no
    ");
    $stmt->execute(['reg_no' => $registration_no]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. If not found, check the special registration table
    if (!$result) {
        $stmt = $pdo_moesnt->prepare("
            SELECT 
                r.*,
                p.payment_id,
                p.txnid,
                p.amount AS payment_amount,
                p.payment_status,
                p.payment_mode,
                p.paid_on,
                p.bank_ref_num,
                p.bank_code,
                a.abstract_id,
                a.subtheme_id,
                a.division_id,
                a.title AS abstract_title,
                a.author_name,
                a.affiliation,
                a.email AS abstract_email,
                a.coauthors,
                a.abstract_text,
                a.keyword,
                a.remarks,
                a.presentation_type,
                a.reviewed_by,
                a.reviewed_date
            FROM public.iwa2026_spregistration r
            LEFT JOIN public.iwa2026_payments p ON r.registration_no = p.registration_no
            LEFT JOIN public.iwa2026_abstract a ON r.registration_no = a.registration_no
            WHERE r.registration_no = :reg_no
        ");
        $stmt->execute(['reg_no' => $registration_no]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
    }

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