<?php
// api/attendance.php - Standalone Registration Desk Check-In API

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$host       = 'localhost';
$port       = '5432';
$db         = 'conference_db';
$user       = 'postgres';
$pass       = 'Ramgopal@123';
$secret_key = 'super_secret_conference_key_2026';

function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function verifyJWT($jwt, $secret) {
    $tokenParts = explode('.', $jwt);
    if (count($tokenParts) !== 3) return false;
    $payload = json_decode(base64UrlDecode($tokenParts[1]), true);
    if (isset($payload['exp']) && time() > $payload['exp']) return false;
    return $payload;
}

$headers = function_exists('apache_request_headers') ? apache_request_headers() : getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Access denied. No token provided."]);
    exit();
}

$token = str_replace("Bearer ", "", $authHeader);
$decoded = verifyJWT($token, $secret_key);

if (!$decoded) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Invalid or expired token."]);
    exit();
}

// RESTRICTION: Only allow the Registration Desk In-Charge
$callerRole = strtolower($decoded['role'] ?? '');
$callerDuty = strtolower($decoded['duty'] ?? '');

if ($callerRole !== 'local_organizer' || ($callerDuty !== 'registration_desk' && !empty($callerDuty))) {
    http_response_code(403);
    echo json_encode([
        "success" => false, 
        "error" => "Access denied: Only the Registration Desk In-Charge can mark registration attendance."
    ]);
    exit();
}

$scannedBy = $decoded['email'] ?? 'regdesk@iwa2026.com';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $method = $_SERVER['REQUEST_METHOD'];

    // POST: Mark Registration Desk Attendance
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        $registrationNo = trim($input['registration_no'] ?? $input['user_identifier'] ?? '');

        if (empty($registrationNo)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "registration_no is required."]);
            exit();
        }

        // Check if attendee has already checked in
        $checkStmt = $pdo->prepare("SELECT attended_at FROM public.attendance_logs WHERE registration_no = ?");
        $checkStmt->execute([$registrationNo]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "error" => "Delegate " . $registrationNo . " already checked in on " . $existing['attended_at']
            ]);
            exit();
        }

        // Insert attendance record
        $insertStmt = $pdo->prepare("
            INSERT INTO public.attendance_logs (registration_no, scanned_by, attended_at, status) 
            VALUES (?, ?, NOW(), 'ATTENDED')
        ");
        $insertStmt->execute([$registrationNo, $scannedBy]);

        echo json_encode([
            "success" => true,
            "message" => "Registration desk check-in successful for " . $registrationNo,
            "data" => [
                "registration_no" => $registrationNo,
                "scanned_by"      => $scannedBy,
                "status"          => "ATTENDED"
            ]
        ]);
    } 
    // GET: List all checked-in attendees
    elseif ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT id, registration_no, scanned_by, attended_at, status 
            FROM public.attendance_logs 
            ORDER BY attended_at DESC 
            LIMIT 200
        ");
        $logs = $stmt->fetchAll();

        echo json_encode([
            "success" => true,
            "count" => count($logs),
            "data" => $logs
        ]);
    } else {
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed."]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
}
?>