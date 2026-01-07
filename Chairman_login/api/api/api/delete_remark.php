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

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/../session.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Verify database connection
    if (!isset($conn) || !$conn) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection error']);
        exit;
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $remark_id = isset($input['remark_id']) ? (int)$input['remark_id'] : 0;
    
    if (!$remark_id) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid remark ID']);
        exit;
    }
    
    // Verify remark exists before deleting
    $check_query = "SELECT id FROM chairman_remarks WHERE id = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_query);
    if (!$check_stmt) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $check_stmt->bind_param("i", $remark_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        mysqli_free_result($check_result);
        $check_stmt->close();
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Remark not found']);
        exit;
    }
    mysqli_free_result($check_result);
    $check_stmt->close();
    
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
        echo json_encode(['success' => true, 'message' => 'Remark deleted successfully']);
    } else {
        $error_msg = $delete_stmt->error ? $delete_stmt->error : $conn->error;
        $delete_stmt->close();
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete remark: ' . $error_msg]);
    }
} catch (Exception $e) {
    ob_end_clean();
    error_log("Delete remark error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} catch (Error $e) {
    ob_end_clean();
    error_log("Delete remark fatal error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
}

