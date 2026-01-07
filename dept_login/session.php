<?php
// dept_login/session.php - Department session verification
require('../session_verification.php');

// Verify user has Department access (department_master users with 'user' permission)
$userData = verifyUserSession('user'); // Changed from 'department' to 'user'

if (!$userData) {
    exit; // verifyUserSession handles redirect
}

// Extract user information
$userInfo = $userData['user_info'];
$permission = $userData['permission'];
$table = $userData['table'];
$email = $userData['email'];

// Additional Department specific checks
if (!hasPermission('department')) {
    redirectToLogin("Access denied. Department permission required.");
}

// Set Department specific session variables
$_SESSION['current_role'] = 'department';
$_SESSION['user_table'] = $table;
$_SESSION['user_permission'] = $permission;
// CRITICAL FIX: Set admin_username for dashboard compatibility
$_SESSION['admin_username'] = $email;
// Ensure dept_login session is set
$_SESSION['dept_login'] = true;
if (isset($userInfo['DEPT_ID'])) {
    $_SESSION['dept_id'] = $userInfo['DEPT_ID'];
}

// Log access for security
// Error logging disabled
?>
