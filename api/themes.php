<?php
// Uses your existing data.php functions
require_once __DIR__ . '/data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit();
}

// Calls your existing function from data.php
getThemes($pdo_conf);
?>