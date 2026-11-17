<?php
// api/venue_scan.php - Strict Single-Row Hall Entry & Exit Management

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

date_default_timezone_set('Asia/Kolkata');

$host       = 'localhost';
$port       = '5432';
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
    echo json_encode(["success" => false, "error" => "Access denied. Token missing."]);
    exit();
}

$token = str_replace("Bearer ", "", $authHeader);
$decoded = verifyJWT($token, $secret_key);

if (!$decoded) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Invalid or expired token."]);
    exit();
}

// RESTRICTION: Only allow Venue Access Organizer
$callerRole = strtolower($decoded['role'] ?? '');
$callerDuty = strtolower($decoded['duty'] ?? '');

if ($callerRole !== 'local_organizer' || ($callerDuty !== 'venue_access' && !empty($callerDuty))) {
    http_response_code(403);
    echo json_encode([
        "success" => false, 
        "error" => "Access denied: Only Venue Access Staff can record hall entry and exit."
    ]);
    exit();
}

$scannedBy = $decoded['email'] ?? 'venuedesk@iwa2026.com';

try {
    $pdo_conf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $pdo_moesnt = null;
    try {
        $pdo_moesnt = new PDO("pgsql:host=$host;port=$port;dbname=moesnt", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        error_log("moesnt connection error: " . $e->getMessage());
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);

        $regNo      = trim($input['registration_no'] ?? $input['user_identifier'] ?? '');
        $hallName   = trim($input['hall_name'] ?? '');
        $scanType   = strtolower(trim($input['scan_type'] ?? '')); // 'entry' or 'exit'
        $personName = trim($input['person_name'] ?? '');
        $userRole   = trim($input['user_role'] ?? '');

        if (empty($regNo) || empty($hallName) || empty($scanType)) {
            http_response_code(400);
            echo json_encode([
                "success" => false, 
                "error" => "registration_no, hall_name, and scan_type ('entry' or 'exit') are required."
            ]);
            exit();
        }

        if (!in_array($scanType, ['entry', 'exit'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "scan_type must be either 'entry' or 'exit'."]);
            exit();
        }

        // Auto-fetch Person Name and Role if not sent from frontend
        if (empty($personName) || empty($userRole)) {
            // 1. Check Sponsor / Exhibitor
            if ($pdo_moesnt && (empty($personName) || empty($userRole))) {
                $spStmt = $pdo_moesnt->prepare("SELECT fullname, pcategory FROM public.iwa2026_spregistration WHERE registration_no = ? LIMIT 1");
                $spStmt->execute([$regNo]);
                if ($row = $spStmt->fetch()) {
                    if (empty($personName)) $personName = $row['fullname'] ?? '';
                    if (empty($userRole)) $userRole = 'exhibitor_sponsor';
                }
            }
            // Check Keynote Speakers in conference_db
            if (empty($personName) || empty($userRole)) {
                $ksStmt = $pdo_conf->prepare("
                    SELECT name FROM public.keynote_speakers 
                    WHERE registration_no = ? OR id::text = ? 
                    LIMIT 1
                 ");
                $ksStmt->execute([$regNo, $regNo]);
                if ($ksRow = $ksStmt->fetch()) {
                    $personName = $ksRow['name'];
                    $userRole = 'keynote_speaker';
                }
            }
         // 2. Check Presenter
            if ($pdo_moesnt && (empty($personName) || empty($userRole))) {
                $regStmt = $pdo_moesnt->prepare("SELECT fullname FROM public.iwa2026_registration WHERE registration_no = ? LIMIT 1");
                $regStmt->execute([$regNo]);
                if ($row = $regStmt->fetch()) {
                    if (empty($personName)) $personName = $row['fullname'] ?? '';
                    if (empty($userRole)) $userRole = 'presenter';
                }
            }

            // 3. Check General Users
            if (empty($personName) || empty($userRole)) {
                $uStmt = $pdo_conf->prepare("SELECT full_name, role FROM public.users WHERE registration_no = ? LIMIT 1");
                $uStmt->execute([$regNo]);
                if ($row = $uStmt->fetch()) {
                    if (empty($personName)) $personName = $row['full_name'] ?? '';
                    if (empty($userRole)) $userRole = $row['role'] ?? 'attendee';
                }
            }

            if (empty($personName)) $personName = 'Delegate';
            if (empty($userRole)) $userRole = 'attendee';
        }

        // ---------------------------------------------------------------------
        // ENTRY SCAN LOGIC
        // ---------------------------------------------------------------------
        if ($scanType === 'entry') {
            // Check if delegate already has an active entry in ANY hall without exiting
            $activeStmt = $pdo_conf->prepare("
                SELECT id, hall_name, entry_time 
                FROM public.venue_access_logs 
                WHERE registration_no = ? AND exit_time IS NULL 
                ORDER BY entry_time DESC LIMIT 1
            ");
            $activeStmt->execute([$regNo]);
            $activeSession = $activeStmt->fetch();

            if ($activeSession) {
                http_response_code(409);
                echo json_encode([
                    "success" => false,
                    "error" => "Cannot register entry: {$personName} ({$regNo}) is already inside '{$activeSession['hall_name']}' (Checked in at {$activeSession['entry_time']}). Please record their exit first."
                ]);
                exit();
            }

            // Create new entry row (exit_time remains NULL until exit scan)
            $insertStmt = $pdo_conf->prepare("
                INSERT INTO public.venue_access_logs 
                (hall_name, registration_no, person_name, user_role, entry_time, exit_time, scanned_by) 
                VALUES (?, ?, ?, ?, NOW(), NULL, ?)
                RETURNING id, entry_time
            ");
            $insertStmt->execute([$hallName, $regNo, $personName, $userRole, $scannedBy]);
            $inserted = $insertStmt->fetch();

            echo json_encode([
                "success" => true,
                "message" => "Entry recorded successfully for {$personName} at {$hallName}.",
                "data" => [
                    "id"              => $inserted['id'],
                    "hall_name"       => $hallName,
                    "registration_no" => $regNo,
                    "person_name"     => $personName,
                    "user_role"       => $userRole,
                    "scan_type"       => "entry",
                    "entry_time"      => $inserted['entry_time'],
                    "exit_time"       => null
                ]
            ]);
        } 
        // ---------------------------------------------------------------------
        // EXIT SCAN LOGIC (STRICT RESTRICTION)
        // ---------------------------------------------------------------------
        else {
            // Look for the active entry in this hall (case-insensitive trim)
            $exitSearch = $pdo_conf->prepare("
                SELECT id, hall_name, entry_time 
                FROM public.venue_access_logs 
                WHERE registration_no = ? 
                  AND LOWER(TRIM(hall_name)) = LOWER(TRIM(?)) 
                  AND exit_time IS NULL 
                ORDER BY entry_time DESC 
                LIMIT 1
            ");
            $exitSearch->execute([$regNo, $hallName]);
            $activeSession = $exitSearch->fetch();

            // RESTRICTION: Reject if no active entry exists
            if (!$activeSession) {
                // Check if they accidentally entered a DIFFERENT hall
                $otherHallStmt = $pdo_conf->prepare("
                    SELECT hall_name, entry_time 
                    FROM public.venue_access_logs 
                    WHERE registration_no = ? AND exit_time IS NULL 
                    ORDER BY entry_time DESC LIMIT 1
                ");
                $otherHallStmt->execute([$regNo]);
                $otherHall = $otherHallStmt->fetch();

                http_response_code(400);
                if ($otherHall) {
                    echo json_encode([
                        "success" => false,
                        "error" => "Cannot record exit: No active entry for '{$hallName}'. Delegate is currently checked into '{$otherHall['hall_name']}' since {$otherHall['entry_time']}."
                    ]);
                } else {
                    echo json_encode([
                        "success" => false,
                        "error" => "Cannot record exit: No active entry registered for delegate {$regNo} in {$hallName}. Entry must be scanned first."
                    ]);
                }
                exit();
            }

            // Update the EXACT same row with exit_time
            $updateStmt = $pdo_conf->prepare("
                UPDATE public.venue_access_logs 
                SET exit_time = NOW() 
                WHERE id = ? 
                RETURNING id, entry_time, exit_time
            ");
            $updateStmt->execute([$activeSession['id']]);
            $updated = $updateStmt->fetch();

            echo json_encode([
                "success" => true,
                "message" => "Exit recorded successfully for {$personName} from {$hallName}.",
                "data" => [
                    "id"              => $updated['id'],
                    "hall_name"       => $hallName,
                    "registration_no" => $regNo,
                    "person_name"     => $personName,
                    "user_role"       => $userRole,
                    "scan_type"       => "exit",
                    "entry_time"      => $updated['entry_time'],
                    "exit_time"       => $updated['exit_time']
                ]
            ]);
        }
    } 
    // GET: List logs
    elseif ($method === 'GET') {
        $hallFilter = trim($_GET['hall_name'] ?? '');
        if (!empty($hallFilter)) {
            $stmt = $pdo_conf->prepare("
                SELECT id, hall_name, registration_no, person_name, user_role, entry_time, exit_time, scanned_by 
                FROM public.venue_access_logs 
                WHERE LOWER(TRIM(hall_name)) = LOWER(TRIM(?))
                ORDER BY entry_time DESC 
                LIMIT 100
            ");
            $stmt->execute([$hallFilter]);
        } else {
            $stmt = $pdo_conf->query("
                SELECT id, hall_name, registration_no, person_name, user_role, entry_time, exit_time, scanned_by 
                FROM public.venue_access_logs 
                ORDER BY entry_time DESC 
                LIMIT 100
            ");
        }
        $logs = $stmt->fetchAll();

        echo json_encode([
            "success" => true,
            "count"   => count($logs),
            "data"    => $logs
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