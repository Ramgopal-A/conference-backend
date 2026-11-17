<?php
// api/generic_data.php - Standalone File

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit();
}

$host = 'localhost';
$port = '5432';
$db   = 'conference_db';
$user = 'postgres';
$pass = 'Ramgopal@123';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $endpoint = basename(__FILE__, '.php');
    $allowed_tables = ['speakers' => 'keynote_speakers', 'committees' => 'committees'];
    
    if (array_key_exists($endpoint, $allowed_tables)) {
        $dbTable = $allowed_tables[$endpoint];
        $data = $pdo->query("SELECT * FROM $dbTable")->fetchAll();
        echo json_encode($data);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Endpoint not found"]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection or query failed: " . $e->getMessage()]);
}
?>