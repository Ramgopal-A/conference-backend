<?php
require_once __DIR__ . '/data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit();
}

// Automatically detects table/endpoint name from the filename (e.g., 'speakers' or 'committees')
$endpoint = basename(__FILE__, '.php');
getGenericData($pdo_conf, $endpoint);
?>