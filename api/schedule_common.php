<?php
// api/schedule_common.php - Standalone File (Fetches All Schedules with Keynote Speakers)

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
$db   = 'moesnt';        // Your database name
$user = 'postgres';
$pass = 'Ramgopal@123';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Fetch ALL schedule rows including keynote_speaker_id, ordered by date and time
    $stmt = $pdo->query("
        SELECT id, conf_date, from_time, to_time, venue, program_name, presenter_id, keynote_speaker_id 
        FROM public.iwa2026_schedule 
        ORDER BY conf_date ASC, from_time ASC
    ");
    $schedule = $stmt->fetchAll();

    echo json_encode([
        "success" => true,
        "count" => count($schedule),
        "data" => $schedule
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database connection or query failed: " . $e->getMessage()
    ]);
}
?>