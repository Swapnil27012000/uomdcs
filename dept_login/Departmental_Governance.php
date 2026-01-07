<?php
// Start session first - before any other code
// Departmental_Governance - Governance form
require('session.php');

// Suppress error reporting and start output buffering
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

// ============================================================================
// PDF UPLOAD HANDLING - MUST BE BEFORE ANY HTML OUTPUT
// ============================================================================

// Handle PDF uploads - Route through common_upload_handler.php
// CRITICAL: Follow DOCUMENT_HANDLING_GUIDE.md
if (isset($_POST['upload_document'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session and load config FIRST (minimal requirements for AJAX)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config for database connection - suppress any output
    ob_start();
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    ob_end_clean();
    
    // Load session for user info - suppress any output
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    ob_end_clean();
    
    // Clear all output buffers again
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Suppress all errors and warnings
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    // CRITICAL #4: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Initialize response in variable - build response first, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
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
                $response = ['success' => false, 'message' => 'Security token validation failed. Please refresh the page and try again.'];
            } else {
                // Route through common_upload_handler.php
                require_once(__DIR__ . '/common_upload_handler.php');
                
                // Set required variables for common handler
                $dept_id = $userInfo['DEPT_ID'] ?? 0;
                if (!$dept_id) {
                    $response = ['success' => false, 'message' => 'Department ID not found. Please login again.'];
                } else {
                    // Calculate academic year - use centralized function if available
                    if (!function_exists('getAcademicYear')) {
                        if (file_exists(__DIR__ . '/common_functions.php')) {
                            require_once(__DIR__ . '/common_functions.php');
                        }
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
                    
                    // Set global variables for common_upload_handler.php
                    $GLOBALS['dept_id'] = $dept_id;
                    $GLOBALS['A_YEAR'] = $A_YEAR;
                    
                    // Call the handler function
                    $result = handleDocumentUpload('departmental_governance', 'Departmental Governance', [
                        'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/departmental_governance/",
                        'max_size' => 10,
                        'document_title' => 'Departmental Governance Documentation',
                        'srno' => (int)($_POST['srno'] ?? 1),
                        'file_id' => $_POST['file_id'] ?? 'gov_doc'
                    ]);
                    
                    // CRITICAL: Ensure result is an array and normalize file path for web access
                    if (!is_array($result)) {
                        $response = ['success' => false, 'message' => 'Invalid response from upload handler'];
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
                        $response = $result;
                    }
                }
            }
        } else {
            // No CSRF validation available, proceed without it
            require_once(__DIR__ . '/common_upload_handler.php');
            
            $dept_id = $userInfo['DEPT_ID'] ?? 0;
            if (!$dept_id) {
                $response = ['success' => false, 'message' => 'Department ID not found. Please login again.'];
            } else {
                $current_year = (int)date('Y');
                $current_month = (int)date('n');
                // FIXED: July onwards (month >= 7) = current-next, Jan-June = (current-2)-(current-1)
                $A_YEAR = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 2) . '-' . ($current_year - 1);
                
                // Set global variables for common_upload_handler.php
                $GLOBALS['dept_id'] = $dept_id;
                $GLOBALS['A_YEAR'] = $A_YEAR;
                
                $result = handleDocumentUpload('departmental_governance', 'Departmental Governance', [
                    'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/departmental_governance/",
                    'max_size' => 10,
                    'document_title' => 'Departmental Governance Documentation',
                    'srno' => (int)($_POST['srno'] ?? 1),
                    'file_id' => $_POST['file_id'] ?? 'gov_doc'
                ]);
                
                // CRITICAL: Ensure result is an array and normalize file path for web access
                if (!is_array($result)) {
                    $response = ['success' => false, 'message' => 'Invalid response from upload handler'];
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
                    $response = $result;
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()];
    } catch (Error $e) {
        $response = ['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #2: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle PDF deletion - MUST BE FIRST
if (isset($_POST['delete_document'])) {
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
        $srno = (int)($_POST['srno'] ?? 0);
        
        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found.'];
        } elseif ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Invalid serial number.'];
        } else {
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            $A_YEAR = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
            
            // Get file path using prepared statement - MUST include section_name to match upload handler
            $section_name = 'Departmental Governance';
            // CRITICAL FIX: Query with fallback to check current year AND previous year
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
            
            $get_file_query = "SELECT file_path, academic_year FROM supporting_documents 
                              WHERE dept_id = ? AND page_section = 'departmental_governance' 
                              AND serial_number = ? AND section_name = ? 
                              AND (academic_year = ? OR academic_year = ?) AND status = 'active' 
                              ORDER BY academic_year DESC, id DESC LIMIT 1";
            $stmt = mysqli_prepare($conn, $get_file_query);
            if (!$stmt) {
                $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
            } else {
                mysqli_stmt_bind_param($stmt, 'iisss', $dept_id, $srno, $section_name, $A_YEAR, $prev_year);
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if ($result && mysqli_num_rows($result) > 0) {
                        $row = mysqli_fetch_assoc($result);
                        $file_path = $row['file_path'];
                        $doc_year = $row['academic_year'] ?? $A_YEAR;
                        mysqli_free_result($result);  // CRITICAL: Free result
                        mysqli_stmt_close($stmt);
                        
                        // Delete file from filesystem (use document's actual academic year)
                        $project_root = dirname(__DIR__);
                        $physical_path = $file_path;
                        
                        // Handle different path formats
                        if (strpos($file_path, $project_root) === 0) {
                            // Already absolute path
                            $physical_path = $file_path;
                        } elseif (strpos($file_path, '../') === 0) {
                            $physical_path = $project_root . '/' . str_replace('../', '', $file_path);
                        } elseif (strpos($file_path, 'uploads/') === 0) {
                            $physical_path = $project_root . '/' . $file_path;
                        } else {
                            // Fallback: construct path using document's academic year
                            $filename = basename($file_path);
                            $physical_path = $project_root . "/uploads/{$doc_year}/DEPARTMENT/{$dept_id}/departmental_governance/{$filename}";
                        }
                        $physical_path = str_replace('\\', '/', $physical_path);
                        
                        if ($physical_path && file_exists($physical_path)) {
                            @unlink($physical_path);
                        }
                        
                        // Soft delete using prepared statement (use document's actual academic year)
                        $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'departmental_governance' AND serial_number = ? AND section_name = ? AND status = 'active'";
                        $delete_stmt = mysqli_prepare($conn, $delete_query);
                        if (!$delete_stmt) {
                            $response = ['success' => false, 'message' => 'Database error: Failed to prepare delete query'];
                        } else {
                            mysqli_stmt_bind_param($delete_stmt, 'siis', $doc_year, $dept_id, $srno, $section_name);
                            if (mysqli_stmt_execute($delete_stmt)) {
                                $response = ['success' => true, 'message' => 'Document deleted successfully'];
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
                        $response = ['success' => false, 'message' => 'Document not found'];
                    }
                } else {
                    mysqli_stmt_close($stmt);
                    $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
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

// Handle document status check - MUST BE FIRST
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
        // Route through common_upload_handler.php
        require_once(__DIR__ . '/common_upload_handler.php');
        
        // Set required variables for common handler
        $dept_id = $userInfo['DEPT_ID'] ?? 0;
        $dept_code = $userInfo['DEPT_COLL_NO'] ?? '';
        
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found.'];
        } else {
            // Calculate academic year - MUST match upload handler logic
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            $A_YEAR = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
            
            // Set global variables for common handler (handleDocumentCheck uses global $A_YEAR, $dept_id)
            // Use $GLOBALS to set global variables
            $GLOBALS['dept_id'] = $dept_id;
            $GLOBALS['A_YEAR'] = $A_YEAR;
            
            // Use common check handler - need to pass srno from GET
            $srno = (int)($_GET['srno'] ?? 0);
            if ($srno <= 0) {
                $response = ['success' => false, 'message' => 'Serial number is required.'];
            } else {
                // For departmental_governance, we need to check with section_name
                // Since all documents use 'Departmental Governance' as section_name
                $section_name = 'Departmental Governance';
                
                // CRITICAL FIX: Query with fallback to check current year AND previous year
                // This handles cases where documents were uploaded in previous academic year
                $page_section = 'departmental_governance';
                
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
                
                $get_file_query = "SELECT file_path, file_name, upload_date, id as document_id, academic_year 
                    FROM supporting_documents 
                    WHERE dept_id = ? AND page_section = ? AND serial_number = ? AND section_name = ? 
                    AND (academic_year = ? OR academic_year = ?) AND status = 'active' 
                    ORDER BY academic_year DESC, id DESC LIMIT 1";
                
                $stmt = mysqli_prepare($conn, $get_file_query);
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
                } else {
                    mysqli_stmt_bind_param($stmt, "isiss", $dept_id, $page_section, $srno, $section_name, $A_YEAR, $prev_year);
                    
                    if (!mysqli_stmt_execute($stmt)) {
                        $error = mysqli_stmt_error($stmt);
                        mysqli_stmt_close($stmt);
                        $response = ['success' => false, 'message' => 'Database error: ' . $error];
                    } else {
                        $result = mysqli_stmt_get_result($stmt);
                        
                        if ($result && mysqli_num_rows($result) > 0) {
                            $row = mysqli_fetch_assoc($result);
                            $doc_year = $row['academic_year'] ?? $A_YEAR;
                            mysqli_free_result($result);
                            mysqli_stmt_close($stmt);
                            
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
                                $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/departmental_governance/' . $filename;
                            }
                            
                            $response = [
                                'success' => true,
                                'file_path' => $file_path,
                                'file_name' => $row['file_name'] ?? basename($file_path),
                                'upload_date' => $row['upload_date'] ?? date('Y-m-d H:i:s'),
                                'document_id' => $row['document_id'] ?? null
                            ];
                        } else {
                            if ($result) {
                                mysqli_free_result($result);
                            }
                            mysqli_stmt_close($stmt);
                            $response = ['success' => false, 'message' => 'No document found'];
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    } catch (Error $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #2: Output response ONCE at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// HANDLE check_all_docs - BATCH endpoint to get ALL document statuses in ONE query
// This replaces 22 individual queries with a single efficient query
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
            // For departmental_governance, we need to include section_name in the query
            $batch_query = "SELECT serial_number, file_path, file_name, upload_date, id, section_name, academic_year
                           FROM supporting_documents 
                           WHERE dept_id = ? AND page_section = 'departmental_governance' 
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
                                $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/departmental_governance/' . $filename;
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

// Get department ID and set variables early for form processing
$dept_id = $userInfo['DEPT_ID'] ?? 0;
$dept = $dept_id; // Keep $dept for backward compatibility
$dept_code = $userInfo['DEPT_COLL_NO'] ?? '';
$dept_name = $userInfo['DEPT_NAME'] ?? '';

if (!$dept_id) {
    throw new Exception('Department ID not found. Please contact administrator.');
}

// Academic year format - always current year - 1 to current year
$current_year = date('Y');
$A_YEAR = ($current_year - 1) . '-' . $current_year;

// Add missing columns if they don't exist
$columns_to_add = [
    'DEPT_ID' => 'INT DEFAULT NULL AFTER id',
    'inclusive_practices' => 'TEXT DEFAULT NULL',
    'inclusive_practices_details' => 'TEXT DEFAULT NULL',
    'green_practices' => 'TEXT DEFAULT NULL',
    'green_practices_details' => 'TEXT DEFAULT NULL',
    'teachers_in_admin' => 'INT DEFAULT 0',
    'total_teachers' => 'INT DEFAULT 0',
    'awards_extension' => 'INT DEFAULT 0',
    'heads_expenditure' => 'TEXT DEFAULT NULL',
    'budget_allocated' => 'DECIMAL(10,2) DEFAULT 0.00',
    'budget_utilized' => 'DECIMAL(10,2) DEFAULT 0.00',
    'alumni_contribution' => 'DECIMAL(10,2) DEFAULT 0.00',
    'alumni_details' => 'TEXT DEFAULT NULL',
    'csr_details' => 'TEXT DEFAULT NULL',
    'csr_funding' => 'DECIMAL(10,2) DEFAULT 0.00',
    'infrastructure_details' => 'TEXT DEFAULT NULL',
    'infrastructure_infrastructural' => 'TEXT DEFAULT NULL',
    'infrastructure_it_digital' => 'TEXT DEFAULT NULL',
    'infrastructure_library' => 'TEXT DEFAULT NULL',
    'infrastructure_laboratory' => 'TEXT DEFAULT NULL',
    'peer_perception_rate' => 'VARCHAR(255) DEFAULT NULL',
    'peer_perception_notes' => 'TEXT DEFAULT NULL',
    'student_feedback_rate' => 'VARCHAR(255) DEFAULT NULL',
    'student_feedback_notes' => 'TEXT DEFAULT NULL',
    'best_practice' => 'TEXT DEFAULT NULL',
    'leadership_sync' => 'TEXT DEFAULT NULL',
    'isr_total' => 'INT DEFAULT 0',
    'isr_budget_percent' => 'DECIMAL(5,2) DEFAULT 0.00',
    'isr_students_percent' => 'DECIMAL(5,2) DEFAULT 0.00',
    'isr_faculty_percent' => 'DECIMAL(5,2) DEFAULT 0.00',
    'sponsors_total' => 'INT DEFAULT 0',
    'sponsors_amount' => 'DECIMAL(10,2) DEFAULT 0.00',
    'isr_volunteer_hours' => 'INT DEFAULT 0',
    'isr_active_partnerships' => 'INT DEFAULT 0',
    'isr_partners_notes' => 'TEXT DEFAULT NULL',
    'department_plan' => 'TEXT DEFAULT NULL'
];

foreach ($columns_to_add as $column_name => $column_definition) {
    $check_column_query = "SHOW COLUMNS FROM department_data LIKE '$column_name'";
    $check_result = mysqli_query($conn, $check_column_query);
    if (!$check_result || mysqli_num_rows($check_result) == 0) {
        // Column doesn't exist, add it
        $alter_query = "ALTER TABLE department_data ADD COLUMN $column_name $column_definition";
        $alter_result = mysqli_query($conn, $alter_query);
            if (!$alter_result) {
        }
            }
}


// Check if data already exists for this department
$existing_data = null;
$check_existing_query = "SELECT * FROM department_data WHERE DEPT_ID = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $check_existing_query);
    if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $dept_id);
mysqli_stmt_execute($stmt);
            $check_existing = mysqli_stmt_get_result($stmt);
            
            if ($check_existing && mysqli_num_rows($check_existing) > 0) {
                $existing_data = mysqli_fetch_assoc($check_existing);
    }
    mysqli_stmt_close($stmt);
}

// Initialize variables
$form_locked = false;
$success_message = '';
$error_message = '';

// Display session messages
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error_message = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Check if data already exists
if ($existing_data) {
    $form_locked = true;
}

// ============================================================================
// FORM SUBMISSION HANDLER - MUST BE BEFORE HTML OUTPUT
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_governance']) || isset($_POST['update_governance']))) {
    // Clean all output buffers BEFORE any processing
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    try {
        // CSRF validation
        if (file_exists(__DIR__ . '/csrf.php')) {
            require_once(__DIR__ . '/csrf.php');
            if (function_exists('validate_csrf')) {
                $csrf = $_POST['csrf_token'] ?? '';
                if (empty($csrf)) {
                    $_SESSION['error'] = 'Security token missing. Please refresh and try again.';
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: Departmental_Governance.php');
                    }
                    exit;
                }
                if (!validate_csrf($csrf)) {
                    $_SESSION['error'] = 'Security token validation failed. Please refresh and try again.';
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: Departmental_Governance.php');
                    }
                    exit;
    }
}
        }
        
        // Define all expected fields with proper sanitization and defaults
        // Text fields default to '-' if empty, numeric fields default to 0
    $fields = [
            'inclusive_practices' => isset($_POST['inclusive_practices']) && !empty($_POST['inclusive_practices']) ? json_encode($_POST['inclusive_practices'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-',
            'inclusive_practices_details' => !empty(trim($_POST['inclusive_practices_details'] ?? '')) ? htmlspecialchars($_POST['inclusive_practices_details']) : '-',
            'green_practices' => isset($_POST['green_practices']) && !empty($_POST['green_practices']) ? json_encode($_POST['green_practices'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-',
            'green_practices_details' => !empty(trim($_POST['green_practices_details'] ?? '')) ? htmlspecialchars($_POST['green_practices_details']) : '-',
            'teachers_in_admin' => (int)($_POST['teachers_in_admin'] ?? 0),
            'awards_extension' => (int)($_POST['awards_extension'] ?? 0),
            'heads_expenditure' => !empty(trim($_POST['heads_expenditure'] ?? '')) ? htmlspecialchars($_POST['heads_expenditure']) : '-',
            'alumni_contribution' => (float)($_POST['alumni_contribution'] ?? 0.0),
            'alumni_details' => !empty(trim($_POST['alumni_details'] ?? '')) ? htmlspecialchars($_POST['alumni_details']) : '-',
            'csr_funding' => (float)($_POST['csr_funding'] ?? 0.0),
            'csr_details' => !empty(trim($_POST['csr_details'] ?? '')) ? htmlspecialchars($_POST['csr_details']) : '-',
            'infrastructure_details' => !empty(trim($_POST['infrastructure_details'] ?? '')) ? htmlspecialchars($_POST['infrastructure_details']) : '-',
            'infrastructure_infrastructural' => !empty(trim($_POST['infrastructure_infrastructural'] ?? '')) ? htmlspecialchars($_POST['infrastructure_infrastructural']) : '-',
            'infrastructure_it_digital' => !empty(trim($_POST['infrastructure_it_digital'] ?? '')) ? htmlspecialchars($_POST['infrastructure_it_digital']) : '-',
            'infrastructure_library' => !empty(trim($_POST['infrastructure_library'] ?? '')) ? htmlspecialchars($_POST['infrastructure_library']) : '-',
            'infrastructure_laboratory' => !empty(trim($_POST['infrastructure_laboratory'] ?? '')) ? htmlspecialchars($_POST['infrastructure_laboratory']) : '-',
            'peer_perception_rate' => !empty(trim($_POST['peer_perception_rate'] ?? '')) ? htmlspecialchars($_POST['peer_perception_rate']) : '-',
            'peer_perception_notes' => !empty(trim($_POST['peer_perception_notes'] ?? '')) ? htmlspecialchars($_POST['peer_perception_notes']) : '-',
            'student_feedback_rate' => !empty(trim($_POST['student_feedback_rate'] ?? '')) ? htmlspecialchars($_POST['student_feedback_rate']) : '-',
            'student_feedback_notes' => !empty(trim($_POST['student_feedback_notes'] ?? '')) ? htmlspecialchars($_POST['student_feedback_notes']) : '-',
            'best_practice' => !empty(trim($_POST['best_practice'] ?? '')) ? htmlspecialchars($_POST['best_practice']) : '-',
            'leadership_sync' => !empty(trim($_POST['leadership_sync'] ?? '')) ? htmlspecialchars($_POST['leadership_sync']) : '-',
            'isr_total' => (int)($_POST['isr_total'] ?? 0),
            'isr_budget_percent' => (float)($_POST['isr_budget_percent'] ?? 0.0),
            'isr_students_percent' => (float)($_POST['isr_students_percent'] ?? 0.0),
            'isr_faculty_percent' => (float)($_POST['isr_faculty_percent'] ?? 0.0),
            'sponsors_total' => (int)($_POST['sponsors_total'] ?? 0),
            'sponsors_amount' => (float)($_POST['sponsors_amount'] ?? 0.0),
            'isr_volunteer_hours' => (int)($_POST['isr_volunteer_hours'] ?? 0),
            'isr_active_partnerships' => (int)($_POST['isr_active_partnerships'] ?? 0),
            'isr_partners_notes' => !empty(trim($_POST['isr_partners_notes'] ?? '')) ? htmlspecialchars($_POST['isr_partners_notes']) : '-',
            'department_plan' => !empty(trim($_POST['department_plan'] ?? '')) ? htmlspecialchars($_POST['department_plan']) : '-'
        ];

        // Basic validation: require at least one non-empty/non-zero value (excluding defaults)
    $hasAnyInput = false;
        foreach ($fields as $key => $value) {
            if (is_numeric($value)) {
            if ((float)$value > 0) { $hasAnyInput = true; break; }
        } else {
                // Skip validation for default '-' values
                if (trim((string)$value) !== '' && trim((string)$value) !== '-') { 
                    $hasAnyInput = true; 
                    break; 
                }
        }
    }

    if (!$hasAnyInput) {
            $_SESSION['error'] = 'Please enter at least one value before submitting.';
        } else {
            // For single entry system, always update if exists, insert if not
            if ($existing_data) {
                // UPDATE existing record
                $fieldNames = array_keys($fields);
                $updatePlaceholders = implode(' = ?, ', $fieldNames) . ' = ?';
                $updateQuery = "UPDATE department_data SET $updatePlaceholders WHERE DEPT_ID = ?";

        $stmt = $conn->prepare($updateQuery);
                if ($stmt) {
            $types = str_repeat('s', count($fields)) . 'i';
                    $values = array_values($fields);
                    $values[] = $dept_id;

            $stmt->bind_param($types, ...$values);
            if ($stmt->execute()) {
                        // CRITICAL: Clear and recalculate score cache after data update
                        require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                        if (isset($A_YEAR) && !empty($A_YEAR)) {
                            clearDepartmentScoreCache($dept_id, $A_YEAR, true);
                        }
                        $_SESSION['success'] = "Record updated successfully!";
                    } else {
                        throw new Exception("Error updating record: " . mysqli_stmt_error($stmt));
                    }
            $stmt->close();
                } else {
                    throw new Exception("Prepare failed for update: " . $conn->error);
                }
            } else {
                // INSERT new record
                $fieldNames = implode(', ', array_keys($fields));
                $placeholders = rtrim(str_repeat('?,', count($fields)), ',');
                $insertQuery = "INSERT INTO department_data (DEPT_ID, $fieldNames) VALUES (?, $placeholders)";

        $stmt = $conn->prepare($insertQuery);
                if ($stmt) {
            $types = 'i' . str_repeat('s', count($fields));
                    $values = array_values($fields);
                    $stmt->bind_param($types, $dept_id, ...$values);
            if ($stmt->execute()) {
                        // CRITICAL: Clear and recalculate score cache after data insert
                        require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                        if (isset($A_YEAR) && !empty($A_YEAR)) {
                            clearDepartmentScoreCache($dept_id, $A_YEAR, true);
                        }
                        $_SESSION['success'] = "Record added successfully!";
                    } else {
                        throw new Exception("Error adding record: " . mysqli_stmt_error($stmt));
                    }
            $stmt->close();
                } else {
                    throw new Exception("Prepare failed for insert: " . $conn->error);
                }
            }
        }
        
        // Redirect after successful submission - use JavaScript redirect as fallback
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Ensure no output before redirect
        @ini_set('display_errors', 0);
        @error_reporting(0);
        
        if (!headers_sent()) {
            header('Location: Departmental_Governance.php', true, 303);
            header('Cache-Control: no-cache, must-revalidate');
        } else {
            // If headers already sent, use JavaScript redirect
            echo '<script>window.location.href = "Departmental_Governance.php";</script>';
        }
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error processing form: " . $e->getMessage();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Ensure no output before redirect
        @ini_set('display_errors', 0);
        @error_reporting(0);
        
        if (!headers_sent()) {
            header('Location: Departmental_Governance.php', true, 303);
            header('Cache-Control: no-cache, must-revalidate');
        } else {
            // If headers already sent, use JavaScript redirect
            echo '<script>window.location.href = "Departmental_Governance.php";</script>';
        }
        exit;
    }
}

// Handle clear data action (MUST BE BEFORE HTML OUTPUT)
if (isset($_GET['action']) && $_GET['action'] === 'clear_data') {
    // Delete uploaded documents using the unified supporting_documents table
    $delete_docs_query = "SELECT file_path FROM supporting_documents WHERE dept_id = ? AND page_section = 'departmental_governance' AND status = 'active'";
    $stmt_docs = mysqli_prepare($conn, $delete_docs_query);
    mysqli_stmt_bind_param($stmt_docs, 'i', $dept_id);
    mysqli_stmt_execute($stmt_docs);
    $docs_result = mysqli_stmt_get_result($stmt_docs);
    
    $files_deleted = 0;
    if ($docs_result) {
        while ($doc = mysqli_fetch_assoc($docs_result)) {
            $file_path = $doc['file_path'];
            // Normalize path - handle both relative and absolute paths
            $physical_path = (strpos($file_path, '../') === 0) ? $file_path : (strpos($file_path, 'uploads/') === 0 ? '../' . $file_path : dirname(__DIR__) . '/' . str_replace('../', '', $file_path));
            if (file_exists($physical_path)) {
                if (@unlink($physical_path)) {
                    $files_deleted++;
                }
            }
        }
    }
    mysqli_stmt_close($stmt_docs);
    
    // Hard delete from unified supporting_documents table (completely remove records)
    $delete_docs_query = "DELETE FROM supporting_documents WHERE dept_id = ? AND page_section = 'departmental_governance'";
    $stmt_docs = mysqli_prepare($conn, $delete_docs_query);
    mysqli_stmt_bind_param($stmt_docs, 'i', $dept_id);
    $docs_deleted = mysqli_stmt_execute($stmt_docs);
    mysqli_stmt_close($stmt_docs);
    
    // Delete from main table
    $stmt = mysqli_prepare($conn, "DELETE FROM department_data WHERE DEPT_ID = ?");
    mysqli_stmt_bind_param($stmt, 'i', $dept_id);
    $main_deleted = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    if ($main_deleted) {
        $_SESSION['success'] = "Data cleared successfully! Deleted $files_deleted uploaded files.";
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: Departmental_Governance.php');
        }
        exit;
    } else {
        $_SESSION['error'] = "Error clearing data: " . mysqli_error($conn);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: Departmental_Governance.php');
        }
        exit;
    }
}

// ============================================================================
// HTML OUTPUT STARTS HERE
// ============================================================================
require('unified_header.php');

// For populating checkboxes, convert JSON string back to an array (with backward compatibility for old comma-separated format)
$inclusivePracticesSelected = [];
if (isset($existing_data['inclusive_practices']) && $existing_data['inclusive_practices'] !== '-' && $existing_data['inclusive_practices'] !== '') {
    $inclusive_str = $existing_data['inclusive_practices'];
    // Try JSON decode first (new format)
    $decoded = json_decode($inclusive_str, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $inclusivePracticesSelected = $decoded;
    } else {
        // Fallback to old comma-separated format (backward compatibility)
        $inclusivePracticesSelected = array_map('trim', explode(', ', $inclusive_str));
    }
}

$greenPracticesSelected = [];
if (isset($existing_data['green_practices']) && $existing_data['green_practices'] !== '-' && $existing_data['green_practices'] !== '') {
    $green_str = $existing_data['green_practices'];
    // Try JSON decode first (new format)
    $decoded = json_decode($green_str, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $greenPracticesSelected = $decoded;
    } else {
        // Fallback to old comma-separated format (backward compatibility)
        $greenPracticesSelected = array_map('trim', explode(', ', $green_str));
    }
}

?>

<div class="container-fluid">
    <div class="glass-card">
        <form class="modern-form" method="post" enctype="multipart/form-data">
    

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" id="successAlert">
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <script>
            // Auto-dismiss success message and lock form after 2 seconds
            setTimeout(function() {
                const successAlert = document.getElementById('successAlert');
                if (successAlert) {
                    successAlert.style.transition = 'opacity 0.5s';
                    successAlert.style.opacity = '0';
                    setTimeout(function() {
                        if (successAlert.parentNode) {
                            successAlert.remove();
                        }
                    }, 500);
                }
                // Lock the form after successful submission
                disableForm();
            }, 2000);
        </script>
                    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

    <?php if ($form_locked): ?>
        <div class="alert alert-info">
            <strong>Form Status:</strong> Data has been submitted. Click "Update" to modify existing data.
        </div>
                        <?php endif; ?>

    <div class="container-fluid">
        <div class="glass-card">
            <form class="modern-form" action="<?php echo basename($_SERVER['PHP_SELF']); ?>" method="POST" id="governanceForm" onsubmit="return validateForm()">
                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                <div class="page-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div>
                            <h1 class="page-title">
                                <i class="fas fa-balance-scale me-3"></i>Departmental Governance
                            </h1>
                        </div>
                        <a href="export_page_pdf.php?page=Departmental_Governance" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                            <i class="fas fa-file-pdf"></i> Download as PDF
                        </a>
                    </div>
                </div>
            <h6><strong>Instructions:</strong></h6>
            <ul class="mb-0">
                <li>Enter at least some data in the form</li>
                <li>If not applicable, enter <strong>0</strong> for numeric fields</li>
                <li>Leave text fields empty if not applicable</li>
            </ul>
        </div>
        
        <?php 
        // Display Skip Form Button
        require_once(__DIR__ . '/skip_form_component.php');
        $has_existing_data = ($existing_data !== null);
        displaySkipFormButton('departmental_governance', 'Departmental Governance', $A_YEAR, $has_existing_data);
        ?>
        
                        <!-- Fieldset 1: Inclusive Practices -->
        <fieldset class="mb-5">
            <legend class="h5">1. No of Inclusive Practices and Support Initiatives, as per UGC Norms,<br>for Socially, Physically, and Economically Disadvantaged Students and Employees:<br>(Max. 10 Marks)</legend>
            <div class="row">
                            <?php
                            $inclusive_options = [
                                'Support Mechanism for Socially Disadvantaged Students and Employees',
                                'Initiatives on the Safety of Female Students and Employees, Regular Working of WDC and ICC',
                                'Facilities for Physically Challenged Students and Employees (RAMP, Lift, Toilet, etc)',
                                'Support Mechanism for Transgender Students and Employee',
                                'Support Mechanism for newly inducted/ young teachers',
                                'Psychological Counselling for Well-being of Students',
                                'Career Counselling',
                                "Students' Grievance Redressal Cell",
                                'Department Academic Integrity Panel',
                            ];
                            $parentId = 'inclusive_parent_all';
                            // CRITICAL FIX: Properly calculate "Select All" state - exclude "Any other" from count
                            $allInclusiveChecked = (
                                count($inclusivePracticesSelected) === count($inclusive_options) && 
                                count($inclusive_options) > 0 &&
                                !in_array('Any other Inclusive Practice', $inclusivePracticesSelected)
                            );
                            $isParentChecked = $allInclusiveChecked;
                $readonly_attr = $form_locked ? 'readonly disabled' : '';
                            ?>
                            <div class="form-check d-inline-flex flex-nowrap align-items-center gap-2 mb-2 ps-0" style="white-space: nowrap;">
                    <input class="form-check-input m-0" type="checkbox" id="<?php echo $parentId; ?>" 
                           onclick="if(!this.disabled){document.querySelectorAll('.inclusive-child').forEach(cb=>{if(!cb.disabled)cb.checked=this.checked;});}" 
                           <?php echo $isParentChecked ? 'checked' : ''; ?> <?php echo $readonly_attr; ?>>
                    <label class="form-check-label mb-0" for="<?php echo $parentId; ?>">Select All</label>
                            </div>
                            <div id="inclusive_children">
                                <?php foreach ($inclusive_options as $opt):
                                    // CRITICAL FIX: Replace ALL non-alphanumeric characters, not just spaces and slashes
                                    $id = 'inclusive_' . preg_replace('/[^a-zA-Z0-9]/', '_', $opt);
                                    $isChecked = in_array($opt, $inclusivePracticesSelected);
                                ?>
                        <div class="col-md-6 mb-2">
                            <div class="form-check">
                                <input class="form-check-input inclusive-child" type="checkbox" name="inclusive_practices[]"
                                    value="<?php echo htmlspecialchars($opt); ?>" id="<?php echo $id; ?>" <?php echo $isChecked ? 'checked' : ''; ?> <?php echo $readonly_attr; ?>>
                                <label class="form-check-label" for="<?php echo $id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                <div class="col-12">
                            <div class="form-check d-flex align-items-center gap-2 mt-2 ps-0">
                        <input class="form-check-input m-0" type="checkbox" id="inclusive_other_toggle" <?php echo in_array('Any other Inclusive Practice', $inclusivePracticesSelected) ? 'checked' : ''; ?> <?php echo $readonly_attr; ?>>
                                <label class="form-check-label mb-0" for="inclusive_other_toggle">Any other Inclusive Practice (specify)</label>
                            </div>
                            <div class="mt-2" id="inclusive_other_text" style="display: <?php echo in_array('Any other Inclusive Practice', $inclusivePracticesSelected) ? 'block' : 'none'; ?>;">
                        <input type="text" class="form-control" name="inclusive_practices_details" placeholder="Specify other inclusive practice" value="<?php echo htmlspecialchars($existing_data['inclusive_practices_details'] ?? ''); ?>" <?php echo $readonly_attr; ?> />
                    </div>
                </div>
                            </div>
                            
            <!-- PDF Upload Section for Inclusive Practices -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="inclusive_practices_pdf"><b>Supporting Document for Inclusive Practices <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for inclusive practices (Max. 10 marks).
                </div>
                <div class="input-group">
                    <input type="file" id="inclusive_practices_pdf" name="inclusive_practices_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('inclusive_practices_pdf', 1)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="inclusive_practices_pdf_status" class="mt-2"></div>
            </div>
                        </fieldset>

                        <!-- Fieldset 2: Green Practices -->
        <fieldset class="mb-5">
            <legend class="h5">2. Green/Ecofriendly/ Sustainability Practices and Conducive Management steps implemented at the Department:</legend>
            <div class="row">
                            <?php
                            $green_options = [
                                'Solid waste management including facilities for Separation of Dry and Wet Waste',
                                'Liquid waste management',
                                'E-waste management',
                                'Paper waste management',
                                'Fire safety Management and Training',
                                'Rainwater harvesting structures and utilization at the department',
                                'Students and Staff using Bicycles',
                                'Solar or Renewable Energy Usage',
                                'Plastic free department',
                                'Paperless office',
                                'Green landscaping with trees and plants',
                                'Water and Energy Saving/ Conserving Practices',
                                'Heritage Conservation',
                                'Biodiversity Conservation',
                                'Energy Conservation'
                            ];
                            $greenParentId = 'green_parent_all';
                            // CRITICAL FIX: Properly calculate "Select All" state - exclude "Any other" from count
                            $allGreenChecked = (
                                count($greenPracticesSelected) === count($green_options) && 
                                count($green_options) > 0 &&
                                !in_array('Any other Green Initiative and Practice to reduce Carbon Footprint', $greenPracticesSelected)
                            );
                            $isGreenParentChecked = $allGreenChecked;
                            ?>
                            <div class="form-check d-inline-flex flex-nowrap align-items-center gap-2 mb-2 ps-0" style="white-space: nowrap;">
                    <input class="form-check-input m-0" type="checkbox" id="<?php echo $greenParentId; ?>" 
                           onclick="if(!this.disabled){document.querySelectorAll('.green-child').forEach(cb=>{if(!cb.disabled)cb.checked=this.checked;});}" 
                           <?php echo $isGreenParentChecked ? 'checked' : ''; ?> <?php echo $readonly_attr; ?>>
                    <label class="form-check-label mb-0" for="<?php echo $greenParentId; ?>">Select All</label>
                            </div>
                            <div id="green_children">
                                <?php foreach ($green_options as $opt):
                                    // CRITICAL FIX: Replace ALL non-alphanumeric characters, not just spaces and slashes
                                    $id = 'green_' . preg_replace('/[^a-zA-Z0-9]/', '_', $opt);
                                    $isChecked = in_array($opt, $greenPracticesSelected);
                                ?>
                        <div class="col-md-6 mb-2">
                            <div class="form-check">
                                <input class="form-check-input green-child" type="checkbox" name="green_practices[]"
                                    value="<?php echo htmlspecialchars($opt); ?>" id="<?php echo $id; ?>" <?php echo $isChecked ? 'checked' : ''; ?> <?php echo $readonly_attr; ?>>
                                <label class="form-check-label" for="<?php echo $id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                <div class="col-12">
                            <div class="form-check d-flex align-items-center gap-2 mt-2 ps-0">
                        <input class="form-check-input m-0" type="checkbox" id="green_other_toggle" <?php echo in_array('Any other Green Initiative and Practice to reduce Carbon Footprint', $greenPracticesSelected) ? 'checked' : ''; ?> <?php echo $readonly_attr; ?>>
                                <label class="form-check-label mb-0" for="green_other_toggle">Any other Green Initiative and Practice (specify)</label>
                            </div>
                            <div class="mt-2" id="green_other_text" style="display: <?php echo in_array('Any other Green Initiative and Practice to reduce Carbon Footprint', $greenPracticesSelected) ? 'block' : 'none'; ?>;">
                        <input type="text" class="form-control" name="green_practices_details" placeholder="Specify other green initiative" value="<?php echo htmlspecialchars($existing_data['green_practices_details'] ?? ''); ?>" <?php echo $readonly_attr; ?> />
                    </div>
                </div>
                            </div>
                            
            <!-- PDF Upload Section for Green Practices -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="green_practices_pdf"><b>Supporting Document for Green Practices <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for green practices.
                </div>
                <div class="input-group">
                    <input type="file" id="green_practices_pdf" name="green_practices_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('green_practices_pdf', 2)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="green_practices_pdf_status" class="mt-2"></div>
            </div>
                        </fieldset>

        <!-- Fieldset 3: Teachers in Admin -->
        <fieldset class="mb-5">
            <legend class="h5">3. Number of teachers involved in University and Government Administrative authorities/bodies:</legend>
                            <div class="mb-3">
                <input type="number" class="form-control" id="teachers_in_admin" name="teachers_in_admin" value="<?php echo htmlspecialchars($existing_data['teachers_in_admin'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for Teachers in Admin -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="teachers_admin_pdf"><b>Supporting Document for Teachers in Admin <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for teachers in administrative roles.
                </div>
                <div class="input-group">
                    <input type="file" id="teachers_admin_pdf" name="teachers_admin_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('teachers_admin_pdf', 3)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="teachers_admin_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 4: Awards and Recognition -->
        <fieldset class="mb-5">
            <legend class="h5">4. Number of awards and recognition received for extension activities from Government/recognized bodies during the last year:</legend>
                            <div class="mb-3">
                <input type="number" class="form-control" id="awards_extension" name="awards_extension" value="<?php echo htmlspecialchars($existing_data['awards_extension'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for Awards -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="awards_pdf"><b>Supporting Document for Awards <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for awards and recognitions.
                </div>
                <div class="input-group">
                    <input type="file" id="awards_pdf" name="awards_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('awards_pdf', 4)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="awards_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 5: Budgetary Allocation -->
        <fieldset class="mb-5">
            <legend class="h5">5. Budgetary Allocation of the Department and Expenditure:</legend>
                            <div class="mb-3">
                <textarea class="form-control" id="heads_expenditure" name="heads_expenditure" rows="3" <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['heads_expenditure'] ?? ''); ?></textarea>
                            </div>
            
            <!-- PDF Upload Section for Budgetary Allocation -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="budget_pdf"><b>Supporting Document for Budgetary Allocation <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for budgetary allocation and expenditure.
                </div>
                <div class="input-group">
                    <input type="file" id="budget_pdf" name="budget_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('budget_pdf', 5)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="budget_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 6: Alumni Contribution -->
        <fieldset class="mb-5">
            <legend class="h5">6. Alumni contribution/Funding Support during the previous year:</legend>
                            <div class="mb-3">
                <label for="alumni_contribution" class="form-label">Alumni Contribution Amount (in Lakhs):</label>
                <input type="number" step="0.01" class="form-control" id="alumni_contribution" name="alumni_contribution" value="<?php echo htmlspecialchars($existing_data['alumni_contribution'] ?? ''); ?>" placeholder="Enter amount in Lakhs (e.g., 5.50 for 5.5 Lakhs)" <?php echo $readonly_attr; ?>>
                <small class="form-text text-muted">Enter the amount in Lakhs. Example: For 5,50,000 INR, enter 5.50</small>
                            </div>
                            <div class="mb-3">
                <label for="alumni_details" class="form-label">Alumni Details and donation (names / notes):</label>
                <input type="text" class="form-control" id="alumni_details" name="alumni_details" value="<?php echo htmlspecialchars($existing_data['alumni_details'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for Alumni Contribution -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="alumni_pdf"><b>Supporting Document for Alumni Contribution <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for alumni contributions.
                </div>
                <div class="input-group">
                    <input type="file" id="alumni_pdf" name="alumni_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('alumni_pdf', 6)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="alumni_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 7: CSR and Philanthropic Funding -->
        <fieldset class="mb-5">
            <legend class="h5">7. CSR and Philanthropic Funding support to the Department during the previous year:</legend>
                            <div class="mb-3">
                <label for="csr_funding" class="form-label">CSR Funding Amount (in Lakhs):</label>
                <input type="number" step="0.01" class="form-control" id="csr_funding" name="csr_funding" value="<?php echo htmlspecialchars($existing_data['csr_funding'] ?? ''); ?>" placeholder="Enter amount in Lakhs (e.g., 1.00 for 1 Lakh)" <?php echo $readonly_attr; ?>>
                <small class="form-text text-muted">Enter the amount in Lakhs. Example: For 1,00,000 INR, enter 1.00</small>
                            </div>
                            <div class="mb-3">
                <label for="csr_details" class="form-label">CSR Details and donor information (names / notes):</label>
                <textarea class="form-control" id="csr_details" name="csr_details" rows="2" placeholder="Enter details about CSR funding sources and purposes" <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['csr_details'] ?? ''); ?></textarea>
                            </div>
            
            <!-- PDF Upload Section for CSR -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="csr_pdf"><b>Supporting Document for CSR and Philanthropic Funding <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for CSR and philanthropic funding.
                </div>
                <div class="input-group">
                    <input type="file" id="csr_pdf" name="csr_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('csr_pdf', 7)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="csr_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 8: Infrastructure -->
        <fieldset class="mb-5">
            <legend class="h5">8. Efforts taken for the Strengthening/Augmentation of Departmental Infrastructural, Computational/IT/Digital, Library, and Laboratory Facilities in the previous year in comparison to the last five years:</legend>
                            <div class="alert alert-info mb-3">
                                <strong>Instructions:</strong> Describe your efforts in each of the 4 areas below. Expert will evaluate and award max 2.5 marks each (Total: 10 marks)
                            </div>
                            
                            <!-- Infrastructural Facilities -->
                            <div class="mb-3">
                                <label for="infrastructure_infrastructural" class="form-label fw-bold">1. Departmental Infrastructural Facilities (Max 2.5 marks):</label>
                                <textarea class="form-control" id="infrastructure_infrastructural" name="infrastructure_infrastructural" rows="3" placeholder="Describe efforts for infrastructural facilities improvement..." <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['infrastructure_infrastructural'] ?? ''); ?></textarea>
                            </div>
                            
                            <!-- IT/Digital Facilities -->
                            <div class="mb-3">
                                <label for="infrastructure_it_digital" class="form-label fw-bold">2. Computational/IT/Digital Facilities (Max 2.5 marks):</label>
                                <textarea class="form-control" id="infrastructure_it_digital" name="infrastructure_it_digital" rows="3" placeholder="Describe efforts for IT/Digital facilities improvement..." <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['infrastructure_it_digital'] ?? ''); ?></textarea>
                            </div>
                            
                            <!-- Library Facilities -->
                            <div class="mb-3">
                                <label for="infrastructure_library" class="form-label fw-bold">3. Library Facilities (Max 2.5 marks):</label>
                                <textarea class="form-control" id="infrastructure_library" name="infrastructure_library" rows="3" placeholder="Describe efforts for library facilities improvement..." <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['infrastructure_library'] ?? ''); ?></textarea>
                            </div>
                            
                            <!-- Laboratory Facilities -->
                            <div class="mb-3">
                                <label for="infrastructure_laboratory" class="form-label fw-bold">4. Laboratory Facilities (Max 2.5 marks):</label>
                                <textarea class="form-control" id="infrastructure_laboratory" name="infrastructure_laboratory" rows="3" placeholder="Describe efforts for laboratory facilities improvement..." <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['infrastructure_laboratory'] ?? ''); ?></textarea>
                            </div>
            
            <!-- PDF Upload Section for Infrastructure -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="infrastructure_pdf"><b>Supporting Document for Infrastructure <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for infrastructure development.
                </div>
                <div class="input-group">
                    <input type="file" id="infrastructure_pdf" name="infrastructure_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('infrastructure_pdf', 8)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="infrastructure_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 9: Peer Perception -->
        <fieldset class="mb-5">
            <legend class="h5">9. Perception from Industry/Employers and Academia (PEER) during the last year:</legend>
                            <div class="mb-3">
                <input type="text" class="form-control" id="peer_perception_rate" name="peer_perception_rate" value="<?php echo htmlspecialchars($existing_data['peer_perception_rate'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
                            <div class="mb-3">
                <label for="peer_perception_notes" class="form-label">Notes on Employer / Academic peer perception:</label>
                <textarea class="form-control" id="peer_perception_notes" name="peer_perception_notes" rows="2" <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['peer_perception_notes'] ?? ''); ?></textarea>
                            </div>
            
            <!-- PDF Upload Section for Peer Perception -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="peer_perception_pdf"><b>Supporting Document for Peer Perception <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for peer perception.
                </div>
                <div class="input-group">
                    <input type="file" id="peer_perception_pdf" name="peer_perception_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('peer_perception_pdf', 9)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="peer_perception_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 10: Student Feedback -->
        <fieldset class="mb-5">
            <legend class="h5">10. Students' Feedback about Teachers and Department:</legend>
                            <div class="mb-3">
                <input type="text" class="form-control" id="student_feedback_rate" name="student_feedback_rate" value="<?php echo htmlspecialchars($existing_data['student_feedback_rate'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
                            <div class="mb-3">
                <label for="student_feedback_notes" class="form-label">Notes on student feedback:</label>
                <textarea class="form-control" id="student_feedback_notes" name="student_feedback_notes" rows="2" <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['student_feedback_notes'] ?? ''); ?></textarea>
            </div>
            
            <!-- PDF Upload Section for Student Feedback -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="student_feedback_pdf"><b>Supporting Document for Student Feedback <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for student feedback.
                </div>
                <div class="input-group">
                    <input type="file" id="student_feedback_pdf" name="student_feedback_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('student_feedback_pdf', 10)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="student_feedback_pdf_status" class="mt-2"></div>
                            </div>
                        </fieldset>

        <!-- Fieldset 11: Best Practice -->
        <fieldset class="mb-5">
            <legend class="h5">11. Best Practice/Unique Activity of the Department:</legend>
                            <div class="mb-3">
                <textarea class="form-control" id="best_practice" name="best_practice" rows="4" maxlength="700" <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['best_practice'] ?? ''); ?></textarea>
                            </div>
            
            <!-- PDF Upload Section for Best Practice -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="best_practice_pdf"><b>Supporting Document for Best Practice <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for best practices.
                </div>
                <div class="input-group">
                    <input type="file" id="best_practice_pdf" name="best_practice_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('best_practice_pdf', 11)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="best_practice_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 12: Leadership and Teamwork -->
        <fieldset class="mb-5">
            <legend class="h5">12. Details of various initiatives taken at the department level to ensure<br>synchronization at the Department through cohesive leadership, a conducive<br>environment, and strong teamwork aligned with the University's reputation and legacy:</legend>
                            <div class="mb-3">
                <textarea class="form-control" id="leadership_sync" name="leadership_sync" rows="4" maxlength="700" <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['leadership_sync'] ?? ''); ?></textarea>
            </div>
            
            <!-- PDF Upload Section for Leadership -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="leadership_pdf"><b>Supporting Document for Leadership Initiatives <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for leadership initiatives.
                </div>
                <div class="input-group">
                    <input type="file" id="leadership_pdf" name="leadership_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('leadership_pdf', 12)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="leadership_pdf_status" class="mt-2"></div>
                            </div>
                        </fieldset>

        <!-- Fieldset 13: ISR Initiatives -->
        <fieldset class="mb-5">
            <legend class="h5">13. Total number of ISR initiatives the institution has participated: (1 mark)</legend>
                            <div class="mb-3">
                <input type="number" class="form-control" id="isr_total" name="isr_total" value="<?php echo htmlspecialchars($existing_data['isr_total'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for ISR Initiatives -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="isr_initiatives_pdf"><b>Supporting Document for ISR Initiatives <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for ISR initiatives.
                </div>
                <div class="input-group">
                    <input type="file" id="isr_initiatives_pdf" name="isr_initiatives_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('isr_initiatives_pdf', 13)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="isr_initiatives_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 14: ISR Budget Percentage -->
        <fieldset class="mb-5">
            <legend class="h5">14. Percentage of the budget allocated for ISR initiatives out of the total annual budget:</legend>
                            <div class="mb-3">
                <input type="number" step="0.01" class="form-control" id="isr_budget_percent" name="isr_budget_percent" value="<?php echo htmlspecialchars($existing_data['isr_budget_percent'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for ISR Budget -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="isr_budget_pdf"><b>Supporting Document for ISR Budget <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for ISR budget allocation.
                </div>
                <div class="input-group">
                    <input type="file" id="isr_budget_pdf" name="isr_budget_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('isr_budget_pdf', 14)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="isr_budget_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 15: ISR Students Percentage -->
        <fieldset class="mb-5">
            <legend class="h5">15. Percentage of students participating in ISR initiatives out of the total number of students in the institution: (1 mark)</legend>
                            <div class="mb-3">
                <input type="number" step="0.01" class="form-control" id="isr_students_percent" name="isr_students_percent" value="<?php echo htmlspecialchars($existing_data['isr_students_percent'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for ISR Students -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="isr_students_pdf"><b>Supporting Document for ISR Student Participation <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for student participation in ISR.
                </div>
                <div class="input-group">
                    <input type="file" id="isr_students_pdf" name="isr_students_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('isr_students_pdf', 15)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="isr_students_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 16: ISR Faculty Percentage -->
        <fieldset class="mb-5">
            <legend class="h5">16. Percentage of faculty participating in ISR initiatives out of the total number of full-time faculty in the institution: (1 mark)</legend>
                            <div class="mb-3">
                <input type="number" step="0.01" class="form-control" id="isr_faculty_percent" name="isr_faculty_percent" value="<?php echo htmlspecialchars($existing_data['isr_faculty_percent'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for ISR Faculty -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="isr_faculty_pdf"><b>Supporting Document for ISR Faculty Participation <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for faculty participation in ISR.
                </div>
                <div class="input-group">
                    <input type="file" id="isr_faculty_pdf" name="isr_faculty_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('isr_faculty_pdf', 16)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="isr_faculty_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 17: Sponsors Total -->
        <fieldset class="mb-5">
            <legend class="h5">17. Total number of sponsors received: (1 mark)</legend>
                            <div class="mb-3">
                <input type="number" class="form-control" id="sponsors_total" name="sponsors_total" value="<?php echo htmlspecialchars($existing_data['sponsors_total'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for Sponsors Total -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="sponsors_total_pdf"><b>Supporting Document for Sponsors <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for sponsors.
                </div>
                <div class="input-group">
                    <input type="file" id="sponsors_total_pdf" name="sponsors_total_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('sponsors_total_pdf', 17)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="sponsors_total_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 18: Sponsors Amount -->
        <fieldset class="mb-5">
            <legend class="h5">18. Total sponsor amount (in INR) if applicable or kind sponsorships: (1 mark)</legend>
                            <div class="mb-3">
                <input type="number" step="0.01" class="form-control" id="sponsors_amount" name="sponsors_amount" value="<?php echo htmlspecialchars($existing_data['sponsors_amount'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for Sponsors Amount -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="sponsors_amount_pdf"><b>Supporting Document for Sponsor Amount <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for sponsor amounts.
                </div>
                <div class="input-group">
                    <input type="file" id="sponsors_amount_pdf" name="sponsors_amount_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('sponsors_amount_pdf', 18)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="sponsors_amount_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 19: Volunteer Hours -->
        <fieldset class="mb-5">
            <legend class="h5">19. Estimated total volunteer hours contributed by students and faculty toward ISR initiative(s): (1 mark)</legend>
                            <div class="mb-3">
                <input type="number" class="form-control" id="isr_volunteer_hours" name="isr_volunteer_hours" value="<?php echo htmlspecialchars($existing_data['isr_volunteer_hours'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for Volunteer Hours -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="volunteer_hours_pdf"><b>Supporting Document for Volunteer Hours <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for volunteer hours.
                </div>
                <div class="input-group">
                    <input type="file" id="volunteer_hours_pdf" name="volunteer_hours_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('volunteer_hours_pdf', 19)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="volunteer_hours_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 20: Active Partnerships -->
        <fieldset class="mb-5">
            <legend class="h5">20. Number of active industry or academic partnerships contributing to ISR initiatives: (1 mark)</legend>
                            <div class="mb-3">
                <input type="number" class="form-control" id="isr_active_partnerships" name="isr_active_partnerships" value="<?php echo htmlspecialchars($existing_data['isr_active_partnerships'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
                            </div>
            
            <!-- PDF Upload Section for Active Partnerships -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="partnerships_pdf"><b>Supporting Document for Active Partnerships <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for active partnerships.
                </div>
                <div class="input-group">
                    <input type="file" id="partnerships_pdf" name="partnerships_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('partnerships_pdf', 20)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="partnerships_pdf_status" class="mt-2"></div>
            </div>
        </fieldset>

        <!-- Fieldset 21: Key Partners -->
        <fieldset class="mb-5">
            <legend class="h5">21. Briefly name key partners or describe the nature of collaborations: (2 marks)</legend>
                            <div class="mb-3">
                <input type="text" class="form-control" id="isr_partners_notes" name="isr_partners_notes" value="<?php echo htmlspecialchars($existing_data['isr_partners_notes'] ?? ''); ?>" <?php echo $readonly_attr; ?>>
            </div>
            
            <!-- PDF Upload Section for Key Partners -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="key_partners_pdf"><b>Supporting Document for Key Partners <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for key partners and collaborations.
                </div>
                <div class="input-group">
                    <input type="file" id="key_partners_pdf" name="key_partners_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('key_partners_pdf', 21)" <?php echo $readonly_attr; ?>>Upload</button>
                </div>
                <div id="key_partners_pdf_status" class="mt-2"></div>
                            </div>
                        </fieldset>

        <!-- Fieldset 22: Department Plan -->
        <fieldset class="mb-5">
            <legend class="h5">22. Describe your department's plan to sustain and scale the initiative over the next two years. Highlight current progress, future goals, and the strategies—such as departmental support, funding, partnerships, or policy integration—that will ensure its long-term success and broader impact: (10 marks)</legend>
            <div class="mb-3">
                <textarea class="form-control" id="department_plan" name="department_plan" rows="6" <?php echo $readonly_attr; ?>><?php echo htmlspecialchars($existing_data['department_plan'] ?? ''); ?></textarea>
            </div>
            
            <!-- PDF Upload Section for Department Plan -->
            <div class="mt-4 p-3 border rounded bg-light">
                <label class="form-label fw-bold mb-3" for="department_plan_pdf"><b>Supporting Document for Department Plan <span class="text-danger">*</span></b></label>
                <div class="alert alert-info" role="alert">
                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for department's sustainability plan.
                </div>
                <div class="input-group">
                    <input type="file" id="department_plan_pdf" name="department_plan_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('department_plan_pdf', 22)" <?php echo $readonly_attr; ?>>Upload</button>
            </div>
                <div id="department_plan_pdf_status" class="mt-2"></div>
        </div>
        </fieldset>

        <div class="text-center">
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <?php if ($form_locked): ?>
                    <button type="button" class="btn btn-warning btn-lg" onclick="enableUpdate()">
                        <i class="fas fa-edit me-2"></i>Update
                    </button>
                    <button type="submit" name="update_governance" class="btn btn-success btn-lg" id="updateBtn" style="display:none;">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                    <a href="?action=clear_data" class="btn btn-danger btn-lg" 
                       onclick="return confirmClearData()">
                        <i class="fas fa-trash me-2"></i>Clear Data
                    </a>
                <?php else: ?>
                    <button type="submit" name="save_governance" class="btn btn-primary btn-lg">
                        <i class="fas fa-paper-plane me-2"></i>Submit Details
                    </button>
                <?php endif; ?>
    </div>
        </div>
            </form>
        </div>
    </div>

<style>
    body {
        background: linear-gradient(135deg, #f6f8fa 0%, #e9ecef 100%);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .container {
        background: #fff;
        border-radius: 1.5rem;
        box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.1);
        padding: 3rem 2.5rem;
        margin-bottom: 2rem;
        border: 1px solid #e3e6ea;
        max-width: 1400px;
        width: 95%;
    }

    fieldset {
        border: 2px solid #e3e6ea !important;
        border-radius: 1rem;
        padding: 2.5rem 2rem 2rem 2rem !important;
        margin-bottom: 3rem !important;
        background: linear-gradient(135deg, #fafdff 0%, #f8f9fa 100%);
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05);
        position: relative;
        transition: all 0.3s ease;
        width: 100%;
        min-width: 100%;
    }
    
    fieldset:hover {
        box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, 0.08);
        border-color: #d1d5db;
    }
    
    /* Checkbox styling */
    .form-check-input {
        width: 1.2em;
        height: 1.2em;
        margin-top: 0.1em;
        vertical-align: top;
        background-color: #fff;
        background-repeat: no-repeat;
        background-position: center;
        background-size: contain;
        border: 1px solid #ced4da;
        appearance: none;
        color-adjust: exact;
        cursor: pointer;
    }
    
    .form-check-input:checked {
        background-color: #0d6efd !important;
        border-color: #0d6efd !important;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
    }
    
    .form-check-input:checked[type="checkbox"] {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='m6 10 3 3 6-6'/%3e%3c/svg%3e") !important;
        background-size: 100% 100% !important;
        background-position: center !important;
        background-repeat: no-repeat !important;
    }
    
    .form-check-input:focus {
        border-color: #86b7fe !important;
        outline: 0 !important;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
    }
    
    .form-check-input:hover {
        border-color: #0d6efd !important;
        cursor: pointer !important;
    }
    
    .form-check-label {
        cursor: pointer !important;
        margin-left: 0.5em !important;
        line-height: 1.2 !important;
        color: #212529 !important;
        font-weight: 500 !important;
        user-select: none !important;
    }
    
    .form-check {
        display: flex !important;
        align-items: flex-start !important;
        margin-bottom: 0.75rem !important;
        padding: 0.5rem 0.75rem !important;
        border-radius: 0.5rem;
        transition: all 0.2s ease;
        background: rgba(255, 255, 255, 0.5);
    }
    
    .form-check:hover {
        background: rgba(102, 126, 234, 0.05);
        transform: translateX(2px);
    }
    
    .form-check-input {
        width: 1.25rem !important;
        height: 1.25rem !important;
        margin-top: 0.125rem !important;
        margin-right: 0.5rem !important;
        cursor: pointer !important;
        flex-shrink: 0 !important;
    }

    legend {
        font-size: 1.4rem;
        font-weight: 700;
        color: #1a365d;
        width: auto;
        padding: 0.75rem 1.5rem;
        border-bottom: none;
        white-space: normal;
        overflow: visible;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 0.75rem;
        box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
        margin-bottom: 1.5rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        line-height: 1.4;
        max-width: 100%;
        word-wrap: break-word;
        word-break: normal;
        hyphens: auto;
        min-width: 100%;
    }
    
    .form-check-label {
        cursor: pointer !important;
        margin-left: 0.5em !important;
        line-height: 1.3 !important;
        color: #2d3748 !important;
        font-weight: 500 !important;
        user-select: none !important;
        white-space: normal;
        word-wrap: break-word;
        hyphens: auto;
        font-size: 0.95rem;
    }

    label.form-label {
        font-weight: 500;
        color: #2b3a55;
    }

    input[type="number"],
    input[type="text"],
    input[type="date"],
    input[type="month"],
    input[type="url"],
    textarea,
    select {
        border-radius: 0.75rem !important;
        border: 2px solid #e2e8f0 !important;
        background: #fff !important;
        font-size: 1rem;
        padding: 0.75rem 1rem !important;
        transition: all 0.3s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        width: 100%;
        max-width: 100%;
    }

    input:focus,
    textarea:focus,
    select:focus {
        border-color: #667eea !important;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25) !important;
        background: #fff !important;
        transform: translateY(-1px);
    }
    
    input:hover,
    textarea:hover,
    select:hover {
        border-color: #cbd5e0 !important;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    input[readonly],
    textarea[readonly],
    select[readonly] {
        background-color: #f8f9fa !important;
        color: #6c757d !important;
        cursor: not-allowed !important;
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    .btn-lg {
        padding: 14px 28px;
        font-size: 16px;
        font-weight: 600;
        border-radius: 12px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        border: none;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .btn-lg:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 15px rgba(0,0,0,0.2);
    }
    
    .btn-lg:active {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .btn-primary {
        background: linear-gradient(135deg, #007bff, #0056b3);
        border: none;
    }
    
    .btn-success {
        background: linear-gradient(135deg, #28a745, #1e7e34);
        border: none;
    }
    
    .btn-warning {
        background: linear-gradient(135deg, #ffc107, #e0a800);
        border: none;
        color: #212529;
    }
    
    .btn-info {
        background: linear-gradient(135deg, #17a2b8, #138496);
        border: none;
    }
    
    .btn-danger {
        background: linear-gradient(135deg, #dc3545, #c82333);
        border: none;
    }

    .btn {
        min-width: 160px;
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        border-radius: 8px;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn i {
        font-size: 1.1em;
    }

    .gap-3 {
        gap: 1rem !important;
    }

    .form-group {
        margin-bottom: 1.25rem;
    }

    @media (max-width: 1200px) {
        .container {
            max-width: 1200px;
            width: 98%;
        }
    }
    
    @media (max-width: 992px) {
        .container {
            max-width: 1000px;
            width: 98%;
        }
    }
    
    @media (max-width: 767px) {
        .container {
            padding: 1rem 0.5rem;
            width: 100%;
            max-width: 100%;
        }

        fieldset {
            padding: 1rem 0.5rem 0.5rem 0.5rem !important;
        }
        
        legend {
            font-size: 1.2rem;
            padding: 0.5rem 1rem;
            line-height: 1.3;
        }
    }
    
    @media (max-width: 480px) {
        legend {
            font-size: 1.1rem;
            padding: 0.4rem 0.8rem;
            line-height: 1.2;
        }
    }

    legend {
        font-size: 1.4rem;
        font-weight: 700;
        color: #1a365d;
        width: auto;
        padding: 0.75rem 1.5rem;
        border-bottom: none;
        white-space: normal;
        overflow: visible;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white !important;
        border-radius: 0.75rem;
        box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
        margin-bottom: 1.5rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        line-height: 1.4;
        max-width: 100%;
        word-wrap: break-word;
        word-break: normal;
        hyphens: auto;
        min-width: 100%;
    }

    /* Ensure all h5 elements in legends have proper styling */
    legend.h5 {
        color: white !important;
        font-weight: 700;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    /* CRITICAL FIX: Ensure all checkboxes are visible and interactive */
    .form-check-input[type="checkbox"] {
        appearance: checkbox !important;
        -webkit-appearance: checkbox !important;
        -moz-appearance: checkbox !important;
        opacity: 1 !important;
        pointer-events: auto !important;
        margin: 0 !important;
        cursor: pointer !important;
    }

    .form-check-input[type="checkbox"]:disabled {
        opacity: 0.5 !important;
        pointer-events: none !important;
        cursor: not-allowed !important;
    }

    /* Fix potential flexbox issues */
    #inclusive_children .col-md-6,
    #green_children .col-md-6 {
        display: block !important;
    }

    /* Ensure checkboxes are not hidden by any parent styles */
    .inclusive-child, .green-child {
        display: inline-block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }

    /* Debug highlight for disabled checkboxes */
    input[type="checkbox"]:disabled {
        outline: 3px solid red !important;
        opacity: 0.5 !important;
    }
    
    /* Debug highlight (uncomment to see which boxes are targeted) */
    /*
    .inclusive-child, .green-child {
        outline: 2px solid red !important;
        background: rgba(255, 0, 0, 0.1) !important;
    }
    */
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // CRITICAL SECURITY: Global request limiter to prevent max connection errors
    let globalActiveRequests = 0;
    const MAX_CONCURRENT_REQUESTS = 3;
    const requestQueue = [];
    let queueProcessing = false;

    function processQueuedRequests() {
        if (queueProcessing) {
            return;
        }
        
        if (requestQueue.length === 0 || globalActiveRequests >= MAX_CONCURRENT_REQUESTS) {
            queueProcessing = false;
            return;
        }
        
        queueProcessing = true;
        const nextRequest = requestQueue.shift();
        globalActiveRequests++;
        
        nextRequest.fn()
            .then(nextRequest.resolve)
            .catch(nextRequest.reject)
            .finally(() => {
                globalActiveRequests--;
                queueProcessing = false;
                if (requestQueue.length > 0 && globalActiveRequests < MAX_CONCURRENT_REQUESTS) {
                    setTimeout(processQueuedRequests, 100);
                }
            });
    }

    function executeWithRateLimit(requestFn) {
        if (globalActiveRequests >= MAX_CONCURRENT_REQUESTS) {
            return new Promise((resolve, reject) => {
                requestQueue.push({
                    fn: requestFn,
                    resolve: resolve,
                    reject: reject
                });
                if (!queueProcessing) {
                    setTimeout(processQueuedRequests, 100);
                }
            });
        }
        
        globalActiveRequests++;
        return requestFn().finally(() => {
            globalActiveRequests--;
            if (requestQueue.length > 0 && globalActiveRequests < MAX_CONCURRENT_REQUESTS) {
                setTimeout(processQueuedRequests, 100);
                }
            });
        }

    // CRITICAL SECURITY: Add request timeout to prevent hanging requests
    function fetchWithTimeout(url, options = {}) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
        
        return fetch(url, { ...options, signal: controller.signal })
            .then(response => {
                clearTimeout(timeoutId);
                return response;
            })
            .catch(error => {
                clearTimeout(timeoutId);
                if (error.name === 'AbortError') {
                    throw new Error('Request timed out. Please refresh the page.');
                }
                throw error;
            });
    }

    // ============================================================================
    // BULLETPROOF SELECT ALL FUNCTIONALITY - Minimal, Direct Approach
    // ============================================================================
    
    function syncInclusiveSelectAll() {
        const parent = document.getElementById('inclusive_parent_all');
        const children = document.querySelectorAll('.inclusive-child');
        
        // Sync parent checkbox (when children are manually clicked)
        let allChecked = true;
        children.forEach(cb => {
            if (!cb.disabled && !cb.checked) allChecked = false;
        });
        
        if (parent && !parent.disabled) {
            parent.checked = allChecked;
        }
    }
    
    function syncGreenSelectAll() {
        const parent = document.getElementById('green_parent_all');
        const children = document.querySelectorAll('.green-child');
        
        let allChecked = true;
        children.forEach(cb => {
            if (!cb.disabled && !cb.checked) allChecked = false;
        });
        
        if (parent && !parent.disabled) {
            parent.checked = allChecked;
        }
    }
    
    // Direct onclick handlers (bypass event delegation issues)
    document.addEventListener('DOMContentLoaded', function() {
        const inclusiveParent = document.getElementById('inclusive_parent_all');
        const greenParent = document.getElementById('green_parent_all');
        const inclusiveOtherToggle = document.getElementById('inclusive_other_toggle');
        const inclusiveText = document.getElementById('inclusive_other_text');
        const greenOtherToggle = document.getElementById('green_other_toggle');
        const greenText = document.getElementById('green_other_text');
        
        if (inclusiveParent) {
            inclusiveParent.onclick = function() {
                if (this.disabled) return false;
                const children = document.querySelectorAll('.inclusive-child');
                children.forEach(cb => {
                    if (!cb.disabled) cb.checked = this.checked;
                });
                return true;
            };
        }
        
        if (greenParent) {
            greenParent.onclick = function() {
                if (this.disabled) return false;
                const children = document.querySelectorAll('.green-child');
                children.forEach(cb => {
                    if (!cb.disabled) cb.checked = this.checked;
                });
                return true;
            };
        }
        
        // Sync parent when children change (event delegation)
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('inclusive-child')) {
                syncInclusiveSelectAll();
            } else if (e.target.classList.contains('green-child')) {
                syncGreenSelectAll();
            }
        });
        
        // Handle "Any other" toggles
        if (inclusiveOtherToggle) {
            inclusiveOtherToggle.addEventListener('change', function() {
                if (inclusiveText) {
                    inclusiveText.style.display = this.checked ? 'block' : 'none';
                    if (!this.checked) {
                        const input = inclusiveText.querySelector('input');
                    if (input) input.value = '';
                    }
                }
                if (this.checked && inclusiveParent && !inclusiveParent.disabled) {
                    inclusiveParent.checked = false;
                }
            });
        }
        
        if (greenOtherToggle) {
            greenOtherToggle.addEventListener('change', function() {
                if (greenText) {
                    greenText.style.display = this.checked ? 'block' : 'none';
                    if (!this.checked) {
                        const input = greenText.querySelector('input');
                        if (input) input.value = '';
                    }
                }
                if (this.checked && greenParent && !greenParent.disabled) {
                    greenParent.checked = false;
                }
            });
        }
    });
    

// Form validation function
function validateForm() {
    try {
        // Check if at least some data is provided
        const teachersInAdmin = parseInt(document.getElementById('teachers_in_admin').value) || 0;
        const awardsExtension = parseInt(document.getElementById('awards_extension').value) || 0;
        const alumniContribution = parseFloat(document.getElementById('alumni_contribution').value) || 0;
        const isrTotal = parseInt(document.getElementById('isr_total').value) || 0;
        const sponsorsTotal = parseInt(document.getElementById('sponsors_total').value) || 0;
    
        // Check if any checkboxes are selected
        const inclusiveCheckboxes = document.querySelectorAll('input[name="inclusive_practices[]"]:checked');
        const greenCheckboxes = document.querySelectorAll('input[name="green_practices[]"]:checked');
        
        // If no data is provided, show alert
        if (inclusiveCheckboxes.length === 0 && greenCheckboxes.length === 0 && 
                teachersInAdmin === 0 && awardsExtension === 0 && alumniContribution === 0 && 
                isrTotal === 0 && sponsorsTotal === 0) {
            alert('Please enter at least some data in the form before submitting.');
            return false;
        }
        
        // Validate numeric fields
        const numericFields = document.querySelectorAll('input[type="number"]');
        for (let field of numericFields) {
            if (field.value && parseFloat(field.value) < 0) {
                alert('Please enter valid positive numbers only.');
                field.focus();
                return false;
            }
        }
        
        return true;
    } catch (error) {
        alert('Please check your form data and try again.');
        return false;
    }
}

// PDF validation function
function validatePDF(input) {
    const file = input.files[0];
    if (file) {
        const fileSize = file.size / 1024 / 1024; // Convert to MB
        const fileType = file.type;
        
        if (fileType !== 'application/pdf') {
            alert('Please select a valid PDF file.');
            input.value = '';
            return false;
        }
        
        if (fileSize > 10) {
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
    
    if (!fileInput.files || !fileInput.files[0]) {
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
    
    // CRITICAL SECURITY: Use rate limiting and timeout for upload
    executeWithRateLimit(() => {
        return fetchWithTimeout(window.location.href, {
        method: 'POST',
        body: formData
        });
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
        
        if (data && data.success) {
            // Check form lock state dynamically (not from PHP variable at page load)
            var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
            var isFormLocked = updateButton && updateButton.style.display !== 'none';
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            // Ensure file path is web-accessible (same robust logic as StudentSupport.php)
            let viewPath = data.file_path || '';
            
            // Convert absolute paths to relative web paths (same as StudentSupport.php)
            if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
                // Extract relative path from absolute path (case-insensitive match for directory names)
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
            // Always show view button when document exists, even when form is locked
            const viewButton = escapedViewPath ? `<a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
                        <i class="fas fa-eye"></i> View
                    </a>` : '';
            
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center flex-wrap">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success me-2">Document uploaded successfully</span>
                    ${deleteButton}
                    ${viewButton}
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
            
            // Clear the file input
            fileInput.value = '';
        } else {
            // Show error message only if explicitly failed
            const errorMsg = (data && data.message) ? data.message : 'Upload failed. Please try again.';
            statusDiv.innerHTML = `<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-circle me-2"></i>${errorMsg}</div>`;
            statusDiv.className = 'mt-2';
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
    // Check form lock state dynamically
    var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
    var isFormLocked = updateButton && updateButton.style.display !== 'none';
    
    if (isFormLocked) {
        alert('Cannot delete document. Form is locked after submission.\n\nTo delete documents, please use the Update button to unlock the form first.');
        return;
    }
    
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }
    
    // Get CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    
    const statusDiv = document.getElementById(fileId + '_status');
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
    statusDiv.className = 'mt-2 text-info';
    
    const formData = new FormData();
    formData.append('delete_document', '1');
    formData.append('file_id', fileId);
    formData.append('srno', srno);
    if (csrfToken) {
        formData.append('csrf_token', csrfToken);
    }
    
    // CRITICAL SECURITY: Use rate limiting and timeout for delete
    executeWithRateLimit(() => {
        return fetchWithTimeout('', {
        method: 'POST',
        body: formData
        });
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

// Check document status function (kept for backward compatibility, but loadExistingDocuments uses consolidated endpoint)
function checkDocumentStatus(fileId, srno) {
    const statusDiv = document.getElementById(fileId + '_status');
    
    // CRITICAL SECURITY: Use rate limiting and timeout for document check
    executeWithRateLimit(() => {
        return fetchWithTimeout(`?check_doc=1&srno=${srno}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        },
        cache: 'no-cache'
        });
    })
    .catch(error => {
        // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
        console.error('[Departmental_Governance] Fetch error for check_doc:', error);
        return { 
            ok: false, 
            text: () => Promise.resolve(JSON.stringify({ 
                success: false, 
                message: 'Network error: ' + (error.message || 'Failed to connect to server') 
            })) 
        };
    })
    .then(response => {
        // CRITICAL: Ensure response is valid before processing
        if (!response || typeof response.text !== 'function') {
            console.error('[Departmental_Governance] Invalid response object:', response);
            return { success: false, message: 'Check failed: Invalid server response' };
        }
        
        // CRITICAL #3: Handle empty responses in JS - return object, don't throw
        return response.text().then(text => {
            const trimmed = text.trim();
            
            // If empty response, return error object instead of throwing
            if (!trimmed || trimmed === '') {
                return { success: false, message: 'No document found' };
            }
            
            // Try to parse as JSON
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                // CRITICAL #5: Return error response instead of throwing
                return { success: false, message: 'Invalid server response' };
            }
        }).catch(textError => {
            // CRITICAL: Handle text() parsing errors
            console.error('[Departmental_Governance] Error reading response text:', textError);
            return { success: false, message: 'Check failed: Error reading server response' };
        });
    })
    .then(data => {
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            return;
        }
        
        if (data.success && data.file_path) {
            // Check form lock state dynamically (not from PHP variable at page load)
            var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
            var isFormLocked = updateButton && updateButton.style.display !== 'none';
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            // Ensure file path is web-accessible (same robust logic as StudentSupport.php)
            let viewPath = data.file_path || '';
            
            // Convert absolute paths to relative web paths (same as StudentSupport.php)
            if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
                // Extract relative path from absolute path (case-insensitive match for directory names)
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
            // Always show view button when document exists, even when form is locked
            const viewButton = escapedViewPath ? `<a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
                        <i class="fas fa-eye"></i> View
                    </a>` : '';
            
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center flex-wrap">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success me-2">Document uploaded</span>
                    ${deleteButton}
                    ${viewButton}
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
        } else {
                statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                statusDiv.className = 'mt-2 text-muted';
        }
    })
    .catch(error => {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
    });
}

// CRITICAL PERFORMANCE FIX: Load ALL documents in ONE batch request instead of 22 individual requests
// This reduces database load by 95% (22 queries → 1 query)
function loadExistingDocuments() {
    // CRITICAL: Guard against duplicate calls - prevent multiple simultaneous loads
    if (documentsLoading || documentsLoadAttempted) {
        return; // Already loading or already attempted - DO NOT RETRY
    }
    documentsLoading = true;
    documentsLoadAttempted = true; // NEVER reset this flag (only reset in refreshAllDocumentStatuses)
    
    // Document mapping for batch response processing
    const documentMap = {
        'inclusive_practices_pdf': 1,
        'green_practices_pdf': 2,
        'teachers_admin_pdf': 3,
        'awards_pdf': 4,
        'budget_pdf': 5,
        'alumni_pdf': 6,
        'csr_pdf': 7,
        'infrastructure_pdf': 8,
        'peer_perception_pdf': 9,
        'student_feedback_pdf': 10,
        'best_practice_pdf': 11,
        'leadership_pdf': 12,
        'isr_initiatives_pdf': 13,
        'isr_budget_pdf': 14,
        'isr_students_pdf': 15,
        'isr_faculty_pdf': 16,
        'sponsors_total_pdf': 17,
        'sponsors_amount_pdf': 18,
        'volunteer_hours_pdf': 19,
        'partnerships_pdf': 20,
        'key_partners_pdf': 21,
        'department_plan_pdf': 22
    };
    
    // CRITICAL: Show loading state for ALL status divs BEFORE making request (like FacultyOutput.php)
    Object.keys(documentMap).forEach(fileId => {
        const statusDiv = document.getElementById(fileId + '_status');
        if (statusDiv) {
            statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Checking...';
            statusDiv.className = 'mt-2 text-info';
        }
    });
    
    // CRITICAL: Use batch endpoint with rate limiting - ONE request instead of 22
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
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
                if (!trimmed || trimmed === '') {
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
        documentsLoading = false; // Reset loading flag, but keep attempted flag
        
        if (data.success && data.documents) {
            // Process batch response - update ALL documents at once (like FacultyOutput.php)
            Object.keys(documentMap).forEach(fileId => {
                const srno = documentMap[fileId];
                const statusDiv = document.getElementById(fileId + '_status');
                if (!statusDiv) return;
                
                const docData = data.documents[srno];
                if (docData && docData.success) {
                    updateDocumentStatusUI(fileId, srno, docData.file_path, docData.file_name);
                } else {
                    // CRITICAL: Show "No document uploaded" for missing documents (prevents stuck "Checking...")
                    statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                    statusDiv.className = 'mt-2 text-muted';
                }
            });
        } else {
            // Error loading documents - show error message in ALL status divs (prevents stuck "Checking...")
            Object.keys(documentMap).forEach(fileId => {
                const statusDiv = document.getElementById(fileId + '_status');
                if (statusDiv) {
                    const errorMsg = data.message || 'Error loading documents';
                    statusDiv.innerHTML = '<span class="text-muted">' + errorMsg + '</span>';
                    statusDiv.className = 'mt-2 text-muted';
                }
            });
        }
    })
    .catch(error => {
        documentsLoading = false; // Reset loading flag on error
        // Show error for ALL status divs (prevents stuck "Checking...")
        Object.keys(documentMap).forEach(fileId => {
            const statusDiv = document.getElementById(fileId + '_status');
            if (statusDiv) {
                statusDiv.innerHTML = '<span class="text-muted">Error loading documents</span>';
                statusDiv.className = 'mt-2 text-muted';
            }
        });
    });
}

// Helper function to update document status UI from consolidated response
function updateDocumentStatusUI(fileId, srno, filePath, fileName) {
    const statusDiv = document.getElementById(fileId + '_status');
    if (!statusDiv) return;

    var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
    var isFormLocked = updateButton && updateButton.style.display !== 'none';
    // CRITICAL: Escape fileId to prevent JavaScript syntax errors
    const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
    var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                <i class="fas fa-trash"></i> Delete
            </button>`;
    
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
            ${deleteButton}
            ${viewButton}
        </div>
    `;
    statusDiv.className = 'mt-2 text-success';
}

// Track document loading state
let documentsLoading = false;
let documentsLoadAttempted = false;
let documentsLoadTimeout = null;

// Force refresh all document statuses (used when Update is clicked)
function refreshAllDocumentStatuses() {
    // CRITICAL: Reset the attempted flag to allow reload (like FacultyOutput.php)
    documentsLoadAttempted = false;
    // Note: Don't reset documentsLoading here - let loadExistingDocuments() handle it
    
    // Clear existing status divs first (loadExistingDocuments will show "Checking..." for all)
    // No need to manually set "Checking..." here - loadExistingDocuments() does it
    
    // Then reload all documents (this will show "Checking..." and update all divs)
    loadExistingDocuments();
}

function confirmClearData() {
    if (confirm('Are you sure you want to clear all data? This action cannot be undone!')) {
        // Proceed with clear data
        window.location.href = '?action=clear_data';
        return true;
    }
    return false;
}

function enableUpdate() {
    // CRITICAL FIX: Enable all checkboxes specifically to ensure Select All works
    const checkboxes = document.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(cb => {
        cb.disabled = false;
        cb.removeAttribute('readonly');
        cb.style.pointerEvents = 'auto';
        cb.style.cursor = 'pointer';
    });
    
    // Enable all form inputs
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        // Skip Academic Year and Department fields - keep them non-editable
        if (input.name === 'academic_year' || input.name === 'dept_info') {
            return;
        }
        input.removeAttribute('readonly');
        input.disabled = false;
        input.style.pointerEvents = 'auto';
        input.style.cursor = 'text';
        input.style.backgroundColor = '#fff';
    });
    
    // Enable all file inputs specifically
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(input => {
        input.disabled = false;
        input.style.pointerEvents = 'auto';
        input.style.cursor = 'pointer';
    });
    
    // Enable all upload buttons
    const uploadButtons = document.querySelectorAll('button[onclick*="uploadDocument"]');
    uploadButtons.forEach(button => {
        button.disabled = false;
        button.style.pointerEvents = 'auto';
        button.style.cursor = 'pointer';
    });
    
    // Show Save Changes button, hide Update button
    const updateBtn = document.getElementById('updateBtn');
    const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
    if (updateBtn) updateBtn.style.display = 'inline-block';
    if (updateTriggerBtn) updateTriggerBtn.style.display = 'none';
    
    // Refresh document status to show delete buttons with a small delay
    setTimeout(() => {
        refreshAllDocumentStatuses();
    }, 100);
    
    // Show nice success message instead of alert
    const formContainer = document.querySelector('.modern-form');
    if (formContainer) {
        const existingAlert = formContainer.querySelector('.alert-info[data-update-message]');
        if (existingAlert) {
            existingAlert.remove();
        }
        const successAlert = document.createElement('div');
        successAlert.className = 'alert alert-info alert-dismissible fade show';
        successAlert.setAttribute('data-update-message', 'true');
        successAlert.innerHTML = `
            <i class="fas fa-info-circle me-2"></i>
            <strong>Form unlocked:</strong> You can now edit the form. Make your changes and click "Save Changes" to update.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        formContainer.insertBefore(successAlert, formContainer.firstChild);
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (successAlert.parentNode) {
                successAlert.remove();
            }
        }, 5000);
    }
}

// Function to disable all form fields (make them read-only)
function disableForm() {
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        input.setAttribute('readonly', 'readonly');
        input.disabled = true;
        input.style.pointerEvents = 'none';
        input.style.cursor = 'not-allowed';
        input.style.backgroundColor = '#f8f9fa';
    });
    
    // Disable file inputs specifically
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(input => {
        input.disabled = true;
        input.style.pointerEvents = 'none';
        input.style.cursor = 'not-allowed';
    });
    
    // Disable upload buttons
    const uploadButtons = document.querySelectorAll('button[onclick*="uploadDocument"]');
    uploadButtons.forEach(button => {
        button.disabled = true;
        button.style.pointerEvents = 'none';
        button.style.cursor = 'not-allowed';
    });
    
    // Hide Save Changes button, show Update button
    const updateBtn = document.getElementById('updateBtn');
    const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
    if (updateBtn) updateBtn.style.display = 'none';
    if (updateTriggerBtn) updateTriggerBtn.style.display = 'inline-block';
}

// CRITICAL: Prevent duplicate page initialization
let pageInitialized = false;

// Initialize form on page load - SINGLE initialization point
function initializePage() {
    if (pageInitialized) {
        return; // Already initialized - prevent duplicate calls
    }
    pageInitialized = true;
    
    // Load existing documents with a small delay to ensure DOM is ready
    setTimeout(function() {
        loadExistingDocuments();
    }, 100);
    
    // Lock form if it should be locked (when existing data is present)
    <?php if ($form_locked): ?>
    setTimeout(function() {
        disableForm();
        // Documents already loaded above - no need to reload
    }, 1500);
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

<?php
// Include your footer file
require "unified_footer.php";
?>