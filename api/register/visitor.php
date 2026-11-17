<?php
// api/register/visitor.php - Standalone Visitor Registration Endpoint

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit();
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Database Credentials & Secret Key
$host       = 'localhost';
$port       = '5432';
$db_conf    = 'conference_db';
$user       = 'postgres';
$pass       = 'Ramgopal@123';
$secret_key = 'super_secret_conference_key_2026';

try {
    $pdo_conf = new PDO("pgsql:host=$host;port=$port;dbname=$db_conf", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database connection failed: " . $e->getMessage()]);
    exit();
}

// Native JWT Helpers
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

// Helper to generate v4 UUID in PHP
function generateUuidV4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Parse Request Body
$data = json_decode(file_get_contents("php://input"), true);

registerVisitor($pdo_conf, $data, $secret_key);

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

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email_address']]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "User already exists"]);
            return;
        }

        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);

        // Make sure these columns exist in your conference_db 'users' table!
        $insert = $pdo->prepare("INSERT INTO users (role, full_name, phone, email, password_hash, organization) VALUES (?, ?, ?, ?, ?, ?) RETURNING id, email, full_name, role");
        
        $insert->execute([
            'visitor', 
            $data['full_name'], 
            $data['mobile_number'] ?? '', 
            $data['email_address'], 
            $passwordHash,
            $data['organization'] ?? null
        ]);
        
        $user = $insert->fetch();
        
        // Generate JWT Token (Valid for 7 days)
        $payload = [
            "userId" => $user['id'],
            "role" => $user['role'],
            "email" => $user['email'],
            "exp" => time() + (7 * 24 * 60 * 60)
        ];
        $token = generateJWT($payload, $secret_key);

        http_response_code(201);
        echo json_encode([
            "success" => true, 
            "message" => "Registration successful", 
            "token" => $token, 
            "user" => $user
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Database Insert Failed: " . $e->getMessage()]);
    }
}
?>