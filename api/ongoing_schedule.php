<?php
// api/ongoing_schedule.php - Filtered for Ongoing Programs Based on Current Date & Time

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
$db   = 'moesnt';
$user = 'postgres';
$pass = 'Ramgopal@123';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Get current date and time (adjust timezone if needed, e.g., 'Asia/Kolkata')
    date_default_timezone_set('Asia/Kolkata');
    $currentDate = date('Y-m-d'); // Format: 2026-11-26
    $currentTime = date('H:i:s'); // Format: 14:05:00

    // Query to find sessions happening right now on the current conference date
    $stmt = $pdo->prepare("
        SELECT id, conf_date, from_time, to_time, venue, program_name, presenter_id, keynote_speaker_id 
        FROM public.iwa2026_schedule 
        WHERE conf_date = ? 
          AND ?::time BETWEEN from_time AND to_time
        ORDER BY from_time ASC
    ");
    $stmt->execute([$currentDate, $currentTime]);
    $ongoingPrograms = $stmt->fetchAll();

    // Fallback: If no event is strictly happening at this exact minute, 
    // fetch the next upcoming events for today so the UI isn't empty.
    if (empty($ongoingPrograms)) {
        $fallbackStmt = $pdo->prepare("
            SELECT id, conf_date, from_time, to_time, venue, program_name, presenter_id, keynote_speaker_id 
            FROM public.iwa2026_schedule 
            WHERE conf_date = ? 
              AND from_time >= ?::time
            ORDER BY from_time ASC
            LIMIT 3
        ");
        $fallbackStmt->execute([$currentDate, $currentTime]);
        $ongoingPrograms = $fallbackStmt->fetchAll();
    }

    echo json_encode([
        "success" => true,
        "server_date" => $currentDate,
        "server_time" => $currentTime,
        "count" => count($ongoingPrograms),
        "data" => $ongoingPrograms
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database connection or query failed: " . $e->getMessage()
    ]);
}
?>