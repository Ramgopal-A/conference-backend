<?php
// api/themes.php - Standalone File with Nested Subthemes and Contents

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

// Database Credentials (Direct / No .env required)
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

    // Fetch all three related tables
    $themes = $pdo->query("SELECT * FROM tbl_theme")->fetchAll();
    $subthemes = $pdo->query("SELECT * FROM tbl_theme_subtheme ORDER BY display_order ASC")->fetchAll();
    $contents = $pdo->query("SELECT * FROM tbl_theme_subtheme_content ORDER BY display_order ASC")->fetchAll();

    $result = [];
    foreach ($themes as $theme) {
        $themeObj = [
            "theme_id" => $theme['theme_id'],
            "theme_name" => $theme['theme_name'],
            "theme_desc" => $theme['theme_desc'],
            "subthemes" => []
        ];

        foreach ($subthemes as $sub) {
            if ($sub['theme_id'] == $theme['theme_id']) {
                $subObj = [
                    "subtheme_id" => $sub['subtheme_id'],
                    "sub_theme_name" => $sub['sub_theme_name'],
                    "contents" => array_values(array_filter($contents, function($c) use ($sub) {
                        return $c['subtheme_id'] == $sub['subtheme_id'];
                    }))
                ];
                $themeObj['subthemes'][] = $subObj;
            }
        }
        $result[] = $themeObj;
    }

    // Return successful structured JSON response
    echo json_encode([
        "success" => true,
        "data" => $result
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database connection or query failed: " . $e->getMessage()
    ]);
}
?>