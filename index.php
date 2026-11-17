<?php
// Clean Standalone Router (index.php)

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
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

$basePath = ''; // Adjust if needed
if ($basePath && strpos($request_uri, $basePath) === 0) {
    $request_uri = substr($request_uri, strlen($basePath));
}

$route = trim(str_replace('/api/', '', $request_uri), '/');

// Strip .php extension if present in URL
if (substr($route, -4) === '.php') {
    $route = substr($route, 0, -4);
}

if (empty($route)) {
    echo json_encode(["message" => "Backend is online in PHP!"]);
    exit();
}

// Automatically route to the physical standalone file inside api/
$file_path = __DIR__ . '/api/' . $route . '.php';

if (file_exists($file_path)) {
    require_once $file_path;
    exit();
}

// If file doesn't exist
http_response_code(404);
echo json_encode(["error" => "API Endpoint not found: /api/" . $route]);
exit();
?>