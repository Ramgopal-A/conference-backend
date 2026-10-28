<?php
/*
// ==========================================
// CHOOSE YOUR ENVIRONMENT BY COMMENTING/UNCOMMENTING
// ==========================================


$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '5432';
$user = getenv('DB_USER') ?: 'postgres';
$pass = getenv('DB_PASSWORD') ?: '';

try {
    // 1. Connection for conference_db
    $db_conf = getenv('DB_NAME_CONF') ?: 'conference_db';
    $pdo_conf = new PDO("pgsql:host=$host;port=$port;dbname=$db_conf;", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 2. Connection for moesnt database
    $db_moesnt = getenv('DB_NAME_MOESNT') ?: 'moesnt';
    $pdo_moesnt = new PDO("pgsql:host=$host;port=$port;dbname=$db_moesnt;", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}
*/

// ==========================================
// ONLINE NEON DATABASES (PRODUCTION & LOCAL)
// ==========================================

$neon_conf_url  = "postgresql://neondb_owner:npg_RGyQ0MKTXvZ7@ep-calm-water-azwktyt1-pooler.c-3.ap-southeast-1.aws.neon.tech/neondb?sslmode=require";
$neon_moesnt_url = "postgresql://neondb_owner:npg_RGyQ0MKTXvZ7@ep-calm-water-azwktyt1-pooler.c-3.ap-southeast-1.aws.neon.tech/moesnt?sslmode=require";

try {
    // Neon App Database (`conference_db`)
    $conf_db = parse_url($neon_conf_url);
    $dsn_conf = sprintf("pgsql:host=%s;port=%s;dbname=%s;sslmode=require", $conf_db["host"], $conf_db["port"] ?? 5432, ltrim($conf_db["path"], "/"));
    $pdo_conf = new PDO($dsn_conf, $conf_db["user"], $conf_db["pass"], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true
    ]);

    // Neon Registration Database (`moesnt`)
    $moesnt_db = parse_url($neon_moesnt_url);
    $dsn_moesnt = sprintf("pgsql:host=%s;port=%s;dbname=%s;sslmode=require", $moesnt_db["host"], $moesnt_db["port"] ?? 5432, ltrim($moesnt_db["path"], "/"));
    $pdo_moesnt = new PDO($dsn_moesnt, $moesnt_db["user"], $moesnt_db["pass"], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true
    ]);
} catch (PDOException $e) {
    die(json_encode(["error" => "Neon Connection failed: " . $e->getMessage()]));
}
    
?>