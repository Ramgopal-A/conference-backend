<?php
// api/chat.php - Production Chat API with Security and Presence Controls

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

$host       = 'localhost';
$port       = '5432';
$db         = 'conference_db'; 
$user       = 'postgres';
$pass       = 'Ramgopal@123';
$secret_key = 'super_secret_conference_key_2026';

// 1. JWT Signature Verification
function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function verifyAndDecodeJWT($jwt, $secret) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;

    list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;

    // Cryptographic signature verification using HMAC-SHA256
    $expectedSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
    $calculatedBase64UrlSignature = rtrim(strtr(base64_encode($expectedSignature), '+/', '-_'), '=');

    if (!hash_equals($calculatedBase64UrlSignature, $base64UrlSignature)) {
        return false; // Signature mismatch / Tampered token
    }

    $payload = json_decode(base64UrlDecode($base64UrlPayload), true);
    if (isset($payload['exp']) && time() > $payload['exp']) {
        return false; // Expired token
    }

    return $payload;
}

// 2. Authentication Middleware
$headers = function_exists('apache_request_headers') ? apache_request_headers() : getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Access denied: Missing Authorization header."]);
    exit();
}

$token = str_replace("Bearer ", "", $authHeader);
$decoded = verifyAndDecodeJWT($token, $secret_key);

if (!$decoded) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Invalid or expired authorization token."]);
    exit();
}

// Extract verified user identification
$currentUserId   = trim($decoded['registration_no'] ?? $decoded['userId'] ?? '');
$currentUserRole = strtolower($decoded['role'] ?? 'attendee');
$currentUserName = trim($decoded['name'] ?? $decoded['email'] ?? 'Delegate');

// Role Restriction: Block Students and Visitors
$restrictedRoles = ['student', 'visitor'];
if (in_array($currentUserRole, $restrictedRoles)) {
    http_response_code(403);
    echo json_encode([
        "success" => false, 
        "error" => "Access denied: Students and Visitors are not permitted to use chat."
    ]);
    exit();
}

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Heartbeat: Update Online Presence
    $presenceStmt = $pdo->prepare("
        INSERT INTO public.user_presence (user_id, user_name, user_role, last_seen, is_online)
        VALUES (?, ?, ?, NOW(), TRUE)
        ON CONFLICT (user_id) 
        DO UPDATE SET last_seen = NOW(), is_online = TRUE, user_name = EXCLUDED.user_name
    ");
    $presenceStmt->execute([$currentUserId, $currentUserName, $currentUserRole]);

    $method = $_SERVER['REQUEST_METHOD'];

    // ---------------------------------------------------------
    // POST: Send Message
    // ---------------------------------------------------------
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);

        $receiverId  = trim($input['receiver_id'] ?? 'broadcast');
        $messageText = trim($input['message'] ?? '');

        if (empty($messageText)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Message body cannot be empty."]);
            exit();
        }

        // Receiver Validation: Prevent sending to self
        if ($receiverId === $currentUserId) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "You cannot send messages to yourself."]);
            exit();
        }

        // Duplicate Message Prevention: Detect identical message within 5 seconds
        $dupStmt = $pdo->prepare("
            SELECT id FROM public.chat_messages 
            WHERE sender_id = ? AND receiver_id = ? AND message = ? 
              AND sent_at >= (NOW() - INTERVAL '5 seconds')
            LIMIT 1
        ");
        $dupStmt->execute([$currentUserId, $receiverId, $messageText]);
        if ($dupStmt->fetch()) {
            http_response_code(429);
            echo json_encode(["success" => false, "error" => "Duplicate message detected. Please wait before retrying."]);
            exit();
        }

        // Insert new message
        $insertStmt = $pdo->prepare("
            INSERT INTO public.chat_messages 
            (sender_id, sender_name, sender_role, receiver_id, message, sent_at, is_read) 
            VALUES (?, ?, ?, ?, ?, NOW(), FALSE)
            RETURNING id, sent_at
        ");
        $insertStmt->execute([$currentUserId, $currentUserName, $currentUserRole, $receiverId, $messageText]);
        $newMessage = $insertStmt->fetch();

        http_response_code(201);
        echo json_encode([
            "success" => true,
            "message" => "Message sent successfully.",
            "data"    => [
                "id"          => $newMessage['id'],
                "sender_id"   => $currentUserId,
                "sender_name" => $currentUserName,
                "receiver_id" => $receiverId,
                "message"     => $messageText,
                "sent_at"     => $newMessage['sent_at']
            ]
        ]);
    }

    // ---------------------------------------------------------
    // GET: Retrieve Messages, Unread Counts, or Online Users
    // ---------------------------------------------------------
    elseif ($method === 'GET') {
        $action = trim($_GET['action'] ?? 'messages');

        // Sub-Action: Online Users List
        if ($action === 'online_users') {
            $usersStmt = $pdo->query("
                SELECT user_id, user_name, user_role, last_seen,
                       (last_seen >= NOW() - INTERVAL '5 minutes') AS is_online
                FROM public.user_presence
                WHERE user_id != '{$currentUserId}'
                ORDER BY is_online DESC, last_seen DESC
            ");
            echo json_encode(["success" => true, "data" => $usersStmt->fetchAll()]);
            exit();
        }

        // Sub-Action: Unread Count Calculation
        if ($action === 'unread_count') {
            $unreadStmt = $pdo->prepare("
                SELECT sender_id, COUNT(*) as unread_count 
                FROM public.chat_messages 
                WHERE receiver_id = ? AND is_read = FALSE 
                GROUP BY sender_id
            ");
            $unreadStmt->execute([$currentUserId]);
            echo json_encode(["success" => true, "data" => $unreadStmt->fetchAll()]);
            exit();
        }

        // Default Action: Fetch Chat Messages
        $withUser = trim($_GET['with_user'] ?? '');

        if (!empty($withUser)) {
            // Private Conversation Filter: Exclusively messages between current user and specified participant
            $stmt = $pdo->prepare("
                SELECT id, sender_id, sender_name, sender_role, receiver_id, message, sent_at, is_read, read_at
                FROM public.chat_messages 
                WHERE (sender_id = ? AND receiver_id = ?) 
                   OR (sender_id = ? AND receiver_id = ?)
                ORDER BY sent_at ASC 
                LIMIT 150
            ");
            $stmt->execute([$currentUserId, $withUser, $withUser, $currentUserId]);
        } else {
            // General / Broadcast Stream: Public broadcasts and messages directed to current user
            $stmt = $pdo->prepare("
                SELECT id, sender_id, sender_name, sender_role, receiver_id, message, sent_at, is_read, read_at
                FROM public.chat_messages 
                WHERE receiver_id = 'broadcast' 
                   OR receiver_id = ? 
                   OR sender_id = ?
                ORDER BY sent_at ASC 
                LIMIT 150
            ");
            $stmt->execute([$currentUserId, $currentUserId]);
        }

        $messages = $stmt->fetchAll();

        echo json_encode([
            "success" => true,
            "count"   => count($messages),
            "data"    => $messages
        ]);
    }

    // ---------------------------------------------------------
    // PUT: Read Receipt Handling
    // ---------------------------------------------------------
    elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents("php://input"), true);
        $senderId = trim($input['sender_id'] ?? '');

        if (empty($senderId)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "sender_id is required to mark messages as read."]);
            exit();
        }

        // Mark all unread messages from this sender as read
        $markStmt = $pdo->prepare("
            UPDATE public.chat_messages 
            SET is_read = TRUE, read_at = NOW() 
            WHERE sender_id = ? AND receiver_id = ? AND is_read = FALSE
        ");
        $markStmt->execute([$senderId, $currentUserId]);

        echo json_encode([
            "success" => true,
            "message" => "Messages marked as read."
        ]);
    } 
    else {
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed."]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
}
?>