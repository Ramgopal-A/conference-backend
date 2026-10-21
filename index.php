<?php
require_once 'config/database.php';
require_once 'jwt_helper.php';

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"), true);
$secret_key = "super_secret_conference_key_2026"; 

// Authentication Middleware
function authenticate() {
    global $secret_key;
    $headers = function_exists('apache_request_headers') ? apache_request_headers() : getallheaders();
    
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    
    if (!$authHeader) {
        http_response_code(401);
        echo json_encode(["error" => "Access denied. No token provided."]);
        exit();
    }
    
    $token = str_replace("Bearer ", "", $authHeader);
    $decoded = verifyJWT($token, $secret_key);
    
    if (!$decoded) {
        http_response_code(403);
        echo json_encode(["error" => "Invalid or expired token."]);
        exit();
    }
    return $decoded['userId'];
}

// Basic Rate Limiting
session_start();
if (!isset($_SESSION['requests'])) { $_SESSION['requests'] = 0; }
$_SESSION['requests']++;
if ($_SESSION['requests'] > 50) {
    http_response_code(429);
    echo json_encode(["error" => "Too many requests. Slow down."]);
    exit();
}

// Router Switch
switch ($request_uri) {
    case '/api/test':
        echo json_encode(["message" => "Backend is online in PHP!"]);
        break;
    case '/api/register/visitor':
        require_once 'api/auth.php';
        registerVisitor($pdo_conf, $data, $secret_key);
        break;
    case '/api/login':
        require_once 'api/auth.php';
        loginUser($pdo_conf, $data, $secret_key);
        break;
    case '/api/user/me':
        $userId = authenticate();
        require_once 'api/user.php';
        if ($method == 'GET') getUserMe($pdo_conf, $userId);
        if ($method == 'DELETE') deleteUserMe($pdo_conf, $userId);
        break;
    case '/api/themes':
        require_once 'api/data.php';
        getThemes($pdo_conf);
        break;
    case '/api/venues':
        require_once 'api/data.php';
        getVenues($pdo_conf);
        break;
    case '/api/venues/facilities':
        require_once 'api/data.php';
        getVenueFacilities($pdo_conf);
        break;
    case '/api/accommodations':
        require_once 'api/data.php';
        getAccommodations($pdo_conf);
        break;
    case '/api/speakers':
    case '/api/committees':
        require_once 'api/data.php';
        getGenericData($pdo_conf, str_replace('/api/', '', $request_uri));
        break;
    case '/api/fetch_details':
        require_once 'api/fetch_details.php';
        break;
    default:
        http_response_code(404);
        echo json_encode(["error" => "Route not found"]);
        break;
}
?>