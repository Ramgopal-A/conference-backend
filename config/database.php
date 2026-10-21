<?php
// ==========================================
// CHOOSE YOUR ENVIRONMENT BY COMMENTING/UNCOMMENTING
// ==========================================

/*
// --- OPTION A: LOCAL DEBUGGING DATABASES ---
$host = "127.0.0.1";
$port = "5432";
$user = "postgres";
$password = ""; // Your local password

try {
    // Local App Database
    $pdo_conf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user,$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Local Registration Database
    $pdo_moesnt = new PDO("pgsql:host=$host;port=$port;dbname=moesnt", $user,$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die(json_encode(["error" => "Local Connection failed: " . $e->getMessage()]));
}
*/


// --- OPTION B: ONLINE NEON DATABASES (PRODUCTION) ---
// Replace the URLs below with your actual Neon connection strings
$neon_conf_url  = "postgresql://neondb_owner:YOUR_PASSWORD@ep-xxx.aws.neon.tech/conference_db?sslmode=require&channel_binding=require";
$neon_moesnt_url = "postgresql://neondb_owner:YOUR_PASSWORD@ep-xxx.aws.neon.tech/moesnt?sslmode=require&channel_binding=require";

try {
    // Neon App Database (`conference_db`)
    $conf_db = parse_url($neon_conf_url);
    $dsn_conf = sprintf("pgsql:host=\%s;port=\%s;dbname=\%s;sslmode=require", $conf_db["host"], $conf_db["port"] ?? 5432, ltrim($conf_db["path"], "/"));
    $pdo_conf = new PDO($dsn_conf, $conf_db["user"], $conf_db["pass"], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Neon Registration Database (`moesnt`)
    $moesnt_db = parse_url($neon_moesnt_url);
    $dsn_moesnt = sprintf("pgsql:host=\%s;port=\%s;dbname=\%s;sslmode=require", $moesnt_db["host"], $moesnt_db["port"] ?? 5432, ltrim($moesnt_db["path"], "/"));
    $pdo_moesnt = new PDO($dsn_moesnt, $moesnt_db["user"], $moesnt_db["pass"], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die(json_encode(["error" => "Neon Connection failed: " . $e->getMessage()]));
}
?>