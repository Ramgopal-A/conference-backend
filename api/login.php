<?php
// api/login.php - Resilient Unified Login File with Payment Verification

// Dynamic CORS Header Handling (Supports Flutter Web random ports, APKs, and production domains)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    empty($origin) ||
    preg_match('/^https?:\/\/(localhost|127\.0\.0\.1|192\.168\.\d+\.\d+)(:\d+)?$/', $origin) ||
    strpos($origin, 'niot.res.in') !== false
) {
    header("Access-Control-Allow-Origin: " . ($origin ?: "*"));
    header("Access-Control-Allow-Credentials: true");
    header("Vary: Origin");
} else {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With");

// Handle Preflight OPTIONS request immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit();
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$data = json_decode(file_get_contents("php://input"), true);
$secret_key = "super_secret_conference_key_2026";

$host = 'localhost';
$port = '5432';
$user = 'postgres';
$pass = 'Ramgopal@123';

$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Email and password are required."]);
    exit();
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function generateJWT($payload, $secret) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlPayload = base64UrlEncode(json_encode($payload));
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
    $base64UrlSignature = base64UrlEncode($signature);
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

function verifyUserPassword($inputPassword, $storedPassword) {
    if (empty($storedPassword)) return false;
    if (password_verify($inputPassword, $storedPassword)) return true;
    return $inputPassword === $storedPassword;
}

$authenticatedUser = null;
$role = 'attendee';
$badgeTitle = 'Attendee';
$duty = null;
$registration_no = '';
$userId = null;
$fullName = '';
$speakerUuid = null;
$participantCategory = null;
$participantStatus = null;

// Database Connections
$pdo_conf = null;
$pdo_moesnt = null;

try {
    $pdo_conf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    error_log("conference_db connection failed: " . $e->getMessage());
}

try {
    $pdo_moesnt = new PDO("pgsql:host=$host;port=$port;dbname=moesnt", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    error_log("moesnt connection failed: " . $e->getMessage());
}

// 1. Check local_organizers table in conference_db
if ($pdo_conf && !$authenticatedUser) {
    try {
        $stmtOrg = $pdo_conf->prepare("SELECT * FROM public.local_organizers WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmtOrg->execute([$email]);
        $orgUser = $stmtOrg->fetch();

        if ($orgUser && verifyUserPassword($password, $orgUser['password_hash'] ?? '')) {
            $authenticatedUser = $orgUser;
            $userId = $orgUser['id'];
            $fullName = $orgUser['full_name'] ?? 'Local Organizer';
            $role = 'local_organizer';
            $badgeTitle = 'Local Organizer';
            $duty = $orgUser['duty'] ?? null;

            if ($duty === 'registration_desk') {
                $registration_no = 'ORG-REG-01';
            } elseif ($duty === 'food_distribution') {
                $registration_no = 'ORG-FOOD-01';
            } elseif ($duty === 'venue_access') {
                $registration_no = 'ORG-VENUE-01';
            } else {
                $registration_no = 'ORG-01';
            }
        }
    } catch (PDOException $e) {
        error_log("local_organizers check skipped: " . $e->getMessage());
    }
}

// 2. Check keynote_speakers in conference_db
if ($pdo_conf && !$authenticatedUser) {
    try {
        $stmtKeynote = $pdo_conf->prepare("SELECT * FROM public.keynote_speakers WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmtKeynote->execute([$email]);
        $keyUser = $stmtKeynote->fetch();

        if ($keyUser && verifyUserPassword($password, $keyUser['password_hash'] ?? $keyUser['password'] ?? '')) {
            $authenticatedUser = $keyUser;
            $speakerUuid = $keyUser['id'];
            $userId = $keyUser['registration_no'] ?? $keyUser['id'];
            $registration_no = $keyUser['registration_no'] ?? $keyUser['id'];
            $fullName = $keyUser['name'] ?? 'Keynote Speaker';
            $role = 'speaker';
            $badgeTitle = 'Keynote Speaker';
        }
    } catch (PDOException $e) {
        error_log("keynote_speakers check failed: " . $e->getMessage());
    }
}

// 3. Check general users table in conference_db (Speakers / Visitors / Students)
if ($pdo_conf && !$authenticatedUser) {
    try {
        $stmtConf = $pdo_conf->prepare("SELECT * FROM public.users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmtConf->execute([$email]);
        $confUser = $stmtConf->fetch();

        if ($confUser && verifyUserPassword($password, $confUser['password_hash'] ?? $confUser['password'] ?? '')) {
            $authenticatedUser = $confUser;
            $userId = $confUser['id'];
            $registration_no = $confUser['registration_no'] ?? '';
            $fullName = $confUser['full_name'] ?? '';
            $rawRole = strtolower(trim($confUser['role'] ?? 'attendee'));

            if (strpos($rawRole, 'speaker') !== false) {
                $role = 'speaker'; $badgeTitle = 'Speaker';
            } elseif (strpos($rawRole, 'student') !== false) {
                $role = 'student'; $badgeTitle = 'Student';
            } elseif (strpos($rawRole, 'visitor') !== false) {
                $role = 'visitor'; $badgeTitle = 'Visitor';
            } else {
                $role = 'attendee'; $badgeTitle = 'Attendee';
            }
        }
    } catch (PDOException $e) {
        error_log("users check failed: " . $e->getMessage());
    }
}

// 4. Check iwa2026_spregistration in moesnt (Exhibitor / Sponsor)
if ($pdo_moesnt && !$authenticatedUser) {
    try {
        $stmtSp = $pdo_moesnt->prepare("SELECT * FROM public.iwa2026_spregistration WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmtSp->execute([$email]);
        $sponsorUser = $stmtSp->fetch();

        if ($sponsorUser && verifyUserPassword($password, $sponsorUser['password'] ?? '')) {
            $authenticatedUser = $sponsorUser;
            $userId = $sponsorUser['registration_no'] ?? $sponsorUser['id'];
            $registration_no = $sponsorUser['registration_no'] ?? '';
            $fullName = $sponsorUser['fullname'] ?? $sponsorUser['name'] ?? '';
            $role = 'exhibitor_sponsor';
            $badgeTitle = 'Exhibitor / Sponsor';

            $participantCategory = $sponsorUser['participant_category'] ?? $sponsorUser['category'] ?? 'Exhibitor / Sponsor';
            $participantStatus = $sponsorUser['status'] ?? 'Active';
        }
    } catch (PDOException $e) {
        error_log("spregistration check failed: " . $e->getMessage());
    }
}

// 5. Check iwa2026_registration in moesnt (Presenter / Attendee)
if ($pdo_moesnt && !$authenticatedUser) {
    try {
        $stmtPres = $pdo_moesnt->prepare("SELECT * FROM public.iwa2026_registration WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmtPres->execute([$email]);
        $presenter = $stmtPres->fetch();

        if ($presenter && verifyUserPassword($password, $presenter['password'] ?? '')) {
            $authenticatedUser = $presenter;
            $userId = $presenter['registration_no'] ?? $presenter['id'];
            $registration_no = $presenter['registration_no'] ?? '';
            $fullName = $presenter['fullname'] ?? $presenter['name'] ?? '';
            $role = 'presenter';
            $badgeTitle = 'Presenter';

            $participantCategory = $presenter['participant_category'] ?? null;
            $participantStatus = $presenter['status'] ?? null;
        }
    } catch (PDOException $e) {
        error_log("registration check failed: " . $e->getMessage());
    }
}

// If credentials don't match any table
if (!$authenticatedUser) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Invalid email or password."]);
    exit();
}

// =========================================================================
// PAYMENT VERIFICATION CHECK
// =========================================================================

$registration_no = trim($registration_no);

// 1. Roles inherently exempt from payment check:
$exemptRoles = ['local_organizer', 'keynote_speaker', 'speaker', 'visitor'];

// 2. Company / Organization Working Officials Whitelist (NIOT Officials)
$companyExemptRegistrations = [
    'IWA/2026/00001',
    'IWA/2026/00006',
    'IWA/2026/00008', // R. Revathy
    'IWA/2026/00058',
    'IWA/2026/00059',
    'IWA/2026/00062',
    'IWA/2026/00065',
    'IWA/2026/00076',
    'IWA/2026/00081',
    'IWA/2026/00082',
    'IWA/2026/00083',
    'IWA/2026/00093',
    'IWA/2026/00094',
    'IWA/2026/00101',
    'IWA/2026/00110',
    'IWA/2026/00112',
    'IWA/2026/00115',
    'IWA/2026/00120',
    'IWA/2026/00121',
    'IWA/2026/00125',
    'IWA/2026/00127',
    'IWA/2026/00130',
    'IWA/2026/00140',
    'IWA/2026/00143',
    'IWA/2026/00148',
    'IWA/2026/00149',
    'IWA/2026/00150',
    'IWA/2026/00155',
    'IWA/2026/00156',
    'IWA/2026/00158',
    'IWA/2026/00159',
    'IWA/2026/00161',
    'IWA/2026/00162',
    'IWA/2026/00166',
    'IWA/2026/00168',
    'IWA/2026/00169',
    'IWA/2026/00171',
    'IWA/2026/00172',
    'IWA/2026/00173',
    'IWA/2026/00179',
    'IWA/2026/00224',
    'IWA/2026/00250',
    'IWA/2026/00251'
];

// 3. Auto-exempt internal NIOT / Government project personnel by email domain
$isNiotStaff = (
    stripos($email, 'niot.res.in') !== false ||
    stripos($email, 'niot@gov.in') !== false ||
    stripos($email, '@gov.in') !== false
);

$isExempt = in_array($role, $exemptRoles, true)
    || in_array($registration_no, $companyExemptRegistrations, true)
    || $isNiotStaff;

// Verify payment for all non-exempt users
if (!$isExempt) {
    if (empty($registration_no)) {
        http_response_code(402);
        echo json_encode([
            "success" => false,
            "error" => "No valid registration record found for this account. Please register and complete payment.",
            "role" => $role
        ]);
        exit();
    }

    if (!$pdo_moesnt) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "error" => "Unable to connect to registration server to verify payment. Please contact event support."
        ]);
        exit();
    }

    try {
        $payStmt = $pdo_moesnt->prepare("
            SELECT payment_id, registration_no, payment_status, isverified 
            FROM public.iwa2026_payments 
            WHERE (
                TRIM(LOWER(registration_no)) = TRIM(LOWER(?))
                OR registration_id::text = ?
                OR registration_no ILIKE ?
            )
            AND TRIM(LOWER(payment_status)) = 'success' 
            AND (
                isverified IS NULL 
                OR TRIM(UPPER(isverified::text)) IN ('Y', 'YES', 'TRUE', '1', '')
            )
            ORDER BY payment_id DESC
            LIMIT 1
        ");
        $payStmt->execute([
            $registration_no,
            (string)$userId,
            '%' . $registration_no . '%'
        ]);
        $paymentRecord = $payStmt->fetch();

        if (!$paymentRecord) {
            http_response_code(402); // 402 Payment Required
            echo json_encode([
                "success" => false,
                "error" => "Payment verification required. Your registration or booth sponsorship fee has not been verified yet. Please complete payment to access your account.",
                "registration_no" => $registration_no,
                "role" => $role
            ]);
            exit();
        }
    } catch (PDOException $e) {
        error_log("Payment verification query failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Error verifying payment records."]);
        exit();
    }
}

// =========================================================================
// FETCH PERSONAL SCHEDULE
// =========================================================================
$scheduleData = [];
if ($pdo_moesnt && (!empty($registration_no) || !empty($speakerUuid))) {
    try {
        // Prevent keynote speaker IDs (e.g., IWA/2026/KEY/0001) from matching presenter ABS/0001 slots
        $isKeynote = (stripos($registration_no, '/KEY/') !== false || !empty($speakerUuid));
        preg_match('/(\d+)$/', $registration_no, $matches);
        $numSuffix = $isKeynote ? '' : ($matches[1] ?? '');
        $shortNumSuffix = $isKeynote ? '' : ltrim($numSuffix, '0');

        $schedStmt = $pdo_moesnt->prepare("
            SELECT 
                s.id AS schedule_id,
                s.conf_date,
                s.from_time,
                s.to_time,
                s.venue,
                s.program_name,
                s.presenter_id,
                s.keynote_speaker_id,
                COALESCE(a.title, s.program_name) AS presentation_title,
                a.abstract_id,
                a.presentation_type,
                a.author_name
            FROM public.iwa2026_schedule s
            LEFT JOIN public.iwa2026_abstract a 
                ON TRIM(LOWER(s.presenter_id)) = TRIM(LOWER(a.abstract_id))
            WHERE 
                s.presenter_id IN (
                    SELECT TRIM(abstract_id) 
                    FROM public.iwa2026_abstract 
                    WHERE TRIM(LOWER(registration_no)) = TRIM(LOWER(?))
                      AND abstract_id IS NOT NULL
                )
                OR TRIM(LOWER(s.presenter_id)) = TRIM(LOWER(?))
                OR TRIM(LOWER(s.keynote_speaker_id)) = TRIM(LOWER(?))
                OR TRIM(LOWER(s.keynote_speaker_id)) = TRIM(LOWER(?))
                OR (
                    ? != '' AND (
                        s.presenter_id ILIKE '%ABS/' || ?
                        OR s.presenter_id ILIKE '%ABS/%' || ?
                    )
                )
            ORDER BY s.conf_date ASC, s.from_time ASC
        ");

        $schedStmt->execute([
            $registration_no,
            $registration_no,
            $registration_no,
            (string)$speakerUuid,
            $numSuffix,
            $numSuffix,
            $shortNumSuffix
        ]);
        $scheduleData = $schedStmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Schedule fetch failed: " . $e->getMessage());
    }
}

// =========================================================================
// JWT TOKEN GENERATION & FINAL RESPONSE
// =========================================================================
$payload = [
    "userId"               => $userId,
    "registration_no"      => $registration_no,
    "email"                => $email,
    "role"                 => $role,
    "duty"                 => $duty,
    "badge_title"          => $badgeTitle,
    "participant_category" => $participantCategory,
    "status"               => $participantStatus,
    "exp"                  => time() + (86400 * 7) // 7-day token expiration
];

$token = generateJWT($payload, $secret_key);

http_response_code(200);
echo json_encode([
    "success" => true,
    "token"   => $token,
    "user"    => [
        "id"                   => $userId,
        "name"                 => $fullName,
        "email"                => $email,
        "role"                 => $role,
        "duty"                 => $duty,
        "badge_title"          => $badgeTitle,
        "registration_no"      => $registration_no,
        "participant_category" => $participantCategory,
        "status"               => $participantStatus,
        "schedule"             => $scheduleData
    ]
]);