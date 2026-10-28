<?php
require_once __DIR__ . '/../config/database.php';

function getUserMe($pdo_conf, $userId) {
    global $pdo_moesnt;

    // 1. If $userId is a conference registration number (e.g. IWA/2026/00001), 
    // skip the uuid table and query moesnt directly!
    if (strpos($userId, 'IWA/') === 0 || strlen($userId) > 30) {
        fetchPresenterProfile($pdo_moesnt, $userId);
        return;
    }

    // 2. Otherwise, try finding the user in the main configuration 'users' table
    try {
        $stmt = $pdo_conf->prepare("SELECT id, email, full_name, phone, organization, designation, country, role, category, salutation, organization_address FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            echo json_encode(["success" => true, "data" => $user]);
            return;
        }
    } catch (Exception $e) {
        // If main users table fails or query errors out, fall back to moesnt search
    }

    // 3. Fallback: check moesnt database
    fetchPresenterProfile($pdo_moesnt, $userId);
}

function fetchPresenterProfile($pdo_moesnt, $identifier) {
    try {
        // Search regular registration first
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
            WHERE r.registration_no = ? OR r.id::text = ?
        ");
        $stmt->execute([$identifier, $identifier]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // If not found, check special registration table
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
                WHERE r.registration_no = ? OR r.id::text = ?
            ");
            $stmt->execute([$identifier, $identifier]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($result) {
            echo json_encode([
                "success" => true,
                "data" => $result
            ]);
        } else {
            http_response_code(404);
            echo json_encode(["success" => false, "error" => "User record not found for: " . $identifier]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Database execution failed: " . $e->getMessage()]);
    }
}

function deleteUserMe($pdo_conf, $userId) {
    $stmt = $pdo_conf->prepare("DELETE FROM users WHERE id = ? RETURNING id");
    $stmt->execute([$userId]);
    if ($stmt->rowCount() == 0) {
        http_response_code(404);
        echo json_encode(["error" => "User not found"]);
    } else {
        echo json_encode(["message" => "Account permanently deleted."]);
    }
}
?>