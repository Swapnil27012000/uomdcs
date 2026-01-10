<?php
// verification/session.php - Verification Committee session verification
require_once(__DIR__ . '/../session_verification.php');

// Verify user has Verification Committee access
$userData = verifyUserSession('verification');

if (!$userData) {
    exit; // verifyUserSession handles redirect
}

// Extract user information
$userInfo = $userData['user_info'];
$permission = $userData['permission'];
$table = $userData['table'];
$email = $userData['email'];

// Additional Verification Committee specific checks
if (!hasPermission('verification')) {
    redirectToLogin("Access denied. Verification Committee permission required.");
}

// Set Verification Committee specific session variables
$_SESSION['current_role'] = 'verification';
$_SESSION['user_table'] = $table;
$_SESSION['user_permission'] = $permission;
// CRITICAL FIX: Set admin_username for dashboard compatibility
// $_SESSION['admin_username'] = $email;
// // Ensure verification session is set
// $_SESSION['verification'] = true;

// Log access for security
error_log("Verification Committee access: " . $email . " from " . $table . " table");

// Error logging disabled
?>
