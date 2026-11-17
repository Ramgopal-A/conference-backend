<?php
// api/user_schedule.php - Relational Schedule Fetcher

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$registrationNo = isset($_GET['registration_no']) ? trim($_GET['registration_no']) : '';

if (empty($registrationNo)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "Registration number is required."
    ]);
    exit;
}

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

    $isKeynote = (stripos($registrationNo, '/KEY/') !== false);
    $speakerUuid = '';

    // If this is a Keynote Speaker, look up their UUID from conference_db as well
    if ($isKeynote) {
        try {
            $pdoConf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $keyStmt = $pdoConf->prepare("
                SELECT id::text AS id 
                FROM public.keynote_speakers 
                WHERE TRIM(LOWER(registration_no)) = TRIM(LOWER(?)) 
                LIMIT 1
            ");
            $keyStmt->execute([$registrationNo]);
            $keyRow = $keyStmt->fetch();
            if ($keyRow && !empty($keyRow['id'])) {
                $speakerUuid = trim($keyRow['id']);
            }
        } catch (PDOException $e) {
            error_log("Keynote UUID lookup skipped: " . $e->getMessage());
        }
    }

    // 1. Fetch all abstract IDs associated with this registration number (skip for keynote speakers)
    $abstractIds = [];
    if (!$isKeynote) {
        $absStmt = $pdo->prepare("
            SELECT TRIM(abstract_id) AS abstract_id, TRIM(title) AS title, TRIM(presentation_type) AS presentation_type 
            FROM public.iwa2026_abstract 
            WHERE TRIM(LOWER(registration_no)) = TRIM(LOWER(?))
              AND abstract_id IS NOT NULL
        ");
        $absStmt->execute([$registrationNo]);
        $abstracts = $absStmt->fetchAll();
        $abstractIds = array_filter(array_column($abstracts, 'abstract_id'));
    }

    // Extract numerical suffix ONLY for regular presenters/attendees (never for /KEY/ accounts)
    preg_match('/(\d+)$/', $registrationNo, $matches);
    $numSuffix = $isKeynote ? '' : ($matches[1] ?? '');
    $shortNumSuffix = $isKeynote ? '' : ltrim($numSuffix, '0');

    // 2. Fetch the corresponding schedule slots
    $query = "
        SELECT 
            s.id AS schedule_id,
            s.conf_date,
            s.from_time,
            s.to_time,
            s.venue,
            s.program_name,
            s.presenter_id,
            s.keynote_speaker_id,
            COALESCE(a.title, s.program_name) AS presentation_title,
            a.abstract_id,
            a.presentation_type,
            a.author_name,
            a.affiliation
        FROM public.iwa2026_schedule s
        LEFT JOIN public.iwa2026_abstract a 
            ON TRIM(LOWER(s.presenter_id)) = TRIM(LOWER(a.abstract_id))
        WHERE 
            -- Exact abstract match from user's abstract records (non-keynote)
            (
                :is_keynote = 0 AND s.presenter_id IN (
                    SELECT TRIM(abstract_id) 
                    FROM public.iwa2026_abstract 
                    WHERE TRIM(LOWER(registration_no)) = TRIM(LOWER(:reg_no))
                )
            )
            -- Direct registration number or keynote speaker ID/UUID match
            OR TRIM(LOWER(s.presenter_id)) = TRIM(LOWER(:reg_no))
            OR TRIM(LOWER(s.keynote_speaker_id)) = TRIM(LOWER(:reg_no))
            OR (:speaker_uuid != '' AND TRIM(LOWER(s.keynote_speaker_id)) = TRIM(LOWER(:speaker_uuid)))
            
            -- Fallback if the schedule was entered using the registration sequence number (non-keynote only)
            OR (
                :num_suffix != '' AND (
                    s.presenter_id ILIKE '%ABS/' || :num_suffix
                    OR s.presenter_id ILIKE '%ABS/%' || :short_num_suffix
                )
            )
        ORDER BY s.conf_date ASC, s.from_time ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'is_keynote'       => $isKeynote ? 1 : 0,
        'reg_no'           => $registrationNo,
        'speaker_uuid'     => $speakerUuid,
        'num_suffix'       => $numSuffix,
        'short_num_suffix' => $shortNumSuffix
    ]);
    $scheduleData = $stmt->fetchAll();

    echo json_encode([
        "success"         => true,
        "registration_no" => $registrationNo,
        "abstract_ids"    => array_values($abstractIds),
        "count"           => count($scheduleData),
        "data"            => $scheduleData
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database execution failed: " . $e->getMessage()
    ]);
}
?>