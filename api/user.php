<?php
function getUserMe($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT id, email, full_name, phone, organization, designation, country, role, category, salutation, organization_address FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(["error" => "User not found"]);
    } else {
        echo json_encode($user);
    }
}

function deleteUserMe($pdo, $userId) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? RETURNING id");
    $stmt->execute([$userId]);
    if ($stmt->rowCount() == 0) {
        http_response_code(404);
        echo json_encode(["error" => "User not found"]);
    } else {
        echo json_encode(["message" => "Account permanently deleted."]);
    }
}
?>