<?php
ob_start();
require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// Clear any accidental prior output buffers
while (ob_get_level() > 1) {
    ob_end_clean();
}

$qid = $_GET['qid'] ?? $_GET['registration_no'] ?? '';

if (empty($qid)) {
    ob_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["error" => "QR value is empty or missing."]);
    exit;
}

try {
    $options = new QROptions([
        'version'      => -1,
        'eccLevel'     => 'Q',
        'scale'        => 10,
        'outputBase64' => false, // Crucial for v6: prevents base64 wrapping and returns raw SVG markup
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
        "file" => $ex->getFile(),
        "line" => $ex->getLine()
    ]);
    exit;
}
?>