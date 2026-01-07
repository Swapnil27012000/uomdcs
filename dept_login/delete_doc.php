<?php
// delete_doc.php - Secure document deletion handler
// Start output buffering at the very beginning
ob_start();

// Set timezone before any date operations
if (!date_default_timezone_get() || date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

session_start();
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    require_once(__DIR__ . '/../config.php');
}

// Ensure user is logged in
if (!isset($_SESSION['dept_id'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Session expired. Please login again.']));
}

// ONLY accept POST requests (not GET) for security
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']));
}

// CSRF validation
if (file_exists(__DIR__ . '/csrf.php')) {
    require_once(__DIR__ . '/csrf.php');
    if (function_exists('validate_csrf')) {
        $csrf = $_POST['csrf_token'] ?? '';
        if (empty($csrf) || !validate_csrf($csrf)) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'Security token validation failed.']));
        }
    }
}

// Clear all output buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Set proper headers
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

// Get and validate parameters using prepared statements
$srno = isset($_POST['srno']) ? (int)$_POST['srno'] : 0;
$particulars = isset($_POST['particulars']) ? trim($_POST['particulars']) : '';

if ($srno <= 0 || empty($particulars)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

$dept = $_SESSION['dept_id'] ?? 0;
if (!$dept) {
    echo json_encode(['success' => false, 'message' => 'Department ID not found.']);
    exit;
}

try {
    // Calculate academic year
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    // FIXED: July onwards (month >= 7) = current-next, Jan-June = (current-2)-(current-1)
    $A_YEAR = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 2) . '-' . ($current_year - 1);
    
    // Use prepared statement to fetch file path from nep_documents table
    // Only fetch records that belong to this department
    $get_file_query = "SELECT file_path FROM nep_documents WHERE dept_id = ? AND srno = ? AND particulars = ? AND A_YEAR = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $get_file_query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit;
    }
    
    mysqli_stmt_bind_param($stmt, 'iiss', $dept, $srno, $particulars, $A_YEAR);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $file_path = $row['file_path'];
        
        // Normalize and validate file path for security
        // Ensure path is within uploads directory and belongs to this department
        $normalized_path = realpath($file_path);
        $uploads_base = realpath(dirname(__DIR__) . '/uploads');
        
        // Security check: Ensure file is within uploads directory
        if (!$normalized_path || !$uploads_base || strpos($normalized_path, $uploads_base) !== 0) {
            // Try relative path normalization
            $relative_path = ltrim($file_path, '/\\');
            // Remove any '../' attempts
            $relative_path = str_replace('../', '', $relative_path);
            $normalized_path = realpath(dirname(__DIR__) . '/' . $relative_path);
            
            if (!$normalized_path || !$uploads_base || strpos($normalized_path, $uploads_base) !== 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid file path.']);
                mysqli_stmt_close($stmt);
                exit;
            }
        }
        
        // Additional security: Ensure path contains department ID
        if (strpos($normalized_path, "/DEPARTMENT/{$dept}/") === false && strpos($normalized_path, "\\DEPARTMENT\\{$dept}\\") === false) {
            echo json_encode(['success' => false, 'message' => 'File path does not match department.']);
            mysqli_stmt_close($stmt);
            exit;
        }
        
        // Delete file from filesystem (only if it exists and is valid)
        if (file_exists($normalized_path) && is_file($normalized_path)) {
            if (!unlink($normalized_path)) {
                error_log("Failed to delete file: $normalized_path");
                // Continue with database deletion even if file deletion fails
            }
        }
        
        // Delete from database using prepared statement
        $delete_query = "DELETE FROM nep_documents WHERE dept_id = ? AND srno = ? AND particulars = ? AND A_YEAR = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        if ($delete_stmt) {
            mysqli_stmt_bind_param($delete_stmt, 'iiss', $dept, $srno, $particulars, $A_YEAR);
            if (mysqli_stmt_execute($delete_stmt)) {
                mysqli_stmt_close($delete_stmt);
                mysqli_stmt_close($stmt);
                echo json_encode(['success' => true, 'message' => 'Document deleted successfully.']);
                exit;
            } else {
                mysqli_stmt_close($delete_stmt);
                echo json_encode(['success' => false, 'message' => 'Database delete failed: ' . mysqli_stmt_error($delete_stmt)]);
                mysqli_stmt_close($stmt);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
            mysqli_stmt_close($stmt);
            exit;
        }
    } else {
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        exit;
    }
} catch (Exception $e) {
    error_log("delete_doc.php Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while deleting the document.']);
    exit;
}
?>
