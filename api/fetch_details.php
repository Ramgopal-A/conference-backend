<?php
// api/fetch_details.php - Standalone File

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Database Credentials (Direct / No .env or external config required)
$host = 'localhost';
$port = '5432';
$db   = 'moesnt';
$user = 'postgres';
$pass = 'Ramgopal@123';

$registration_no = trim($_GET['registration_no'] ?? '');

if (empty($registration_no)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Registration number is required"]);
    exit;
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo_moesnt = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

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
        WHERE TRIM(LOWER(r.registration_no)) = TRIM(LOWER(:reg_no))
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
            WHERE TRIM(LOWER(r.registration_no)) = TRIM(LOWER(:reg_no))
        ");
        $stmt->execute(['reg_no' => $registration_no]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 3. If not found in moesnt, check conference_db (keynote_speakers and users)
    if (!$result) {
        try {
            $pdo_conf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            $stmtKey = $pdo_conf->prepare("
                SELECT * 
                FROM public.keynote_speakers 
                WHERE TRIM(LOWER(registration_no)) = TRIM(LOWER(:reg_no)) 
                   OR id::text = :reg_no
                LIMIT 1
            ");
            $stmtKey->execute(['reg_no' => $registration_no]);
            $keynote = $stmtKey->fetch(PDO::FETCH_ASSOC);

            if ($keynote) {
                unset($keynote['password_hash'], $keynote['password']);
                $displayName = $keynote['full_name'] ?? $keynote['name'] ?? 'Keynote Speaker';
                $keynote['fullname']       = $displayName;
                $keynote['full_name']      = $displayName;
                $keynote['name']           = $displayName;
                $keynote['role']           = 'speaker';
                $keynote['badge_title']    = 'Keynote Speaker';
                $keynote['payment_status'] = 'exempt';
                $result = $keynote;
            } else {
                $stmtUser = $pdo_conf->prepare("
                    SELECT * 
                    FROM public.users 
                    WHERE TRIM(LOWER(registration_no)) = TRIM(LOWER(:reg_no)) 
                       OR id::text = :reg_no
                    LIMIT 1
                ");
                $stmtUser->execute(['reg_no' => $registration_no]);
                $confUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

                if ($confUser) {
                    unset($confUser['password_hash'], $confUser['password']);
                    $displayName = $confUser['full_name'] ?? $confUser['name'] ?? '';
                    $confUser['fullname']  = $displayName;
                    $confUser['full_name'] = $displayName;
                    $result = $confUser;
                }
            }
        } catch (PDOException $e) {
            error_log("conference_db lookup in fetch_details failed: " . $e->getMessage());
        }
    }

    if ($result) {
        unset($result['password'], $result['password_hash']);
        echo json_encode([
            "success" => true,
            "data" => $result
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "No records found for this registration number."
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database execution failed: " . $e->getMessage()
    ]);
}
?>