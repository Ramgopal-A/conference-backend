<?php
// Enable error logging for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../user.php';

    // Verify authentication function exists
    if (!function_exists('authenticate')) {
        throw new Exception("Authentication function not found.");
    }

    $userId = authenticate(); 
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method == 'GET') {
        getUserMe($pdo_conf, $userId);
    } elseif ($method == 'DELETE') {
        deleteUserMe($pdo_conf, $userId);
    } else {
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Server Exception: " . $e->getMessage()
    ]);
}
?>