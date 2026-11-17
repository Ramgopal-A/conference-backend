<?php
// api/venues.php - Standalone File

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Database Credentials
$host = 'localhost';
$port = '5432';
$db   = 'conference_db';
$user = 'postgres';
$pass = 'Ramgopal@123';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $stmt = $pdo->query("SELECT * FROM public.venues");
    $venues = $stmt->fetchAll();

    echo json_encode([
        "success" => true,
        "data" => $venues
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database connection or query failed: " . $e->getMessage()
    ]);
}
?>