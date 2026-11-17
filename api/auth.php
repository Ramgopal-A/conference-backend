<?php
// api/auth.php - Standalone Authentication & Registration File

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

// Database Credentials & Secret Key (Direct / No .env required)
$host       = 'localhost';
$port       = '5432';
$db_conf    = 'conference_db';
$db_moesnt  = 'moesnt';
$user       = 'postgres';
$pass       = 'Ramgopal@123';
$secret_key = 'super_secret_conference_key_2026';

try {
    $pdo_conf = new PDO("pgsql:host=$host;port=$port;dbname=$db_conf", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $pdo_moesnt = new PDO("pgsql:host=$host;port=$port;dbname=$db_moesnt", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database connection failed: " . $e->getMessage()]);
    exit();
}

// Native JWT Helper Functions (Embedded directly to remove external dependencies)
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

// Determine action based on request or input
$data = json_decode(file_get_contents("php://input"), true);
$action = $_GET['action'] ?? $data['action'] ?? 'login';

if ($action === 'register') {
    registerVisitor($pdo_conf, $data, $secret_key);
} else {
    loginUser($pdo_conf, $pdo_moesnt, $data, $secret_key);
}

function registerVisitor($pdo, $data, $secret_key) {
    if (empty($data['full_name']) || strlen($data['full_name']) < 2 || !filter_var($data['email_address'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid name or email format"]);
        return;
    }
    if ($data['password'] !== $data['confirm_password'] || strlen($data['password']) < 6) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Passwords do not match or are too short"]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$data['email_address']]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "User already exists"]);
        return;
    }

    $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);

    $insert = $pdo->prepare("INSERT INTO users (role, category, salutation, full_name, designation, organization, organization_address, phone, email, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id, email, full_name, role");
    
    $insert->execute(['visitor', $data['category'] ?? '', $data['salutation'] ?? '', $data['full_name'], $data['designation'] ?? null, $data['organization'] ?? null, $data['organization_address'] ?? null, $data['mobile_number'] ?? '', $data['email_address'], $passwordHash]);
    
    $user = $insert->fetch();
    
    // Fixed expiration calculation (7 days)
    $payload = [
        "userId" => $user['id'],
        "exp" => time() + (7 * 24 * 60 * 60)
    ];
    $token = generateJWT($payload, $secret_key);

    http_response_code(201);
    echo json_encode(["success" => true, "message" => "Registration successful", "token" => $token, "user" => $user]);
}

function loginUser($pdo_conf, $pdo_moesnt, $data, $secret_key) {
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Email and password are required."]);
        exit();
    }

    $user = null;
    $role = 'user';
    $registration_no = null;
    $fullName = '';
    $userId = null;

    // 1. First, check the main 'users' table ($pdo_conf)
    $stmt = $pdo_conf->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $confUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($confUser) {
        $storedHash = $confUser['password_hash'] ?? $confUser['password'] ?? '';
        if (!password_verify($password, $storedHash)) {
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Invalid email or password."]);
            exit();
        }
        $userId = $confUser['id'];
        $role = $confUser['role'] ?? 'user';
        $registration_no = $confUser['registration_no'] ?? null;
        $fullName = $confUser['full_name'] ?? '';
    } else {
        // 2. If not found in main users table, check moesnt DB tables
        $stmt2 = $pdo_moesnt->prepare("
            SELECT registration_no, fullname, email, password, 'presenter' as role 
            FROM public.iwa2026_registration WHERE email = ?
            UNION ALL
            SELECT registration_no, fullname, email, password, 'presenter' as role 
            FROM public.iwa2026_spregistration WHERE email = ?
        ");
        $stmt2->execute([$email, $email]);
        $presenter = $stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$presenter || !password_verify($password, $presenter['password'])) {
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Invalid email or password."]);
            exit();
        }

        $userId = $presenter['registration_no'];
        $registration_no = $presenter['registration_no'];
        $role = 'presenter';
        $fullName = $presenter['fullname'];
    }

    // 3. Generate JWT Token containing the registration_no (Fixed 7-day expiration math)
    $payload = [
        "userId" => $userId,
        "registration_no" => $registration_no,
        "email" => $email,
        "role" => $role,
        "exp" => time() + (7 * 24 * 60 * 60)
    ];

    $token = generateJWT($payload, $secret_key);

    // 4. Return success response with user details and registration_no
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "token" => $token,
        "user" => [
            "email" => $email,
            "full_name" => $fullName,
            "role" => $role,
            "registration_no" => $registration_no
        ]
    ]);
    exit();
}
?>