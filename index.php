<?php
// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Authentication Middleware Function (available globally to all endpoints)
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

// Parse Request URI to find the correct file
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$basePath = '/IWA2025MobileAPP'; // Change or leave empty '' if running on local root
if ($basePath && strpos($request_uri, $basePath) === 0) {
    $request_uri = substr($request_uri, strlen($basePath));
}

$route = trim(str_replace('/api/', '', $request_uri), '/');

if (empty($route)) {
    echo json_encode(["message" => "Backend is online in PHP!"]);
    exit();
}

// Automatically look for a physical file matching the route inside api/
$file_path = __DIR__ . '/api/' . $route . '.php';

if (file_exists($file_path)) {
    // Load database and JWT helpers before loading the endpoint file
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/jwt_helper.php';
    $secret_key = "super_secret_conference_key_2026";
    
    // Execute the specific endpoint file
    require_once $file_path;
    exit();
}

// If file doesn't exist
http_response_code(404);
echo json_encode(["error" => "API Endpoint not found: /api/" . $route]);
exit();
?>