<?php
// Include our custom JWT helper
require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/../config/database.php';

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

function loginUser($pdo_conf, $data, $secret_key) {
    global $pdo_moesnt; // Access the moesnt database connection

    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(["error" => "Email and password are required."]);
        exit();
    }

    $user = null;
    $role = 'user';
    $registration_no = null;
    $fullName = '';

    // 1. First, check the main 'users' table ($pdo_conf)
    $stmt = $pdo_conf->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $confUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($confUser) {
        $storedHash = $confUser['password_hash'] ?? $confUser['password'] ?? '';
        if (!password_verify($password, $storedHash)) {
            http_response_code(401);
            echo json_encode(["error" => "Invalid email or password."]);
            exit();
        }
        $userId = $confUser['id'];
        $role = $confUser['role'] ?? 'user';
        $registration_no = $confUser['registration_no'] ?? null;
        $fullName = $confUser['full_name'] ?? '';
    } else {
        // 2. If not found in main users table, check moesnt DB tables (Presenters / Registrants)
        $stmt2 = $pdo_moesnt->prepare("
            SELECT registration_no, fullname, email, password, 'presenter' as role 
            FROM public.iwa2026_registration WHERE email = ?
            UNION ALL
            SELECT registration_no, fullname, email, password, 'presenter' as role 
            FROM public.iwa2026_spregistration WHERE email = ?
        ");
        $stmt2->execute([$email, $email]);
        $presenter = $stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$presenter || !password_verify($password, $presenter['password'])) {
            http_response_code(401);
            echo json_encode(["error" => "Invalid email or password."]);
            exit();
        }

        $userId = $presenter['registration_no'];
        $registration_no = $presenter['registration_no'];
        $role = 'presenter';
        $fullName = $presenter['fullname'];
    }

    // 3. Generate JWT Token containing the registration_no
    $payload = [
        "userId" => $userId,
        "registration_no" => $registration_no,
        "email" => $email,
        "role" => $role,
        "exp" => time() + (86400 * 7) // Valid for 7 days
    ];

    $token = generateJWT($payload, $secret_key);

    // 4. Return success response with user details and registration_no
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "token" => $token,
        "user" => [
            "email" => $email,
            "full_name" => $fullName,
            "role" => $role,
            "registration_no" => $registration_no
        ]
    ]);
    exit();
}
?>