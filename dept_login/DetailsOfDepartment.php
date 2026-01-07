<?php
// DetailsOfDepartment.php - Department Details Form
require('session.php');

// Start output buffering immediately to prevent accidental output before headers
if (ob_get_level() === 0) {
    ob_start();
}

// Handle PDF uploads FIRST - before any HTML output
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_document'])) {
    // Disable error display
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Clear output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Ensure session is started (session.php should have done this, but double-check)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
    if (!isset($conn) || !$conn) {
    require_once('../config.php');
    }
    
    // Load CSRF utilities
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        // Ensure CSRF token is generated in session before validation
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if it doesn't exist
        }
    }
    
    header('Content-Type: application/json');
    
    try {
        // Validate CSRF token
        if (function_exists('validate_csrf')) {
            $csrf_token = $_POST['csrf_token'] ?? '';
            if (empty($csrf_token)) {
                throw new Exception('Security token missing. Please refresh the page and try again.');
            }
            if (!validate_csrf($csrf_token)) {
                throw new Exception('Security token validation failed. Please refresh the page and try again.');
            }
        }
        
        // Get department ID from session (already verified in session.php)
        $dept = $userInfo['DEPT_ID'];
        
        if (!$dept) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }
        $file_id = $_POST['file_id'] ?? '';
        $srno = (int)($_POST['srno'] ?? 0);
        
        // Validate required fields
        if (empty($file_id) || $srno <= 0) {
            throw new Exception('File ID and serial number are required.');
        }
        
        // Validate file upload
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error occurred.');
        }
        
        $file = $_FILES['document'];
        
        // Validate file type (extension + MIME)
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_extension !== 'pdf') {
            throw new Exception('Only PDF files are allowed.');
        }
        
        // Validate MIME type using finfo for security
        if (function_exists('finfo_open')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if ($mime !== 'application/pdf') {
                throw new Exception('Invalid file type. Only PDF documents are allowed.');
            }
        }
        
        // Validate file size (5MB limit)
        $max_size = 5 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            throw new Exception('File size must be less than 5MB.');
        }
        
        // Calculate academic year - use centralized function if available
        if (function_exists('getAcademicYear')) {
            $A_YEAR = getAcademicYear();
        } else {
            // Fallback calculation - matches getAcademicYear() logic
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            if ($current_month >= 7) {
                $A_YEAR = $current_year . '-' . ($current_year + 1);
            } else {
                $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
            }
        }
        
        // Route through common_upload_handler.php
        require_once(__DIR__ . '/common_upload_handler.php');
            
        // Set global variables for common_upload_handler.php
        $GLOBALS['dept_id'] = $dept;
        $GLOBALS['A_YEAR'] = $A_YEAR;
        
        // Document titles mapping
                $document_titles = [
                    1 => 'Permanent Faculties with PhD Documentation',
            2 => 'Adhoc Faculties with PhD Documentation',
            10 => 'Faculty Positions Supporting Documentation'
        ];
        $document_title = $document_titles[$srno] ?? 'Department Documentation';
        
        // Use common upload handler
        // Structure: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/details_of_department/
        $result = handleDocumentUpload('details_dept', 'Department Details', [
            'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/details_of_department/",
            'max_size' => 5,
            'document_title' => $document_title,
            'srno' => $srno,
            'file_id' => $file_id
        ]);
        
        // CRITICAL: Ensure result is an array and normalize file path
        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'Invalid response from upload handler'];
        } else {
            // Ensure file path has ../ prefix for web access from dept_login directory
            if ($result['success'] && isset($result['file_path'])) {
                $web_path = $result['file_path'];
                // Normalize path separators first
                $web_path = str_replace('\\', '/', $web_path);
                
                // If path doesn't start with ../ and starts with uploads/, add ../ prefix
                if (strpos($web_path, '../') !== 0 && strpos($web_path, 'uploads/') === 0) {
                    $web_path = '../' . $web_path;
                } elseif (strpos($web_path, dirname(__DIR__)) === 0) {
                    // If absolute path, convert to relative
                    $project_root = dirname(__DIR__);
                    $web_path = str_replace($project_root . '/', '../', $web_path);
                    $web_path = str_replace('\\', '/', $web_path);
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
            header('X-Content-Type-Options: nosniff');
        }
        
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit();
        
    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
        exit();
    }
}

// Handle PDF deletion - BEFORE unified_header (convert to POST for security)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_doc'])) {
    // CRITICAL: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session and load config FIRST (silently)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    ob_start();
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
        ob_end_clean();
    
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
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
        // Get srno from POST (not GET) since this is a POST request
        $srno = (int)($_POST['srno'] ?? 0);
        
        if ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Serial number is required for deletion.'];
        } else {
        // Get department ID from session (already verified in session.php)
            $dept_id = $userInfo['DEPT_ID'] ?? 0;
        
        if (!$dept_id) {
                $response = ['success' => false, 'message' => 'Department ID not found. Please contact administrator.'];
        } else {
                // Use the same academic year logic as the main page
                $current_year = (int)date("Y");
                $current_month = (int)date("n");
                if (function_exists('getAcademicYear')) {
                $academic_year = getAcademicYear();
            } else {
                // Fallback calculation - matches getAcademicYear() logic
                if ($current_month >= 7) {
                    $academic_year = $current_year . '-' . ($current_year + 1);
                } else {
                    $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
                }
            }
        
        // Get file path from database
        $get_file_query = "SELECT file_path FROM supporting_documents 
            WHERE academic_year = ? AND dept_id = ? AND page_section = 'details_dept' 
                    AND serial_number = ? AND status = 'active' LIMIT 1";
        
        $stmt = mysqli_prepare($conn, $get_file_query);
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
                } else {
        mysqli_stmt_bind_param($stmt, "sii", $academic_year, $dept_id, $srno);
                    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $file_path = $row['file_path'];
                            mysqli_free_result($result);  // CRITICAL: Free result
                            mysqli_stmt_close($stmt);
                            
        $project_root = dirname(__DIR__);
        
        // Convert file path to absolute path for deletion
        $file_physical = $file_path;
        
        // Handle different path formats stored in database
        if (strpos($file_path, $project_root) === 0) {
            // Already absolute path
            $file_physical = $file_path;
        } elseif (strpos($file_path, '../') === 0) {
            // Relative path with ../ prefix
            $file_physical = $project_root . '/' . str_replace('../', '', $file_path);
        } elseif (strpos($file_path, 'uploads/') === 0) {
            // Path starting with uploads/
            $file_physical = $project_root . '/' . $file_path;
        } else {
            // Try with project root
            $file_physical = $project_root . '/' . ltrim($file_path, '/');
        }
        
        // Normalize path separators
        $file_physical = str_replace('\\', '/', $file_physical);
        
        // Delete file from filesystem if it exists
        if ($file_path && file_exists($file_physical)) {
            @unlink($file_physical);
        } else {
            // Try alternative path construction
            $filename = basename($file_path);
            $alt_path = $project_root . "/uploads/{$academic_year}/DEPARTMENT/{$dept_id}/details_of_department/{$filename}";
            $alt_path = str_replace('\\', '/', $alt_path);
            if (file_exists($alt_path)) {
                @unlink($alt_path);
            }
        }
        
            // Soft delete from database
            $delete_query = "UPDATE supporting_documents 
                SET status = 'deleted', updated_date = CURRENT_TIMESTAMP 
                WHERE academic_year = ? AND dept_id = ? AND page_section = 'details_dept' 
                AND serial_number = ? AND status = 'active'";
            
                            $delete_stmt = mysqli_prepare($conn, $delete_query);
                            if (!$delete_stmt) {
                                $response = ['success' => false, 'message' => 'Database error: Failed to prepare delete query'];
        } else {
                                mysqli_stmt_bind_param($delete_stmt, "sii", $academic_year, $dept_id, $srno);
                                if (mysqli_stmt_execute($delete_stmt)) {
                                    $response = ['success' => true, 'message' => 'Document deleted successfully.'];
                                } else {
                                    $response = ['success' => false, 'message' => 'Database error: Failed to execute delete query'];
                                }
                                mysqli_stmt_close($delete_stmt);  // CRITICAL: Close statement
        }
    } else {
                            if ($result) {
                                mysqli_free_result($result);  // CRITICAL: Free result even if empty
                            }
                            mysqli_stmt_close($stmt);
                            $response = ['success' => false, 'message' => 'Document not found.'];
    }
                    } else {
                        mysqli_stmt_close($stmt);
                        $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
                    }
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()];
    } catch (Error $e) {
        $response = ['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()];
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
// This replaces 3 individual queries with a single efficient query
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
            // Use centralized function for academic year
            if (function_exists('getAcademicYear')) {
                $academic_year = getAcademicYear();
            } else {
                // Fallback calculation - matches getAcademicYear() logic
                $current_year = (int)date('Y');
                $current_month = (int)date('n');
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
                           WHERE dept_id = ? AND page_section = 'details_dept' 
                           AND (academic_year = ? OR academic_year = ?) AND status = 'active'
                           ORDER BY academic_year DESC, id DESC";
            $stmt = mysqli_prepare($conn, $batch_query);
            
            if (!$stmt) {
                $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
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
                                // Reconstruct path if needed using document's academic year
                                $filename = basename($file_path);
                                $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/details_of_department/' . $filename;
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

// Handle document status check - BEFORE unified_header
// CRITICAL: Follow all checklist items to prevent crashes and unnecessary requests
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['check_doc'])) {
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
        require_once(__DIR__ . '/../config.php');
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
        
        if ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Serial number is required.'];
        } else {
        // Get department ID from session (already verified in session.php)
            $dept_id = $userInfo['DEPT_ID'] ?? 0;
        
        if (!$dept_id) {
                $response = ['success' => false, 'message' => 'Department ID not found. Please contact administrator.'];
        } else {
                // Use the same academic year logic as the main page
                $current_year = (int)date("Y");
                $current_month = (int)date("n");
                if (function_exists('getAcademicYear')) {
                $academic_year = getAcademicYear();
            } else {
                // Fallback calculation - matches getAcademicYear() logic
                if ($current_month >= 7) {
                    $academic_year = $current_year . '-' . ($current_year + 1);
                } else {
                    $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
                }
            }
        
        // CRITICAL FIX: Query with fallback to check current year AND previous year
                $get_file_query = "SELECT file_path, file_name, upload_date, id, academic_year 
                                  FROM supporting_documents 
                                  WHERE dept_id = ? AND page_section = 'details_dept' 
                                  AND serial_number = ? AND (academic_year = ? OR academic_year = ?) 
                                  AND status = 'active' 
                                  ORDER BY academic_year DESC, id DESC LIMIT 1";
        
        $stmt = mysqli_prepare($conn, $get_file_query);
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
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
        $doc_year = $row['academic_year'] ?? $academic_year;
                            mysqli_free_result($result);  // CRITICAL: Free result
                            mysqli_stmt_close($stmt);
                            
        // Ensure file_path is a relative web path (robust path conversion like PlacementDetails.php)
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
            // Reconstruct path if needed using document's academic year
            $filename = basename($file_path);
            $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/details_of_department/' . $filename;
        }
        
        // Remove any leading ../ or ./ if present (legacy cleanup)
        $file_path = ltrim($file_path, './');
        if (strpos($file_path, '../') === 0) {
            $file_path = substr($file_path, 3);
        }
        
        // Ensure path starts with ../ for web access from dept_login directory
        if (strpos($file_path, 'uploads/') === 0) {
            $file_path = '../' . $file_path;
        } elseif (strpos($file_path, '../') !== 0 && strpos($file_path, 'http') !== 0 && strpos($file_path, '/') !== 0) {
            // Reconstruct path if needed
            $filename = basename($file_path);
            $file_path = '../uploads/' . $academic_year . '/DEPARTMENT/' . $dept_id . '/details_of_department/' . $filename;
        }
        
        // Normalize path separators
        $file_path = str_replace('\\', '/', $file_path);
        
        // CRITICAL: Ensure path is web-accessible (already has ../ prefix if needed)
        // Don't add another ../ prefix if it already exists
        
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
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Check failed: ' . $e->getMessage()];
    } catch (Error $e) {
        $response = ['success' => false, 'message' => 'Check failed: ' . $e->getMessage()];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #2: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// MAIN FORM HANDLING - MUST BE BEFORE unified_header.php
// ============================================================================

// Handle form submission - BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    // Disable error display
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Clear output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
    if (!isset($conn) || !$conn) {
    require_once('../config.php');
    }
    
    // Load common functions for getAcademicYear() (matches profile.php)
    if (file_exists(__DIR__ . '/common_functions.php')) {
        require_once(__DIR__ . '/common_functions.php');
    } elseif (file_exists(__DIR__ . '/../common_functions.php')) {
        require_once(__DIR__ . '/../common_functions.php');
    }
    
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load CSRF utilities
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        // Ensure CSRF token is generated in session before validation
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if it doesn't exist
        }
    }
    
    header('Content-Type: application/json');
    
    try {
        // Validate CSRF token
        if (function_exists('validate_csrf')) {
            $csrf_token = $_POST['csrf_token'] ?? '';
            if (empty($csrf_token)) {
                throw new Exception('Security token missing. Please refresh the page and try again.');
            }
            if (!validate_csrf($csrf_token)) {
                throw new Exception('Security token validation failed. Please refresh the page and try again.');
            }
        }
        
        // Get department ID
        $admin_username = $_SESSION['admin_username'] ?? '';
        if (empty($admin_username)) {
            throw new Exception('Session expired. Please login again.');
        }
        
        $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ?";
        $stmt = mysqli_prepare($conn, $dept_query);
        if (!$stmt) {
            throw new Exception('Database error: Failed to prepare query');
        }
        mysqli_stmt_bind_param($stmt, "s", $admin_username);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new Exception('Database error: Failed to execute query');
        }
        $result = mysqli_stmt_get_result($stmt);
        $dept_info = mysqli_fetch_assoc($result);
        if ($result) {
            mysqli_free_result($result);  // CRITICAL: Free result
        }
        mysqli_stmt_close($stmt);  // CRITICAL: Close statement
        
        if (!$dept_info || !$dept_info['DEPT_ID']) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }
        
        $dept_id = $dept_info['DEPT_ID'];
        
        // Calculate academic year - use centralized function if available (matches profile.php logic)
        if (function_exists('getAcademicYear')) {
            $academic_year = getAcademicYear();
        } else {
            // Fallback calculation - matches getAcademicYear() logic from profile.php
            // Academic year logic: July onwards = current-to-next, before July = (current-2)-to-(current-1)
            $current_year = (int)date("Y");
            $current_month = (int)date("n");
            if ($current_month >= 7) {
                $academic_year = $current_year . '-' . ($current_year + 1);
            } else {
                $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
            }
        }
        
        if ($_POST['action'] == 'save_details') {
            // Handle main form submission
            
            // Get department name from database since it's not in the form
            $dept_name_query = "SELECT DEPT_NAME FROM department_master WHERE DEPT_ID = ?";
            $stmt_name = mysqli_prepare($conn, $dept_name_query);
            if (!$stmt_name) {
                throw new Exception('Database error: Failed to prepare department name query');
            }
            mysqli_stmt_bind_param($stmt_name, "i", $dept_id);
            if (!mysqli_stmt_execute($stmt_name)) {
                mysqli_stmt_close($stmt_name);
                throw new Exception('Database error: Failed to execute department name query');
            }
            $name_result = mysqli_stmt_get_result($stmt_name);
            $dept_name_info = mysqli_fetch_assoc($name_result);
            $department_name = $dept_name_info['DEPT_NAME'] ?? '';
            if ($name_result) {
                mysqli_free_result($name_result);  // CRITICAL: Free result
            }
            mysqli_stmt_close($stmt_name);  // CRITICAL: Close statement
            
            // Process ALL form fields with proper sanitization
            $hod_name = trim($_POST['hod_name'] ?? '');
            $hod_email = trim($_POST['hod_email'] ?? ''); // Get HOD_EMAIL from form input
            $hod_mobile = trim($_POST['hod_mobile'] ?? '');
            $iqac_coordinator_name = trim($_POST['iqac_coordinator_name'] ?? '');
            $iqac_coordinator_email = trim($_POST['iqac_coordinator_email'] ?? '');
            $iqac_coordinator_mobile = trim($_POST['iqac_coordinator_mobile'] ?? '');
            
            // Validate numeric fields and ensure non-negative
            $sanctioned_teaching_faculty = max(0, (int)($_POST['sanctioned_teaching_faculty'] ?? 0));
            $prof_direct_val = max(0, (int)($_POST['prof_direct'] ?? 0));
            $prof_cas_val = max(0, (int)($_POST['prof_cas'] ?? 0));
            $permanent_professors = $prof_direct_val + $prof_cas_val;
            $assoc_prof_direct_val = max(0, (int)($_POST['assoc_prof_direct'] ?? 0));
            $assoc_prof_cas_val = max(0, (int)($_POST['assoc_prof_cas'] ?? 0));
            $permanent_associate_professors = $assoc_prof_direct_val + $assoc_prof_cas_val;
            $permanent_assistant_professors = max(0, (int)($_POST['permanent_assistant_professors'] ?? 0));
            $professor_of_practice_associate = max(0, (int)($_POST['professor_of_practice_associate'] ?? 0));
            $associate_professor_of_practice = max(0, (int)($_POST['associate_professor_of_practice'] ?? 0));
            $assistant_professor_of_practice = max(0, (int)($_POST['assistant_professor_of_practice'] ?? 0));
            $prof_direct = $prof_direct_val;
            $prof_cas = $prof_cas_val;
            $assoc_prof_direct = $assoc_prof_direct_val;
            $assoc_prof_cas = $assoc_prof_cas_val;
            $pm_professor = max(0, (int)($_POST['pm_professor'] ?? 0));
            $contract_teachers = max(0, (int)($_POST['contract_teachers'] ?? 0));
            $male_faculty_inbound = max(0, (int)($_POST['male_faculty_inbound'] ?? 0));
            $female_faculty_inbound = max(0, (int)($_POST['female_faculty_inbound'] ?? 0));
            $other_faculty_inbound = max(0, (int)($_POST['other_faculty_inbound'] ?? 0));
            $male_faculty_outbound = max(0, (int)($_POST['male_faculty_outbound'] ?? 0));
            $female_faculty_outbound = max(0, (int)($_POST['female_faculty_outbound'] ?? 0));
            $other_faculty_outbound = max(0, (int)($_POST['other_faculty_outbound'] ?? 0));
            $class_1_perm = max(0, (int)($_POST['class_1_perm'] ?? 0));
            $class_1_temp = max(0, (int)($_POST['class_1_temp'] ?? 0));
            $class_2_perm = max(0, (int)($_POST['class_2_perm'] ?? 0));
            $class_2_temp = max(0, (int)($_POST['class_2_temp'] ?? 0));
            $class_3_perm = max(0, (int)($_POST['class_3_perm'] ?? 0));
            $class_3_temp = max(0, (int)($_POST['class_3_temp'] ?? 0));
            $class_4_perm = max(0, (int)($_POST['class_4_perm'] ?? 0));
            $class_4_temp = max(0, (int)($_POST['class_4_temp'] ?? 0));
            $apprenticeships_interns = max(0, (int)($_POST['apprenticeships_interns'] ?? 0));
            $permanent_faculties = max(0, (int)($_POST['num_perm_phd'] ?? 0));
            $adhoc_faculties = max(0, (int)($_POST['num_adhoc_phd'] ?? 0));
            
            // Required database fields that don't exist in form - use defaults
            $year_of_establishment = 0; // Default value
            $male_faculty = 0; // Default value  
            $female_faculty = 0; // Default value
            $other_faculty = 0; // Default value
            $areas_of_research = ''; // Default value
            $class_1 = 0; // Default value
            $class_2 = 0; // Default value
            $class_3 = 0; // Default value
            $class_4 = 0; // Default value
            $type = 'Sciences and Technology'; // Default value
            
            // Check if record exists
            $check_query = "SELECT ID FROM brief_details_of_the_department WHERE DEPT_ID = ? AND A_YEAR = ?";
            $stmt_check = mysqli_prepare($conn, $check_query);
            if (!$stmt_check) {
                throw new Exception('Database error: Failed to prepare check query');
            }
            mysqli_stmt_bind_param($stmt_check, "is", $dept_id, $academic_year);
            if (!mysqli_stmt_execute($stmt_check)) {
                mysqli_stmt_close($stmt_check);
                throw new Exception('Database error: Failed to execute check query');
            }
            $check_result = mysqli_stmt_get_result($stmt_check);
            $record_exists = ($check_result && mysqli_num_rows($check_result) > 0);
            
            if ($check_result) {
                mysqli_free_result($check_result);  // CRITICAL: Free result
            }
            mysqli_stmt_close($stmt_check);  // CRITICAL: Close statement
            
            if ($record_exists) {
                // Update existing record - use correct column names
                $update_query = "UPDATE brief_details_of_the_department SET 
                    A_YEAR = ?, DEPARTMENT_NAME = ?, YEAR_OF_ESTABLISHMENT = ?, HOD_NAME = ?, HOD_EMAIL = ?, HOD_MOBILE = ?,
                    IQAC_COORDINATOR_NAME = ?, IQAC_COORDINATOR_EMAIL = ?, IQAC_COORDINATOR_MOBILE = ?,
                    SANCTIONED_TEACHING_FACULTY = ?, PERMANENT_PROFESSORS = ?, PERMANENT_ASSOCIATE_PROFESSORS = ?, PERMANENT_ASSISTANT_PROFESSORS = ?, 
                    NUM_PERM_PHD = ?, NUM_ADHOC_PHD = ?, PROFESSOR_OF_PRACTICE_ASSOCIATE = ?, ASSOCIATE_PROFESSOR_OF_PRACTICE = ?,
                    ASSISTANT_PROFESSOR_OF_PRACTICE = ?, PROF_DIRECT = ?, PROF_CAS = ?, ASSOC_PROF_DIRECT = ?, ASSOC_PROF_CAS = ?,
                    PM_PROFESSOR = ?, CONTRACT_TEACHERS = ?, MALE_FACULTY = ?, FEMALE_FACULTY = ?, OTHER_FACULTY = ?,
                    MALE_FACULTY_INBOUND = ?, FEMALE_FACULTY_INBOUND = ?, OTHER_FACULTY_INBOUND = ?, 
                    MALE_FACULTY_OUTBOUND = ?, FEMALE_FACULTY_OUTBOUND = ?, OTHER_FACULTY_OUTBOUND = ?,
                    AREAS_OF_RESEARCH = ?, CLASS_1_PERM = ?, CLASS_1_TEMP = ?, CLASS_2_PERM = ?, CLASS_2_TEMP = ?, CLASS_3_PERM = ?, CLASS_3_TEMP = ?,
                    CLASS_4_PERM = ?, CLASS_4_TEMP = ?, CLASS_1 = ?, CLASS_2 = ?, CLASS_3 = ?, CLASS_4 = ?, APPRENTICESHIPS_INTERNS = ?, TYPE = ?
                    WHERE DEPT_ID = ? AND A_YEAR = ?";
                
                $stmt_update = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt_update, "ssississsssiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiisis", 
                    $academic_year, $department_name, $year_of_establishment, $hod_name, $hod_email, $hod_mobile,
                    $iqac_coordinator_name, $iqac_coordinator_email, $iqac_coordinator_mobile,
                    $sanctioned_teaching_faculty, $permanent_professors, $permanent_associate_professors, $permanent_assistant_professors,
                    $permanent_faculties, $adhoc_faculties, $professor_of_practice_associate, $associate_professor_of_practice,
                    $assistant_professor_of_practice, $prof_direct, $prof_cas, $assoc_prof_direct, $assoc_prof_cas,
                    $pm_professor, $contract_teachers, $male_faculty, $female_faculty, $other_faculty,
                    $male_faculty_inbound, $female_faculty_inbound, $other_faculty_inbound,
                    $male_faculty_outbound, $female_faculty_outbound, $other_faculty_outbound,
                    $areas_of_research, $class_1_perm, $class_1_temp, $class_2_perm, $class_2_temp, $class_3_perm, $class_3_temp,
                    $class_4_perm, $class_4_temp, $class_1, $class_2, $class_3, $class_4, $apprenticeships_interns, $type,
                    $dept_id, $academic_year);
                
                if (!mysqli_stmt_execute($stmt_update)) {
                    $error_msg = 'Failed to update department details: ' . mysqli_stmt_error($stmt_update);
                    throw new Exception($error_msg);
                }
                
                // 🔄 SYNC: Update department_master table with HoD information (only HOD_NAME, not HOD_EMAIL)
                $sync_dept_query = "UPDATE department_master SET HOD_NAME = ? WHERE DEPT_ID = ?";
                $stmt_sync = mysqli_prepare($conn, $sync_dept_query);
                mysqli_stmt_bind_param($stmt_sync, "si", $hod_name, $dept_id);
                mysqli_stmt_execute($stmt_sync);
                
                $message = 'Department details updated successfully.';
            } else {
                // Insert new record - use correct column names
                $insert_query = "INSERT INTO brief_details_of_the_department 
                    (DEPT_ID, A_YEAR, DEPARTMENT_NAME, YEAR_OF_ESTABLISHMENT, HOD_NAME, HOD_EMAIL, HOD_MOBILE, 
                     IQAC_COORDINATOR_NAME, IQAC_COORDINATOR_EMAIL, IQAC_COORDINATOR_MOBILE,
                     SANCTIONED_TEACHING_FACULTY, PERMANENT_PROFESSORS, PERMANENT_ASSOCIATE_PROFESSORS, PERMANENT_ASSISTANT_PROFESSORS, 
                     NUM_PERM_PHD, NUM_ADHOC_PHD, PROFESSOR_OF_PRACTICE_ASSOCIATE, ASSOCIATE_PROFESSOR_OF_PRACTICE, 
                     ASSISTANT_PROFESSOR_OF_PRACTICE, PROF_DIRECT, PROF_CAS, ASSOC_PROF_DIRECT, ASSOC_PROF_CAS, 
                     PM_PROFESSOR, CONTRACT_TEACHERS, MALE_FACULTY, FEMALE_FACULTY, OTHER_FACULTY,
                     MALE_FACULTY_INBOUND, FEMALE_FACULTY_INBOUND, OTHER_FACULTY_INBOUND, 
                     MALE_FACULTY_OUTBOUND, FEMALE_FACULTY_OUTBOUND, OTHER_FACULTY_OUTBOUND,
                     AREAS_OF_RESEARCH, CLASS_1_PERM, CLASS_1_TEMP, CLASS_2_PERM, CLASS_2_TEMP, CLASS_3_PERM, CLASS_3_TEMP, 
                     CLASS_4_PERM, CLASS_4_TEMP, CLASS_1, CLASS_2, CLASS_3, CLASS_4, APPRENTICESHIPS_INTERNS, TYPE) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt_insert = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($stmt_insert, "ississssssiiiiiiiiiiiiiiiiiiiiiiiisiiiiiiiiiiiiis", 
                    $dept_id, $academic_year, $department_name, $year_of_establishment, $hod_name, $hod_email, $hod_mobile,
                    $iqac_coordinator_name, $iqac_coordinator_email, $iqac_coordinator_mobile,
                    $sanctioned_teaching_faculty, $permanent_professors, $permanent_associate_professors, $permanent_assistant_professors,
                    $permanent_faculties, $adhoc_faculties, $professor_of_practice_associate, $associate_professor_of_practice,
                    $assistant_professor_of_practice, $prof_direct, $prof_cas, $assoc_prof_direct, $assoc_prof_cas,
                    $pm_professor, $contract_teachers, $male_faculty, $female_faculty, $other_faculty,
                    $male_faculty_inbound, $female_faculty_inbound, $other_faculty_inbound,
                    $male_faculty_outbound, $female_faculty_outbound, $other_faculty_outbound,
                    $areas_of_research, $class_1_perm, $class_1_temp, $class_2_perm, $class_2_temp, $class_3_perm, $class_3_temp,
                    $class_4_perm, $class_4_temp, $class_1, $class_2, $class_3, $class_4, $apprenticeships_interns, $type);
                
                if (!mysqli_stmt_execute($stmt_insert)) {
                    $error_msg = 'Failed to save department details: ' . mysqli_stmt_error($stmt_insert);
                    error_log("INSERT Error: " . $error_msg);
                    error_log("SQL Query: " . $insert_query);
                    error_log("Parameters: " . print_r([
                        $dept_id, $academic_year, $department_name, $year_of_establishment, $hod_name, $hod_email, $hod_mobile,
                        $iqac_coordinator_name, $iqac_coordinator_email, $iqac_coordinator_mobile,
                        $sanctioned_teaching_faculty, $permanent_professors, $permanent_associate_professors, $permanent_assistant_professors,
                        $permanent_faculties, $adhoc_faculties, $professor_of_practice_associate, $associate_professor_of_practice,
                        $assistant_professor_of_practice, $prof_direct, $prof_cas, $assoc_prof_direct, $assoc_prof_cas,
                        $pm_professor, $contract_teachers, $male_faculty, $female_faculty, $other_faculty,
                        $male_faculty_inbound, $female_faculty_inbound, $other_faculty_inbound,
                        $male_faculty_outbound, $female_faculty_outbound, $other_faculty_outbound,
                        $areas_of_research, $class_1_perm, $class_1_temp, $class_2_perm, $class_2_temp, $class_3_perm, $class_3_temp,
                        $class_4_perm, $class_4_temp, $class_1, $class_2, $class_3, $class_4, $apprenticeships_interns, $type
                    ], true));
                    throw new Exception($error_msg);
                }
                
                // 🔄 SYNC: Update department_master table with HoD information (only HOD_NAME, not HOD_EMAIL)
                $sync_dept_query = "UPDATE department_master SET HOD_NAME = ? WHERE DEPT_ID = ?";
                $stmt_sync = mysqli_prepare($conn, $sync_dept_query);
                mysqli_stmt_bind_param($stmt_sync, "si", $hod_name, $dept_id);
                mysqli_stmt_execute($stmt_sync);
                
                $message = 'Department details saved successfully.';
            }
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            exit();
            
        } elseif ($_POST['action'] == 'clear_data') {
            // Handle clear data action
            $delete_query = "DELETE FROM brief_details_of_the_department WHERE DEPT_ID = ? AND A_YEAR = ?";
            $stmt_delete = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt_delete, "is", $dept_id, $academic_year);
            
            if (mysqli_stmt_execute($stmt_delete)) {
                ob_clean();
                echo json_encode([
                    'success' => true,
                    'message' => 'Data cleared successfully.'
                ]);
                exit();
            } else {
                throw new Exception('Failed to clear data: ' . mysqli_stmt_error($stmt_delete));
            }
        }
        
    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit();
    } catch (Throwable $e) {
        // Catch any fatal errors or other exceptions
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Form submission failed due to server error. Please try again.'
        ]);
        exit();
    }
}

// Now require unified_header.php for normal page display
require('unified_header.php');

// ============================================================================
// HTML OUTPUT STARTS HERE
// ============================================================================

// Soft message container
echo '<div id="soft-message" class="soft-message" style="display: none;"></div>';

//Academic year logic - use centralized function
if (file_exists(__DIR__ . '/common_functions.php')) {
    require_once(__DIR__ . '/common_functions.php');
}
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    // Fallback calculation - matches getAcademicYear() logic
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    if ($current_month >= 7) {
        $A_YEAR = $current_year . '-' . ($current_year + 1);
    } else {
        $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
    }
}

// Get department ID from admin username (email) - same logic as dashboard.php
$admin_username = $_SESSION['admin_username'] ?? '';
if (empty($admin_username)) {
    die('Session expired. Please login again.');
}

$dept_query = "SELECT DEPT_ID, DEPT_COLL_NO, DEPT_NAME, HOD_NAME, EMAIL FROM department_master WHERE EMAIL = ?";
$stmt = mysqli_prepare($conn, $dept_query);
if ($stmt) {
mysqli_stmt_bind_param($stmt, "s", $admin_username);
    if (mysqli_stmt_execute($stmt)) {
$result = mysqli_stmt_get_result($stmt);
$dept_info = mysqli_fetch_assoc($result);
        if ($result) {
            mysqli_free_result($result);  // CRITICAL: Free result
        }
        mysqli_stmt_close($stmt);  // CRITICAL: Close statement
    } else {
        mysqli_stmt_close($stmt);
        $dept_info = null;
    }
} else {
    $dept_info = null;
}

if (!$dept_info) {
    die('Department information not found. Please login again.');
}

$dept = $dept_info['DEPT_ID'];
$dept_code = $dept_info['DEPT_COLL_NO'];
$dept_name = $dept_info['DEPT_NAME'];
$master_hod_name = $dept_info['HOD_NAME']; // Only for default HOD name display
$master_dept_email = $dept_info['EMAIL'];


// Get existing data for the department with department name from department_master table
$existing_data = null;
$department_name = '';
$existing_query = "SELECT b.*, d.DEPT_NAME FROM brief_details_of_the_department b 
                  LEFT JOIN department_master d ON b.DEPT_ID = d.DEPT_ID 
                  WHERE b.DEPT_ID = ? AND b.A_YEAR = ?";
$stmt = mysqli_prepare($conn, $existing_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "is", $dept, $A_YEAR);
    if (mysqli_stmt_execute($stmt)) {
        $existing_result = mysqli_stmt_get_result($stmt);
        if ($existing_result && mysqli_num_rows($existing_result) > 0) {
            $existing_data = mysqli_fetch_assoc($existing_result);
            $department_name = $existing_data['DEPT_NAME'] ?? $existing_data['DEPARTMENT_NAME'] ?? '';
        }
        if ($existing_result) {
            mysqli_free_result($existing_result);  // CRITICAL: Free result
        }
    }
    mysqli_stmt_close($stmt);  // CRITICAL: Close statement
}

// If no existing data, fetch department name from department_master table
if (!$department_name && $dept) {
    $dept_query = "SELECT DEPT_NAME FROM department_master WHERE DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $dept_query);
    if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $dept);
        if (mysqli_stmt_execute($stmt)) {
    $dept_result = mysqli_stmt_get_result($stmt);
    if ($dept_result && mysqli_num_rows($dept_result) > 0) {
        $dept_row = mysqli_fetch_assoc($dept_result);
        $department_name = $dept_row['DEPT_NAME'];
            }
            if ($dept_result) {
                mysqli_free_result($dept_result);  // CRITICAL: Free result
            }
        }
        mysqli_stmt_close($stmt);  // CRITICAL: Close statement
    }
}

// Initialize form state variables
$form_locked = false;
$success_message = '';
$error_message = '';

// Check if data already exists
if ($existing_data) {
    $form_locked = true;
}

// Set readonly attribute for form fields
$readonly_attr = $form_locked ? 'readonly' : '';
$disabled_attr = $form_locked ? 'disabled' : '';


if (isset($_POST['submit']) || isset($_POST['update_data'])) {
    // Check if this is an update operation
    $is_update = isset($_POST['update_data']);
    
    // --- Retrieving all variables that are actually in the form ---
    // Get department name from department_master table
    $dept_query = "SELECT DEPT_NAME FROM department_master WHERE DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $dept_query);
    if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $dept);
        if (mysqli_stmt_execute($stmt)) {
    $dept_result = mysqli_stmt_get_result($stmt);
    $dept_info = mysqli_fetch_assoc($dept_result);
            $department_name = $dept_info['DEPT_NAME'] ?? '';
            if ($dept_result) {
                mysqli_free_result($dept_result);  // CRITICAL: Free result
            }
        }
        mysqli_stmt_close($stmt);  // CRITICAL: Close statement
    } else {
        $department_name = '';
    }
    
    // Sanitize all input data using trim() instead of mysqli_real_escape_string
    $hod_name = trim($_POST['hod_name'] ?? '');
    $hod_email = trim($_POST['hod_email'] ?? '');
    $hod_mobile = trim($_POST['hod_mobile'] ?? '');
    $iqac_coordinator_name = trim($_POST['iqac_coordinator_name'] ?? '');
    $iqac_coordinator_email = trim($_POST['iqac_coordinator_email'] ?? '');
    $iqac_coordinator_mobile = trim($_POST['iqac_coordinator_mobile'] ?? '');
    $sanctioned_teaching_faculty = (int)($_POST['sanctioned_teaching_faculty'] ?? 0);

    // Professor of Practice fields
    $professor_of_practice_associate = (int)($_POST['professor_of_practice_associate'] ?? 0);
    $associate_professor_of_practice = (int)($_POST['associate_professor_of_practice'] ?? 0);
    $assistant_professor_of_practice = (int)($_POST['assistant_professor_of_practice'] ?? 0);

    // Permanent Faculty fields - Updated to match form field names
    $num_perm_phd = (int)($_POST['num_perm_phd'] ?? 0);
    $num_adhoc_phd = (int)($_POST['num_adhoc_phd'] ?? 0);

    $pm_professor = (int)($_POST['pm_professor'] ?? 0);
    $contract_teachers = (int)($_POST['contract_teachers'] ?? 0);
    
    // International Faculty fields - Updated to separate inbound/outbound
    $male_faculty_inbound = (int)($_POST['male_faculty_inbound'] ?? 0);
    $female_faculty_inbound = (int)($_POST['female_faculty_inbound'] ?? 0);
    $other_faculty_inbound = (int)($_POST['other_faculty_inbound'] ?? 0);
    $male_faculty_outbound = (int)($_POST['male_faculty_outbound'] ?? 0);
    $female_faculty_outbound = (int)($_POST['female_faculty_outbound'] ?? 0);
    $other_faculty_outbound = (int)($_POST['other_faculty_outbound'] ?? 0);
    
    // Note: areas_of_research is now handled in profile.php
    $apprenticeships_interns = (int)($_POST['apprenticeships_interns'] ?? 0);

    // Set default values for non-compulsory fields
    $pm_professor = $pm_professor ?: 0;
    $contract_teachers = $contract_teachers ?: 0;
    $male_faculty_inbound = $male_faculty_inbound ?: 0;
    $female_faculty_inbound = $female_faculty_inbound ?: 0;
    $other_faculty_inbound = $other_faculty_inbound ?: 0;
    $male_faculty_outbound = $male_faculty_outbound ?: 0;
    $female_faculty_outbound = $female_faculty_outbound ?: 0;
    $other_faculty_outbound = $other_faculty_outbound ?: 0;
    $apprenticeships_interns = $apprenticeships_interns ?: 0;
    $professor_of_practice_associate = $professor_of_practice_associate ?: 0;
    $associate_professor_of_practice = $associate_professor_of_practice ?: 0;
    $assistant_professor_of_practice = $assistant_professor_of_practice ?: 0;
    $permanent_professors = $permanent_professors ?: 0;
    $permanent_associate_professors = $permanent_associate_professors ?: 0;
    $permanent_assistant_professors = $permanent_assistant_professors ?: 0;
    $num_perm_phd = $num_perm_phd ?: 0;
    $num_adhoc_phd = $num_adhoc_phd ?: 0;


    // Get new permanent faculty fields
    $prof_direct = (int)($_POST['prof_direct'] ?? 0);
    $prof_cas = (int)($_POST['prof_cas'] ?? 0);
    $assoc_prof_direct = (int)($_POST['assoc_prof_direct'] ?? 0);
    $assoc_prof_cas = (int)($_POST['assoc_prof_cas'] ?? 0);
    $permanent_assistant_professors = (int)($_POST['asst_professors'] ?? 0);
    
    // Set default values
    $prof_direct = $prof_direct ?: 0;
    $prof_cas = $prof_cas ?: 0;
    $assoc_prof_direct = $assoc_prof_direct ?: 0;
    $assoc_prof_cas = $assoc_prof_cas ?: 0;
    $permanent_assistant_professors = $permanent_assistant_professors ?: 0;
    
    // Calculate totals
    $total_professors = $prof_direct + $prof_cas;
    $total_assoc_professors = $assoc_prof_direct + $assoc_prof_cas;
    $total_asst_professors = $permanent_assistant_professors;
    $total_permanent = $total_professors + $total_assoc_professors + $total_asst_professors;
    
    if ($total_permanent > $sanctioned_teaching_faculty) {
        echo "<script>alert('VALIDATION ERROR:\n\nTotal Permanent Faculty count: $total_permanent\nSanctioned Teaching Faculty limit: $sanctioned_teaching_faculty\n\nBREAKDOWN:\n• Professor: $total_professors (Direct: $prof_direct, CAS: $prof_cas)\n• Associate Professor: $total_assoc_professors (Direct: $assoc_prof_direct, CAS: $assoc_prof_cas)\n• Assistant Professor: $total_asst_professors\n\nIMPORTANT: Only Permanent Faculty positions count against the sanctioned teaching faculty limit.\nProfessor Practice, PM Professors, and Contract Teachers are separate categories.\n\nPlease reduce the Permanent Faculty numbers to fit within the sanctioned limit.')</script>";
        echo '<script>window.location.href = "DetailsOfDepartment.php";</script>';
        exit;
    }

    // Get new non-teaching employee fields
    $class_1_perm = (int)($_POST['class_1_perm'] ?? 0);
    $class_1_temp = (int)($_POST['class_1_temp'] ?? 0);
    $class_2_perm = (int)($_POST['class_2_perm'] ?? 0);
    $class_2_temp = (int)($_POST['class_2_temp'] ?? 0);
    $class_3_perm = (int)($_POST['class_3_perm'] ?? 0);
    $class_3_temp = (int)($_POST['class_3_temp'] ?? 0);
    $class_4_perm = (int)($_POST['class_4_perm'] ?? 0);
    $class_4_temp = (int)($_POST['class_4_temp'] ?? 0);
    
    // Set default values
    $class_1_perm = $class_1_perm ?: 0;
    $class_1_temp = $class_1_temp ?: 0;
    $class_2_perm = $class_2_perm ?: 0;
    $class_2_temp = $class_2_temp ?: 0;
    $class_3_perm = $class_3_perm ?: 0;
    $class_3_temp = $class_3_temp ?: 0;
    $class_4_perm = $class_4_perm ?: 0;
    $class_4_temp = $class_4_temp ?: 0;

    // Ensure all required columns exist before inserting
    $required_columns = [
        "NUM_ADHOC_PHD", "PROF_DIRECT", "PROF_CAS", "ASSOC_PROF_DIRECT", "ASSOC_PROF_CAS", 
        "CLASS_1_PERM", "CLASS_1_TEMP", "CLASS_2_PERM", "CLASS_2_TEMP", 
        "CLASS_3_PERM", "CLASS_3_TEMP", "CLASS_4_PERM", "CLASS_4_TEMP",
        "PROFESSOR_OF_PRACTICE_ASSOCIATE", "ASSOCIATE_PROFESSOR_OF_PRACTICE", "ASSISTANT_PROFESSOR_OF_PRACTICE",
        "MALE_FACULTY_INBOUND", "FEMALE_FACULTY_INBOUND", "OTHER_FACULTY_INBOUND",
        "MALE_FACULTY_OUTBOUND", "FEMALE_FACULTY_OUTBOUND", "OTHER_FACULTY_OUTBOUND"
    ];
    
    foreach ($required_columns as $col) {
        $check_col = "SHOW COLUMNS FROM brief_details_of_the_department LIKE ?";
        $stmt_check = mysqli_prepare($conn, $check_col);
        if ($stmt_check) {
        mysqli_stmt_bind_param($stmt_check, "s", $col);
            if (mysqli_stmt_execute($stmt_check)) {
        $col_result = mysqli_stmt_get_result($stmt_check);
                $column_exists = ($col_result && mysqli_num_rows($col_result) > 0);
                if ($col_result) {
                    mysqli_free_result($col_result);  // CRITICAL: Free result
                }
                mysqli_stmt_close($stmt_check);  // CRITICAL: Close statement
                
                if (!$column_exists) {
                    // Note: ALTER TABLE with prepared statements is not directly supported
                    // Use direct query with proper escaping
                    $col_safe = mysqli_real_escape_string($conn, $col);
                    $add_col = "ALTER TABLE brief_details_of_the_department ADD COLUMN `$col_safe` int NOT NULL DEFAULT '0'";
                    @mysqli_query($conn, $add_col);
                }
            } else {
                mysqli_stmt_close($stmt_check);
            }
        }
    }
    
    // First, delete any existing record for this department and year using prepared statement
    $delete_query = "DELETE FROM brief_details_of_the_department WHERE DEPT_ID = ? AND A_YEAR = ?";
    $stmt_delete = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($stmt_delete, "is", $dept, $A_YEAR);
    mysqli_stmt_execute($stmt_delete);
    
    // Then insert the new record using prepared statement
    $insert_query = "INSERT INTO `brief_details_of_the_department` ( 
        `A_YEAR`, `DEPT_ID`, `DEPARTMENT_NAME`,
        `HOD_NAME`, `HOD_EMAIL`, `HOD_MOBILE`, 
        `IQAC_COORDINATOR_NAME`, `IQAC_COORDINATOR_EMAIL`, `IQAC_COORDINATOR_MOBILE`, 
        `SANCTIONED_TEACHING_FACULTY`, 
        `PROFESSOR_OF_PRACTICE_ASSOCIATE`, `ASSOCIATE_PROFESSOR_OF_PRACTICE`, `ASSISTANT_PROFESSOR_OF_PRACTICE`, 
        `PROF_DIRECT`, `PROF_CAS`, `ASSOC_PROF_DIRECT`, `ASSOC_PROF_CAS`, `PERMANENT_ASSISTANT_PROFESSORS`,
        `NUM_PERM_PHD`, `NUM_ADHOC_PHD`,
        `PM_PROFESSOR`, `CONTRACT_TEACHERS`, 
        `MALE_FACULTY_INBOUND`, `FEMALE_FACULTY_INBOUND`, `OTHER_FACULTY_INBOUND`,
        `MALE_FACULTY_OUTBOUND`, `FEMALE_FACULTY_OUTBOUND`, `OTHER_FACULTY_OUTBOUND`,
        `CLASS_1_PERM`, `CLASS_1_TEMP`, `CLASS_2_PERM`, `CLASS_2_TEMP`, `CLASS_3_PERM`, `CLASS_3_TEMP`, `CLASS_4_PERM`, `CLASS_4_TEMP`,
        `APPRENTICESHIPS_INTERNS`
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_insert = mysqli_prepare($conn, $insert_query);
    mysqli_stmt_bind_param($stmt_insert, "sisssssssssssssssssssssssssssssssss", 
        $A_YEAR, $dept, $department_name,
        $hod_name, $hod_email, $hod_mobile,
        $iqac_coordinator_name, $iqac_coordinator_email, $iqac_coordinator_mobile,
        $sanctioned_teaching_faculty,
        $professor_of_practice_associate, $associate_professor_of_practice, $assistant_professor_of_practice,
        $prof_direct, $prof_cas, $assoc_prof_direct, $assoc_prof_cas, $permanent_assistant_professors,
        $num_perm_phd, $num_adhoc_phd,
        $pm_professor, $contract_teachers,
        $male_faculty_inbound, $female_faculty_inbound, $other_faculty_inbound,
        $male_faculty_outbound, $female_faculty_outbound, $other_faculty_outbound,
        $class_1_perm, $class_1_temp, $class_2_perm, $class_2_temp, $class_3_perm, $class_3_temp, $class_4_perm, $class_4_temp,
        $apprenticeships_interns
    );
    
    $test_result = mysqli_stmt_execute($stmt_insert);
    
    if ($test_result) {
        // 🔄 SYNC: Update department_master table with HoD information (only HOD_NAME, not HOD_EMAIL)
        $sync_dept_query = "UPDATE department_master SET HOD_NAME = ? WHERE DEPT_ID = ?";
        $stmt_sync = mysqli_prepare($conn, $sync_dept_query);
        mysqli_stmt_bind_param($stmt_sync, "si", $hod_name, $dept);
        mysqli_stmt_execute($stmt_sync);
        
        if ($is_update) {
        $success_message = "Data updated successfully!";
        } else {
            $success_message = "Data saved successfully!";
        }
        $form_locked = true;
        // Reload the data to reflect changes using prepared statement
        $reload_query = "SELECT b.*, d.DEPT_NAME FROM brief_details_of_the_department b 
                        LEFT JOIN department_master d ON b.DEPT_ID = d.DEPT_ID 
                        WHERE b.DEPT_ID = ? AND b.A_YEAR = ?";
        $stmt_reload = mysqli_prepare($conn, $reload_query);
        if ($stmt_reload) {
        mysqli_stmt_bind_param($stmt_reload, "is", $dept, $A_YEAR);
            if (mysqli_stmt_execute($stmt_reload)) {
        $reload_result = mysqli_stmt_get_result($stmt_reload);
        if ($reload_result && mysqli_num_rows($reload_result) > 0) {
            $existing_data = mysqli_fetch_assoc($reload_result);
            $department_name = $existing_data['DEPT_NAME'] ?: $existing_data['DEPARTMENT_NAME'];
                }
                if ($reload_result) {
                    mysqli_free_result($reload_result);  // CRITICAL: Free result
                }
            }
            mysqli_stmt_close($stmt_reload);  // CRITICAL: Close statement
        }
        // Update readonly attribute after successful submission
        $readonly_attr = 'readonly disabled';
        if ($is_update) {
            echo "<script>alert('✅ Data updated successfully! Form will be locked for editing.');</script>";
    } else {
            echo "<script>alert('✅ Data saved successfully! Form will be locked for editing.');</script>";
        }
        echo "<script>// Database operation successful</script>";
    } else {
        $error_message = "Database Error: " . mysqli_stmt_error($stmt_insert);
        echo "<script>alert('❌ Database Error: " . mysqli_stmt_error($stmt_insert) . "');</script>";
        echo "<script>// Database Error</script>";
    }
}

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'clear_data') {
        // Clear all data for this department and year using prepared statement
        $clear_query = "DELETE FROM `brief_details_of_the_department` WHERE DEPT_ID = ? AND A_YEAR = ?";
        $stmt_clear = mysqli_prepare($conn, $clear_query);
        mysqli_stmt_bind_param($stmt_clear, "is", $dept, $A_YEAR);
        $sql = mysqli_stmt_execute($stmt_clear);
        
        // Delete uploaded documents using the unified supporting_documents table
        $delete_docs_query = "SELECT file_path FROM supporting_documents WHERE dept_id = ? AND page_section = 'details_dept' AND status = 'active'";
        $stmt_docs = mysqli_prepare($conn, $delete_docs_query);
        if ($stmt_docs) {
        mysqli_stmt_bind_param($stmt_docs, "i", $dept);
            if (mysqli_stmt_execute($stmt_docs)) {
        $docs_result = mysqli_stmt_get_result($stmt_docs);
        if ($docs_result) {
            while ($doc = mysqli_fetch_assoc($docs_result)) {
                $file_path = $doc['file_path'];
                        $project_root = dirname(__DIR__);
                        // Normalize path
                        $physical_path = $file_path;
                        if (strpos($file_path, $project_root) !== 0) {
                            if (strpos($file_path, '../') === 0) {
                                $physical_path = $project_root . '/' . str_replace('../', '', $file_path);
                            } elseif (strpos($file_path, 'uploads/') === 0) {
                                $physical_path = $project_root . '/' . $file_path;
                            }
                        }
                        $physical_path = str_replace('\\', '/', $physical_path);
                        if (file_exists($physical_path)) {
                            @unlink($physical_path);
                }
            }
                    mysqli_free_result($docs_result);  // CRITICAL: Free result
                }
            }
            mysqli_stmt_close($stmt_docs);  // CRITICAL: Close statement
        }
        
        // Hard delete from supporting_documents table (completely remove records)
        $delete_docs_query = "DELETE FROM supporting_documents WHERE dept_id = ? AND page_section = 'details_dept'";
        $stmt_docs_delete = mysqli_prepare($conn, $delete_docs_query);
        mysqli_stmt_bind_param($stmt_docs_delete, "i", $dept);
        $docs_deleted = mysqli_stmt_execute($stmt_docs_delete);
        
        if ($sql) {
            $success_message = "✅ All data and uploaded documents cleared successfully!\n\n• Form data removed from database\n• PDF files deleted from server\n• Database records removed\n\nForm is now ready for new data entry.";
            $existing_data = null;
            $form_locked = false;
            // Redirect to clean URL after 2 seconds
            echo "<script>
                setTimeout(function() {
                    window.location.href = 'DetailsOfDepartment.php';
                }, 2000);
            </script>";
        } else {
            $error_message = "Error clearing data: " . mysqli_error($conn);
        }
    }
}

// Note: View document functionality now uses direct file paths from supporting_documents table
// This is the same approach as Research_Details_Support_Doc.php
?>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

<script>
    // Store CSRF token globally for use in upload functions
    let pageCSRFToken = '';
    
    $(document).ready(function() {
        // Get and store CSRF token when page loads
        const metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken && metaToken.content && metaToken.content.trim() !== '') {
            pageCSRFToken = metaToken.content.trim();
        } else {
            const formToken = document.querySelector('input[name="csrf_token"]');
            if (formToken && formToken.value && formToken.value.trim() !== '') {
                pageCSRFToken = formToken.value.trim();
            }
        }
        // Handle form submission with AJAX
        $("#my_form").on("submit", function(e) {
            e.preventDefault();
            
            // Show loading
            $(".loader").fadeIn();
            
            // Prepare form data
            const formData = new FormData(this);
            
            // Ensure CSRF token is included (get from global or form)
            if (!formData.has('csrf_token')) {
                const csrfToken = getCSRFToken();
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                } else {
                    console.error('CSRF token not found for form submission');
                    showMessage('Security token not found. Please refresh the page and try again.', 'error');
                    $(".loader").fadeOut();
                    return;
                }
            }
            
            // Check if this is an update operation by checking which button was clicked
            const updateBtn = document.getElementById('updateBtn');
            const isUpdate = updateBtn && updateBtn.style.display !== 'none';
            
            if (isUpdate) {
                formData.append('action', 'save_details');
                formData.append('update_data', '1');
        } else {
                formData.append('action', 'save_details');
            }
            
            // Submit via AJAX
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Check if response is OK
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                // Try to parse as JSON
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON Parse Error:', text);
                        throw new Error('Invalid JSON response: ' + text.substring(0, 200));
                    }
                });
            })
            .then(data => {
                $(".loader").fadeOut();
                
                if (data.success) {
                    showMessage(data.message, 'success');
                    // Refresh documents first, then reload page
                    setTimeout(() => {
                        refreshAllDocumentStatuses();
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    }, 500);
                } else {
                    showMessage(data.message || 'An error occurred while saving data.', 'error');
                }
            })
            .catch(error => {
                $(".loader").fadeOut();
                console.error('Save Error:', error);
                showMessage('An error occurred while saving data: ' + error.message, 'error');
            });
        });
        
        // Handle clear data button
        $("#clear_data_btn").on("click", function(e) {
            e.preventDefault();
            
            if (confirm('Are you sure you want to clear all data? This action cannot be undone.')) {
            $(".loader").fadeIn();
                
                const formData = new FormData();
                formData.append('action', 'clear_data');
                // Ensure CSRF token is included
                const csrfToken = getCSRFToken();
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    $(".loader").fadeOut();
                    
                    if (data.success) {
                        showMessage(data.message, 'success');
                        // Reload page data
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
    } else {
                        showMessage(data.message, 'error');
                    }
                })
                .catch(error => {
                    $(".loader").fadeOut();
                    showMessage('An error occurred while clearing data.', 'error');
                    // Error occurred
                });
            }
        });
    }); //document ready  

    // Soft message function
    function showMessage(message, type = 'info') {
        const messageDiv = document.getElementById('soft-message');
        if (!messageDiv) return;
        
        // Remove existing classes
        messageDiv.className = 'soft-message';
        
        // Add type-specific class
        messageDiv.classList.add(type === 'success' ? 'success' : type === 'error' ? 'error' : 'info');
        
        // Set message content
        messageDiv.innerHTML = message;
        
        // Show message
        messageDiv.style.display = 'block';
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            messageDiv.style.display = 'none';
        }, 5000);
    }

    function GetCollege(val) {
        $.ajax({
            url: "ajaxGetCollege.php",
            method: "POST",
            data: {
                d_id: val,
            },
            success: function(resp) {
                $("#atcollege_name").html(resp);
            },
            error: function(error) {
                // Error logged
            }
        });
    }

    // Real-time year validation function
    function validateYear(input) {
        let value = input.value;
        
        // Remove any non-numeric characters
        value = value.replace(/[^0-9]/g, '');
        
        // Limit to 4 digits
        if (value.length > 4) {
            value = value.substring(0, 4);
        }
        
        input.value = value;
        
        // Real-time validation feedback
        const currentYear = new Date().getFullYear();
        const year = parseInt(value);
        
        // Remove existing validation classes
        input.classList.remove('is-valid', 'is-invalid');
        
        if (value.length === 4) {
            if (year >= 1900 && year <= currentYear) {
                input.classList.add('is-valid');
                input.setCustomValidity('');
            } else {
                input.classList.add('is-invalid');
                input.setCustomValidity(`Year must be between 1900 and ${currentYear}`);
            }
        } else if (value.length > 0) {
            input.classList.add('is-invalid');
            input.setCustomValidity('Please enter exactly 4-digit year');
        } else {
            input.setCustomValidity('');
        }
    }

    // Validate positive numbers
    function validatePositiveNumber(input) {
        let value = input.value;
        // Remove any non-numeric characters except decimal point
        value = value.replace(/[^0-9.]/g, '');
        
        // Ensure only one decimal point
        const parts = value.split('.');
        if (parts.length > 2) {
            value = parts[0] + '.' + parts.slice(1).join('');
        }
        
        // Convert to number and check if negative
        const numValue = parseFloat(value);
        if (numValue < 0) {
            value = '0';
        }
        
        input.value = value;
    }

    
    
    // PDF validation function
    function validatePDF(input) {
        const file = input.files[0];
        if (file) {
            const fileSize = file.size / 1024 / 1024; // Convert to MB
            const fileType = file.type;
            const fileName = file.name;
            const maxSizeMB = 5; // Maximum file size in MB
            
            // Check file type
            if (fileType !== 'application/pdf') {
                alert(`❌ Invalid File Type!\n\nPlease select a PDF file only.\n\nSelected file: ${fileName}\nFile type: ${fileType || 'Unknown'}`);
                input.value = '';
                return false;
            }
            
            // Check file size
            if (fileSize > maxSizeMB) {
                alert(`❌ File Too Large!\n\nFile size: ${fileSize.toFixed(2)} MB\nMaximum allowed: ${maxSizeMB} MB\n\nPlease compress the PDF or select a smaller file.\n\nFile: ${fileName}`);
                input.value = '';
                return false;
            }
            
        }
        return true;
    }
    
    // Helper function to get CSRF token
    function getCSRFToken() {
        // First try the global variable (set on page load)
        if (pageCSRFToken && pageCSRFToken.trim() !== '') {
            return pageCSRFToken.trim();
        }
        
        // Try meta tag (most reliable, set by unified_header.php)
        const metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken && metaToken.content && metaToken.content.trim() !== '') {
            pageCSRFToken = metaToken.content.trim(); // Cache it
            return pageCSRFToken;
        }
        
        // Fallback to form input
        const formToken = document.querySelector('input[name="csrf_token"]');
        if (formToken && formToken.value && formToken.value.trim() !== '') {
            pageCSRFToken = formToken.value.trim(); // Cache it
            return pageCSRFToken;
        }
        
        // Last resort: try to get from any form on the page
        const form = document.querySelector('form');
        if (form) {
            const hiddenToken = form.querySelector('input[name="csrf_token"]');
            if (hiddenToken && hiddenToken.value && hiddenToken.value.trim() !== '') {
                pageCSRFToken = hiddenToken.value.trim(); // Cache it
                return pageCSRFToken;
            }
        }
        
        return null;
    }
    
    // Upload document function
    function uploadDocument(fileId, srno) {
        const fileInput = document.getElementById(fileId);
        const statusDiv = document.getElementById(fileId + '_status');
        
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            showMessage('Please select a file to upload.', 'error');
            return;
        }
        
        if (!validatePDF(fileInput)) {
            return;
        }
        
        // Get CSRF token
        const csrfToken = getCSRFToken();
        if (!csrfToken) {
            console.error('CSRF token not found. Checking page elements...');
            console.error('Meta tag exists:', document.querySelector('meta[name="csrf-token"]') !== null);
            console.error('Form input exists:', document.querySelector('input[name="csrf_token"]') !== null);
            console.error('Form exists:', document.querySelector('form') !== null);
            showMessage('Security token not found. Please refresh the page and try again.', 'error');
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
                    console.warn('[DetailsOfDepartment] Empty response received from upload');
                    return { success: false, message: 'Upload failed: Empty server response' };
                }
                
                // Try to parse as JSON
                try {
                    return JSON.parse(trimmed);
                } catch (e) {
                    console.error('[DetailsOfDepartment] Failed to parse JSON response from upload:', e);
                    console.error('[DetailsOfDepartment] Response text:', trimmed.substring(0, 500));
                    // CRITICAL #5: Return error response instead of throwing
                    return { success: false, message: 'Upload failed: Invalid server response. Please refresh and try again.' };
                }
            });
        })
        .then(data => {
            // CRITICAL #3: Handle null/undefined data gracefully
            if (!data || typeof data !== 'object') {
                console.error('[DetailsOfDepartment] Invalid data structure received from upload:', data);
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Upload failed: Invalid server response</span>';
                statusDiv.className = 'mt-2 text-danger';
                return;
            }
            
        if (data.success) {
            // Check if form is currently locked by looking at the Update button visibility
            const updateBtn = document.querySelector('button[onclick="enableUpdate()"]');
            var isFormLocked = updateBtn && updateBtn.style.display !== 'none';
            
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            var fileInfo = data.file_name ? `<small class="text-muted d-block">📄 ${data.file_name}</small>` : '';
            var fileSize = data.file_size ? `<small class="text-muted d-block">📏 Size: ${(data.file_size / (1024 * 1024)).toFixed(2)} MB</small>` : '';
            
            // CRITICAL: Escape file path for HTML attribute to prevent XSS and syntax errors
            const filePathEscaped = String(data.file_path.startsWith('../') ? data.file_path : '../' + data.file_path).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <div>
                        <span class="text-success">✅ Document uploaded successfully</span>
                        ${fileInfo}
                        ${fileSize}
                    </div>
                    ${deleteButton}
                    <a href="${filePathEscaped}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                        <i class="fas fa-eye"></i> View
                    </a>
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
            
            // Clear the file input
            fileInput.value = '';
            
            showMessage(data.message, 'success');
        } else {
                statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">${data.message}</span>`;
                statusDiv.className = 'mt-2 text-danger';
            showMessage(data.message, 'error');
            }
        })
        .catch(error => {
            // CRITICAL #5: Handle errors gracefully - return object, don't throw
            console.error('[DetailsOfDepartment] Upload error:', error);
            statusDiv.innerHTML = `<div class="text-danger"><i class="fas fa-exclamation-circle"></i> Upload failed: ${error.message || 'Unknown error'}</div>`;
            statusDiv.className = 'mt-2 text-danger';
            showMessage('Upload failed: ' + (error.message || 'Unknown error'), 'error');
        });
    }
    
    // Delete document function
    function deleteDocument(fileId, srno) {
        // Check if form is currently locked by looking at the Update button visibility
        const updateBtn = document.querySelector('button[onclick="enableUpdate()"]');
        var isFormLocked = updateBtn && updateBtn.style.display !== 'none';
        
        if (isFormLocked) {
            showMessage('Cannot delete document. Form is locked after submission.\n\nTo delete documents, please use the Update button to unlock the form first.', 'error');
            return;
        }
        
        if (!confirm('Are you sure you want to delete this document?')) {
            return;
        }
        
        const statusDiv = document.getElementById(fileId + '_status');
        statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
        statusDiv.className = 'mt-2 text-info';
        
        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                         document.querySelector('input[name="csrf_token"]')?.value || '';
        
        const formData = new FormData();
        formData.append('delete_doc', '1');
        formData.append('srno', srno);
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .catch(error => {
            // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
            console.error('[DetailsOfDepartment] Fetch error for delete:', error);
            return { ok: false, text: () => Promise.resolve(JSON.stringify({ success: false, message: 'Network error: ' + (error.message || 'Failed to connect to server') })) };
        })
        .then(response => {
            // CRITICAL: Ensure response is valid before processing
            if (!response || typeof response.text !== 'function') {
                console.error('[DetailsOfDepartment] Invalid response object:', response);
                return { success: false, message: 'Delete failed: Invalid server response' };
            }
            
            // CRITICAL #3: Handle empty responses in JS - return object, don't throw
            return response.text().then(text => {
                const trimmed = text.trim();
                
                // Check if response is HTML (starts with <!DOCTYPE or <html)
                if (trimmed.startsWith('<!DOCTYPE') || trimmed.startsWith('<html') || trimmed.startsWith('<')) {
                    console.error('[DetailsOfDepartment] Received HTML instead of JSON:', trimmed.substring(0, 200));
                    return { success: false, message: 'Invalid server response: Received HTML instead of JSON. Please refresh and try again.' };
                }
                
                // If empty response, return error object instead of throwing
                if (!trimmed || trimmed === '') {
                    console.warn('[DetailsOfDepartment] Empty response received for delete');
                    return { success: false, message: 'Delete failed: Empty server response' };
                }
                
                // Try to parse as JSON
                try {
                    return JSON.parse(trimmed);
                } catch (e) {
                    console.error('[DetailsOfDepartment] Failed to parse JSON response for delete:', e);
                    console.error('[DetailsOfDepartment] Response text:', trimmed.substring(0, 200));
                    // CRITICAL #5: Return error response instead of throwing
                    return { success: false, message: 'Delete failed: Invalid server response' };
                }
            }).catch(textError => {
                // CRITICAL: Handle text() parsing errors
                console.error('[DetailsOfDepartment] Error reading response text:', textError);
                return { success: false, message: 'Delete failed: Error reading server response' };
            });
        })
        .then(data => {
            // CRITICAL #3: Handle null/undefined data gracefully
            if (!data || typeof data !== 'object') {
                console.error('[DetailsOfDepartment] Invalid data structure received for delete:', data);
                statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                statusDiv.className = 'mt-2 text-muted';
                return;
            }
            
            if (data.success) {
                statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                statusDiv.className = 'mt-2 text-muted';
                showMessage(data.message || 'Document deleted successfully', 'success');
            } else {
                statusDiv.innerHTML = `<div class="text-danger">❌ ${data.message || 'Delete failed'}</div>`;
                statusDiv.className = 'mt-2 text-danger';
                showMessage(data.message || 'Delete failed', 'error');
            }
        })
        .catch(error => {
            // CRITICAL #5: Handle errors gracefully - return object, don't throw
            console.error('[DetailsOfDepartment] Delete error in promise chain:', error);
            statusDiv.innerHTML = '<div class="text-danger">❌ Delete failed. Please try again.</div>';
            statusDiv.className = 'mt-2 text-danger';
            showMessage('Delete failed: ' + (error.message || 'Unknown error'), 'error');
            
            // Return resolved promise to prevent unhandled promise rejection
            return Promise.resolve({ success: false, message: 'Delete failed: ' + (error.message || 'Unknown error') });
        });
    }
    
    // Check document status function
    function checkDocumentStatus(fileId, srno) {
        const statusDiv = document.getElementById(fileId + '_status');
        
        fetch(`?check_doc=1&srno=${srno}`, {
            method: 'GET'
        })
        .then(response => {
            // CRITICAL #3: Handle empty responses in JS - return object, don't throw
            return response.text().then(text => {
                const trimmed = text.trim();
                
                // If empty response, return error object instead of throwing
                if (!trimmed || trimmed === '') {
                    console.warn('[DetailsOfDepartment] Empty response received from check_doc');
                    return { success: false, message: 'No document found' };
                }
                
                // Try to parse as JSON
                try {
                    return JSON.parse(trimmed);
                } catch (e) {
                    console.error('[DetailsOfDepartment] Failed to parse JSON response from check_doc:', e);
                    // CRITICAL #5: Return error response instead of throwing
                    return { success: false, message: 'Invalid server response' };
                }
            });
        })
    .then(data => {
            // CRITICAL #3: Handle null/undefined data gracefully
            if (!data || typeof data !== 'object') {
                console.error('[DetailsOfDepartment] Invalid data structure received from check_doc:', data);
                return;
            }
            
        if (data.success && data.file_path) {
            // Check if form is currently locked by looking at the Update button visibility
            const updateBtn = document.querySelector('button[onclick="enableUpdate()"]');
            const saveBtn = document.getElementById('updateBtn');
            var isFormLocked = updateBtn && updateBtn.style.display !== 'none';
            
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            var fileInfo = data.file_name ? `<small class="text-muted d-block">${data.file_name}</small>` : '';
            var uploadDate = data.upload_date ? `<small class="text-muted d-block">Uploaded: ${new Date(data.upload_date).toLocaleDateString()}</small>` : '';
            
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <div>
                        <span class="text-success">Document uploaded</span>
                        ${fileInfo}
                        ${uploadDate}
                    </div>
                    ${deleteButton}
                    <a href="${data.file_path.startsWith('../') ? data.file_path : '../' + data.file_path}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                        <i class="fas fa-eye"></i> View
                    </a>
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
        } else {
                statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                statusDiv.className = 'mt-2 text-muted';
            }
        })
        .catch(error => {
            // CRITICAL #5: Handle errors gracefully - return object, don't throw
            console.error('[DetailsOfDepartment] Check document error:', error);
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
            
            // Return resolved promise to prevent unhandled promise rejection
            return Promise.resolve({ success: false, message: 'Check failed: ' + (error.message || 'Unknown error') });
        });
    }
    
// Load existing documents on page load
// CRITICAL PERFORMANCE FIX: Load ALL documents in ONE batch request instead of 3 individual requests
// This prevents unlimited database requests and server crashes
let documentsLoading = false;
let documentsLoadAttempted = false;

// ============================================================================
// GLOBAL REQUEST LIMITING - Prevent connection exhaustion with 20+ users
// CRITICAL FIX: These variables MUST be OUTSIDE the function to work properly
// If declared inside the function, each call creates new variables and rate limiting fails
// ============================================================================
let globalActiveRequests = 0;
const MAX_CONCURRENT_REQUESTS = 3; // Maximum 3 simultaneous requests per page
const requestQueue = [];

function loadExistingDocuments() {
    if (documentsLoading || documentsLoadAttempted) {
        return; 
    }
    documentsLoading = true;
    documentsLoadAttempted = true;
    
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
                requestQueue.push({ fn: requestFn, resolve, reject });
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
    
    // CRITICAL: Add request timeout with AbortController
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
    // CRITICAL: Use consolidated endpoint with rate limiting - ONE request instead of 3
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
                'perm_phd_pdf': 1,
                'adhoc_phd_pdf': 2,
                'faculty_positions_pdf': 10
            };
    
            // CRITICAL FIX: Clear all status divs first to remove "Checking..." message
            // Then only show documents that actually exist
            const statusDivs = document.querySelectorAll('[id$="_status"]');
            statusDivs.forEach(div => {
                div.innerHTML = '<span class="text-muted">No document uploaded</span>';
                div.className = 'mt-2 text-muted';
            });
    
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
            // CRITICAL FIX: Clear "Checking..." message if no documents found or error
            const statusDivs = document.querySelectorAll('[id$="_status"]');
            statusDivs.forEach(div => {
                div.innerHTML = '<span class="text-muted">No document uploaded</span>';
                div.className = 'mt-2 text-muted';
            });
            if (data.message && data.message !== 'No document found') {
                console.error('Error loading all documents:', data.message || 'Unknown error');
            }
        }
    })
    .catch(error => {
        console.error('Network error loading all documents:', error);
        // CRITICAL FIX: Clear "Checking..." message on error to prevent stuck UI
        const statusDivs = document.querySelectorAll('[id$="_status"]');
        statusDivs.forEach(div => {
            div.innerHTML = '<span class="text-muted">No document uploaded</span>';
            div.className = 'mt-2 text-muted';
        });
    })
    .finally(() => {
        documentsLoading = false;
        // Note: documentsLoadAttempted stays true to prevent duplicate calls
        // It will be reset by refreshAllDocumentStatuses() when Update is clicked
    });
}

// Helper function to update document status UI from consolidated response
function updateDocumentStatusUI(fileId, srno, filePath, fileName) {
    const statusDiv = document.getElementById(fileId + '_status');
    if (!statusDiv) return;

    const fileInput = document.getElementById(fileId);
    const isFormLocked = fileInput ? fileInput.disabled : false;
    
    let actionButtons = '';
    if (isFormLocked) {
        // Form is locked, only show view button
        actionButtons = '';
    } else {
        // Form is unlocked, show delete and view buttons
        // CRITICAL: Escape fileId to prevent JavaScript syntax errors
        const escapedFileId2 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
        actionButtons = `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId2}', ${srno})">
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

// Force refresh all document statuses (used when Update is clicked)
function refreshAllDocumentStatuses() {
    // CRITICAL FIX: Reset flags to allow reload when Update is clicked
    // This prevents "Checking..." from getting stuck if documents were already loaded
    documentsLoadAttempted = false;
    documentsLoading = false;
    
    // Clear existing status divs first
    const statusDivs = document.querySelectorAll('[id$="_status"]');
    statusDivs.forEach(div => {
        div.innerHTML = '<span class="text-muted">Checking...</span>';
        div.className = 'mt-2 text-muted';
    });
    
    // Then reload all documents
    loadExistingDocuments();
}
    
    // Enable update mode
    function enableUpdate() {
        // Enable all form inputs
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.removeAttribute('readonly');
            input.disabled = false;
            input.style.backgroundColor = 'rgba(255, 255, 255, 1)';
            input.style.cursor = (input.type === 'text' || input.type === 'email' || input.type === 'tel' || input.tagName === 'TEXTAREA') ? 'text' : 'default';
            // Ensure required fields have required attribute
            if (input.name && (input.name === 'hod_email' || input.name === 'hod_name' || input.name === 'hod_mobile' || 
                input.name === 'iqac_coordinator_name' || input.name === 'iqac_coordinator_email' || input.name === 'iqac_coordinator_mobile')) {
                input.setAttribute('required', 'required');
            }
        });
        
        // Enable file upload buttons
        const uploadButtons = document.querySelectorAll('button[onclick*="uploadDocument"]');
        uploadButtons.forEach(button => {
            button.disabled = false;
            button.style.cursor = 'pointer';
        });
        
        // Show Save Changes button, hide Update button
        const updateBtn = document.getElementById('updateBtn');
        const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
        const lockedMessage = document.querySelector('.alert-info');
        
        if (updateBtn) updateBtn.style.display = 'inline-block';
        if (updateTriggerBtn) updateTriggerBtn.style.display = 'none';
        if (lockedMessage) lockedMessage.style.display = 'none';
        
        // Refresh document status to show delete buttons with a small delay
        setTimeout(() => {
            refreshAllDocumentStatuses();
        }, 100);
        
        // Focus on first input
        const firstInput = document.querySelector('input[name="hod_name"]');
        if (firstInput) firstInput.focus();
    }
    
    // Disable form (make read-only)
    function disableForm() {
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.setAttribute('readonly', 'readonly');
            input.disabled = true;
            input.style.backgroundColor = '#f8f9fa';
            input.style.cursor = 'not-allowed';
        });
        
        // Disable file upload buttons
        const uploadButtons = document.querySelectorAll('button[onclick*="uploadDocument"]');
        uploadButtons.forEach(button => {
            button.disabled = true;
            button.style.cursor = 'not-allowed';
        });
        
        // Hide Save Changes button, show Update button
        const updateBtn = document.getElementById('updateBtn');
        const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
        if (updateBtn) updateBtn.style.display = 'none';
        if (updateTriggerBtn) updateTriggerBtn.style.display = 'inline-block';
    }
    
    // Confirm clear data
    function confirmClearData() {
        if (confirm('Are you sure you want to clear all data? This action cannot be undone!')) {
            window.location.href = '?action=clear_data';
            return true;
        }
        return false;
    }
    
    // Clear file status displays
    function clearFileStatusDisplays() {
        const statusDivs = document.querySelectorAll('[id$="_status"]');
        statusDivs.forEach(div => {
            div.innerHTML = '<span class="text-muted">No document uploaded</span>';
            div.className = 'mt-2 text-muted';
        });
    }
    
    
    // Initialize form on page load
    // CRITICAL: Prevent duplicate page initialization
    let pageInitialized = false;

    // Initialize page - SINGLE initialization point
    function initializePage() {
        if (pageInitialized) {
            return; // Already initialized - prevent duplicate calls
        }
        pageInitialized = true;
        
        // Check if data was just cleared (no existing data)
        <?php if (!$existing_data): ?>
        // Clear file status displays if no existing data
        clearFileStatusDisplays();
        <?php else: ?>
        // Load existing documents if data exists
        loadExistingDocuments();
        <?php endif; ?>
        
        // Lock form if it should be locked (when existing data is present)
        <?php if ($form_locked): ?>
        setTimeout(function() {
            disableForm();
        }, 500);
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
</script>

<!-- Department Details Content -->
<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
        <div>
            <h1 class="page-title">
                <i class="fas fa-building"></i>Department Details
            </h1>
            <p class="page-subtitle">Manage comprehensive department information and faculty details</p>
        </div>
        <a href="export_page_pdf.php?page=DetailsOfDepartment" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
            <i class="fas fa-file-pdf"></i> Download as PDF
        </a>
    </div>
</div>

                <?php if ($success_message): ?>
    <div class="alert alert-success animate-fadeIn">
                        <i class="fas fa-check-circle"></i><?php echo $success_message; ?>
                    </div>
                    <script>
                        setTimeout(function() {
            const successAlert = document.querySelector('.alert-success');
                            if (successAlert) {
                                successAlert.style.transition = 'opacity 0.5s';
                                successAlert.style.opacity = '0';
                                setTimeout(function() {
                                    if (successAlert.parentNode) {
                                        successAlert.remove();
                                    }
                                }, 500);
                            }
        }, 3000);
                    </script>
                <?php endif; ?>

                <?php if ($error_message): ?>
    <div class="alert alert-danger animate-fadeIn">
                        <i class="fas fa-exclamation-triangle"></i><?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($form_locked): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-lock me-2"></i><strong>Form Completed!</strong> Your department details have been successfully submitted. Click "Update" to modify existing data.
                    </div>
                <?php endif; ?>

<!-- Instructions -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-info-circle"></i>Instructions
        </h3>
    </div>
    <div class="card-body">
                    <ul class="mb-0">
                        <li><strong>Compulsory Fields:</strong> HoD Details and IQAC Coordinator Details must be filled</li>
                        <li><strong>Profile Information:</strong> Year of Establishment, Category, and Areas of Research are managed in the Profile page</li>
                        <li><strong>Faculty Counts:</strong> Enter actual numbers or 0 if not applicable. Only Permanent Faculty positions count against Sanctioned Teaching Faculty limit</li>
                        <li><strong>Mobile Numbers:</strong> Enter exactly 10 digits (e.g., 9876543210)</li>
                        <li><strong>Year Format:</strong> Enter 4-digit year (e.g., 2020)</li>
                        <li><strong>PDF Documents:</strong> Upload supporting documents for PhD faculties (Permanent & Adhoc) - PDF format only</li>
                        <li><strong>Validation:</strong> Form will validate data before submission and highlight any errors</li>
                        <li><strong>Save & Update:</strong> After submission, use "Update" to modify data or "Clear Data" to start fresh</li>
                    </ul>
    </div>
                </div>

<form class="card" method="POST" enctype="multipart/form-data" autocomplete="off" id="my_form">
    <?php 
    // Add CSRF token to form
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        if (function_exists('csrf_field')) {
            echo csrf_field();
        }
    }
    ?>
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-edit"></i>Department Information Form
        </h3>
    </div>
    <div class="card-body">


        <!-- Head of Department Details -->
        <div class="form-section mb-6">
            <h4 class="section-title">
                <i class="fas fa-user-tie"></i>Head of Department (HoD)/Director Details
            </h4>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Name of the current HoD/Director</label>
                        <input type="text" id="hod_name" name="hod_name" placeholder="Enter Name" class="form-control" value="<?php echo $existing_data ? htmlspecialchars($existing_data['HOD_NAME']) : htmlspecialchars($master_hod_name); ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Email of the HoD/Director</label>
                        <input type="email" id="hod_email" name="hod_email" placeholder="Enter Email" class="form-control" value="<?php echo ($existing_data && !empty($existing_data['HOD_EMAIL'])) ? htmlspecialchars($existing_data['HOD_EMAIL']) : ''; ?>" <?php echo ($existing_data && !empty($existing_data['HOD_EMAIL'])) ? $readonly_attr : ''; ?> required>
                        
                        <div class="form-text">Enter the HoD's personal email address. <?php echo ($existing_data && !empty($existing_data['HOD_EMAIL'])) ? 'This field is locked because it has been previously saved.' : 'This field will be locked after form submission.'; ?></div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="mobile" class="form-label">Mobile Number of the HoD/Director</label>
                        <input type="tel" id="mobile" name="hod_mobile" placeholder="Enter Mobile Number" minlength="10" maxlength="10" oninput="numbersOnly_hod(); validateMobile(this);"
                            title="Enter a 10-digit mobile number" pattern="[0-9]{10}" value="<?php echo $existing_data ? htmlspecialchars($existing_data['HOD_MOBILE']) : ''; ?>" required>
                        <div class="form-text">Enter exactly 10 digits (e.g., 9876543210)</div>
                    </div>
                </div>
            </div>
                    </div>
                    
                    <!-- IQAC Coordinator Details -->
        <div class="form-section mb-6">
            <h4 class="section-title">
                <i class="fas fa-user-cog"></i>IQAC Coordinator Details
            </h4>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="iqac_name" class="form-label">Name of the IQAC Coordinator</label>
                        <input type="text" id="iqac_name" name="iqac_coordinator_name" placeholder="Enter Name" class="form-control" value="<?php echo $existing_data ? htmlspecialchars($existing_data['IQAC_COORDINATOR_NAME']) : ''; ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="iqac_email" class="form-label">Email of the IQAC Coordinator</label>
                        <input type="email" id="iqac_email" name="iqac_coordinator_email" placeholder="Enter Email" class="form-control" value="<?php echo $existing_data ? htmlspecialchars($existing_data['IQAC_COORDINATOR_EMAIL']) : ''; ?>" required>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="mobile_iqac" class="form-label">Mobile Number of the IQAC Coordinator</label>
                        <input type="tel" id="mobile_iqac" name="iqac_coordinator_mobile" placeholder="Enter Mobile Number"
                            minlength="10" maxlength="10" oninput="numbersOnly(); validateMobile(this);" title="Enter a 10-digit mobile number" pattern="[0-9]{10}" value="<?php echo $existing_data ? htmlspecialchars($existing_data['IQAC_COORDINATOR_MOBILE']) : ''; ?>" required>
                        <div class="form-text">Enter exactly 10 digits (e.g., 9876543210)</div>
                    </div>
                </div>
            </div>
        </div>
            <hr>
            <!-- <h2>Faculty Details</h2> -->
            <div class="mb-3">
                <label for="sanctioned_faculty">Sanctioned Teaching Faculty</label>
                        <input type="number" id="sanctioned_faculty" name="sanctioned_teaching_faculty" min="0"
                            placeholder="Enter Count" class="form-control" oninput="validatePositiveNumber(this);" value="<?php echo $existing_data ? $existing_data['SANCTIONED_TEACHING_FACULTY'] : ''; ?>" required>
                        <div class="form-text">Enter a positive number or 0. This limit applies only to Permanent Faculty positions.</div>
                    </div>
            
            <div class="form-group">
                <label>Number of Permanent Faculties (Counts against Sanctioned Teaching Faculty)</label>
                <div class="alert alert-info" style="margin-bottom: 15px;">
                    <strong>Note:</strong> The sum of all Permanent Faculty positions must not exceed the Sanctioned Teaching Faculty limit. Professor Practice, PM Professors, and Contract Teachers are separate categories and do not count against this limit.
                </div>
                <div class="mb-3">
                    <div class="row">
                        <div class="col-md-6">
                            <label style="font-weight:normal;">Number of Professor</label>
                            <div class="row">
                                <div class="col-6">
                                    <input type="number" id="prof_direct" name="prof_direct" min="0" value="<?php echo $existing_data ? $existing_data['PROF_DIRECT'] : '0'; ?>"
                                        placeholder="Direct" class="form-control" oninput="validatePositiveNumber(this);" required>
                                    <div class="form-text">Direct Recruitment</div>
                    </div>
                                <div class="col-6">
                                    <input type="number" id="prof_cas" name="prof_cas" min="0" value="<?php echo $existing_data ? $existing_data['PROF_CAS'] : '0'; ?>"
                                        placeholder="CAS" class="form-control" oninput="validatePositiveNumber(this);" required>
                                    <div class="form-text">CAS</div>
                    </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label style="font-weight:normal;">Associate Professor</label>
                            <div class="row">
                                <div class="col-6">
                                    <input type="number" id="assoc_prof_direct" name="assoc_prof_direct" min="0" value="<?php echo $existing_data ? $existing_data['ASSOC_PROF_DIRECT'] : '0'; ?>"
                                        placeholder="Direct" class="form-control" oninput="validatePositiveNumber(this);" required>
                                    <div class="form-text">Direct Recruitment</div>
                                </div>
                                <div class="col-6">
                                    <input type="number" id="assoc_prof_cas" name="assoc_prof_cas" min="0" value="<?php echo $existing_data ? $existing_data['ASSOC_PROF_CAS'] : '0'; ?>"
                                        placeholder="CAS" class="form-control" oninput="validatePositiveNumber(this);" required>
                                    <div class="form-text">CAS</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label for="asst_professors" style="font-weight:normal;">Assistant Professors</label>
                            <input type="number" id="asst_professors" name="permanent_assistant_professors" min="0" value="<?php echo $existing_data ? $existing_data['PERMANENT_ASSISTANT_PROFESSORS'] : '0'; ?>"
                            placeholder="Enter Count" class="form-control" oninput="validatePositiveNumber(this);" required>
                            <div class="form-text">Position: Assistant Professor</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Professor Practice</label>
                <div class="mb-3">
                    <div>
                        <label for="prof_practice" style="font-weight:normal;">Professor Practice (Count)</label>
                        <input type="number" id="prof_practice" name="professor_of_practice_associate" min="0" value="<?php echo $existing_data ? $existing_data['PROFESSOR_OF_PRACTICE_ASSOCIATE'] : '0'; ?>"
                            placeholder="Enter Count" class="form-control" oninput="validatePositiveNumber(this);" required>
                    </div>
                    <div>
                        <label for="assoc_prof_practice" style="font-weight:normal;">Associate Professor Practice</label>
                        <input type="number" id="assoc_prof_practice" name="associate_professor_of_practice" min="0" value="<?php echo $existing_data ? $existing_data['ASSOCIATE_PROFESSOR_OF_PRACTICE'] : '0'; ?>"
                            placeholder="Enter Count" class="form-control" oninput="validatePositiveNumber(this);" required>
                    </div>
                    <div>
                        <label for="asst_prof_practice" style="font-weight:normal;">Assistant Professor Practice</label>
                        <input type="number" id="asst_prof_practice" name="assistant_professor_of_practice" min="0" value="<?php echo $existing_data ? $existing_data['ASSISTANT_PROFESSOR_OF_PRACTICE'] : '0'; ?>"
                            placeholder="Enter Count" class="form-control" oninput="validatePositiveNumber(this);" required>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="pm_professor">PM Professor (ANRF)</label>
                <input type="number" id="pm_professor" name="pm_professor" min="0" value="<?php echo $existing_data ? $existing_data['PM_PROFESSOR'] : '0'; ?>" placeholder="Enter Count"
                    class="form-control" oninput="validatePositiveNumber(this); validateFacultyTotals();" required>
            </div>

            <div class="form-group">
                <label for="contract_teachers">Number of Ad hoc/Contract Teachers</label>
                <input type="number" id="contract_teachers" name="contract_teachers" min="0" value="<?php echo $existing_data ? $existing_data['CONTRACT_TEACHERS'] : '0'; ?>" placeholder="Enter Count"
                    class="form-control" oninput="validatePositiveNumber(this); validateFacultyTotals();" required>
            </div>

            <!-- Supporting Document for Faculty Positions -->
            <div class="form-group">
                <label class="form-label fw-bold mb-3"><b>Supporting Document for Faculty Positions <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for faculty positions (Professor, Associate Professor, Assistant Professor, Professor Practice, PM Professor, Ad hoc/Contract Teachers). This should include official documents, contracts, or agreements showing the faculty details.
                </div>
                <div class="input-group">
                    <input type="file" id="faculty_positions_pdf" name="faculty_positions_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('faculty_positions_pdf', 10)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="faculty_positions_pdf_status" class="mt-2"></div>
            </div>

            <!-- International Faculty Fields -->
            <div class="form-group">
                <label>International Faculty</label>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Male</th>
                                <th>Female</th>
                                <th>Other</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Inbound</strong></td>
                                <td>
                                    <input type="number" id="male_faculty_inbound" name="male_faculty_inbound" min="0" value="<?php echo $existing_data ? $existing_data['MALE_FACULTY_INBOUND'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                                <td>
                                    <input type="number" id="female_faculty_inbound" name="female_faculty_inbound" min="0" value="<?php echo $existing_data ? $existing_data['FEMALE_FACULTY_INBOUND'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                                <td>
                                    <input type="number" id="other_faculty_inbound" name="other_faculty_inbound" min="0" value="<?php echo $existing_data ? $existing_data['OTHER_FACULTY_INBOUND'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Outbound</strong></td>
                                <td>
                                    <input type="number" id="male_faculty_outbound" name="male_faculty_outbound" min="0" value="<?php echo $existing_data ? $existing_data['MALE_FACULTY_OUTBOUND'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                                <td>
                                    <input type="number" id="female_faculty_outbound" name="female_faculty_outbound" min="0" value="<?php echo $existing_data ? $existing_data['FEMALE_FACULTY_OUTBOUND'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                                <td>
                                    <input type="number" id="other_faculty_outbound" name="other_faculty_outbound" min="0" value="<?php echo $existing_data ? $existing_data['OTHER_FACULTY_OUTBOUND'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="form-text">Enter the number of registered international faculty members. If none, leave as 0.</div>
            </div>
            <!-- PhD Faculties Section -->
            <div class="form-group">
                <label for="num_perm_phd">Number of Permanent Faculties with Ph.D</label>
                <input type="number" id="num_perm_phd" name="num_perm_phd" min="0" value="<?php echo $existing_data ? $existing_data['NUM_PERM_PHD'] : '0'; ?>" placeholder="Enter Count"
                    class="form-control" oninput="validatePositiveNumber(this)" required>
                <div class="form-text">If none, leave as 0.</div>
            </div>

            <div class="form-group">
                <label for="num_adhoc_phd">Number of Adhoc Faculties with Ph.D</label>
                <input type="number" id="num_adhoc_phd" name="num_adhoc_phd" min="0" value="<?php echo $existing_data ? $existing_data['NUM_ADHOC_PHD'] : '0'; ?>" placeholder="Enter Count"
                    class="form-control" oninput="validatePositiveNumber(this)" required>
                <div class="form-text">If none, leave as 0.</div>
            </div>

            <!-- PDF Upload Section -->
            <div class="form-group">
                <label>Supporting Documents for PhD Faculties <span class="text-danger">*</span></label>
                <div class="alert alert-info" style="margin-bottom: 15px;">
                    <strong>📄 File Upload Guidelines:</strong>
                    <ul class="mb-0 mt-2">
                        <li><strong>File Format:</strong> PDF files only</li>
                        <li><strong>Maximum Size:</strong> 5 MB per file</li>
                        <li><strong>File Name:</strong> Use descriptive names (e.g., "PhD_Permanent_Faculty_2024.pdf")</li>
                        <li><strong>Quality:</strong> Ensure documents are clear and readable</li>
                    </ul>
                    <div class="mt-2">
                        <small class="text-muted">
                            <strong>💡 File Too Large?</strong> Try compressing your PDF using online tools like SmallPDF, ILovePDF, or Adobe Acrobat's "Reduce File Size" feature.
                        </small>
                    </div>
                </div>
                <div class="mb-3">
                    <div>
                        <label for="perm_phd_pdf" style="font-weight:normal;">Permanent Faculties with PhD - Supporting Document</label>
                        <div class="input-group">
                            <input type="file" id="perm_phd_pdf" name="perm_phd_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('perm_phd_pdf', 1)" <?php echo $readonly_attr; ?>>Upload</button>
                        </div>
                        <div id="perm_phd_pdf_status" class="mt-2"></div>
                    </div>
                    <div>
                        <label for="adhoc_phd_pdf" style="font-weight:normal;">Adhoc Faculties with PhD - Supporting Document</label>
                        <div class="input-group">
                            <input type="file" id="adhoc_phd_pdf" name="adhoc_phd_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('adhoc_phd_pdf', 2)" <?php echo $readonly_attr; ?>>Upload</button>
                        </div>
                        <div id="adhoc_phd_pdf_status" class="mt-2"></div>
                    </div>
                </div>
            </div>

            <hr>
            <!-- <h2>Academic & Research Details</h2> -->
            <!-- <div class="mb-3">
                <label for="form-label">Certificate/Diploma/UG/PG Programmes Offered (with intake) * </label>
                <textarea id="programs_offered" name="programmes_offered" style="width: 100%; height: 180px;"
                    placeholder="with intake"></textarea>
            </div> -->

            <div class="form-group">
                <label>Strength of Non-teaching Employee</label>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Class</th>
                                <th>Permanent</th>
                                <th>Temporary</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Class I</strong></td>
                                <td>
                                    <input type="number" id="class_1_perm" name="class_1_perm" min="0" value="<?php echo $existing_data ? $existing_data['CLASS_1_PERM'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                                <td>
                                    <input type="number" id="class_1_temp" name="class_1_temp" min="0" value="<?php echo $existing_data ? $existing_data['CLASS_1_TEMP'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Class II</strong></td>
                                <td>
                                    <input type="number" id="class_2_perm" name="class_2_perm" min="0" value="<?php echo $existing_data ? $existing_data['CLASS_2_PERM'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                                <td>
                                    <input type="number" id="class_2_temp" name="class_2_temp" min="0" value="<?php echo $existing_data ? $existing_data['CLASS_2_TEMP'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Class III</strong></td>
                                <td>
                                    <input type="number" id="class_3_perm" name="class_3_perm" min="0" value="<?php echo $existing_data ? $existing_data['CLASS_3_PERM'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                                <td>
                                    <input type="number" id="class_3_temp" name="class_3_temp" min="0" value="<?php echo $existing_data ? $existing_data['CLASS_3_TEMP'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Class IV</strong></td>
                                <td>
                                    <input type="number" id="class_4_perm" name="class_4_perm" min="0" value="<?php echo $existing_data ? $existing_data['CLASS_4_PERM'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                                <td>
                                    <input type="number" id="class_4_temp" name="class_4_temp" min="0" value="<?php echo $existing_data ? $existing_data['CLASS_4_TEMP'] : '0'; ?>" placeholder="0" class="form-control" oninput="validatePositiveNumber(this)" required>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mb-3">
                <label for="apprenticeships_interns">Number of Apprenticeships/Interns (OJT)</label>
                <input type="number" id="apprenticeships_interns" name="apprenticeships_interns" min="0" value="<?php echo $existing_data ? $existing_data['APPRENTICESHIPS_INTERNS'] : '0'; ?>"
                    placeholder="Enter Count" class="form-control" oninput="validatePositiveNumber(this)" required>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="card-footer">
            <div class="action-buttons">
                            <?php if ($form_locked): ?>
                    <button type="button" class="btn btn-warning" onclick="enableUpdate()">
                                    <i class="fas fa-edit"></i>Update
                                </button>
                    <button type="submit" name="update_data" class="btn btn-success" id="updateBtn" style="display:none;">
                                    <i class="fas fa-save"></i>Save Changes
                                </button>
                    <a href="?action=clear_data" class="btn btn-danger" onclick="return confirmClearData()">
                                    <i class="fas fa-trash"></i>Clear Data
                                </a>
                            <?php else: ?>
                    <button type="submit" name="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i>Submit Details
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
            </div>
</form>

<style>
/* Soft Message Styles */
.soft-message {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    color: white;
    font-weight: 500;
    z-index: 9999;
    max-width: 400px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    animation: slideInRight 0.3s ease-out;
}

.soft-message.success {
    background: linear-gradient(135deg, #10b981, #059669);
}

.soft-message.error {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}

.soft-message.info {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Form Section Styles */
.form-section {
    background: var(--gray-50);
    border-radius: var(--radius-lg);
    padding: var(--spacing-6);
    border: 1px solid var(--gray-200);
}

.section-title {
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: var(--spacing-4);
    display: flex;
    align-items: center;
    gap: var(--spacing-2);
    padding-bottom: var(--spacing-2);
    border-bottom: 2px solid var(--primary-color);
}

.section-title i {
    color: var(--primary-color);
}

/* Table Styles */
.table-container {
    background: var(--white);
    border-radius: var(--radius-lg);
    overflow: hidden;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}

.table th {
    background: var(--primary-color);
    color: var(--white);
    font-weight: 600;
    font-size: var(--font-size-sm);
    padding: var(--spacing-4);
    text-align: left;
    border: none;
}

.table td {
    padding: var(--spacing-4);
    border-bottom: 1px solid var(--gray-200);
    font-size: var(--font-size-sm);
    color: var(--gray-700);
}

.table tbody tr:hover {
    background: var(--gray-50);
}

.table tbody tr:last-child td {
    border-bottom: none;
}

/* File Upload Styles */
.file-upload-section {
    background: var(--gray-50);
    border-radius: var(--radius-lg);
    padding: var(--spacing-4);
    border: 2px dashed var(--gray-300);
    transition: var(--transition-fast);
}

.file-upload-section:hover {
    border-color: var(--primary-color);
    background: rgba(37, 99, 235, 0.05);
}

.upload-info {
    background: var(--info-color);
    color: var(--white);
    padding: var(--spacing-3);
    border-radius: var(--radius-md);
    margin-bottom: var(--spacing-4);
}

.upload-info h6 {
    margin: 0;
    font-weight: 600;
}

.upload-info ul {
    margin: var(--spacing-2) 0 0 0;
    padding-left: var(--spacing-4);
}

.upload-info li {
    font-size: var(--font-size-sm);
    margin-bottom: var(--spacing-1);
}

/* Button Styles */
.action-buttons {
    display: flex;
    gap: var(--spacing-3);
    justify-content: center;
    flex-wrap: wrap;
    margin-top: var(--spacing-6);
    padding-top: var(--spacing-4);
    border-top: 1px solid var(--gray-200);
}

/* Validation Styles */
.is-invalid {
    border-color: var(--danger-color) !important;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
}

.is-valid {
    border-color: var(--success-color) !important;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1) !important;
}

/* Shake animation for validation errors */
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
}

.shake {
    animation: shake 0.5s;
}

/* Form Lock Styles */
.form-control[readonly] {
    background-color: #f8f9fa !important;
    cursor: not-allowed !important;
    opacity: 0.8;
}

.form-control[disabled] {
    background-color: #f8f9fa !important;
    cursor: not-allowed !important;
    opacity: 0.8;
}

.btn[disabled] {
    opacity: 0.6;
    cursor: not-allowed !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .form-section {
        padding: var(--spacing-4);
    }
    
    .section-title {
        font-size: var(--font-size-base);
    }
    
    .action-buttons {
        flex-direction: column;
        align-items: center;
    }
    
    .btn {
        width: 100%;
        max-width: 300px;
    }
}
</style>

<script>
// Mobile number validation functions
    function numbersOnly_hod() {
        var textInput = document.getElementById("mobile").value;
        textInput = textInput.replace(/[^0-9]/g, "");
        if (textInput.length > 10) {
            textInput = textInput.substring(0, 10);
        }
        document.getElementById("mobile").value = textInput;
    }
    
    function numbersOnly() {
        var textInput = document.getElementById("mobile_iqac").value;
        textInput = textInput.replace(/[^0-9]/g, "");
        if (textInput.length > 10) {
            textInput = textInput.substring(0, 10);
        }
        document.getElementById("mobile_iqac").value = textInput;
    }
    
    function validateMobile(input) {
        if (input.value.length === 10) {
        input.classList.add('is-valid');
        input.classList.remove('is-invalid');
        } else {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        }
    }
</script>

<?php require "unified_footer.php"; ?>