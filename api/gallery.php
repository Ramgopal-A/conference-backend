<?php
// api/gallery.php - Dynamic Folder-Based Conference Gallery API (PHP 7.4 Compatible)

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, HEAD, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit;
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$galleryDir = realpath(__DIR__ . '/../gallery');

if (!$galleryDir || !is_dir($galleryDir)) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "error"   => "Gallery directory not found on server."
    ]);
    exit;
}

// Build base public URL dynamically (e.g., https://niot.res.in/IWA2026MobileApp/gallery)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

$scheme   = $isHttps ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'niot.res.in';
$apiPath  = dirname($_SERVER['SCRIPT_NAME'] ?? '/IWA2026MobileApp/api/gallery.php'); // .../api
$rootPath = rtrim(dirname($apiPath), '/\\'); // .../IWA2026MobileApp
$baseUrl  = $scheme . '://' . $host . $rootPath . '/gallery';

// Preferred chronological display order for session folders
$preferredOrder = [
    'day1_inauguration' => 'Day 1 - Inauguration',
    'day1_forenoon'     => 'Day 1 - Forenoon',
    'day1_afternoon'    => 'Day 1 - Afternoon',
    'day2_forenoon'     => 'Day 2 - Forenoon',
    'day2_afternoon'    => 'Day 2 - Afternoon',
    'day3_forenoon'     => 'Day 3 - Forenoon',
    'day3_afternoon'    => 'Day 3 - Afternoon',
];

function formatFolderTitle($folderName, $preferredOrder) {
    if (isset($preferredOrder[$folderName])) {
        return $preferredOrder[$folderName];
    }
    $label = preg_replace('/^day(\d+)_/i', 'Day $1 - ', $folderName);
    return ucwords(str_replace(['_', '-'], ' ', $label));
}

$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$detectedFolders   = [];

foreach (scandir($galleryDir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (is_dir($galleryDir . DIRECTORY_SEPARATOR . $entry)) {
        $detectedFolders[] = $entry;
    }
}

// Sort known session folders chronologically, then any extra folders alphabetically
$orderedKeys = array_keys($preferredOrder);
usort($detectedFolders, function ($a, $b) use ($orderedKeys) {
    $posA = array_search($a, $orderedKeys, true);
    $posB = array_search($b, $orderedKeys, true);
    if ($posA !== false && $posB !== false) return $posA <=> $posB;
    if ($posA !== false) return -1;
    if ($posB !== false) return 1;
    return strcasecmp($a, $b);
});

// Optional filter: ?category=day1_inauguration
$filterCategory = trim($_GET['category'] ?? $_GET['folder'] ?? '');

$categories = [];
$allImages  = [];

foreach ($detectedFolders as $folder) {
    if ($filterCategory !== '' && strcasecmp($filterCategory, $folder) !== 0) {
        continue;
    }

    $folderPath   = $galleryDir . DIRECTORY_SEPARATOR . $folder;
    $categoryName = formatFolderTitle($folder, $preferredOrder);
    $folderImages = [];

    $files = scandir($folderPath);
    natcasesort($files);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fullFilePath = $folderPath . DIRECTORY_SEPARATOR . $file;
        if (!is_file($fullFilePath)) continue;

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) continue;

        $imageUrl  = $baseUrl . '/' . rawurlencode($folder) . '/' . rawurlencode($file);
        $cleanName = ucwords(str_replace(['_', '-'], ' ', pathinfo($file, PATHINFO_FILENAME)));

        $item = [
            'id'            => md5($folder . '/' . $file),
            'category'      => $folder,
            'category_name' => $categoryName,
            'title'         => $cleanName,
            'file_name'     => $file,
            'image_url'     => $imageUrl,
        ];

        $folderImages[] = $item;
        $allImages[]    = $item;
    }

    $categories[] = [
        'category'      => $folder,
        'category_name' => $categoryName,
        'count'         => count($folderImages),
        'images'        => $folderImages,
    ];
}

echo json_encode([
    'success'      => true,
    'total_images' => count($allImages),
    'categories'   => $categories,
    'data'         => $allImages,
], JSON_UNESCAPED_SLASHES);