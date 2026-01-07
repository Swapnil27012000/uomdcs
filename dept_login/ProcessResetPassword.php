<?php

// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php'; // Include database configuration
}

session_start();
error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method.");
}

if (!isset($_POST["token"], $_POST["password"], $_POST["confirm_password"])) {
    die("Missing required fields.");
}

$token = $_POST["token"];
$new_password = $_POST["password"];
$confirm_password = $_POST["confirm_password"];

// Validate password and confirm password match
if ($new_password !== $confirm_password) {
    die("Passwords do not match.");
}

$mysql = $conn; // Ensure $conn is initialized in config.php

// Validate token
$sql = "SELECT * FROM department_master WHERE 
        RESET_PASSWORD = ? AND 
        RESET_TOKEN_EXPIRE > NOW()";
$stmt = $mysql->prepare($sql);

if (!$stmt) {
    // die("Database error: " . $mysql->error);
    die("Database error: " . $conn->error);
}

$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();



######## affter php server need to check ###################
// if (!$user) {
//     die("Invalid or expired password reset token.");
// }

// Hash the new password securely
// $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

// Update the password in the database and clear the reset token and expiry
$update_sql = "UPDATE department_master SET 
               PASS_WORD = ?, 
               RESET_PASSWORD = NULL, 
               RESET_TOKEN_EXPIRE = NULL 
               WHERE EMAIL = ?";
$update_stmt = $mysql->prepare($update_sql);
// $update_stmt -> $conn -> prepare($update_sql);

if (!$update_stmt) {
    die("Database error: " . $mysql->error);
}

$update_stmt->bind_param("ss", $new_password, $user['EMAIL']);

if ($update_stmt->execute()) {
    echo "Password updated successfully.";
    echo "<script>alert('Password reset successfully. You can now log in with your new password.');</script>";
    echo "<script>window.location.href = 'index.php';</script>";
} else {
    die("Failed to update password: " . $update_stmt->error);
}




?>