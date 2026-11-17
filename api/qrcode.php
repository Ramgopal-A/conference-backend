<?php
// api/qrcode.php - Standalone QR Code Generation File (PHP 7.4 Compatible)

ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

while (ob_get_level() > 1) {
    ob_end_clean();
}

$qid = trim($_GET['qid'] ?? $_GET['registration_no'] ?? '');

if (empty($qid)) {
    ob_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["error" => "QR value is empty or missing."]);
    exit;
}

try {
    $options = new QROptions([
        'version'     => QRCode::VERSION_AUTO,
        'outputType'  => QRCode::OUTPUT_MARKUP_SVG,
        'eccLevel'    => QRCode::ECC_Q,
        'scale'       => 10,
        'imageBase64' => false,
    ]);

    $qrcode = new QRCode($options);
    $svgData = trim($qrcode->render($qid));

    ob_clean();
    http_response_code(200);
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo $svgData;
    exit;

} catch (Throwable $ex) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        "error_message" => $ex->getMessage(),
        "file"          => $ex->getFile(),
        "line"          => $ex->getLine()
    ]);
    exit;
}
?>