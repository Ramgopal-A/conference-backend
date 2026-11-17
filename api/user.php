<?php
// api/user.php - Standalone User Profile & Management File

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Database Credentials (Direct / No .env required)
$host = 'localhost';
$port = '5432';
$db   = 'conference_db';
$user = 'postgres';
$pass = 'Ramgopal@123';

try {
    $dsn_conf = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo_conf = new PDO($dsn_conf, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $dsn_moesnt = "pgsql:host=$host;port=$port;dbname=moesnt";
    $pdo_moesnt = new PDO($dsn_moesnt, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database connection failed: " . $e->getMessage()]);
    exit();
}

// Get userId or identifier from query parameters
$userId = trim($_GET['user_id'] ?? $_GET['id'] ?? $_GET['registration_no'] ?? '');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (empty($userId)) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "User ID or registration number is required."]);
        exit();
    }
    getUserMe($pdo_conf, $pdo_moesnt, $userId);
} elseif ($method === 'DELETE') {
    if (empty($userId)) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "User ID is required for deletion."]);
        exit();
    }
    deleteUserMe($pdo_conf, $userId);
} else {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
}

function getUserMe($pdo_conf, $pdo_moesnt, $userId) {
    // 1. Check keynote_speakers in conference_db (handles IWA/2026/KEY/0001 or UUID)
    try {
        $stmtKey = $pdo_conf->prepare("
            SELECT * 
            FROM public.keynote_speakers 
            WHERE TRIM(LOWER(registration_no)) = TRIM(LOWER(?)) 
               OR id::text = ?
            LIMIT 1
        ");
        $stmtKey->execute([$userId, $userId]);
        $keynote = $stmtKey->fetch(PDO::FETCH_ASSOC);

        if ($keynote) {
            unset($keynote['password_hash'], $keynote['password']);
            $keynote['full_name']   = $keynote['full_name'] ?? $keynote['name'] ?? 'Keynote Speaker';
            $keynote['name']        = $keynote['name'] ?? $keynote['full_name'];
            $keynote['role']        = 'speaker';
            $keynote['badge_title'] = 'Keynote Speaker';
            $keynote['payment_status'] = 'exempt';

            echo json_encode(["success" => true, "data" => $keynote]);
            return;
        }
    } catch (Exception $e) {
        error_log("keynote_speakers lookup failed: " . $e->getMessage());
    }

    // 2. Check general 'users' table in conference_db
    try {
        $stmt = $pdo_conf->prepare("
            SELECT id, email, full_name, phone, organization, designation, country, role, category, salutation, organization_address 
            FROM public.users 
            WHERE id::text = ? OR TRIM(LOWER(registration_no)) = TRIM(LOWER(?))
            LIMIT 1
        ");
        $stmt->execute([$userId, $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            echo json_encode(["success" => true, "data" => $user]);
            return;
        }
    } catch (Exception $e) {
        // Fall back to moesnt search
    }

    // 3. Check moesnt database (Presenters & Exhibitors/Sponsors)
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
            WHERE TRIM(LOWER(r.registration_no)) = TRIM(LOWER(?)) OR r.id::text = ?
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
                WHERE TRIM(LOWER(r.registration_no)) = TRIM(LOWER(?)) OR r.id::text = ?
            ");
            $stmt->execute([$identifier, $identifier]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($result) {
            unset($result['password']);
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
    try {
        $stmt = $pdo_conf->prepare("DELETE FROM users WHERE id = ? RETURNING id");
        $stmt->execute([$userId]);
        if ($stmt->rowCount() == 0) {
            http_response_code(404);
            echo json_encode(["success" => false, "error" => "User not found"]);
        } else {
            echo json_encode(["success" => true, "message" => "Account permanently deleted."]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Deletion failed: " . $e->getMessage()]);
    }
}
?>