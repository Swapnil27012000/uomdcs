<?php
// Programmes_Offered.php - Programmes Management Form
require('session.php');

// Load CSRF utilities
if (file_exists(__DIR__ . '/csrf.php')) {
    require_once __DIR__ . '/csrf.php';
}

// CRITICAL FIX: Resolve academic year using centralized getAcademicYear() function
function resolveAcademicYear() {
    // Prefer explicit academic year from session/header if provided
    if (!empty($_SESSION['A_YEAR'])) {
        return (string)$_SESSION['A_YEAR'];
    }
    if (!empty($GLOBALS['userInfo']['A_YEAR'])) {
        return (string)$GLOBALS['userInfo']['A_YEAR'];
    }
    
    // CRITICAL FIX: Use centralized getAcademicYear() function
    if (!function_exists('getAcademicYear')) {
        if (file_exists(__DIR__ . '/common_functions.php')) {
            require_once(__DIR__ . '/common_functions.php');
        }
    }
    if (function_exists('getAcademicYear')) {
        return getAcademicYear();
    }
    
    // Fallback: compute based on current date (CORRECTED: July as rollover, not June)
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    // July onwards (month >= 7): current_year to current_year+1
    // Below July (month < 7): (current_year-2) to (current_year-1)
    if ($current_month >= 7) {
        return $current_year . '-' . ($current_year + 1);
    } else {
        return ($current_year - 2) . '-' . ($current_year - 1);
    }
}

// Generate a unique serial number for supporting_documents, avoiding collisions
function generateUniqueSerial($conn) {
    // Try up to 10 times to avoid pathological collisions
    for ($i = 0; $i < 10; $i++) {
        $serial = random_int(1, 2000000000); // within signed 32-bit range
        $check = mysqli_prepare($conn, "SELECT 1 FROM supporting_documents WHERE serial_number = ? LIMIT 1");
        if ($check) {
            mysqli_stmt_bind_param($check, "i", $serial);
            mysqli_stmt_execute($check);
            $res = mysqli_stmt_get_result($check);
            if (!$res || mysqli_num_rows($res) === 0) {
                return $serial;
            }
        }
    }
    // Fallback: time-based with randomness (still check not done here)
    return (int) ((time() % 2000000000));
}

// ============================================================================
// ALL AJAX HANDLERS - MUST BE BEFORE unified_header.php
// ============================================================================

// Handle document upload for programmes - BEFORE unified_header
// CRITICAL: Follow all checklist items to prevent crashes and ensure proper JSON responses
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_programme_document'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config silently
    ob_start();
    if (!isset($conn) || !$conn) {
        require_once('../config.php');
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
        require_once __DIR__ . '/csrf.php';
        // Ensure CSRF token is generated in session before validation
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if it doesn't exist
        }
    }
    ob_end_clean();
    
    // Clear buffers again
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
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
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
        $programme_code = trim($_POST['programme_code'] ?? '');
        $programme_name = trim($_POST['programme_name'] ?? '');
        
        // Validate required fields
        if (empty($programme_code) || empty($programme_name)) {
            throw new Exception('Programme code and name are required for document upload.');
        }
        
        // Validate file upload
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error occurred.');
        }
        
        $file = $_FILES['document'];
        
        // Validate file type
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_extension !== 'pdf') {
            throw new Exception('Only PDF files are allowed.');
        }
        
        // Validate file size (5MB limit)
        $max_size = 5 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            throw new Exception('File size must be less than 5MB.');
        }
        
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
        
        // Route through common_upload_handler.php
        require_once(__DIR__ . '/common_upload_handler.php');
        
        // Set global variables for common_upload_handler.php
        $GLOBALS['dept_id'] = $dept;
        $GLOBALS['A_YEAR'] = $A_YEAR;
        
        // Use common upload handler with program-specific directory
        // Structure: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/Program_offered/{programme_code}/
        // IMPORTANT: Use dept_id (DEPT_ID), not dept_code, and use programme_code for unique folder per program
        // Use 'Program_offered' (capital P, no 's') to match user requirement
        // Use A_YEAR format (e.g., "2024-2025") for folder structure
        
        $result = handleDocumentUpload('programmes_offered', 'Programme Documentation', [
            'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/",
            'max_size' => 5,
            'document_title' => "Programme Documentation - {$programme_name}",
            'srno' => 1, // Use 1 as default serial for programmes
            'file_id' => 'programme_doc',
            'program_id' => $programme_code
        ]);
        
        // Ensure result is an array
        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'Invalid response from upload handler'];
        }
        
        $response = $result;
        
    } catch (Exception $e) {
        // CRITICAL #2: Build error response in variable
        $response = ['success' => false, 'message' => $e->getMessage()];
    } catch (Error $e) {
        // CRITICAL #2: Build error response in variable for fatal errors
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

// Handle programme document download/view - BEFORE unified_header
// Use PHP to serve files to prevent 403 errors
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['download_programme_doc'])) {
    // Disable error display
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Clear output buffer FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session FIRST
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    
    // Load session for user info - CRITICAL: Must load session.php to get $userInfo
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    
    // Clear buffers again after includes
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    try {
        $programme_code = $_GET['programme_code'] ?? '';
        
        if (empty($programme_code)) {
            header('HTTP/1.1 400 Bad Request');
            echo 'Programme code is required.';
            exit();
        }
        
        // Get department ID from session (already verified in session.php)
        $dept = $userInfo['DEPT_ID'] ?? 0;
        
        if (!$dept) {
            header('HTTP/1.1 403 Forbidden');
            echo 'Department ID not found. Please login again.';
            exit();
        }
        
        // CRITICAL FIX: Use resolveAcademicYear() function for consistency
        $A_YEAR = resolveAcademicYear();
        
        // Get file path from database
        $get_file_query = "SELECT file_path, file_name FROM supporting_documents 
            WHERE dept_id = ? AND page_section = 'programmes_offered' 
            AND program_id = ? AND status = 'active'
            ORDER BY upload_date DESC LIMIT 1";
        
        $stmt = mysqli_prepare($conn, $get_file_query);
        if (!$stmt) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Database error: ' . mysqli_error($conn);
            exit();
        }
        
        mysqli_stmt_bind_param($stmt, "is", $dept, $programme_code);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $file_data = mysqli_fetch_assoc($result);
            mysqli_free_result($result);
            mysqli_stmt_close($stmt);
            $file_path = $file_data['file_path'];
            $file_name = $file_data['file_name'];
            
            // Convert to absolute path for file_exists check
            $physical_path = $file_path;
            $project_root = dirname(__DIR__);
            
            // Handle various path formats
            if (strpos($physical_path, '../') === 0) {
                $physical_path = $project_root . '/' . str_replace('../', '', $physical_path);
            } elseif (strpos($physical_path, 'uploads/') === 0) {
                $physical_path = $project_root . '/' . $physical_path;
            } elseif (strpos($physical_path, $project_root) !== 0) {
                // Try to construct path with programme_code folder structure
                $filename = basename($file_path);
                $physical_path = $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/{$filename}";
            }
            
            // Normalize path separators
            $physical_path = str_replace('\\', '/', $physical_path);
            
            // Try multiple path variations
            $paths_to_try = [
                $physical_path,
                $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/" . basename($file_path),
                $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/programmes_offered/{$programme_code}/" . basename($file_path),
                $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/program_offered/{$programme_code}/" . basename($file_path)
            ];
            
            $file_found = false;
            foreach ($paths_to_try as $try_path) {
                $try_path = str_replace('\\', '/', $try_path);
                if (file_exists($try_path) && is_file($try_path)) {
                    $physical_path = $try_path;
                    $file_found = true;
                    break;
                }
            }
            
            // If still not found, try searching the folder directly
            if (!$file_found) {
                $possible_folders = [
                    $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/",
                    $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/programmes_offered/{$programme_code}/",
                    $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/program_offered/{$programme_code}/",
                    $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/Program_offered/{$programme_code}/"
                ];
                
                foreach ($possible_folders as $folder) {
                    $folder = str_replace('\\', '/', $folder);
                    if (is_dir($folder)) {
                        $files = glob($folder . "*.pdf");
                        if (!empty($files) && is_array($files) && count($files) > 0) {
                            // Use the most recently modified file
                            usort($files, function($a, $b) {
                                return filemtime($b) - filemtime($a);
                            });
                            $physical_path = str_replace('\\', '/', $files[0]);
                            $file_name = basename($physical_path);
                            $file_found = true;
                            break;
                        }
                    }
                }
            }
            
            if ($file_found) {
                // Serve the file
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . htmlspecialchars($file_name) . '"');
                header('Content-Length: ' . filesize($physical_path));
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                readfile($physical_path);
                exit();
            } else {
                header('HTTP/1.1 404 Not Found');
                echo 'File not found on server. Programme code: ' . htmlspecialchars($programme_code) . '<br>';
                echo 'Checked paths:<br>';
                foreach ($paths_to_try as $p) {
                    echo htmlspecialchars($p) . '<br>';
                }
                exit();
            }
        } else {
            mysqli_stmt_close($stmt);
            
            // If not in database, try to find file directly in folder
            $project_root = dirname(__DIR__);
            $possible_folders = [
                $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/",
                $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/programmes_offered/{$programme_code}/",
                $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/program_offered/{$programme_code}/",
                $project_root . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/Program_offered/{$programme_code}/"
            ];
            
            $file_found = false;
            $physical_path = '';
            $file_name = '';
            
            foreach ($possible_folders as $folder) {
                $folder = str_replace('\\', '/', $folder);
                if (is_dir($folder)) {
                    $files = glob($folder . "*.pdf");
                    if (!empty($files) && is_array($files) && count($files) > 0) {
                        // Use the most recently modified file
                        usort($files, function($a, $b) {
                            return filemtime($b) - filemtime($a);
                        });
                        $physical_path = str_replace('\\', '/', $files[0]);
                        $file_name = basename($physical_path);
                        $file_found = true;
                        break;
                    }
                }
            }
            
            if ($file_found) {
                // Serve the file even if not in database
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . htmlspecialchars($file_name) . '"');
                header('Content-Length: ' . filesize($physical_path));
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                readfile($physical_path);
                exit();
            } else {
                header('HTTP/1.1 404 Not Found');
                echo 'Document not found in database or file system. Programme code: ' . htmlspecialchars($programme_code);
                exit();
            }
        }
        
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Error: ' . htmlspecialchars($e->getMessage());
        exit();
    }
}

// Handle programme document deletion - BEFORE unified_header
// Convert GET delete to POST for security
// CRITICAL: Follow all checklist items to prevent crashes and ensure proper JSON responses
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_programme_doc'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config silently
    ob_start();
    if (!isset($conn) || !$conn) {
        require_once('../config.php');
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
        require_once __DIR__ . '/csrf.php';
        // Ensure CSRF token is generated in session before validation
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if it doesn't exist
        }
    }
    ob_end_clean();
    
    // Clear buffers again
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
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
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
        
        $programme_code = trim($_POST['programme_code'] ?? '');
        
        if (empty($programme_code)) {
            throw new Exception('Programme code is required for deletion.');
        }
        
        // Get department ID from session (already verified in session.php)
        $dept = $userInfo['DEPT_ID'];
        
        if (!$dept) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }
        $academic_year = date('Y') . '-' . (date('Y') + 1);
        
        // Get file path from database - without academic_year restriction
        $get_file_query = "SELECT file_path FROM supporting_documents 
            WHERE dept_id = ? AND page_section = 'programmes_offered' 
            AND program_id = ? AND status = 'active'
            ORDER BY upload_date DESC LIMIT 1";
        
        $stmt = mysqli_prepare($conn, $get_file_query);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "is", $dept, $programme_code);
        if (!mysqli_stmt_execute($stmt)) {
            $error = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new Exception('Query execution failed: ' . $error);
        }
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            mysqli_free_result($result);  // CRITICAL: Free result
            $file_path = $row['file_path'];
            
            // Delete file from filesystem (handle both ../ and relative paths, and both old/new folder structures)
            // Try absolute path first
            $file_physical = $file_path;
            if (strpos($file_physical, '../') === 0) {
                $file_physical = dirname(__DIR__) . '/' . str_replace('../', '', $file_physical);
            } elseif (strpos($file_physical, 'uploads/') === 0) {
                $file_physical = dirname(__DIR__) . '/' . $file_physical;
            }
            
            if ($file_path && file_exists($file_physical)) {
                unlink($file_physical);
                } else {
                // Try with programme code folder structure (new structure)
                    // Use Program_offered with A_YEAR format
                $current_year = (int)date('Y');
                $current_month = (int)date('n');
                // FIXED: July onwards (month >= 7) = current-next, Jan-June = (current-2)-(current-1)
                $a_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 2) . '-' . ($current_year - 1);
                    $file_with_prog = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/" . basename($file_path);
                if (file_exists($file_with_prog)) {
                    unlink($file_with_prog);
                }
                    // Also try with old format for backward compatibility
                    $file_with_prog_old = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/programmes_offered/{$programme_code}/" . basename($file_path);
                    if (file_exists($file_with_prog_old)) {
                        unlink($file_with_prog_old);
                    }
            }
            
            // Soft delete from database - without academic_year restriction
            $delete_query = "UPDATE supporting_documents 
                SET status = 'deleted', updated_date = CURRENT_TIMESTAMP 
                WHERE dept_id = ? AND page_section = 'programmes_offered' 
                AND program_id = ? AND status = 'active'";
            
            $stmt = mysqli_prepare($conn, $delete_query);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "is", $dept, $programme_code);
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);  // CRITICAL: Close statement
                $response = ['success' => true, 'message' => 'Document deleted successfully.'];
                } else {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception('Database error: ' . $error);
            }
        } else {
            if ($result) {
                mysqli_free_result($result);  // CRITICAL: Free result even if empty
            }
            mysqli_stmt_close($stmt);  // CRITICAL: Close statement
            throw new Exception('Document not found.');
        }
        
    } catch (Exception $e) {
        // CRITICAL #2: Build error response in variable
        $response = ['success' => false, 'message' => $e->getMessage()];
    } catch (Error $e) {
        // CRITICAL #2: Build error response in variable for fatal errors
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

// Handle programme document status check - BEFORE unified_header
// CRITICAL: Follow all checklist items to prevent crashes and ensure proper JSON responses
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['check_programme_doc'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config silently
    ob_start();
    if (!isset($conn) || !$conn) {
    require_once('../config.php');
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
    
    // Clear buffers again
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
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $programme_code = $_GET['programme_code'] ?? '';
        
        if (empty($programme_code)) {
            $response = ['success' => false, 'message' => 'Programme code is required.'];
        } else {
        // Get department ID from session (already verified in session.php)
            $dept = $userInfo['DEPT_ID'] ?? 0;
        
        if (!$dept) {
                $response = ['success' => false, 'message' => 'Department ID not found. Please contact administrator.'];
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
        
        // Calculate previous academic year for fallback
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        if ($current_month >= 7) {
            // Current: 2025-2026, Previous: 2024-2025
            $academic_year_alt = ($current_year - 1) . '-' . $current_year;
        } else {
            // Current: 2024-2025, Previous: 2023-2024
            $academic_year_alt = ($current_year - 3) . '-' . ($current_year - 2);
        }
        
        // Check for programme document - use direct comparison (same as table display)
        $programme_code = trim((string)($programme_code ?? ''));
        if (empty($programme_code)) {
                    $response = ['success' => false, 'message' => 'Programme code is required.'];
                } else {
                    // CRITICAL FIX: Include academic_year in SELECT to use actual document year for path reconstruction
                    $get_file_query = "SELECT file_path, file_name, upload_date, document_id, academic_year FROM supporting_documents 
            WHERE dept_id = ? AND page_section = 'programmes_offered' 
            AND program_id = ? AND status = 'active' 
            ORDER BY upload_date DESC LIMIT 1";
        
        $stmt = mysqli_prepare($conn, $get_file_query);
        if (!$stmt) {
                        $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
                    } else {
        mysqli_stmt_bind_param($stmt, "is", $dept, $programme_code);
                        if (!mysqli_stmt_execute($stmt)) {
                            $error = mysqli_stmt_error($stmt);
                            mysqli_stmt_close($stmt);
                            $response = ['success' => false, 'message' => 'Query execution failed: ' . $error];
                        } else {
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
                                mysqli_free_result($result);  // CRITICAL: Free result
            $file_path = $row['file_path'];
            $doc_academic_year = $row['academic_year'] ?? $academic_year; // Use document's actual year
            
            // Normalize path separators first
            $file_path = str_replace('\\', '/', $file_path);
            
            // Convert absolute paths to relative web paths (handle programme_code folder structure)
            $project_root = dirname(__DIR__);
            if (strpos($file_path, $project_root) === 0) {
                // Absolute path - convert to relative
                $file_path = str_replace($project_root . '/', '', $file_path);
                $file_path = str_replace($project_root . '\\', '', $file_path);
            }
            
            // Remove any leading slashes or dots
            $file_path = ltrim($file_path, './\\/');
            
            // CRITICAL FIX: Handle OLD path format (department_120/programmes_offered/)
            // Convert to NEW format (2024-2025/DEPARTMENT/120/Program_Offered/)
            if (strpos($file_path, 'uploads/department_' . $dept . '/programmes_offered/') === 0) {
                // OLD format detected - convert to NEW format
                // Extract programme_code and filename
                $old_path = $file_path;
                $pattern = '#uploads/department_' . $dept . '/programmes_offered/([^/]+)/(.+)$#';
                if (preg_match($pattern, $file_path, $matches)) {
                    $prog_code_from_path = $matches[1];
                    $filename = basename($matches[2]);
                    // Reconstruct using NEW format
                    $file_path = 'uploads/' . $doc_academic_year . '/DEPARTMENT/' . $dept . '/Program_Offered/' . $programme_code . '/' . $filename;
                }
            }
            
            // CRITICAL FIX: Use document's actual academic year for path reconstruction, not current year
            $a_year = $doc_academic_year;
            
            // If path starts with uploads/, ensure it has ../ prefix for web access
            if (strpos($file_path, 'uploads/') === 0) {
                // Try NEW path format first
                $new_path = '../' . $file_path;
                $new_physical = $project_root . '/' . $file_path;
                
                // Check if file exists in NEW location
                if (!file_exists($new_physical)) {
                    // File doesn't exist in NEW location - try OLD location
                    // OLD format: uploads/department_120/programmes_offered/{programme_code}/
                    $old_path = '../uploads/department_' . $dept . '/programmes_offered/' . $programme_code . '/' . basename($file_path);
                    $old_physical = $project_root . '/uploads/department_' . $dept . '/programmes_offered/' . $programme_code . '/' . basename($file_path);
                    
                    if (file_exists($old_physical)) {
                        // Use OLD path
                        $file_path = $old_path;
                } else {
                        // Neither exists - use NEW path (default)
                        $file_path = $new_path;
                    }
                } else {
                    // File exists in NEW location
                    $file_path = $new_path;
                }
            } elseif (strpos($file_path, '../') !== 0 && strpos($file_path, 'http') === false) {
                // Path doesn't start with uploads/ or ../ - reconstruct it
                $filename = basename($file_path);
                $file_path = '../uploads/' . $a_year . '/DEPARTMENT/' . $dept . '/Program_Offered/' . $programme_code . '/' . $filename;
            }
            
            // CRITICAL: Replace any instances of /0/ with the actual programme_code if found
            if (strpos($file_path, '/0/') !== false) {
                $file_path = str_replace('/0/', '/' . $programme_code . '/', $file_path);
            }
            if (strpos($file_path, 'programmes_offered/0') !== false) {
                $file_path = str_replace('programmes_offered/0', 'Program_Offered/' . $programme_code, $file_path);
            }
            if (strpos($file_path, 'program_offered/0') !== false) {
                $file_path = str_replace('program_offered/0', 'Program_Offered/' . $programme_code, $file_path);
            }
            if (strpos($file_path, 'Program_offered/0') !== false) {
                $file_path = str_replace('Program_offered/0', 'Program_Offered/' . $programme_code, $file_path);
            }
            if (strpos($file_path, 'Program_Offered/0') !== false) {
                $file_path = str_replace('Program_Offered/0', 'Program_Offered/' . $programme_code, $file_path);
            }
            
                                mysqli_stmt_close($stmt);  // CRITICAL: Close statement
                                $response = [
                'success' => true,
                'file_path' => $file_path,
                'file_name' => $row['file_name'],
                                    'upload_date' => $row['upload_date'] ? date('d-M-Y', strtotime($row['upload_date'])) : 'N/A',
                                    'document_id' => $row['document_id'] ?? null
                                ];
        } else {
                                if ($result) {
                                    mysqli_free_result($result);  // CRITICAL: Free result even if empty
                                }
                                mysqli_stmt_close($stmt);  // CRITICAL: Close statement
                                $response = ['success' => false, 'message' => 'No document found'];
                            }
                        }
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        // CRITICAL #2: Build error response in variable
        $response = ['success' => false, 'message' => $e->getMessage()];
    } catch (Error $e) {
        // CRITICAL #2: Build error response in variable for fatal errors
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

// Handle AJAX requests for fetching programme data for editing - BEFORE unified_header
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_programme') {
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
    
    header('Content-Type: application/json');
    
    try {
        // Get department ID from session (already verified in session.php)
        $dept = $userInfo['DEPT_ID'];
        
        if (!$dept) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }
        
        $programme_id = (int)($_GET['programme_id'] ?? 0);
        
        if ($programme_id <= 0) {
            throw new Exception('Invalid programme ID.');
        }
        
        // Get programme details
        $get_query = "SELECT * FROM programmes WHERE id = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $get_query);
        mysqli_stmt_bind_param($stmt, "ii", $programme_id, $dept);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (!$result || mysqli_num_rows($result) == 0) {
            throw new Exception('Programme not found or you don\'t have permission to edit it.');
        }
        
        $programme = mysqli_fetch_assoc($result);
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'programme' => $programme
        ]);
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

// Handle AJAX requests for adding/updating programmes - BEFORE unified_header
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && ($_POST['action'] == 'add_programme' || $_POST['action'] == 'update_programme')) {
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
        
        // Get department ID from session (already verified in session.php)
        $dept = $userInfo['DEPT_ID'];
        
        if (!$dept) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }
        
        // CRITICAL: Ensure ENUM column has ALL required values BEFORE processing
        // This must happen BEFORE validation to ensure the column is correct
        
        // Save current SQL mode to restore later
        $sql_mode_query = mysqli_query($conn, "SELECT @@sql_mode as mode");
        $sql_mode_row = mysqli_fetch_assoc($sql_mode_query);
        $original_sql_mode = $sql_mode_row['mode'] ?? '';
        
        // Temporarily disable strict mode to allow ENUM updates even with invalid data
        mysqli_query($conn, "SET SESSION sql_mode = ''");
        
        // CRITICAL: Include 'UG 2 years' in the expected ENUM
        $expected_enum = "ENUM('Certificate course', 'UG Diploma 1 year', 'UG Diploma 2 years', 'UG Diploma 3 years', 'UG 2 years', 'UG 3 years', 'UG 4 years', 'UG 5 years', 'UG 6 years', 'PG Diploma 1 year', 'PG Diploma 2 years', 'PG 1 year', 'PG 2 years', 'PG 3 years', 'Advanced Diploma 1 year', 'Advanced Diploma 2 years', 'PG Integrated 5 years', 'PG Integrated 6 years', 'PhD')";
        
        // ALL required values to check (not just a subset) - must match $valid_programme_types
        $all_required_values = [
            'Certificate course',
            'UG Diploma 1 year',
            'UG Diploma 2 years', 
            'UG Diploma 3 years',
            'UG 2 years',
            'UG 3 years',
            'UG 4 years',
            'UG 5 years',
            'UG 6 years',
            'PG Diploma 1 year',
            'PG Diploma 2 years',
            'PG 1 year',
            'PG 2 years',
            'PG 3 years',
            'Advanced Diploma 1 year',
            'Advanced Diploma 2 years',
            'PG Integrated 5 years',
            'PG Integrated 6 years',
            'PhD'
        ];
        
        $check_column = "SHOW COLUMNS FROM programmes WHERE Field = 'programme_type'";
        $column_result = mysqli_query($conn, $check_column);
        
        if ($column_result && mysqli_num_rows($column_result) > 0) {
            $column_info = mysqli_fetch_assoc($column_result);
            $current_type = $column_info['Type'] ?? '';
            
            // Check if column needs updating
            preg_match("/ENUM\((.*?)\)/i", $current_type, $matches);
            $current_enum_values = isset($matches[1]) ? $matches[1] : '';
            
            $needs_update = false;
            $missing_values = [];
            foreach ($all_required_values as $req_value) {
                // Check with quotes since ENUM values are stored with quotes
                $quoted_value = "'" . $req_value . "'";
                if (stripos($current_enum_values, $quoted_value) === false) {
                    $needs_update = true;
                    $missing_values[] = $req_value;
                }
            }
            
            // ALWAYS update ENUM to ensure it has all values (handles edge cases)
            // This is safer than conditional update - ensures column is always correct
            // CRITICAL: Update ENUM FIRST before cleaning data
            // This ensures the ENUM has all values BEFORE we try to insert
            // Step 1: Update ENUM WITHOUT NOT NULL first (safer, avoids constraint conflicts)
            $temp_enum_no_null = str_replace(" NOT NULL", "", $expected_enum);
            $temp_modify = "ALTER TABLE programmes MODIFY COLUMN programme_type " . $temp_enum_no_null;
            $temp_result = mysqli_query($conn, $temp_modify);
            
            // Step 2: Add NOT NULL back
            $final_modify = "ALTER TABLE programmes MODIFY COLUMN programme_type " . $expected_enum . " NOT NULL";
            $final_result = mysqli_query($conn, $final_modify);
            
            // Step 3: NOW clean invalid data (only affects existing records, not the one we're about to insert)
            // Only clean values that are definitely invalid - don't touch the value we're about to insert
            $check_invalid_query = "SELECT id, programme_type FROM programmes WHERE programme_type IS NOT NULL AND programme_type != ''";
            $invalid_result = mysqli_query($conn, $check_invalid_query);
            
            if ($invalid_result) {
                $invalid_count = 0;
                while ($invalid_row = mysqli_fetch_assoc($invalid_result)) {
                    $current_pt = $invalid_row['programme_type'];
                    // Check if this value is in our valid list (now that ENUM is updated)
                    if (!in_array($current_pt, $all_required_values, true)) {
                        $fix_query = "UPDATE programmes SET programme_type = 'Certificate course' WHERE id = ?";
                        $fix_stmt = mysqli_prepare($conn, $fix_query);
                        if ($fix_stmt) {
                            mysqli_stmt_bind_param($fix_stmt, "i", $invalid_row['id']);
                            mysqli_stmt_execute($fix_stmt);
                            mysqli_stmt_close($fix_stmt);
                        }
                        $invalid_count++;
                    }
                }
            }
            
            // Step 4: Fix NULL/empty values (only for existing records)
            mysqli_query($conn, "UPDATE programmes SET programme_type = 'Certificate course' WHERE programme_type IS NULL OR programme_type = ''");
            
            // Step 4: Verify the update worked and restore SQL mode
            $verify_result = mysqli_query($conn, $check_column);
            if ($verify_result && $verify_row = mysqli_fetch_assoc($verify_result)) {
                $verify_type = $verify_row['Type'] ?? '';
            }
            
            // Restore original SQL mode
            if (!empty($original_sql_mode)) {
                mysqli_query($conn, "SET SESSION sql_mode = '" . mysqli_real_escape_string($conn, $original_sql_mode) . "'");
            }
        } else {
            $add_column = "ALTER TABLE programmes ADD COLUMN programme_type " . $expected_enum . " NOT NULL AFTER programme_name";
            mysqli_query($conn, $add_column);
            
            // Restore original SQL mode
            if (!empty($original_sql_mode)) {
                mysqli_query($conn, "SET SESSION sql_mode = '" . mysqli_real_escape_string($conn, $original_sql_mode) . "'");
            }
        }

        // Ensure A_YEAR column exists in programmes (stores academic year for each record)
        $ay_col_check = mysqli_query($conn, "SHOW COLUMNS FROM programmes LIKE 'A_YEAR'");
        if (!$ay_col_check || mysqli_num_rows($ay_col_check) == 0) {
            // Add A_YEAR as VARCHAR(20) NOT NULL, placed after programme_name for readability
            $add_ay = "ALTER TABLE programmes ADD COLUMN A_YEAR VARCHAR(20) NOT NULL AFTER programme_name";
            mysqli_query($conn, $add_ay);
        }
        
        // Validate and sanitize input
        $programme_code = trim($_POST['programme_code'] ?? '');
        $programme_name = trim($_POST['programme_name'] ?? '');
        // Resolve academic year for this operation
        $academic_year_for_prog = resolveAcademicYear();
        
        // Get programme_type - check multiple possible sources and formats
        $programme_type = '';
        if (isset($_POST['programme_type']) && !empty($_POST['programme_type'])) {
            // Trim and clean the value, removing any extra whitespace
            $programme_type = trim($_POST['programme_type']);
            // Remove any null bytes or special characters that might cause issues
            $programme_type = str_replace(["\0", "\r", "\n"], '', $programme_type);
            // Normalize Unicode spaces (NBSP and other width spaces) to regular spaces
            $programme_type = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', ' ', $programme_type);
            // Normalize multiple spaces to single space
            $programme_type = preg_replace('/\s+/', ' ', $programme_type);
            $programme_type = trim($programme_type);
        }
        
        // Define valid ENUM values exactly as they appear in database
        $valid_programme_types = [
            'Certificate course',
            'UG Diploma 1 year',
            'UG Diploma 2 years',
            'UG Diploma 3 years',
            'UG 2 years',
            'UG 3 years',
            'UG 4 years',
            'UG 5 years',
            'UG 6 years',
            'PG Diploma 1 year',
            'PG Diploma 2 years',
            'PG 1 year',
            'PG 2 years',
            'PG 3 years',
            'Advanced Diploma 1 year',
            'Advanced Diploma 2 years',
            'PG Integrated 5 years',
            'PG Integrated 6 years',
            'PhD'
        ];
        
        // Validate and normalize programme_type against ENUM values
        if (!empty($programme_type)) {
            // Alias mapping to handle common user-input variants (singular/plural, abbreviations)
            $aliases = [
                'ug 3 year' => 'UG 3 years',
                'ug3 year' => 'UG 3 years',
                'ug-3 year' => 'UG 3 years',
                'ug 3yr' => 'UG 3 years',
                'ug 3yrs' => 'UG 3 years',
                'ug 3years' => 'UG 3 years',
                'ug three years' => 'UG 3 years',
                'ug 4 year' => 'UG 4 years',
                'ug 5 year' => 'UG 5 years',
                'ug 6 year' => 'UG 6 years',
                'pg 1 years' => 'PG 1 year',
                'pg 2 year' => 'PG 2 years',
                'pg 3 year' => 'PG 3 years',
                'ug diploma 1 years' => 'UG Diploma 1 year',
                'pg diploma 1 years' => 'PG Diploma 1 year',
                'advanced diploma 1 years' => 'Advanced Diploma 1 year',
                'advanced diploma 2 year' => 'Advanced Diploma 2 years'
            ];
            $norm_key = strtolower(preg_replace('/\s+/', ' ', trim($programme_type)));
            if (isset($aliases[$norm_key])) {
                $programme_type = $aliases[$norm_key];
            }

            // Heuristic mapping for common phrasing variants (order-insensitive)
            $norm_free = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $programme_type));
            $words = array_filter(explode(' ', $norm_free));
            $hasUG = in_array('ug', $words, true) || in_array('undergraduate', $words, true);
            $hasPG = in_array('pg', $words, true) || in_array('postgraduate', $words, true);
            $hasDiploma = in_array('diploma', $words, true);
            $hasAdvanced = in_array('advanced', $words, true);
            $hasIntegrated = in_array('integrated', $words, true);
            $hasPhD = in_array('phd', $words, true);
            $has1 = in_array('1', $words, true) || in_array('one', $words, true);
            $has2 = in_array('2', $words, true) || in_array('two', $words, true);
            $has3 = in_array('3', $words, true) || in_array('three', $words, true) || in_array('yr', $words, true) || in_array('yrs', $words, true);
            $has4 = in_array('4', $words, true) || in_array('four', $words, true);
            $has5 = in_array('5', $words, true) || in_array('five', $words, true);
            $has6 = in_array('6', $words, true) || in_array('six', $words, true);

            // Diploma mappings
            if ($hasUG && $hasDiploma && $has1) { $programme_type = 'UG Diploma 1 year'; }
            if ($hasUG && $hasDiploma && $has2) { $programme_type = 'UG Diploma 2 years'; }
            if ($hasUG && $hasDiploma && $has3) { $programme_type = 'UG Diploma 3 years'; }

            if ($hasPG && $hasDiploma && $has1) { $programme_type = 'PG Diploma 1 year'; }
            if ($hasPG && $hasDiploma && $has2) { $programme_type = 'PG Diploma 2 years'; }

            // Advanced Diploma
            if ($hasAdvanced && $hasDiploma && $has1) { $programme_type = 'Advanced Diploma 1 year'; }
            if ($hasAdvanced && $hasDiploma && $has2) { $programme_type = 'Advanced Diploma 2 years'; }

            // UG durations
            if ($hasUG && !$hasDiploma) {
                if ($has2) { $programme_type = 'UG 2 years'; }
                if ($has3) { $programme_type = 'UG 3 years'; }
                if ($has4) { $programme_type = 'UG 4 years'; }
                if ($has5) { $programme_type = 'UG 5 years'; }
                if ($has6) { $programme_type = 'UG 6 years'; }
            }

            // PG durations
            if ($hasPG && !$hasDiploma && !$hasIntegrated) {
                if ($has1) { $programme_type = 'PG 1 year'; }
                if ($has2) { $programme_type = 'PG 2 years'; }
                if ($has3) { $programme_type = 'PG 3 years'; }
            }

            // Integrated
            if ($hasPG && $hasIntegrated) {
                if ($has5) { $programme_type = 'PG Integrated 5 years'; }
                if ($has6) { $programme_type = 'PG Integrated 6 years'; }
            }

            // PhD
            if ($hasPhD) { $programme_type = 'PhD'; }
            $original_type = $programme_type;
            // Try exact match first
            if (!in_array($programme_type, $valid_programme_types, true)) {
                // Try case-insensitive match
                $found_match = false;
                foreach ($valid_programme_types as $valid_type) {
                    // Normalize both for comparison
                    $normalized_input = trim(strtolower($programme_type));
                    $normalized_valid = trim(strtolower($valid_type));
                    
                    if ($normalized_input === $normalized_valid || 
                        trim($programme_type) === trim($valid_type)) {
                        $programme_type = $valid_type; // Use the exact ENUM value from database
                        $found_match = true;
                        break;
                    }
                }
                if (!$found_match) {
                    // Don't throw exception yet - let the validation below handle it
                }
            } else {
                // Value is valid, ensure we use exact match from valid array (in case of minor differences)
                $programme_type = $valid_programme_types[array_search($programme_type, $valid_programme_types, true)];
            }
        }
        
        // Validate and sanitize numeric fields - ensure non-negative
        $intake_capacity = max(0, (int)($_POST['intake_capacity'] ?? 0));
        
        
        // Get year-wise capacities - validate as non-negative integers
        $year_1_capacity = max(0, (int)($_POST['year_1_capacity'] ?? 0));
        $year_2_capacity = max(0, (int)($_POST['year_2_capacity'] ?? 0));
        $year_3_capacity = max(0, (int)($_POST['year_3_capacity'] ?? 0));
        $year_4_capacity = max(0, (int)($_POST['year_4_capacity'] ?? 0));
        $year_5_capacity = max(0, (int)($_POST['year_5_capacity'] ?? 0));
        $year_6_capacity = max(0, (int)($_POST['year_6_capacity'] ?? 0));
        $intake_strength = max(0, (int)($_POST['intake_strength'] ?? 0));
        
        // Calculate total intake
        // For Certificate, PhD, and 1-year diplomas that use intake_strength field
        if ($programme_type === 'Certificate course' || $programme_type === 'PhD' || 
            $programme_type === 'UG Diploma 1 year' || $programme_type === 'PG Diploma 1 year' || 
            $programme_type === 'Advanced Diploma 1 year') {
            $total_intake = $intake_strength;
            $year_1_capacity = $intake_strength;
            $year_2_capacity = 0;
            $year_3_capacity = 0;
            $year_4_capacity = 0;
            $year_5_capacity = 0;
            $year_6_capacity = 0;
        } else {
            // For multi-year programmes, sum up year capacities
            $total_intake = $year_1_capacity + $year_2_capacity + $year_3_capacity + 
                          $year_4_capacity + $year_5_capacity + $year_6_capacity;
        }

        // Ensure intake_capacity reflects the overall total for consistency in DB
        // Many UIs do not collect a separate intake_capacity; we mirror total_intake
        $intake_capacity = (int)$total_intake;
        
        // Validate required fields
        if (empty($programme_code)) {
            throw new Exception('Programme code is required.');
        }
        
        if (empty($programme_name)) {
            throw new Exception('Programme name is required.');
        }
        
        if (empty($programme_type)) {
            throw new Exception('Programme type is required. Please select a valid programme type.');
        }
        
        // Final validation - ensure it's a valid ENUM value
        if (!in_array($programme_type, $valid_programme_types, true)) {
            // Double-check against actual database ENUM values
            $db_enum_check = "SHOW COLUMNS FROM programmes WHERE Field = 'programme_type'";
            $db_enum_result = mysqli_query($conn, $db_enum_check);
            if ($db_enum_result && $db_enum_row = mysqli_fetch_assoc($db_enum_result)) {
                $db_enum_type = $db_enum_row['Type'] ?? '';
                
                // Extract actual ENUM values from database
                preg_match("/ENUM\((.*?)\)/i", $db_enum_type, $db_matches);
                if (isset($db_matches[1])) {
                    $db_enum_values = $db_matches[1];
                    
                    // Check if our value exists in database ENUM
                    if (stripos($db_enum_values, $programme_type) === false) {
                        throw new Exception('Invalid programme type "' . htmlspecialchars($programme_type) . '". The database ENUM column may need to be updated. Please contact administrator.');
                    }
                }
            }
            
            throw new Exception('Invalid programme type "' . htmlspecialchars($programme_type) . '". Please select a valid programme type from the dropdown.');
        }
        
        // CRITICAL: VERIFY AGAINST DATABASE - Final check before insert/update
        // This MUST pass or we throw an exception - don't proceed if value isn't in ENUM
        $final_verify_query = "SHOW COLUMNS FROM programmes WHERE Field = 'programme_type'";
        $final_verify_result = mysqli_query($conn, $final_verify_query);
        
        if (!$final_verify_result) {
            throw new Exception('Database error: Unable to verify programme_type column. Error: ' . mysqli_error($conn));
        }
        
        $final_verify_row = mysqli_fetch_assoc($final_verify_result);
        if (!$final_verify_row) {
            throw new Exception('Database error: programme_type column not found. Please contact administrator.');
        }
        
        $final_db_enum_definition = $final_verify_row['Type'] ?? '';
        preg_match("/ENUM\((.*?)\)/i", $final_db_enum_definition, $final_enum_matches);
        
        if (!isset($final_enum_matches[1])) {
            throw new Exception('Database error: Invalid ENUM definition. Please contact administrator.');
        }
        
        $final_db_enum_string = $final_enum_matches[1];
        $final_search_pattern = "'" . mysqli_real_escape_string($conn, $programme_type) . "'";
        
        // Check if our programme_type exists in the database ENUM (case-sensitive check with quotes)
        if (stripos($final_db_enum_string, $final_search_pattern) === false) {
            // Last chance: Try emergency ENUM update with aggressive cleanup
            // Step 1: Find ALL programmes with invalid values and fix them
            $find_invalid = "SELECT id, programme_type FROM programmes";
            $invalid_result = mysqli_query($conn, $find_invalid);
            if ($invalid_result) {
                while ($inv_row = mysqli_fetch_assoc($invalid_result)) {
                    $pt_value = $inv_row['programme_type'];
                    if (!in_array($pt_value, $all_required_values, true) && !empty($pt_value)) {
                        $fix_query = "UPDATE programmes SET programme_type = 'Certificate course' WHERE id = ?";
                        $fix_stmt = mysqli_prepare($conn, $fix_query);
                        if ($fix_stmt) {
                            mysqli_stmt_bind_param($fix_stmt, "i", $inv_row['id']);
                            mysqli_stmt_execute($fix_stmt);
                            mysqli_stmt_close($fix_stmt);
                        }
                    }
                }
            }
            
            // Step 2: Fix NULL/empty
            mysqli_query($conn, "UPDATE programmes SET programme_type = 'Certificate course' WHERE programme_type IS NULL OR programme_type = ''");
            
            // Step 3: Update ENUM WITHOUT NOT NULL first
            // CRITICAL: Use $expected_enum variable to ensure 'UG 2 years' is included
            $emergency_enum_no_null = str_replace(" NOT NULL", "", $expected_enum);
            $emergency_update1 = "ALTER TABLE programmes MODIFY COLUMN programme_type " . $emergency_enum_no_null;
            
            if (mysqli_query($conn, $emergency_update1)) {
                // Step 4: Add NOT NULL back
                // CRITICAL: Use $expected_enum variable to ensure 'UG 2 years' is included
                $emergency_enum = $expected_enum;
                $emergency_update2 = "ALTER TABLE programmes MODIFY COLUMN programme_type " . $emergency_enum . " NOT NULL";
                
                mysqli_query($conn, $emergency_update2);
                
                // Step 5: Re-check after emergency update
                $recheck_result = mysqli_query($conn, $final_verify_query);
                if ($recheck_result && $recheck_row = mysqli_fetch_assoc($recheck_result)) {
                    $recheck_enum = $recheck_row['Type'] ?? '';
                    preg_match("/ENUM\((.*?)\)/i", $recheck_enum, $recheck_matches);
                    if (isset($recheck_matches[1])) {
                        $recheck_enum_string = $recheck_matches[1];
                        // Check with exact quote pattern
                        $exact_pattern = "'" . mysqli_real_escape_string($conn, $programme_type) . "'";
                        if (stripos($recheck_enum_string, $exact_pattern) === false) {
                            throw new Exception('The programme type "' . htmlspecialchars($programme_type) . '" is not available in the database ENUM column even after update. Please contact administrator. Current ENUM: ' . substr($recheck_enum_string, 0, 200));
                        }
                    }
                }
            } else {
                $emergency_error = mysqli_error($conn);
                throw new Exception('Database error: Unable to update programme type column. The value "' . htmlspecialchars($programme_type) . '" is not in the ENUM. Error: ' . $emergency_error);
            }
        }
        
        if ($total_intake <= 0) {
            throw new Exception('Total intake capacity must be greater than 0.');
        }
        
        // FINAL VALIDATION: Test if MySQL will accept this programme_type value
        // Try a test query to see if the value is valid for the ENUM
        $test_query = "SELECT '" . mysqli_real_escape_string($conn, $programme_type) . "' = CAST('" . mysqli_real_escape_string($conn, $programme_type) . "' AS CHAR) AS test_result";
        $test_result = mysqli_query($conn, $test_query);
        
        // More direct test: Try to get the ENUM values and verify exact match
        $enum_check_query = "SHOW COLUMNS FROM programmes WHERE Field = 'programme_type'";
        $enum_check_result = mysqli_query($conn, $enum_check_query);
        if ($enum_check_result && $enum_check_row = mysqli_fetch_assoc($enum_check_result)) {
            $enum_def = $enum_check_row['Type'] ?? '';
            // Extract enum values
            preg_match("/ENUM\((.*?)\)/i", $enum_def, $enum_extract);
            if (isset($enum_extract[1])) {
                $enum_values_str = $enum_extract[1];
                // Check if our value (with quotes) exists
                $quote_pattern = "'" . mysqli_real_escape_string($conn, $programme_type) . "'";
                if (stripos($enum_values_str, $quote_pattern) === false) {
                    // Force update one more time
                    // CRITICAL: Use $expected_enum variable to ensure 'UG 2 years' is included
                    $force_enum = $expected_enum;
                    mysqli_query($conn, "UPDATE programmes SET programme_type = 'Certificate course' WHERE programme_type IS NULL OR programme_type = ''");
                    $force_update = "ALTER TABLE programmes MODIFY COLUMN programme_type " . $force_enum;
                    if (!mysqli_query($conn, $force_update)) {
                        $force_error = mysqli_error($conn);
                        throw new Exception('The programme type "' . htmlspecialchars($programme_type) . '" cannot be inserted. Database ENUM column update failed. Error: ' . $force_error . '. Please contact administrator.');
                    }
                    
                    // Re-check after force update
                    $final_enum_check = mysqli_query($conn, $enum_check_query);
                    if ($final_enum_check && $final_enum_row = mysqli_fetch_assoc($final_enum_check)) {
                        $final_enum_def = $final_enum_row['Type'] ?? '';
                        preg_match("/ENUM\((.*?)\)/i", $final_enum_def, $final_extract);
                        if (isset($final_extract[1])) {
                            $final_enum_str = $final_extract[1];
                            if (stripos($final_enum_str, $quote_pattern) === false) {
                                throw new Exception('CRITICAL: Programme type "' . htmlspecialchars($programme_type) . '" still not found in ENUM after force update. Database may need manual intervention. Please contact administrator.');
                            }
                        }
                    }
                }
            }
        }
        
        $is_update = ($_POST['action'] == 'update_programme');
        $programme_id = $is_update ? (int)($_POST['programme_id'] ?? 0) : 0;
        
        if ($is_update && $programme_id <= 0) {
            throw new Exception('Invalid programme ID for update.');
        }
        
        if ($is_update) {
            // Verify programme belongs to this department
            $verify_query = "SELECT id FROM programmes WHERE id = ? AND DEPT_ID = ?";
            $stmt = mysqli_prepare($conn, $verify_query);
            mysqli_stmt_bind_param($stmt, "ii", $programme_id, $dept);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) == 0) {
                throw new Exception('Programme not found or you don\'t have permission to update it.');
            }
            
            // Get old programme code for file path updates
            $old_prog_query = "SELECT programme_code FROM programmes WHERE id = ?";
            $stmt = mysqli_prepare($conn, $old_prog_query);
            mysqli_stmt_bind_param($stmt, "i", $programme_id);
            mysqli_stmt_execute($stmt);
            $old_result = mysqli_stmt_get_result($stmt);
            $old_prog = mysqli_fetch_assoc($old_result);
            $old_programme_code = $old_prog['programme_code'];
        }
        
        // Check for duplicate programme code (only if code changed or new programme)
        if (!$is_update || ($is_update && $programme_code != $old_programme_code)) {
            $check_query = "SELECT id FROM programmes WHERE DEPT_ID = ? AND programme_code = ?";
            $stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($stmt, "is", $dept, $programme_code);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) > 0) {
                throw new Exception("Programme code '{$programme_code}' already exists for this department.");
            }
        }
        
        if ($is_update) {
            // Update programme
            $update_query = "UPDATE programmes SET 
                programme_code = ?, programme_name = ?, A_YEAR = ?, programme_type = ?, intake_capacity = ?,
                year_1_capacity = ?, year_2_capacity = ?, year_3_capacity = ?, 
                year_4_capacity = ?, year_5_capacity = ?, year_6_capacity = ?, total_intake = ?,
                upload_date = CURRENT_TIMESTAMP
                WHERE id = ? AND DEPT_ID = ?";
            
            // Ensure programme_type is not empty or null
            if (empty($programme_type)) {
                throw new Exception('Programme type cannot be empty during update.');
            }
            
            $stmt = mysqli_prepare($conn, $update_query);
            if (!$stmt) {
                $error_msg = 'Failed to prepare update statement: ' . mysqli_error($conn);
                error_log("UPDATE Prepare Error: " . $error_msg);
                throw new Exception($error_msg);
            }
            
            mysqli_stmt_bind_param($stmt, "ssssiiiiiiiiii", 
                $programme_code, $programme_name, $academic_year_for_prog, $programme_type, $intake_capacity,
                $year_1_capacity, $year_2_capacity, $year_3_capacity, 
                $year_4_capacity, $year_5_capacity, $year_6_capacity, $total_intake,
                $programme_id, $dept);
            
            if (!mysqli_stmt_execute($stmt)) {
                $error_msg = 'Failed to update programme: ' . mysqli_error($conn);
                error_log("UPDATE Execute Error: " . $error_msg);
                error_log("UPDATE SQL Error: " . mysqli_stmt_error($stmt));
                
                throw new Exception($error_msg);
            }
            
            // Note: Keep sql_mode unchanged during UPDATE to prevent coercion
            
            // Check for MySQL warnings (like invalid ENUM values)
            $warning_count = mysqli_warning_count($conn);
            if ($warning_count > 0) {
                $warnings = [];
                $result = mysqli_query($conn, "SHOW WARNINGS");
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $warnings[] = $row['Message'];
                        
                        // If ENUM value is invalid, throw exception
                        if (stripos($row['Message'], 'invalid') !== false || 
                            stripos($row['Message'], 'enum') !== false ||
                            stripos($row['Message'], 'Data truncated') !== false) {
                            throw new Exception('Invalid programme type value. Please contact administrator. Error: ' . $row['Message']);
                        }
                    }
                }
            }
            
            // Verify the update was successful by querying the database
            $verify_query = "SELECT programme_type FROM programmes WHERE id = ? AND DEPT_ID = ?";
            $verify_stmt = mysqli_prepare($conn, $verify_query);
            mysqli_stmt_bind_param($verify_stmt, "ii", $programme_id, $dept);
            mysqli_stmt_execute($verify_stmt);
            $verify_result = mysqli_stmt_get_result($verify_stmt);
            $verify_row = mysqli_fetch_assoc($verify_result);
        } else {
            // Insert programme (keep sql_mode enabled to avoid coercion)
            
            $insert_query = "INSERT INTO programmes 
                (DEPT_ID, programme_code, programme_name, A_YEAR, programme_type, intake_capacity, 
                 year_1_capacity, year_2_capacity, year_3_capacity, year_4_capacity, 
                 year_5_capacity, year_6_capacity, total_intake) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            // Ensure programme_type is not empty or null
            if (empty($programme_type)) {
                throw new Exception('Programme type cannot be empty. Please select a programme type.');
            }
            
            
            // FINAL CHECK: Verify value exists in ENUM one more time before INSERT
            $last_check = mysqli_query($conn, "SHOW COLUMNS FROM programmes WHERE Field = 'programme_type'");
            if ($last_check && $last_row = mysqli_fetch_assoc($last_check)) {
                $last_enum = $last_row['Type'] ?? '';
                preg_match("/ENUM\((.*?)\)/i", $last_enum, $last_matches);
                if (isset($last_matches[1])) {
                    $last_enum_str = $last_matches[1];
                    $last_pattern = "'" . mysqli_real_escape_string($conn, $programme_type) . "'";
                    if (stripos($last_enum_str, $last_pattern) === false) {
                        
                        // Force update one final time
                        // CRITICAL: Use $expected_enum variable to ensure 'UG 2 years' is included
                        $final_force_enum = $expected_enum;
                        mysqli_query($conn, "SET SESSION sql_mode = ''");
                        mysqli_query($conn, "UPDATE programmes SET programme_type = 'Certificate course' WHERE programme_type IS NULL OR programme_type = ''");
                        $final_force = "ALTER TABLE programmes MODIFY COLUMN programme_type " . $final_force_enum;
                        if (!mysqli_query($conn, $final_force)) {
                            throw new Exception('CRITICAL: Cannot update ENUM column. Database may need manual fix. Error: ' . mysqli_error($conn));
                        }
                        
                        // Re-check after force update
                        $recheck_after_force = mysqli_query($conn, "SHOW COLUMNS FROM programmes WHERE Field = 'programme_type'");
                        if ($recheck_after_force && $recheck_row = mysqli_fetch_assoc($recheck_after_force)) {
                            $recheck_enum = $recheck_row['Type'] ?? '';
                            preg_match("/ENUM\((.*?)\)/i", $recheck_enum, $recheck_matches);
                            if (isset($recheck_matches[1])) {
                                $recheck_enum_str = $recheck_matches[1];
                                if (stripos($recheck_enum_str, $last_pattern) === false) {
                                    error_log("CRITICAL: Value STILL missing after force update!");
                                    throw new Exception('The programme type "' . htmlspecialchars($programme_type) . '" is not available in the database ENUM even after update. Please contact administrator.');
                                } else {
                                    error_log("SUCCESS: Value now found in ENUM after force update!");
                                }
                            }
                        }
                    } else {
                        error_log("PRE-INSERT CHECK PASSED: Value '" . $programme_type . "' confirmed in ENUM.");
                    }
                }
            }
            
            // Log the exact value being inserted
            error_log("=== ABOUT TO INSERT ===");
            error_log("programme_type value: '" . $programme_type . "'");
            error_log("programme_type length: " . strlen($programme_type));
            error_log("programme_type hex: " . bin2hex($programme_type));
            error_log("programme_code: '" . $programme_code . "'");
            error_log("programme_name: '" . $programme_name . "'");
            
            $stmt = mysqli_prepare($conn, $insert_query);
            if (!$stmt) {
                $error_msg = 'Failed to prepare insert statement: ' . mysqli_error($conn);
                error_log("INSERT Prepare Error: " . $error_msg);
                throw new Exception($error_msg);
            }
            
            // CRITICAL: Log the bound parameters to verify
            error_log("Binding parameters - programme_type: '" . $programme_type . "'");
            mysqli_stmt_bind_param($stmt, "issssiiiiiiii", 
                $dept, $programme_code, $programme_name, $academic_year_for_prog, $programme_type, $intake_capacity,
                $year_1_capacity, $year_2_capacity, $year_3_capacity, 
                $year_4_capacity, $year_5_capacity, $year_6_capacity, $total_intake);
            
            // Execute INSERT with one retry if ENUM causes truncation
            $executed = mysqli_stmt_execute($stmt);
            if (!$executed) {
                $execute_err = mysqli_stmt_error($stmt) ?: mysqli_error($conn);
                error_log("INSERT Programme Error: " . $execute_err);
                error_log("Programme Type Value: " . var_export($programme_type, true));
                
                if (stripos($execute_err, 'Data truncated') !== false || stripos($execute_err, 'enum') !== false) {
                    error_log("INSERT failed due to ENUM. Reading current ENUM, merging with required values, forcing update, and retrying once...");
                    
                    // Read current ENUM definition
                    $enum_res = mysqli_query($conn, "SHOW COLUMNS FROM programmes WHERE Field = 'programme_type'");
                    $current_values = [];
                    if ($enum_res && $enum_row = mysqli_fetch_assoc($enum_res)) {
                        $type_def = $enum_row['Type'] ?? '';
                        if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type_def, $m)) {
                            foreach ($m[1] as $raw) {
                                $v = stripcslashes($raw);
                                if ($v !== '') { $current_values[$v] = true; }
                            }
                        } else {
                            error_log("WARNING: Unable to parse ENUM definition: " . $type_def);
                        }
                    }
                    
                    // Build merged ENUM values: required + current + the incoming value
                    // CRITICAL: Use $valid_programme_types to ensure 'UG 2 years' is included
                    $required_values = $valid_programme_types;
                    $merged = [];
                    foreach ($required_values as $rv) { $merged[$rv] = true; }
                    foreach ($current_values as $cv => $_) { $merged[$cv] = true; }
                    $merged[$programme_type] = true; // ensure the incoming value is present
                    
                    // Construct ENUM SQL list with proper quoting
                    $enum_list = [];
                    foreach (array_keys($merged) as $val) {
                        $enum_list[] = "'" . mysqli_real_escape_string($conn, $val) . "'";
                    }
                    $enum_sql = 'ENUM(' . implode(',', $enum_list) . ')';
                    
                    // Temporarily disable strict mode for ALTER to avoid failures on existing bad data
                    $saved_mode_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT @@sql_mode AS mode"));
                    $saved_mode = $saved_mode_row['mode'] ?? '';
                    mysqli_query($conn, "SET SESSION sql_mode = ''");
                    
                    // First without NOT NULL, then add NOT NULL, with checks and warnings
                    $alter1 = mysqli_query($conn, "ALTER TABLE programmes MODIFY COLUMN programme_type " . $enum_sql);
                    if (!$alter1) {
                        error_log("ALTER (no NOT NULL) failed: " . mysqli_error($conn));
                    }
                    $alter2 = false;
                    if ($alter1) {
                        $alter2 = mysqli_query($conn, "ALTER TABLE programmes MODIFY COLUMN programme_type " . $enum_sql . " NOT NULL");
                        if (!$alter2) {
                            error_log("ALTER (add NOT NULL) failed: " . mysqli_error($conn));
                        }
                    }
                    
                    // Show warnings if any
                    $warn_count = mysqli_warning_count($conn);
                    if ($warn_count > 0) {
                        $wr = mysqli_query($conn, "SHOW WARNINGS");
                        if ($wr) {
                            while ($w = mysqli_fetch_assoc($wr)) {
                                error_log("ALTER TABLE warning: " . ($w['Message'] ?? ''));
                            }
                        }
                    }
                    
                    // Restore SQL mode
                    if ($saved_mode !== '') {
                        mysqli_query($conn, "SET SESSION sql_mode = '" . mysqli_real_escape_string($conn, $saved_mode) . "'");
                    }
                    
                    // Retry only if ALTER succeeded
                    if ($alter1) {
                        $stmt = mysqli_prepare($conn, $insert_query);
                        mysqli_stmt_bind_param($stmt, "issssiiiiiiii", 
                            $dept, $programme_code, $programme_name, $academic_year_for_prog, $programme_type, $intake_capacity,
                            $year_1_capacity, $year_2_capacity, $year_3_capacity, 
                            $year_4_capacity, $year_5_capacity, $year_6_capacity, $total_intake);
                        $executed = mysqli_stmt_execute($stmt);
                    }
                }
                
                if (!$executed) {
                    // Still failed
                    throw new Exception('Failed to add programme after ENUM refresh: ' . ($execute_err ?: mysqli_error($conn)));
                }
            }
            
            // Check for MySQL warnings (like invalid ENUM values) - check BEFORE getting insert_id
            $warning_count = mysqli_warning_count($conn);
            if ($warning_count > 0) {
                $warnings = [];
                $result = mysqli_query($conn, "SHOW WARNINGS");
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $warnings[] = $row['Message'];
                        error_log("MySQL Warning during INSERT: " . $row['Message'] . " (Code: " . ($row['Code'] ?? 'N/A') . ")");
                        
                        // If ENUM value is invalid, throw exception
                        if (stripos($row['Message'], 'invalid') !== false || 
                            stripos($row['Message'], 'enum') !== false ||
                            stripos($row['Message'], 'Data truncated') !== false) {
                            error_log("CRITICAL: MySQL rejected programme_type value: '" . $programme_type . "'");
                            throw new Exception('Invalid programme type value. Please contact administrator. Error: ' . $row['Message']);
                        }
                    }
                }
                if (!empty($warnings)) {
                    error_log("MySQL Warnings detected during INSERT: " . implode(', ', $warnings));
                }
            }
            
            $programme_id = mysqli_insert_id($conn);
            
            // CRITICAL: Verify the programme_type was saved correctly
            // If it wasn't saved correctly, fix it immediately
            $verify_query = "SELECT programme_type FROM programmes WHERE id = ? AND DEPT_ID = ?";
            $verify_stmt = mysqli_prepare($conn, $verify_query);
            mysqli_stmt_bind_param($verify_stmt, "ii", $programme_id, $dept);
            mysqli_stmt_execute($verify_stmt);
            $verify_result = mysqli_stmt_get_result($verify_stmt);
            $verify_row = mysqli_fetch_assoc($verify_result);
            
            if ($verify_row) {
                $saved_programme_type = $verify_row['programme_type'] ?? '';
                error_log("=== INSERT SUCCESS VERIFICATION ===");
                error_log("INSERT: Expected programme_type '" . $programme_type . "'");
                error_log("VERIFY: programme_type in database is: '" . $saved_programme_type . "'");
                
                // If MySQL saved the wrong value (e.g., defaulted to first ENUM value), fix it!
                if ($saved_programme_type !== $programme_type) {
                    error_log("CRITICAL: programme_type mismatch detected! Expected: '" . $programme_type . "', Got: '" . $saved_programme_type . "'");
                    error_log("Attempting to fix by directly updating the record...");
                    
                    // Check if the value exists in ENUM now
                    $enum_check_again = mysqli_query($conn, "SHOW COLUMNS FROM programmes WHERE Field = 'programme_type'");
                    if ($enum_check_again && $enum_row_again = mysqli_fetch_assoc($enum_check_again)) {
                        $enum_def_again = $enum_row_again['Type'] ?? '';
                        preg_match("/ENUM\((.*?)\)/i", $enum_def_again, $enum_matches_again);
                        if (isset($enum_matches_again[1])) {
                            $enum_str_again = $enum_matches_again[1];
                            $pattern_again = "'" . mysqli_real_escape_string($conn, $programme_type) . "'";
                            
                            if (stripos($enum_str_again, $pattern_again) !== false) {
                                // Value exists in ENUM, update the record directly
                                // Disable strict mode temporarily for the fix update
                                mysqli_query($conn, "SET SESSION sql_mode = ''");
                                
                                $fix_update = "UPDATE programmes SET programme_type = ? WHERE id = ? AND DEPT_ID = ?";
                                $fix_stmt = mysqli_prepare($conn, $fix_update);
                                mysqli_stmt_bind_param($fix_stmt, "sii", $programme_type, $programme_id, $dept);
                                
                                if (mysqli_stmt_execute($fix_stmt)) {
                                    error_log("SUCCESS: Fixed programme_type from '" . $saved_programme_type . "' to '" . $programme_type . "'");
                                    
                                    // Verify the fix worked
                                    $verify_fix = mysqli_query($conn, "SELECT programme_type FROM programmes WHERE id = " . (int)$programme_id);
                                    if ($verify_fix && $verify_fix_row = mysqli_fetch_assoc($verify_fix)) {
                                        $fixed_value = $verify_fix_row['programme_type'] ?? '';
                                        if ($fixed_value === $programme_type) {
                                            error_log("VERIFIED: programme_type successfully fixed to '" . $programme_type . "'");
                                        } else {
                                            error_log("WARNING: Fix update succeeded but value is still '" . $fixed_value . "'");
                                        }
                                    }
                                } else {
                                    error_log("FAILED to fix programme_type: " . mysqli_error($conn));
                                }
                                
                                // Restore SQL mode
                                if (!empty($original_sql_mode)) {
                                    mysqli_query($conn, "SET SESSION sql_mode = '" . mysqli_real_escape_string($conn, $original_sql_mode) . "'");
                                }
                            } else {
                                error_log("CRITICAL: Value '" . $programme_type . "' still not in ENUM! Cannot fix.");
                                error_log("Current ENUM: " . $enum_str_again);
                            }
                        }
                    }
                } else {
                    error_log("SUCCESS: programme_type saved correctly as '" . $programme_type . "'");
                }
            }
        }
        
        // If programme code changed during update, move/update file paths
        if ($is_update && $programme_code != $old_programme_code) {
            // Move folder if it exists (using absolute paths)
            // Use A_YEAR format: uploads/{A_YEAR}/DEPARTMENT/dept_id/Program_offered/programme_code/
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            $a_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
            $old_folder = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/Program_Offered/{$old_programme_code}/";
            $new_folder = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/";
            if (file_exists($old_folder) && is_dir($old_folder)) {
                if (!file_exists($new_folder)) {
                    mkdir($new_folder, 0777, true);
                    chmod($new_folder, 0777);
                }
                // Move files
                $files = glob($old_folder . "*");
                foreach ($files as $file) {
                    if (is_file($file)) {
                        rename($file, $new_folder . basename($file));
                    }
                }
                // Remove old folder if empty
                if (count(scandir($old_folder)) == 2) {
                    rmdir($old_folder);
                }
            }
            // Also handle old format for backward compatibility
            $current_month = (int)date('n');
            $a_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
            $old_folder_old = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/programmes_offered/{$old_programme_code}/";
            $new_folder_old = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/programmes_offered/{$programme_code}/";
            if (file_exists($old_folder_old) && is_dir($old_folder_old)) {
                if (!file_exists($new_folder_old)) {
                    mkdir($new_folder_old, 0777, true);
                    chmod($new_folder_old, 0777);
                }
                // Move files
                $files = glob($old_folder_old . "*");
                foreach ($files as $file) {
                    if (is_file($file)) {
                        rename($file, $new_folder_old . basename($file));
                    }
                }
                // Remove old folder if empty
                if (count(scandir($old_folder_old)) == 2) {
                    rmdir($old_folder_old);
                }
            }
            // Update file paths in database
            $update_path_query = "UPDATE supporting_documents 
                SET program_id = ?, file_path = REPLACE(file_path, '{$old_programme_code}', '{$programme_code}')
                WHERE dept_id = ? AND page_section = 'programmes_offered' AND program_id = ?";
            $stmt = mysqli_prepare($conn, $update_path_query);
            mysqli_stmt_bind_param($stmt, "sis", $programme_code, $dept, $old_programme_code);
            mysqli_stmt_execute($stmt);
        }
        $document_uploaded = false;
        $upload_result = null; // Initialize upload result
        
        // Handle PDF upload if file was uploaded with form submission - route through common_upload_handler
        if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
            // CRITICAL: Rename pdf_file to document for common_upload_handler.php
            // common_upload_handler expects $_FILES['document']
            $_FILES['document'] = $_FILES['pdf_file'];
            
            // Route through common_upload_handler.php
            require_once(__DIR__ . '/common_upload_handler.php');
            
            // Set global variables for common_upload_handler.php
            $GLOBALS['dept_id'] = $dept;
            $GLOBALS['A_YEAR'] = $A_YEAR;
            
            // Use common upload handler with program-specific directory
            // Structure: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/Program_offered/{programme_code}/FILENAME.pdf
            // IMPORTANT: Use dept_id (DEPT_ID), not dept_code, and use programme_code for unique folder per program
            // Use 'Program_offered' (capital P, no 's') to match user requirement
            // Use A_YEAR format (e.g., "2024-2025") for folder structure
            
            $result = handleDocumentUpload('programmes_offered', 'Programme Documentation', [
                'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/",
                'max_size' => 5,
                'document_title' => "Programme Documentation - {$programme_name}",
                'srno' => 1, // Use 1 as default serial for programmes
                'file_id' => 'programme_doc',
                'program_id' => $programme_code
            ]);
                
            if ($result && isset($result['success']) && $result['success']) {
                $document_uploaded = true;
                // Store upload result for response
                $upload_result = $result;
            }
            
            // Restore original $_FILES structure
            unset($_FILES['document']);
        }
        
        ob_clean();
        $message = $is_update ? 'Programme updated successfully!' : 'Programme added successfully!';
        $response_data = [
            'success' => true,
            'message' => $message,
            'programme_id' => $programme_id,
            'action' => $is_update ? 'update' : 'add'
        ];
        
        // If document was uploaded, include file info for view/delete buttons
        if ($document_uploaded && isset($upload_result)) {
            $response_data['document_uploaded'] = true;
            if (isset($upload_result['file_path'])) {
                // Convert file path to web-accessible path for view button
                $web_path = $upload_result['file_path'];
                $project_root = dirname(__DIR__);
                
                // Normalize path separators
                $web_path = str_replace('\\', '/', $web_path);
                
                // Convert absolute to relative
                if (strpos($web_path, $project_root) === 0) {
                    $web_path = str_replace($project_root . '/', '', $web_path);
                    $web_path = str_replace($project_root . '\\', '', $web_path);
                }
                
                // Remove leading slashes/dots
                $web_path = ltrim($web_path, './\\/');
                
                // Add ../ prefix if needed
                if (strpos($web_path, 'uploads/') === 0) {
                    $web_path = '../' . $web_path;
                } elseif (strpos($web_path, '../') !== 0 && strpos($web_path, 'http') === false) {
                    // Reconstruct path with programme_code folder
                    $filename = basename($web_path);
                    $current_year = (int)date('Y');
                    $current_month = (int)date('n');
                    $a_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
                    $web_path = '../uploads/' . $a_year . '/DEPARTMENT/' . $dept . '/Program_Offered/' . $programme_code . '/' . $filename;
                }
                
                $response_data['file_path'] = $web_path;
                $response_data['file_name'] = $upload_result['file_name'] ?? basename($web_path);
                $response_data['file_size'] = $upload_result['file_size'] ?? 0;
            }
            $message .= ' Document uploaded.';
            $response_data['message'] = $message;
        }
        
        echo json_encode($response_data);
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

// Handle AJAX requests for deleting programmes - BEFORE unified_header
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_programme') {
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
                error_log('[Programmes Delete] CSRF validation failed');
                throw new Exception('Security token validation failed. Please refresh the page and try again.');
            }
        }
        
        // Get department ID from session (already verified in session.php)
        $dept = $userInfo['DEPT_ID'];
        
        if (!$dept) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }
        $programme_id = (int)($_POST['programme_id'] ?? 0);
        
        if ($programme_id <= 0) {
            throw new Exception('Invalid programme ID.');
        }
        
        // Get programme details before deleting
        $get_programme_query = "SELECT programme_name, programme_code FROM programmes WHERE id = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $get_programme_query);
        mysqli_stmt_bind_param($stmt, "ii", $programme_id, $dept);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (!$result || mysqli_num_rows($result) == 0) {
            throw new Exception('Programme not found or you don\'t have permission to delete it.');
        }
        
        $programme = mysqli_fetch_assoc($result);
        
            // Delete from programmes table
        $delete_query = "DELETE FROM programmes WHERE id = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($stmt, "ii", $programme_id, $dept);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to delete programme: ' . mysqli_error($conn));
        }
        
        // Delete associated documents
                $academic_year = date('Y') . '-' . (date('Y') + 1);
        $programme_code = $programme['programme_code'];
        
        // Get file paths before deleting - without academic_year restriction
        $get_docs_query = "SELECT file_path FROM supporting_documents 
            WHERE dept_id = ? AND page_section = 'programmes_offered' 
            AND program_id = ? AND status = 'active'";
        
        $stmt = mysqli_prepare($conn, $get_docs_query);
        mysqli_stmt_bind_param($stmt, "is", $dept, $programme_code);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        // Delete physical files (handle both ../ and relative paths, and both old/new folder structures)
        while ($row = mysqli_fetch_assoc($result)) {
            if ($row['file_path']) {
                // Try absolute path first
                $file_physical = $row['file_path'];
                if (strpos($file_physical, '../') === 0) {
                    $file_physical = dirname(__DIR__) . '/' . str_replace('../', '', $file_physical);
                } elseif (strpos($file_physical, 'uploads/') === 0) {
                    $file_physical = dirname(__DIR__) . '/' . $file_physical;
                }
                
                if (file_exists($file_physical)) {
                    unlink($file_physical);
                } else {
                    // Try with programme code folder structure (new structure)
                    // Use Program_offered with A_YEAR format
                    $current_year = (int)date('Y');
                    $current_month = (int)date('n');
                    $a_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
                    $file_with_prog = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/" . basename($row['file_path']);
                    if (file_exists($file_with_prog)) {
                        unlink($file_with_prog);
                    }
                    // Also try with A_YEAR format for backward compatibility
                    $current_month = (int)date('n');
                    $a_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
                    $file_with_prog_old = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/programmes_offered/{$programme_code}/" . basename($row['file_path']);
                    if (file_exists($file_with_prog_old)) {
                        unlink($file_with_prog_old);
                    }
                }
            }
        }
        
        // Also try to delete the entire programme code folder if it's empty (new structure)
        // Use Program_offered with A_YEAR format
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        $a_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
        $programme_folder = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/Program_Offered/{$programme_code}/";
        if (file_exists($programme_folder) && is_dir($programme_folder)) {
            // Check if folder is empty
            if (count(scandir($programme_folder)) == 2) { // . and .. only
                rmdir($programme_folder);
            }
        }
        // Also try with A_YEAR format for backward compatibility
        $current_month = (int)date('n');
        $a_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
        $programme_folder_old = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept}/programmes_offered/{$programme_code}/";
        if (file_exists($programme_folder_old) && is_dir($programme_folder_old)) {
            // Check if folder is empty
            if (count(scandir($programme_folder_old)) == 2) { // . and .. only
                rmdir($programme_folder_old);
            }
        }
        
        // Delete from supporting_documents table - without academic_year restriction
        $delete_docs_query = "DELETE FROM supporting_documents 
            WHERE dept_id = ? AND page_section = 'programmes_offered' 
            AND program_id = ?";
        
        $stmt = mysqli_prepare($conn, $delete_docs_query);
        mysqli_stmt_bind_param($stmt, "is", $dept, $programme_code);
        mysqli_stmt_execute($stmt);
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Programme and associated documents deleted successfully!'
        ]);
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

// Now require unified_header.php for normal page display
require('unified_header.php');

// Get department ID from session (already verified in session.php)
$dept = $userInfo['DEPT_ID'];

if (!$dept) {
    die('Department ID not found. Please contact administrator.');
}
    
// Create table if not exists
$createTable = "CREATE TABLE IF NOT EXISTS programmes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    DEPT_ID INT NOT NULL,
    programme_code VARCHAR(50) NOT NULL,
    programme_name VARCHAR(255) NOT NULL,
    programme_type ENUM('Certificate course', 'UG Diploma 1 year', 'UG Diploma 2 years', 'UG Diploma 3 years', 'UG 2 years', 'UG 3 years', 'UG 4 years', 'UG 5 years', 'UG 6 years', 'PG Diploma 1 year', 'PG Diploma 2 years', 'PG 1 year', 'PG 2 years', 'PG 3 years', 'Advanced Diploma 1 year', 'Advanced Diploma 2 years', 'PG Integrated 5 years', 'PG Integrated 6 years', 'PhD') NOT NULL,
    intake_capacity INT NOT NULL,
    year_1_capacity INT DEFAULT 0,
    year_2_capacity INT DEFAULT 0,
    year_3_capacity INT DEFAULT 0,
    year_4_capacity INT DEFAULT 0,
    year_5_capacity INT DEFAULT 0,
    year_6_capacity INT DEFAULT 0,
    total_intake INT NOT NULL DEFAULT 0,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_dept_programme_code (DEPT_ID, programme_code),
    INDEX idx_dept_id (DEPT_ID),
    INDEX idx_programme_code (programme_code)
)";
mysqli_query($conn, $createTable);

// CRITICAL: Ensure programme_type column exists and has ALL correct ENUM values
// This runs on EVERY page load to ensure the column is always correct
$expected_enum = "ENUM('Certificate course', 'UG Diploma 1 year', 'UG Diploma 2 years', 'UG Diploma 3 years', 'UG 2 years', 'UG 3 years', 'UG 4 years', 'UG 5 years', 'UG 6 years', 'PG Diploma 1 year', 'PG Diploma 2 years', 'PG 1 year', 'PG 2 years', 'PG 3 years', 'Advanced Diploma 1 year', 'Advanced Diploma 2 years', 'PG Integrated 5 years', 'PG Integrated 6 years', 'PhD')";

// ALL 19 required programme types
$all_programme_types_list = [
    'Certificate course',
    'UG Diploma 1 year',
    'UG Diploma 2 years', 
    'UG Diploma 3 years',
    'UG 2 years',
    'UG 3 years',
    'UG 4 years',
    'UG 5 years',
    'UG 6 years',
    'PG Diploma 1 year',
    'PG Diploma 2 years',
    'PG 1 year',
    'PG 2 years',
    'PG 3 years',
    'Advanced Diploma 1 year',
    'Advanced Diploma 2 years',
    'PG Integrated 5 years',
    'PG Integrated 6 years',
    'PhD'
];

$check_column = "SHOW COLUMNS FROM programmes WHERE Field = 'programme_type'";
$column_result = mysqli_query($conn, $check_column);

if (!$column_result || mysqli_num_rows($column_result) == 0) {
    // Column doesn't exist - add it
    $add_column = "ALTER TABLE programmes ADD COLUMN programme_type " . $expected_enum . " NOT NULL AFTER programme_name";
    
    if (mysqli_query($conn, $add_column)) {
    } else {
    }
} else {
    // Column exists - check if ENUM values match
    $column_info = mysqli_fetch_assoc($column_result);
    $current_type = $column_info['Type'] ?? '';
    
    // Extract current ENUM values
    preg_match("/ENUM\((.*?)\)/i", $current_type, $matches);
    $current_enum_values = isset($matches[1]) ? $matches[1] : '';
    
    // Check if ALL 19 required values are in the ENUM (check EVERY single value)
    $missing_values = [];
    foreach ($all_programme_types_list as $req_value) {
        // Check with quotes since ENUM values are stored with quotes
        $quoted_value = "'" . $req_value . "'";
        if (stripos($current_enum_values, $quoted_value) === false) {
            $missing_values[] = $req_value;
        }
    }
    
    // ALWAYS force update ENUM on page load to ensure it has ALL 19 values
    // This prevents any edge cases where values might be missing
    if (!empty($missing_values)) {
        error_log("CRITICAL: programme_type ENUM column missing " . count($missing_values) . " required values: " . implode(', ', $missing_values));
    } else {
        error_log("ENUM check: All values appear present, but forcing update to ensure consistency...");
    }
    
    error_log("Current ENUM: " . $current_type);
    error_log("Forcing ENUM column update to include ALL 19 required values...");
    
    // Save and disable SQL mode for ENUM update
    $page_sql_mode_query = mysqli_query($conn, "SELECT @@sql_mode as mode");
    $page_sql_mode_row = mysqli_fetch_assoc($page_sql_mode_query);
    $page_original_sql_mode = $page_sql_mode_row['mode'] ?? '';
    mysqli_query($conn, "SET SESSION sql_mode = ''");
    
    // Step 1: Find and fix ALL invalid data
    $find_all_invalid = "SELECT id, programme_type FROM programmes WHERE programme_type IS NOT NULL AND programme_type != ''";
    $all_invalid_result = mysqli_query($conn, $find_all_invalid);
    if ($all_invalid_result) {
        $fixed_count = 0;
        while ($inv_row = mysqli_fetch_assoc($all_invalid_result)) {
            $pt_val = $inv_row['programme_type'];
            if (!in_array($pt_val, $all_programme_types_list, true)) {
                mysqli_query($conn, "UPDATE programmes SET programme_type = 'Certificate course' WHERE id = " . (int)$inv_row['id']);
                error_log("Fixed invalid programme_type '" . $pt_val . "' for programme_id " . $inv_row['id']);
                $fixed_count++;
            }
        }
        if ($fixed_count > 0) {
            error_log("Fixed $fixed_count programme(s) with invalid programme_type values on page load.");
        }
        mysqli_free_result($all_invalid_result); // CRITICAL: Free result
    }
    
    // Step 2: Fix NULL/empty
    mysqli_query($conn, "UPDATE programmes SET programme_type = 'Certificate course' WHERE programme_type IS NULL OR programme_type = ''");
    
    // Step 3: Update ENUM - try multiple approaches to ensure it works
    // CRITICAL: Always attempt update even if check says values are present (handles edge cases)
    $enum_updated = false;
    
    // Approach 1: Update WITHOUT NOT NULL first (safer for MySQL strict mode)
    $page_temp_enum = str_replace(" NOT NULL", "", $expected_enum);
    $page_temp_modify = "ALTER TABLE programmes MODIFY COLUMN programme_type " . $page_temp_enum;
    
    if (mysqli_query($conn, $page_temp_modify)) {
        error_log("Page load Step 1: ENUM updated without NOT NULL");
        
        // Step 4: Add NOT NULL back
        $page_final_modify = "ALTER TABLE programmes MODIFY COLUMN programme_type " . $expected_enum . " NOT NULL";
        if (mysqli_query($conn, $page_final_modify)) {
            error_log("Page load Step 2: NOT NULL added back - ENUM fully updated with all 19 values!");
            $enum_updated = true;
        } else {
            $step2_error = mysqli_error($conn);
            error_log("Page load Step 2 failed: " . $step2_error);
            // CRITICAL: Try alternative approach if Step 2 fails
            $alt_modify = "ALTER TABLE programmes MODIFY programme_type " . $expected_enum . " NOT NULL";
            if (mysqli_query($conn, $alt_modify)) {
                error_log("Page load Step 2 (alternative): ENUM updated successfully!");
                $enum_updated = true;
            } else {
                error_log("Page load Step 2 (alternative) also failed: " . mysqli_error($conn));
            }
        }
    } else {
        $page_modify_error = mysqli_error($conn);
        error_log("Page load ENUM update (Step 1) failed: " . $page_modify_error);
    }
    
    // Approach 2: If Approach 1 failed, try direct update
    if (!$enum_updated) {
        $direct_modify = "ALTER TABLE programmes MODIFY programme_type " . $expected_enum . " NOT NULL";
        if (mysqli_query($conn, $direct_modify)) {
            error_log("Page load (direct update): ENUM updated successfully!");
            $enum_updated = true;
        } else {
            $direct_error = mysqli_error($conn);
            error_log("Page load (direct update) failed: " . $direct_error);
            
            // Approach 3: Try with CHANGE instead of MODIFY
            $change_modify = "ALTER TABLE programmes CHANGE programme_type programme_type " . $expected_enum . " NOT NULL";
            if (mysqli_query($conn, $change_modify)) {
                error_log("Page load (CHANGE method): ENUM updated successfully!");
                $enum_updated = true;
            } else {
                error_log("Page load (CHANGE method) also failed: " . mysqli_error($conn));
            }
        }
    }
    
    // Step 5: Verify ALL 19 values are present
    $page_verify = mysqli_query($conn, $check_column);
    if ($page_verify && $page_verify_row = mysqli_fetch_assoc($page_verify)) {
        $page_verify_type = $page_verify_row['Type'] ?? '';
        preg_match("/ENUM\((.*?)\)/i", $page_verify_type, $page_verify_matches);
        if (isset($page_verify_matches[1])) {
            $page_verify_enum = $page_verify_matches[1];
            $missing_on_page_load = [];
            foreach ($all_programme_types_list as $check_val) {
                $check_quoted = "'" . $check_val . "'";
                if (stripos($page_verify_enum, $check_quoted) === false) {
                    $missing_on_page_load[] = $check_val;
                }
            }
            if (!empty($missing_on_page_load)) {
                error_log("CRITICAL: Still missing values after page load update: " . implode(', ', $missing_on_page_load));
                error_log("Current ENUM: " . $page_verify_enum);
                // CRITICAL: Force one more update attempt if values are still missing
                $force_final_update = "ALTER TABLE programmes MODIFY programme_type " . $expected_enum . " NOT NULL";
                if (mysqli_query($conn, $force_final_update)) {
                    error_log("Final force update succeeded - ENUM should now have all 19 values");
                } else {
                    error_log("Final force update failed: " . mysqli_error($conn));
                }
            } else {
                error_log("VERIFIED: All 19 programme types confirmed present in ENUM column on page load.");
            }
        }
    }
    
    // Restore SQL mode
    if (!empty($page_original_sql_mode)) {
        mysqli_query($conn, "SET SESSION sql_mode = '" . mysqli_real_escape_string($conn, $page_original_sql_mode) . "'");
    }
}

// Fix any existing records that have NULL or empty programme_type
// This handles cases where data was inserted before the ENUM was fixed
$fix_null_types = "UPDATE programmes SET programme_type = 'Certificate course' 
    WHERE (programme_type IS NULL OR programme_type = '' OR programme_type = '0')
    AND DEPT_ID = ?";
$fix_stmt = mysqli_prepare($conn, $fix_null_types);
mysqli_stmt_bind_param($fix_stmt, "i", $dept);
if (mysqli_stmt_execute($fix_stmt)) {
    $affected = mysqli_stmt_affected_rows($fix_stmt);
    if ($affected > 0) {
        error_log("Fixed $affected programme(s) with NULL/empty programme_type for dept $dept");
    }
}

// Debug: Check what programme_type values exist in database for this department
$debug_types_query = "SELECT DISTINCT programme_type, COUNT(*) as count FROM programmes WHERE DEPT_ID = ? GROUP BY programme_type";
$debug_stmt = mysqli_prepare($conn, $debug_types_query);
mysqli_stmt_bind_param($debug_stmt, "i", $dept);
mysqli_stmt_execute($debug_stmt);
$debug_result = mysqli_stmt_get_result($debug_stmt);
$type_counts = [];
if ($debug_result) {
    while ($debug_row = mysqli_fetch_assoc($debug_result)) {
        $type_counts[] = $debug_row['programme_type'] . ' (' . $debug_row['count'] . ')';
    }
    // CRITICAL: Free result set after loop
    mysqli_free_result($debug_result);
}
if (!empty($type_counts)) {
    error_log("Current programme_type values in database for dept $dept: " . implode(', ', $type_counts));
} else {
    error_log("No programmes found for dept $dept");
}
mysqli_stmt_close($debug_stmt);

// Fetch programmes for the current department - explicitly select programme_type to ensure it's retrieved
$programmes_query = "SELECT id, DEPT_ID, programme_code, programme_name, 
    COALESCE(programme_type, '') as programme_type, 
    intake_capacity,
    year_1_capacity, year_2_capacity, year_3_capacity, year_4_capacity, year_5_capacity, year_6_capacity, 
    total_intake, upload_date 
    FROM programmes WHERE DEPT_ID = ? ORDER BY upload_date DESC";
$stmt = mysqli_prepare($conn, $programmes_query);
mysqli_stmt_bind_param($stmt, "i", $dept);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$programmes = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Ensure programme_type is properly set (handle NULL values and ensure proper case)
        if (empty($row['programme_type']) || $row['programme_type'] === null) {
            $row['programme_type'] = '';
        } else {
            $row['programme_type'] = trim($row['programme_type']);
        }
        $programmes[] = $row;
    }
    // CRITICAL: Free result set after loop
    mysqli_free_result($result);
}
mysqli_stmt_close($stmt);

// Get total counts for the current department
$counts_query = "SELECT programme_type, COUNT(*) as count, SUM(total_intake) as total_intake 
    FROM programmes WHERE DEPT_ID = ? GROUP BY programme_type";
$stmt = mysqli_prepare($conn, $counts_query);
mysqli_stmt_bind_param($stmt, "i", $dept);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

    $counts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $counts[] = $row;
}

$total_programmes = count($programmes);
$total_intake = array_sum(array_column($programmes, 'total_intake'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programme Management System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            overflow: hidden;
            padding: 10px;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f8f9fa;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
        }
        
        .content {
            padding: 30px;
        }
        
        .add-form {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        label {
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }
        
        input, select {
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .programmes-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .programmes-table th,
        .programmes-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .programmes-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        
        .programmes-table tr:hover {
            background: #f8f9fa;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .pdf-link {
            color: #667eea;
            text-decoration: none;
        }
        
        .pdf-link:hover {
            text-decoration: underline;
        }
        
        /* Action Buttons Styling */
        .btn-edit-action, .btn-delete-action, .btn-view-pdf {
            padding: 6px 12px;
            font-size: 13px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .btn-edit-action {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-edit-action:hover {
            background: linear-gradient(135deg, #5568d3 0%, #653d8f 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
        }
        
        .btn-view-pdf {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .btn-view-pdf:hover {
            background: linear-gradient(135deg, #e081ea 0%, #e34558 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(245, 87, 108, 0.3);
        }
        
        .btn-delete-action {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: #333;
        }
        
        .btn-delete-action:hover {
            background: linear-gradient(135deg, #f85a8d 0%, #fed02e 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(250, 112, 154, 0.3);
        }
        
        .btn-edit-action i, .btn-delete-action i, .btn-view-pdf i {
            font-size: 12px;
        }
        
        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 40px;
        }
        
        .file-upload-info {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }
        
        .message-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }
        
        .message {
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInRight 0.3s ease-out;
            position: relative;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
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
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .programmes-table {
                font-size: 12px;
            }
            
            .programmes-table th,
            .programmes-table td {
                padding: 8px;
            }
        }
    </style>
    <script>
        // Define toggleIntakeFields and related functions early so they're available for inline onchange
        function toggleIntakeFields() {
            console.log('toggleIntakeFields called');
            const programmeTypeSelect = document.getElementById('programme_type');
            if (!programmeTypeSelect) {
                console.error('programme_type select element not found');
                return;
            }
            
            const programmeType = programmeTypeSelect.value;
            console.log('Programme type selected:', programmeType);
            
            const intakeFields = document.getElementById('intakeFields');
            const yearFields = document.getElementById('yearFields');
            
            if (!intakeFields) {
                console.error('intakeFields div not found');
                return;
            }
            
            if (!yearFields) {
                console.error('yearFields div not found');
                return;
            }
            
            if (!programmeType || programmeType === '') {
                intakeFields.style.display = 'none';
                return;
            }
            
            // Show intake fields
            intakeFields.style.display = 'block';
            console.log('Intake fields container displayed');
            
            // Clear existing year fields
            yearFields.innerHTML = '';
            console.log('Year fields cleared');
            
            // Determine number of years based on programme type
            let years = 0;
            switch(programmeType) {
                case 'Certificate course':
                case 'PhD':
                case 'UG Diploma 1 year':
                case 'PG Diploma 1 year':
                case 'Advanced Diploma 1 year':
                    years = 0;
                    break;
                case 'UG Diploma 2 years':
                case 'PG Diploma 2 years':
                case 'Advanced Diploma 2 years':
                case 'UG 2 years':
                    years = 2;
                    break;
                case 'UG Diploma 3 years':
                case 'UG 3 years':
                    years = 3;
                    break;
                case 'UG 4 years':
                    years = 4;
                    break;
                case 'UG 5 years':
                case 'PG Integrated 5 years':
                    years = 5;
                    break;
                case 'UG 6 years':
                case 'PG Integrated 6 years':
                    years = 6;
                    break;
                case 'PG 1 year':
                    years = 1;
                    break;
                case 'PG 2 years':
                    years = 2;
                    break;
                case 'PG 3 years':
                    years = 3;
                    break;
                default:
                    years = 1;
                    break;
            }
            
            // Generate year fields or intake strength field
            console.log('Generating fields for', years, 'years');
            
            if (years === 0) {
                // For Certificate course and PhD - show intake strength field
                console.log('Creating intake strength field');
                const intakeDiv = document.createElement('div');
                intakeDiv.className = 'form-group';
                intakeDiv.innerHTML = '<label for="intake_strength">Intake Strength <span style="color: red;">*</span></label><input type="number" id="intake_strength" name="intake_strength" min="1" max="9999" oninput="setIntakeStrength()" placeholder="Enter intake strength" required>';
                yearFields.appendChild(intakeDiv);
                console.log('Intake strength field added');
                setTimeout(setIntakeStrength, 100);
            } else {
                // Generate year fields for other programmes
                console.log('Creating', years, 'year capacity fields');
                for (let i = 1; i <= years; i++) {
                    const yearDiv = document.createElement('div');
                    yearDiv.className = 'form-group';
                    yearDiv.innerHTML = '<label for="year_' + i + '_capacity">Year ' + i + ' Capacity</label><input type="number" id="year_' + i + '_capacity" name="year_' + i + '_capacity" min="0" max="9999" oninput="calculateTotal()" placeholder="0">';
                    yearFields.appendChild(yearDiv);
                    console.log('Year', i, 'field added');
                }
                setTimeout(calculateTotal, 100);
            }
            console.log('Field generation completed');
        }
        
        // Function to set intake strength for Certificate and PhD programmes
        function setIntakeStrength() {
            const intakeStrengthInput = document.getElementById('intake_strength');
            const totalIntakeInput = document.getElementById('total_intake');
            if (intakeStrengthInput && totalIntakeInput) {
                totalIntakeInput.value = parseInt(intakeStrengthInput.value || 0);
            }
        }
        
        // Function to calculate total intake
        function calculateTotal() {
            let total = 0;
            for (let i = 1; i <= 6; i++) {
                const yearInput = document.getElementById('year_' + i + '_capacity');
                if (yearInput && yearInput.value) {
                    total += parseInt(yearInput.value) || 0;
                }
            }
            const totalIntakeInput = document.getElementById('total_intake');
            if (totalIntakeInput) {
                totalIntakeInput.value = total;
            }
        }
    </script>
</head>
    <div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
        <div class="main-content-area">
            <div class="page-header">
                <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-graduation-cap me-3"></i>Manage Certificate, Diploma, UG & PG Programmes
                        </h1>
                        <p class="page-subtitle">Add, edit, and manage all academic programmes offered by your department</p>
                    </div>
                    <a href="export_page_pdf.php?page=Programmes_Offered" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                        <i class="fas fa-file-pdf"></i> Download as PDF
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <!-- Message Container -->
                    <div id="messageContainer" class="message-container"></div>
        
        <div class="stats">
            <div class="stat-card">
                            <div class="stat-number"><?php echo count($programmes); ?></div>
                <div class="stat-label">Total Programmes</div>
            </div>
            <div class="stat-card">
                            <div class="stat-number"><?php echo array_sum(array_column($programmes, 'total_intake')); ?></div>
                <div class="stat-label">Total Intake Capacity</div>
            </div>
            <?php foreach($counts as $count): ?>
            <div class="stat-card">
                <div class="stat-number"><?php echo $count['count']; ?></div>
                <div class="stat-label"><?php echo $count['programme_type']; ?> Programmes</div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="content">
            <div class="add-form">
                <h3 id="formTitle" style="margin-bottom: 20px; color: #333;">Add New Programme</h3>
                
                            <form id="programmeForm" enctype="multipart/form-data">
                                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    <input type="hidden" id="programme_id" name="programme_id" value="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="programme_code">Programme Code *</label>
                            <input type="text" id="programme_code" name="programme_code" required maxlength="50" placeholder="e.g., CS101, ME201">
                        </div>
                        
                        <div class="form-group">
                            <label for="programme_name">Programme Name *</label>
                            <input type="text" id="programme_name" name="programme_name" required maxlength="255" placeholder="e.g., Bachelor of Computer Science">
                        </div>
                        
                        <div class="form-group">
                            <label for="programme_type">Programme Type *</label>
                                        <select id="programme_type" name="programme_type" required onchange="toggleIntakeFields()">
                                 <option value="">Select Type</option>
                                 <option value="Certificate course">Certificate course</option>
                                 <option value="UG Diploma 1 year">UG Diploma 1 year</option>
                                 <option value="UG Diploma 2 years">UG Diploma 2 years</option>
                                 <option value="UG Diploma 3 years">UG Diploma 3 years</option>
                                 <option value="UG 2 years">UG 2 years</option>
                                 <option value="UG 3 years">UG 3 years</option>
                                 <option value="UG 4 years">UG 4 years</option>
                                 <option value="UG 5 years">UG 5 years</option>
                                 <option value="UG 6 years">UG 6 years</option>
                                 <option value="PG Diploma 1 year">PG Diploma 1 year</option>
                                 <option value="PG Diploma 2 years">PG Diploma 2 years</option>
                                 <option value="PG 1 year">PG 1 year</option>
                                 <option value="PG 2 years">PG 2 years</option>
                                 <option value="PG 3 years">PG 3 years</option>
                                 <option value="Advanced Diploma 1 year">Advanced Diploma 1 year</option>
                                 <option value="Advanced Diploma 2 years">Advanced Diploma 2 years</option>
                                 <option value="PG Integrated 5 years">PG Integrated 5 years</option>
                                 <option value="PG Integrated 6 years">PG Integrated 6 years</option>
                                 <option value="PhD">PhD</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Dynamic Intake Fields -->
                    <div id="intakeFields" style="display: none;">
                        <h4 style="margin: 20px 0 15px 0; color: #333;">Year-wise Intake Capacity</h4>
                        <div class="form-row" id="yearFields">
                            <!-- Year fields will be dynamically generated here -->
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="total_intake">Total Intake (Auto-calculated)</label>
                                <input type="number" id="total_intake" name="total_intake" readonly style="background-color: #f8f9fa; color: #667eea; font-weight: bold;">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                                        <label for="pdf_file" class="form-label fw-bold mb-3">Programme Documentation (Optional)</label>
                                        <div class="alert alert-info" role="alert">
                                            <strong>Note:</strong> Select a PDF document (max 5MB). The document will be uploaded automatically when you click "Add Programme". No need to upload separately.
                                        </div>
                                            <input type="file" id="pdf_file" name="pdf_file" accept=".pdf" class="form-control" onchange="validatePDFFile(this)">
                                        <div id="pdf_file_status" class="mt-2 text-muted"><small>File will be uploaded when you submit the form</small></div>
                                        <div id="existing_pdf_info" style="margin-top: 10px; display: none;">
                                            <!-- Existing PDF info will be shown here when editing -->
                                        </div>
                        </div>
                    </div>
                    
                    <button type="submit" id="submitBtn" class="btn">Add Programme</button>
                    <button type="button" id="cancelEditBtn" class="btn" style="background: #6c757d; display: none; margin-left: 10px;" onclick="cancelEdit()">Cancel Edit</button>
                </form>
            </div>
            
            <h3 style="margin-bottom: 20px; color: #333;">All Programmes</h3>
            
            <?php if (empty($programmes)): ?>
                <div class="no-data">No programmes added yet. Use the form above to add your first programme.</div>
            <?php else: ?>
                            <table class="programmes-table" id="programmesTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Programme Name</th>
                            <th>Type</th>
                            <th>Total Intake</th>
                            <th>Year-wise Breakdown</th>
                            <th>Added Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programmes as $programme): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($programme['programme_code']); ?></td>
                            <td><?php echo htmlspecialchars($programme['programme_name']); ?></td>
                            <td><?php 
                                // Get programme_type - check both lowercase and uppercase column names, and handle all variations
                                $prog_type = '';
                                if (isset($programme['programme_type'])) {
                                    $prog_type = trim($programme['programme_type']);
                                } elseif (isset($programme['PROGRAMME_TYPE'])) {
                                    $prog_type = trim($programme['PROGRAMME_TYPE']);
                                }
                                
                                // Also check with mixed case
                                if (empty($prog_type) && isset($programme['PROGRAMME_TYPE'])) {
                                    $prog_type = trim($programme['PROGRAMME_TYPE']);
                                } elseif (empty($prog_type) && isset($programme['Programme_Type'])) {
                                    $prog_type = trim($programme['Programme_Type']);
                                }
                                
                                // Debug: Log if empty (helpful for troubleshooting)
                                if (empty($prog_type)) {
                                    error_log("WARNING: programme_type is empty for programme_id: " . ($programme['id'] ?? 'unknown'));
                                    error_log("Available keys in programme array: " . implode(', ', array_keys($programme)));
                                }
                                
                                // Display programme type or "Not Set" in red
                                if (!empty($prog_type)) {
                                    echo '<span style="font-weight: 500; color: #333;">' . htmlspecialchars($prog_type) . '</span>';
                                } else {
                                    echo '<span style="color: #dc3545; font-style: italic;">Not Set - Please Edit</span>';
                                }
                            ?></td>
                            <td><?php echo htmlspecialchars($programme['total_intake']); ?></td>
                            <td>
                                <?php 
                                // Get programme_type safely (same logic as above)
                                $display_prog_type = '';
                                if (isset($programme['programme_type'])) {
                                    $display_prog_type = trim($programme['programme_type']);
                                } elseif (isset($programme['PROGRAMME_TYPE'])) {
                                    $display_prog_type = trim($programme['PROGRAMME_TYPE']);
                                } elseif (isset($programme['Programme_Type'])) {
                                    $display_prog_type = trim($programme['Programme_Type']);
                                }
                                
                                // For 1-year programmes (Certificate, PhD, and Advanced Diploma 1 year), show intake strength
                                if ($display_prog_type === 'Certificate course' || 
                                    $display_prog_type === 'PhD' || 
                                    $display_prog_type === 'UG Diploma 1 year' ||
                                    $display_prog_type === 'PG Diploma 1 year' ||
                                    $display_prog_type === 'Advanced Diploma 1 year') {
                                    echo "Intake Strength: " . ($programme['year_1_capacity'] ?? 0);
                                } else {
                                    // For multi-year programmes, show year-wise breakdown
                                    $years = [];
                                    if (($programme['year_1_capacity'] ?? 0) > 0) $years[] = "Y1: " . $programme['year_1_capacity'];
                                    if (($programme['year_2_capacity'] ?? 0) > 0) $years[] = "Y2: " . $programme['year_2_capacity'];
                                    if (($programme['year_3_capacity'] ?? 0) > 0) $years[] = "Y3: " . $programme['year_3_capacity'];
                                    if (($programme['year_4_capacity'] ?? 0) > 0) $years[] = "Y4: " . $programme['year_4_capacity'];
                                    if (($programme['year_5_capacity'] ?? 0) > 0) $years[] = "Y5: " . $programme['year_5_capacity'];
                                    if (($programme['year_6_capacity'] ?? 0) > 0) $years[] = "Y6: " . $programme['year_6_capacity'];
                                    echo !empty($years) ? implode(', ', $years) : 'N/A';
                                }
                                ?>
                            </td>
                            <td><?php echo date('d-M-Y', strtotime($programme['upload_date'])); ?></td>
                            <td style="white-space: nowrap;">
                                <?php 
                                // Check for document in unified supporting_documents table - use direct comparison
                                $prog_code = trim((string)($programme['programme_code'] ?? ''));
                                if (!empty($prog_code)) {
                                    // CRITICAL FIX: Use resolveAcademicYear() for correct academic year calculation
                                    $a_year = resolveAcademicYear();
                                    
                                    // Query for document - check current A_YEAR first, then any active document as fallback
                                    $doc_query = "SELECT file_path, file_name, id as doc_id, academic_year FROM supporting_documents 
                                        WHERE dept_id = ? AND page_section = 'programmes_offered' AND program_id = ? AND status = 'active'
                                        ORDER BY 
                                            CASE WHEN academic_year = ? THEN 0 ELSE 1 END,
                                            upload_date DESC 
                                        LIMIT 1";
                                    $stmt = mysqli_prepare($conn, $doc_query);
                                    if ($stmt) {
                                        mysqli_stmt_bind_param($stmt, "iss", $dept, $prog_code, $a_year);
                                        mysqli_stmt_execute($stmt);
                                        $doc_result = mysqli_stmt_get_result($stmt);
                                        $doc_row = mysqli_fetch_assoc($doc_result);
                                        
                                        // If no document found with current A_YEAR, try without A_YEAR filter (fallback)
                                        if (!$doc_row || empty($doc_row)) {
                                            mysqli_free_result($doc_result);
                                            mysqli_stmt_close($stmt);
                                            
                                            $doc_query2 = "SELECT file_path, file_name, id as doc_id, academic_year FROM supporting_documents 
                                                WHERE dept_id = ? AND page_section = 'programmes_offered' AND program_id = ? AND status = 'active'
                                                ORDER BY upload_date DESC LIMIT 1";
                                            $stmt2 = mysqli_prepare($conn, $doc_query2);
                                            if ($stmt2) {
                                                mysqli_stmt_bind_param($stmt2, "is", $dept, $prog_code);
                                                mysqli_stmt_execute($stmt2);
                                                $doc_result2 = mysqli_stmt_get_result($stmt2);
                                                $doc_row = mysqli_fetch_assoc($doc_result2);
                                                mysqli_free_result($doc_result2);
                                                mysqli_stmt_close($stmt2);
                                            }
                                        } else {
                                            mysqli_free_result($doc_result);
                                            mysqli_stmt_close($stmt);
                                        }
                                        
                                        $has_pdf = !empty($doc_row) && !empty($doc_row['file_path']);
                                        
                                        // Debug: Log if document not found (only log once per page load to avoid spam)
                                        if (!$has_pdf && !isset($GLOBALS['programme_doc_debug_logged'])) {
                                            error_log("[Programmes_Offered] No document found for programme_code: '$prog_code', dept_id: $dept");
                                            $GLOBALS['programme_doc_debug_logged'] = true;
                                        }
                                    } else {
                                        $has_pdf = false;
                                        $doc_row = null;
                                        if (!isset($GLOBALS['programme_doc_debug_logged'])) {
                                            error_log("[Programmes_Offered] Query prepare failed for programme_code: '$prog_code', dept_id: $dept");
                                            $GLOBALS['programme_doc_debug_logged'] = true;
                                        }
                                    }
                                } else {
                                    $has_pdf = false;
                                    $doc_row = null;
                                }
                                
                                // Additional check: If no database record but file exists in folder, still show view button
                                if (!$has_pdf && !empty($prog_code)) {
                                    // Check if file exists in the expected upload folder
                                    $project_root = dirname(__DIR__);
                                    // CRITICAL FIX: Use resolveAcademicYear() for correct academic year
                                    $a_year = resolveAcademicYear();
                                    
                                    // Try multiple path variations (NEW and OLD formats)
                                    $possible_folders = [
                                        // NEW format
                                        $project_root . "/uploads/{$a_year}/DEPARTMENT/{$dept}/Program_Offered/{$prog_code}/",
                                        $project_root . "/uploads/{$a_year}/DEPARTMENT/{$dept}/Programme_Offered/{$prog_code}/",
                                        $project_root . "/uploads/{$a_year}/DEPARTMENT/{$dept}/Program_offered/{$prog_code}/",
                                        $project_root . "/uploads/{$a_year}/DEPARTMENT/{$dept}/program_offered/{$prog_code}/",
                                        $project_root . "/uploads/{$a_year}/DEPARTMENT/{$dept}/programmes_offered/{$prog_code}/",
                                        // OLD format (department_ID instead of academic year)
                                        $project_root . "/uploads/department_{$dept}/programmes_offered/{$prog_code}/",
                                        $project_root . "/uploads/department_{$dept}/Program_Offered/{$prog_code}/",
                                        $project_root . "/uploads/department_{$dept}/program_offered/{$prog_code}/"
                                    ];
                                    
                                    foreach ($possible_folders as $folder) {
                                        $folder = str_replace('\\', '/', $folder);
                                        if (is_dir($folder)) {
                                            $files = glob($folder . "*.pdf");
                                            if (!empty($files) && is_array($files) && count($files) > 0) {
                                                // File exists in folder - set has_pdf to true and use first file
                                                $has_pdf = true;
                                                $doc_row = [
                                                    'file_path' => str_replace($project_root . '/', '', $files[0]),
                                                    'file_name' => basename($files[0]),
                                                    'doc_id' => null,
                                                    'academic_year' => $a_year
                                                ];
                                                // Normalize path
                                                $doc_row['file_path'] = str_replace('\\', '/', $doc_row['file_path']);
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                // Show view button if document exists (from database or folder check)
                                if ($has_pdf && !empty($prog_code)) {
                                    // Get file path from database row
                                    $pdf_path = $doc_row['file_path'] ?? '';
                                    
                                    if (!empty($pdf_path)) {
                                        // Normalize path separators first
                                        $pdf_path = str_replace('\\', '/', $pdf_path);
                                        
                                        // Convert absolute paths to relative web paths (handle programme_code folder structure)
                                        $project_root = dirname(__DIR__);
                                        if (strpos($pdf_path, $project_root) === 0) {
                                            // Absolute path - convert to relative
                                            $pdf_path = str_replace($project_root . '/', '', $pdf_path);
                                            $pdf_path = str_replace($project_root . '\\', '', $pdf_path);
                                        }
                                        
                                        // Remove any leading slashes or dots
                                        $pdf_path = ltrim($pdf_path, './\\/');
                                    
                                    // CRITICAL FIX: Use document's actual academic year for path reconstruction, not current year
                                    // This ensures we find documents uploaded with different academic years
                                    $doc_year = $doc_row['academic_year'] ?? resolveAcademicYear();
                                    $a_year = $doc_year;
                                    
                                    // If path starts with uploads/, ensure it has ../ prefix for web access
                                    if (strpos($pdf_path, 'uploads/') === 0) {
                                        // Check if path includes programme_code folder structure
                                        if (!preg_match('/Program_Offered\/[^\/]+\//', $pdf_path) && 
                                            !preg_match('/Program_offered\/[^\/]+\//', $pdf_path) && 
                                            !preg_match('/program_offered\/[^\/]+\//', $pdf_path) && 
                                            !preg_match('/programmes_offered\/[^\/]+\//', $pdf_path)) {
                                            // Path missing programme_code folder - reconstruct it
                                            $filename = basename($pdf_path);
                                            $pdf_path = '../uploads/' . $a_year . '/DEPARTMENT/' . $dept . '/Program_Offered/' . $prog_code . '/' . $filename;
                                        } else {
                                            // Path already has programme_code folder - just add ../ prefix
                                            $pdf_path = '../' . $pdf_path;
                                        }
                                    } elseif (strpos($pdf_path, '../') !== 0 && strpos($pdf_path, 'http') === false) {
                                        // Path doesn't start with uploads/ or ../ - reconstruct it
                                        $filename = basename($pdf_path);
                                        $pdf_path = '../uploads/' . $a_year . '/DEPARTMENT/' . $dept . '/Program_Offered/' . $prog_code . '/' . $filename;
                                    }
                                    
                                    // CRITICAL: Replace any instances of /0/ with the actual programme_code if found
                                    if (strpos($pdf_path, '/0/') !== false) {
                                        $pdf_path = str_replace('/0/', '/' . $prog_code . '/', $pdf_path);
                                    }
                                    if (strpos($pdf_path, 'programmes_offered/0') !== false) {
                                        $pdf_path = str_replace('programmes_offered/0', 'Program_Offered/' . $prog_code, $pdf_path);
                                    }
                                    if (strpos($pdf_path, 'program_offered/0') !== false) {
                                        $pdf_path = str_replace('program_offered/0', 'Program_Offered/' . $prog_code, $pdf_path);
                                    }
                                    if (strpos($pdf_path, 'Program_offered/0') !== false) {
                                        $pdf_path = str_replace('Program_offered/0', 'Program_Offered/' . $prog_code, $pdf_path);
                                    }
                                    if (strpos($pdf_path, 'Program_Offered/0') !== false) {
                                        $pdf_path = str_replace('Program_Offered/0', 'Program_Offered/' . $prog_code, $pdf_path);
                                    }
                                    } else {
                                        // PDF path is empty - hide view button
                                        $has_pdf = false;
                                    }
                                } else {
                                    $has_pdf = false;
                                }
                                ?>
                                <div style="display: flex; gap: 5px; align-items: center; flex-wrap: wrap;">
                                    <button type="button" class="btn-edit-action" onclick="editProgramme(<?php echo htmlspecialchars($programme['id']); ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if ($has_pdf && !empty($prog_code)): ?>
                                        <a href="?download_programme_doc=1&programme_code=<?php echo urlencode($prog_code); ?>" target="_blank" class="btn-view-pdf">
                                            <i class="fas fa-file-pdf"></i> View PDF
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn-delete-action" onclick="deleteProgramme(<?php echo htmlspecialchars($programme['id']); ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
            </div>
        </div>
    </div>
    <script>
        // Function to show messages
        function showMessage(message, type = 'success') {
            const container = document.getElementById('messageContainer');
            if (!container) return;
            
            // Remove any existing messages
            container.innerHTML = '';
            
            // Create message element
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            messageDiv.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="flex: 1;">
                        <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}" style="margin-right: 8px;"></i>
                        ${message}
                    </div>
                    <button type="button" onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; font-size: 18px; cursor: pointer; margin-left: 10px;">&times;</button>
                </div>
            `;
            
            container.appendChild(messageDiv);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.style.animation = 'slideOutRight 0.3s ease-in';
                    setTimeout(() => {
                        if (messageDiv.parentNode) {
                            messageDiv.remove();
                        }
                    }, 300);
                }
        }, 5000);
        }
        
        // Note: toggleIntakeFields, setIntakeStrength, and calculateTotal are defined in <head> section
        
        // PDF validation function
        function validatePDFFile(input) {
            const file = input.files[0];
            if (file) {
                const fileSize = file.size / 1024 / 1024; // Convert to MB
                const fileType = file.type;
                const fileName = file.name;
                const maxSizeMB = 5; // Maximum file size in MB
                
                // Check file type
                if (fileType !== 'application/pdf') {
                    showMessage(`❌ Invalid File Type! Please select a PDF file only. Selected file: ${fileName}`, 'error');
                    input.value = '';
                    const statusDiv = document.getElementById('pdf_file_status');
                    if (statusDiv) {
                        statusDiv.innerHTML = '<small class="text-muted">File will be uploaded when you submit the form</small>';
                        statusDiv.className = 'mt-2 text-muted';
                    }
                    return false;
                }
                
                // Check file size
                if (fileSize > maxSizeMB) {
                    showMessage(`❌ File Too Large! File size: ${fileSize.toFixed(2)} MB. Maximum allowed: ${maxSizeMB} MB. Please compress the PDF or select a smaller file.`, 'error');
                    input.value = '';
                    const statusDiv = document.getElementById('pdf_file_status');
                    if (statusDiv) {
                        statusDiv.innerHTML = '<small class="text-muted">File will be uploaded when you submit the form</small>';
                        statusDiv.className = 'mt-2 text-muted';
                    }
                    return false;
                }
                
                // File is valid - show preview and remove buttons
                const statusDiv = document.getElementById('pdf_file_status');
                if (statusDiv) {
                    // Create object URL for preview (only available before upload)
                    const fileUrl = URL.createObjectURL(file);
                    
                    // Build preview/view and remove buttons
                    const previewButton = `<a href="${fileUrl}" target="_blank" class="btn btn-sm btn-outline-primary ms-2" style="text-decoration: none;" onclick="event.stopPropagation();">
                        <i class="fas fa-eye"></i> Preview PDF
                    </a>`;
                    
                    const removeButton = `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="clearSelectedFile(this)">
                        <i class="fas fa-times"></i> Remove File
                    </button>`;
                    
                    statusDiv.innerHTML = `
                        <div class="d-flex align-items-center flex-wrap">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <div>
                                <span class="text-success">✓ File selected: ${fileName} (${fileSize.toFixed(2)} MB)</span>
                                <small class="text-muted d-block">Will be uploaded on form submission</small>
                            </div>
                            ${previewButton}
                            ${removeButton}
                        </div>
                    `;
                    statusDiv.className = 'mt-2 text-success';
                    
                    // Store the file URL for cleanup later
                    statusDiv.dataset.fileUrl = fileUrl;
                }
                return true;
            } else {
                // No file selected
                const statusDiv = document.getElementById('pdf_file_status');
                if (statusDiv) {
                    statusDiv.innerHTML = '<small class="text-muted">File will be uploaded when you submit the form</small>';
                    statusDiv.className = 'mt-2 text-muted';
                    // Clean up any previous object URL
                    if (statusDiv.dataset.fileUrl) {
                        URL.revokeObjectURL(statusDiv.dataset.fileUrl);
                        delete statusDiv.dataset.fileUrl;
                    }
                }
                return false;
            }
        }
        
        // Function to clear selected file before submit
        function clearSelectedFile(button) {
            const pdfFileInput = document.getElementById('pdf_file');
            const statusDiv = document.getElementById('pdf_file_status');
            
            if (pdfFileInput) {
                pdfFileInput.value = '';
            }
            
            if (statusDiv) {
                statusDiv.innerHTML = '<small class="text-muted">File will be uploaded when you submit the form</small>';
                statusDiv.className = 'mt-2 text-muted';
                // Clean up object URL
                if (statusDiv.dataset.fileUrl) {
                    URL.revokeObjectURL(statusDiv.dataset.fileUrl);
                    delete statusDiv.dataset.fileUrl;
                }
            }
        }
        
        // Function to edit programme
        function editProgramme(programmeId) {
            fetch(`?action=get_programme&programme_id=${programmeId}`, {
                method: 'GET'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.programme) {
                    const prog = data.programme;
                    
                    // Populate form fields
                    document.getElementById('programme_id').value = prog.id;
                    document.getElementById('programme_code').value = prog.programme_code || '';
                    document.getElementById('programme_name').value = prog.programme_name || '';
                    // Set programme type and preserve it
                    const programmeTypeSelect = document.getElementById('programme_type');
                    if (programmeTypeSelect && prog.programme_type) {
                        programmeTypeSelect.value = prog.programme_type;
                        // Force trigger change event to ensure intake fields are shown
                        programmeTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    
                    // Show intake fields and populate based on programme type
                    if (prog.programme_type) {
                        // toggleIntakeFields was already triggered by the change event above
                        
                        // Populate intake fields based on programme type
                        if (prog.programme_type === 'Certificate course' || prog.programme_type === 'PhD' || 
                            prog.programme_type === 'UG Diploma 1 year' || prog.programme_type === 'PG Diploma 1 year' || 
                            prog.programme_type === 'Advanced Diploma 1 year') {
                            // Wait a bit for fields to be created, then set intake strength
                            setTimeout(() => {
                                const intakeStrengthInput = document.getElementById('intake_strength');
                                if (intakeStrengthInput) {
                                    intakeStrengthInput.value = prog.year_1_capacity || 0;
                                    setIntakeStrength();
                                }
                            }, 300);
                        } else {
                            // Populate year capacity fields
                            setTimeout(() => {
                                for (let i = 1; i <= 6; i++) {
                                    const yearInput = document.getElementById(`year_${i}_capacity`);
                                    if (yearInput) {
                                        yearInput.value = prog[`year_${i}_capacity`] || 0;
                                    }
                                }
                                calculateTotal();
                            }, 300);
                        }
                    }
                    
                    // Load and display existing PDF if available
                    loadExistingPDF(prog.programme_code);
                    
                    // Update form title and button
                    document.getElementById('formTitle').textContent = 'Edit Programme';
                    document.getElementById('submitBtn').textContent = 'Update Programme';
                    document.getElementById('cancelEditBtn').style.display = 'inline-block';
                    
                    // Scroll to form
                    document.querySelector('.add-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    showMessage(data.message || 'Failed to load programme data.', 'error');
                }
            })
            .catch(error => {
                console.error('Edit error:', error);
                showMessage('Failed to load programme data: ' + error.message, 'error');
            });
        }
        
        // Function to cancel edit mode
        function cancelEdit() {
            // Reset form
            document.getElementById('programmeForm').reset();
            document.getElementById('programme_id').value = '';
            document.getElementById('intakeFields').style.display = 'none';
            document.getElementById('yearFields').innerHTML = '';
            document.getElementById('total_intake').value = '';
            document.getElementById('pdf_file_status').innerHTML = '<small class="text-muted">File will be uploaded when you submit the form</small>';
            document.getElementById('pdf_file_status').className = 'mt-2 text-muted';
            
            // Hide existing PDF info
            const existingPdfDiv = document.getElementById('existing_pdf_info');
            if (existingPdfDiv) {
                existingPdfDiv.innerHTML = '';
                existingPdfDiv.style.display = 'none';
            }
            
            // Reset form title and button
            document.getElementById('formTitle').textContent = 'Add New Programme';
            document.getElementById('submitBtn').textContent = 'Add Programme';
            document.getElementById('cancelEditBtn').style.display = 'none';
        }
        
        // Function to load existing PDF info in edit mode
        function loadExistingPDF(programmeCode) {
            const existingPdfDiv = document.getElementById('existing_pdf_info');
            if (!existingPdfDiv) return;
            
            // Show loading message
            existingPdfDiv.innerHTML = '<div style="padding: 10px; color: #666;"><i class="fas fa-spinner fa-spin"></i> Checking for PDF...</div>';
            existingPdfDiv.style.display = 'block';
            
            // Check for existing document - use correct endpoint
            fetch(`?check_programme_doc=1&programme_code=${encodeURIComponent(programmeCode)}`, {
                method: 'GET'
            })
            .then(async response => {
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    return await response.json();
                } else {
                    const text = await response.text();
                    console.error('Non-JSON response:', text.substring(0, 200));
                    throw new Error('Invalid response format');
                }
            })
            .then(data => {
                if (data.success && data.file_path) {
                    // Use download handler to prevent 403 errors
                    const viewUrl = `?download_programme_doc=1&programme_code=${encodeURIComponent(programmeCode)}`;
                    
                    existingPdfDiv.innerHTML = `
                        <div style="background: linear-gradient(135deg, #e7f3ff 0%, #fff5f5 100%); border: 2px solid #b3d9ff; border-radius: 6px; padding: 15px; margin-top: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                                <div style="flex: 1; min-width: 200px;">
                                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                        <i class="fas fa-file-pdf" style="color: #dc3545; font-size: 20px; margin-right: 10px;"></i>
                                        <strong style="color: #333; font-size: 14px;">Current PDF Document</strong>
                                    </div>
                                    <div style="margin-left: 30px;">
                                        <div style="color: #555; margin-bottom: 4px;">
                                            <strong>File:</strong> ${data.file_name || 'Document'}
                                        </div>
                                        <div style="color: #888; font-size: 12px;">
                                            <i class="fas fa-calendar"></i> Uploaded: ${data.upload_date || 'N/A'}
                                        </div>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <a href="${viewUrl}" target="_blank" class="btn-view-pdf" style="padding: 8px 15px; font-size: 13px; text-decoration: none;">
                                        <i class="fas fa-eye"></i> View PDF
                                    </a>
                                    <button type="button" class="btn-delete-action" style="padding: 8px 15px; font-size: 13px;" onclick="deleteProgrammePDF('${programmeCode}', this)">
                                        <i class="fas fa-trash"></i> Delete PDF
                                    </button>
                                </div>
                            </div>
                            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #d0e8ff; color: #666; font-size: 12px;">
                                <i class="fas fa-info-circle"></i> <em>You can upload a new PDF to replace this one, or delete it using the button above.</em>
                            </div>
                        </div>
                    `;
                    existingPdfDiv.style.display = 'block';
                } else {
                    // No PDF found
                    existingPdfDiv.innerHTML = `
                        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 10px; margin-top: 10px;">
                            <i class="fas fa-info-circle" style="color: #856404;"></i> <span style="color: #856404;">No PDF document uploaded for this programme yet. You can upload one below.</span>
                        </div>
                    `;
                    existingPdfDiv.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('PDF check error:', error);
                existingPdfDiv.innerHTML = `
                    <div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; padding: 10px; margin-top: 10px;">
                        <i class="fas fa-exclamation-triangle" style="color: #721c24;"></i> <span style="color: #721c24;">Unable to check PDF status. Please try again.</span>
                    </div>
                `;
                existingPdfDiv.style.display = 'block';
            });
        }
        
        // Function to delete programme PDF
        function deleteProgrammePDF(programmeCode, buttonElement) {
            if (!confirm('Are you sure you want to delete this PDF document? This action cannot be undone.')) {
                return;
            }
            
            const csrfToken = getCSRFToken();
            if (!csrfToken) {
                showMessage('Security token not found. Please refresh the page and try again.', 'error');
                return;
            }
            
            // Convert to POST request with CSRF token
            const formData = new FormData();
            formData.append('delete_programme_doc', '1');
            formData.append('programme_code', programmeCode);
            formData.append('csrf_token', csrfToken);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message || 'PDF deleted successfully!', 'success');
                    // Update the PDF info div to show "No PDF" message instead of reloading
                    const existingPdfDiv = document.getElementById('existing_pdf_info');
                    if (existingPdfDiv) {
                        existingPdfDiv.innerHTML = `
                            <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 10px; margin-top: 10px;">
                                <i class="fas fa-info-circle" style="color: #856404;"></i> <span style="color: #856404;">No PDF document uploaded for this programme yet. You can upload one below.</span>
                            </div>
                        `;
                        existingPdfDiv.style.display = 'block';
                    }
                    // Don't reload page - stay in edit mode so user can upload new PDF
                } else {
                    showMessage(data.message || 'Failed to delete PDF.', 'error');
                }
            })
            .catch(error => {
                console.error('Delete PDF error:', error);
                showMessage('Failed to delete PDF: ' + error.message, 'error');
            });
        }
        
        // Form submission handler
        document.getElementById('programmeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const programmeId = document.getElementById('programme_id').value;
            const action = programmeId ? 'update_programme' : 'add_programme';
            formData.append('action', action);
            if (programmeId) {
                formData.append('programme_id', programmeId);
            }
            
            // Explicitly ensure total_intake is included (readonly fields might not be included)
            const totalIntakeInput = document.getElementById('total_intake');
            if (totalIntakeInput) {
                const totalIntakeValue = totalIntakeInput.value || '0';
                formData.set('total_intake', totalIntakeValue);
            }
            
            // Explicitly include all year capacities
            for (let i = 1; i <= 6; i++) {
                const yearInput = document.getElementById(`year_${i}_capacity`);
                if (yearInput) {
                    formData.set(`year_${i}_capacity`, yearInput.value || '0');
                }
            }
            
            // Explicitly include intake_strength if it exists
            const intakeStrengthInput = document.getElementById('intake_strength');
            if (intakeStrengthInput) {
                formData.set('intake_strength', intakeStrengthInput.value || '0');
            }
            
            // Ensure CSRF token is included
            if (!formData.has('csrf_token')) {
                const csrfToken = getCSRFToken();
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                } else {
                    showMessage('Security token not found. Please refresh the page and try again.', 'error');
                    return;
                }
            }
            
            // CRITICAL: Explicitly ensure programme_type is included
            // Get the value directly from the select element, not from FormData
            const programmeTypeSelect = document.getElementById('programme_type');
            if (programmeTypeSelect) {
                // Get the selected option value directly
                const selectedOption = programmeTypeSelect.options[programmeTypeSelect.selectedIndex];
                const programmeTypeValue = selectedOption ? selectedOption.value : (programmeTypeSelect.value || '');
                
                // Remove any existing programme_type from FormData and set the correct one
                formData.delete('programme_type');
                formData.set('programme_type', programmeTypeValue);
                
                console.log('DEBUG: programme_type value being sent = "' + programmeTypeValue + '"');
                console.log('DEBUG: dropdown selectedIndex = ' + programmeTypeSelect.selectedIndex);
                console.log('DEBUG: dropdown value = "' + programmeTypeSelect.value + '"');
                
                if (!programmeTypeValue || programmeTypeValue.trim() === '') {
                    showMessage('Please select a programme type before submitting.', 'error');
                    return;
                }
            } else {
                console.error('ERROR: programme_type select element not found!');
                showMessage('Programme type field not found. Please refresh the page.', 'error');
                return;
            }
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    return await response.json();
                } else {
                    const text = await response.text();
                    console.error('Non-JSON response:', text.substring(0, 200));
                    throw new Error('Invalid response format');
                }
            })
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    
                    // Get programme code to reload PDF info if in edit mode
                    const programmeId = document.getElementById('programme_id').value;
                    const programmeCode = document.getElementById('programme_code').value;
                    
                    // If document was uploaded, show view/delete buttons immediately
                    const pdfFileStatus = document.getElementById('pdf_file_status');
                    if (data.document_uploaded && data.file_path && pdfFileStatus) {
                        // Build file info
                        const fileInfo = data.file_name ? `<small class="text-muted d-block">📄 ${data.file_name}</small>` : '';
                        const fileSize = data.file_size ? `<small class="text-muted d-block">📏 Size: ${(data.file_size / (1024 * 1024)).toFixed(2)} MB</small>` : '';
                        
                        // Build view and delete buttons - use download handler to prevent 403 errors
                        const viewUrl = `?download_programme_doc=1&programme_code=${encodeURIComponent(programmeCode)}`;
                        const viewButton = `<a href="${viewUrl}" target="_blank" class="btn btn-sm btn-outline-primary ms-2" style="text-decoration: none;">
                            <i class="fas fa-eye"></i> View PDF
                        </a>`;
                        
                        const deleteButton = `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteProgrammePDF('${programmeCode}', this)">
                            <i class="fas fa-trash"></i> Delete PDF
                        </button>`;
                        
                        // Show success message with view/delete buttons
                        pdfFileStatus.innerHTML = `
                            <div class="d-flex align-items-center flex-wrap">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div>
                                    <span class="text-success">✅ Document uploaded successfully</span>
                                    ${fileInfo}
                                    ${fileSize}
                                </div>
                                ${viewButton}
                                ${deleteButton}
                            </div>
                        `;
                        pdfFileStatus.className = 'mt-2 text-success';
                        
                        // Clear the file input
                        const pdfFileInput = document.getElementById('pdf_file');
                        if (pdfFileInput) {
                            pdfFileInput.value = '';
                        }
                    }
                    
                    // Reload page after successful edit to show updated view button in table
                    if (programmeId && data.action === 'update') {
                        // Small delay to ensure database is updated
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                        return; // Exit early to prevent form reset
                    }
                    
                    // If document was uploaded during edit, reload PDF info
                    if (programmeId && data.document_uploaded && programmeCode) {
                        // Reload existing PDF info to show view/delete buttons
                        loadExistingPDF(programmeCode);
                    }
                    
                    // Also reset the form inputs for immediate visual feedback
                    if (!programmeId) {
                        // New programme - clear form immediately for visual feedback (except PDF status which we just updated)
                        document.getElementById('programmeForm').reset();
                        document.getElementById('programme_id').value = '';
                        document.getElementById('intakeFields').style.display = 'none';
                        document.getElementById('yearFields').innerHTML = '';
                        document.getElementById('total_intake').value = '';
                        const pdfFileInput = document.getElementById('pdf_file');
                        if (pdfFileInput) {
                            pdfFileInput.value = '';
                        }
                        // Only reset status if document wasn't uploaded
                        if (!data.document_uploaded && pdfFileStatus) {
                            pdfFileStatus.innerHTML = '<small class="text-muted">File will be uploaded when you submit the form</small>';
                            pdfFileStatus.className = 'mt-2 text-muted';
                        }
                    }
                    
                    // Page reload is already handled above for updates, so skip duplicate reload
                    if (!programmeId && !data.action) {
                        // New programme - reload page to show the new programme in the list
                        // Reduced delay for faster refresh (800ms = 0.8 seconds)
                        setTimeout(() => {
                            window.location.reload();
                        }, 800);
                    }
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Submit error:', error);
                showMessage('Submit failed: ' + error.message, 'error');
            });
        });
        
        // Helper function to get CSRF token
        function getCSRFToken() {
            // Try meta tag first (most reliable, set by unified_header.php)
            const metaToken = document.querySelector('meta[name="csrf-token"]');
            if (metaToken && metaToken.content && metaToken.content.trim() !== '') {
                return metaToken.content.trim();
            }
            
            // Fallback to form input
            const formToken = document.querySelector('input[name="csrf_token"]');
            if (formToken && formToken.value && formToken.value.trim() !== '') {
                return formToken.value.trim();
            }
            
            return null;
        }
        
        // Delete programme function
        function deleteProgramme(id) {
            if (!confirm('Are you sure you want to delete this programme? This action cannot be undone.')) {
                return;
            }
            
            const csrfToken = getCSRFToken();
            if (!csrfToken) {
                showMessage('Security token not found. Please refresh the page and try again.', 'error');
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_programme&programme_id=${id}&csrf_token=${encodeURIComponent(csrfToken)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                showMessage('Delete failed: ' + error.message, 'error');
            });
        }
        
        // Initialize on page load - ensure function is available
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing programme form');
            const programmeType = document.getElementById('programme_type');
            const intakeFields = document.getElementById('intakeFields');
            const yearFields = document.getElementById('yearFields');
            
            console.log('Elements found:', {
                programmeType: !!programmeType,
                intakeFields: !!intakeFields,
                yearFields: !!yearFields
            });
            
            if (programmeType && intakeFields && yearFields) {
                // Ensure the onchange handler is set (in case inline onchange doesn't work)
                programmeType.removeEventListener('change', toggleIntakeFields); // Remove if exists
                programmeType.addEventListener('change', toggleIntakeFields);
                console.log('Event listener attached to programme_type');
            } else {
                console.error('Not all required elements found for programme form');
            }
        });
        
        // Also try on window load as backup
        window.addEventListener('load', function() {
            const programmeType = document.getElementById('programme_type');
            if (programmeType && !programmeType.onchange) {
                programmeType.addEventListener('change', toggleIntakeFields);
            }
        });
    </script>
</body>
</html>

<?php
require "unified_footer.php";
?>
