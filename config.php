<?php
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$server   = $_ENV['DB_HOST'];
$user     = $_ENV['DB_USER'];
$pass     = $_ENV['DB_PASS'];
$database = $_ENV['DB_NAME'];

/**
 * Use persistent connection (prefix 'p:' before hostname)
 * This helps reuse existing DB connections instead of opening a new one each request.
 */
if (
    !isset($GLOBALS['db_connection']) ||
    !$GLOBALS['db_connection'] ||
    !@mysqli_ping($GLOBALS['db_connection'])
) {
    // CRITICAL: If connection exists but ping fails, it means connection timed out
    // Unset the old connection before creating a new one
    if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection']) {
        @mysqli_close($GLOBALS['db_connection']);
        unset($GLOBALS['db_connection']);
    }
    
    // Persistent connection
    $GLOBALS['db_connection'] = @mysqli_connect('p:' . $server, $user, $pass, $database);

    if (!$GLOBALS['db_connection']) {
        // Log the error for debugging (in error_log file)
        $error_msg = mysqli_connect_error();
        error_log("Database connection failed: " . $error_msg);
        
        // CRITICAL: Return proper HTTP status code (500 Internal Server Error, NOT 404)
        // 404 is for "page not found", 500 is for server errors like database connection failures
        if (!headers_sent()) {
            http_response_code(500); // Internal Server Error
            header('Content-Type: text/html; charset=UTF-8');
        }
        
        // Show user-friendly error page instead of just dying
        die("<!DOCTYPE html><html><head><title>Database Connection Error</title></head><body style='font-family: Arial, sans-serif; padding: 40px; text-align: center;'><h1 style='color: #d32f2f;'>Database Connection Error</h1><p>Unable to connect to the database. Please try again in a few moments.</p><p style='color: #666; font-size: 14px;'>If this problem persists, please contact the administrator.</p><p><a href='javascript:location.reload()' style='padding: 10px 20px; background: #1976d2; color: white; text-decoration: none; border-radius: 4px;'>Retry</a></p></body></html>");
    }

    // Set character encoding
    mysqli_set_charset($GLOBALS['db_connection'], 'utf8mb4');
    mysqli_query($GLOBALS['db_connection'], "SET collation_connection = 'utf8mb4_general_ci'");
    
    // CRITICAL: Set query timeout to prevent long-running queries from blocking other users
    // These settings help prevent queries from running indefinitely and blocking other connections
    // Note: max_execution_time is PHP setting, not MySQL - set via ini_set if needed
    // MySQL wait_timeout controls how long MySQL waits for activity on a connection
    // Reduced to 600 seconds (10 minutes) to free connections faster for other users
    @mysqli_query($GLOBALS['db_connection'], "SET SESSION wait_timeout = 600");
    @mysqli_query($GLOBALS['db_connection'], "SET SESSION interactive_timeout = 600");
    
    // CRITICAL: Query cache optimization - REMOVED
    // Note: Query cache is deprecated and removed in MySQL 8.0+
    // Most modern MySQL/MariaDB servers have query cache disabled globally
    // Attempting to enable it causes errors, so we skip it entirely
    // Modern MySQL uses better caching mechanisms (InnoDB buffer pool, etc.)
    
    // Set PHP execution time limit (30 seconds) for safety
    // This controls how long PHP scripts can run, which includes database queries
    @ini_set('max_execution_time', 30);
    
    // CRITICAL: Set connection limits to prevent too many concurrent connections
    // This helps prevent "Too many connections" errors
    // Note: These are MySQL server-level settings, but we can check current status
    @mysqli_query($GLOBALS['db_connection'], "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");
}

// Use the global persistent connection
$conn = $GLOBALS['db_connection'];

// Base URL (loaded from .env file)
$port_link = $_ENV['BASE_URL'] ?? 'https://uomdcs.univofmumbai.in';
// $port_link = 'http://localhost/NIFR-University/nirf'; // For local development
$date = date_default_timezone_set('Asia/Kolkata');

?>
