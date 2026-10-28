<?php
// Uses your existing auth.php functions
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
$secret_key = "super_secret_conference_key_2026";

// Calls your existing function from auth.php
loginUser($pdo_conf, $data, $secret_key);
?>