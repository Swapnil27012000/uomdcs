<?php
// Suppress error reporting and start output buffering
// ExecutiveDevelopment - Executive development form
require('session.php');

// Load CSRF utilities
if (file_exists(__DIR__ . '/csrf.php')) {
    require_once __DIR__ . '/csrf.php';
}

error_reporting(0);
ini_set('display_errors', 0);
ob_start();require 'common_functions.php';
// Get messages from session and clear them
$success = isset($_SESSION['success']) ? $_SESSION['success'] : null;
$error = isset($_SESSION['error']) ? $_SESSION['error'] : null;
unset($_SESSION['success'], $_SESSION['error']);

// Get department ID from session (already verified in session.php)
$dept = $userInfo['DEPT_ID'] ?? 0;





if (!$dept) {
    throw new Exception('Department ID not found. Please contact administrator.');
}

// CRITICAL FIX: Get department details before use
$dept_query = "SELECT DEPT_COLL_NO, DEPT_NAME FROM department_master WHERE DEPT_ID = ? LIMIT 1";
$dept_stmt = mysqli_prepare($conn, $dept_query);
if ($dept_stmt) {
    mysqli_stmt_bind_param($dept_stmt, 'i', $dept);
    mysqli_stmt_execute($dept_stmt);
    $dept_result = mysqli_stmt_get_result($dept_stmt);
    $dept_info = mysqli_fetch_assoc($dept_result);
    if ($dept_result) {
        mysqli_free_result($dept_result);
    }
    mysqli_stmt_close($dept_stmt);
} else {
    $dept_info = ['DEPT_COLL_NO' => '', 'DEPT_NAME' => ''];
}

$dept_code = $dept_info['DEPT_COLL_NO'] ?? '';
$dept_name = $dept_info['DEPT_NAME'] ?? '';

// Academic year - use centralized function (common_functions.php already loaded)
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    if ($current_month >= 7) {
        $A_YEAR = $current_year . '-' . ($current_year + 1);
    } else {
        $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
    }
}

// Check if data already exists for this academic year
$existing_data = null;
$form_locked = false;
$check_query = "SELECT * FROM exec_dev WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
$check_stmt = mysqli_prepare($conn, $check_query);
if ($check_stmt) {
    mysqli_stmt_bind_param($check_stmt, "is", $dept, $A_YEAR);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if ($check_result && mysqli_num_rows($check_result) > 0) {
        $existing_data = mysqli_fetch_assoc($check_result);
        $form_locked = true; // Lock form if data exists for this academic year
    }
    
    // CRITICAL: Free result set before closing statement
    if ($check_result) {
        mysqli_free_result($check_result);
    }
    mysqli_stmt_close($check_stmt);
}



// ============================================================================
// PDF UPLOAD HANDLING - MUST BE BEFORE ANY HTML OUTPUT
// ============================================================================

// Handle PDF uploads - Route through common_upload_handler.php
if (isset($_POST['upload_document'])) {
    // Disable error display and clear all output buffers
    error_reporting(0);
    ini_set('display_errors', 0);
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load CSRF utilities
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if it doesn't exist
        }
    }
    
    // Validate CSRF token
    if (function_exists('validate_csrf')) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (empty($csrf_token) || !validate_csrf($csrf_token)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token validation failed. Please refresh the page and try again.']);
            exit;
        }
    }
    
    // Route through common_upload_handler.php
    require_once(__DIR__ . '/common_upload_handler.php');
    
    // Set required variables for common handler
    $dept_id = $dept;
    // Get dept_code from dept_info if available, otherwise use dept_id
    if (isset($dept_info) && isset($dept_info['DEPT_COLL_NO'])) {
        $dept_code = $dept_info['DEPT_COLL_NO'];
    } else {
        // Fallback: try to get from database
        $dept_code_query = "SELECT DEPT_COLL_NO FROM department_master WHERE DEPT_ID = ? LIMIT 1";
        $dept_code_stmt = mysqli_prepare($conn, $dept_code_query);
        if ($dept_code_stmt) {
            mysqli_stmt_bind_param($dept_code_stmt, "i", $dept);
            mysqli_stmt_execute($dept_code_stmt);
            $dept_code_result = mysqli_stmt_get_result($dept_code_stmt);
            if ($dept_code_row = mysqli_fetch_assoc($dept_code_result)) {
                $dept_code = $dept_code_row['DEPT_COLL_NO'] ?? $dept;
            } else {
                $dept_code = $dept;
            }
            mysqli_stmt_close($dept_code_stmt);
        } else {
            $dept_code = $dept;
        }
    }
    
    try {
        // Use A_YEAR format for folder structure
        // Format: uploads/{A_YEAR}/DEPARTMENT/{department_ID}/executive_development/FILENAME_UNIC_CODE.pdf
        // Get academic year - use centralized function
        if (function_exists('getAcademicYear')) {
            $A_YEAR = getAcademicYear();
        } else {
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            if ($current_month >= 7) {
                $A_YEAR = $current_year . '-' . ($current_year + 1);
            } else {
                $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
            }
        }
        
        // Call the handler function
        $result = handleDocumentUpload('executive_development', 'Executive Development', [
            'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/executive_development/",
            'max_size' => 10,
            'document_title' => 'Executive Development Program Documentation',
            'srno' => (int)($_POST['srno'] ?? 6),
            'file_id' => $_POST['file_id'] ?? 'exec_dev_pdf'
        ]);
        
        // CRITICAL: Ensure result is an array and normalize file path for web access
        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'Invalid response from upload handler'];
        } else {
            // Normalize file path to web-accessible format (same as StudentSupport.php)
            if ($result['success'] && isset($result['file_path'])) {
                $web_path = $result['file_path'];
                $project_root = dirname(__DIR__);
                
                // Convert absolute paths to relative web paths
                if (strpos($web_path, $project_root) === 0) {
                    $web_path = str_replace([$project_root . '/', $project_root . '\\'], '', $web_path);
                }
                $web_path = str_replace('\\', '/', $web_path);
                if (strpos($web_path, 'uploads/') === 0) {
                    $web_path = '../' . $web_path;
                }
                $result['file_path'] = $web_path;
            }
        }
        
        // Clear buffers before output
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Ensure headers are set
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-cache, must-revalidate');
        }
        
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Upload failed: ' . $e->getMessage()
        ]);
        exit;
    } catch (Error $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Upload failed: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Legacy upload handler (keeping for backward compatibility but routing to common handler above)
if (isset($_POST['upload_document_legacy'])) {
    // Clear any previous output
    ob_clean();
    
    // Set content type to JSON
    header('Content-Type: application/json');
    
    $file_id = $_POST['file_id'] ?? '';
    $srno = $_POST['srno'] ?? '';
    
    // Get department ID from session (already verified in session.php)

    
    $dept = $userInfo['DEPT_ID'];

    
    

    
    if (!$dept) {

    
        throw new Exception('Department ID not found. Please contact administrator.');

    
    }
    
    $academic_year = $A_YEAR;
    
    $dept_id = $dept;
    
    // Create organized department-wise upload directory structure (outside dept_login)
    // Use A_YEAR format: uploads/{A_YEAR}/DEPARTMENT/{department_ID}/executive_development/
    // A_YEAR is already set above, no need to recalculate
            $upload_dir = dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/executive_development/";
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            throw new Exception('Failed to create upload directory with proper permissions.');
        }
        chmod($upload_dir, 0777);
    }
    
    if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
        $file = $_FILES['document'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Validate PDF file
        if ($file_extension === 'pdf' && $file['type'] === 'application/pdf') {
            $file_name = $file_id . '_' . time() . '.pdf';
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                // Save to unified supporting_documents table
                $file_name = $file['name'];
                $file_size = $file['size'];
                $uploaded_by = $_SESSION['admin_username'];
                $document_title = 'Executive Development Program Documentation';
                
                // Check if document already exists
                $check_query = "SELECT id, file_path, status FROM supporting_documents 
                    WHERE academic_year=? AND dept_id=? AND page_section='executive_development' AND serial_number=?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                if ($check_stmt) {
                    mysqli_stmt_bind_param($check_stmt, "sii", $academic_year, $dept_id, $srno);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);
                } else {
                    $check_result = false;
                }
                
                if ($check_result && mysqli_num_rows($check_result) > 0) {
                    // Document exists - update it
                    $existing_doc = mysqli_fetch_assoc($check_result);
                    $old_file_path = $existing_doc['file_path'];
                    
                    // Delete old file if different (handle relative vs absolute)
                    $old_phys = (strpos($old_file_path, '../') === 0)
                        ? dirname(__DIR__) . '/' . str_replace('../', '', $old_file_path)
                        : (strpos($old_file_path, 'uploads/') === 0 ? dirname(__DIR__) . '/' . $old_file_path : $old_file_path);
                    if ($old_phys !== $file_path && file_exists($old_phys)) {
                        unlink($old_phys);
                    }
                    
                    // Convert absolute path to web-accessible path
                    $web_path = str_replace(dirname(__DIR__) . '/', '', $file_path);
                    $web_path = str_replace('\\', '/', $web_path);
                    
                    // Update existing record
                    $update_query = "UPDATE supporting_documents SET 
                        document_title=?, file_path=?, file_name=?, 
                        file_size=?, uploaded_by=?, updated_date=NOW(), status='active' 
                        WHERE id=?";
                    
                    $update_stmt = mysqli_prepare($conn, $update_query);
                    if ($update_stmt) {
                        mysqli_stmt_bind_param($update_stmt, "sssisi", $document_title, $web_path, $file_name, $file['size'], $uploaded_by, $existing_doc['id']);
                        if (mysqli_stmt_execute($update_stmt)) {
                        echo json_encode(['success' => true, 'message' => 'Document updated successfully', 'file_path' => $web_path, 'file_name' => $file_name, 'file_size' => $file_size]);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_stmt_error($update_stmt)]);
                        }
                        mysqli_stmt_close($update_stmt);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
                    }
                } else {
                    // Convert absolute path to web-accessible path
                    $web_path = str_replace(dirname(__DIR__) . '/', '', $file_path);
                    $web_path = str_replace('\\', '/', $web_path);
                    
                    // Insert new record
                    $insert_query = "INSERT INTO supporting_documents 
                        (academic_year, dept_id, page_section, section_name, serial_number, document_title, file_path, file_name, file_size, uploaded_by, status) 
                        VALUES (?, ?, 'executive_development', 'Executive Development', ?, ?, ?, ?, ?, ?, 'active')";
                    
                    $insert_stmt = mysqli_prepare($conn, $insert_query);
                    if ($insert_stmt) {
                        mysqli_stmt_bind_param($insert_stmt, "siisssis", $academic_year, $dept_id, $srno, $document_title, $web_path, $file_name, $file['size'], $uploaded_by);
                        if (mysqli_stmt_execute($insert_stmt)) {
                        echo json_encode(['success' => true, 'message' => 'Document uploaded successfully', 'file_path' => $web_path, 'file_name' => $file_name, 'file_size' => $file_size]);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_stmt_error($insert_stmt)]);
                        }
                        mysqli_stmt_close($insert_stmt);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
                    }
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Please upload a valid PDF file']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    }
    exit;
}

// Handle PDF deletion - Convert to POST for security
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_doc'])) {
    // CRITICAL: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session and load config FIRST (silently)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config silently
    ob_start();
    if (!isset($conn) || !$conn) {
        if (file_exists(__DIR__ . '/../config.php')) {
            require_once(__DIR__ . '/../config.php');
        }
    }
    ob_end_clean();
    
    // Load session silently
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    ob_end_clean();
    
    // Load CSRF utilities silently
    ob_start();
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('csrf_token')) {
            csrf_token();
        }
    }
    ob_end_clean();
    
    // Clear buffers again
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // CRITICAL #4: Set headers BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
    // Validate CSRF token
    if (function_exists('validate_csrf')) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (empty($csrf_token) || !validate_csrf($csrf_token)) {
                $response = ['success' => false, 'message' => 'Security token validation failed. Please refresh the page and try again.'];
        }
    }
    
        if (!isset($response['message']) || $response['message'] !== 'Security token validation failed. Please refresh the page and try again.') {
    $srno = (int)($_POST['srno'] ?? 0);
            $dept = $userInfo['DEPT_ID'] ?? 0;
    
    if (!$dept) {
                $response = ['success' => false, 'message' => 'Department ID not found.'];
            } elseif ($srno <= 0) {
                $response = ['success' => false, 'message' => 'Invalid serial number.'];
            } else {
                // Use A_YEAR if available, otherwise calculate using getAcademicYear() logic
                if (!empty($A_YEAR)) {
                    $academic_year = $A_YEAR;
                } else {
                    if (function_exists('getAcademicYear')) {
                        $academic_year = getAcademicYear();
                    } else {
                        $current_year = (int)date('Y');
                        $current_month = (int)date('n');
                        if ($current_month >= 7) {
                            $academic_year = $current_year . '-' . ($current_year + 1);
                        } else {
                            $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
                        }
                    }
                }
    $dept_id = $dept;
    
    // Get file path from database
                $get_file_query = "SELECT file_path FROM supporting_documents WHERE academic_year=? AND dept_id=? AND page_section='executive_development' AND serial_number=? AND status='active' LIMIT 1";
                $stmt_get = mysqli_prepare($conn, $get_file_query);
                if (!$stmt_get) {
                    $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
    } else {
                    mysqli_stmt_bind_param($stmt_get, "sii", $academic_year, $dept_id, $srno);
                    if (mysqli_stmt_execute($stmt_get)) {
                        $result_get = mysqli_stmt_get_result($stmt_get);
    
                        if ($result_get && mysqli_num_rows($result_get) > 0) {
                            $row = mysqli_fetch_assoc($result_get);
        $file_path = $row['file_path'];
                            mysqli_free_result($result_get);  // CRITICAL: Free result
                            mysqli_stmt_close($stmt_get);
        
                            // Delete file from filesystem
        $project_root = dirname(__DIR__);
        $phys = $file_path;
        if (strpos($phys, '../') === 0) {
            $phys = $project_root . '/' . str_replace('../', '', $phys);
        } elseif (strpos($phys, 'uploads/') === 0) {
            $phys = $project_root . '/' . $phys;
        }
                            $phys = str_replace('\\', '/', $phys);
        
        if ($phys && file_exists($phys)) {
                                @unlink($phys);
        } else {
                                // Try with A_YEAR format
            $filename = basename($file_path);
                                $current_year = (int)date('Y');
            $current_month = (int)date('n');
            // FIXED: July onwards (month >= 7) = current-next, Jan-June = (current-2)-(current-1)
            $a_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 2) . '-' . ($current_year - 1);
            $phys_a_year = $project_root . "/uploads/{$a_year}/DEPARTMENT/{$dept_id}/executive_development/{$filename}";
            $phys_a_year = str_replace('\\', '/', $phys_a_year);
            if (file_exists($phys_a_year)) {
                                    @unlink($phys_a_year);
            }
        }
        
        // Soft delete from database
                            $delete_query = "UPDATE supporting_documents SET status='deleted', updated_date=CURRENT_TIMESTAMP WHERE academic_year=? AND dept_id=? AND page_section='executive_development' AND serial_number=? AND status='active'";
                            $stmt_del = mysqli_prepare($conn, $delete_query);
                            if ($stmt_del) {
                                mysqli_stmt_bind_param($stmt_del, "sii", $academic_year, $dept_id, $srno);
                                if (mysqli_stmt_execute($stmt_del)) {
                                    $response = ['success' => true, 'message' => 'Document deleted successfully'];
            } else {
                                    $response = ['success' => false, 'message' => 'Database error: Failed to execute delete query'];
            }
                                mysqli_stmt_close($stmt_del);  // CRITICAL: Close statement
        } else {
                                $response = ['success' => false, 'message' => 'Database error: Failed to prepare delete query'];
        }
    } else {
                            if ($result_get) {
                                mysqli_free_result($result_get);  // CRITICAL: Free result even if empty
                            }
                            mysqli_stmt_close($stmt_get);
                            $response = ['success' => false, 'message' => 'Document not found'];
                        }
                    } else {
                        mysqli_stmt_close($stmt_get);
                        $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
                    }
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    // Clear buffers before output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Output response once at end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// HANDLE check_all_docs - BATCH endpoint to get ALL document statuses in ONE query
// This replaces individual queries with a single efficient query
// ============================================================================
if (isset($_GET['check_all_docs'])) {
    // CRITICAL: Clear ALL output buffers FIRST - prevent any output before JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Start session and load dependencies FIRST
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config silently
    ob_start();
    if (!isset($conn) || !$conn) {
        if (file_exists(__DIR__ . '/../config.php')) {
            require_once(__DIR__ . '/../config.php');
        }
    }
    ob_end_clean();
    
    // Verify connection
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(503);
        }
        echo json_encode(['success' => false, 'message' => 'Database connection unavailable'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Load session silently
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    ob_end_clean();
    
    // Clear output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Set headers
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    $response = ['success' => false, 'documents' => []];
    
    try {
        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found'];
        } else {
            // CRITICAL FIX: Use centralized getAcademicYear() function consistently
            if (!function_exists('getAcademicYear')) {
                if (file_exists(__DIR__ . '/common_functions.php')) {
                    require_once(__DIR__ . '/common_functions.php');
                }
            }
            if (function_exists('getAcademicYear')) {
                $academic_year = getAcademicYear();
            } else {
                // Fallback calculation - matches getAcademicYear() logic exactly
                $current_year = (int)date('Y');
                $current_month = (int)date('n');
                // July onwards (month >= 7): current_year to current_year+1
                // Below July (month < 7): (current_year-2) to (current_year-1)
                if ($current_month >= 7) {
                    $academic_year = $current_year . '-' . ($current_year + 1);
                } else {
                    $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
                }
            }
            
            // CRITICAL FIX: Query with fallback to check current year AND previous year
            // This handles cases where documents were uploaded in previous academic year
            $batch_query = "SELECT serial_number, file_path, file_name, upload_date, id, academic_year
                           FROM supporting_documents 
                           WHERE dept_id = ? AND page_section = 'executive_development' 
                           AND (academic_year = ? OR academic_year = ?) AND status = 'active'
                           ORDER BY academic_year DESC, id DESC";
            $stmt = mysqli_prepare($conn, $batch_query);
            
            if (!$stmt) {
                $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
            } else {
                // Calculate previous academic year for fallback
                $prev_year = '';
                if (function_exists('getAcademicYear')) {
                    $current_year = (int)date('Y');
                    $current_month = (int)date('n');
                    if ($current_month >= 7) {
                        // Current: 2025-2026, Previous: 2024-2025
                        $prev_year = ($current_year - 1) . '-' . $current_year;
                    } else {
                        // Current: 2024-2025, Previous: 2023-2024
                        $prev_year = ($current_year - 3) . '-' . ($current_year - 2);
                    }
                } else {
                    $prev_year = $academic_year; // Fallback to same year if can't calculate
                }
                mysqli_stmt_bind_param($stmt, 'iss', $dept_id, $academic_year, $prev_year);
                
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    $documents = [];
                    $project_root = dirname(__DIR__);
                    
                    // Track documents by serial number, prefer current year over previous year
                    $documents_found = [];
                    while ($row = mysqli_fetch_assoc($result)) {
                        $srno = (int)$row['serial_number'];
                        $doc_year = $row['academic_year'] ?? '';
                        
                        // Only keep the most recent document for each serial number (prefer current year)
                        if (!isset($documents_found[$srno]) || $doc_year === $academic_year) {
                            // Convert to web-accessible path (robust path conversion like PlacementDetails.php)
                            $file_path = $row['file_path'];
                            $project_root = dirname(__DIR__);
                            
                            // Handle absolute paths
                            if (strpos($file_path, $project_root) === 0) {
                                $file_path = str_replace([$project_root . '/', $project_root . '\\'], '', $file_path);
                            }
                            
                            // Normalize path separators
                            $file_path = str_replace('\\', '/', trim($file_path));
                            $file_path = ltrim($file_path, './\\/');
                            
                            // Convert to web-accessible path
                            if (strpos($file_path, 'uploads/') === 0) {
                                $file_path = '../' . $file_path;
                            } elseif (strpos($file_path, '../') !== 0 && strpos($file_path, 'http') !== 0) {
                                // Reconstruct path if needed
                                $filename = basename($file_path);
                                $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/executive_development/' . $filename;
                            }
                            
                            $documents_found[$srno] = [
                                'success' => true,
                                'file_path' => $file_path,
                                'file_name' => $row['file_name'] ?? basename($file_path),
                                'upload_date' => $row['upload_date'] ?? date('Y-m-d H:i:s'),
                                'document_id' => $row['id']
                            ];
                        }
                    }
                    $documents = $documents_found;
                    
                    mysqli_free_result($result);
                    mysqli_stmt_close($stmt);
                    
                    $response = ['success' => true, 'documents' => $documents];
                } else {
                    mysqli_stmt_close($stmt);
                    $response = ['success' => false, 'message' => 'Query execution failed: ' . mysqli_stmt_error($stmt)];
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle document status check
// CRITICAL: Follow all checklist items to prevent crashes and unnecessary requests
if (isset($_GET['check_doc'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST - prevent any output before JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session and load config FIRST (silently)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    ob_start();
    if (!isset($conn) || !$conn) {
        if (file_exists(__DIR__ . '/../config.php')) {
            require_once(__DIR__ . '/../config.php');
        }
    }
    ob_end_clean();
    
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    ob_end_clean();
    
    // Clear any remaining output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // CRITICAL #4: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Initialize response in variable - build response first, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $srno = (int)($_GET['srno'] ?? 0);
        $dept = $userInfo['DEPT_ID'] ?? 0;
    
    if (!$dept) {
            $response = ['success' => false, 'message' => 'Department ID not found.'];
        } elseif ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Invalid serial number.'];
        } else {
            // CRITICAL FIX: Use centralized getAcademicYear() function consistently
            if (!function_exists('getAcademicYear')) {
                if (file_exists(__DIR__ . '/common_functions.php')) {
                    require_once(__DIR__ . '/common_functions.php');
                }
            }
            if (function_exists('getAcademicYear')) {
                $academic_year = getAcademicYear();
            } else {
                // Fallback calculation - matches getAcademicYear() logic exactly
                $current_year = (int)date('Y');
                $current_month = (int)date('n');
                // July onwards (month >= 7): current_year to current_year+1
                // Below July (month < 7): (current_year-2) to (current_year-1)
                if ($current_month >= 7) {
                    $academic_year = $current_year . '-' . ($current_year + 1);
                } else {
                    $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
                }
            }
            $dept_id = $dept;
            
            // CRITICAL FIX: Query with fallback to check current year AND previous year
            // This handles cases where documents were uploaded in previous academic year
            $get_file_query = "SELECT file_path, file_name, upload_date, id, academic_year 
                              FROM supporting_documents 
                              WHERE dept_id=? AND page_section='executive_development' 
                              AND serial_number=? AND (academic_year=? OR academic_year=?) 
                              AND status='active' 
                              ORDER BY academic_year DESC, id DESC LIMIT 1";
            $stmt = mysqli_prepare($conn, $get_file_query);
            if (!$stmt) {
                $response = ['success' => false, 'message' => 'Database error'];
            } else {
                // Calculate previous academic year for fallback
                $prev_year = '';
                $current_year = (int)date('Y');
                $current_month = (int)date('n');
                if ($current_month >= 7) {
                    // Current: 2025-2026, Previous: 2024-2025
                    $prev_year = ($current_year - 1) . '-' . $current_year;
                } else {
                    // Current: 2024-2025, Previous: 2023-2024
                    $prev_year = ($current_year - 3) . '-' . ($current_year - 2);
                }
                mysqli_stmt_bind_param($stmt, "iiss", $dept_id, $srno, $academic_year, $prev_year);
                if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
                        mysqli_free_result($result);  // CRITICAL: Free result
                        mysqli_stmt_close($stmt);
                        
        $file_path = $row['file_path'];
        $doc_year = $row['academic_year'] ?? $academic_year;
        
        // Convert to web-accessible path (robust path conversion like PlacementDetails.php)
        $project_root = dirname(__DIR__);
        
        // Handle absolute paths
        if (strpos($file_path, $project_root) === 0) {
            $file_path = str_replace([$project_root . '/', $project_root . '\\'], '', $file_path);
        }
        
        // Normalize path separators
        $file_path = str_replace('\\', '/', trim($file_path));
        $file_path = ltrim($file_path, './\\/');
        
        // Convert to web-accessible path
        if (strpos($file_path, 'uploads/') === 0) {
            $file_path = '../' . $file_path;
        } elseif (strpos($file_path, '../') !== 0 && strpos($file_path, 'http') !== 0) {
            // Reconstruct path if needed using document's academic year
            $filename = basename($file_path);
            $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/executive_development/' . $filename;
        }
        
                        $response = [
                            'success' => true,
                            'file_path' => $file_path,
                            'file_name' => $row['file_name'] ?? basename($file_path),
                            'upload_date' => $row['upload_date'] ?? date('Y-m-d H:i:s'),
                            'document_id' => $row['id'] ?? 0
                        ];
    } else {
                        if ($result) {
                            mysqli_free_result($result);  // CRITICAL: Free result even if empty
                        }
                        mysqli_stmt_close($stmt);
                        $response = ['success' => false, 'message' => 'No document found'];
                    }
                } else {
                    mysqli_stmt_close($stmt);
                    $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #2: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
    
if (isset($_POST['submit'])){
    // Validate CSRF token
    if (function_exists('validate_csrf')) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (empty($csrf_token) || !validate_csrf($csrf_token)) {
            echo "<script>alert('Security token validation failed. Please refresh the page and try again.');</script>";
            echo '<script>window.location.href = "ExecutiveDevelopment.php";</script>';
            exit;
        }
    }
    
    // Check if data already exists for this academic year
    $check_existing_query = "SELECT * FROM exec_dev WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $check_stmt = mysqli_prepare($conn, $check_existing_query);
    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, "is", $dept, $A_YEAR);
        mysqli_stmt_execute($check_stmt);
        $check_existing = mysqli_stmt_get_result($check_stmt);
    if ($check_existing && mysqli_num_rows($check_existing) > 0) {
            mysqli_stmt_close($check_stmt);
        echo "<script>alert('Data already exists for academic year $A_YEAR. Form is locked. Use Update button to modify.');</script>";
        echo '<script>window.location.href = "ExecutiveDevelopment.php";</script>';
        exit;
    }
        mysqli_stmt_close($check_stmt);
    }

    // Validate and sanitize input
    $Executive_Programs = max(0, (int)($_POST['Executive_Programs'] ?? 0));
    $Total_Participants = max(0, (int)($_POST['Total_Participants'] ?? 0));
    $Total_Income = max(0, (float)($_POST['Total_Income'] ?? 0));

    $query = "INSERT INTO `exec_dev`(`A_YEAR`, `DEPT_ID`, `NO_OF_EXEC_PROGRAMS`, `TOTAL_PARTICIPANTS`, `TOTAL_INCOME`) 
    VALUES (?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "siiid", $A_YEAR, $dept, $Executive_Programs, $Total_Participants, $Total_Income);
        if (mysqli_stmt_execute($stmt)) {
        $_SESSION['success'] = "Data submitted successfully for academic year $A_YEAR. Form is now locked.";
        $_SESSION['clear_form'] = true; // Flag to clear form
            mysqli_stmt_close($stmt);
        echo '<script>window.location.href = "ExecutiveDevelopment.php";</script>';
        } else {
            mysqli_stmt_close($stmt);
            echo "<script>alert('Woops! There was an error (Contact Admin if it continues).')</script>";
        }
    } else {
        echo "<script>alert('Woops! There was an error (Contact Admin if it continues).')</script>";
    }
}

// Handle update
if (isset($_POST['update'])){
    // Validate CSRF token
    if (function_exists('validate_csrf')) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (empty($csrf_token) || !validate_csrf($csrf_token)) {
            echo "<script>alert('Security token validation failed. Please refresh the page and try again.');</script>";
        echo '<script>window.location.href = "ExecutiveDevelopment.php";</script>';
            exit;
    }
}

    // Validate and sanitize input
    $Executive_Programs = max(0, (int)($_POST['Executive_Programs'] ?? 0));
    $Total_Participants = max(0, (int)($_POST['Total_Participants'] ?? 0));
    $Total_Income = max(0, (float)($_POST['Total_Income'] ?? 0));
        
    $query = "UPDATE `exec_dev` SET 
    NO_OF_EXEC_PROGRAMS = ?,
    TOTAL_PARTICIPANTS = ?,
    TOTAL_INCOME = ?
    WHERE DEPT_ID = ? AND A_YEAR = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iidis", $Executive_Programs, $Total_Participants, $Total_Income, $dept, $A_YEAR);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Data updated successfully for academic year $A_YEAR.";
            mysqli_stmt_close($stmt);
            echo '<script>window.location.href = "ExecutiveDevelopment.php";</script>';
        } else {
            mysqli_stmt_close($stmt);
            echo "<script>alert('Woops! There was an error updating data.');</script>";
        }
    } else {
        echo "<script>alert('Woops! There was an error updating data.');</script>";
    }
}    

// Import from previous year functionality removed as requested    

// Handle clear data action
if (isset($_GET['action']) && $_GET['action'] === 'clear_data') {
    // Delete from main table for current academic year only
    $stmt = mysqli_prepare($conn, "DELETE FROM exec_dev WHERE DEPT_ID = ? AND A_YEAR = ?");
    mysqli_stmt_bind_param($stmt, 'is', $dept, $A_YEAR);
    $main_deleted = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    // Delete uploaded documents using the unified supporting_documents table for current academic year
    $delete_docs_query = "SELECT file_path FROM supporting_documents WHERE dept_id = ? AND page_section = 'executive_development' AND academic_year = ? AND status = 'active'";
    $stmt_docs = mysqli_prepare($conn, $delete_docs_query);
    mysqli_stmt_bind_param($stmt_docs, 'is', $dept, $A_YEAR);
    mysqli_stmt_execute($stmt_docs);
    $docs_result = mysqli_stmt_get_result($stmt_docs);
    
    if ($docs_result) {
        while ($doc = mysqli_fetch_assoc($docs_result)) {
            $file_path = $doc['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        // CRITICAL: Free result set after loop
        mysqli_free_result($docs_result);
    }
    mysqli_stmt_close($stmt_docs);
    
    // Hard delete from unified supporting_documents table for current academic year only
    $delete_docs_query = "DELETE FROM supporting_documents WHERE dept_id = ? AND page_section = 'executive_development' AND academic_year = ?";
    $stmt_docs = mysqli_prepare($conn, $delete_docs_query);
    mysqli_stmt_bind_param($stmt_docs, 'is', $dept, $A_YEAR);
    $docs_deleted = mysqli_stmt_execute($stmt_docs);
    mysqli_stmt_close($stmt_docs);
    
    if ($main_deleted) {
        $_SESSION['success'] = "Data and uploaded documents cleared successfully for academic year $A_YEAR!";
        echo "<script>window.location.href = 'ExecutiveDevelopment.php';</script>";
    } else {
        $_SESSION['error'] = "Error clearing data: " . mysqli_error($conn);
    }
}    

if(isset($_GET['action'])) {
    $action=$_GET['action'];
    if($action == 'delete') {
        // CRITICAL SECURITY FIX: Use prepared statement to prevent SQL injection
        $id = (int)($_GET['ID'] ?? 0);
        if ($id > 0) {
            $delete_stmt = mysqli_prepare($conn, "DELETE FROM exec_dev WHERE ID = ? AND DEPT_ID = ?");
            if ($delete_stmt) {
                mysqli_stmt_bind_param($delete_stmt, 'ii', $id, $dept);
                if (mysqli_stmt_execute($delete_stmt)) {
                    mysqli_stmt_close($delete_stmt);
                    // Clear buffers before redirect
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: ExecutiveDevelopment.php?success=deleted');
                    }
                    exit;
                }
                mysqli_stmt_close($delete_stmt);
            }
        }
        // If delete fails or invalid ID, redirect without error (silent fail for security)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: ExecutiveDevelopment.php');
        }
        exit;
    }
}
?>
<?php require('unified_header.php'); ?>

        <div class="container-fluid">
            <div class="glass-card">
                <form class="modern-form" method="POST" enctype="multipart/form-data" autocomplete="off">
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    <div class="page-header">
                        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                            <div>
                                <h1 class="page-title">
                                    <i class="fas fa-graduation-cap me-3"></i>Executive Development
                                </h1>
                            </div>
                            <a href="export_page_pdf.php?page=ExecutiveDevelopment" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                                <i class="fas fa-file-pdf"></i> Download as PDF
                            </a>
                        </div>
                </div>

                    <!-- Important Instructions -->
                    <div class="alert alert-warning">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i><b>Important Guidelines:</b></h5>
                        <ul class="mb-0">
                            <li><b>No bachelor's programmes should be counted and entered</b></li>
                                    <li><b>Amount received should not include Lodging and Boarding Charges</b></li>
                                    <li><b>The amount mentioned for various year is total amount received through executive education programmes for that particular year</b></li>
                                    <li><b>Enter value(s) in all field(s); if not applicable enter zero[0]</b></li>
                            <li><b>Faculty Development Programmes shall not be entered</b></li>
                    </ul>   
                </div>

                <?php 
                // Display Skip Form Button
                require_once(__DIR__ . '/skip_form_component.php');
                $has_existing_data = ($existing_data !== null);
                displaySkipFormButton('executive_development', 'Executive Development Program', $A_YEAR, $has_existing_data);
                ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($form_locked): ?>
                            <div class="alert alert-info">
                            <i class="fas fa-lock me-2"></i><strong>Form Status:</strong> Data has been submitted for academic year <?php echo $A_YEAR; ?>. 
                                    Form is locked. Click "Update" to modify existing data.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><strong>Form Status:</strong> Form is ready for data entry for academic year <?php echo $A_YEAR; ?>.
                                </div>
                            <?php endif; ?>

                    <!-- Executive Development Program Details -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-chart-line me-2"></i>Program Statistics
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-4">
                <div class="mb-3">
                                    <label class="form-label"><b>Number of Executive Programs *</b></label>
                                <input type="number" name="Executive_Programs" class="form-control" placeholder="Enter Count" 
                                       value="<?php echo $existing_data ? $existing_data['NO_OF_EXEC_PROGRAMS'] : ''; ?>" 
                                           <?php echo $form_locked ? 'readonly disabled' : ''; ?> min="0" required>
                                </div>
                </div>
                            <div class="col-md-4">
                <div class="mb-3">
                                    <label class="form-label"><b>Total Participants *</b></label>
                                    <input type="number" name="Total_Participants" class="form-control" placeholder="Enter Count" 
                                       value="<?php echo $existing_data ? $existing_data['TOTAL_PARTICIPANTS'] : ''; ?>" 
                                           <?php echo $form_locked ? 'readonly disabled' : ''; ?> min="0" required>
                                </div>
                </div>
                            <div class="col-md-4">
                <div class="mb-3">
                                    <label class="form-label"><b>Total Income (₹ in Lakhs) *</b></label>
                                    <input type="number" name="Total_Income" class="form-control" placeholder="Enter Amount in Lakhs (e.g., 1.5 for ₹1.5 Lakhs)" 
                                       value="<?php echo $existing_data ? $existing_data['TOTAL_INCOME'] : ''; ?>" 
                                           <?php echo $form_locked ? 'readonly disabled' : ''; ?> min="0" step="0.01" required>
                                    <div class="form-text">Enter amount in Lakhs (e.g., 1.5 for ₹1.5 Lakhs)</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Document Upload Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-file-pdf me-2"></i>Supporting Documentation
                        </h3>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i><strong>Document Required:</strong> 
                            Number of students enrolled for each Executive Development Program of the department with total male and female and total fee collected for each program
                            </div>

                                <div class="row">
                            <div class="col-md-8">
                                <label class="form-label"><b>Executive Development Program Documentation *</b></label>
                                        <div class="input-group">
                                            <input type="file" class="form-control" id="exec_dev_pdf" name="exec_dev_pdf" accept=".pdf" 
                                                   onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                                            <button type="button" class="btn btn-outline-primary" 
                                            onclick="uploadDocument('exec_dev_pdf', 6)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                                        <i class="fas fa-upload me-2"></i>Upload PDF
                                    </button>
                                        </div>
                                <div class="form-text">Upload PDF file containing detailed program information (Max 10MB)</div>
                                        <div id="exec_dev_pdf_status" class="mt-2"></div>
                                    </div>
                                </div>
                </div>

                    <!-- Action Buttons -->
                            <div class="text-center mt-4">
                                <div class="d-flex flex-wrap justify-content-center gap-3">
                                    <?php if ($form_locked): ?>
                                        <!-- Form is locked - show Update and Clear Data buttons -->
                                        <button type="button" class="btn btn-warning btn-lg px-5" onclick="enableUpdate()">
                                    <i class="fas fa-edit me-2"></i>Update Data
                                        </button>
                                        <button type="submit" name="update" class="btn btn-success btn-lg px-5" id="updateBtn" style="display:none;">
                                            <i class="fas fa-save me-2"></i>Save Changes
                                        </button>
                                        <a href="?action=clear_data" class="btn btn-danger btn-lg px-5" 
                                           onclick="return confirmClearData()">
                                            <i class="fas fa-trash me-2"></i>Clear Data
                                        </a>
                                    <?php else: ?>
                                        <!-- Form is unlocked - show Submit and Import buttons -->
                                        <button type="submit" name="submit" class="btn btn-primary btn-lg px-5">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Data
                                        </button>
                                        <?php 
                                        // Check if previous year data exists for import
                                        // CRITICAL SECURITY FIX: Use prepared statement to prevent SQL injection
                                        $previous_year = ($current_year - 2) . '-' . ($current_year - 1);
                                        $check_previous_stmt = mysqli_prepare($conn, "SELECT * FROM exec_dev WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
                                        $check_previous = false;
                                        if ($check_previous_stmt) {
                                            mysqli_stmt_bind_param($check_previous_stmt, "is", $dept, $previous_year);
                                            if (mysqli_stmt_execute($check_previous_stmt)) {
                                                $check_previous = mysqli_stmt_get_result($check_previous_stmt);
                                            }
                                        }
                                        if ($check_previous && mysqli_num_rows($check_previous) > 0): 
                                            // Free result and close statement after use
                                            mysqli_free_result($check_previous);
                                            mysqli_stmt_close($check_previous_stmt);
                                        ?>
                                            <button type="submit" name="import_previous" class="btn btn-info btn-lg px-5" 
                                                    onclick="return confirmImport()">
                                                <i class="fas fa-download me-2"></i>Import from <?php echo $previous_year; ?>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
            </form>
        </div>

    </div>

    <style>
        .form-section {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }

        .section-title {
            color: #667eea;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #667eea;
            display: flex;
            align-items: center;
        }

        .section-title i {
            color: #764ba2;
            margin-right: 0.5rem;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // PDF validation function
    function validatePDF(input) {
        const file = input.files[0];
        if (file) {
            const fileExtension = file.name.split('.').pop().toLowerCase();
            if (fileExtension !== 'pdf') {
                alert('Please select a PDF file only.');
                input.value = '';
                return false;
            }
            if (file.size > 10 * 1024 * 1024) { // 10MB limit
                alert('File size must be less than 10MB.');
                input.value = '';
                return false;
            }
        }
        return true;
    }

    // Upload document function
    function uploadDocument(fileId, srno) {
        const fileInput = document.getElementById(fileId);
        const statusDiv = document.getElementById(fileId + '_status');
        
        // Block upload while form is locked; require clicking Update first
        if (fileInput.disabled) {
            alert('Form is locked. Click "Update Data" to enable uploading and editing.');
            return;
        }
        if (!fileInput.files[0]) {
            alert('Please select a file to upload.');
            return;
        }
        
        if (!validatePDF(fileInput)) {
            return;
        }
        
        // Get CSRF token
        let csrfToken = '';
        const metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken && metaToken.content) {
            csrfToken = metaToken.content.trim();
        } else {
            const formToken = document.querySelector('input[name="csrf_token"]');
            if (formToken && formToken.value) {
                csrfToken = formToken.value.trim();
            }
        }
        
        if (!csrfToken) {
            alert('Security token not found. Please refresh the page and try again.');
            return;
        }
        
        const formData = new FormData();
        formData.append('upload_document', '1');
        formData.append('file_id', fileId);
        formData.append('srno', srno);
        formData.append('document', fileInput.files[0]);
        formData.append('csrf_token', csrfToken);
        
        // Show loading state
        statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Uploading...';
        statusDiv.className = 'mt-2 text-info';
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // CRITICAL #3: Handle empty responses in JS - return object, don't throw
                return response.text().then(text => {
                const trimmed = text.trim();
                
                // If empty response, return error object instead of throwing
                if (!trimmed || trimmed === '') {
                    return { success: false, message: 'Upload failed: Empty server response' };
                }
                
                // Try to parse as JSON
                try {
                    return JSON.parse(trimmed);
                } catch (e) {
                    // CRITICAL #5: Return error response instead of throwing
                    return { success: false, message: 'Upload failed: Invalid server response. Please refresh and try again.' };
                }
            });
        })
                .then(data => {
            // CRITICAL #3: Handle null/undefined data gracefully
            if (!data || typeof data !== 'object') {
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Upload failed: Invalid server response</span>';
                statusDiv.className = 'mt-2 text-danger';
                return;
            }
            
            if (data.success) {
                // Ensure file path is web-accessible (same robust logic as StudentSupport.php)
                let viewHref = data.file_path || '';
                
                // Convert absolute paths to relative web paths (same as StudentSupport.php)
                if (viewHref && (viewHref.startsWith('/home/') || viewHref.startsWith('C:/') || viewHref.startsWith('C:\\'))) {
                    // Extract relative path from absolute path (case-insensitive match for directory names)
                    const match = viewHref.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/i);
                    if (match) {
                        viewHref = '../' + match[1];
                    }
                } else if (viewHref && !viewHref.startsWith('../') && !viewHref.startsWith('http')) {
                    if (viewHref.startsWith('uploads/')) {
                        viewHref = '../' + viewHref;
                    } else if (viewHref.includes('uploads/')) {
                        const match = viewHref.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)/i);
                        if (match) {
                            viewHref = '../' + match[1];
                        }
                    }
                }
                
                statusDiv.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        <span class="text-success">Document uploaded successfully</span>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"')}', ${srno})">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                        <a href="${String(viewHref).replace(/"/g, '&quot;').replace(/'/g, '&#39;')}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                `;
                statusDiv.className = 'mt-2 text-success';
                
                // Clear the file input
                fileInput.value = '';
            } else {
                statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">${data.message}</span>`;
                statusDiv.className = 'mt-2 text-danger';
            }
        })
        .catch(error => {
            // CRITICAL #5: Handle errors gracefully - return object, don't throw
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Upload failed: ${error.message || 'Unknown error'}</span>`;
            statusDiv.className = 'mt-2 text-danger';
        });
    }

    // Delete document function
    function deleteDocument(fileId, srno) {
        if (!confirm('Are you sure you want to delete this document?')) {
            return;
        }
        
        // Get CSRF token
        let csrfToken = '';
        const metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken && metaToken.content) {
            csrfToken = metaToken.content.trim();
        } else {
            const formToken = document.querySelector('input[name="csrf_token"]');
            if (formToken && formToken.value) {
                csrfToken = formToken.value.trim();
            }
        }
        
        if (!csrfToken) {
            alert('Security token not found. Please refresh the page and try again.');
            return;
        }
        
        const statusDiv = document.getElementById(fileId + '_status');
        statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
        statusDiv.className = 'mt-2 text-info';
        
        // Convert to POST request with CSRF token
        const formData = new FormData();
        formData.append('delete_doc', '1');
        formData.append('srno', srno);
        formData.append('csrf_token', csrfToken);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // CRITICAL #3: Handle empty responses in JS - return object, don't throw
                return response.text().then(text => {
                const trimmed = text.trim();
                
                // If empty response, return error object instead of throwing
                if (!trimmed || trimmed === '') {
                    return { success: false, message: 'Delete failed: Empty server response' };
                }
                
                // Try to parse as JSON
                try {
                    return JSON.parse(trimmed);
                } catch (e) {
                    // CRITICAL #5: Return error response instead of throwing
                    return { success: false, message: 'Delete failed: Invalid server response' };
                }
            });
        })
        .then(data => {
            // CRITICAL #3: Handle null/undefined data gracefully
            if (!data || typeof data !== 'object') {
                statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                statusDiv.className = 'mt-2 text-muted';
                return;
            }
            
            if (data.success) {
                statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                statusDiv.className = 'mt-2 text-muted';
            } else {
                statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">${data.message || 'Delete failed'}</span>`;
                statusDiv.className = 'mt-2 text-danger';
            }
        })
        .catch(error => {
            // CRITICAL #5: Handle errors gracefully - return object, don't throw
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Delete failed: ${error.message || 'Unknown error'}</span>`;
            statusDiv.className = 'mt-2 text-danger';
        });
    }

    // Track active check requests to prevent duplicate calls and unlimited database requests
    const activeDocumentChecks = new Set();
    
    // Check document status function with guards
    function checkDocumentStatus(fileId, srno) {
        const statusDiv = document.getElementById(fileId + '_status');
        if (!statusDiv) {
            return;
        }
        
        // CRITICAL: Prevent duplicate checks for the same document - prevent unlimited database requests
        const checkKey = `${fileId}_${srno}`;
        if (activeDocumentChecks.has(checkKey)) {
            return; // Already checking this document
        }
        
        // Add to active checks
        activeDocumentChecks.add(checkKey);
        
        // CRITICAL: Add request timeout with AbortController
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
        
        executeWithRateLimit(() => {
            return fetch(`?check_doc=1&srno=${srno}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                cache: 'no-cache',
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    activeDocumentChecks.delete(checkKey);
                    return { success: false, message: 'HTTP error: ' + response.status };
                }
                // CRITICAL #3: Handle empty responses in JS - return object, don't throw
                return response.text().then(text => {
                    const trimmed = text.trim();
                    
                    // If empty response, return error object instead of throwing
                    if (!trimmed || trimmed === '') {
                        activeDocumentChecks.delete(checkKey);
                        return { success: false, message: 'No document found' };
                    }
                    
                    // Try to parse as JSON
                    try {
                        const json = JSON.parse(trimmed);
                        activeDocumentChecks.delete(checkKey);
                        return json;
                    } catch (e) {
                        // CRITICAL #5: Return error response instead of throwing
                        activeDocumentChecks.delete(checkKey);
                        return { success: false, message: 'Invalid server response' };
                    }
                }).catch(textError => {
                    // CRITICAL: Handle text() parsing errors
                    activeDocumentChecks.delete(checkKey);
                    return { success: false, message: 'Check failed: Error reading server response' };
                });
            })
            .catch(error => {
                clearTimeout(timeoutId);
                activeDocumentChecks.delete(checkKey);
                if (error.name === 'AbortError') {
                    return { success: false, message: 'Request timeout' };
                }
                return { success: false, message: 'Network error: ' + (error.message || 'Unknown error') };
            });
        })
        .catch(error => {
            // CRITICAL: Remove from active checks on error - prevent loops
            activeDocumentChecks.delete(checkKey);
            // Return error object instead of throwing
            return { 
                ok: false, 
                text: () => Promise.resolve(JSON.stringify({ 
                    success: false, 
                    message: 'Network error: ' + (error.message || 'Failed to connect to server') 
                })) 
            };
        })
        .then(data => {
            // CRITICAL #3: Handle null/undefined data gracefully
            if (!data || typeof data !== 'object') {
                return;
            }
            
            if (data.success && data.file_path) {
                const fileInput = document.getElementById(fileId);
                const isFormLocked = fileInput ? fileInput.disabled : <?php echo $form_locked ? 'true' : 'false'; ?>;
                
                // Ensure file path is web-accessible (same robust logic as StudentSupport.php)
                let href = data.file_path || '';
                
                // Convert absolute paths to relative web paths (same as StudentSupport.php)
                if (href && (href.startsWith('/home/') || href.startsWith('C:/') || href.startsWith('C:\\'))) {
                    // Extract relative path from absolute path (case-insensitive match for directory names)
                    const match = href.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/i);
                    if (match) {
                        href = '../' + match[1];
                    }
                } else if (href && !href.startsWith('../') && !href.startsWith('http')) {
                    if (href.startsWith('uploads/')) {
                        href = '../' + href;
                    } else if (href.includes('uploads/')) {
                        const match = href.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)/i);
                        if (match) {
                            href = '../' + match[1];
                        }
                    }
                }
                
                let actionButtons = '';
                if (isFormLocked) {
                    // Form is locked, only show view button
                    // CRITICAL: Escape href for HTML attribute to prevent XSS and syntax errors
                    const escapedHref1 = String(href).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    actionButtons = `
                        <a href="${escapedHref1}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                            <i class="fas fa-eye"></i> View
                        </a>
                    `;
                } else {
                    // Form is unlocked, show delete and view buttons
                    // CRITICAL: Escape fileId and href to prevent JavaScript syntax errors
                    const escapedFileId4 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
                    const escapedHref = String(href).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    actionButtons = `
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId4}', ${srno})">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                        <a href="${escapedHref}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                            <i class="fas fa-eye"></i> View
                        </a>
                    `;
                }
                
                statusDiv.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        <span class="text-success">Document uploaded</span>
                        ${actionButtons}
                    </div>
                `;
                statusDiv.className = 'mt-2 text-success';
            } else {
                statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                statusDiv.className = 'mt-2 text-muted';
            }
        })
        .catch(error => {
            // CRITICAL: Remove from active checks on ANY error - prevent loops
            activeDocumentChecks.delete(checkKey);
            // Return resolved promise with error object - prevent unhandled promise rejection
            return Promise.resolve({ success: false, message: 'Network error: ' + (error.message || 'Unknown error') });
        })
        .then(data => {
            // CRITICAL: Handle null/undefined data gracefully
            if (!data || typeof data !== 'object') {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
                return;
            }
            
            // Process the data (existing logic continues here)
            if (data.success && data.file_path) {
                const fileInput = document.getElementById(fileId);
                const isFormLocked = fileInput ? fileInput.disabled : <?php echo $form_locked ? 'true' : 'false'; ?>;
                
                // Ensure file path is web-accessible (same robust logic as StudentSupport.php)
                let href = data.file_path || '';
                
                // Convert absolute paths to relative web paths (same as StudentSupport.php)
                if (href && (href.startsWith('/home/') || href.startsWith('C:/') || href.startsWith('C:\\'))) {
                    // Extract relative path from absolute path (case-insensitive match for directory names)
                    const match = href.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/i);
                    if (match) {
                        href = '../' + match[1];
                    }
                } else if (href && !href.startsWith('../') && !href.startsWith('http')) {
                    if (href.startsWith('uploads/')) {
                        href = '../' + href;
                    } else if (href.includes('uploads/')) {
                        const match = href.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)/i);
                        if (match) {
                            href = '../' + match[1];
                        }
                    }
                }
                
                let actionButtons = '';
                if (isFormLocked) {
                    // Form is locked, only show view button
                    // CRITICAL: Escape href for HTML attribute to prevent XSS and syntax errors
                    const escapedHref1 = String(href).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    actionButtons = `
                        <a href="${escapedHref1}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                            <i class="fas fa-eye"></i> View
                        </a>
                    `;
                } else {
                    // Form is unlocked, show delete and view buttons
                    // CRITICAL: Escape fileId and href to prevent JavaScript syntax errors
                    const escapedFileId4 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
                    const escapedHref = String(href).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    actionButtons = `
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId4}', ${srno})">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                        <a href="${escapedHref}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                            <i class="fas fa-eye"></i> View
                        </a>
                    `;
                }
                
                statusDiv.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        <span class="text-success">Document uploaded</span>
                        ${actionButtons}
                    </div>
                `;
                statusDiv.className = 'mt-2 text-success';
            } else {
                statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                statusDiv.className = 'mt-2 text-muted';
            }
        });
    }

    // CRITICAL PERFORMANCE FIX: Load ALL documents in ONE batch request instead of individual requests
    // This prevents unlimited database requests and server crashes
    // ============================================================================
    // GLOBAL REQUEST LIMITING - Prevent connection exhaustion with 20+ users
    // ============================================================================
    let globalActiveRequests = 0;
    const MAX_CONCURRENT_REQUESTS = 3; // Maximum 3 simultaneous requests
    const requestQueue = [];
    
    function processQueuedRequests() {
        if (requestQueue.length === 0 || globalActiveRequests >= MAX_CONCURRENT_REQUESTS) {
            return;
        }
        
        const nextRequest = requestQueue.shift();
        globalActiveRequests++;
        
        nextRequest.fn()
            .then(nextRequest.resolve)
            .catch(nextRequest.reject)
            .finally(() => {
                globalActiveRequests--;
                setTimeout(processQueuedRequests, 100);
            });
    }
    
    function executeWithRateLimit(requestFn) {
        return new Promise((resolve, reject) => {
            if (globalActiveRequests >= MAX_CONCURRENT_REQUESTS) {
                // Queue the request
                requestQueue.push({
                    fn: requestFn,
                    resolve: resolve,
                    reject: reject
                });
                setTimeout(processQueuedRequests, 100);
            } else {
                globalActiveRequests++;
                requestFn()
                    .then(resolve)
                    .catch(reject)
                    .finally(() => {
                        globalActiveRequests--;
                        setTimeout(processQueuedRequests, 100);
                    });
            }
        });
    }
    
    let documentsLoading = false;
    let documentsLoadAttempted = false;

    function loadExistingDocuments() {
        if (documentsLoading || documentsLoadAttempted) {
            return; 
        }
        documentsLoading = true;
        documentsLoadAttempted = true;
        
        // CRITICAL: Add request timeout with AbortController
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
        
        // CRITICAL: Use consolidated endpoint with rate limiting - ONE request instead of individual calls
        executeWithRateLimit(() => {
            return fetch('?check_all_docs=1', {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                cache: 'no-cache',
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    return { success: false, message: 'HTTP error: ' + response.status, documents: {} };
                }
                return response.text().then(text => {
                    const trimmed = text.trim();
                    if (!trimmed) {
                        return { success: false, message: 'Empty server response', documents: {} };
                    }
                    try {
                        return JSON.parse(trimmed);
                    } catch (e) {
                        return { success: false, message: 'Invalid server response', documents: {} };
                    }
                });
            })
            .catch(error => {
                clearTimeout(timeoutId);
                if (error.name === 'AbortError') {
                    return { success: false, message: 'Request timeout', documents: {} };
                }
                return { success: false, message: 'Network error: ' + (error.message || 'Unknown error'), documents: {} };
            });
        })
        .then(data => {
            if (data.success && data.documents) {
                // Map of fileId to srno for updating UI
                const documentMap = {
                    'exec_dev_pdf': 6
                };
    
                // Update all document statuses from batch response
                // data.documents is an object keyed by serial_number (srno)
                Object.keys(documentMap).forEach(fileId => {
                    const srno = documentMap[fileId];
                    const docData = data.documents[srno];
                    if (docData && docData.success) {
                        const statusDiv = document.getElementById(fileId + '_status');
                        if (statusDiv) {
                            updateDocumentStatusUI(fileId, srno, docData.file_path, docData.file_name);
                        }
                    }
                });
            } else {
                console.error('Error loading all documents:', data.message || 'Unknown error');
            }
        })
        .catch(error => {
            console.error('Network error loading all documents:', error);
        })
        .finally(() => {
            documentsLoading = false;
        });
    }

    // Helper function to update document status UI from consolidated response
    function updateDocumentStatusUI(fileId, srno, filePath, fileName) {
        const statusDiv = document.getElementById(fileId + '_status');
        if (!statusDiv) return;

        const fileInput = document.getElementById(fileId);
        const isFormLocked = fileInput ? fileInput.disabled : <?php echo $form_locked ? 'true' : 'false'; ?>;
        
        let actionButtons = '';
        if (isFormLocked) {
            // Form is locked, only show view button
            actionButtons = '';
        } else {
            // Form is unlocked, show delete and view buttons
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            actionButtons = `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                <i class="fas fa-trash"></i> Delete
            </button>`;
        }
        
        let viewPath = filePath || '';
        // Normalize path for web access (same robust logic as StudentSupport.php)
        if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
            // Extract relative path from absolute path (case-insensitive match)
            const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/i);
            if (match) {
                viewPath = '../' + match[1];
            }
        } else if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http')) {
            if (viewPath.startsWith('uploads/')) {
                viewPath = '../' + viewPath;
            } else if (viewPath.includes('uploads/')) {
                const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)/i);
                if (match) {
                    viewPath = '../' + match[1];
                }
            }
        }
        
        // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
        const escapedViewPath = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
        const viewButton = escapedViewPath ? `<a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
                <i class="fas fa-eye"></i> View
            </a>` : '';
        
        statusDiv.innerHTML = `
            <div class="d-flex align-items-center flex-wrap">
                <i class="fas fa-check-circle text-success me-2"></i>
                <span class="text-success me-2">Document uploaded</span>
                ${actionButtons}
                ${viewButton}
            </div>
        `;
        statusDiv.className = 'mt-2 text-success';
    }

    // Function to clear form after successful submission
    function clearForm() {
        const form = document.querySelector('form');
        if (form) {
            form.reset();
            
            // Clear file inputs specifically
            const fileInputs = document.querySelectorAll('input[type="file"]');
            fileInputs.forEach(input => {
                input.value = '';
            });
            
            // Clear file status displays
            const statusDivs = document.querySelectorAll('[id$="_status"]');
            statusDivs.forEach(div => {
                div.innerHTML = '<span class="text-muted">No document uploaded</span>';
                div.className = 'mt-2 text-muted';
            });
        }
    }

    // CRITICAL: Prevent duplicate page initialization
    let pageInitialized = false;

    // Initialize on page load - SINGLE initialization point
    function initializePage() {
        if (pageInitialized) {
            return; // Already initialized - prevent duplicate calls
        }
        pageInitialized = true;
        
        loadExistingDocuments();
        
        // Clear form if it was successfully submitted
        <?php if (isset($_SESSION['clear_form'])): ?>
            clearForm();
            <?php unset($_SESSION['clear_form']); ?>
        <?php endif; ?>
    }

    // Use window.onload as primary initialization
    window.onload = function() {
        initializePage();
    };

    // Use DOMContentLoaded as fallback ONLY if window.onload hasn't fired
    document.addEventListener('DOMContentLoaded', function() {
        // Only initialize if not already initialized (window.onload might fire first)
        if (!pageInitialized) {
            // Small delay to ensure window.onload has a chance to fire first
            setTimeout(function() {
                if (!pageInitialized) {
                    initializePage();
                }
            }, 50);
        }
    });

    // Confirm clear data function
    function confirmClearData() {
        if (confirm('Are you sure you want to clear all data for academic year <?php echo $A_YEAR; ?>? This action cannot be undone!')) {
            return true;
        }
        return false;
    }

    // Enable update function
    function enableUpdate() {
        // Enable all form inputs
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.removeAttribute('readonly');
            input.disabled = false;
            input.style.pointerEvents = 'auto';
            input.style.cursor = 'text';
            input.style.backgroundColor = '#fff';
        });
        // Also enable any disabled buttons (e.g., Upload button)
        const buttons = document.querySelectorAll('button[disabled]');
        buttons.forEach(btn => {
            btn.disabled = false;
        });
        
        // Show Save Changes button, hide Update button
        document.getElementById('updateBtn').style.display = 'inline-block';
        document.querySelector('button[onclick="enableUpdate()"]').style.display = 'none';
        
        alert('Form is now editable. Make your changes and click "Save Changes" to update.');
    }

    // Clear file status displays
    function clearFileStatusDisplays() {
        const statusDivs = document.querySelectorAll('[id$="_status"]');
        statusDivs.forEach(div => {
            div.innerHTML = '<span class="text-muted">No document uploaded</span>';
            div.className = 'mt-2 text-muted';
        });
    }
    </script>
</body>
</html>

<?php
require "unified_footer.php";
?>