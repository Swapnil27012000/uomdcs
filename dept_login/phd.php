<?php
// Suppress error reporting and start output buffering
// phd - PhD details form
require('session.php');

// CRITICAL: Include skip form component BEFORE any other handlers
require_once(__DIR__ . '/skip_form_component.php');

// unified_header must be required AFTER AJAX handlers

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0); // Disable error logging completely
ob_start();require 'common_functions.php';

//Academic year logic - use centralized function
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

// Check PHP configuration for handling large forms (important for 50+ awardees)
// With 60 awardees, we have ~180 input variables (60 names + 60 dates + 60 categories)
$max_input_vars = ini_get('max_input_vars');
if ($max_input_vars && $max_input_vars < 2000) {
}

// ============================================================================
// PDF UPLOAD HANDLING - MUST BE BEFORE ANY HTML OUTPUT
// ============================================================================

// Handle PDF uploads - MUST be before unified_header.php
if (isset($_POST['upload_document'])) {
    // Start fresh output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // Suppress all errors and warnings
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 0);
    
    // Set proper headers FIRST
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // Clear any output
    ob_clean();
    
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // CSRF validation
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if missing
        }
        if (function_exists('validate_csrf')) {
            $csrf_token = $_POST['csrf_token'] ?? '';
            if (empty($csrf_token) || !validate_csrf($csrf_token)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Security token validation failed. Please refresh the page and try again.']);
                exit;
            }
        }
    }
    
    $response = null;
    try {
        $file_id = $_POST['file_id'] ?? '';
        $srno = (int)($_POST['srno'] ?? 0);
        
        // Get department ID using common function
        $dept_info = getDepartmentInfo($conn);
        if (!$dept_info) {
            throw new Exception('Department information not found');
        }
        $dept_id = $dept_info['DEPT_ID'];
        
        // Calculate academic year
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        if ($current_month >= 7) {
            $A_YEAR = $current_year . '-' . ($current_year + 1);
        } else {
            $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
        }
        
        // Route through common_upload_handler.php
        require_once(__DIR__ . '/common_upload_handler.php');
        
        // Set global variables for common_upload_handler.php
        $GLOBALS['dept_id'] = $dept_id;
        $GLOBALS['A_YEAR'] = $A_YEAR;
        
        // Determine upload directory based on srno (document type)
        // Structure: uploads/{YEAR}/DEPARTMENT/{department_ID}/phd/{document_type}/
        $document_type = '';
        $document_title = '';
        
        if ($srno == 7) {
            // PhD Awardees document
            $document_type = 'awardee';
            $document_title = 'PhD Awardees Documentation';
        } elseif ($srno == 8) {
            // Full Time Intake Proof
            $document_type = 'full time';
            $document_title = 'Full Time Intake Proof';
        } elseif ($srno == 9) {
            // Part Time Intake Proof
            $document_type = 'part time';
            $document_title = 'Part Time Intake Proof';
        } else {
            // Default fallback
            $document_type = 'documents';
            $document_title = 'PhD Documentation';
        }
        
        // Use common upload handler with correct directory structure
        // Structure: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/phd/{document_type}/
        $result = handleDocumentUpload('phd_details', $document_title, [
            'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/phd/{$document_type}/",
            'max_size' => 10,
            'document_title' => $document_title,
            'srno' => $srno,
            'file_id' => $file_id
        ]);
        
        // Clear buffers before output
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Ensure headers are set
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        
        $response = $result;
        
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'message' => 'Upload failed: ' . $e->getMessage()
        ];
    } catch (Error $e) {
        $response = [
            'success' => false,
            'message' => 'Upload failed: ' . $e->getMessage()
        ];
    }
    
    // Clean all output buffers and send clean JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    if ($response === null) {
        $response = ['success' => false, 'message' => 'Unexpected error: No response generated'];
    }
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle PDF deletion - MUST be before unified_header.php
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
        if (file_exists(__DIR__ . '/../config.php')) {
            require_once(__DIR__ . '/../config.php');
        }
    }
    ob_end_clean();
    
    ob_start();
    if (file_exists(__DIR__ . '/session.php')) {
        require_once(__DIR__ . '/session.php');
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
        
        // Get department ID using common function
        $dept_info = getDepartmentInfo($conn);
        if (!$dept_info) {
            throw new Exception('Department information not found');
        }
        $dept_id = $dept_info['DEPT_ID'];
        
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
        $get_file_query = "SELECT file_path, academic_year FROM supporting_documents 
                          WHERE dept_id = ? AND page_section = 'phd_details' 
                          AND serial_number = ? AND (academic_year = ? OR academic_year = ?) 
                          AND status = 'active' 
                          ORDER BY academic_year DESC, id DESC LIMIT 1";
        $get_stmt = mysqli_prepare($conn, $get_file_query);
        if (!$get_stmt) {
            throw new Exception('Failed to prepare query: ' . mysqli_error($conn));
        }
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
        mysqli_stmt_bind_param($get_stmt, "iss", $dept_id, $srno, $academic_year, $prev_year);
        mysqli_stmt_execute($get_stmt);
        $result = mysqli_stmt_get_result($get_stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $file_path = $row['file_path'];
            $doc_year = $row['academic_year'] ?? $academic_year;
            mysqli_free_result($result);  // CRITICAL: Free result
            mysqli_stmt_close($get_stmt);
            
            // Determine document type folder based on srno for path construction
            $document_type = '';
            if ($srno == 7) {
                $document_type = 'awardee';
            } elseif ($srno == 8) {
                $document_type = 'full time';
            } elseif ($srno == 9) {
                $document_type = 'part time';
            } else {
                $document_type = 'documents';
            }
            
            // Convert web path to physical path (use document's actual academic year)
            $phys = $file_path;
            $project_root = dirname(__DIR__);
            
            // Handle different path formats
            if (strpos($phys, $project_root) === 0) {
                // Already absolute path
                $phys = $file_path;
            } elseif (strpos($phys, '../') === 0) {
                $phys = $project_root . '/' . str_replace('../', '', $phys);
            } elseif (strpos($phys, 'uploads/') === 0) {
                $phys = $project_root . '/' . $phys;
            } else {
                // Fallback: construct expected path using document's academic year
                $filename = basename($phys);
                $phys = $project_root . "/uploads/{$doc_year}/DEPARTMENT/{$dept_id}/phd/{$document_type}/{$filename}";
            }
            
            // Normalize path separators
            $phys = str_replace('\\', '/', $phys);
            
            // Delete file if exists
            if ($phys && file_exists($phys)) {
                @unlink($phys);
            }
            
            // Soft delete using prepared statement (use document's actual academic year)
            $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'phd_details' AND serial_number = ? AND status = 'active'";
            $delete_stmt = mysqli_prepare($conn, $delete_query);
            if (!$delete_stmt) {
                $response = ['success' => false, 'message' => 'Database error: Failed to prepare delete query'];
            } else {
                mysqli_stmt_bind_param($delete_stmt, "sis", $doc_year, $dept_id, $srno);
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
        mysqli_stmt_close($get_stmt);
            $response = ['success' => false, 'message' => 'Document not found'];
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
        $dept_info = getDepartmentInfo($conn);
        if (!$dept_info) {
            $response = ['success' => false, 'message' => 'Department information not found'];
        } else {
            $dept_id = $dept_info['DEPT_ID'];
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
                           WHERE dept_id = ? AND page_section = 'phd_details' 
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
                            // Determine document type folder based on srno
                            $document_type = '';
                            if ($srno == 7) {
                                $document_type = 'awardee';
                            } elseif ($srno == 8) {
                                $document_type = 'full time';
                            } elseif ($srno == 9) {
                                $document_type = 'part time';
                            } else {
                                $document_type = 'documents';
                            }
                            
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
                                $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/phd/' . $document_type . '/' . $filename;
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

// Handle document status check - MUST be before unified_header.php
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
    if (file_exists(__DIR__ . '/session.php')) {
        require_once(__DIR__ . '/session.php');
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
        
        // Get department ID using common function
        $dept_info = getDepartmentInfo($conn);
        if (!$dept_info) {
            throw new Exception('Department information not found');
        }
        $dept = $dept_info['DEPT_ID'];
        
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
        $get_file_query = "SELECT file_path, file_name, upload_date, id, academic_year 
                          FROM supporting_documents 
                          WHERE dept_id = ? AND page_section = 'phd_details' 
                          AND serial_number = ? AND (academic_year = ? OR academic_year = ?) 
                          AND status = 'active' 
                          ORDER BY academic_year DESC, id DESC LIMIT 1";
        $get_stmt = mysqli_prepare($conn, $get_file_query);
        if (!$get_stmt) {
            throw new Exception('Database error: ' . mysqli_error($conn));
        }
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
        mysqli_stmt_bind_param($get_stmt, "iss", $dept, $srno, $academic_year, $prev_year);
        mysqli_stmt_execute($get_stmt);
        $result = mysqli_stmt_get_result($get_stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $doc_year = $row['academic_year'] ?? $academic_year;
            mysqli_free_result($result);  // CRITICAL: Free result
            mysqli_stmt_close($get_stmt);
            
            $file_path = $row['file_path'];
            
            // Determine document type folder based on srno
            $document_type = '';
            if ($srno == 7) {
                $document_type = 'awardee';
            } elseif ($srno == 8) {
                $document_type = 'full time';
            } elseif ($srno == 9) {
                $document_type = 'part time';
            } else {
                $document_type = 'documents';
            }
            
            // Convert absolute paths to relative web paths (robust path conversion like PlacementDetails.php)
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
                $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept . '/phd/' . $document_type . '/' . $filename;
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
            mysqli_stmt_close($get_stmt);
            $response = ['success' => false, 'message' => 'No document found'];
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
// MAIN FORM SUBMISSION HANDLER - MUST BE BEFORE unified_header.php
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_phd']) || isset($_POST['update_phd']))) {
    // Clear output buffer before processing to prevent headers already sent error
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Ensure config.php is loaded for database connection
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    
    // Get department ID and connection (ensure they're available)
    // session.php sets $userInfo
    if (!isset($userInfo) || empty($userInfo)) {
        $_SESSION['error'] = 'Session error. Please login again.';
        header('Location: phd.php');
        exit;
    }
    
    $dept = $userInfo['DEPT_ID'] ?? 0;
    if (!$dept) {
        $_SESSION['error'] = 'Department ID not found. Please login again.';
        header('Location: phd.php');
        exit;
    }
    
    // CRITICAL FIX: Get academic year using centralized function
    if (!function_exists('getAcademicYear')) {
        if (file_exists(__DIR__ . '/../common_progress_functions.php')) {
            require_once(__DIR__ . '/../common_progress_functions.php');
        }
    }
    if (function_exists('getAcademicYear')) {
        $A_YEAR = getAcademicYear();
    } else {
        // Fallback calculation - CORRECTED: July rollover
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        if ($current_month >= 7) {
            $A_YEAR = $current_year . '-' . ($current_year + 1);
        } else {
            $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
        }
    }
    
    // CRITICAL FIX: Check if data exists for THIS academic year (not just any year)
    $existing_data = null;
    $check_existing_query = "SELECT * FROM phd_details WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $check_stmt = mysqli_prepare($conn, $check_existing_query);
    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, 'is', $dept, $A_YEAR);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $existing_data = mysqli_fetch_assoc($check_result);
        }
        if ($check_result) {
            mysqli_free_result($check_result);
        }
        mysqli_stmt_close($check_stmt);
    }
    
    // Debug: Log that handler was triggered
    
    // CSRF validation
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if missing
        }
        if (function_exists('validate_csrf')) {
            $csrf_token = $_POST['csrf_token'] ?? '';
            if (empty($csrf_token) || !validate_csrf($csrf_token)) {
                // Clear output buffer before redirect
                while (ob_get_level()) {
                    ob_end_clean();
                }
                $_SESSION['error'] = 'Security token validation failed. Please refresh the page and try again.';
                header('Location: phd.php');
                exit;
            }
        }
    }
    
    try {
        // Validate and sanitize numeric inputs
        $Male_Students_FT = max(0, (int)($_POST['Male_Students_FT'] ?? 0));
        $Female_Students_FT = max(0, (int)($_POST['Female_Students_FT'] ?? 0));
        $Other_Students_FT = max(0, (int)($_POST['Other_Students_FT'] ?? 0));
        $Male_Students_PT = max(0, (int)($_POST['Male_Students_PT'] ?? 0));
        $Female_Students_PT = max(0, (int)($_POST['Female_Students_PT'] ?? 0));
        $Other_Students_PT = max(0, (int)($_POST['Other_Students_PT'] ?? 0));
        $Male_Students_AWD_FT = max(0, (int)($_POST['Male_Students_AWD_FT'] ?? 0));
        $Female_Students_AWD_FT = max(0, (int)($_POST['Female_Students_AWD_FT'] ?? 0));
        $Other_Students_AWD_FT = max(0, (int)($_POST['Other_Students_AWD_FT'] ?? 0));
        $Male_Students_AWD_PT = max(0, (int)($_POST['Male_Students_AWD_PT'] ?? 0));
        $Female_Students_AWD_PT = max(0, (int)($_POST['Female_Students_AWD_PT'] ?? 0));
        $Other_Students_AWD_PT = max(0, (int)($_POST['Other_Students_AWD_PT'] ?? 0));
        

        // Process PhD awardee details - sanitize inputs
        $phd_awardee_names = $_POST['phd_awardee_names'] ?? [];
        $phd_awardee_dates = $_POST['phd_awardee_dates'] ?? [];
        $phd_awardee_categories = $_POST['phd_awardee_categories'] ?? [];
        
        // Ensure arrays are actually arrays
        if (!is_array($phd_awardee_names)) {
            $phd_awardee_names = [];
        }
        if (!is_array($phd_awardee_dates)) {
            $phd_awardee_dates = [];
        }
        if (!is_array($phd_awardee_categories)) {
            $phd_awardee_categories = [];
        }
        
        $names_count = count($phd_awardee_names);
        $dates_count = count($phd_awardee_dates);
        $categories_count = count($phd_awardee_categories);
        
        
        // CRITICAL FIX: Check for array length mismatches - indicates data loss
        if ($names_count !== $dates_count || $names_count !== $categories_count) {
            
            // Use the minimum length to prevent index errors
            $min_length = min($names_count, $dates_count, $categories_count);
            if ($min_length < $names_count) {
            }
        } else {
            $min_length = $names_count;
        }
        
        // Create JSON data for awardee details
        $awardee_details = [];
        $category_counts = [
            'Male_FT' => 0,
            'Female_FT' => 0,
            'Other_FT' => 0,
            'Male_PT' => 0,
            'Female_PT' => 0,
            'Other_PT' => 0
        ];
        
        // FIXED: Use minimum length and ensure we process all available data
        for ($i = 0; $i < $min_length; $i++) {
            $name = trim($phd_awardee_names[$i] ?? '');
            $date = trim($phd_awardee_dates[$i] ?? '');
            $category = trim($phd_awardee_categories[$i] ?? '');
            
            // Only add if name is not empty (date can be added later)
            if (!empty($name)) {
                // Validate category is one of the expected values
                if (!isset($category_counts[$category])) {
                    // Default to Other_FT if category is invalid
                    $category = 'Other_FT';
                }
                
                $awardee_details[] = [
                    'name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                    'date' => htmlspecialchars($date, ENT_QUOTES, 'UTF-8'),
                    'category' => htmlspecialchars($category, ENT_QUOTES, 'UTF-8')
                ];
                
                // Count by category for debugging
                if (isset($category_counts[$category])) {
                    $category_counts[$category]++;
                }
            }
        }
        
        // Log category breakdown
        $awardee_details_json = json_encode($awardee_details, JSON_UNESCAPED_UNICODE);
        
        // Check if data was lost
        if ($names_count > $min_length) {
            $lost_count = $names_count - $min_length;
        }

        // For single entry system, always update if exists, insert if not
        if ($existing_data) {
            // UPDATE existing record using prepared statement
            // 15 parameters: A_YEAR(s), DEPT_ID(i), then 12 integers, PHD_AWARDEES_DETAILS(s), WHERE DEPT_ID(i)
            $update_query = "UPDATE phd_details SET A_YEAR = ?, DEPT_ID = ?, FULL_TIME_MALE_STUDENTS = ?, FULL_TIME_FEMALE_STUDENTS = ?, FULL_TIME_OTHER_STUDENTS = ?, PART_TIME_MALE_STUDENTS = ?, PART_TIME_FEMALE_STUDENTS = ?, PART_TIME_OTHER_STUDENTS = ?, PHD_AWARDED_MALE_STUDENTS_FULL = ?, PHD_AWARDED_FEMALE_STUDENTS_FULL = ?, PHD_AWARDED_OTHER_STUDENTS_FULL = ?, PHD_AWARDED_MALE_STUDENTS_PART = ?, PHD_AWARDED_FEMALE_STUDENTS_PART = ?, PHD_AWARDED_OTHER_STUDENTS_PART = ?, PHD_AWARDEES_DETAILS = ? WHERE DEPT_ID = ?";
            
            $update_stmt = mysqli_prepare($conn, $update_query);
            if (!$update_stmt) {
                throw new Exception("Failed to prepare update statement: " . mysqli_error($conn));
            }
            
            // Type string: A_YEAR(s=1) + DEPT_ID(i=1) + 13 integers(i=13) + PHD_AWARDEES_DETAILS(s=1) + WHERE DEPT_ID(i=1) = 17 chars total
            // Parameters: A_YEAR(s), DEPT_ID(i), 13 integers, PHD_AWARDEES_DETAILS(s), WHERE DEPT_ID(i) = 17 total
            // But wait - let me count the actual placeholders in the query: 16 placeholders (15 in SET + 1 in WHERE)
            // Type string: s + i + (13*i) + s + i = "s" + "i" + 13*"i" + "s" + "i" = "siiiiiiiiiiiiisi" (16 chars)
            mysqli_stmt_bind_param($update_stmt, "siiiiiiiiiiiiisi", $A_YEAR, $dept, $Male_Students_FT, $Female_Students_FT, $Other_Students_FT, $Male_Students_PT, $Female_Students_PT, $Other_Students_PT, $Male_Students_AWD_FT, $Female_Students_AWD_FT, $Other_Students_AWD_FT, $Male_Students_AWD_PT, $Female_Students_AWD_PT, $Other_Students_AWD_PT, $awardee_details_json, $dept);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $affected_rows = mysqli_stmt_affected_rows($update_stmt);
                mysqli_stmt_close($update_stmt);
                
                // CRITICAL: Clear and recalculate score cache after data update
                require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                clearDepartmentScoreCache($dept, $A_YEAR, true);
                
                $success_message = "Record updated successfully!";
                $_SESSION['success'] = $success_message;
                // Clear ALL output buffers before redirect
                while (ob_get_level()) {
                    ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Location: phd.php');
                    exit;
                } else {
                    echo '<script>window.location.href = "phd.php";</script>';
                    exit;
                }
            } else {
                $error = mysqli_stmt_error($update_stmt);
                mysqli_stmt_close($update_stmt);
                throw new Exception("Error updating record: " . $error);
            }
        } else {
            // INSERT new record using prepared statement
            // 14 parameters: A_YEAR(s), DEPT_ID(i), then 12 integers, PHD_AWARDEES_DETAILS(s)
            $insert_query = "INSERT INTO phd_details (A_YEAR, DEPT_ID, FULL_TIME_MALE_STUDENTS, FULL_TIME_FEMALE_STUDENTS, FULL_TIME_OTHER_STUDENTS, PART_TIME_MALE_STUDENTS, PART_TIME_FEMALE_STUDENTS, PART_TIME_OTHER_STUDENTS, PHD_AWARDED_MALE_STUDENTS_FULL, PHD_AWARDED_FEMALE_STUDENTS_FULL, PHD_AWARDED_OTHER_STUDENTS_FULL, PHD_AWARDED_MALE_STUDENTS_PART, PHD_AWARDED_FEMALE_STUDENTS_PART, PHD_AWARDED_OTHER_STUDENTS_PART, PHD_AWARDEES_DETAILS) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            if (!$insert_stmt) {
                throw new Exception("Failed to prepare insert statement: " . mysqli_error($conn));
            }
            
            // Type string: A_YEAR(s=1) + DEPT_ID(i=1) + 13 integers(i=13) + PHD_AWARDEES_DETAILS(s=1) = 16 chars total
            // Parameters: A_YEAR(s), DEPT_ID(i), 13 integers, PHD_AWARDEES_DETAILS(s) = 16 total
            // But wait - let me count the actual placeholders in the query: 15 placeholders
            // Actually counting: A_YEAR(s), DEPT_ID(i), FULL_TIME_MALE(i), FULL_TIME_FEMALE(i), FULL_TIME_OTHER(i), PART_TIME_MALE(i), PART_TIME_FEMALE(i), PART_TIME_OTHER(i), AWD_FULL_MALE(i), AWD_FULL_FEMALE(i), AWD_FULL_OTHER(i), AWD_PART_MALE(i), AWD_PART_FEMALE(i), AWD_PART_OTHER(i), PHD_AWARDEES_DETAILS(s) = 15
            // Type string: s + i + (13*i) + s = "s" + "i" + 13*"i" + "s" = "siiiiiiiiiiiiis" (15 chars)
            mysqli_stmt_bind_param($insert_stmt, "siiiiiiiiiiiiis", $A_YEAR, $dept, $Male_Students_FT, $Female_Students_FT, $Other_Students_FT, $Male_Students_PT, $Female_Students_PT, $Other_Students_PT, $Male_Students_AWD_FT, $Female_Students_AWD_FT, $Other_Students_AWD_FT, $Male_Students_AWD_PT, $Female_Students_AWD_PT, $Other_Students_AWD_PT, $awardee_details_json);
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $insert_id = mysqli_insert_id($conn);
                mysqli_stmt_close($insert_stmt);
                
                // CRITICAL: Clear and recalculate score cache after data insert
                require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                clearDepartmentScoreCache($dept, $A_YEAR, true);
                
                $success_message = "Record added successfully!";
                $_SESSION['success'] = $success_message;
                // Clear ALL output buffers before redirect
                while (ob_get_level()) {
                    ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Location: phd.php');
                    exit;
                } else {
                    echo '<script>window.location.href = "phd.php";</script>';
                    exit;
                }
            } else {
                $error = mysqli_stmt_error($insert_stmt);
                mysqli_stmt_close($insert_stmt);
                throw new Exception("Error adding record: " . $error);
            }
        }
    } catch (Exception $e) {
        $error_message = "Error processing form: " . $e->getMessage();
        $_SESSION['error'] = $error_message;
        // Clear ALL output buffers before redirect
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: phd.php');
            exit;
        } else {
            echo '<script>window.location.href = "phd.php";</script>';
            exit;
        }
    } catch (Error $e) {
        $error_message = "Fatal error processing form: " . $e->getMessage();
        $_SESSION['error'] = $error_message;
        // Clear ALL output buffers before redirect
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: phd.php');
            exit;
        } else {
            echo '<script>window.location.href = "phd.php";</script>';
            exit;
        }
    }
}

// ============================================================================
// HTML OUTPUT STARTS HERE
// ============================================================================

// Include unified header AFTER all AJAX handlers and POST handlers
require('unified_header.php');

// Get department ID from session (already verified in session.php)
$dept = $userInfo['DEPT_ID'];





if (!$dept) {


    throw new Exception('Department ID not found. Please contact administrator.');


}
$dept_code = $dept_info['DEPT_COLL_NO'];
$dept_name = $dept_info['DEPT_NAME'];
    
// CRITICAL FIX: Check if data exists for CURRENT academic year (not any year)
// This ensures: Submit button for new year, Update button for existing year
$existing_data = null;
$check_existing_query = "SELECT * FROM phd_details WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $check_existing_query);
mysqli_stmt_bind_param($stmt, 'is', $dept, $A_YEAR);
mysqli_stmt_execute($stmt);
$check_existing = mysqli_stmt_get_result($stmt);

if ($check_existing && mysqli_num_rows($check_existing) > 0) {
    $existing_data = mysqli_fetch_assoc($check_existing);
}
if ($check_existing) {
    mysqli_free_result($check_existing);
}

// Initialize variables
$form_locked = false;
$success_message = '';
$error_message = '';

// Check for success/error messages from session
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

// Handle clear data action
if (isset($_GET['action']) && $_GET['action'] === 'clear_data') {
    // Delete from main table
    $stmt = mysqli_prepare($conn, "DELETE FROM phd_details WHERE DEPT_ID = ?");
    mysqli_stmt_bind_param($stmt, 'i', $dept);
    $main_deleted = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    // Delete uploaded documents using the unified supporting_documents table
    // Get academic year for path construction
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    if ($current_month >= 7) {
        $academic_year = $current_year . '-' . ($current_year + 1);
    } else {
        $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
    }
    
    $delete_docs_query = "SELECT file_path, serial_number FROM supporting_documents WHERE dept_id = ? AND page_section = 'phd_details' AND status = 'active'";
    $stmt_docs = mysqli_prepare($conn, $delete_docs_query);
    mysqli_stmt_bind_param($stmt_docs, 'i', $dept);
    mysqli_stmt_execute($stmt_docs);
    $docs_result = mysqli_stmt_get_result($stmt_docs);
    
    if ($docs_result) {
        while ($doc = mysqli_fetch_assoc($docs_result)) {
            $file_path = $doc['file_path'];
            $srno = $doc['serial_number'];
            
            // Determine document type folder based on srno
            $document_type = '';
            if ($srno == 7) {
                $document_type = 'awardee';
            } elseif ($srno == 8) {
                $document_type = 'full time';
            } elseif ($srno == 9) {
                $document_type = 'part time';
            } else {
                $document_type = 'documents';
            }
            
            // Convert web path to physical path
            if (strpos($file_path, '../') === 0) {
                $phys = dirname(__DIR__) . '/' . str_replace('../', '', $file_path);
            } elseif (strpos($file_path, 'uploads/') === 0) {
                $phys = dirname(__DIR__) . '/' . $file_path;
            } elseif (strpos($file_path, '/home/') === 0 || strpos($file_path, 'C:/') === 0 || strpos($file_path, 'C:\\') === 0) {
                $phys = $file_path;
            } else {
                // Fallback: construct expected path
                $filename = basename($file_path);
                $phys = dirname(__DIR__) . "/uploads/{$academic_year}/DEPARTMENT/{$dept}/phd/{$document_type}/{$filename}";
            }
            
            // Normalize path separators
            $phys = str_replace('\\', '/', $phys);
            
            if ($phys && file_exists($phys)) {
                unlink($phys);
            }
        }
    }
    mysqli_stmt_close($stmt_docs);
    
    // Hard delete from unified supporting_documents table (completely remove records)
    $delete_docs_query = "DELETE FROM supporting_documents WHERE dept_id = ? AND page_section = 'phd_details'";
    $stmt_docs = mysqli_prepare($conn, $delete_docs_query);
    mysqli_stmt_bind_param($stmt_docs, 'i', $dept);
    $docs_deleted = mysqli_stmt_execute($stmt_docs);
    mysqli_stmt_close($stmt_docs);
    
    if ($main_deleted) {
        $success_message = "Data and uploaded documents cleared successfully!";
        $existing_data = null;
        $form_locked = false;
        echo "<script>
            setTimeout(function() {
                window.location.href = 'phd.php';
            }, 2000);
        </script>";
    } else {
        $error_message = "Error clearing data: " . mysqli_error($conn);
    }
}


if(isset($_GET['action'])) {
    $action = $_GET['action'];
    if($action == 'delete') {
        // CRITICAL SECURITY FIX: Must verify DEPT_ID to prevent unauthorized deletion
        $id = (int)($_GET['ID'] ?? 0);
        if ($id > 0) {
            // Verify the record belongs to this department before deleting
            $check_query = "SELECT DEPT_ID FROM phd_details WHERE ID = ? AND DEPT_ID = ? LIMIT 1";
            $check_stmt = mysqli_prepare($conn, $check_query);
            if ($check_stmt) {
                mysqli_stmt_bind_param($check_stmt, "ii", $id, $dept);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                
                if ($check_result && mysqli_num_rows($check_result) > 0) {
                    // Record belongs to this department, safe to delete
                    $delete_query = "DELETE FROM phd_details WHERE ID = ? AND DEPT_ID = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    if ($delete_stmt) {
                        mysqli_stmt_bind_param($delete_stmt, "ii", $id, $dept);
                        mysqli_stmt_execute($delete_stmt);
                        mysqli_stmt_close($delete_stmt);
                    }
                    mysqli_stmt_close($check_stmt);
                } else {
                    mysqli_stmt_close($check_stmt);
                    // Record not found or doesn't belong to this department
                    $_SESSION['error'] = 'Record not found or you do not have permission to delete it.';
                }
            }
        }
        echo '<script>window.location.href = "phd.php";</script>';
        exit;
    }
}
?>
<div class="container-fluid" style="max-width: 100%; overflow-x: hidden; padding-left: 10px;">
    <div class="main-content-area" style="margin-left: 10px;">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-user-graduate me-3"></i>PhD Details
                    </h1>
                    <p class="page-subtitle">Manage PhD student information and research details</p>
                </div>
                <a href="export_page_pdf.php?page=phd" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
            </div>
        </div>

        <?php
        // ============================================================================
        // SKIP FORM BUTTON - Display if department has no PhD data
        // ============================================================================
        
        // Check if data already exists for current academic year
        $has_phd_data = false;
        $check_phd_query = "SELECT id FROM phd_details WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
        $check_phd_stmt = mysqli_prepare($conn, $check_phd_query);
        if ($check_phd_stmt) {
            mysqli_stmt_bind_param($check_phd_stmt, 'is', $dept, $A_YEAR);
            mysqli_stmt_execute($check_phd_stmt);
            $check_phd_result = mysqli_stmt_get_result($check_phd_stmt);
            if ($check_phd_result && mysqli_num_rows($check_phd_result) > 0) {
                $has_phd_data = true;
            }
            if ($check_phd_result) {
                mysqli_free_result($check_phd_result);
            }
            mysqli_stmt_close($check_phd_stmt);
        }
        
        // Display skip button only if no data exists
        displaySkipFormButton('phd', 'PhD Details', $A_YEAR, $has_phd_data);
        ?>

        <div class="card">
            <div class="card-body">
                <form class="modern-form" method="POST" id="phdForm" novalidate>
                    <?php 
                    // Ensure CSRF token is generated
                    if (file_exists(__DIR__ . '/csrf.php')) {
                        require_once __DIR__ . '/csrf.php';
                        if (function_exists('csrf_token')) {
                            csrf_token(); // Generate token if missing
                        }
                    }
                    if (function_exists('csrf_field')) {
                        echo csrf_field();
                    } else {
                        // Fallback: generate token manually
                        if (!isset($_SESSION['csrf_token'])) {
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        }
                        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') . '">';
                    }
                    ?>
                    <!-- Success/Error Messages -->
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert" id="successAlert">
                            <?php echo htmlspecialchars($success_message ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <script>
                            setTimeout(function() {
                                const successAlert = document.getElementById('successAlert');
                                if (successAlert) {
                                    successAlert.style.transition = 'opacity 0.5s';
                                    successAlert.style.opacity = '0';
                                    setTimeout(() => successAlert.remove(), 500);
                                }
                                disableForm();
                            }, 2000);
                        </script>
                    <?php endif; ?>

                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($error_message ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($form_locked): ?>
                        <div class="alert alert-info">
                            <strong>Form Status:</strong> Data has been submitted. Click "Update" to modify existing data.
                        </div>
                    <?php endif; ?>
                    
                    <!-- Smooth Message Container -->
                    <div id="soft-message" class="soft-message" style="display:none; position: fixed; top: 20px; right: 20px; padding: 12px 16px; border-radius: 8px; color: #fff; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 300px; max-width: 400px;"></div>

                    <!-- PhD Statistics Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-chart-bar me-2"></i>PhD Student Statistics
                        </h3>
                        
                        <div class="table-responsive">
                            <table class="table table-hover modern-table">
                    <thead class="table-header">
                        <tr>
                            <th scope="col" class="text-center">Category</th>
                            <th scope="col" class="text-center">Male</th>
                            <th scope="col" class="text-center">Female</th>
                            <th scope="col" class="text-center">Other</th>
                            <th scope="col" class="text-center">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Full Time Students -->
                        <tr>
                            <td class="fw-bold">Full Time Students</td>
                            <td>
                                <input type="number" name="Male_Students_FT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? $existing_data['FULL_TIME_MALE_STUDENTS'] : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required>
                            </td>
                            <td>
                                <input type="number" name="Female_Students_FT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? $existing_data['FULL_TIME_FEMALE_STUDENTS'] : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required>
                            </td>
                            <td>
                                <input type="number" name="Other_Students_FT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? ($existing_data['FULL_TIME_OTHER_STUDENTS'] ?? '0') : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required>
                            </td>
                            <td class="text-center fw-bold" id="total_ft">0</td>
                        </tr>
                        
                        <!-- Part Time Students -->
                        <tr>
                            <td class="fw-bold">Part Time Students</td>
                            <td>
                                <input type="number" name="Male_Students_PT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? $existing_data['PART_TIME_MALE_STUDENTS'] : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required>
                            </td>
                            <td>
                                <input type="number" name="Female_Students_PT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? $existing_data['PART_TIME_FEMALE_STUDENTS'] : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required>
                            </td>
                            <td>
                                <input type="number" name="Other_Students_PT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? ($existing_data['PART_TIME_OTHER_STUDENTS'] ?? '0') : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required>
                            </td>
                            <td class="text-center fw-bold" id="total_pt">0</td>
                        </tr>
                        
                        <!-- PhD Awarded Full Time -->
                        <tr class="table-success">
                            <td class="fw-bold">PhD Awarded (Full Time)</td>
                            <td>
                                <input type="number" name="Male_Students_AWD_FT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? $existing_data['PHD_AWARDED_MALE_STUDENTS_FULL'] : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required oninput="debouncedGenerateFields()">
                            </td>
                            <td>
                                <input type="number" name="Female_Students_AWD_FT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? $existing_data['PHD_AWARDED_FEMALE_STUDENTS_FULL'] : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required oninput="debouncedGenerateFields()">
                            </td>
                            <td>
                                <input type="number" name="Other_Students_AWD_FT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? ($existing_data['PHD_AWARDED_OTHER_STUDENTS_FULL'] ?? '0') : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required oninput="debouncedGenerateFields()">
                            </td>
                            <td class="text-center fw-bold" id="total_awd_ft">0</td>
                        </tr>
                        
                        <!-- PhD Awarded Part Time -->
                        <tr class="table-success">
                            <td class="fw-bold">PhD Awarded (Part Time)</td>
                            <td>
                                <input type="number" name="Male_Students_AWD_PT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? $existing_data['PHD_AWARDED_MALE_STUDENTS_PART'] : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required oninput="debouncedGenerateFields()">
                            </td>
                            <td>
                                <input type="number" name="Female_Students_AWD_PT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? $existing_data['PHD_AWARDED_FEMALE_STUDENTS_PART'] : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required oninput="debouncedGenerateFields()">
                            </td>
                            <td>
                                <input type="number" name="Other_Students_AWD_PT" class="form-control text-center" placeholder="0" min="0" 
                                       value="<?php echo $existing_data ? ($existing_data['PHD_AWARDED_OTHER_STUDENTS_PART'] ?? '0') : '0'; ?>" 
                                       <?php echo $form_locked ? 'readonly disabled' : ''; ?> required oninput="debouncedGenerateFields()">
                            </td>
                            <td class="text-center fw-bold" id="total_awd_pt">0</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Dynamic Fields for PhD Awardees -->
        <div class="mb-4">
            <h5 class="text-primary mb-3">🎓 PhD Awardee Details</h5>
            
            <!-- Male Full Time Awardees -->
            <div id="male_ft_awardees_container" class="mt-3">
                <!-- Dynamic fields will be generated here -->
            </div>
            
            <!-- Female Full Time Awardees -->
            <div id="female_ft_awardees_container" class="mt-3">
                <!-- Dynamic fields will be generated here -->
            </div>
            
            <!-- Other Full Time Awardees -->
            <div id="other_ft_awardees_container" class="mt-3">
                <!-- Dynamic fields will be generated here -->
            </div>
            
            <!-- Male Part Time Awardees -->
            <div id="male_pt_awardees_container" class="mt-3">
                <!-- Dynamic fields will be generated here -->
            </div>
            
            <!-- Female Part Time Awardees -->
            <div id="female_pt_awardees_container" class="mt-3">
                <!-- Dynamic fields will be generated here -->
            </div>
            
            <!-- Other Part Time Awardees -->
            <div id="other_pt_awardees_container" class="mt-3">
                <!-- Dynamic fields will be generated here -->
            </div>
        </div>
        <!-- Proof of Intake Documentation -->
        <div class="mb-4">
            <h5 class="mb-3" style="color: #667eea; font-weight: 700;">Proof of Intake Documentation</h5>
            <div class="alert alert-info">
                <strong>Instructions:</strong> Upload PDF documents containing proof of intake for Full Time and Part Time PhD students. This should include official documents showing the number of students enrolled in each category.
            </div>
            
            <!-- Full Time Intake Proof -->
            <div class="upload-section mb-3">
                <h6 class="text-primary mb-2">Full Time Intake Proof</h6>
                <div class="input-group">
                    <input type="file" id="fulltime_intake_proof" name="fulltime_intake_proof" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('fulltime_intake_proof', 8)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>Upload</button>
                </div>
                <div id="fulltime_intake_proof_status" class="mt-2"></div>
            </div>
            
            <!-- Part Time Intake Proof -->
            <div class="upload-section">
                <h6 class="text-primary mb-2">Part Time Intake Proof</h6>
                <div class="input-group">
                    <input type="file" id="parttime_intake_proof" name="parttime_intake_proof" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('parttime_intake_proof', 9)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>Upload</button>
                </div>
                <div id="parttime_intake_proof_status" class="mt-2"></div>
            </div>
        </div>
        <hr>

        <div class="mb-4">
            <label class="form-label fw-bold mb-3"><b>Supporting Document for PhD Awardees <span class="text-danger">*</span></b></label>
            <div class="alert alert-info" role="alert">
                <strong>Note:</strong> Upload a PDF document containing the names and details of all PhD awardees for the academic year.
            </div>
            <div class="input-group">
                <input type="file" id="phd_awardees_pdf" name="phd_awardees_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('phd_awardees_pdf', 7)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>Upload</button>
            </div>
            <div id="phd_awardees_pdf_status" class="mt-2"></div>
        </div>

        <div class="text-center">
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <?php if ($form_locked): ?>
                    <button type="button" class="btn btn-warning btn-lg" onclick="enableUpdate()">
                        <i class="fas fa-edit me-2"></i>Update
                    </button>
                    <button type="submit" name="update_phd" class="btn btn-success btn-lg" id="updateBtn" style="display:none;">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                    <a href="?action=clear_data" class="btn btn-danger btn-lg" 
                       onclick="return confirmClearData()">
                        <i class="fas fa-trash me-2"></i>Clear Data
                    </a>
                <?php else: ?>
                    <button type="submit" name="save_phd" class="btn btn-primary btn-lg">
                        <i class="fas fa-paper-plane me-2"></i>Submit Details
                    </button>
                <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
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

    .upload-section {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
        border: 2px solid rgba(102, 126, 234, 0.1);
        border-radius: 0.75rem;
        padding: 1.5rem;
        margin-bottom: 1rem;
        position: relative;
        overflow: hidden;
    }

    .upload-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #667eea, #764ba2);
    }

    .upload-section h6 {
        color: #667eea;
        font-weight: 600;
        margin-bottom: 0.75rem;
    }

    .alert {
        border-radius: 0.75rem;
        border: none;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .alert-info {
        background: linear-gradient(135deg, rgba(23, 162, 184, 0.1) 0%, rgba(23, 162, 184, 0.05) 100%);
        border-left: 4px solid #17a2b8;
        color: #2b3a55;
    }

    @media (max-width: 767px) {
        .container {
            padding: 1rem 0.5rem;
        }
    }
</style>
<script>
// Flag to prevent infinite loops - MUST be defined first
let isGeneratingFields = false;
let generateFieldsTimeout;

// Debounce function to prevent infinite loops - MUST be defined before HTML uses it
// Make it globally accessible for inline handlers
function debouncedGenerateFields() {
    if (typeof generateFieldsTimeout !== 'undefined') {
        clearTimeout(generateFieldsTimeout);
    }
    generateFieldsTimeout = setTimeout(function() {
        if (typeof generatePhdAwardeeFields === 'function') {
            generatePhdAwardeeFields();
        } else {
        }
    }, 300); // Wait 300ms after user stops typing
}

// Also expose to window for inline handlers (as fallback)
window.debouncedGenerateFields = debouncedGenerateFields;

// Get CSRF token function - MUST be defined early for onclick handlers
function getCSRFToken() {
    let csrfToken = '';
    const metaToken = document.querySelector('meta[name="csrf-token"]');
    if (metaToken && metaToken.content && metaToken.content.trim() !== '') {
        csrfToken = metaToken.content.trim();
    } else {
        const formToken = document.querySelector('input[name="csrf_token"]');
        if (formToken && formToken.value && formToken.value.trim() !== '') {
            csrfToken = formToken.value.trim();
        }
    }
    return csrfToken;
}

// Upload document function - MUST be defined early for onclick handlers
function uploadDocument(fileId, srno) {
    const fileInput = document.getElementById(fileId);
    const statusDiv = document.getElementById(fileId + '_status');
    
    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
        showSmoothMessage('Please select a file to upload.', 'error');
        return;
    }
    
    // Validate PDF
    const file = fileInput.files[0];
    if (file.type !== 'application/pdf') {
        showSmoothMessage('Please select a PDF file.', 'error');
        fileInput.value = '';
        return;
    }
    
    const fileSize = file.size / (1024 * 1024); // Size in MB
    if (fileSize > 10) {
        showSmoothMessage('File size must be less than 10MB.', 'error');
        fileInput.value = '';
        return;
    }
    
    const formData = new FormData();
    formData.append('upload_document', '1');
    formData.append('file_id', fileId);
    formData.append('srno', srno);
    formData.append('document', file);
    
    // Add CSRF token
    const csrfToken = getCSRFToken();
    if (csrfToken) {
        formData.append('csrf_token', csrfToken);
    } else {
        showSmoothMessage('Security token not found. Please refresh the page and try again.', 'error');
        return;
    }
    
    // Show loading state
    if (statusDiv) {
        statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Uploading...';
        statusDiv.className = 'mt-2 text-info';
    }
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .catch(error => {
        // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
        console.error('[PhD] Fetch error for upload:', error);
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
            console.error('[PhD] Invalid response object:', response);
            return { success: false, message: 'Upload failed: Invalid server response' };
        }
        
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
        }).catch(textError => {
            // CRITICAL: Handle text() parsing errors
            console.error('[PhD] Error reading response text:', textError);
            return { success: false, message: 'Upload failed: Error reading server response' };
        });
    })
    .then(data => {
        if (!statusDiv) return;
        
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Upload failed: Invalid server response</span>';
            statusDiv.className = 'mt-2 text-danger';
            return;
        }
        
        if (data.success) {
            // Check if form is locked by checking any input field's disabled state
            const testInput = document.querySelector('input[name="Male_Students_AWD_FT"]');
            const isFormLocked = testInput ? (testInput.disabled || testInput.hasAttribute('readonly')) : false;
            
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            // Ensure correct path format for view link
            // Expected structure: uploads/{YEAR}/DEPARTMENT/{dept_id}/phd/{document_type}/{filename}
            let viewPath = data.file_path || '';
            
            // Normalize path separators
            viewPath = viewPath.replace(/\\/g, '/');
            
            // If path doesn't start with '../' and starts with 'uploads/', prepend '../'
            if (viewPath && !viewPath.startsWith('../') && viewPath.startsWith('uploads/')) {
                viewPath = '../' + viewPath;
            }
            // If path is absolute, try to convert to relative
            if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
                // Extract relative path - match new structure: uploads/{YEAR}/DEPARTMENT/{dept_id}/phd/{type}/{file}
                const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/phd\/[^\/]+\/.+\.pdf)$/);
                if (match) {
                    viewPath = '../' + match[1];
                } else {
                    // Fallback: try old structure
                    const matchOld = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/);
                    if (matchOld) {
                        viewPath = '../' + matchOld[1];
                    }
                }
            }
            
            // Ensure path is properly formatted for web access
            if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http')) {
                viewPath = '../' + viewPath.replace(/^\.\.\//, '');
            }
            
            // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
            const escapedViewPath = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success">${data.message || 'Document uploaded successfully!'}</span>
                    ${deleteButton}
                    ${escapedViewPath ? `<a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                        <i class="fas fa-eye"></i> View
                    </a>` : ''}
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
            
            // Clear the file input
            fileInput.value = '';
        } else {
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">${data.message || 'Upload failed'}</span>`;
            statusDiv.className = 'mt-2 text-danger';
        }
    })
    .catch(error => {
        // CRITICAL #5: Handle errors gracefully - return object, don't throw
        if (statusDiv) {
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Upload failed: ${error.message || 'Unknown error'}</span>`;
            statusDiv.className = 'mt-2 text-danger';
        }
    });
}

function generatePhdAwardeeFields() {
    // Prevent infinite loops
    if (isGeneratingFields) {
        return;
    }
    isGeneratingFields = true;
    
    try {
        // Save current form data before regenerating fields
        const currentData = saveCurrentFormData();
    
    // Get the counts for each category - ensure elements exist first
    const maleFTInput = document.querySelector('input[name="Male_Students_AWD_FT"]');
    const femaleFTInput = document.querySelector('input[name="Female_Students_AWD_FT"]');
    const otherFTInput = document.querySelector('input[name="Other_Students_AWD_FT"]');
    const malePTInput = document.querySelector('input[name="Male_Students_AWD_PT"]');
    const femalePTInput = document.querySelector('input[name="Female_Students_AWD_PT"]');
    const otherPTInput = document.querySelector('input[name="Other_Students_AWD_PT"]');
    
    const maleFTCount = maleFTInput ? (parseInt(maleFTInput.value) || 0) : 0;
    const femaleFTCount = femaleFTInput ? (parseInt(femaleFTInput.value) || 0) : 0;
    const otherFTCount = otherFTInput ? (parseInt(otherFTInput.value) || 0) : 0;
    const malePTCount = malePTInput ? (parseInt(malePTInput.value) || 0) : 0;
    const femalePTCount = femalePTInput ? (parseInt(femalePTInput.value) || 0) : 0;
    const otherPTCount = otherPTInput ? (parseInt(otherPTInput.value) || 0) : 0;
    
    // Get containers for each category
    const maleFTContainer = document.getElementById('male_ft_awardees_container');
    const femaleFTContainer = document.getElementById('female_ft_awardees_container');
    const otherFTContainer = document.getElementById('other_ft_awardees_container');
    const malePTContainer = document.getElementById('male_pt_awardees_container');
    const femalePTContainer = document.getElementById('female_pt_awardees_container');
    const otherPTContainer = document.getElementById('other_pt_awardees_container');
    
    // Clear all containers
    [maleFTContainer, femaleFTContainer, otherFTContainer, malePTContainer, femalePTContainer, otherPTContainer].forEach(container => {
        if (container) container.innerHTML = '';
    });
    
    // Check if form is readonly by looking at any input field (with null check)
    const readonlyCheckInput = document.querySelector('input[name="Male_Students_AWD_FT"]');
    const isReadonly = readonlyCheckInput ? readonlyCheckInput.hasAttribute('readonly') : false;
    const readonlyAttr = isReadonly ? 'readonly disabled' : '';
    
    
    // Generate fields for Male Full Time PhD Awardees
    if (maleFTCount > 0 && maleFTContainer) {
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'p-3 border rounded bg-light';
        sectionDiv.innerHTML = `<h6 class="text-primary mb-3">Male PhD Awardees (Full Time) - ${maleFTCount} students</h6>`;
        
        for (let i = 1; i <= maleFTCount; i++) {
            const fieldDiv = document.createElement('div');
            fieldDiv.className = 'row mb-2';
            fieldDiv.innerHTML = `
                <div class="col-md-6">
                    <label class="form-label">Name ${i}</label>
                    <input type="text" name="phd_awardee_names[]" class="form-control" placeholder="Enter full name" ${readonlyAttr} required>
                    <input type="hidden" name="phd_awardee_categories[]" value="Male_FT">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date Awarded ${i}</label>
                    <input type="date" name="phd_awardee_dates[]" class="form-control" ${readonlyAttr} required>
                </div>
            `;
            sectionDiv.appendChild(fieldDiv);
        }
        maleFTContainer.appendChild(sectionDiv);
    }
    
    // Generate fields for Female Full Time PhD Awardees
    if (femaleFTCount > 0 && femaleFTContainer) {
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'p-3 border rounded bg-light';
        sectionDiv.innerHTML = `<h6 class="text-primary mb-3">Female PhD Awardees (Full Time) - ${femaleFTCount} students</h6>`;
        
        for (let i = 1; i <= femaleFTCount; i++) {
            const fieldDiv = document.createElement('div');
            fieldDiv.className = 'row mb-2';
            fieldDiv.innerHTML = `
                <div class="col-md-6">
                    <label class="form-label">Name ${i}</label>
                    <input type="text" name="phd_awardee_names[]" class="form-control" placeholder="Enter full name" ${readonlyAttr} required>
                    <input type="hidden" name="phd_awardee_categories[]" value="Female_FT">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date Awarded ${i}</label>
                    <input type="date" name="phd_awardee_dates[]" class="form-control" ${readonlyAttr} required>
                </div>
            `;
            sectionDiv.appendChild(fieldDiv);
        }
        femaleFTContainer.appendChild(sectionDiv);
    }
    
    // Generate fields for Other Full Time PhD Awardees
    if (otherFTCount > 0 && otherFTContainer) {
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'p-3 border rounded bg-light';
        sectionDiv.innerHTML = `<h6 class="text-primary mb-3">Other PhD Awardees (Full Time) - ${otherFTCount} students</h6>`;
        
        for (let i = 1; i <= otherFTCount; i++) {
            const fieldDiv = document.createElement('div');
            fieldDiv.className = 'row mb-2';
            fieldDiv.innerHTML = `
                <div class="col-md-6">
                    <label class="form-label">Name ${i}</label>
                    <input type="text" name="phd_awardee_names[]" class="form-control" placeholder="Enter full name" ${readonlyAttr} required>
                    <input type="hidden" name="phd_awardee_categories[]" value="Other_FT">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date Awarded ${i}</label>
                    <input type="date" name="phd_awardee_dates[]" class="form-control" ${readonlyAttr} required>
                </div>
            `;
            sectionDiv.appendChild(fieldDiv);
        }
        otherFTContainer.appendChild(sectionDiv);
    }
    
    // Generate fields for Male Part Time PhD Awardees
    if (malePTCount > 0 && malePTContainer) {
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'p-3 border rounded bg-light';
        sectionDiv.innerHTML = `<h6 class="text-primary mb-3">Male PhD Awardees (Part Time) - ${malePTCount} students</h6>`;
        
        for (let i = 1; i <= malePTCount; i++) {
            const fieldDiv = document.createElement('div');
            fieldDiv.className = 'row mb-2';
            fieldDiv.innerHTML = `
                <div class="col-md-6">
                    <label class="form-label">Name ${i}</label>
                    <input type="text" name="phd_awardee_names[]" class="form-control" placeholder="Enter full name" ${readonlyAttr} required>
                    <input type="hidden" name="phd_awardee_categories[]" value="Male_PT">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date Awarded ${i}</label>
                    <input type="date" name="phd_awardee_dates[]" class="form-control" ${readonlyAttr} required>
                </div>
            `;
            sectionDiv.appendChild(fieldDiv);
        }
        malePTContainer.appendChild(sectionDiv);
    }
    
    // Generate fields for Female Part Time PhD Awardees
    if (femalePTCount > 0 && femalePTContainer) {
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'p-3 border rounded bg-light';
        sectionDiv.innerHTML = `<h6 class="text-primary mb-3">Female PhD Awardees (Part Time) - ${femalePTCount} students</h6>`;
        
        for (let i = 1; i <= femalePTCount; i++) {
            const fieldDiv = document.createElement('div');
            fieldDiv.className = 'row mb-2';
            fieldDiv.innerHTML = `
                <div class="col-md-6">
                    <label class="form-label">Name ${i}</label>
                    <input type="text" name="phd_awardee_names[]" class="form-control" placeholder="Enter full name" ${readonlyAttr} required>
                    <input type="hidden" name="phd_awardee_categories[]" value="Female_PT">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date Awarded ${i}</label>
                    <input type="date" name="phd_awardee_dates[]" class="form-control" ${readonlyAttr} required>
                </div>
            `;
            sectionDiv.appendChild(fieldDiv);
        }
        femalePTContainer.appendChild(sectionDiv);
    }
    
    // Generate fields for Other Part Time PhD Awardees
    if (otherPTCount > 0 && otherPTContainer) {
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'p-3 border rounded bg-light';
        sectionDiv.innerHTML = `<h6 class="text-primary mb-3">Other PhD Awardees (Part Time) - ${otherPTCount} students</h6>`;
        
        for (let i = 1; i <= otherPTCount; i++) {
            const fieldDiv = document.createElement('div');
            fieldDiv.className = 'row mb-2';
            fieldDiv.innerHTML = `
                <div class="col-md-6">
                    <label class="form-label">Name ${i}</label>
                    <input type="text" name="phd_awardee_names[]" class="form-control" placeholder="Enter full name" ${readonlyAttr} required>
                    <input type="hidden" name="phd_awardee_categories[]" value="Other_PT">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date Awarded ${i}</label>
                    <input type="date" name="phd_awardee_dates[]" class="form-control" ${readonlyAttr} required>
                </div>
            `;
            sectionDiv.appendChild(fieldDiv);
        }
        otherPTContainer.appendChild(sectionDiv);
    }
    
    // Calculate and update totals
    calculateTotals();
    
    // Restore data after generating all fields
    setTimeout(() => {
        // First try to restore current data, then fall back to existing data
        const hasCurrentData = Object.values(currentData).some(arr => arr.length > 0);
        
        if (hasCurrentData) {
            restoreFormData(currentData);
        } else if (existingAwardeeData && Array.isArray(existingAwardeeData)) {
            populateAwardeeFields();
        } else {
        }
        // Reset flag after restoration is complete
        isGeneratingFields = false;
    }, 150); // Increased timeout to ensure fields are fully generated
    } catch (error) {
        isGeneratingFields = false;
    }
}

// Calculate totals function
function calculateTotals() {
    // Full Time totals
    const maleFT = parseInt(document.querySelector('input[name="Male_Students_FT"]').value) || 0;
    const femaleFT = parseInt(document.querySelector('input[name="Female_Students_FT"]').value) || 0;
    const otherFT = parseInt(document.querySelector('input[name="Other_Students_FT"]').value) || 0;
    const totalFT = maleFT + femaleFT + otherFT;
    document.getElementById('total_ft').textContent = totalFT;
    
    // Part Time totals
    const malePT = parseInt(document.querySelector('input[name="Male_Students_PT"]').value) || 0;
    const femalePT = parseInt(document.querySelector('input[name="Female_Students_PT"]').value) || 0;
    const otherPT = parseInt(document.querySelector('input[name="Other_Students_PT"]').value) || 0;
    const totalPT = malePT + femalePT + otherPT;
    document.getElementById('total_pt').textContent = totalPT;
    
    // PhD Awarded Full Time totals
    const maleAwdFT = parseInt(document.querySelector('input[name="Male_Students_AWD_FT"]').value) || 0;
    const femaleAwdFT = parseInt(document.querySelector('input[name="Female_Students_AWD_FT"]').value) || 0;
    const otherAwdFT = parseInt(document.querySelector('input[name="Other_Students_AWD_FT"]').value) || 0;
    const totalAwdFT = maleAwdFT + femaleAwdFT + otherAwdFT;
    document.getElementById('total_awd_ft').textContent = totalAwdFT;
    
    // PhD Awarded Part Time totals
    const maleAwdPT = parseInt(document.querySelector('input[name="Male_Students_AWD_PT"]').value) || 0;
    const femaleAwdPT = parseInt(document.querySelector('input[name="Female_Students_AWD_PT"]').value) || 0;
    const otherAwdPT = parseInt(document.querySelector('input[name="Other_Students_AWD_PT"]').value) || 0;
    const totalAwdPT = maleAwdPT + femaleAwdPT + otherAwdPT;
    document.getElementById('total_awd_pt').textContent = totalAwdPT;
}

// Flag and debounce function already defined at top of script

// REWRITTEN: Match PlacementDetails.php exactly - wire on DOMContentLoaded
(function wirePhdFormSubmission(){
    function attachHandlers() {
        const form = document.getElementById('phdForm');
        const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
        
        if (!form) {
            return false;
        }
        
        if (!submitBtn) {
            return false;
        }
        
        
        // Handle form submit event - validate here (matches PlacementDetails.php pattern)
        form.addEventListener('submit', function(e){
            
            // Remove readonly and disabled attributes from ALL form inputs
            const allInputs = form.querySelectorAll('input, select, textarea');
            let removedCount = 0;
            allInputs.forEach(input => {
                if (input.hasAttribute('readonly')) {
                    input.removeAttribute('readonly');
                    removedCount++;
                }
                if (input.hasAttribute('disabled') || input.disabled) {
                    input.disabled = false;
                    input.removeAttribute('disabled');
                    removedCount++;
                }
            });
            
            // REMOVED: Validation that prevented zero values
            // Departments without PhD students should be able to submit all zeros
            // Basic validation: Just ensure fields are filled (0 is valid)
            const studentFields = [
                'Male_Students_FT', 'Female_Students_FT', 'Other_Students_FT',
                'Male_Students_PT', 'Female_Students_PT', 'Other_Students_PT'
            ];
            
            let allFieldsEmpty = true;
            for (let fieldName of studentFields) {
                const field = form.querySelector(`input[name="${fieldName}"]`);
                if (field && field.value !== '' && field.value !== null && field.value !== undefined) {
                    allFieldsEmpty = false;
                    break;
                }
            }
            
            // Only prevent submission if ALL fields are completely empty (not filled with any value including 0)
            if (allFieldsEmpty) {
                e.preventDefault();
                showSmoothMessage('Please fill in the student count fields (you can enter 0 if you have no students).', 'error');
                return false;
            }
            
            // Ensure awardee fields are generated if awardee counts > 0 (quick generation)
            clearTimeout(generateFieldsTimeout);
            const awardeeCountFields = [
                'Male_Students_AWD_FT', 'Female_Students_AWD_FT', 'Other_Students_AWD_FT',
                'Male_Students_AWD_PT', 'Female_Students_AWD_PT', 'Other_Students_AWD_PT'
            ];
            
            let totalAwardees = 0;
            for (let fieldName of awardeeCountFields) {
                const field = form.querySelector(`input[name="${fieldName}"]`);
                if (field) {
                    totalAwardees += parseInt(field.value || 0);
                }
            }
            
            const existingAwardeeInputs = form.querySelectorAll('input[name="phd_awardee_names[]"]').length;
            
            // Generate awardee fields if needed (synchronous if possible)
            if (totalAwardees > 0 && existingAwardeeInputs === 0) {
                const wasGenerating = isGeneratingFields;
                isGeneratingFields = false;
                try {
                    generatePhdAwardeeFields();
                    // Small delay to allow DOM update
                    const start = Date.now();
                    while (Date.now() - start < 100) {} // Wait 100ms
                } catch (e) {
                }
                isGeneratingFields = wasGenerating;
            }
            
            // CRITICAL FIX: Validate that awardee arrays have matching lengths before submission
            if (totalAwardees > 0) {
                const nameInputs = form.querySelectorAll('input[name="phd_awardee_names[]"]');
                const dateInputs = form.querySelectorAll('input[name="phd_awardee_dates[]"]');
                const categoryInputs = form.querySelectorAll('input[name="phd_awardee_categories[]"]');
                
                const namesCount = nameInputs.length;
                const datesCount = dateInputs.length;
                const categoriesCount = categoryInputs.length;
                
                
                if (namesCount !== datesCount || namesCount !== categoriesCount) {
                    e.preventDefault();
                    const errorMsg = 'ERROR: Awardee data arrays are mismatched! Names: ' + namesCount + ', Dates: ' + datesCount + ', Categories: ' + categoriesCount + '. Please refresh the page and try again.';
                    showSmoothMessage(errorMsg, 'error');
                    return false;
                }
                
                // Validate that expected count matches actual input count
                if (namesCount !== totalAwardees) {
                    // Don't prevent submission, but log warning
                }
                
                // Check for missing categories (especially Other categories)
                let missingCategories = [];
                categoryInputs.forEach((catInput, idx) => {
                    const catValue = catInput.value.trim();
                    const validCategories = ['Male_FT', 'Female_FT', 'Other_FT', 'Male_PT', 'Female_PT', 'Other_PT'];
                    if (!validCategories.includes(catValue)) {
                        missingCategories.push(`Input ${idx + 1}: "${catValue}"`);
                    }
                });
                
                if (missingCategories.length > 0) {
                    e.preventDefault();
                    showSmoothMessage('ERROR: Some awardee categories are missing or invalid. Please check all fields and try again.', 'error');
                    return false;
                }
            }
            
            
            // Change button text and disable after delay (matches PlacementDetails.php)
            const originalHtml = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
            
            setTimeout(function() {
                submitBtn.disabled = true;
            }, 50);
            
            // Don't prevent default - allow form to submit naturally
        });
        
        return true;
    }
    
    // Try to attach immediately, or wait for DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachHandlers);
    } else {
        attachHandlers();
    }
})();

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
    
    // Hide Save Changes button, show Update button
    const updateBtn = document.getElementById('updateBtn');
    const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
    if (updateBtn) updateBtn.style.display = 'none';
    if (updateTriggerBtn) updateTriggerBtn.style.display = 'inline-block';
}

function enableUpdate() {
    // Save current form data before regenerating
    const currentData = saveCurrentFormData();
    
    // Enable all form inputs (including file inputs)
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        input.removeAttribute('readonly');
        input.disabled = false;
        input.style.pointerEvents = 'auto';
        input.style.cursor = (input.type === 'file') ? 'pointer' : 'text';
        input.style.backgroundColor = '#fff';
    });
    
    // Enable all file inputs explicitly
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(input => {
        input.disabled = false;
        input.style.cursor = 'pointer';
        input.style.backgroundColor = '#fff';
    });
    
    // Enable all upload buttons
    const uploadButtons = document.querySelectorAll('button[onclick*="uploadDocument"]');
    uploadButtons.forEach(btn => {
        btn.disabled = false;
        btn.style.cursor = 'pointer';
        btn.style.opacity = '1';
    });
    
    // CRITICAL FIX: Update document status displays to show delete buttons WITHOUT reloading from database
    // This preserves any newly uploaded documents that haven't been saved to main table yet
    updateDocumentActionButtons();
    
    // Show Save Changes button, hide Update button
    const updateBtn = document.getElementById('updateBtn');
    const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
    if (updateBtn) updateBtn.style.display = 'inline-block';
    if (updateTriggerBtn) updateTriggerBtn.style.display = 'none';
    
    // Regenerate fields to make them editable (use debounced to prevent loops)
    clearTimeout(generateFieldsTimeout);
    generatePhdAwardeeFields();
    
    // Restore data after regenerating fields
    setTimeout(() => {
        // First try to restore current data, then fall back to existing data
        if (Object.values(currentData).some(arr => arr.length > 0)) {
            // Restore current data
            restoreFormData(currentData);
        } else {
            // Fall back to existing data
            populateAwardeeFields();
        }
    }, 100);
    
    showSmoothMessage('Form is now editable. Make your changes and click "Save Changes" to update.', 'info');
}

function confirmClearData() {
    if (confirm('Are you sure you want to clear all data? This action cannot be undone!')) { // Keep confirm for destructive action
        window.location.href = '?action=clear_data';
        return true;
    }
    return false;
}

// Global variable to store existing awardee data
let existingAwardeeData = null;

// Function to save current form data before regenerating fields
function saveCurrentFormData() {
    const currentData = {
        'Male_FT': [],
        'Female_FT': [],
        'Other_FT': [],
        'Male_PT': [],
        'Female_PT': [],
        'Other_PT': []
    };
    
    // Get all current awardee data from the form
    const nameInputs = document.querySelectorAll('input[name="phd_awardee_names[]"]');
    const dateInputs = document.querySelectorAll('input[name="phd_awardee_dates[]"]');
    const categoryInputs = document.querySelectorAll('input[name="phd_awardee_categories[]"]');
    
    
    nameInputs.forEach((nameInput, index) => {
        const nameValue = nameInput.value.trim();
        const dateValue = dateInputs[index] ? dateInputs[index].value : '';
        const category = categoryInputs[index] ? categoryInputs[index].value : '';
        
        
        // Save data even if only name is provided (date can be added later)
        if (nameValue || dateValue) {
            if (currentData[category]) {
                currentData[category].push({
                    name: nameValue,
                    date: dateValue,
                    category: category
                });
            }
        }
    });
    
    return currentData;
}

// Function to load existing awardee data
function loadExistingAwardeeData() {
    <?php if ($existing_data && !empty($existing_data['PHD_AWARDEES_DETAILS'])): ?>
    const existingData = <?php echo json_encode($existing_data); ?>;
    if (existingData.PHD_AWARDEES_DETAILS) {
        try {
            existingAwardeeData = JSON.parse(existingData.PHD_AWARDEES_DETAILS);
        } catch (e) {
            existingAwardeeData = null;
        }
    }
    <?php endif; ?>
}

// Function to populate awardee fields with existing data
function populateAwardeeFields() {
    if (!existingAwardeeData || !Array.isArray(existingAwardeeData)) {
        return;
    }
    
    // Group awardees by category - FIXED: Include all 6 categories including Other_FT and Other_PT
    const groupedAwardees = {
        'Male_FT': [],
        'Female_FT': [],
        'Other_FT': [],
        'Male_PT': [],
        'Female_PT': [],
        'Other_PT': []
    };
    
    existingAwardeeData.forEach(awardee => {
        if (groupedAwardees[awardee.category]) {
            groupedAwardees[awardee.category].push(awardee);
        } else {
            // Log if unknown category is found
        }
    });
    
    // Populate fields for each category
    Object.keys(groupedAwardees).forEach(category => {
        const awardees = groupedAwardees[category];
        // FIXED: Correct container ID mapping - convert "Other_FT" -> "other_ft_awardees_container"
        const containerId = category.toLowerCase() + '_awardees_container';
        const container = document.getElementById(containerId);
        
        if (container && awardees.length > 0) {
            const nameInputs = container.querySelectorAll('input[name="phd_awardee_names[]"]');
            const dateInputs = container.querySelectorAll('input[name="phd_awardee_dates[]"]');
            
            
            awardees.forEach((awardee, index) => {
                if (nameInputs[index]) {
                    nameInputs[index].value = awardee.name || '';
                }
                if (dateInputs[index]) {
                    dateInputs[index].value = awardee.date || '';
                }
            });
        } else if (awardees.length > 0) {
        }
    });
}

// Function to restore form data from saved data
function restoreFormData(savedData) {
    
    Object.keys(savedData).forEach(category => {
        const awardees = savedData[category];
        // FIXED: Correct container ID mapping - convert "Other_FT" -> "other_ft_awardees_container"
        const containerId = category.toLowerCase() + '_awardees_container';
        const container = document.getElementById(containerId);
        
        
        if (container && awardees.length > 0) {
            const nameInputs = container.querySelectorAll('input[name="phd_awardee_names[]"]');
            const dateInputs = container.querySelectorAll('input[name="phd_awardee_dates[]"]');
            
            
            if (nameInputs.length !== awardees.length) {
            }
            
            awardees.forEach((awardee, index) => {
                if (nameInputs[index]) {
                    nameInputs[index].value = awardee.name || '';
                } else {
                }
                if (dateInputs[index]) {
                    dateInputs[index].value = awardee.date || '';
                } else {
                }
            });
        } else if (awardees.length > 0) {
        }
    });
}

// CRITICAL: Prevent duplicate page initialization
let pageInitialized = false;

// Initialize page - SINGLE initialization point
function initializePage() {
    if (pageInitialized) {
        return; // Already initialized - prevent duplicate calls
    }
    pageInitialized = true;
    
    // Load existing awardee data first
    loadExistingAwardeeData();
    
    // Generate fields based on existing data
    setTimeout(() => {
        generatePhdAwardeeFields();
        
        // Populate fields with existing data after generation
        setTimeout(() => {
            populateAwardeeFields();
        }, 300);
    }, 100);
    
    // Lock form if it should be locked
    <?php if ($form_locked): ?>
    setTimeout(function() {
        disableForm();
    }, 500);
    <?php endif; ?>
    
    // CRITICAL FIX: Load existing documents AFTER form lock state is set
    // This ensures document status is checked with correct form state
    setTimeout(function() {
        loadExistingDocuments();
    }, <?php echo $form_locked ? '600' : '200'; ?>);
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

// getCSRFToken already defined at top of script

// PDF validation function
function validatePDF(input) {
    const file = input.files[0];
    if (file) {
        const fileSize = file.size / 1024 / 1024; // Convert to MB
        const fileType = file.type;
        
        if (fileType !== 'application/pdf') {
            showSmoothMessage('Please select a valid PDF file.', 'error');
            input.value = '';
            return false;
        }
        
        if (fileSize > 10) {
            showSmoothMessage('File size must be less than 10MB.', 'error');
            input.value = '';
            return false;
        }
    }
    return true;
}

// Delete document function
function deleteDocument(fileId, srno) {
    // Check form lock state dynamically (not from PHP variable at page load)
    var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
    var isFormLocked = updateButton && updateButton.style.display !== 'none';
    
    // Also check if any input is disabled
    var testInput = document.querySelector('input[name="Male_Students_AWD_FT"]');
    if (testInput && (testInput.disabled || testInput.hasAttribute('readonly'))) {
        isFormLocked = true;
    }
    
    if (isFormLocked) {
        showSmoothMessage('Cannot delete document. Form is locked after submission. To delete documents, please use the Update button to unlock the form first.', 'warning');
        return;
    }
    
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }
    
    const statusDiv = document.getElementById(fileId + '_status');
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
    statusDiv.className = 'mt-2 text-info';
    
    // Get CSRF token
    const csrfToken = getCSRFToken();
    if (!csrfToken) {
        showSmoothMessage('Security token not found. Please refresh the page and try again.', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('delete_document', '1');
    formData.append('file_id', fileId);
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
        return { ok: false, text: () => Promise.resolve(JSON.stringify({ success: false, message: 'Network error: ' + (error.message || 'Failed to connect to server') })) };
    })
    .then(response => {
        // CRITICAL: Ensure response is valid before processing
        if (!response || typeof response.text !== 'function') {
            return { success: false, message: 'Delete failed: Invalid server response' };
        }
        
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
        }).catch(textError => {
            // CRITICAL: Handle text() parsing errors
            return { success: false, message: 'Delete failed: Error reading server response' };
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
        
        // Return resolved promise to prevent unhandled promise rejection
        return Promise.resolve({ success: false, message: 'Delete failed: ' + (error.message || 'Unknown error') });
    });
}

// Check document status function
function checkDocumentStatus(fileId, srno) {
    console.log(`[PhD] Checking document status for fileId=${fileId}, srno=${srno}`);
    const statusDiv = document.getElementById(fileId + '_status');
    
    if (!statusDiv) {
        console.error(`[PhD] Status div not found for fileId=${fileId}`);
        return;
    }
    
    // CRITICAL: Add request timeout with AbortController
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
    executeWithRateLimit(() => {
        return fetch(`?check_doc=1&srno=${srno}`, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            cache: 'no-cache',
            signal: controller.signal
        })
        .then(response => {
            clearTimeout(timeoutId);
            if (!response.ok) {
                return { success: false, message: 'HTTP error: ' + response.status };
            }
            return response;
        })
        .catch(error => {
            clearTimeout(timeoutId);
            // CRITICAL: Handle fetch errors (network, CORS, timeout, etc.) - return error object
            if (error.name === 'AbortError') {
                return { 
                    ok: false, 
                    text: () => Promise.resolve(JSON.stringify({ 
                        success: false, 
                        message: 'Request timeout' 
                    })) 
                };
            }
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
            console.error('[PhD] Invalid response object:', response);
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
                console.error('[PhD] Error reading response text:', textError);
                return { success: false, message: 'Check failed: Error reading server response' };
            });
        });
    })
    .then(data => {
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            console.warn(`[PhD] Invalid data received for fileId=${fileId}`);
            return;
        }
        
        console.log(`[PhD] Document check result for fileId=${fileId}:`, data);
        
        if (data.success && data.file_path) {
            console.log(`[PhD] Document found for fileId=${fileId}, path=${data.file_path}`);
            
            // Check if form is locked by checking any input field's disabled state
            const testInput = document.querySelector('input[name="Male_Students_AWD_FT"]');
            const isFormLocked = testInput ? (testInput.disabled || testInput.hasAttribute('readonly')) : false;
            
            console.log(`[PhD] Form lock state for fileId=${fileId}: ${isFormLocked ? 'LOCKED' : 'UNLOCKED'}`);
            
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId2 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId2}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            // Ensure correct path format for view link
            // Expected structure: uploads/{YEAR}/DEPARTMENT/{dept_id}/phd/{document_type}/{filename}
            let viewPath2 = data.file_path || '';
            
            // Normalize path separators
            viewPath2 = viewPath2.replace(/\\/g, '/');
            
            // If path doesn't start with '../' and starts with 'uploads/', prepend '../'
            if (viewPath2 && !viewPath2.startsWith('../') && viewPath2.startsWith('uploads/')) {
                viewPath2 = '../' + viewPath2;
            }
            // If path is absolute, try to convert to relative
            if (viewPath2 && (viewPath2.startsWith('/home/') || viewPath2.startsWith('C:/') || viewPath2.startsWith('C:\\'))) {
                // Extract relative path - match new structure: uploads/{YEAR}/DEPARTMENT/{dept_id}/phd/{type}/{file}
                const match = viewPath2.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/phd\/[^\/]+\/.+\.pdf)$/);
                if (match) {
                    viewPath2 = '../' + match[1];
                } else {
                    // Fallback: try old structure
                    const matchOld = viewPath2.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/);
                    if (matchOld) {
                        viewPath2 = '../' + matchOld[1];
                    }
                }
            }
            
            // Keep A_YEAR format (e.g., 2024-2025) for consistency - no conversion needed
            
            // Ensure path is properly formatted for web access
            if (viewPath2 && !viewPath2.startsWith('../') && !viewPath2.startsWith('http') && !viewPath2.startsWith('/')) {
                viewPath2 = '../' + viewPath2.replace(/^\.\.\//, '');
            }
            
            // CRITICAL: Escape viewPath2 for HTML attribute to prevent XSS and syntax errors
            const escapedViewPath2 = viewPath2 ? String(viewPath2).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success">Document uploaded</span>
                    ${deleteButton}
                    <a href="${escapedViewPath2}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                        <i class="fas fa-eye"></i> View
                    </a>
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
        } else {
            console.log(`[PhD] No document found for fileId=${fileId}, message=${data.message || 'N/A'}`);
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
        }
    })
    .catch(error => {
        console.error(`[PhD] Error checking document status for fileId=${fileId}:`, error);
        statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
        statusDiv.className = 'mt-2 text-muted';
    });
}

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

// CRITICAL PERFORMANCE FIX: Load ALL documents in ONE batch request instead of 3 individual requests
// This prevents unlimited database requests and server crashes
let documentsLoading = false;
let documentsLoadAttempted = false;

// Load existing documents on page load
function loadExistingDocuments() {
    // CRITICAL: Guard against duplicate calls
    if (documentsLoading || documentsLoadAttempted) {
        return; 
    }
    documentsLoading = true;
    documentsLoadAttempted = true;
    
    console.log('[PhD] Loading existing documents...');
    
    const documentTypes = [
        { fileId: 'phd_awardees_pdf', srno: 7 },
        { fileId: 'fulltime_intake_proof', srno: 8 },
        { fileId: 'parttime_intake_proof', srno: 9 }
    ];
    
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
            // Update all document statuses from batch response
            // data.documents is an object keyed by serial_number (srno)
            documentTypes.forEach(doc => {
                const docData = data.documents[doc.srno];
                if (docData && docData.success) {
                    const statusDiv = document.getElementById(doc.fileId + '_status');
                    if (statusDiv) {
                        updateDocumentStatusUI(doc.fileId, doc.srno, docData.file_path, docData.file_name);
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
    const isFormLocked = fileInput ? fileInput.disabled : false;
    
    let actionButtons = '';
    if (isFormLocked) {
        // Form is locked, only show view button
        actionButtons = '';
    } else {
        // Form is unlocked, show delete and view buttons
        // CRITICAL: Escape fileId to prevent JavaScript syntax errors
        const escapedFileId3 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
        actionButtons = `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId3}', ${srno})">
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
    const escapedViewPath3 = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
    const viewButton = escapedViewPath3 ? `<a href="${escapedViewPath3}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
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
    console.log('[PhD] Refreshing all document statuses...');
    
    // Clear existing status divs first
    const statusDivs = document.querySelectorAll('[id$="_status"]');
    statusDivs.forEach(div => {
        div.innerHTML = '<span class="text-muted">Checking...</span>';
        div.className = 'mt-2 text-muted';
    });
    
    // CRITICAL FIX: Add small delay before reloading to ensure form state is stable
    setTimeout(() => {
        console.log('[PhD] Loading existing documents after refresh...');
        loadExistingDocuments();
    }, 100);
}

// Update document action buttons (add delete buttons) WITHOUT reloading from database
// This preserves newly uploaded documents that haven't been saved yet
function updateDocumentActionButtons() {
    console.log('[PhD] Updating document action buttons (no database reload)...');
    
    const documentTypes = [
        { fileId: 'phd_awardees_pdf', srno: 7 },
        { fileId: 'fulltime_intake_proof', srno: 8 },
        { fileId: 'parttime_intake_proof', srno: 9 }
    ];
    
    documentTypes.forEach(doc => {
        const statusDiv = document.getElementById(doc.fileId + '_status');
        if (!statusDiv) return;
        
        // Check if there's already a document shown (success status)
        const hasDocument = statusDiv.classList.contains('text-success') && statusDiv.innerHTML.includes('Document uploaded');
        
        if (hasDocument) {
            console.log(`[PhD] Document found in UI for ${doc.fileId}, adding delete button...`);
            
            // Extract the current view link href
            const existingViewLink = statusDiv.querySelector('a[href]');
            const viewHref = existingViewLink ? existingViewLink.getAttribute('href') : '#';
            
            // Check if delete button already exists
            const hasDeleteButton = statusDiv.innerHTML.includes('deleteDocument');
            
            if (!hasDeleteButton) {
                // Add delete button to existing display
                statusDiv.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        <span class="text-success">Document uploaded</span>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${String(doc.fileId).replace(/'/g, "\\'").replace(/"/g, '\\"')}', ${doc.srno})">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                        <a href="${String(viewHref).replace(/"/g, '&quot;').replace(/'/g, '&#39;')}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                `;
                console.log(`[PhD] Delete button added for ${doc.fileId}`);
            } else {
                console.log(`[PhD] Delete button already exists for ${doc.fileId}`);
            }
        } else {
            console.log(`[PhD] No document in UI for ${doc.fileId}, skipping...`);
        }
    });
}

// Smooth message function (like other pages)
function showSmoothMessage(message, type = 'info') {
    const messageDiv = document.getElementById('soft-message');
    if (!messageDiv) {
        return;
    }
    
    const colors = { 
        success: '#16a34a', 
        error: '#dc2626', 
        warning: '#ffc107',
        info: '#2563eb' 
    };
    const icons = {
        success: 'check-circle',
        error: 'exclamation-circle',
        warning: 'exclamation-triangle',
        info: 'info-circle'
    };
    
    messageDiv.style.background = colors[type] || colors.info;
    messageDiv.innerHTML = `
        <div style="display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-${icons[type] || icons.info}"></i>
            <span>${message}</span>
        </div>
    `;
    messageDiv.style.display = 'flex';
    messageDiv.style.transform = 'translateX(100%)';
    
    // Animate in
    setTimeout(() => {
        messageDiv.style.transition = 'transform 0.3s ease';
        messageDiv.style.transform = 'translateX(0)';
    }, 100);
    
    // Auto-hide after 3 seconds
    setTimeout(() => {
        messageDiv.style.transform = 'translateX(100%)';
        setTimeout(() => {
            messageDiv.style.display = 'none';
        }, 300);
    }, 3000);
}

// Add event listeners to all count inputs
document.addEventListener('DOMContentLoaded', function() {
    const countInputs = [
        'Male_Students_FT', 'Female_Students_FT', 'Other_Students_FT',
        'Male_Students_PT', 'Female_Students_PT', 'Other_Students_PT',
        'Male_Students_AWD_FT', 'Female_Students_AWD_FT', 'Other_Students_AWD_FT',
        'Male_Students_AWD_PT', 'Female_Students_AWD_PT', 'Other_Students_AWD_PT'
    ];
    
    countInputs.forEach(inputName => {
        const input = document.querySelector(`input[name="${inputName}"]`);
        if (input) {
            input.addEventListener('input', function() {
                calculateTotals();
                // Awardee fields will be generated via oninput debounced handler
            });
        }
    });
    
    // Calculate initial totals
    calculateTotals();
});
</script>

<?php
require "unified_footer.php";
?>
