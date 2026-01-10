<?php
// session_verification.php - Universal session verification for all user types
// CRITICAL: Set proper security headers (not X-XSS-Protection: 0)
if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    $config_path = __DIR__ . '/config.php';
    if (file_exists($config_path)) {
        require_once($config_path);
    } else {
        // Try relative path as fallback
        require_once('config.php');
    }
}

// Prevent function redeclaration
if (!function_exists('verifyUserSession')) {
// Function to get the absolute base URL
function getBaseURL() {
    global $conn;
    
    // Try to get from environment variable first
    if (isset($_ENV['BASE_URL'])) {
        return rtrim($_ENV['BASE_URL'], '/');
    }
    
    // Fallback: construct from server variables
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $baseURL = $protocol . $host;
    
    // If the application is in a subdirectory, add it
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    if ($scriptDir !== '/' && $scriptDir !== '\\') {
        $baseURL .= str_replace('\\', '/', $scriptDir);
    }
    
    return rtrim($baseURL, '/');
}

// Session verification function for all user types
function verifyUserSession($required_permission = null) {
    global $conn;
    
    // Check database connection first
    if (!$conn || !mysqli_ping($conn)) {
        error_log("Database connection lost during session verification");
        redirectToLogin("Database connection error. Please try again.");
        return false;
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['admin_username'])) {
        redirectToLogin();
        return false;
    }
    
    $email = $_SESSION['admin_username'];
    $userInfo = null;
    $table = null;
    
    // Check boss table first (contains all supported user types)
    $stmt = $conn->prepare("SELECT * FROM boss WHERE EMAIL = ?");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $userInfo = $res->fetch_assoc();
            $table = 'boss';
        }
        // CRITICAL: Free result set before closing statement
        if ($res) {
            mysqli_free_result($res);
        }
        $stmt->close();
    }
    
    // Check department_master table if not found in boss (all have 'user' permission)
    if (!$userInfo) {
        $stmt = $conn->prepare("SELECT * FROM department_master WHERE EMAIL = ?");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $userInfo = $res->fetch_assoc();
                $table = 'department_master';
                // Force permission to 'user' for department_master users
                $userInfo['PERMISSION'] = 'user';
            }
            // CRITICAL: Free result set before closing statement
            if ($res) {
                mysqli_free_result($res);
            }
            $stmt->close();
        }
    }
    
    // If user not found in either table, logout
    if (!$userInfo) {
        session_destroy();
        redirectToLogin();
        return false;
    }
    
    $permission = strtolower(trim($userInfo['PERMISSION'] ?? ''));
    
    // If specific permission required, check it
    if ($required_permission) {
        $required_permission = strtolower(trim($required_permission));
        
        // Map permission requirements to session checks
        $permission_map = [
            'admin' => ['admin'],
            'department' => ['dept_login'],
            'user' => ['dept_login'], // department_master users are treated as department users
            'aaqa' => ['AAQA'],
            'muibeas' => ['MUIBEAS'],
            'expert' => ['Expert_comty_login'],
            'chairman' => ['Chairman_login'],
            'verification_committee' => ['verification_committee']
        ];
        
        $required_session = $permission_map[$required_permission] ?? null;
        
        if ($required_session) {
            $has_access = false;
            foreach ($required_session as $session_var) {
                if (isset($_SESSION[$session_var])) {
                    $has_access = true;
                    break;
                }
            }
            
            if (!$has_access) {
                redirectToLogin("Access denied. Insufficient permissions.");
                return false;
            }
        }
    }
    
    return [
        'user_info' => $userInfo,
        'permission' => $permission,
        'table' => $table,
        'email' => $email
    ];
}
}

// Redirect to login function
if (!function_exists('redirectToLogin')) {
function redirectToLogin($error_message = null) {
    if ($error_message) {
        $_SESSION['login_error'] = $error_message;
    }
    
    // Get absolute URL for login page
    $loginURL = getBaseURL() . '/unified_login.php';
    
    // Clear any output buffers before redirect
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Location: ' . $loginURL);
    exit();
}
}

// Check if user has specific permission
if (!function_exists('hasPermission')) {
function hasPermission($permission) {
    $permission = strtolower(trim($permission));
    
    switch ($permission) {
        case 'admin':
            return isset($_SESSION['admin']);
        case 'department':
            return isset($_SESSION['dept_login']);
        case 'aaqa':
            return isset($_SESSION['AAQA']);
        case 'muibeas':
            return isset($_SESSION['MUIBEAS']);
        case 'expert':
            return isset($_SESSION['Expert_comty_login']);
        case 'chairman':
            return isset($_SESSION['Chairman_login']);
        case 'verification_committee':
            return isset($_SESSION['verification_committee']);
        default:
            return false;
    }
}
}

// Get user's current role
if (!function_exists('getUserRole')) {
function getUserRole() {
    if (isset($_SESSION['admin'])) return 'admin';
    if (isset($_SESSION['dept_login'])) return 'department';
    if (isset($_SESSION['AAQA'])) return 'aaqa';
    if (isset($_SESSION['MUIBEAS'])) return 'muibeas';
    if (isset($_SESSION['Expert_comty_login'])) return 'expert';
    if (isset($_SESSION['Chairman_login'])) return 'chairman';
    if (isset($_SESSION['verification_committee'])) return 'verification_committee';
    return 'user';
}
}

// Get user's dashboard URL
if (!function_exists('getDashboardUrl')) {
function getDashboardUrl() {
    $role = getUserRole();
    
    switch ($role) {
        case 'admin':
            return 'admin/Dashboard.php';
        case 'department':
            return 'dept_login/dashboard.php';
        case 'aaqa':
            return 'AAQA_login/dashboard.php';
        case 'muibeas':
            return 'MUIBEAS/dashboard.php';
        case 'expert':
            return 'Expert_comty_login/dashboard.php';
        case 'chairman':
            return 'Chairman_login/dashboard.php';
        case 'verification_committee':
            return 'verification_committee/dashboard.php';
        default:
            return 'user/dashboard.php';
    }
}
}

// Logout function
if (!function_exists('logoutUser')) {
function logoutUser() {
    session_start();
    session_destroy();
    echo '<script>top.location = "unified_login.php";</script>';
    exit;
}
}

// Security headers
if (!function_exists('setSecurityHeaders')) {
function setSecurityHeaders() {
    // Only set headers if they haven't been sent yet
    if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    }
}
}

// Set security headers ONLY if headers haven't been sent yet
// This prevents errors when called after HTML output has started
if (!headers_sent()) {
setSecurityHeaders();
}
?>
