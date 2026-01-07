<?php
/**
 * Delete Chairman Remark - Chairman Side
 * Security: Only chairman can delete remarks
 */

// Start output buffering FIRST to catch any output
if (ob_get_level() === 0) {
    ob_start();
}

// Suppress any errors/warnings that might output before JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    // Include config first
    $config_path = __DIR__ . '/../../config.php';
    if (!file_exists($config_path)) {
        throw new Exception('config.php not found at: ' . $config_path);
    }
    require_once($config_path);
    
    // Include session
    $session_path = __DIR__ . '/../session.php';
    if (!file_exists($session_path)) {
        throw new Exception('session.php not found at: ' . $session_path);
    }
    require_once($session_path);
    
    // Ensure database connection is available
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection not available');
    }
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Initialization error: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get input - try JSON first, then form data
$input = null;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $input = null;
    }
}

// Fallback to form data
if (!$input) {
    $input = $_POST;
}

// If still no input, try to parse raw input as form data
if (empty($input) && !empty(file_get_contents('php://input'))) {
    parse_str(file_get_contents('php://input'), $input);
}

$remark_id = isset($input['remark_id']) ? (int)$input['remark_id'] : 0;

if (!$remark_id) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid remark ID']);
    exit;
}

// Delete the remark (chairman can delete any remark)
$delete_query = "DELETE FROM chairman_remarks WHERE id = ?";
$delete_stmt = $conn->prepare($delete_query);
if (!$delete_stmt) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete statement: ' . $conn->error]);
    exit;
}

$delete_stmt->bind_param("i", $remark_id);
if ($delete_stmt->execute()) {
    $delete_stmt->close();
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Remark deleted successfully']);
} else {
    $error_msg = $delete_stmt->error ? $delete_stmt->error : $conn->error;
    $delete_stmt->close();
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete remark: ' . $error_msg]);
}

