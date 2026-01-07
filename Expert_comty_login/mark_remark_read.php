<?php
// Expert_comty_login/mark_remark_read.php - Mark remark as read (AJAX compatible)
// Set timezone FIRST (before any other code)
if (!date_default_timezone_get() || date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

// Start output buffering to catch any output
if (ob_get_level() === 0) {
    ob_start();
}

// Suppress any errors/warnings that might output before JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/session.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Debug: Log email variable
    error_log("[mark_remark_read] Expert email from session: " . ($email ?? 'NULL'));
    error_log("[mark_remark_read] Request method: " . $_SERVER['REQUEST_METHOD']);
    
    // Get input (form or JSON)
    $remark_id = 0;
    if (isset($_POST['remark_id'])) {
        $remark_id = (int)$_POST['remark_id'];
        error_log("[mark_remark_read] Got remark_id from POST: " . $remark_id);
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $remark_id = isset($input['remark_id']) ? (int)$input['remark_id'] : 0;
        error_log("[mark_remark_read] Got remark_id from JSON: " . $remark_id);
    }
    
    if (!$remark_id) {
        error_log("[mark_remark_read] ERROR: Invalid remark ID");
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid remark ID']);
        exit;
    }
    
    // Check if email variable exists
    if (!isset($email) || empty($email)) {
        error_log("[mark_remark_read] ERROR: Email not set in session");
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    
    // Verify remark belongs to this expert
    $check_query = "SELECT id FROM chairman_remarks WHERE id = ? AND expert_email = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_query);
    if (!$check_stmt) {
        error_log("[mark_remark_read] ERROR: Failed to prepare check query: " . $conn->error);
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $check_stmt->bind_param("is", $remark_id, $email);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    error_log("[mark_remark_read] Check query returned " . $check_result->num_rows . " rows");
    
    if ($check_result->num_rows === 0) {
        error_log("[mark_remark_read] ERROR: Remark not found or unauthorized for ID: " . $remark_id . ", Email: " . $email);
        mysqli_free_result($check_result);
        $check_stmt->close();
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Remark not found or unauthorized']);
        exit;
    }
    mysqli_free_result($check_result);
    $check_stmt->close();
    
    // Update remark as read
    // Note: status ENUM has values: 'new','read','acknowledged','resolved'
    // But some databases might have different values, so we update is_read flag only
    $update_query = "UPDATE chairman_remarks SET is_read = 1, read_at = NOW() WHERE id = ? AND expert_email = ?";
    $stmt = $conn->prepare($update_query);
    if ($stmt) {
        $stmt->bind_param("is", $remark_id, $email);
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            error_log("[mark_remark_read] SUCCESS: Updated " . $affected . " row(s)");
            
            // Now try to update status separately (if column supports 'read' value)
            $status_update = "UPDATE chairman_remarks SET status = 'read' WHERE id = ? AND expert_email = ?";
            $stmt2 = $conn->prepare($status_update);
            if ($stmt2) {
                $stmt2->bind_param("is", $remark_id, $email);
                $stmt2->execute(); // Don't fail if this doesn't work
                $stmt2->close();
            }
            
            $stmt->close();
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Remark marked as read']);
        } else {
            error_log("[mark_remark_read] ERROR: Execute failed: " . $stmt->error);
            $stmt->close();
            ob_end_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to mark remark as read: ' . $stmt->error]);
        }
    } else {
        error_log("[mark_remark_read] ERROR: Failed to prepare update query: " . $conn->error);
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to prepare statement: ' . $conn->error]);
    }
} catch (Exception $e) {
    ob_end_clean();
    error_log("[mark_remark_read] EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

