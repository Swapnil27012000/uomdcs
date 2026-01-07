<?php
// logout.php - Universal logout script
session_start();

// Include session verification to access getUserRole function
require_once('session_verification.php');

// Log logout activity
if (isset($_SESSION['admin_username'])) {
    $email = $_SESSION['admin_username'];
    $role = getUserRole();
    error_log("User logout: " . $email . " (Role: " . $role . ")");
}

// Destroy all session data
session_unset();
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Get base URL from config
require_once('config.php');
$baseURL = isset($_ENV['BASE_URL']) ? rtrim($_ENV['BASE_URL'], '/') : '';

if (empty($baseURL)) {
    // Fallback: construct base URL from server variables
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $baseURL = $protocol . $host;
    
    // If the application is in a subdirectory, add it to the base URL
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    if ($scriptDir !== '/' && $scriptDir !== '\\') {
        $baseURL .= str_replace('\\', '/', $scriptDir);
    }
}

// Remove any trailing slashes and add the login page path
$loginURL = rtrim($baseURL, '/') . '/unified_login.php';

// Clear any output buffers before redirect
while (ob_get_level()) {
    ob_end_clean();
}

// Redirect to login page with absolute URL
header('Location: ' . $loginURL);
exit;
?>