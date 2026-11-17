<?php
// api/meal_scan.php - Handles meal distribution via QR code scanning with time and role validation

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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

// Authenticate request
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

// RESTRICTION: Only allow Food Distribution staff
$callerRole = strtolower($decoded['role'] ?? '');
$callerDuty = strtolower($decoded['duty'] ?? '');

if ($callerRole !== 'local_organizer' || ($callerDuty !== 'food_distribution' && !empty($callerDuty))) {
    http_response_code(403);
    echo json_encode([
        "success" => false, 
        "error" => "Access denied: Only Food Distribution staff can record meals."
    ]);
    exit();
}

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        $registrationNo = trim($input['registration_no'] ?? $input['user_identifier'] ?? '');
        $mealKey        = strtolower(trim($input['meal_key'] ?? ''));
        $rawRole        = strtolower(trim($input['user_role'] ?? 'attendee'));

        // Standardize role inline
        if (strpos($rawRole, 'visitor') !== false) {
            $userRole = 'visitor';
        } elseif (strpos($rawRole, 'organizer') !== false) {
            $userRole = 'local_organizer';
        } elseif (strpos($rawRole, 'sponsor') !== false || strpos($rawRole, 'exhibitor') !== false) {
            $userRole = 'exhibitor_sponsor';
        } else {
            $userRole = $rawRole;
        }

        if (empty($registrationNo) || empty($mealKey)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "registration_no and meal_key are required."]);
            exit();
        }

        // Allowed meal columns list
        $allowedMeals = ['day1_lunch', 'day1_dinner', 'day2_lunch', 'day2_dinner', 'day3_lunch', 'day3_dinner'];
        if (!in_array($mealKey, $allowedMeals)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Invalid meal key specified. Must be day[1-3]_lunch or day[1-3]_dinner."]);
            exit();
        }

        // Rule 1: Visitors do not get lunch
        if (strpos($mealKey, 'lunch') !== false && $userRole === 'visitor') {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Access denied: Visitors are not permitted to claim lunch."]);
            exit();
        }

        // Rule 2: Time Window Validation
        date_default_timezone_set('Asia/Kolkata');
        $currentTime = time();
        $hourMin = date('Hi', $currentTime);

        $isLunch  = strpos($mealKey, 'lunch') !== false;
        $isDinner = strpos($mealKey, 'dinner') !== false;

        $timeValid = false;
        if ($isLunch) {
            // Lunch: 11:00 AM (1100) to 4:00 PM (1600)
            if ($hourMin >= 1100 && $hourMin <= 1600) {
                $timeValid = true;
            }
        } elseif ($isDinner) {
            // Dinner: 5:30 PM (1730) to 11:00 PM (2300)
            if ($hourMin >= 1730 && $hourMin <= 2300) {
                $timeValid = true;
            }
        }

        if (!$timeValid) {
            http_response_code(400);
            echo json_encode([
                "success" => false, 
                "error" => "Meal distribution closed. Current time is outside allowed hours for " . ($isLunch ? "Lunch (11:00 AM - 4:00 PM)" : "Dinner (5:30 PM - 11:00 PM)") . "."
            ]);
            exit();
        }

        // Check if user record exists in meal_attendance
        $stmt = $pdo->prepare("SELECT * FROM public.meal_attendance WHERE registration_no = ?");
        $stmt->execute([$registrationNo]);
        $record = $stmt->fetch(); 

        $timeCol = $mealKey . '_time';

        if (!$record) {
            $insertStmt = $pdo->prepare("INSERT INTO public.meal_attendance (registration_no, user_role) VALUES (?, ?)");
            $insertStmt->execute([$registrationNo, $userRole]);
        } else {
            // Check if meal was already claimed
            if (!empty($record[$mealKey])) {
                http_response_code(409);
                echo json_encode([
                    "success" => false, 
                    "error" => "Meal already claimed! This QR code has already been used for " . str_replace('_', ' ', $mealKey) . " at " . ($record[$timeCol] ?? 'earlier') . "."
                ]);
                exit();
            }
        }

        // Mark meal as claimed (TRUE) and record timestamp
        $updateStmt = $pdo->prepare("
            UPDATE public.meal_attendance 
            SET $mealKey = TRUE, $timeCol = NOW(), updated_at = NOW()
            WHERE registration_no = ?
        ");
        $updateStmt->execute([$registrationNo]);

        echo json_encode([
            "success" => true,
            "message" => ucfirst(str_replace('_', ' ', $mealKey)) . " successfully claimed for delegate " . $registrationNo
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