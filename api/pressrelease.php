<?php
// api/pressrelease.php - Dynamic Folder-Based Press Release API (PHP 7.4 Compatible)

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

$pressDir = realpath(__DIR__ . '/../pressrelease');

if (!$pressDir || !is_dir($pressDir)) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "error"   => "Press release directory not found on server."
    ]);
    exit;
}

// Build base public URL dynamically (e.g., https://niot.res.in/IWA2026MobileApp/pressrelease)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

$scheme   = $isHttps ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'niot.res.in';
$apiPath  = dirname($_SERVER['SCRIPT_NAME'] ?? '/IWA2026MobileApp/api/pressrelease.php');
$rootPath = rtrim(dirname($apiPath), '/\\');
$baseUrl  = $scheme . '://' . $host . $rootPath . '/pressrelease';

// Preferred chronological display order
$preferredOrder = [
    'day1' => 'Day 1',
    'day2' => 'Day 2',
    'day3' => 'Day 3',
];

function formatDayTitle($folderName, $preferredOrder) {
    $key = strtolower(str_replace(['_', '-', ' '], '', $folderName));
    if (isset($preferredOrder[$key])) {
        return $preferredOrder[$key];
    }
    $label = preg_replace('/^day[\s_-]*(\d+)/i', 'Day $1', $folderName);
    return ucwords(str_replace(['_', '-'], ' ', $label));
}

function formatBytes($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

$allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
$imageExtensions   = ['jpg', 'jpeg', 'png', 'webp'];
$detectedFolders   = [];

foreach (scandir($pressDir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (is_dir($pressDir . DIRECTORY_SEPARATOR . $entry)) {
        $detectedFolders[] = $entry;
    }
}

// Sort Day 1, Day 2, Day 3 chronologically
$orderedKeys = array_keys($preferredOrder);
usort($detectedFolders, function ($a, $b) use ($orderedKeys) {
    $normA = strtolower(str_replace(['_', '-', ' '], '', $a));
    $normB = strtolower(str_replace(['_', '-', ' '], '', $b));
    $posA  = array_search($normA, $orderedKeys, true);
    $posB  = array_search($normB, $orderedKeys, true);
    if ($posA !== false && $posB !== false) return $posA <=> $posB;
    if ($posA !== false) return -1;
    if ($posB !== false) return 1;
    return strnatcasecmp($a, $b);
});

// Optional filter: ?day=day1 or ?category=day1
$filterCategory = trim($_GET['day'] ?? $_GET['category'] ?? $_GET['folder'] ?? '');
$normFilter     = strtolower(str_replace(['_', '-', ' '], '', $filterCategory));

$categories = [];
$allItems   = [];

foreach ($detectedFolders as $folder) {
    $normFolder = strtolower(str_replace(['_', '-', ' '], '', $folder));
    if ($normFilter !== '' && $normFilter !== $normFolder) {
        continue;
    }

    $folderPath   = $pressDir . DIRECTORY_SEPARATOR . $folder;
    $categoryName = formatDayTitle($folder, $preferredOrder);
    $folderItems  = [];

    $files = scandir($folderPath);
    natcasesort($files);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fullFilePath = $folderPath . DIRECTORY_SEPARATOR . $file;
        if (!is_file($fullFilePath)) continue;

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) continue;

        $fileUrl   = $baseUrl . '/' . rawurlencode($folder) . '/' . rawurlencode($file);
        $cleanName = ucwords(str_replace(['_', '-'], ' ', pathinfo($file, PATHINFO_FILENAME)));
        $fileType  = in_array($ext, $imageExtensions, true) ? 'image' : ($ext === 'pdf' ? 'pdf' : 'document');
        $fileSize  = filesize($fullFilePath);

        $item = [
            'id'            => md5($folder . '/' . $file),
            'category'      => $folder,
            'category_name' => $categoryName,
            'title'         => $cleanName,
            'file_name'     => $file,
            'file_type'     => $fileType,
            'extension'     => $ext,
            'file_size'     => formatBytes($fileSize),
            'file_url'      => $fileUrl,
            'updated_at'    => date('Y-m-d H:i:s', filemtime($fullFilePath)),
        ];

        $folderItems[] = $item;
        $allItems[]    = $item;
    }

    $categories[] = [
        'category'      => $folder,
        'category_name' => $categoryName,
        'count'         => count($folderItems),
        'items'         => $folderItems,
    ];
}

echo json_encode([
    'success'     => true,
    'total_items' => count($allItems),
    'categories'  => $categories,
    'data'        => $allItems,
], JSON_UNESCAPED_SLASHES);