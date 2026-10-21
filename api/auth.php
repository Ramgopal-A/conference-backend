<?php
// Include our custom JWT helper
require_once __DIR__ . '/../jwt_helper.php';

function registerVisitor($pdo, $data, $secret_key) {
    if (strlen($data['full_name']) < 2 || !filter_var($data['email_address'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid name or email format"]);
        return;
    }
    if ($data['password'] !== $data['confirm_password'] || strlen($data['password']) < 6) {
        http_response_code(400);
        echo json_encode(["error" => "Passwords do not match or are too short"]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$data['email_address']]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(["error" => "User already exists"]);
        return;
    }

    $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);

    $insert = $pdo->prepare("INSERT INTO users (role, category, salutation, full_name, designation, organization, organization_address, phone, email, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id, email, full_name, role");
    
    $insert->execute(['visitor', $data['category'], $data['salutation'], $data['full_name'], $data['designation'] ?? null, $data['organization'] ?? null, $data['organization_address'] ?? null, $data['mobile_number'], $data['email_address'], $passwordHash]);
    
    $user = $insert->fetch();
    
    // Create JWT using your native helper function
    $payload = ["userId" => $user['id'], "exp" => time() + (7 * 24 * 60 * 60)];
    $token = generateJWT($payload, $secret_key);

    http_response_code(201);
    echo json_encode(["message" => "Registration successful", "token" => $token, "user" => $user]);
}

function loginUser($pdo, $data, $secret_key) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$data['email']]);
    $user = $stmt->fetch();

    if ($user && password_verify($data['password'], $user['password_hash'])) {
        unset($user['password_hash']);
        
        // Create JWT using your native helper function
        $payload = ["userId" => $user['id'], "exp" => time() + (7 * 24 * 60 * 60)];
        $token = generateJWT($payload, $secret_key);
        
        echo json_encode(["token" => $token, "user" => $user]);
    } else {
        http_response_code(401);
        echo json_encode(["error" => "Invalid email or password"]);
    }
}
?>