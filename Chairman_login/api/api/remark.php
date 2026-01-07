<?php
/**
 * API Endpoint: Send Chairman Remark
 * POST /api/chairman/remark
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any errors
ob_start();

header('Content-Type: application/json');

try {
    require_once(__DIR__ . '/../../config.php');
    require_once(__DIR__ . '/../session.php');
    require_once(__DIR__ . '/../functions.php');
    
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

$dept_id = isset($input['dept_id']) ? (int)$input['dept_id'] : 0;
$remark_text = isset($input['remark_text']) ? trim($input['remark_text']) : '';
$academic_year = isset($input['academic_year']) ? trim($input['academic_year']) : getAcademicYear();
$category = isset($input['category']) ? trim($input['category']) : '';
$remark_type = isset($input['remark_type']) ? trim($input['remark_type']) : 'general';
$priority = isset($input['priority']) ? trim($input['priority']) : 'medium';

if (!$dept_id || empty($remark_text)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields: dept_id and remark_text']);
    exit;
}

if (strlen($remark_text) > 500) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Remark text exceeds 500 characters']);
    exit;
}

// Get category if not provided - try multiple approaches
if (empty($category)) {
    // First try: direct match with dept_id as string
    $cat_query = "SELECT category FROM department_profiles WHERE dept_id = ? AND A_YEAR = ? LIMIT 1";
    $cat_stmt = $conn->prepare($cat_query);
    if ($cat_stmt) {
        $dept_id_str = (string)$dept_id;
        $cat_stmt->bind_param("ss", $dept_id_str, $academic_year);
        $cat_stmt->execute();
        $cat_result = $cat_stmt->get_result();
        if ($cat_row = $cat_result->fetch_assoc()) {
            $category = $cat_row['category'] ?? '';
        }
        mysqli_free_result($cat_result);
        $cat_stmt->close();
    }
    
    // Second try: if not found, try with CAST
    if (empty($category)) {
        $cat_query = "SELECT category FROM department_profiles WHERE CAST(dept_id AS UNSIGNED) = ? AND A_YEAR = ? LIMIT 1";
        $cat_stmt = $conn->prepare($cat_query);
        if ($cat_stmt) {
            $cat_stmt->bind_param("is", $dept_id, $academic_year);
            $cat_stmt->execute();
            $cat_result = $cat_stmt->get_result();
            if ($cat_row = $cat_result->fetch_assoc()) {
                $category = $cat_row['category'] ?? '';
            }
            $cat_stmt->close();
        }
    }
}

// Use sendChairmanRemark function
try {
    $remark_data = [
        'remark_type' => $remark_type,
        'priority' => $priority
    ];
    $result = sendChairmanRemark($dept_id, $remark_text, $academic_year, $remark_data);
    
    if ($result && isset($result['success']) && $result['success']) {
        ob_end_clean();
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Remark sent successfully']);
    } else {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Failed to send remark']);
    }
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
} catch (Error $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
}

