<?php
/**
 * API Endpoint: Save Verification Status
 * Handles verification submission for HRDC and AAQA
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/verification_debug.log');
error_reporting(0);

// SECURITY: Clear all output buffers FIRST before any includes
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!function_exists('send_json_response')) {
    function send_json_response(array $payload): void {
        // SECURITY: Clear all output buffers before JSON response
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // SECURITY: Set proper JSON headers
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-cache, must-revalidate');
        }
        // SECURITY: Use JSON encoding with security flags
        echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('verification_debug_log')) {
    function verification_debug_log(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $line = '[' . $timestamp . '] ' . $message . PHP_EOL;
        $primary = __DIR__ . '/verification_debug.log';
        $fallback = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'verification_debug.log';
        $written = @file_put_contents($primary, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            @file_put_contents($fallback, $line, FILE_APPEND | LOCK_EX);
        }
    }
}

try {
    $debug_code = 'SV-START';
    verification_debug_log('--- save_verification.php start ---');
    
    // SECURITY: Load dependencies silently with output buffering
    ob_start();
    require_once(__DIR__ . '/../../session_verification.php');
    ob_end_clean();
    $debug_code = 'SV-SESSION';
    
    ob_start();
    require('../functions.php');
    ob_end_clean();
    $debug_code = 'SV-FUNCTIONS';
    
    ob_start();
    if (file_exists(__DIR__ . '/../../dept_login/csrf.php')) {
        require_once(__DIR__ . '/../../dept_login/csrf.php');
    }
    ob_end_clean();
    $debug_code = 'SV-CSRF-LOAD';
    
    // SECURITY: Ensure database connection is available (check before including)
    if (!isset($conn) || !$conn) {
        ob_start();
        if (file_exists(__DIR__ . '/../../config.php')) {
            require_once(__DIR__ . '/../../config.php');
        }
        ob_end_clean();
    }
    
    // SECURITY: Clear buffers again after includes
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL: Check connection with timeout protection (same as dept_login)
    if (!isset($conn) || !$conn) {
        $debug_code = 'SV-DB-CONNECT';
        verification_debug_log('DB connection not set');
        send_json_response(['success' => false, 'message' => 'Database connection error. Please try again.']);
    }
    
    // CRITICAL: Ping connection to check if it's alive, with error suppression
    if (!@mysqli_ping($conn)) {
        $debug_code = 'SV-DB-PING';
        verification_debug_log('DB connection ping failed, attempting reconnect');
        // Try to reconnect if ping fails
        @mysqli_close($conn);
        unset($conn);
        if (file_exists(__DIR__ . '/../../config.php')) {
            require_once(__DIR__ . '/../../config.php');
        }
        if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
            verification_debug_log('DB reconnection failed');
            send_json_response(['success' => false, 'message' => 'Database connection error. Please try again.']);
        }
    }

    $email = $_SESSION['admin_username'] ?? '';
    $has_verification_access = function_exists('hasPermission') ? hasPermission('verification') : false;
    $role_name = getCommitteeRoleName($email);
    $debug_code = 'SV-AUTH';

    if (!$email || !$role_name || !$has_verification_access) {
        $debug_code = 'SV-UNAUTHORIZED';
        verification_debug_log('Unauthorized: email/role/access missing');
        send_json_response(['success' => false, 'message' => 'Unauthorized']);
    }

// Get POST data
$dept_id = isset($_POST['dept_id']) ? (int)$_POST['dept_id'] : 0;
$section_number = isset($_POST['section_number']) ? trim((string)$_POST['section_number']) : '';
$item_number = isset($_POST['item_number']) ? trim((string)$_POST['item_number']) : '';
$verification_status = isset($_POST['verification_status']) ? trim($_POST['verification_status']) : '';
$lock = isset($_POST['lock']) && $_POST['lock'] === 'true';
$unlock = isset($_POST['unlock']) && $_POST['unlock'] === 'true';
$clear = isset($_POST['clear']) && $_POST['clear'] === 'true';
$remark = isset($_POST['remark']) ? trim($_POST['remark']) : null;

    // CSRF validation
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!function_exists('validate_csrf') || empty($csrf_token) || !validate_csrf($csrf_token)) {
        $debug_code = 'SV-CSRF-FAIL';
        verification_debug_log('CSRF validation failed');
        send_json_response(['success' => false, 'message' => 'Security token validation failed. Please refresh the page and try again.']);
    }
    $debug_code = 'SV-CSRF-OK';

// Debug logging
verification_debug_log("Received: dept_id=$dept_id, section=$section_number, item=$item_number, status=$verification_status, lock=" . ($lock ? 'true' : 'false'));
error_log("[save_verification.php] Received: dept_id=$dept_id, section=$section_number, item=$item_number, status=$verification_status, lock=" . ($lock ? 'true' : 'false'));

// Validate inputs
    if (!$dept_id || $section_number === '' || $item_number === '') {
        $debug_code = 'SV-VALIDATE-MISSING';
        error_log("[save_verification.php] ERROR: Missing required parameters - dept_id=$dept_id, section='$section_number', item='$item_number'");
        verification_debug_log('Missing required parameters');
        send_json_response(['success' => false, 'message' => 'Missing required parameters: dept_id, section_number, and item_number are required']);
    }
    
    if (!preg_match('/^\d+$/', $section_number)) {
        $debug_code = 'SV-VALIDATE-SECTION';
        verification_debug_log('Invalid section number format');
        send_json_response(['success' => false, 'message' => 'Invalid section number format']);
    }
    if (!preg_match('/^\d+(?:-\d+)?$/', $item_number)) {
        $debug_code = 'SV-VALIDATE-ITEM';
        verification_debug_log('Invalid item number format');
        send_json_response(['success' => false, 'message' => 'Invalid item number format']);
    }

// Validate verification_status (unless unlocking or clearing)
    if (!$unlock && !$clear && !in_array($verification_status, ['verified_correct', 'verified_incorrect'], true)) {
        $debug_code = 'SV-VALIDATE-STATUS';
        verification_debug_log('Invalid verification status');
        send_json_response(['success' => false, 'message' => 'Invalid verification status']);
    }
    $debug_code = 'SV-VALIDATE-OK';

// Check if this item is assigned to this committee member
$assigned_items = getAssignedItems($email, $role_name);
$is_assigned = false;
    $debug_code = 'SV-ASSIGN-LOADED';
verification_debug_log('Assigned items count: ' . count($assigned_items));

foreach ($assigned_items as $item) {
    if ($item['section'] == $section_number) {
        // Exact match
        if ($item['item'] == $item_number) {
            $is_assigned = true;
            break;
        }
        // Sub-item check: if assigned to item "8", allow sub-items "8-1", "8-2", "8-3", "8-4"
        if (strpos($item_number, '-') !== false) {
            // item_number is a sub-item like "8-1"
            $parent_item = explode('-', $item_number)[0];
            if ($item['item'] == $parent_item || (string)$item['item'] === $parent_item) {
                $is_assigned = true;
                break;
            }
        }
    }
}

    if (!$is_assigned) {
        $debug_code = 'SV-ASSIGN-DENY';
        verification_debug_log('Not assigned to item');
        send_json_response(['success' => false, 'message' => 'You are not assigned to verify this item']);
    }
    $debug_code = 'SV-ASSIGN-OK';

// Get academic year
$academic_year = getAcademicYear();
    $debug_code = 'SV-YEAR';
verification_debug_log('Academic year: ' . $academic_year);

// Handle clear
if ($clear) {
    global $conn;
    $section_str = is_numeric($section_number) ? (string)$section_number : $section_number;
    $item_str = is_numeric($item_number) ? (string)$item_number : (string)$item_number;
    
    $clear_query = "DELETE FROM verification_flags 
                    WHERE dept_id = ? 
                      AND academic_year = ? 
                      AND section_number = ? 
                      AND item_number = ? 
                      AND committee_email = ?";
    $stmt = @$conn->prepare($clear_query);
    if ($stmt) {
        $stmt->bind_param("issss", $dept_id, $academic_year, $section_str, $item_str, $email);
        $success = @$stmt->execute();
        // CRITICAL: Always close statement to free resources (same as dept_login)
        $stmt->close();
        
        if ($success) {
            send_json_response(['success' => true, 'message' => 'Verification cleared successfully']);
        }
        send_json_response(['success' => false, 'message' => 'Failed to clear verification']);
    } else {
        error_log("[clear_verification] Prepare failed: " . ($conn->error ?? 'Unknown error'));
        send_json_response(['success' => false, 'message' => 'Database error']);
    }
}

// Handle unlock
if ($unlock) {
    global $conn;
    $section_str = is_numeric($section_number) ? (string)$section_number : $section_number;
    $item_str = is_numeric($item_number) ? (string)$item_number : (string)$item_number;
    
    $unlock_query = "UPDATE verification_flags 
                    SET is_locked = 0, updated_at = CURRENT_TIMESTAMP
                    WHERE dept_id = ? 
                      AND academic_year = ? 
                      AND section_number = ? 
                      AND item_number = ? 
                      AND committee_email = ?";
    $stmt = @$conn->prepare($unlock_query);
    if ($stmt) {
        $stmt->bind_param("issss", $dept_id, $academic_year, $section_str, $item_str, $email);
        $success = @$stmt->execute();
        // CRITICAL: Always close statement to free resources (same as dept_login)
        $stmt->close();
        
        if ($success) {
            send_json_response(['success' => true, 'message' => 'Verification unlocked successfully']);
        }
        send_json_response(['success' => false, 'message' => 'Failed to unlock verification']);
    } else {
        error_log("[unlock_verification] Prepare failed: " . ($conn->error ?? 'Unknown error'));
        send_json_response(['success' => false, 'message' => 'Database error']);
    }
}

// Save verification status
    $debug_code = 'SV-SAVE';
    $result = saveVerificationStatus($dept_id, $section_number, $item_number, $email, $verification_status, $academic_year, $lock, $remark);
    verification_debug_log('Save result: ' . json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    send_json_response($result);
} catch (Throwable $e) {
    error_log("[save_verification.php] Fatal error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    verification_debug_log("Fatal error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    $code = isset($debug_code) ? $debug_code : 'SV-UNKNOWN';
    send_json_response(['success' => false, 'message' => 'Server error. Please try again.', 'code' => $code]);
}
