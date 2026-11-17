<?php
// config/database.php - Production Neon & Local Fallback

// Allow Render environment variables or fall back to your Neon database URLs
$neon_conf_url   = getenv('NEON_CONF_URL') ?: "postgresql://neondb_owner:npg_hOI8oP4EBAbW@ep-calm-water-azwktyt1-pooler.c-3.ap-southeast-1.aws.neon.tech/neondb?sslmode=require";
$neon_moesnt_url = getenv('NEON_MOESNT_URL') ?: "postgresql://neondb_owner:npg_hOI8oP4EBAbW@ep-calm-water-azwktyt1-pooler.c-3.ap-southeast-1.aws.neon.tech/moesnt?sslmode=require";

$pdo_options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // Safer prepared statements
    PDO::ATTR_TIMEOUT            => 10
];

$pdo_conf = null;
$pdo_moesnt = null;

try {
    // 1. Neon App Database ('conference_db' / 'neondb')
    $conf_db  = parse_url($neon_conf_url);
    $dsn_conf = sprintf(
        "pgsql:host=%s;port=%s;dbname=%s;sslmode=require",
        $conf_db["host"],
        $conf_db["port"] ?? 5432,
        ltrim($conf_db["path"], "/")
    );
    $pdo_conf = new PDO($dsn_conf, $conf_db["user"], $conf_db["pass"], $pdo_options);

    // 2. Neon Registration Database ('moesnt')
    $moesnt_db  = parse_url($neon_moesnt_url);
    $dsn_moesnt = sprintf(
        "pgsql:host=%s;port=%s;dbname=%s;sslmode=require",
        $moesnt_db["host"],
        $moesnt_db["port"] ?? 5432,
        ltrim($moesnt_db["path"], "/")
    );
    $pdo_moesnt = new PDO($dsn_moesnt, $moesnt_db["user"], $moesnt_db["pass"], $pdo_options);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => "Neon Database connection failed: " . $e->getMessage()
    ]);
    exit();
}