<?php
// api/user/me.php - Authenticated Current User Profile Endpoint

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit();
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$secret_key = "super_secret_conference_key_2026";

function base64UrlDecode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function verifyJWT($jwt, $secret) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;

    [$headerB64, $payloadB64, $sigB64] = $parts;
    $expectedSig = rtrim(strtr(base64_encode(
        hash_hmac('sha256', "$headerB64.$payloadB64", $secret, true)
    ), '+/', '-_'), '=');

    if (!hash_equals($expectedSig, $sigB64)) return null;

    $payload = json_decode(base64UrlDecode($payloadB64), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) {
        return null;
    }
    return $payload;
}

// Extract Bearer token from Nginx HTTP_AUTHORIZATION or Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');

if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Missing or invalid Authorization header."]);
    exit();
}

$claims = verifyJWT($matches[1], $secret_key);
if (!$claims) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Invalid or expired session token."]);
    exit();
}

$email = trim($claims['email'] ?? '');
$registrationNo = trim($claims['registration_no'] ?? '');
$role = $claims['role'] ?? 'attendee';

$host = 'localhost';
$port = '5432';
$user = 'postgres';
$pass = 'Ramgopal@123';

try {
    $pdo_conf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdo_moesnt = new PDO("pgsql:host=$host;port=$port;dbname=moesnt", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database connection failed."]);
    exit();
}

$profile = null;

// 1. Check keynote_speakers in conference_db
if (!$profile) {
    $stmt = $pdo_conf->prepare("
        SELECT * FROM public.keynote_speakers 
        WHERE LOWER(email) = LOWER(?) 
           OR (? != '' AND TRIM(LOWER(registration_no)) = TRIM(LOWER(?)))
        LIMIT 1
    ");
    $stmt->execute([$email, $registrationNo, $registrationNo]);
    if ($row = $stmt->fetch()) {
        unset($row['password_hash'], $row['password']);
        $displayName = $row['full_name'] ?? $row['name'] ?? 'Keynote Speaker';
        $row['name']        = $displayName;
        $row['full_name']   = $displayName;
        $row['fullname']    = $displayName;
        $row['role']        = 'speaker';
        $row['badge_title'] = 'Keynote Speaker';
        $profile = $row;
    }
}

// 2. Check local_organizers in conference_db
if (!$profile) {
    $stmt = $pdo_conf->prepare("SELECT * FROM public.local_organizers WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    if ($row = $stmt->fetch()) {
        unset($row['password_hash'], $row['password']);
        $row['name']            = $row['full_name'] ?? 'Local Organizer';
        $row['registration_no'] = $registrationNo;
        $row['role']            = 'local_organizer';
        $row['badge_title']     = 'Local Organizer';
        $profile = $row;
    }
}

// 3. Check users in conference_db
if (!$profile) {
    $stmt = $pdo_conf->prepare("SELECT * FROM public.users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    if ($row = $stmt->fetch()) {
        unset($row['password_hash'], $row['password']);
        $row['name']        = $row['full_name'] ?? '';
        $row['role']        = $claims['role'] ?? $row['role'] ?? 'attendee';
        $row['badge_title'] = $claims['badge_title'] ?? 'Attendee';
        $profile = $row;
    }
}

// 4. Check iwa2026_spregistration in moesnt
if (!$profile) {
    $stmt = $pdo_moesnt->prepare("SELECT * FROM public.iwa2026_spregistration WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    if ($row = $stmt->fetch()) {
        unset($row['password']);
        $displayName = $row['fullname'] ?? $row['name'] ?? '';
        $row['name']        = $displayName;
        $row['full_name']   = $displayName;
        $row['role']        = 'exhibitor_sponsor';
        $row['badge_title'] = 'Exhibitor / Sponsor';
        $profile = $row;
    }
}

// 5. Check iwa2026_registration in moesnt
if (!$profile) {
    $stmt = $pdo_moesnt->prepare("SELECT * FROM public.iwa2026_registration WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    if ($row = $stmt->fetch()) {
        unset($row['password']);
        $displayName = $row['fullname'] ?? $row['name'] ?? '';
        $row['name']        = $displayName;
        $row['full_name']   = $displayName;
        $row['role']        = 'presenter';
        $row['badge_title'] = 'Presenter';
        $profile = $row;
    }
}

if (!$profile) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "User profile not found."]);
    exit();
}

echo json_encode([
    "success" => true,
    "user"    => $profile,
    "data"    => $profile
]);