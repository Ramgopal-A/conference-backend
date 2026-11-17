<?php
// api/get_chat_contacts.php - Fetch eligible chat contacts grouped by role

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

// Current user's registration/user ID to exclude from their own list
$currentUserId = isset($_GET['current_user_id']) ? trim($_GET['current_user_id']) : '';

$host = 'localhost';
$port = '5432';
$user = 'postgres';
$pass = 'Ramgopal@123';

$contacts = [];

// 1. Connect to moesnt (Presenters & Sponsors)
try {
    $pdo_moesnt = new PDO("pgsql:host=$host;port=$port;dbname=moesnt", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Presenters / Registered Attendees
    $stmtPres = $pdo_moesnt->prepare("
        SELECT 
            registration_no AS user_id, 
            fullname AS name, 
            'Presenter' AS role,
            participant_category,
            status
        FROM public.iwa2026_registration 
        WHERE registration_no IS NOT NULL 
          AND TRIM(registration_no) != ''
          AND TRIM(LOWER(registration_no)) != TRIM(LOWER(:curr_id))
        ORDER BY fullname ASC
    ");
    $stmtPres->execute(['curr_id' => $currentUserId]);
    $presenters = $stmtPres->fetchAll();
    foreach ($presenters as $p) {
        $contacts[] = $p;
    }

    // Exhibitors / Sponsors
    $stmtSp = $pdo_moesnt->prepare("
        SELECT 
            registration_no AS user_id, 
            COALESCE(fullname, name) AS name, 
            'Exhibitor / Sponsor' AS role,
            'Sponsor' AS participant_category,
            'Active' AS status
        FROM public.iwa2026_spregistration 
        WHERE registration_no IS NOT NULL 
          AND TRIM(registration_no) != ''
          AND TRIM(LOWER(registration_no)) != TRIM(LOWER(:curr_id))
        ORDER BY name ASC
    ");
    $stmtSp->execute(['curr_id' => $currentUserId]);
    $sponsors = $stmtSp->fetchAll();
    foreach ($sponsors as $s) {
        $contacts[] = $s;
    }

} catch (PDOException $e) {
    error_log("moesnt contacts query failed: " . $e->getMessage());
}

// 2. Connect to conference_db (Keynote Speakers & Local Organizers)
try {
    $pdo_conf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Keynote Speakers
    $stmtKey = $pdo_conf->prepare("
        SELECT 
            COALESCE(registration_no, id::text) AS user_id, 
            name, 
            'Keynote Speaker' AS role,
            'Dignitary' AS participant_category,
            'Speaker' AS status
        FROM public.keynote_speakers 
        WHERE TRIM(LOWER(COALESCE(registration_no, id::text))) != TRIM(LOWER(:curr_id))
        ORDER BY name ASC
    ");
    $stmtKey->execute(['curr_id' => $currentUserId]);
    $speakers = $stmtKey->fetchAll();
    foreach ($speakers as $spk) {
        $contacts[] = $spk;
    }

    // Local Organizers
    $stmtOrg = $pdo_conf->prepare("
        SELECT 
            id::text AS user_id, 
            full_name AS name, 
            'Local Organizer' AS role,
            COALESCE(duty, 'Organizer') AS participant_category,
            'Staff' AS status
        FROM public.local_organizers
        WHERE TRIM(LOWER(id::text)) != TRIM(LOWER(:curr_id))
        ORDER BY full_name ASC
    ");
    $stmtOrg->execute(['curr_id' => $currentUserId]);
    $organizers = $stmtOrg->fetchAll();
    foreach ($organizers as $org) {
        $contacts[] = $org;
    }

} catch (PDOException $e) {
    error_log("conference_db contacts query failed: " . $e->getMessage());
}

// Extract unique roles for dropdown
$roles = array_values(array_unique(array_column($contacts, 'role')));

echo json_encode([
    "success" => true,
    "roles"   => $roles,
    "count"   => count($contacts),
    "users"   => $contacts
]);
?>