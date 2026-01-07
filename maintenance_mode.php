<?php
// maintenance_mode.php - Handles maintenance mode redirection

// Function to check if we're in maintenance mode
function isMaintenanceMode() {
    // You can modify this to read from a configuration file or database
    $maintenanceFile = __DIR__ . '/maintenance.flag';
    return file_exists($maintenanceFile);
}

// Function to enable maintenance mode
function enableMaintenanceMode() {
    $maintenanceFile = __DIR__ . '/maintenance.flag';
    return file_put_contents($maintenanceFile, date('Y-m-d H:i:s'));
}

// Function to disable maintenance mode
function disableMaintenanceMode() {
    $maintenanceFile = __DIR__ . '/maintenance.flag';
    if (file_exists($maintenanceFile)) {
        return unlink($maintenanceFile);
    }
    return true;
}

// Check if current request should be allowed during maintenance
function shouldAllowRequest() {
    // List of IPs that should always have access (add your IP here)
    $allowedIPs = [
        '127.0.0.1',        // localhost
        '::1'               // localhost IPv6
    ];
    
    // List of URLs that should always be accessible
    $allowedURLs = [
        '/maintenance.html',
        '/assets/css/',
        '/assets/js/',
        '/assets/img/'
    ];
    
    // Check if the request is from an allowed IP
    if (in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
        return true;
    }
    
    // Check if the requested URL is in the allowed list
    $requestUri = $_SERVER['REQUEST_URI'];
    foreach ($allowedURLs as $url) {
        if (strpos($requestUri, $url) !== false) {
            return true;
        }
    }
    
    return false;
}

// If in maintenance mode and request should be redirected
if (isMaintenanceMode() && !shouldAllowRequest()) {
    // Save the original URL if it's not already saved
    if (!isset($_SESSION['redirect_after_maintenance'])) {
        $_SESSION['redirect_after_maintenance'] = $_SERVER['REQUEST_URI'];
    }
    
    // Redirect to maintenance page
    header('Location: maintenance.html');
    exit;
}
?>