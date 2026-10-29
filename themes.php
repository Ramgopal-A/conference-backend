<?php
// api/themes.php - Standalone Local Backend File

// 1. Set Headers & Error Handling
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight OPTIONS request for mobile/web apps
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 2. Local Database Credentials
$host = 'localhost';
$port = '5432';
$db   = 'conference_db';        // Replace with your local database name if different (e.g., moesnt)
$user = 'postgres';        // Replace with your local PostgreSQL username
$pass = 'Ramgopal@123'; // Replace with your local PostgreSQL password





try {
    // 3. Establish local PostgreSQL connection using PDO (no SSL required locally)
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 4. Fetch themes data from the local database
    $stmt = $pdo->query("SELECT * FROM public.iwa2026_themes ORDER BY id ASC");
    $themes = $stmt->fetchAll();

    // 5. Return successful JSON response
    echo json_encode([
        "success" => true,
        "data" => $themes
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Local database connection or query failed: " . $e->getMessage()
    ]);
}
?>