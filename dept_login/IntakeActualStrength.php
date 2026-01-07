<?php
// IntakeActualStrength.php - Student Intake Form

// ============================================================================
// CRITICAL: Handle AJAX requests FIRST - BEFORE ANY INCLUDES OR OUTPUT
// ============================================================================

// Handle country data AJAX request - MUST BE FIRST, BEFORE session.php
if (isset($_GET['action']) && $_GET['action'] == 'get_country_data' && isset($_GET['record_id'])) {
    // Start session and load config FIRST (minimal requirements for AJAX)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Load config for database connection
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }

    // Load session for user info - use session.php if available
    if (!isset($userInfo) || empty($userInfo)) {
        // Try to include session.php without output
        ob_start();
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
        ob_end_clean();

        // If still no userInfo, return error
        if (!isset($userInfo) || empty($userInfo)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized - Please login']);
            exit;
        }
    }

    // Clear any output buffer immediately - CRITICAL for JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Suppress all errors and warnings
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    // Set proper headers FIRST - before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }

    $response = ['success' => false, 'country_data' => []];

    try {
        $record_id = (int)($_GET['record_id'] ?? 0);

        if (empty($record_id)) {
            $response['error'] = 'Record ID is required';
            echo json_encode($response);
            exit;
        }

        // Get department ID
        $dept = $userInfo['DEPT_ID'] ?? 0;

        if (!$dept) {
            $response['error'] = 'Department ID not found. Please contact administrator.';
            echo json_encode($response);
            exit;
        }

        // Calculate academic year - use centralized function
        if (!function_exists('getAcademicYear')) {
            if (file_exists(__DIR__ . '/common_functions.php')) {
                require_once(__DIR__ . '/common_functions.php');
            }
        }
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

        // Get the program code from the record ID first
        $get_record_query = "SELECT PROGRAM_CODE FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $get_record_query);
        if (!$stmt) {
            $response['error'] = 'Database query preparation failed: ' . mysqli_error($conn);
            echo json_encode($response);
            exit;
        }

        mysqli_stmt_bind_param($stmt, "ii", $record_id, $dept);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $response['error'] = 'Database query execution failed: ' . mysqli_stmt_error($stmt);
            echo json_encode($response);
            exit;
        }

        $record_result = mysqli_stmt_get_result($stmt);

        if (!$record_result || mysqli_num_rows($record_result) === 0) {
            mysqli_stmt_close($stmt);
            echo json_encode([
                'success' => true,
                'country_data' => []
            ]);
            exit;
        }

        $record = mysqli_fetch_assoc($record_result);
        $program_code = $record['PROGRAM_CODE'];
        mysqli_stmt_close($stmt);
        
        // Convert program_code to integer for country_wise_student table (PROGRAM_CODE is int(11))
        // Handle both numeric strings and alphanumeric codes (e.g., "msc1012" -> extract "1012")
        if (is_numeric($program_code)) {
            $program_code_int = (int) $program_code;
        } else {
            // Extract numeric part from alphanumeric codes
            preg_match('/\d+/', $program_code, $matches);
            $program_code_int = !empty($matches) ? (int) $matches[0] : 0;
        }

        // Get country data for the specific program
        $country_query = "SELECT * FROM country_wise_student WHERE DEPT_ID = ? AND PROGRAM_CODE = ? AND A_YEAR = ? ORDER BY COUNTRY_CODE";
        $stmt = mysqli_prepare($conn, $country_query);
        if (!$stmt) {
            $response['error'] = 'Database query preparation failed: ' . mysqli_error($conn);
            echo json_encode($response);
            exit;
        }

        // Bind parameters: DEPT_ID (i), PROGRAM_CODE (i), A_YEAR (s)
        mysqli_stmt_bind_param($stmt, "iis", $dept, $program_code_int, $A_YEAR);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $response['error'] = 'Database query execution failed: ' . mysqli_stmt_error($stmt);
            echo json_encode($response);
            exit;
        }

        $country_result = mysqli_stmt_get_result($stmt);

        $countries = [];
        if ($country_result && mysqli_num_rows($country_result) > 0) {
            while ($row = mysqli_fetch_assoc($country_result)) {
                $countries[] = [
                    'ID' => $row['ID'],
                    'COUNTRY_CODE' => $row['COUNTRY_CODE'],
                    'NO_OF_STUDENT_COUNTRY' => $row['NO_OF_STUDENT_COUNTRY'],
                    'country_code' => $row['COUNTRY_CODE'], // Also provide lowercase version for compatibility
                    'student_count' => $row['NO_OF_STUDENT_COUNTRY']
                ];
            }
        }
        mysqli_stmt_close($stmt);

        $response = [
            'success' => true,
            'country_data' => $countries
        ];
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    } catch (Error $e) {
        $response = [
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage()
        ];
    }

    // Ensure no output before JSON
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Output JSON and exit
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle get record data for editing - MUST BE BEFORE session.php
if (isset($_GET['action']) && $_GET['action'] == 'get_record_data' && isset($_GET['record_id'])) {
    // Start session and load config FIRST (minimal requirements for AJAX)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Load config for database connection
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }

    // Load session for user info - use session.php if available
    if (!isset($userInfo) || empty($userInfo)) {
        // Try to include session.php without output
        ob_start();
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
        ob_end_clean();
    }

    // Clear any output buffer immediately - CRITICAL for JSON response
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Suppress all errors and warnings
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    // Set proper headers FIRST - before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }

    $response = ['success' => false];

    try {
        $record_id = (int)($_GET['record_id'] ?? 0);

        if (empty($record_id)) {
            $response['message'] = 'Record ID is required';
            echo json_encode($response);
            exit;
        }

        // Check authorization and get dept_id
        $dept = 0;
        if (isset($userInfo) && !empty($userInfo) && isset($userInfo['DEPT_ID'])) {
            $dept = (int)$userInfo['DEPT_ID'];
        }

        if (!$dept) {
            $response['message'] = 'Unauthorized - Please login';
            echo json_encode($response);
            exit;
        }

        // Get record data
        $get_record_query = "SELECT * FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $get_record_query);
        if (!$stmt) {
            $response['message'] = 'Database query preparation failed: ' . mysqli_error($conn);
            echo json_encode($response);
            exit;
        }

        mysqli_stmt_bind_param($stmt, "ii", $record_id, $dept);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $response['message'] = 'Database query execution failed: ' . mysqli_stmt_error($stmt);
            echo json_encode($response);
            exit;
        }

        $result = mysqli_stmt_get_result($stmt);

        if (!$result) {
            mysqli_stmt_close($stmt);
            $response['message'] = 'Database query failed: ' . mysqli_error($conn);
            echo json_encode($response);
            exit;
        }

        if (mysqli_num_rows($result) === 0) {
            mysqli_stmt_close($stmt);
            $response['message'] = 'Record not found';
            echo json_encode($response);
            exit;
        }

        $record = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        $response = ['success' => true, 'record' => $record];
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    } catch (Error $e) {
        $response = ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }

    // Ensure no output before JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Output JSON and exit
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Now include session.php and other files for normal page loads
require('session.php');

// Require common_functions early to ensure getDepartmentInfo is available
if (!function_exists('getDepartmentInfo')) {
    require_once(__DIR__ . '/common_functions.php');
}

// Handle other AJAX requests - before any includes or HTML output
if (isset($_GET['action']) && $_GET['action'] == 'check_program_data' && isset($_GET['program_code'])) {
    // Clear any output buffer immediately
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Suppress all errors and warnings
    error_reporting(0);
    ini_set('display_errors', 0);

    // Set proper headers FIRST
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate');

    try {
        $program_code = $_GET['program_code'];

        // Get department ID from session (already verified in session.php)
        $dept = $userInfo['DEPT_ID'];

        if (!$dept) {
            echo json_encode([
                'exists' => false,
                'error' => 'Department ID not found. Please contact administrator.'
            ]);
            exit;
        }

        // Calculate academic year - use centralized function
        if (!function_exists('getAcademicYear')) {
            if (file_exists(__DIR__ . '/common_functions.php')) {
                require_once(__DIR__ . '/common_functions.php');
            }
        }
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

        // Check if record exists for this program code
        $check_query = "SELECT * FROM intake_actual_strength WHERE DEPT_ID = ? AND PROGRAM_CODE = ? AND A_YEAR = ?";
        $stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($stmt, "iss", $dept, $program_code, $A_YEAR);
        mysqli_stmt_execute($stmt);
        $check_result = mysqli_stmt_get_result($stmt);

        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $record = mysqli_fetch_assoc($check_result);
            echo json_encode([
                'exists' => true,
                'record' => $record
            ]);
        } else {
            echo json_encode([
                'exists' => false
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'exists' => false,
            'error' => 'An error occurred: ' . $e->getMessage()
        ]);
    }
    exit;
}


// Handle PDF uploads - MUST be before unified_header.php
// if (isset($_POST['upload_document'])) {
//     // Start fresh output buffering to catch any unwanted output
//     if (ob_get_level()) {
//         ob_end_clean();
//     }
//     ob_start();

//     // Suppress all errors and warnings
//     error_reporting(0);
//     ini_set('display_errors', 0);
//     ini_set('log_errors', 0);

//     // Set proper headers FIRST - before any output
//     if (!headers_sent()) {
//         header('Content-Type: application/json; charset=UTF-8');
//         header('Cache-Control: no-cache, must-revalidate');
//     }

//     // Clear any output that might have been generated by requires
//     ob_clean();

//     $response = null;
//     try {
//         // Get file_id and srno from POST
//         $file_id = $_POST['file_id'] ?? '';
//         $srno = (int)($_POST['srno'] ?? 0);

//         // CRITICAL FIX #1: Validate srno
//         if ($srno <= 0 || $srno > 4) {
//             throw new Exception('Invalid serial number. Must be between 1 and 4.');
//         }

//         // Get department ID using common function
//         $dept_info = getDepartmentInfo($conn);
//         if (!$dept_info) {
//             throw new Exception('Department information not found');
//         }
//         $dept_id = $dept_info['DEPT_ID'];

//         // Calculate academic year
//         $current_year = (int)date('Y');
//         $current_month = (int)date('n');
//         $A_YEAR = ($current_month >= 6) ?
//             ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;

//         // CRITICAL FIX #2: Get program_code from POST (required for document separation)
//         $program_code = $_POST['program_code'] ?? $_POST['p_name'] ?? $_GET['program_code'] ?? '';

//         if (empty($program_code)) {
//             // Try to get from file_id as fallback
//             $file_id_value = $_POST['file_id'] ?? '';
//             if (!empty($file_id_value) && is_numeric($file_id_value)) {
//                 $program_code = $file_id_value;
//             }
//         }

//         if (empty($program_code)) {
//             throw new Exception('Program code is required for document upload.');
//         }

//         // CRITICAL FIX #3: Validate file upload BEFORE processing
//         if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
//             $error_messages = [
//                 UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
//                 UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
//                 UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
//                 UPLOAD_ERR_NO_FILE => 'No file was uploaded',
//                 UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
//                 UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
//                 UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
//             ];

//             $error_code = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
//             $error_msg = $error_messages[$error_code] ?? 'Unknown upload error';
//             throw new Exception($error_msg);
//         }

//         // Create upload directory with program-specific folder
//         $upload_dir = dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/intake_actual_strength/{$program_code}/";

//         if (!file_exists($upload_dir)) {
//             if (!mkdir($upload_dir, 0755, true)) {
//                 throw new Exception('Failed to create upload directory.');
//             }
//             chmod($upload_dir, 0755);
//         }

//         $file = $_FILES['document'];
//         $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

//         // Validate PDF file
//         if ($file_extension !== 'pdf') {
//             throw new Exception('Only PDF files are allowed. Uploaded file type: ' . $file_extension);
//         }

//         if ($file['type'] !== 'application/pdf') {
//             throw new Exception('Invalid file MIME type. Expected: application/pdf, Got: ' . $file['type']);
//         }

//         // Validate file size (5MB limit)
//         $max_size = 5 * 1024 * 1024; // 5MB in bytes
//         if ($file['size'] > $max_size) {
//             $size_mb = round($file['size'] / (1024 * 1024), 2);
//             throw new Exception("File size ({$size_mb}MB) exceeds maximum limit (5MB)");
//         }

//         // Generate unique filename with program code
//         $original_name = pathinfo($file['name'], PATHINFO_FILENAME);
//         $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $original_name);
//         $file_name = "{$program_code}_{$srno}_" . time() . "_{$safe_name}.pdf";
//         $file_path = $upload_dir . $file_name;

//         // Move uploaded file
//         if (!move_uploaded_file($file['tmp_name'], $file_path)) {
//             throw new Exception('Failed to move uploaded file to destination');
//         }

//         // Prepare database entry
//         $file_size = $file['size'];
//         $uploaded_by = $_SESSION['admin_username'] ?? 'Unknown';

//         // Document titles based on serial number
//         $document_titles = [
//             1 => 'Total Intake Proof Documentation',
//             2 => 'Regional Diversity Documentation',
//             3 => 'ESCS Diversity Documentation',
//             4 => 'Scholarship/Freeship Documentation'
//         ];
//         $document_title = $document_titles[$srno] ?? 'Enrolment Documentation';

//         // Convert absolute path to web-accessible path
//         $web_path = str_replace(dirname(__DIR__) . '/', '', $file_path);
//         $web_path = str_replace('\\', '/', $web_path);

//         // CRITICAL FIX #4: Delete existing record for this program + serial number
//         // This ensures each program has its own separate documents
//         $delete_query = "DELETE FROM supporting_documents 
//             WHERE academic_year = ? AND dept_id = ? 
//             AND page_section = 'intake_actual_strength' 
//             AND serial_number = ? AND program_id = ? 
//             AND status = 'active'";
//         $delete_stmt = mysqli_prepare($conn, $delete_query);
//         if ($delete_stmt) {
//             mysqli_stmt_bind_param($delete_stmt, "siss", $A_YEAR, $dept_id, $srno, $program_code);
//             mysqli_stmt_execute($delete_stmt);
//             mysqli_stmt_close($delete_stmt);
//         }

//         // Insert new record
//         $insert_query = "INSERT INTO supporting_documents 
//             (academic_year, dept_id, page_section, section_name, serial_number, 
//              program_id, document_title, file_path, file_name, file_size, 
//              uploaded_by, status) 
//             VALUES (?, ?, 'intake_actual_strength', 'Intake Actual Strength', 
//                     ?, ?, ?, ?, ?, ?, ?, 'active')";

//         $stmt = mysqli_prepare($conn, $insert_query);
//         if (!$stmt) {
//             throw new Exception('Failed to prepare insert statement: ' . mysqli_error($conn));
//         }

//         // Bind parameters: academic_year(s), dept_id(i), srno(i), program_id(s), 
//         //                  document_title(s), web_path(s), file_name(s), file_size(i), uploaded_by(s)
//         $bind_result = mysqli_stmt_bind_param(
//             $stmt,
//             "siissssis",
//             $A_YEAR,
//             $dept_id,
//             $srno,
//             $program_code,
//             $document_title,
//             $web_path,
//             $file['name'],
//             $file_size,
//             $uploaded_by
//         );

//         if (!$bind_result) {
//             mysqli_stmt_close($stmt);
//             throw new Exception('Failed to bind parameters: ' . mysqli_stmt_error($stmt));
//         }

//         if (mysqli_stmt_execute($stmt)) {
//             mysqli_stmt_close($stmt);
//             $response = [
//                 'success' => true,
//                 'message' => 'Document uploaded successfully',
//                 'file_path' => $web_path,
//                 'file_name' => $file['name'],
//                 'file_size' => $file_size
//             ];
//         } else {
//             $error = mysqli_stmt_error($stmt);
//             mysqli_stmt_close($stmt);

//             // Delete the uploaded file if database insert failed
//             if (file_exists($file_path)) {
//                 unlink($file_path);
//             }

//             throw new Exception('Database error: ' . $error);
//         }
//     } catch (Exception $e) {
//         $response = [
//             'success' => false,
//             'message' => 'Upload failed: ' . $e->getMessage()
//         ];
//     } catch (Error $e) {
//         $response = [
//             'success' => false,
//             'message' => 'Upload failed: ' . $e->getMessage()
//         ];
//     } catch (Throwable $e) {
//         $response = [
//             'success' => false,
//             'message' => 'Upload failed: ' . $e->getMessage()
//         ];
//     }

//     // Clean all output buffers and send clean JSON response
//     while (ob_get_level()) {
//         ob_end_clean();
//     }

//     if (!headers_sent()) {
//         header('Content-Type: application/json; charset=UTF-8');
//         header('Cache-Control: no-cache, must-revalidate');
//     }

//     // Ensure we always output valid JSON
//     if ($response === null) {
//         $response = ['success' => false, 'message' => 'Unexpected error: No response generated'];
//     }

//     echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
//     exit;
// }

// ****************************

// CORRECTED DOCUMENT UPLOAD HANDLER - Replace lines 162-248 in IntakeActualStrength.php

// Handle PDF uploads - MUST be before unified_header.php
if (isset($_POST['upload_document'])) {
    // Start fresh output buffering to catch any unwanted output
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // Suppress all errors and warnings
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 0);

    // Set proper headers FIRST - before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }

    // Clear any output that might have been generated by requires
    ob_clean();

    // Load CSRF utilities BEFORE common_upload_handler.php (required for validation)
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        // Ensure CSRF token is generated in session before validation
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if it doesn't exist
        }
    }

    $response = null;
    try {
        // Get file_id and srno from POST
        $file_id = $_POST['file_id'] ?? '';
        $srno = (int)($_POST['srno'] ?? 0);

        // CRITICAL FIX #1: Validate srno
        if ($srno <= 0 || $srno > 4) {
            throw new Exception('Invalid serial number. Must be between 1 and 4.');
        }

        // Get department ID using common function
        $dept_info = getDepartmentInfo($conn);
        if (!$dept_info) {
            throw new Exception('Department information not found');
        }
        $dept_id = $dept_info['DEPT_ID'];

        // Calculate academic year - use centralized function
        if (!function_exists('getAcademicYear')) {
            if (file_exists(__DIR__ . '/common_functions.php')) {
                require_once(__DIR__ . '/common_functions.php');
            }
        }
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

        // CRITICAL FIX #2: Get program_code from POST (required for document separation)
        $program_code = trim((string)($_POST['program_code'] ?? $_POST['p_name'] ?? $_GET['program_code'] ?? ''));

        if (empty($program_code)) {
            // Try to get from file_id as fallback
            $file_id_value = trim((string)($_POST['file_id'] ?? ''));
            if (!empty($file_id_value) && is_numeric($file_id_value)) {
                $program_code = $file_id_value;
            }
        }

        // Ensure program_code is trimmed and not empty
        $program_code = trim((string)$program_code);

        if (empty($program_code)) {
            throw new Exception('Program code is required for document upload.');
        }

        // CRITICAL FIX #3: Validate file upload BEFORE processing
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
            ];

            $error_code = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
            $error_msg = $error_messages[$error_code] ?? 'Unknown upload error';
            throw new Exception($error_msg);
        }

        // USE common_upload_handler.php JUST LIKE Programmes_Offered.php
        // This ensures each program gets its own separate documents
        require_once(__DIR__ . '/common_upload_handler.php');

        // Set global variables for common_upload_handler.php
        $GLOBALS['dept_id'] = $dept_id;
        $GLOBALS['A_YEAR'] = $A_YEAR;

        // Document titles based on serial number
        $document_titles = [
            1 => 'Total Enrolment Proof Documentation',
            2 => 'Regional Diversity Documentation',
            3 => 'ESCS Diversity Documentation',
            4 => 'Scholarship/Freeship Documentation'
        ];
        $document_title = $document_titles[$srno] ?? 'Enrolment Documentation';

        // CRITICAL: Modify section_name to include program_code for uniqueness per program
        // This ensures each program gets its own separate documents (like PlacementDetails.php does)
        // The unique constraint is (academic_year, dept_id, page_section, serial_number, section_name)
        // By including program_code in section_name, each program gets a unique section_name
        $unique_section_name = 'Intake Actual Strength_PROG_' . $program_code;
        
        // CRITICAL FIX: EXPLICIT PROGRAM-SCOPED DELETE - Ensures only this program's doc for this srno is replaced
        // This prevents the common handler from deleting documents from other programs
        // Delete ONLY documents for this specific program + serial_number combination
        // First, get file paths to delete physical files
        $get_file_query = "SELECT file_path FROM supporting_documents 
            WHERE academic_year = ? AND dept_id = ? 
            AND page_section = 'intake_actual_strength' 
            AND serial_number = ? AND section_name = ?
            AND status = 'active'";
        $get_file_stmt = mysqli_prepare($conn, $get_file_query);
        if ($get_file_stmt) {
            mysqli_stmt_bind_param($get_file_stmt, "siss", $A_YEAR, $dept_id, $srno, $unique_section_name);
            if (mysqli_stmt_execute($get_file_stmt)) {
                $file_result = mysqli_stmt_get_result($get_file_stmt);
                $project_root = dirname(__DIR__);
                
                // Delete physical files
                while ($file_row = mysqli_fetch_assoc($file_result)) {
                    $old_file_path = $file_row['file_path'];
                    if (!empty($old_file_path)) {
                        // Convert to physical path
                        $physical_path = $old_file_path;
                        if (strpos($physical_path, '../') === 0) {
                            $physical_path = $project_root . '/' . str_replace('../', '', $physical_path);
                        } elseif (strpos($physical_path, 'uploads/') === 0) {
                            $physical_path = $project_root . '/' . $physical_path;
                        }
                        $physical_path = str_replace('\\', '/', $physical_path);
                        
                        if (file_exists($physical_path)) {
                            @unlink($physical_path);
                        }
                    }
                }
                mysqli_free_result($file_result);
            }
            mysqli_stmt_close($get_file_stmt);
        }
        
        // EXPLICIT PROGRAM-SCOPED DELETE BEFORE UPLOAD - Prevents wiping other programs
        // This ensures only this program's document for this srno is deleted, not documents from other programs
        $delete_query = "DELETE FROM supporting_documents 
            WHERE academic_year = ? AND dept_id = ? 
            AND page_section = 'intake_actual_strength' 
            AND serial_number = ? AND section_name = ?
            AND status = 'active'";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        if ($delete_stmt) {
            mysqli_stmt_bind_param($delete_stmt, "siss", $A_YEAR, $dept_id, $srno, $unique_section_name);
            if (mysqli_stmt_execute($delete_stmt)) {
                $deleted_rows = mysqli_stmt_affected_rows($delete_stmt);
                if ($deleted_rows > 0) {
                    error_log("Intake Upload: Deleted $deleted_rows rows for $unique_section_name (srno=$srno)");
                }
            } else {
                error_log("[IntakeActualStrength] Delete query execution failed: " . mysqli_stmt_error($delete_stmt));
            }
            mysqli_stmt_close($delete_stmt);
        } else {
            throw new Exception('Failed to prepare scoped delete: ' . mysqli_error($conn));
        }
        
        // Use common upload handler - handles per-program documents correctly
        // Pass the modified section_name to ensure uniqueness per program
        $result = handleDocumentUpload('intake_actual_strength', $unique_section_name, [
            'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/intake_actual_strength/{$program_code}/",
            'max_size' => 5,
            'document_title' => $document_title,
            'srno' => $srno,
            'file_id' => 'intake_doc',
            'program_id' => $program_code
        ]);
        
        // Use the result from common_upload_handler.php
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
    } catch (Throwable $e) {
        $response = [
            'success' => false,
            'message' => 'Upload failed: ' . $e->getMessage()
        ];
    }

    // CRITICAL: Clean ALL output buffers and send clean JSON response
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // CRITICAL: Set headers BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }

    // CRITICAL: Ensure we always output valid JSON - build response in variable first
    if ($response === null) {
        $response = ['success' => false, 'message' => 'Unexpected error: No response generated'];
    }

    // Output response ONCE at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// OLD UPLOAD LOGIC REMOVED - Now using common_upload_handler.php (same as Programmes_Offered.php)
// All old upload code has been replaced with common_upload_handler.php call above

// Handle document check and deletion (check_doc and delete_doc handlers below)

// ============================================================================
// CORRECTED DELETE DOCUMENT HANDLER - Replace lines 346-428
// ============================================================================

// Handle PDF deletion - MUST be before unified_header.php - Convert to POST for security
// CRITICAL: Match FacultyOutput.php pattern exactly for stability
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_doc'])) {
    // CRITICAL #1: Clear ALL output buffers first to prevent any output before JSON
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
    
    // Clear any remaining output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Load CSRF utilities silently
    ob_start();
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('csrf_token')) {
            csrf_token();
        }
    }
    ob_end_clean();

    // CRITICAL #4: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Initialize response in variable - build response first, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    // Validate CSRF token
    if (function_exists('validate_csrf')) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (empty($csrf_token) || !validate_csrf($csrf_token)) {
            // Build response in variable
            $response = [
                'success' => false, 
                'message' => 'Security token validation failed. Please refresh the page and try again.'
            ];
            // Output response ONCE at the end
            echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    try {
        // Get department ID from session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Load session.php if not already loaded
        if (!isset($userInfo) || empty($userInfo)) {
            if (file_exists(__DIR__ . '/session.php')) {
                require_once(__DIR__ . '/session.php');
            }
        }

        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            throw new Exception('Department ID not found. Please login again.');
        }

        // CRITICAL FIX: Calculate academic year correctly - use centralized function
        if (!function_exists('getAcademicYear')) {
            if (file_exists(__DIR__ . '/common_functions.php')) {
                require_once(__DIR__ . '/common_functions.php');
            }
        }
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

        // CRITICAL: Get program_code from request (POST now)
        $program_code = trim($_POST['program_code'] ?? $_GET['program_code'] ?? '');

        if (empty($program_code)) {
            throw new Exception('Program code is required');
        }
        
        // Get srno from POST first (since this is a POST request), then fallback to GET
        $srno = (int)($_POST['srno'] ?? $_GET['srno'] ?? 0);
        if ($srno <= 0 || $srno > 4) {
            throw new Exception('Invalid serial number');
        }

        // CRITICAL: Use unique section_name format for query (same as upload handler)
        $unique_section_name = 'Intake Actual Strength_PROG_' . $program_code;
        
        // Get file path from database using unique section_name
        $get_file_query = "SELECT file_path FROM supporting_documents 
            WHERE academic_year = ? AND dept_id = ? 
            AND page_section = 'intake_actual_strength' 
            AND serial_number = ? AND section_name = ?
            AND status = 'active' LIMIT 1";

        $stmt = mysqli_prepare($conn, $get_file_query);
        if (!$stmt) {
            throw new Exception('Database error: ' . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "siss", $A_YEAR, $dept_id, $srno, $unique_section_name);
        
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new Exception('Database execution error');
        }
        
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $file_path = $row['file_path'];
            
            // CRITICAL: Free result before closing statement
            mysqli_free_result($result);
            mysqli_stmt_close($stmt);

            // Delete file from filesystem
            $physical_path = $file_path;
            if (strpos($physical_path, '../') === 0) {
                $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $physical_path);
            } elseif (strpos($physical_path, 'uploads/') === 0) {
                $physical_path = dirname(__DIR__) . '/' . $physical_path;
            }

            $file_deleted = false;
            if (file_exists($physical_path)) {
                $file_deleted = unlink($physical_path);
            } else {
                // Try with program code folder structure - use YEAR format
                $current_year = (int)date('Y');
                if (function_exists('getAcademicYear')) {
                    $a_year = getAcademicYear();
                } else {
                    $current_year = (int)date('Y');
                    $current_month = (int)date('n');
                    $a_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
                }
                $file_with_prog = dirname(__DIR__) . "/uploads/{$a_year}/DEPARTMENT/{$dept_id}/intake_actual_strength/{$program_code}/" . basename($file_path);
                if (file_exists($file_with_prog)) {
                    $file_deleted = unlink($file_with_prog);
                }
                // Also try with A_YEAR format for backward compatibility
                $file_with_prog_old = dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/intake_actual_strength/{$program_code}/" . basename($file_path);
                if (file_exists($file_with_prog_old)) {
                    $file_deleted = unlink($file_with_prog_old);
                }
            }

            // Soft delete from database using unique section_name
            $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP 
                WHERE academic_year = ? AND dept_id = ? 
                AND page_section = 'intake_actual_strength' 
                AND serial_number = ? AND section_name = ?
                AND status = 'active'";

            $stmt = mysqli_prepare($conn, $delete_query);
            if (!$stmt) {
                throw new Exception('Database error: ' . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($stmt, "siss", $A_YEAR, $dept_id, $srno, $unique_section_name);

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception('Database error: ' . mysqli_stmt_error($stmt));
            }
            
            mysqli_stmt_close($stmt);
            
            // Build response in variable
            $response = [
                    'success' => true,
                    'message' => 'Document deleted successfully',
                    'file_deleted' => $file_deleted
            ];
            } else {
            // Free result and close statement even if not found
            if ($result) {
                mysqli_free_result($result);
            }
            mysqli_stmt_close($stmt);
            throw new Exception('Document not found');
        }
    } catch (Exception $e) {
        // Build error response in variable
        $response = [
            'success' => false,
            'message' => 'Delete failed: ' . $e->getMessage()
        ];
    } catch (Throwable $e) {
        // Build error response in variable
        $response = [
            'success' => false,
            'message' => 'Delete failed: ' . $e->getMessage()
        ];
    }
    
    // CRITICAL #2: Output response ONCE at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// HANDLE check_all_docs - BATCH endpoint to get ALL document statuses in ONE query
// This replaces 4 individual queries with a single efficient query
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
            // CRITICAL FIX: Correct academic year calculation - use centralized function
            if (!function_exists('getAcademicYear')) {
                if (file_exists(__DIR__ . '/common_functions.php')) {
                    require_once(__DIR__ . '/common_functions.php');
                }
            }
            if (function_exists('getAcademicYear')) {
                $academic_year = getAcademicYear();
            } else {
                $current_year = (int)date('Y');
                $current_month = (int)date('n');
                $academic_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
            }
            
            // CRITICAL: Get program_code from request (required for this page)
            $program_code = $_GET['program_code'] ?? '';
            
            if (empty($program_code)) {
                $response = ['success' => false, 'message' => 'Program code is required'];
            } else {
                // CRITICAL: Single query to get ALL documents at once - replaces 4 individual queries
                // Note: This page uses section_name with program_code, so we need to filter by that
                $unique_section_name = 'Intake Actual Strength_PROG_' . $program_code;
                $batch_query = "SELECT serial_number, file_path, file_name, upload_date, id, academic_year
                               FROM supporting_documents 
                               WHERE dept_id = ? AND page_section = 'intake_actual_strength' 
                               AND section_name = ? AND (academic_year = ? OR academic_year = ?) AND status = 'active'
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
                    mysqli_stmt_bind_param($stmt, 'isss', $dept_id, $unique_section_name, $academic_year, $prev_year);
                    
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
                                // Convert to web-accessible path (robust path conversion)
                                $file_path = $row['file_path'];
                                
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
                                    $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/intake_actual_strength/' . $program_code . '/' . $filename;
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

// ============================================================================
// CORRECTED CHECK DOCUMENT HANDLER - Replace lines 429-479
// ============================================================================

// Handle document status check - MUST be before unified_header.php
// CRITICAL: Match FacultyOutput.php pattern exactly for stability
if (isset($_GET['check_doc'])) {
    // CRITICAL #1: Clear ALL output buffers first to prevent any output before JSON
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

        // Validate srno
        if ($srno <= 0 || $srno > 4) {
            throw new Exception('Invalid serial number');
        }

        // Get department ID using common function (silently)
        ob_start();
        $dept_info = getDepartmentInfo($conn);
        ob_end_clean();
        
        if (!$dept_info) {
            throw new Exception('Department information not found');
        }
        $dept_id = $dept_info['DEPT_ID'];

        // CRITICAL FIX: Calculate academic year correctly - use centralized function
        if (!function_exists('getAcademicYear')) {
            if (file_exists(__DIR__ . '/common_functions.php')) {
                require_once(__DIR__ . '/common_functions.php');
            }
        }
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

        // CRITICAL: Get program_code from request
        $program_code = $_GET['program_code'] ?? '';

        if (empty($program_code)) {
            throw new Exception('Program code is required');
        }

        // CRITICAL: Use unique section_name format for query (same as upload handler)
        $unique_section_name = 'Intake Actual Strength_PROG_' . $program_code;
        
        // Check if document exists using unique section_name
        $get_file_query = "SELECT id, file_path, file_name, upload_date FROM supporting_documents 
            WHERE academic_year = ? AND dept_id = ? 
            AND page_section = 'intake_actual_strength' 
            AND serial_number = ? AND section_name = ?
            AND status = 'active' LIMIT 1";

        $stmt = mysqli_prepare($conn, $get_file_query);
        if (!$stmt) {
            throw new Exception('Database error: ' . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "siss", $A_YEAR, $dept_id, $srno, $unique_section_name);
        
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new Exception('Database execution error');
        }
        
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            
            // CRITICAL: Free result before closing statement
            mysqli_free_result($result);
            mysqli_stmt_close($stmt);

            $file_path = $row['file_path'];

            // Convert absolute paths to relative web paths (handle program_code folder structure)
            $project_root = dirname(__DIR__);
            
            // If path is absolute (contains project root), convert to relative
            if (strpos($file_path, $project_root) === 0) {
                $file_path = str_replace($project_root . '/', '', $file_path);
                $file_path = str_replace($project_root . '\\', '', $file_path);
            }
            
            // Handle other absolute path formats
            if (
                strpos($file_path, '/home/') === 0 ||
                strpos($file_path, 'C:/') === 0 ||
                strpos($file_path, 'C:\\') === 0
            ) {
                // Extract relative path from absolute path (must include program_code folder)
                if (preg_match('/uploads\/[\d\-]+\/DEPARTMENT\/\d+\/intake_actual_strength\/[^\/]+\/.+\.pdf$/', $file_path, $matches)) {
                    $file_path = $matches[0];
                } else {
                    // Construct relative path with program_code folder structure
                    $filename = basename($file_path);
                    $file_path = "uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/intake_actual_strength/{$program_code}/" . $filename;
                }
            }

            // Ensure path doesn't start with ./
            $file_path = ltrim($file_path, './');
            
            // Normalize directory separators
            $file_path = str_replace('\\', '/', $file_path);

            // If path starts with uploads/, prepend ../ for access from dept_login directory
            if (strpos($file_path, 'uploads/') === 0 && strpos($file_path, '../') !== 0) {
                $file_path = '../' . $file_path;
            }
            
            // If path doesn't have ../ prefix and doesn't start with http, ensure it has ../ for web access
            if (strpos($file_path, '../') !== 0 && strpos($file_path, 'http') !== 0 && strpos($file_path, 'uploads/') === 0) {
                $file_path = '../' . $file_path;
            }

            // Build response in variable
            $response = [
                'success' => true,
                'file_path' => $file_path,
                'file_name' => $row['file_name'],
                'upload_date' => $row['upload_date'],
                'document_id' => $row['id'] // Include document_id for delete functionality
            ];
        } else {
            // Free result and close statement even if no rows found
            if ($result) {
                mysqli_free_result($result);
            }
            mysqli_stmt_close($stmt);
            
            // Build response in variable
            $response = [
                'success' => false,
                'message' => 'No document found'
            ];
        }
    } catch (Exception $e) {
        // Build error response in variable
        $response = [
            'success' => false,
            'message' => 'Check failed: ' . $e->getMessage()
        ];
    } catch (Throwable $e) {
        // Build error response in variable
        $response = [
            'success' => false,
            'message' => 'Check failed: ' . $e->getMessage()
        ];
    }
    
    // CRITICAL #2: Output response ONCE at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
// ****************************

// ============================================================================
// MAIN FORM SUBMISSION HANDLER - MUST BE BEFORE unified_header.php
// ============================================================================
// Handle form submission - match PlacementDetails.php pattern exactly
// Log POST check for debugging

// #######

// CORRECTED UPDATE HANDLER - Replace lines 416-602
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['p_name'])) {
    // Get academic year - use centralized function
    if (!function_exists('getAcademicYear')) {
        if (file_exists(__DIR__ . '/common_functions.php')) {
            require_once(__DIR__ . '/common_functions.php');
        }
    }
    if (function_exists('getAcademicYear')) {
        $A_YEAR = getAcademicYear();
    } else {
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        $A_YEAR = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
    }
    $dept = $userInfo['DEPT_ID'];

    // Validate CSRF token
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf) || !validate_csrf($csrf)) {
                $_SESSION['error'] = 'Security token validation failed. Please refresh and try again.';
                header('Location: IntakeActualStrength.php');
                exit;
            }
        }
    }

    // Validate required fields
    if (empty($_POST['p_name'])) {
        $_SESSION['error'] = 'Please select a program!';
        header('Location: IntakeActualStrength.php');
        exit;
    }

    $p_code = $_POST['p_name']; // programme_code

    // Get program details
    // CRITICAL: Use COALESCE to prefer total_intake over intake_capacity
    // total_intake is calculated from year-wise breakdowns and is the actual value shown in Programmes_Offered
    $query = "SELECT id, programme_code, programme_name, programme_type, 
              COALESCE(NULLIF(total_intake, 0), intake_capacity, 0) AS intake_capacity 
              FROM programmes WHERE programme_code = ? AND DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "si", $p_code, $dept);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result || mysqli_num_rows($result) == 0) {
        $_SESSION['error'] = 'Program not found or access denied.';
        header('Location: IntakeActualStrength.php');
        exit;
    }

    $row = mysqli_fetch_assoc($result);
    $p_code = $row['programme_code'];
    $programme_name = $row['programme_name'];
    $intake_capacity = (int) $row['intake_capacity'];
    
    // Convert p_code to integer for country_wise_student table (PROGRAM_CODE is int(11))
    // Handle both numeric strings and alphanumeric codes (e.g., "msc1012" -> extract "1012")
    if (is_numeric($p_code)) {
        $p_code_int = (int) $p_code;
    } else {
        // Extract numeric part from alphanumeric codes
        preg_match('/\d+/', $p_code, $matches);
        $p_code_int = !empty($matches) ? (int) $matches[0] : 0;
        if ($p_code_int == 0) {
            error_log("[Intake] WARNING: Could not extract numeric program code from: $p_code - country data will not be saved");
        }
    }

    // Get all form data
    $Student_Intake = $intake_capacity;
    $Male_Students = (int)($_POST['Total_number_of_Male_Students_within_state'] ?? 0) +
        (int)($_POST['Male_Students_outside_state'] ?? 0) +
        (int)($_POST['Male_Students_outside_country'] ?? 0);
    $Female_Students = (int)($_POST['Total_number_of_Female_Students_within_state'] ?? 0) +
        (int)($_POST['Female_Students_outside_state'] ?? 0) +
        (int)($_POST['Female_Students_outside_country'] ?? 0);
    $Other_Students = (int)($_POST['Total_number_of_Other_Students_within_state'] ?? 0) +
        (int)($_POST['Other_Students_outside_state'] ?? 0) +
        (int)($_POST['Other_Students_outside_country'] ?? 0);

    // Validate total students
    $total_students = $Male_Students + $Female_Students + $Other_Students;
    if ($total_students > $Student_Intake) {
        $_SESSION['error'] = "Total students ($total_students) cannot exceed enrolment capacity ($Student_Intake).";
        header('Location: IntakeActualStrength.php');
        exit;
    }

    // Get all other form fields
    $Male_Students_within_state = (int)($_POST['Total_number_of_Male_Students_within_state'] ?? 0);
    $Female_Students_within_state = (int)($_POST['Total_number_of_Female_Students_within_state'] ?? 0);
    $Other_Students_within_state = (int)($_POST['Total_number_of_Other_Students_within_state'] ?? 0);
    $Male_Students_outside_state = (int)($_POST['Male_Students_outside_state'] ?? 0);
    $Female_Students_outside_state = (int)($_POST['Female_Students_outside_state'] ?? 0);
    $Other_Students_outside_state = (int)($_POST['Other_Students_outside_state'] ?? 0);
    $Male_Students_outside_country = (int)($_POST['Male_Students_outside_country'] ?? 0);
    $Female_Students_outside_country = (int)($_POST['Female_Students_outside_country'] ?? 0);
    $Other_Students_outside_country = (int)($_POST['Other_Students_outside_country'] ?? 0);

    // Economic Backward
    $Male_Students_Economic_Backward = (int)($_POST['Male_Students_Economic_Backward'] ?? 0);
    $Female_Students_Economic_Backward = (int)($_POST['Female_Students_Economic_Backward'] ?? 0);
    $Other_Students_Economic_Backward = (int)($_POST['Other_Students_Economic_Backward'] ?? 0);

    // General Category
    $Male_Student_General = (int)($_POST['Male_Student_General'] ?? 0);
    $Female_Student_General = (int)($_POST['Female_Student_General'] ?? 0);
    $Other_Student_General = (int)($_POST['Other_Student_General'] ?? 0);

    // Social Backward Categories
    $Male_Student_Social_Backward_SC = (int)($_POST['Male_Students_Social_Backward_SC'] ?? 0);
    $Female_Student_Social_Backward_SC = (int)($_POST['Female_Student_Social_Backward_SC'] ?? 0);
    $Other_Student_Social_Backward_SC = (int)($_POST['Other_Student_Social_Backward_SC'] ?? 0);
    $Male_Student_Social_Backward_ST = (int)($_POST['Male_Students_Social_Backward_ST'] ?? 0);
    $Female_Student_Social_Backward_ST = (int)($_POST['Female_Student_Social_Backward_ST'] ?? 0);
    $Other_Student_Social_Backward_ST = (int)($_POST['Other_Student_Social_Backward_ST'] ?? 0);
    $Male_Student_Social_Backward_OBC = (int)($_POST['Male_Students_Social_Backward_OBC'] ?? 0);
    $Female_Student_Social_Backward_OBC = (int)($_POST['Female_Student_Social_Backward_OBC'] ?? 0);
    $Other_Student_Social_Backward_OBC = (int)($_POST['Other_Student_Social_Backward_OBC'] ?? 0);

    // Additional Social Backward Categories
    $Male_Student_Social_Backward_DTA = (int)($_POST['Male_Students_Social_Backward_DTA'] ?? 0);
    $Female_Student_Social_Backward_DTA = (int)($_POST['Female_Student_Social_Backward_DTA'] ?? 0);
    $Other_Student_Social_Backward_DTA = (int)($_POST['Other_Student_Social_Backward_DTA'] ?? 0);
    $Male_Student_Social_Backward_NTB = (int)($_POST['Male_Students_Social_Backward_NTB'] ?? 0);
    $Female_Student_Social_Backward_NTB = (int)($_POST['Female_Student_Social_Backward_NTB'] ?? 0);
    $Other_Student_Social_Backward_NTB = (int)($_POST['Other_Student_Social_Backward_NTB'] ?? 0);
    $Male_Student_Social_Backward_NTC = (int)($_POST['Male_Students_Social_Backward_NTC'] ?? 0);
    $Female_Student_Social_Backward_NTC = (int)($_POST['Female_Student_Social_Backward_NTC'] ?? 0);
    $Other_Student_Social_Backward_NTC = (int)($_POST['Other_Student_Social_Backward_NTC'] ?? 0);
    $Male_Student_Social_Backward_NTD = (int)($_POST['Male_Students_Social_Backward_NTD'] ?? 0);
    $Female_Student_Social_Backward_NTD = (int)($_POST['Female_Student_Social_Backward_NTD'] ?? 0);
    $Other_Student_Social_Backward_NTD = (int)($_POST['Other_Student_Social_Backward_NTD'] ?? 0);
    $Male_Student_Social_Backward_EWS = (int)($_POST['Male_Students_Social_Backward_EWS'] ?? 0);
    $Female_Student_Social_Backward_EWS = (int)($_POST['Female_Student_Social_Backward_EWS'] ?? 0);
    $Other_Student_Social_Backward_EWS = (int)($_POST['Other_Student_Social_Backward_EWS'] ?? 0);
    $Male_Student_Social_Backward_SEBC = (int)($_POST['Male_Students_Social_Backward_SEBC'] ?? 0);
    $Female_Student_Social_Backward_SEBC = (int)($_POST['Female_Student_Social_Backward_SEBC'] ?? 0);
    $Other_Student_Social_Backward_SEBC = (int)($_POST['Other_Student_Social_Backward_SEBC'] ?? 0);
    $Male_Student_Social_Backward_SBC = (int)($_POST['Male_Students_Social_Backward_SBC'] ?? 0);
    $Female_Student_Social_Backward_SBC = (int)($_POST['Female_Student_Social_Backward_SBC'] ?? 0);
    $Other_Student_Social_Backward_SBC = (int)($_POST['Other_Student_Social_Backward_SBC'] ?? 0);

    // Physically Handicapped
    $Male_Student_Physically_Handicapped = (int)($_POST['Male_Students_Physically_Handicapped'] ?? 0);
    $Female_Student_Physically_Handicapped = (int)($_POST['Female_Students_Physically_Handicapped'] ?? 0);
    $Other_Student_Physically_Handicapped = (int)($_POST['Other_Students_Physically_Handicapped'] ?? 0);

    // TGO
    $Male_Student_TGO = (int)($_POST['Male_Students_TRANGOVTOF_TGO'] ?? 0);
    $Female_Student_TGO = (int)($_POST['Female_Students_TRANGOVTOF_TGO'] ?? 0);
    $Other_Student_TGO = (int)($_POST['Other_Students_TRANGOVTOF_TGO'] ?? 0);

    // CMIL
    $Male_Student_CMIL = (int)($_POST['Male_Students_CMIL'] ?? 0);
    $Female_Student_CMIL = (int)($_POST['Female_Student_CMIL'] ?? 0);
    $Other_Student_CMIL = (int)($_POST['Other_Student_CMIL'] ?? 0);

    // SPCUL
    $Male_Student_SPCUL = (int)($_POST['Male_Student_SPCUL'] ?? 0);
    $Female_Student_SPCUL = (int)($_POST['Female_Student_SPCUL'] ?? 0);
    $Other_Student_SPCUL = (int)($_POST['Other_Student_SPCUL'] ?? 0);

    // Widow Single Mother
    $Male_Student_Widow_Single_Mother = (int)($_POST['Male_Student_Widow_Single_Mother'] ?? 0);
    $Female_Student_Widow_Single_Mother = (int)($_POST['Female_Student_Widow_Single_Mother'] ?? 0);
    $Other_Student_Widow_Single_Mother = (int)($_POST['Other_Student_Widow_Single_Mother'] ?? 0);

    // ES
    $Male_Student_ES = (int)($_POST['Male_Student_ES'] ?? 0);
    $Female_Student_ES = (int)($_POST['Female_Student_ES'] ?? 0);
    $Other_Student_ES = (int)($_POST['Other_Student_ES'] ?? 0);

    // Scholarship Government
    $Male_Student_Receiving_Scholarship_Government = (int)($_POST['Male_Student_Receiving_Scholarship_Government'] ?? 0);
    $Female_Student_Receiving_Scholarship_Government = (int)($_POST['Female_Student_Receiving_Scholarship_Government'] ?? 0);
    $Other_Student_Receiving_Scholarship_Government = (int)($_POST['Other_Student_Receiving_Scholarship_Government'] ?? 0);

    // Scholarship Institution
    $Male_Student_Receiving_Scholarship_Institution = (int)($_POST['Male_Student_Receiving_Scholarship_Institution'] ?? 0);
    $Female_Student_Receiving_Scholarship_Institution = (int)($_POST['Female_Student_Receiving_Scholarship_Institution'] ?? 0);
    $Other_Student_Receiving_Scholarship_Institution = (int)($_POST['Other_Student_Receiving_Scholarship_Institution'] ?? 0);

    // Scholarship Private Body
    $Male_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Male_Student_Receiving_Scholarship_Private_Body'] ?? 0);
    $Female_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Female_Student_Receiving_Scholarship_Private_Body'] ?? 0);
    $Other_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Other_Student_Receiving_Scholarship_Private_Body'] ?? 0);

    // Check if this is an UPDATE (explicit record_id provided) or INSERT (new entry)
    // Only update if record_id is explicitly provided in POST (edit mode)
    $record_id = (int)($_POST['record_id'] ?? 0);
    $is_update = false;
    
    if ($record_id > 0) {
        // Explicit record_id provided - this is an edit operation
        // Verify the record exists and belongs to this department
        $check_existing = "SELECT ID FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ? LIMIT 1";
        $check_stmt = mysqli_prepare($conn, $check_existing);
        if ($check_stmt) {
            mysqli_stmt_bind_param($check_stmt, "ii", $record_id, $dept);
            mysqli_stmt_execute($check_stmt);
            $existing_result = mysqli_stmt_get_result($check_stmt);
            
            if ($existing_result && mysqli_num_rows($existing_result) > 0) {
                $is_update = true;
            } else {
                // Invalid record_id - treat as new entry
                $record_id = 0;
                $is_update = false;
            }
            mysqli_stmt_close($check_stmt);
        }
    } else {
        // No record_id provided - this is a new entry, always INSERT
        $is_update = false;
    }

    if ($is_update) {
        // UPDATE existing record
        $update_query = "UPDATE `intake_actual_strength` SET 
            `Add_Total_Student_Intake` = ?, 
            `Total_number_of_Male_Students` = ?, 
            `Total_number_of_Female_Students` = ?,
            `Total_number_of_Other_Students` = ?,
            `Total_number_of_Male_Students_within_state` = ?,
            `Total_number_of_Female_Students_within_state` = ?,
            `Total_number_of_Other_Students_within_state` = ?,
            `Male_Students_outside_state` = ?,
            `Female_Students_outside_state` = ?,
            `Other_Students_outside_state` = ?,
            `Male_Students_outside_country` = ?,
            `Female_Students_outside_country` = ?,
            `Other_Students_outside_country` = ?,
            `Male_Students_Economic_Backward` = ?,
            `Female_Students_Economic_Backward` = ?,
            `Other_Students_Economic_Backward` = ?,
            `Male_Student_General` = ?,
            `Female_Student_General` = ?,
            `Other_Student_General` = ?,
            `Male_Student_Social_Backward_SC` = ?,
            `Female_Student_Social_Backward_SC` = ?,
            `Other_Student_Social_Backward_SC` = ?,
            `Male_Student_Social_Backward_ST` = ?,
            `Female_Student_Social_Backward_ST` = ?,
            `Other_Student_Social_Backward_ST` = ?,
            `Male_Student_Social_Backward_OBC` = ?,
            `Female_Student_Social_Backward_OBC` = ?,
            `Other_Student_Social_Backward_OBC` = ?,
            `Male_Student_Social_Backward_DTA` = ?,
            `Female_Student_Social_Backward_DTA` = ?,
            `Other_Student_Social_Backward_DTA` = ?,
            `Male_Student_Social_Backward_NTB` = ?,
            `Female_Student_Social_Backward_NTB` = ?,
            `Other_Student_Social_Backward_NTB` = ?,
            `Male_Student_Social_Backward_NTC` = ?,
            `Female_Student_Social_Backward_NTC` = ?,
            `Other_Student_Social_Backward_NTC` = ?,
            `Male_Student_Social_Backward_NTD` = ?,
            `Female_Student_Social_Backward_NTD` = ?,
            `Other_Student_Social_Backward_NTD` = ?,
            `Male_Student_Social_Backward_EWS` = ?,
            `Female_Student_Social_Backward_EWS` = ?,
            `Other_Student_Social_Backward_EWS` = ?,
            `Male_Student_Social_Backward_SEBC` = ?,
            `Female_Student_Social_Backward_SEBC` = ?,
            `Other_Student_Social_Backward_SEBC` = ?,
            `Male_Student_Social_Backward_SBC` = ?,
            `Female_Student_Social_Backward_SBC` = ?,
            `Other_Student_Social_Backward_SBC` = ?,
            `Male_Student_Physically_Handicapped` = ?,
            `Female_Student_Physically_Handicapped` = ?,
            `Other_Student_Physically_Handicapped` = ?,
            `Male_Student_TGO` = ?,
            `Female_Student_TGO` = ?,
            `Other_Student_TGO` = ?,
            `Male_Student_CMIL` = ?,
            `Female_Student_CMIL` = ?,
            `Other_Student_CMIL` = ?,
            `Male_Student_SPCUL` = ?,
            `Female_Student_SPCUL` = ?,
            `Other_Student_SPCUL` = ?,
            `Male_Student_Widow_Single_Mother` = ?,
            `Female_Student_Widow_Single_Mother` = ?,
            `Other_Student_Widow_Single_Mother` = ?,
            `Male_Student_ES` = ?,
            `Female_Student_ES` = ?,
            `Other_Student_ES` = ?,
            `Male_Student_Receiving_Scholarship_Government` = ?,
            `Female_Student_Receiving_Scholarship_Government` = ?,
            `Other_Student_Receiving_Scholarship_Government` = ?,
            `Male_Student_Receiving_Scholarship_Institution` = ?,
            `Female_Student_Receiving_Scholarship_Institution` = ?,
            `Other_Student_Receiving_Scholarship_Institution` = ?,
            `Male_Student_Receiving_Scholarship_Private_Body` = ?,
            `Female_Student_Receiving_Scholarship_Private_Body` = ?,
            `Other_Student_Receiving_Scholarship_Private_Body` = ?,
            `updated_at` = CURRENT_TIMESTAMP
            WHERE `ID` = ? AND `DEPT_ID` = ? AND `A_YEAR` = ?";

        $stmt = mysqli_prepare($conn, $update_query);
        if (!$stmt) {
            $_SESSION['error'] = 'Database prepare error: ' . mysqli_error($conn);
            header('Location: IntakeActualStrength.php');
            exit;
        }

        // Bind parameters: 76 integers (added 3 for General category) + record_id(i) + dept(i) + A_YEAR(s)
        $type_string = str_repeat("i", 76) . "iis";

        mysqli_stmt_bind_param(
            $stmt,
            $type_string,
            $Student_Intake,
            $Male_Students,
            $Female_Students,
            $Other_Students,
            $Male_Students_within_state,
            $Female_Students_within_state,
            $Other_Students_within_state,
            $Male_Students_outside_state,
            $Female_Students_outside_state,
            $Other_Students_outside_state,
            $Male_Students_outside_country,
            $Female_Students_outside_country,
            $Other_Students_outside_country,
            $Male_Students_Economic_Backward,
            $Female_Students_Economic_Backward,
            $Other_Students_Economic_Backward,
            $Male_Student_General,
            $Female_Student_General,
            $Other_Student_General,
            $Male_Student_Social_Backward_SC,
            $Female_Student_Social_Backward_SC,
            $Other_Student_Social_Backward_SC,
            $Male_Student_Social_Backward_ST,
            $Female_Student_Social_Backward_ST,
            $Other_Student_Social_Backward_ST,
            $Male_Student_Social_Backward_OBC,
            $Female_Student_Social_Backward_OBC,
            $Other_Student_Social_Backward_OBC,
            $Male_Student_Social_Backward_DTA,
            $Female_Student_Social_Backward_DTA,
            $Other_Student_Social_Backward_DTA,
            $Male_Student_Social_Backward_NTB,
            $Female_Student_Social_Backward_NTB,
            $Other_Student_Social_Backward_NTB,
            $Male_Student_Social_Backward_NTC,
            $Female_Student_Social_Backward_NTC,
            $Other_Student_Social_Backward_NTC,
            $Male_Student_Social_Backward_NTD,
            $Female_Student_Social_Backward_NTD,
            $Other_Student_Social_Backward_NTD,
            $Male_Student_Social_Backward_EWS,
            $Female_Student_Social_Backward_EWS,
            $Other_Student_Social_Backward_EWS,
            $Male_Student_Social_Backward_SEBC,
            $Female_Student_Social_Backward_SEBC,
            $Other_Student_Social_Backward_SEBC,
            $Male_Student_Social_Backward_SBC,
            $Female_Student_Social_Backward_SBC,
            $Other_Student_Social_Backward_SBC,
            $Male_Student_Physically_Handicapped,
            $Female_Student_Physically_Handicapped,
            $Other_Student_Physically_Handicapped,
            $Male_Student_TGO,
            $Female_Student_TGO,
            $Other_Student_TGO,
            $Male_Student_CMIL,
            $Female_Student_CMIL,
            $Other_Student_CMIL,
            $Male_Student_SPCUL,
            $Female_Student_SPCUL,
            $Other_Student_SPCUL,
            $Male_Student_Widow_Single_Mother,
            $Female_Student_Widow_Single_Mother,
            $Other_Student_Widow_Single_Mother,
            $Male_Student_ES,
            $Female_Student_ES,
            $Other_Student_ES,
            $Male_Student_Receiving_Scholarship_Government,
            $Female_Student_Receiving_Scholarship_Government,
            $Other_Student_Receiving_Scholarship_Government,
            $Male_Student_Receiving_Scholarship_Institution,
            $Female_Student_Receiving_Scholarship_Institution,
            $Other_Student_Receiving_Scholarship_Institution,
            $Male_Student_Receiving_Scholarship_Private_Body,
            $Female_Student_Receiving_Scholarship_Private_Body,
            $Other_Student_Receiving_Scholarship_Private_Body,
            $record_id,
            $dept,
            $A_YEAR
        );

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Data updated successfully for academic year $A_YEAR.";
        } else {
            $_SESSION['error'] = 'Update failed: ' . mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
    } else {
        // INSERT new record

        $A_YEAR_esc = mysqli_real_escape_string($conn, $A_YEAR);
        $dept_esc = mysqli_real_escape_string($conn, $dept);
        $p_code_esc = mysqli_real_escape_string($conn, $p_code);
        $programme_name_esc = mysqli_real_escape_string($conn, $programme_name);

        $insert_query = "INSERT INTO `intake_actual_strength`(
            `A_YEAR`, `DEPT_ID`, `PROGRAM_CODE`, `PROGRAM_NAME`,
            `Add_Total_Student_Intake`, `Total_number_of_Male_Students`, `Total_number_of_Female_Students`, `Total_number_of_Other_Students`,
            `Total_number_of_Male_Students_within_state`, `Total_number_of_Female_Students_within_state`, `Total_number_of_Other_Students_within_state`,
            `Male_Students_outside_state`, `Female_Students_outside_state`, `Other_Students_outside_state`,
            `Male_Students_outside_country`, `Female_Students_outside_country`, `Other_Students_outside_country`,
            `Male_Students_Economic_Backward`, `Female_Students_Economic_Backward`, `Other_Students_Economic_Backward`,
            `Male_Student_General`, `Female_Student_General`, `Other_Student_General`,
            `Male_Student_Social_Backward_SC`, `Female_Student_Social_Backward_SC`, `Other_Student_Social_Backward_SC`,
            `Male_Student_Social_Backward_ST`, `Female_Student_Social_Backward_ST`, `Other_Student_Social_Backward_ST`,
            `Male_Student_Social_Backward_OBC`, `Female_Student_Social_Backward_OBC`, `Other_Student_Social_Backward_OBC`,
            `Male_Student_Social_Backward_DTA`, `Female_Student_Social_Backward_DTA`, `Other_Student_Social_Backward_DTA`,
            `Male_Student_Social_Backward_NTB`, `Female_Student_Social_Backward_NTB`, `Other_Student_Social_Backward_NTB`,
            `Male_Student_Social_Backward_NTC`, `Female_Student_Social_Backward_NTC`, `Other_Student_Social_Backward_NTC`,
            `Male_Student_Social_Backward_NTD`, `Female_Student_Social_Backward_NTD`, `Other_Student_Social_Backward_NTD`,
            `Male_Student_Social_Backward_EWS`, `Female_Student_Social_Backward_EWS`, `Other_Student_Social_Backward_EWS`,
            `Male_Student_Social_Backward_SEBC`, `Female_Student_Social_Backward_SEBC`, `Other_Student_Social_Backward_SEBC`,
            `Male_Student_Social_Backward_SBC`, `Female_Student_Social_Backward_SBC`, `Other_Student_Social_Backward_SBC`,
            `Male_Student_Physically_Handicapped`, `Female_Student_Physically_Handicapped`, `Other_Student_Physically_Handicapped`,
            `Male_Student_TGO`, `Female_Student_TGO`, `Other_Student_TGO`,
            `Male_Student_CMIL`, `Female_Student_CMIL`, `Other_Student_CMIL`,
            `Male_Student_SPCUL`, `Female_Student_SPCUL`, `Other_Student_SPCUL`,
            `Male_Student_Widow_Single_Mother`, `Female_Student_Widow_Single_Mother`, `Other_Student_Widow_Single_Mother`,
            `Male_Student_ES`, `Female_Student_ES`, `Other_Student_ES`,
            `Male_Student_Receiving_Scholarship_Government`, `Female_Student_Receiving_Scholarship_Government`, `Other_Student_Receiving_Scholarship_Government`,
            `Male_Student_Receiving_Scholarship_Institution`, `Female_Student_Receiving_Scholarship_Institution`, `Other_Student_Receiving_Scholarship_Institution`,
            `Male_Student_Receiving_Scholarship_Private_Body`, `Female_Student_Receiving_Scholarship_Private_Body`, `Other_Student_Receiving_Scholarship_Private_Body`,
            `created_at`, `updated_at`
        ) VALUES (
            '$A_YEAR_esc', '$dept_esc', '$p_code_esc', '$programme_name_esc',
            '$Student_Intake', '$Male_Students', '$Female_Students', '$Other_Students',
            '$Male_Students_within_state', '$Female_Students_within_state', '$Other_Students_within_state',
            '$Male_Students_outside_state', '$Female_Students_outside_state', '$Other_Students_outside_state',
            '$Male_Students_outside_country', '$Female_Students_outside_country', '$Other_Students_outside_country',
            '$Male_Students_Economic_Backward', '$Female_Students_Economic_Backward', '$Other_Students_Economic_Backward',
            '$Male_Student_General', '$Female_Student_General', '$Other_Student_General',
            '$Male_Student_Social_Backward_SC', '$Female_Student_Social_Backward_SC', '$Other_Student_Social_Backward_SC',
            '$Male_Student_Social_Backward_ST', '$Female_Student_Social_Backward_ST', '$Other_Student_Social_Backward_ST',
            '$Male_Student_Social_Backward_OBC', '$Female_Student_Social_Backward_OBC', '$Other_Student_Social_Backward_OBC',
            '$Male_Student_Social_Backward_DTA', '$Female_Student_Social_Backward_DTA', '$Other_Student_Social_Backward_DTA',
            '$Male_Student_Social_Backward_NTB', '$Female_Student_Social_Backward_NTB', '$Other_Student_Social_Backward_NTB',
            '$Male_Student_Social_Backward_NTC', '$Female_Student_Social_Backward_NTC', '$Other_Student_Social_Backward_NTC',
            '$Male_Student_Social_Backward_NTD', '$Female_Student_Social_Backward_NTD', '$Other_Student_Social_Backward_NTD',
            '$Male_Student_Social_Backward_EWS', '$Female_Student_Social_Backward_EWS', '$Other_Student_Social_Backward_EWS',
            '$Male_Student_Social_Backward_SEBC', '$Female_Student_Social_Backward_SEBC', '$Other_Student_Social_Backward_SEBC',
            '$Male_Student_Social_Backward_SBC', '$Female_Student_Social_Backward_SBC', '$Other_Student_Social_Backward_SBC',
            '$Male_Student_Physically_Handicapped', '$Female_Student_Physically_Handicapped', '$Other_Student_Physically_Handicapped',
            '$Male_Student_TGO', '$Female_Student_TGO', '$Other_Student_TGO',
            '$Male_Student_CMIL', '$Female_Student_CMIL', '$Other_Student_CMIL',
            '$Male_Student_SPCUL', '$Female_Student_SPCUL', '$Other_Student_SPCUL',
            '$Male_Student_Widow_Single_Mother', '$Female_Student_Widow_Single_Mother', '$Other_Student_Widow_Single_Mother',
            '$Male_Student_ES', '$Female_Student_ES', '$Other_Student_ES',
            '$Male_Student_Receiving_Scholarship_Government', '$Female_Student_Receiving_Scholarship_Government', '$Other_Student_Receiving_Scholarship_Government',
            '$Male_Student_Receiving_Scholarship_Institution', '$Female_Student_Receiving_Scholarship_Institution', '$Other_Student_Receiving_Scholarship_Institution',
            '$Male_Student_Receiving_Scholarship_Private_Body', '$Female_Student_Receiving_Scholarship_Private_Body', '$Other_Student_Receiving_Scholarship_Private_Body',
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )";

        $query_result = mysqli_query($conn, $insert_query);
        if (!$query_result) {
            $_SESSION['error'] = 'Insert failed: ' . mysqli_error($conn);
            header('Location: IntakeActualStrength.php');
            exit;
        }
        
        // CRITICAL: Clear and recalculate score cache after data insert
        if (isset($dept) && isset($A_YEAR)) {
            require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
            clearDepartmentScoreCache($dept, $A_YEAR, true);
        }
        
        $record_id = mysqli_insert_id($conn);
        $_SESSION['success'] = "Data entered successfully for academic year $A_YEAR.";
    }

    // Process country-wise student data
    $country_codes = $_POST['country_codes'] ?? [];
    $country_students = $_POST['country_students'] ?? [];

    // Validate p_code_int and programme_name before processing country data
    if (!isset($p_code_int) || $p_code_int <= 0) {
        error_log("[Intake] ERROR: Invalid program code ($p_code) - country data will not be saved. p_code_int: " . ($p_code_int ?? 'undefined'));
    } elseif (empty($programme_name)) {
        error_log("[Intake] ERROR: Programme name is empty - country data will not be saved. Programme code: $p_code");
    } else {
        if (!empty($country_codes) && is_array($country_codes)) {
            for ($i = 0; $i < count($country_codes); $i++) {
                $country_code_str = trim($country_codes[$i] ?? '');
                $student_count = (int) ($country_students[$i] ?? 0);

                // Convert country code to integer (database expects bigint)
                $country_code = !empty($country_code_str) ? (int) $country_code_str : 0;

                if ($country_code > 0 && $student_count > 0 && $p_code_int > 0) {
                // Check if country record exists
                $check_country_query = "SELECT ID FROM country_wise_student 
                    WHERE A_YEAR = ? AND DEPT_ID = ? AND PROGRAM_CODE = ? AND COUNTRY_CODE = ?";
                $check_stmt = mysqli_prepare($conn, $check_country_query);
                if ($check_stmt) {
                    mysqli_stmt_bind_param($check_stmt, "siii", $A_YEAR, $dept, $p_code_int, $country_code);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);

                    if ($check_result && mysqli_num_rows($check_result) > 0) {
                        // UPDATE existing country record
                        $existing_row = mysqli_fetch_assoc($check_result);
                        $country_id = $existing_row['ID'];
                        
                        // Ensure programme_name is set and not empty
                        if (empty($programme_name)) {
                            error_log("[Intake] ERROR: Programme name is empty during UPDATE - Country code: $country_code, ID: $country_id");
                            // Try to get programme name from database if available
                            $prog_name_query = "SELECT programme_name FROM programmes WHERE programme_code = ? AND DEPT_ID = ? LIMIT 1";
                            $prog_stmt = mysqli_prepare($conn, $prog_name_query);
                            if ($prog_stmt) {
                                mysqli_stmt_bind_param($prog_stmt, "si", $p_code, $dept);
                                mysqli_stmt_execute($prog_stmt);
                                $prog_result = mysqli_stmt_get_result($prog_stmt);
                                if ($prog_result && mysqli_num_rows($prog_result) > 0) {
                                    $prog_row = mysqli_fetch_assoc($prog_result);
                                    $programme_name = $prog_row['programme_name'];
                                }
                                mysqli_stmt_close($prog_stmt);
                            }
                        }
                        
                        $update_country = "UPDATE country_wise_student 
                            SET NO_OF_STUDENT_COUNTRY = ?, PROGRAM_NAME = ? WHERE ID = ?";
                        $update_stmt = mysqli_prepare($conn, $update_country);
                        if ($update_stmt) {
                            mysqli_stmt_bind_param($update_stmt, "isi", $student_count, $programme_name, $country_id);
                            if (!mysqli_stmt_execute($update_stmt)) {
                                error_log("Country update failed: " . mysqli_stmt_error($update_stmt) . " - Country code: $country_code, Count: $student_count, Program: $programme_name");
                            } else {
                                error_log("[Intake] Country updated successfully - Program: $programme_name, Country: $country_code, Count: $student_count");
                            }
                            mysqli_stmt_close($update_stmt);
                        }
                    } else {
                        // INSERT new country record
                        // Ensure programme_name is set and not empty before inserting
                        if (empty($programme_name)) {
                            error_log("[Intake] ERROR: Programme name is empty during INSERT - Country code: $country_code, p_code: $p_code");
                            // Try to get programme name from database if available
                            $prog_name_query = "SELECT programme_name FROM programmes WHERE programme_code = ? AND DEPT_ID = ? LIMIT 1";
                            $prog_stmt = mysqli_prepare($conn, $prog_name_query);
                            if ($prog_stmt) {
                                mysqli_stmt_bind_param($prog_stmt, "si", $p_code, $dept);
                                mysqli_stmt_execute($prog_stmt);
                                $prog_result = mysqli_stmt_get_result($prog_stmt);
                                if ($prog_result && mysqli_num_rows($prog_result) > 0) {
                                    $prog_row = mysqli_fetch_assoc($prog_result);
                                    $programme_name = $prog_row['programme_name'];
                                    error_log("[Intake] Retrieved programme name from database: $programme_name");
                                }
                                mysqli_stmt_close($prog_stmt);
                            }
                        }
                        
                        // Only insert if programme_name is not empty
                        if (!empty($programme_name)) {
                            $insert_country = "INSERT INTO country_wise_student 
                                (A_YEAR, DEPT_ID, PROGRAM_CODE, PROGRAM_NAME, COUNTRY_CODE, NO_OF_STUDENT_COUNTRY) 
                                VALUES (?, ?, ?, ?, ?, ?)";
                            $insert_stmt = mysqli_prepare($conn, $insert_country);
                            if ($insert_stmt) {
                                mysqli_stmt_bind_param(
                                    $insert_stmt,
                                    "siiisi",
                                    $A_YEAR,
                                    $dept,
                                    $p_code_int,
                                    $programme_name,
                                    $country_code,
                                    $student_count
                                );
                                if (!mysqli_stmt_execute($insert_stmt)) {
                                    error_log("Country insert failed: " . mysqli_stmt_error($insert_stmt) . " - Country code: $country_code, Count: $student_count, Program: $programme_name, p_code_int: $p_code_int");
                                } else {
                                    error_log("[Intake] Country inserted successfully - Program: $programme_name, Country: $country_code, Count: $student_count, PROGRAM_CODE: $p_code_int");
                                }
                                mysqli_stmt_close($insert_stmt);
                            } else {
                                error_log("Country insert prepare failed: " . mysqli_error($conn) . " - Program: $programme_name");
                            }
                        } else {
                            error_log("[Intake] ERROR: Skipping country insert - Programme name is empty for Country code: $country_code, p_code: $p_code");
                        }
                    }
                    mysqli_stmt_close($check_stmt);
                } else {
                    error_log("Country check prepare failed: " . mysqli_error($conn));
                }
                }
            }
        }
    }

    // Redirect to clean state
    header('Location: IntakeActualStrength.php');
    exit;
}

// ============================================================================
// DELETE/CLEAR HANDLERS - MUST BE BEFORE unified_header.php
// ============================================================================

// Handle POST-based AJAX delete request (from JavaScript clearAllData function)
if (isset($_POST['action']) && $_POST['action'] == 'delete_record') {
    // Clear all output buffers FIRST
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Suppress all errors and warnings to prevent HTML output
    error_reporting(0);
    ini_set('display_errors', 0);

    // Set JSON headers BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    try {
        $record_id = (int)($_POST['record_id'] ?? 0);

        if ($record_id <= 0) {
            throw new Exception('Invalid record ID');
        }

        // Get department ID from session
        $dept_id = $userInfo['DEPT_ID'] ?? 0;

        if (!$dept_id) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }

        // Get the record details first
        $get_record_query = "SELECT PROGRAM_CODE, A_YEAR FROM intake_actual_strength 
            WHERE ID = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $get_record_query);
        mysqli_stmt_bind_param($stmt, "ii", $record_id, $dept_id);
        mysqli_stmt_execute($stmt);
        $record_result = mysqli_stmt_get_result($stmt);

        if ($record_result && mysqli_num_rows($record_result) > 0) {
            $record = mysqli_fetch_assoc($record_result);
            $program_code = $record['PROGRAM_CODE'];
            $academic_year = $record['A_YEAR'];
            mysqli_free_result($record_result);
            mysqli_stmt_close($stmt);

            // Start transaction for data consistency
            mysqli_begin_transaction($conn);

            try {
                // 1. Delete country-wise student data
                $delete_country = "DELETE FROM country_wise_student 
                    WHERE DEPT_ID = ? AND PROGRAM_CODE = ? AND A_YEAR = ?";
                $stmt = mysqli_prepare($conn, $delete_country);
                mysqli_stmt_bind_param($stmt, "iss", $dept_id, $program_code, $academic_year);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                // 2. Soft delete supporting documents
                $delete_docs = "UPDATE supporting_documents 
                    SET status = 'deleted', updated_date = CURRENT_TIMESTAMP 
                    WHERE dept_id = ? AND program_id = ? AND academic_year = ? 
                    AND page_section = 'intake_actual_strength'";
                $stmt = mysqli_prepare($conn, $delete_docs);
                mysqli_stmt_bind_param($stmt, "iss", $dept_id, $program_code, $academic_year);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                // 3. Delete main intake record
                $delete_main = "DELETE FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ?";
                $stmt = mysqli_prepare($conn, $delete_main);
                mysqli_stmt_bind_param($stmt, "ii", $record_id, $dept_id);

                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);

                    // Commit transaction
                    mysqli_commit($conn);

                    echo json_encode(['success' => true, 'message' => 'Record deleted successfully']);
                } else {
                    throw new Exception(mysqli_stmt_error($stmt));
                }
            } catch (Exception $e) {
                // Rollback on error
                mysqli_rollback($conn);
                throw new Exception('Error deleting record: ' . $e->getMessage());
            }
        } else {
            if ($record_result) {
                mysqli_free_result($record_result);
            }
            if ($stmt) {
                mysqli_stmt_close($stmt);
            }
            throw new Exception('Record not found or you don\'t have permission to delete this record');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    exit;
}

// Handle GET-based delete/clear actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action == 'delete') {
        $id = (int)$_GET['ID'];

        // Get the record details first
        $get_record_query = "SELECT PROGRAM_CODE, A_YEAR FROM intake_actual_strength 
            WHERE ID = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $get_record_query);
        mysqli_stmt_bind_param($stmt, "ii", $id, $dept);
        mysqli_stmt_execute($stmt);
        $record_result = mysqli_stmt_get_result($stmt);

        if ($record_result && mysqli_num_rows($record_result) > 0) {
            $record = mysqli_fetch_assoc($record_result);
            $program_code = $record['PROGRAM_CODE'];
            $academic_year = $record['A_YEAR'];
            mysqli_stmt_close($stmt);

            // Start transaction for data consistency
            mysqli_begin_transaction($conn);

            try {
                // 1. Delete country-wise student data
                $delete_country = "DELETE FROM country_wise_student 
                    WHERE DEPT_ID = ? AND PROGRAM_CODE = ? AND A_YEAR = ?";
                $stmt = mysqli_prepare($conn, $delete_country);
                mysqli_stmt_bind_param($stmt, "iss", $dept, $program_code, $academic_year);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                // 2. Soft delete supporting documents
                $delete_docs = "UPDATE supporting_documents 
                    SET status = 'deleted', updated_date = CURRENT_TIMESTAMP 
                    WHERE dept_id = ? AND program_id = ? AND academic_year = ? 
                    AND page_section = 'intake_actual_strength'";
                $stmt = mysqli_prepare($conn, $delete_docs);
                mysqli_stmt_bind_param($stmt, "iss", $dept, $program_code, $academic_year);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                // 3. Delete main intake record
                $delete_main = "DELETE FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ?";
                $stmt = mysqli_prepare($conn, $delete_main);
                mysqli_stmt_bind_param($stmt, "ii", $id, $dept);

                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);

                    // Commit transaction
                    mysqli_commit($conn);

                    $_SESSION['success'] = '✅ Record and associated data deleted successfully!';
                } else {
                    throw new Exception(mysqli_stmt_error($stmt));
                }
            } catch (Exception $e) {
                // Rollback on error
                mysqli_rollback($conn);
                $_SESSION['error'] = '❌ Error deleting record: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = '❌ Record not found or you don\'t have permission to delete this record!';
        }

        header('Location: IntakeActualStrength.php');
        exit;
    }

    if ($action == 'delete_country') {
        $id = (int)$_GET['ID'];
        $delete_query = "DELETE FROM country_wise_student WHERE ID = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($stmt, "ii", $id, $dept);

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = 'Country data deleted successfully';
        } else {
            $_SESSION['error'] = 'Failed to delete country data';
        }
        mysqli_stmt_close($stmt);

        header('Location: IntakeActualStrength.php');
        exit;
    }

    if ($action == 'clear_data') {
        if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
            mysqli_begin_transaction($conn);

            try {
                // Delete all intake records for this year
                $delete_intake = "DELETE FROM intake_actual_strength WHERE DEPT_ID = ? AND A_YEAR = ?";
                $stmt = mysqli_prepare($conn, $delete_intake);
                mysqli_stmt_bind_param($stmt, "is", $dept, $A_YEAR);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                // Delete country data
                $delete_countries = "DELETE FROM country_wise_student WHERE DEPT_ID = ? AND A_YEAR = ?";
                $stmt = mysqli_prepare($conn, $delete_countries);
                mysqli_stmt_bind_param($stmt, "is", $dept, $A_YEAR);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                // Soft delete documents
                $delete_docs = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP 
                    WHERE dept_id = ? AND academic_year = ? AND page_section = 'intake_actual_strength'";
                $stmt = mysqli_prepare($conn, $delete_docs);
                mysqli_stmt_bind_param($stmt, "is", $dept, $A_YEAR);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);
                $_SESSION['success'] = "✅ All data cleared successfully for academic year $A_YEAR!";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $_SESSION['error'] = "❌ Error clearing data: " . $e->getMessage();
            }

            header('Location: IntakeActualStrength.php');
            exit;
        } else {
            // Clear all buffers before JavaScript output
            while (ob_get_level()) {
                ob_end_clean();
            }
            echo '<script>
                if (confirm("⚠️ Are you sure you want to clear ALL intake data for this academic year? This action cannot be undone!")) {
                    window.location.href = "IntakeActualStrength.php?action=clear_data&confirm=yes";
                } else {
                    window.location.href = "IntakeActualStrength.php";
                }
            </script>';
            exit;
        }
    }
}

// ============================================================================
// HTML OUTPUT STARTS HERE
// ============================================================================
// #######
require('unified_header.php');

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CSRF validation function
function validateCSRF($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// These handlers have been moved to the top of the file (before unified_header.php)
// to prevent HTML output from corrupting JSON responses

// Duplicate handler removed - check_program_data handler is now at the top of the file (line 6)
// This prevents HTML output from unified_header.php from corrupting JSON responses

// Duplicate handler removed - get_record_data handler is now at the top of the file (before session.php)
// This prevents HTML output from unified_header.php from corrupting JSON responses

// Handle document download
if (isset($_GET['download_doc']) && isset($_GET['file_id']) && isset($_GET['srno'])) {
    try {
        $file_id = (int)$_GET['file_id'];
        $srno = (int)$_GET['srno'];

        // Get current academic year
        $current_year = date('Y');
        if (date('m') >= 6) {
            $academic_year = $current_year . '-' . ($current_year + 1);
        } else {
            $academic_year = ($current_year - 1) . '-' . $current_year;
        }

        $dept = $_SESSION['dept_id'] ?? null;

        // Check if dept_id is set (same as main page)
        if (empty($dept)) {
            header('HTTP/1.1 404 Not Found');
            echo 'Department ID not found in session. Please login again.';
            exit;
        }

        // Get program_code from request
        $program_code = trim($_GET['program_code'] ?? '');

        if (empty($program_code)) {
            header('HTTP/1.1 400 Bad Request');
            echo 'Program code is required';
            exit;
        }

        // CRITICAL: Use unique section_name format for query (same as upload/delete handlers)
        $unique_section_name = 'Intake Actual Strength_PROG_' . $program_code;
        
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
        
        $query = "SELECT file_path, file_name, academic_year FROM supporting_documents 
            WHERE dept_id = ? AND page_section = 'intake_actual_strength' 
            AND serial_number = ? AND section_name = ? 
            AND (academic_year = ? OR academic_year = ?) AND status = 'active' 
            ORDER BY academic_year DESC, id DESC LIMIT 1";

        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Database error';
            exit;
        }
        mysqli_stmt_bind_param($stmt, "isss", $dept, $srno, $unique_section_name, $academic_year, $prev_year);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) > 0) {
            $file_data = mysqli_fetch_assoc($result);
            $file_path = $file_data['file_path'];
            $file_name = $file_data['file_name'];
            $doc_year = $file_data['academic_year'] ?? $academic_year;
            mysqli_free_result($result);  // CRITICAL: Free result
            mysqli_stmt_close($stmt);     // CRITICAL: Close statement

            // Convert to absolute path for file_exists check (use document's actual academic year)
            $physical_path = $file_path;
            $project_root = dirname(__DIR__);
            
            // Handle various path formats
            if (strpos($physical_path, $project_root) === 0) {
                // Already absolute path
                $physical_path = $file_path;
            } elseif (strpos($physical_path, '../') === 0) {
                $physical_path = $project_root . '/' . str_replace('../', '', $physical_path);
            } elseif (strpos($physical_path, 'uploads/') === 0) {
                $physical_path = $project_root . '/' . $physical_path;
            } else {
                // Fallback: construct path with program_code folder structure using document's academic year
                $filename = basename($file_path);
                $physical_path = $project_root . "/uploads/{$doc_year}/DEPARTMENT/{$dept}/intake_actual_strength/{$program_code}/{$filename}";
            }

            // Normalize path separators
            $physical_path = str_replace('\\', '/', $physical_path);

            if (file_exists($physical_path)) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . htmlspecialchars($file_name) . '"');
                header('Content-Length: ' . filesize($physical_path));
                readfile($physical_path);
                exit;
            } else {
                // Try with program code folder structure (new structure) - extract filename from path
                $filename = basename($file_path);
                $file_with_prog = $project_root . "/uploads/{$academic_year}/DEPARTMENT/{$dept}/intake_actual_strength/{$program_code}/{$filename}";
                $file_with_prog = str_replace('\\', '/', $file_with_prog);
                
                if (file_exists($file_with_prog)) {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="' . htmlspecialchars($file_name) . '"');
                    header('Content-Length: ' . filesize($file_with_prog));
                    readfile($file_with_prog);
                    exit;
                } else {
                    // Try one more time - check if path in DB already includes program_code
                    $db_path_with_prog = $project_root . "/uploads/{$academic_year}/DEPARTMENT/{$dept}/intake_actual_strength/{$program_code}/{$filename}";
                    if (file_exists($db_path_with_prog)) {
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: inline; filename="' . htmlspecialchars($file_name) . '"');
                        header('Content-Length: ' . filesize($db_path_with_prog));
                        readfile($db_path_with_prog);
                        exit;
                    }
                    throw new Exception('File not found on server. Paths checked: ' . $physical_path . ', ' . $file_with_prog);
                }
            }
        } else {
            throw new Exception('Document not found in database');
        }
    } catch (Exception $e) {
        header('HTTP/1.1 404 Not Found');
        echo 'Document not found: ' . $e->getMessage();
        exit;
    }
}


// Handle document status check - SECOND HANDLER (for backward compatibility)
// CRITICAL: Follow all checklist items to prevent crashes and unnecessary requests
if (isset($_GET['check_doc']) && isset($_GET['file_id']) && isset($_GET['srno'])) {
    // CRITICAL #1: Clear ALL output buffers first to prevent any output before JSON
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
    
    // Clear any remaining output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Suppress error reporting for clean JSON output
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
        $file_id = $_GET['file_id'] ?? '';
        $srno = (int)($_GET['srno'] ?? 0);
        $program_code = trim((string)($_GET['program_code'] ?? ''));

        // Get department ID
        $dept = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;

        // Validate inputs
        if (empty($dept) || $dept == 0) {
            throw new Exception('Department ID not found. Please login again.');
        }

        if (empty($program_code)) {
            throw new Exception('Program code is required.');
        }

        // Calculate academic year - use centralized function
        if (!function_exists('getAcademicYear')) {
            if (file_exists(__DIR__ . '/common_functions.php')) {
                require_once(__DIR__ . '/common_functions.php');
            }
        }
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

        // CRITICAL: Use unique section_name format for query (same as upload handler)
        $unique_section_name = 'Intake Actual Strength_PROG_' . $program_code;

        // Check if document exists using unique section_name (primary method)
        $check_query = "SELECT id, file_path, file_name, file_size, upload_date FROM supporting_documents 
                       WHERE dept_id = ? 
                       AND page_section = 'intake_actual_strength' 
                       AND serial_number = ? 
                       AND section_name = ?
                       AND academic_year = ?
                       AND status = 'active' 
                       ORDER BY id DESC LIMIT 1";
        $stmt = mysqli_prepare($conn, $check_query);
        if (!$stmt) {
            throw new Exception('Database error: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "iiss", $dept, $srno, $unique_section_name, $A_YEAR);
        
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new Exception('Database execution error: ' . mysqli_stmt_error($stmt));
        }
        
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) > 0) {
            $doc = mysqli_fetch_assoc($result);
            // CRITICAL: Free result before closing statement
            mysqli_free_result($result);
            mysqli_stmt_close($stmt);
            
            $file_path = $doc['file_path'];

            // Convert absolute paths to relative web paths (handle new program-specific folder structure)
            $project_root = dirname(__DIR__);
            
            // Handle absolute paths
            if (strpos($file_path, $project_root) === 0) {
                // Extract relative path from absolute path (includes program_code folder)
                $file_path = str_replace($project_root . '/', '', $file_path);
                // Normalize path separators
                $file_path = str_replace('\\', '/', $file_path);
            } elseif (strpos($file_path, '/home/') === 0 || strpos($file_path, 'C:/') === 0 || strpos($file_path, 'C:\\') === 0) {
                // Try to extract relative path from absolute path (includes program_code folder)
                if (preg_match('/uploads\/[\d\-]+\/DEPARTMENT\/\d+\/intake_actual_strength\/[^\/]+\/.+\.pdf$/', $file_path, $matches)) {
                    $file_path = $matches[0];
                } else {
                    // Construct relative path with program code folder
                    $filename = basename($file_path);
                    // Get academic year for path construction
                    // CRITICAL FIX: Calculate academic year correctly - use centralized function
                    if (function_exists('getAcademicYear')) {
                        $academic_year = getAcademicYear();
                    } else {
                        $current_year = (int)date('Y');
                        $current_month = (int)date('n');
                        $academic_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
                    }
                    $file_path = 'uploads/' . $academic_year . '/DEPARTMENT/' . $dept . '/intake_actual_strength/' . $program_code . '/' . $filename;
                }
            }

            // Ensure path doesn't start with ./
            $file_path = ltrim($file_path, './');
            
            // Normalize path separators
            $file_path = str_replace('\\', '/', $file_path);

            // If path starts with uploads/, prepend ../ for access from dept_login directory
            if (strpos($file_path, 'uploads/') === 0) {
                $file_path = '../' . $file_path;
            } elseif (strpos($file_path, '../') !== 0) {
                // If no prefix, ensure it has the correct structure
                if (!strpos($file_path, 'intake_actual_strength/' . $program_code . '/')) {
                    // Path doesn't include program_code folder - reconstruct it
                    // Use A_YEAR format: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/intake_actual_strength/{program_code}/FILENAME.pdf
                    $filename = basename($file_path);
                    $current_year = (int)date('Y');
                    $current_month = (int)date('n');
                    if (function_exists('getAcademicYear')) {
                    $a_year = getAcademicYear();
                } else {
                    $current_year = (int)date('Y');
                    $current_month = (int)date('n');
                    $a_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
                }
                    $file_path = '../uploads/' . $a_year . '/DEPARTMENT/' . $dept . '/intake_actual_strength/' . $program_code . '/' . $filename;
                }
            }
            
            // Keep A_YEAR format (e.g., 2024-2025) for consistency with upload paths
            // No conversion needed - A_YEAR format is the standard

            // CRITICAL #2: Build response in variable
            $response = [
                'success' => true,
                'file_path' => $file_path,
                'file_name' => $doc['file_name'],
                'file_size' => $doc['file_size'],
                'upload_date' => $doc['upload_date'] ?? null,
                'document_id' => $doc['id'] ?? null
            ];
        } else {
            // CRITICAL: Free result and close statement even if no rows found
            if ($result) {
                mysqli_free_result($result);
            }
            mysqli_stmt_close($stmt);
            
            // CRITICAL #2: Build response in variable
            $response = [
                'success' => false,
                'message' => 'No document found'
            ];
        }
    } catch (Exception $e) {
        // CRITICAL #2: Build error response in variable
        $response = [
            'success' => false,
            'message' => 'Check failed: ' . $e->getMessage()
        ];
    } catch (Throwable $e) {
        // CRITICAL #2: Build error response in variable
        $response = [
            'success' => false,
            'message' => 'Check failed: ' . $e->getMessage()
        ];
    }

    // CRITICAL #2: Output response ONCE at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Academic year - use centralized function
if (!function_exists('getAcademicYear')) {
    if (file_exists(__DIR__ . '/common_functions.php')) {
        require_once(__DIR__ . '/common_functions.php');
    }
}
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    $A_YEAR = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
}

$dept = $_SESSION['dept_id'];
$locked_program_data = null;
$form_locked = false; // Add form locked flag like ExecutiveDevelopment.php

// Check if data already exists for this academic year (like ExecutiveDevelopment.php)
$existing_data = null;
$check_query = "SELECT * FROM intake_actual_strength WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $check_query);
mysqli_stmt_bind_param($stmt, "is", $dept, $A_YEAR);
mysqli_stmt_execute($stmt);
$check_result = mysqli_stmt_get_result($stmt);

if ($check_result && mysqli_num_rows($check_result) > 0) {
    $existing_data = mysqli_fetch_assoc($check_result);
    $form_locked = true; // Lock form if data exists for this academic year
}

// Check if we're in edit mode or loading existing data
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['ID'])) {
    $record_id = (int)$_GET['ID'];
    $query = "SELECT * FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $record_id, $dept);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        $existing_record = mysqli_fetch_assoc($result);
        $form_state = 'edit';
        $is_edit_mode = true;
        $selected_program_id = $existing_record['PROGRAM_CODE'];
    }
} else {
    // Initialize form state for new entry
    $form_state = 'new';
    $is_edit_mode = false;
    $selected_program_id = null;
}

// POST delete_record handler has been moved to BEFORE unified_header.php (line ~2130)
// This prevents "headers already sent" errors

// Get programs for dropdown
// CRITICAL: Use COALESCE to prefer total_intake over intake_capacity
// total_intake is calculated from year-wise breakdowns and is the actual value shown in Programmes_Offered
$programs_query = "SELECT id, programme_code, programme_name, 
                    COALESCE(NULLIF(total_intake, 0), intake_capacity, 0) AS intake_capacity 
                    FROM programmes WHERE DEPT_ID = ? ORDER BY programme_name";
$stmt = mysqli_prepare($conn, $programs_query);
mysqli_stmt_bind_param($stmt, "i", $dept);
mysqli_stmt_execute($stmt);
$programs_result = mysqli_stmt_get_result($stmt);

// Initialize form state variables
$form_state = 'new'; // new, edit, locked
$existing_record = null;
$record_id = null;
$is_edit_mode = false;
$selected_program_id = null;
$locked_program_data = null;
$form_locked = false; // Add form locked flag like ExecutiveDevelopment.php

// Check if we're in edit mode or loading existing data
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['ID'])) {
    $record_id = (int)$_GET['ID'];
    $query = "SELECT * FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $record_id, $dept);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        $existing_record = mysqli_fetch_assoc($result);
        $form_state = 'edit';
        $is_edit_mode = true;
        $selected_program_id = $existing_record['PROGRAM_CODE'];
    }
} else {
    // Check if we have a locked program from recent submission
    if (isset($_GET['locked']) && isset($_GET['program_code'])) {
        $locked_program_code = trim($_GET['program_code']);
        $locked_query = "SELECT * FROM intake_actual_strength WHERE DEPT_ID = ? AND PROGRAM_CODE = ? AND A_YEAR = ? ORDER BY updated_at DESC LIMIT 1";
        $stmt = mysqli_prepare($conn, $locked_query);
        mysqli_stmt_bind_param($stmt, "iss", $dept, $locked_program_code, $A_YEAR);
        mysqli_stmt_execute($stmt);
        $locked_result = mysqli_stmt_get_result($stmt);

        if ($locked_result && mysqli_num_rows($locked_result) > 0) {
            $locked_program_data = mysqli_fetch_assoc($locked_result);
            $form_state = 'locked';
            $form_locked = true; // Set form locked flag
            $record_id = $locked_program_data['ID'];

            // Get the program ID from programmes table using the program code
            $program_id_query = "SELECT id FROM programmes WHERE programme_code = ? AND DEPT_ID = ?";
            $stmt = mysqli_prepare($conn, $program_id_query);
            mysqli_stmt_bind_param($stmt, "si", $locked_program_code, $dept);
            mysqli_stmt_execute($stmt);
            $program_id_result = mysqli_stmt_get_result($stmt);
            if ($program_id_result && mysqli_num_rows($program_id_result) > 0) {
                $program_data = mysqli_fetch_assoc($program_id_result);
                $selected_program_id = $program_data['id'];
            } else {
                $selected_program_id = null;
            }
        } else {
            $form_state = 'new';
            $form_locked = false;
            $existing_record = null;
            $record_id = null;
            $selected_program_id = null;
        }
    } else {
        // For new form, don't auto-select any program
        // The program will be selected via JavaScript when user chooses from dropdown
        $form_state = 'new';
        $existing_record = null;
        $record_id = null;
        $selected_program_id = null;
    }
}

// Function to get department information
function getDepartmentInfoByDeptId($conn, $dept_id)
{
    $query = "SELECT DEPT_ID, DEPT_NAME FROM department_master WHERE DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $dept_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        return [
            'DEPT_ID' => $row['DEPT_ID'],
            'code' => $row['DEPT_ID'],
            'name' => $row['DEPT_NAME'],
            'DEPT_NAME' => $row['DEPT_NAME']
        ];
    }

    mysqli_stmt_close($stmt);
    // Return default values if department not found
    return [
        'DEPT_ID' => $dept_id,
        'code' => $dept_id,
        'name' => 'Department',
        'DEPT_NAME' => 'Department'
    ];
}

// Handle temporary document upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_temp_document'])) {
    // Start session for AJAX handlers
    if (session_status() == PHP_SESSION_NONE) {
    }


    // Get department ID after session is started
    $dept = $_SESSION['dept_id'] ?? 0;

    // Check if department ID is valid
    if (empty($dept) || $dept == 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Department ID not found. Please login again.']);
        exit;
    }

    // Suppress error reporting and start output buffering for AJAX
    error_reporting(0);
    ini_set('display_errors', 0);
    ob_start();

    header('Content-Type: application/json');

    // Clear any existing output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Start fresh output buffer
    ob_start();

    try {
        $program_code = trim($_POST['program_id'] ?? ''); // This is now programme_code
        $document_type = trim($_POST['document_type'] ?? '');

        if (empty($program_code)) {
            throw new Exception('Program code is required');
        }

        if (empty($document_type)) {
            throw new Exception('Document type is required');
        }

        // Get programme details from programmes table
        $programme_query = "SELECT programme_code, programme_name FROM programmes WHERE programme_code = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $programme_query);
        mysqli_stmt_bind_param($stmt, "si", $program_code, $dept);
        mysqli_stmt_execute($stmt);
        $programme_result = mysqli_stmt_get_result($stmt);

        if (!$programme_result || mysqli_num_rows($programme_result) == 0) {
            throw new Exception('Program not found');
        }

        $programme_data = mysqli_fetch_assoc($programme_result);
        $programme_code = $programme_data['programme_code'];
        $programme_name = $programme_data['programme_name'];

        // Check if file was uploaded
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error');
        }

        $file = $_FILES['document'];

        // Validate file type
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_extension !== 'pdf') {
            throw new Exception('Only PDF files are allowed');
        }

        // Validate file size (5MB limit)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('File size must be less than 5MB');
        }

        // Create temporary directory if it doesn't exist
        $temp_dir = "../uploads/temp_intake_docs/";
        if (!file_exists($temp_dir)) {
            if (!mkdir($temp_dir, 0777, true)) {
                throw new Exception('Failed to create temporary directory');
            }
        }

        // Generate unique filename
        $temp_filename = "intake_doc_" . $programme_code . "_" . $document_type . "_" . time() . "_" . rand(1000, 9999) . ".pdf";
        $temp_file_path = $temp_dir . $temp_filename;

        // Move uploaded file to temporary location
        if (!move_uploaded_file($file['tmp_name'], $temp_file_path)) {
            throw new Exception('Failed to save uploaded file');
        }

        // Store temporary file info in session
        if (!isset($_SESSION['temp_intake_docs'])) {
            $_SESSION['temp_intake_docs'] = [];
        }

        // Use programme_code as key to match with form submission
        $key = $programme_code . '_' . $document_type;
        $_SESSION['temp_intake_docs'][$key] = [
            'file_path' => $temp_file_path,
            'file_name' => $file['name'],
            'file_size' => $file['size'],
            'programme_code' => $programme_code,
            'programme_name' => $programme_name,
            'document_type' => $document_type,
            'section_name' => 'Intake Actual Strength Documentation'
        ];

        echo json_encode([
            'success' => true,
            'message' => 'Document uploaded successfully',
            'file_name' => $file['name'],
            'file_size' => $file['size'],
            'programme_code' => $programme_code,
            'programme_name' => $programme_name
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle temporary document deletion
if (isset($_GET['delete_temp_document'])) {
    header('Content-Type: application/json');

    $temp_id = (int)$_GET['temp_id'];

    if (isset($_SESSION['temp_documents'][$temp_id])) {
        $temp_doc = $_SESSION['temp_documents'][$temp_id];

        // Delete file from filesystem
        if (file_exists($temp_doc['file_path'])) {
            unlink($temp_doc['file_path']);
        }

        // Remove from session
        unset($_SESSION['temp_documents'][$temp_id]);
        $_SESSION['temp_documents'] = array_values($_SESSION['temp_documents']); // Reindex array

        echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
    }
    exit;
}

// Handle permanent document deletion from database - SAFE DELETE WITH PROPER RESOURCE CLEANUP
if (isset($_GET['delete_document'])) {
    // CRITICAL: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Start session and load config FIRST (silently)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
    ob_start();
    if (!isset($conn) || !$conn) {
    require_once(__DIR__ . '/../config.php');
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
    
    // CRITICAL #4: Set headers BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $document_id = (int)($_GET['document_id'] ?? 0);
        $dept_id = $userInfo['DEPT_ID'] ?? $dept ?? 0;
        
        if ($document_id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid document ID'];
        } elseif (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found'];
        } else {
            // Get file path and program_id from database using prepared statement
            $get_file_query = "SELECT file_path, program_id FROM supporting_documents WHERE id = ? AND dept_id = ? AND page_section = 'intake_actual_strength' AND status = 'active' LIMIT 1";
            $stmt_get = mysqli_prepare($conn, $get_file_query);
            if ($stmt_get) {
                mysqli_stmt_bind_param($stmt_get, 'ii', $document_id, $dept_id);
                if (mysqli_stmt_execute($stmt_get)) {
                    $result_get = mysqli_stmt_get_result($stmt_get);
                    if ($result_get && mysqli_num_rows($result_get) > 0) {
                        $row = mysqli_fetch_assoc($result_get);
        $file_path = $row['file_path'];
                        $program_id = $row['program_id'] ?? '';
                        mysqli_free_result($result_get);  // CRITICAL: Free result
                        mysqli_stmt_close($stmt_get);
                        
                        // Delete physical file safely
                        $project_root = dirname(__DIR__);
                        $phys_path = $file_path;
                        
                        // Convert relative to absolute path
                        if (strpos($phys_path, '../') === 0) {
                            $phys_path = $project_root . '/' . str_replace('../', '', $phys_path);
                        } elseif (strpos($phys_path, 'uploads/') === 0) {
                            $phys_path = $project_root . '/' . $phys_path;
                        } elseif (strpos($phys_path, $project_root) !== 0) {
                            // Reconstruct path
                            $current_year = (int)date('Y');
                            $current_month = (int)date('n');
                            if (function_exists('getAcademicYear')) {
                    $a_year = getAcademicYear();
                } else {
                    $current_year = (int)date('Y');
                    $current_month = (int)date('n');
                    $a_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
                }
                            $filename = basename($phys_path);
                            $phys_path = $project_root . "/uploads/{$a_year}/DEPARTMENT/{$dept_id}/intake_actual_strength/" . ($program_id ?: '') . "/{$filename}";
                        }
                        $phys_path = str_replace('\\', '/', $phys_path);
                        
                        // Try to delete file (suppress errors if file doesn't exist)
                        if ($phys_path && file_exists($phys_path)) {
                            @unlink($phys_path);
        }

                        // Soft delete from database using prepared statement
        $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE id = ? AND dept_id = ?";
                        $stmt_del = mysqli_prepare($conn, $delete_query);
                        if ($stmt_del) {
                            mysqli_stmt_bind_param($stmt_del, 'ii', $document_id, $dept_id);
                            if (mysqli_stmt_execute($stmt_del)) {
                                $response = ['success' => true, 'message' => 'Document deleted successfully'];
        } else {
                                $response = ['success' => false, 'message' => 'Database error: Failed to update record'];
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
            } else {
                $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
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

// Handle record update BEFORE main form submission
if (isset($_POST['update_record'])) {
    // Start session for form submission
    if (session_status() == PHP_SESSION_NONE) {
    }

    // Get department ID from session (already verified in session.php)
    $dept = $userInfo['DEPT_ID'];

    if (!$dept) {
        echo "<script>alert('❌ Error: Department ID not found. Please contact administrator.');</script>";
        exit;
    }

    // ============================================================================
    // CORRECT ACADEMIC YEAR LOGIC (July Rollover)
    // ============================================================================
    // If month >= 7 (July onwards): academic year is current_year to current_year+1
    // If month < 7 (January to June): academic year is (current_year-2) to (current_year-1)
    // 
    // Example: January 2026 = 2024-2025, July 2026 = 2026-2027
    // ============================================================================
    $current_year = (int)date('Y');
    $current_month = (int)date('n');

    if ($current_month >= 7) {
        // July onwards: e.g., July 2026 = 2026-2027
        $A_YEAR = $current_year . '-' . ($current_year + 1);
    } else {
        // Before July: e.g., January 2026 = 2024-2025
        $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
    }

    // Validate required fields
    if (empty($_POST['edit_record_id'])) {
        echo "<script>alert('❌ Error: Record ID not found!');</script>";
        exit;
    }

    $record_id = $_POST['edit_record_id'];

    // Sanitize and validate form data
    $Student_Intake = (int)($_POST['edit_total_student_intake'] ?? 0);
    $Male_Students = (int)($_POST['edit_male_students'] ?? 0);
    $Female_Students = (int)($_POST['edit_female_students'] ?? 0);
    $Other_Students = (int)($_POST['edit_other_students'] ?? 0);

    // Validation: Check if numbers are reasonable
    if ($Student_Intake < 0 || $Male_Students < 0 || $Female_Students < 0 || $Other_Students < 0) {
        echo "<script>alert('❌ Error: All values must be positive numbers!');</script>";
        exit;
    }

    // CRITICAL FIX: Map edit form fields to main handler field format
    // Get program code and academic year first
    $get_program_query = "SELECT PROGRAM_CODE, A_YEAR FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ?";
    $get_program_stmt = mysqli_prepare($conn, $get_program_query);
    mysqli_stmt_bind_param($get_program_stmt, "ii", $record_id, $dept);
    mysqli_stmt_execute($get_program_stmt);
    $program_result = mysqli_stmt_get_result($get_program_stmt);
    $program_row = mysqli_fetch_assoc($program_result);
    
    if (!$program_row) {
        echo "<script>alert('❌ Error: Record not found or does not belong to your department!');</script>";
        exit;
    }
    
    $original_program_code = $program_row['PROGRAM_CODE'];
    $A_YEAR = $program_row['A_YEAR'];
    
    // CRITICAL FIX: Map edit form fields (check both edit_* prefix and regular field names)
    // The editIntakeForm uses edit_* prefixed field names, but also check regular names as fallback
    $Student_Intake = (int)($_POST['edit_total_student_intake'] ?? $_POST['Add_Total_Student_Intake'] ?? 0);
    $Male_Students = (int)($_POST['edit_male_students'] ?? $_POST['Total_number_of_Male_Students'] ?? 0);
    $Female_Students = (int)($_POST['edit_female_students'] ?? $_POST['Total_number_of_Female_Students'] ?? 0);
    $Other_Students = (int)($_POST['edit_other_students'] ?? $_POST['Total_number_of_Other_Students'] ?? 0);
    
    // Get all other fields - use same field names as main handler (check both edit_* and regular names)
    $Male_Students_within_state = (int)($_POST['Total_number_of_Male_Students_within_state'] ?? 0);
    $Female_Students_within_state = (int)($_POST['Total_number_of_Female_Students_within_state'] ?? 0);
    $Other_Students_within_state = (int)($_POST['Total_number_of_Other_Students_within_state'] ?? 0);
    $Male_Students_outside_state = (int)($_POST['Male_Students_outside_state'] ?? 0);
    $Female_Students_outside_state = (int)($_POST['Female_Students_outside_state'] ?? 0);
    $Other_Students_outside_state = (int)($_POST['Other_Students_outside_state'] ?? 0);
    $Male_Students_outside_country = (int)($_POST['Male_Students_outside_country'] ?? 0);
    $Female_Students_outside_country = (int)($_POST['Female_Students_outside_country'] ?? 0);
    $Other_Students_outside_country = (int)($_POST['Other_Students_outside_country'] ?? 0);
    
    // Get all other fields from POST (same field names as main handler)
    $Male_Students_Economic_Backward = (int)($_POST['Male_Students_Economic_Backward'] ?? 0);
    $Female_Students_Economic_Backward = (int)($_POST['Female_Students_Economic_Backward'] ?? 0);
    $Other_Students_Economic_Backward = (int)($_POST['Other_Students_Economic_Backward'] ?? 0);
    
    // General Category
    $Male_Student_General = (int)($_POST['Male_Student_General'] ?? 0);
    $Female_Student_General = (int)($_POST['Female_Student_General'] ?? 0);
    $Other_Student_General = (int)($_POST['Other_Student_General'] ?? 0);
    
    // Social Backward Categories (same field names as main handler)
    $Male_Student_Social_Backward_SC = (int)($_POST['Male_Students_Social_Backward_SC'] ?? $_POST['Male_Student_Social_Backward_SC'] ?? 0);
    $Female_Student_Social_Backward_SC = (int)($_POST['Female_Student_Social_Backward_SC'] ?? 0);
    $Other_Student_Social_Backward_SC = (int)($_POST['Other_Student_Social_Backward_SC'] ?? 0);
    $Male_Student_Social_Backward_ST = (int)($_POST['Male_Students_Social_Backward_ST'] ?? $_POST['Male_Student_Social_Backward_ST'] ?? 0);
    $Female_Student_Social_Backward_ST = (int)($_POST['Female_Student_Social_Backward_ST'] ?? 0);
    $Other_Student_Social_Backward_ST = (int)($_POST['Other_Student_Social_Backward_ST'] ?? 0);
    $Male_Student_Social_Backward_OBC = (int)($_POST['Male_Students_Social_Backward_OBC'] ?? $_POST['Male_Student_Social_Backward_OBC'] ?? 0);
    $Female_Student_Social_Backward_OBC = (int)($_POST['Female_Student_Social_Backward_OBC'] ?? 0);
    $Other_Student_Social_Backward_OBC = (int)($_POST['Other_Student_Social_Backward_OBC'] ?? 0);
    $Male_Student_Social_Backward_DTA = (int)($_POST['Male_Students_Social_Backward_DTA'] ?? $_POST['Male_Student_Social_Backward_DTA'] ?? 0);
    $Female_Student_Social_Backward_DTA = (int)($_POST['Female_Student_Social_Backward_DTA'] ?? 0);
    $Other_Student_Social_Backward_DTA = (int)($_POST['Other_Student_Social_Backward_DTA'] ?? 0);
    $Male_Student_Social_Backward_NTB = (int)($_POST['Male_Students_Social_Backward_NTB'] ?? $_POST['Male_Student_Social_Backward_NTB'] ?? 0);
    $Female_Student_Social_Backward_NTB = (int)($_POST['Female_Student_Social_Backward_NTB'] ?? 0);
    $Other_Student_Social_Backward_NTB = (int)($_POST['Other_Student_Social_Backward_NTB'] ?? 0);
    $Male_Student_Social_Backward_NTC = (int)($_POST['Male_Students_Social_Backward_NTC'] ?? $_POST['Male_Student_Social_Backward_NTC'] ?? 0);
    $Female_Student_Social_Backward_NTC = (int)($_POST['Female_Student_Social_Backward_NTC'] ?? 0);
    $Other_Student_Social_Backward_NTC = (int)($_POST['Other_Student_Social_Backward_NTC'] ?? 0);
    $Male_Student_Social_Backward_NTD = (int)($_POST['Male_Students_Social_Backward_NTD'] ?? $_POST['Male_Student_Social_Backward_NTD'] ?? 0);
    $Female_Student_Social_Backward_NTD = (int)($_POST['Female_Student_Social_Backward_NTD'] ?? 0);
    $Other_Student_Social_Backward_NTD = (int)($_POST['Other_Student_Social_Backward_NTD'] ?? 0);
    $Male_Student_Social_Backward_EWS = (int)($_POST['Male_Students_Social_Backward_EWS'] ?? $_POST['Male_Student_Social_Backward_EWS'] ?? 0);
    $Female_Student_Social_Backward_EWS = (int)($_POST['Female_Student_Social_Backward_EWS'] ?? 0);
    $Other_Student_Social_Backward_EWS = (int)($_POST['Other_Student_Social_Backward_EWS'] ?? 0);
    $Male_Student_Social_Backward_SEBC = (int)($_POST['Male_Students_Social_Backward_SEBC'] ?? $_POST['Male_Student_Social_Backward_SEBC'] ?? 0);
    $Female_Student_Social_Backward_SEBC = (int)($_POST['Female_Student_Social_Backward_SEBC'] ?? 0);
    $Other_Student_Social_Backward_SEBC = (int)($_POST['Other_Student_Social_Backward_SEBC'] ?? 0);
    $Male_Student_Social_Backward_SBC = (int)($_POST['Male_Students_Social_Backward_SBC'] ?? $_POST['Male_Student_Social_Backward_SBC'] ?? 0);
    $Female_Student_Social_Backward_SBC = (int)($_POST['Female_Student_Social_Backward_SBC'] ?? 0);
    $Other_Student_Social_Backward_SBC = (int)($_POST['Other_Student_Social_Backward_SBC'] ?? 0);
    
    // Physically Handicapped
    $Male_Student_Physically_Handicapped = (int)($_POST['Male_Students_Physically_Handicapped'] ?? 0);
    $Female_Student_Physically_Handicapped = (int)($_POST['Female_Students_Physically_Handicapped'] ?? 0);
    $Other_Student_Physically_Handicapped = (int)($_POST['Other_Students_Physically_Handicapped'] ?? 0);
    
    // TGO
    $Male_Student_TGO = (int)($_POST['Male_Students_TRANGOVTOF_TGO'] ?? $_POST['Male_Student_TGO'] ?? 0);
    $Female_Student_TGO = (int)($_POST['Female_Students_TRANGOVTOF_TGO'] ?? $_POST['Female_Student_TGO'] ?? 0);
    $Other_Student_TGO = (int)($_POST['Other_Students_TRANGOVTOF_TGO'] ?? $_POST['Other_Student_TGO'] ?? 0);
    
    // CMIL
    $Male_Student_CMIL = (int)($_POST['Male_Students_CMIL'] ?? $_POST['Male_Student_CMIL'] ?? 0);
    $Female_Student_CMIL = (int)($_POST['Female_Student_CMIL'] ?? 0);
    $Other_Student_CMIL = (int)($_POST['Other_Student_CMIL'] ?? 0);
    
    // SPCUL
    $Male_Student_SPCUL = (int)($_POST['Male_Student_SPCUL'] ?? 0);
    $Female_Student_SPCUL = (int)($_POST['Female_Student_SPCUL'] ?? 0);
    $Other_Student_SPCUL = (int)($_POST['Other_Student_SPCUL'] ?? 0);
    
    // Widow Single Mother
    $Male_Student_Widow_Single_Mother = (int)($_POST['Male_Student_Widow_Single_Mother'] ?? 0);
    $Female_Student_Widow_Single_Mother = (int)($_POST['Female_Student_Widow_Single_Mother'] ?? 0);
    $Other_Student_Widow_Single_Mother = (int)($_POST['Other_Student_Widow_Single_Mother'] ?? 0);
    
    // ES
    $Male_Student_ES = (int)($_POST['Male_Student_ES'] ?? 0);
    $Female_Student_ES = (int)($_POST['Female_Student_ES'] ?? 0);
    $Other_Student_ES = (int)($_POST['Other_Student_ES'] ?? 0);
    
    // Scholarship Government
    $Male_Student_Receiving_Scholarship_Government = (int)($_POST['Male_Student_Receiving_Scholarship_Government'] ?? 0);
    $Female_Student_Receiving_Scholarship_Government = (int)($_POST['Female_Student_Receiving_Scholarship_Government'] ?? 0);
    $Other_Student_Receiving_Scholarship_Government = (int)($_POST['Other_Student_Receiving_Scholarship_Government'] ?? 0);
    
    // Scholarship Institution
    $Male_Student_Receiving_Scholarship_Institution = (int)($_POST['Male_Student_Receiving_Scholarship_Institution'] ?? 0);
    $Female_Student_Receiving_Scholarship_Institution = (int)($_POST['Female_Student_Receiving_Scholarship_Institution'] ?? 0);
    $Other_Student_Receiving_Scholarship_Institution = (int)($_POST['Other_Student_Receiving_Scholarship_Institution'] ?? 0);
    
    // Scholarship Private Body
    $Male_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Male_Student_Receiving_Scholarship_Private_Body'] ?? 0);
    $Female_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Female_Student_Receiving_Scholarship_Private_Body'] ?? 0);
    $Other_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Other_Student_Receiving_Scholarship_Private_Body'] ?? 0);
    
    // Use the EXACT same UPDATE query as the main handler (line 1287-1362)
    $update_query = "UPDATE `intake_actual_strength` SET 
        `Add_Total_Student_Intake` = ?, 
        `Total_number_of_Male_Students` = ?, 
        `Total_number_of_Female_Students` = ?,
        `Total_number_of_Other_Students` = ?,
        `Total_number_of_Male_Students_within_state` = ?,
        `Total_number_of_Female_Students_within_state` = ?,
        `Total_number_of_Other_Students_within_state` = ?,
        `Male_Students_outside_state` = ?,
        `Female_Students_outside_state` = ?,
        `Other_Students_outside_state` = ?,
        `Male_Students_outside_country` = ?,
        `Female_Students_outside_country` = ?,
        `Other_Students_outside_country` = ?,
        `Male_Students_Economic_Backward` = ?,
        `Female_Students_Economic_Backward` = ?,
        `Other_Students_Economic_Backward` = ?,
        `Male_Student_General` = ?,
        `Female_Student_General` = ?,
        `Other_Student_General` = ?,
        `Male_Student_Social_Backward_SC` = ?,
        `Female_Student_Social_Backward_SC` = ?,
        `Other_Student_Social_Backward_SC` = ?,
        `Male_Student_Social_Backward_ST` = ?,
        `Female_Student_Social_Backward_ST` = ?,
        `Other_Student_Social_Backward_ST` = ?,
        `Male_Student_Social_Backward_OBC` = ?,
        `Female_Student_Social_Backward_OBC` = ?,
        `Other_Student_Social_Backward_OBC` = ?,
        `Male_Student_Social_Backward_DTA` = ?,
        `Female_Student_Social_Backward_DTA` = ?,
        `Other_Student_Social_Backward_DTA` = ?,
        `Male_Student_Social_Backward_NTB` = ?,
        `Female_Student_Social_Backward_NTB` = ?,
        `Other_Student_Social_Backward_NTB` = ?,
        `Male_Student_Social_Backward_NTC` = ?,
        `Female_Student_Social_Backward_NTC` = ?,
        `Other_Student_Social_Backward_NTC` = ?,
        `Male_Student_Social_Backward_NTD` = ?,
        `Female_Student_Social_Backward_NTD` = ?,
        `Other_Student_Social_Backward_NTD` = ?,
        `Male_Student_Social_Backward_EWS` = ?,
        `Female_Student_Social_Backward_EWS` = ?,
        `Other_Student_Social_Backward_EWS` = ?,
        `Male_Student_Social_Backward_SEBC` = ?,
        `Female_Student_Social_Backward_SEBC` = ?,
        `Other_Student_Social_Backward_SEBC` = ?,
        `Male_Student_Social_Backward_SBC` = ?,
        `Female_Student_Social_Backward_SBC` = ?,
        `Other_Student_Social_Backward_SBC` = ?,
        `Male_Student_Physically_Handicapped` = ?,
        `Female_Student_Physically_Handicapped` = ?,
        `Other_Student_Physically_Handicapped` = ?,
        `Male_Student_TGO` = ?,
        `Female_Student_TGO` = ?,
        `Other_Student_TGO` = ?,
        `Male_Student_CMIL` = ?,
        `Female_Student_CMIL` = ?,
        `Other_Student_CMIL` = ?,
        `Male_Student_SPCUL` = ?,
        `Female_Student_SPCUL` = ?,
        `Other_Student_SPCUL` = ?,
        `Male_Student_Widow_Single_Mother` = ?,
        `Female_Student_Widow_Single_Mother` = ?,
        `Other_Student_Widow_Single_Mother` = ?,
        `Male_Student_ES` = ?,
        `Female_Student_ES` = ?,
        `Other_Student_ES` = ?,
        `Male_Student_Receiving_Scholarship_Government` = ?,
        `Female_Student_Receiving_Scholarship_Government` = ?,
        `Other_Student_Receiving_Scholarship_Government` = ?,
        `Male_Student_Receiving_Scholarship_Institution` = ?,
        `Female_Student_Receiving_Scholarship_Institution` = ?,
        `Other_Student_Receiving_Scholarship_Institution` = ?,
        `Male_Student_Receiving_Scholarship_Private_Body` = ?,
        `Female_Student_Receiving_Scholarship_Private_Body` = ?,
        `Other_Student_Receiving_Scholarship_Private_Body` = ?,
        `updated_at` = CURRENT_TIMESTAMP
        WHERE `ID` = ? AND `DEPT_ID` = ? AND `A_YEAR` = ?";

    $update_stmt = mysqli_prepare($conn, $update_query);
    if (!$update_stmt) {
        echo "<script>alert('❌ Database Error: " . mysqli_error($conn) . "');</script>";
        exit;
    }

    // Bind parameters: 76 integers (includes 3 General category fields) + record_id(i) + dept(i) + A_YEAR(s)
    $type_string = str_repeat("i", 76) . "iis";

    mysqli_stmt_bind_param(
        $update_stmt,
        $type_string,
        $Student_Intake,
        $Male_Students,
        $Female_Students,
        $Other_Students,
        $Male_Students_within_state,
        $Female_Students_within_state,
        $Other_Students_within_state,
        $Male_Students_outside_state,
        $Female_Students_outside_state,
        $Other_Students_outside_state,
        $Male_Students_outside_country,
        $Female_Students_outside_country,
        $Other_Students_outside_country,
        $Male_Students_Economic_Backward,
        $Female_Students_Economic_Backward,
        $Other_Students_Economic_Backward,
        $Male_Student_General,
        $Female_Student_General,
        $Other_Student_General,
        $Male_Student_Social_Backward_SC,
        $Female_Student_Social_Backward_SC,
        $Other_Student_Social_Backward_SC,
        $Male_Student_Social_Backward_ST,
        $Female_Student_Social_Backward_ST,
        $Other_Student_Social_Backward_ST,
        $Male_Student_Social_Backward_OBC,
        $Female_Student_Social_Backward_OBC,
        $Other_Student_Social_Backward_OBC,
        $Male_Student_Social_Backward_DTA,
        $Female_Student_Social_Backward_DTA,
        $Other_Student_Social_Backward_DTA,
        $Male_Student_Social_Backward_NTB,
        $Female_Student_Social_Backward_NTB,
        $Other_Student_Social_Backward_NTB,
        $Male_Student_Social_Backward_NTC,
        $Female_Student_Social_Backward_NTC,
        $Other_Student_Social_Backward_NTC,
        $Male_Student_Social_Backward_NTD,
        $Female_Student_Social_Backward_NTD,
        $Other_Student_Social_Backward_NTD,
        $Male_Student_Social_Backward_EWS,
        $Female_Student_Social_Backward_EWS,
        $Other_Student_Social_Backward_EWS,
        $Male_Student_Social_Backward_SEBC,
        $Female_Student_Social_Backward_SEBC,
        $Other_Student_Social_Backward_SEBC,
        $Male_Student_Social_Backward_SBC,
        $Female_Student_Social_Backward_SBC,
        $Other_Student_Social_Backward_SBC,
        $Male_Student_Physically_Handicapped,
        $Female_Student_Physically_Handicapped,
        $Other_Student_Physically_Handicapped,
        $Male_Student_TGO,
        $Female_Student_TGO,
        $Other_Student_TGO,
        $Male_Student_CMIL,
        $Female_Student_CMIL,
        $Other_Student_CMIL,
        $Male_Student_SPCUL,
        $Female_Student_SPCUL,
        $Other_Student_SPCUL,
        $Male_Student_Widow_Single_Mother,
        $Female_Student_Widow_Single_Mother,
        $Other_Student_Widow_Single_Mother,
        $Male_Student_ES,
        $Female_Student_ES,
        $Other_Student_ES,
        $Male_Student_Receiving_Scholarship_Government,
        $Female_Student_Receiving_Scholarship_Government,
        $Other_Student_Receiving_Scholarship_Government,
        $Male_Student_Receiving_Scholarship_Institution,
        $Female_Student_Receiving_Scholarship_Institution,
        $Other_Student_Receiving_Scholarship_Institution,
        $Male_Student_Receiving_Scholarship_Private_Body,
        $Female_Student_Receiving_Scholarship_Private_Body,
        $Other_Student_Receiving_Scholarship_Private_Body,
        $record_id,
        $dept,
        $A_YEAR
    );

    if (mysqli_stmt_execute($update_stmt)) {
        mysqli_stmt_close($update_stmt);
        
        // CRITICAL: Clear and recalculate score cache after data update
        require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
        clearDepartmentScoreCache($dept, $A_YEAR, true);
        
        $_SESSION['success'] = "Record updated successfully for academic year $A_YEAR.";
        header("Location: IntakeActualStrength.php?success=updated");
        exit;
    } else {
        $error_msg = mysqli_stmt_error($update_stmt);
        mysqli_stmt_close($update_stmt);
        echo "<script>alert('❌ Database Error: " . addslashes($error_msg) . "');</script>";
        echo "<script>window.location.href = 'IntakeActualStrength.php';</script>";
        exit;
    }
}

// ============================================================================
// DUPLICATE HANDLER REMOVED - The main handler is at line 389 (before unified_header.php)
// This duplicate was causing "headers already sent" errors because it runs AFTER unified_header.php
// All form submission is now handled by the handler at line 389 which runs BEFORE any HTML output
// ============================================================================
if (false && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['p_name'])) {
    // DUPLICATE - DISABLED - Use main handler at line 389 instead

    // Validate CSRF token first
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if missing
        }
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf)) {
                $_SESSION['error'] = 'Security token missing. Please refresh and try again.';
                header('Location: IntakeActualStrength.php');
                exit;
            }
            if (!validate_csrf($csrf)) {
                $_SESSION['error'] = 'Security token validation failed. Please refresh and try again.';
                header('Location: IntakeActualStrength.php');
                exit;
            }
        } else {
        }
    } else {
    }

    // Validate required fields
    if (empty($_POST['p_name'])) {
        $_SESSION['error'] = 'Please select a program!';
        echo '<script>window.location.href = "IntakeActualStrength.php";</script>';
        exit;
    }

    $p_code = $_POST['p_name']; // This is now programme_code

    // Get program details
    // CRITICAL: Use COALESCE to prefer total_intake over intake_capacity
    // total_intake is calculated from year-wise breakdowns and is the actual value shown in Programmes_Offered
    $query = "SELECT id, programme_code, programme_name, programme_type, 
              COALESCE(NULLIF(total_intake, 0), intake_capacity, 0) AS intake_capacity 
              FROM programmes WHERE programme_code = ? AND DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "si", $p_code, $dept);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result || mysqli_num_rows($result) == 0) {
        $_SESSION['error'] = 'Program not found or access denied.';
        echo '<script>window.location.href = "IntakeActualStrength.php";</script>';
        exit;
    }

    $row = mysqli_fetch_assoc($result);
    $p_code = $row['programme_code'];
    $programme_name = $row['programme_name'];
    $programme_type = $row['programme_type'];
    $intake_capacity = (int) $row['intake_capacity'];
    
    // Convert p_code to integer for country_wise_student table (PROGRAM_CODE is int(11))
    // Handle both numeric strings and alphanumeric codes (e.g., "msc1012" -> extract "1012")
    if (is_numeric($p_code)) {
        $p_code_int = (int) $p_code;
    } else {
        // Extract numeric part from alphanumeric codes
        preg_match('/\d+/', $p_code, $matches);
        $p_code_int = !empty($matches) ? (int) $matches[0] : 0;
        if ($p_code_int == 0) {
            error_log("[Intake] WARNING: Could not extract numeric program code from: $p_code - country data will not be saved");
        }
    }

    // Get all form data (same as submit logic)
    $Student_Intake = $intake_capacity;
    $Male_Students = (int)($_POST['Total_number_of_Male_Students_within_state'] ?? 0) +
        (int)($_POST['Male_Students_outside_state'] ?? 0) +
        (int)($_POST['Male_Students_outside_country'] ?? 0);
    $Female_Students = (int)($_POST['Total_number_of_Female_Students_within_state'] ?? 0) +
        (int)($_POST['Female_Students_outside_state'] ?? 0) +
        (int)($_POST['Female_Students_outside_country'] ?? 0);
    $Other_Students = (int)($_POST['Total_number_of_Other_Students_within_state'] ?? 0) +
        (int)($_POST['Other_Students_outside_state'] ?? 0) +
        (int)($_POST['Other_Students_outside_country'] ?? 0);

    // Validate total students
    $total_students = $Male_Students + $Female_Students + $Other_Students;
    if ($total_students > $Student_Intake) {
        $_SESSION['error'] = "Total students ($total_students) cannot exceed enrolment capacity ($Student_Intake).";
        echo '<script>window.location.href = "IntakeActualStrength.php";</script>';
        exit;
    }

    // Get all other form fields (same as submit)
    $Male_Students_within_state = (int)($_POST['Total_number_of_Male_Students_within_state'] ?? 0);
    $Female_Students_within_state = (int)($_POST['Total_number_of_Female_Students_within_state'] ?? 0);
    $Other_Students_within_state = (int)($_POST['Total_number_of_Other_Students_within_state'] ?? 0);
    $Male_Students_outside_state = (int)($_POST['Male_Students_outside_state'] ?? 0);
    $Female_Students_outside_state = (int)($_POST['Female_Students_outside_state'] ?? 0);
    $Other_Students_outside_state = (int)($_POST['Other_Students_outside_state'] ?? 0);
    $Male_Students_outside_country = (int)($_POST['Male_Students_outside_country'] ?? 0);
    $Female_Students_outside_country = (int)($_POST['Female_Students_outside_country'] ?? 0);
    $Other_Students_outside_country = (int)($_POST['Other_Students_outside_country'] ?? 0);

    // Economic Backward
    $Male_Students_Economic_Backward = (int)($_POST['Male_Students_Economic_Backward'] ?? 0);
    $Female_Students_Economic_Backward = (int)($_POST['Female_Students_Economic_Backward'] ?? 0);
    $Other_Students_Economic_Backward = (int)($_POST['Other_Students_Economic_Backward'] ?? 0);

    // General Category
    $Male_Student_General = (int)($_POST['Male_Student_General'] ?? 0);
    $Female_Student_General = (int)($_POST['Female_Student_General'] ?? 0);
    $Other_Student_General = (int)($_POST['Other_Student_General'] ?? 0);

    // Social Backward Categories
    $Male_Student_Social_Backward_SC = (int)($_POST['Male_Students_Social_Backward_SC'] ?? 0);
    $Female_Student_Social_Backward_SC = (int)($_POST['Female_Student_Social_Backward_SC'] ?? 0);
    $Other_Student_Social_Backward_SC = (int)($_POST['Other_Student_Social_Backward_SC'] ?? 0);
    $Male_Student_Social_Backward_ST = (int)($_POST['Male_Students_Social_Backward_ST'] ?? 0);
    $Female_Student_Social_Backward_ST = (int)($_POST['Female_Student_Social_Backward_ST'] ?? 0);
    $Other_Student_Social_Backward_ST = (int)($_POST['Other_Student_Social_Backward_ST'] ?? 0);
    $Male_Student_Social_Backward_OBC = (int)($_POST['Male_Students_Social_Backward_OBC'] ?? 0);
    $Female_Student_Social_Backward_OBC = (int)($_POST['Female_Student_Social_Backward_OBC'] ?? 0);
    $Other_Student_Social_Backward_OBC = (int)($_POST['Other_Student_Social_Backward_OBC'] ?? 0);

    // Additional Social Backward Categories
    $Male_Student_Social_Backward_DTA = (int)($_POST['Male_Students_Social_Backward_DTA'] ?? 0);
    $Female_Student_Social_Backward_DTA = (int)($_POST['Female_Student_Social_Backward_DTA'] ?? 0);
    $Other_Student_Social_Backward_DTA = (int)($_POST['Other_Student_Social_Backward_DTA'] ?? 0);
    $Male_Student_Social_Backward_NTB = (int)($_POST['Male_Students_Social_Backward_NTB'] ?? 0);
    $Female_Student_Social_Backward_NTB = (int)($_POST['Female_Student_Social_Backward_NTB'] ?? 0);
    $Other_Student_Social_Backward_NTB = (int)($_POST['Other_Student_Social_Backward_NTB'] ?? 0);
    $Male_Student_Social_Backward_NTC = (int)($_POST['Male_Students_Social_Backward_NTC'] ?? 0);
    $Female_Student_Social_Backward_NTC = (int)($_POST['Female_Student_Social_Backward_NTC'] ?? 0);
    $Other_Student_Social_Backward_NTC = (int)($_POST['Other_Student_Social_Backward_NTC'] ?? 0);
    $Male_Student_Social_Backward_NTD = (int)($_POST['Male_Students_Social_Backward_NTD'] ?? 0);
    $Female_Student_Social_Backward_NTD = (int)($_POST['Female_Student_Social_Backward_NTD'] ?? 0);
    $Other_Student_Social_Backward_NTD = (int)($_POST['Other_Student_Social_Backward_NTD'] ?? 0);
    $Male_Student_Social_Backward_EWS = (int)($_POST['Male_Students_Social_Backward_EWS'] ?? 0);
    $Female_Student_Social_Backward_EWS = (int)($_POST['Female_Student_Social_Backward_EWS'] ?? 0);
    $Other_Student_Social_Backward_EWS = (int)($_POST['Other_Student_Social_Backward_EWS'] ?? 0);
    $Male_Student_Social_Backward_SEBC = (int)($_POST['Male_Students_Social_Backward_SEBC'] ?? 0);
    $Female_Student_Social_Backward_SEBC = (int)($_POST['Female_Student_Social_Backward_SEBC'] ?? 0);
    $Other_Student_Social_Backward_SEBC = (int)($_POST['Other_Student_Social_Backward_SEBC'] ?? 0);
    $Male_Student_Social_Backward_SBC = (int)($_POST['Male_Students_Social_Backward_SBC'] ?? 0);
    $Female_Student_Social_Backward_SBC = (int)($_POST['Female_Student_Social_Backward_SBC'] ?? 0);
    $Other_Student_Social_Backward_SBC = (int)($_POST['Other_Student_Social_Backward_SBC'] ?? 0);

    // Physically Handicapped - Fixed field names (form uses Male_Students_*)
    $Male_Student_Physically_Handicapped = (int)($_POST['Male_Students_Physically_Handicapped'] ?? 0);  //1
    $Female_Student_Physically_Handicapped = (int)($_POST['Female_Students_Physically_Handicapped'] ?? 0); //2
    $Other_Student_Physically_Handicapped = (int)($_POST['Other_Students_Physically_Handicapped'] ?? 0);

    // TGO - Fixed field names (form uses Male_Students_TRANGOVTOF_TGO)
    $Male_Student_TGO = (int)($_POST['Male_Students_TRANGOVTOF_TGO'] ?? 0);
    $Female_Student_TGO = (int)($_POST['Female_Students_TRANGOVTOF_TGO'] ?? 0);
    $Other_Student_TGO = (int)($_POST['Other_Students_TRANGOVTOF_TGO'] ?? 0);

    // CMIL - Fixed field names (form uses Male_Students_CMIL)
    $Male_Student_CMIL = (int)($_POST['Male_Students_CMIL'] ?? 0);
    $Female_Student_CMIL = (int)($_POST['Female_Student_CMIL'] ?? 0);
    $Other_Student_CMIL = (int)($_POST['Other_Student_CMIL'] ?? 0);

    // SPCUL
    $Male_Student_SPCUL = (int)($_POST['Male_Student_SPCUL'] ?? 0);
    $Female_Student_SPCUL = (int)($_POST['Female_Student_SPCUL'] ?? 0);
    $Other_Student_SPCUL = (int)($_POST['Other_Student_SPCUL'] ?? 0);

    // Widow Single Mother
    $Male_Student_Widow_Single_Mother = (int)($_POST['Male_Student_Widow_Single_Mother'] ?? 0);
    $Female_Student_Widow_Single_Mother = (int)($_POST['Female_Student_Widow_Single_Mother'] ?? 0);
    $Other_Student_Widow_Single_Mother = (int)($_POST['Other_Student_Widow_Single_Mother'] ?? 0);

    // ES
    $Male_Student_ES = (int)($_POST['Male_Student_ES'] ?? 0);
    $Female_Student_ES = (int)($_POST['Female_Student_ES'] ?? 0);
    $Other_Student_ES = (int)($_POST['Other_Student_ES'] ?? 0);

    // Scholarship Government
    $Male_Student_Receiving_Scholarship_Government = (int)($_POST['Male_Student_Receiving_Scholarship_Government'] ?? 0);
    $Female_Student_Receiving_Scholarship_Government = (int)($_POST['Female_Student_Receiving_Scholarship_Government'] ?? 0);
    $Other_Student_Receiving_Scholarship_Government = (int)($_POST['Other_Student_Receiving_Scholarship_Government'] ?? 0);

    // Scholarship Institution
    $Male_Student_Receiving_Scholarship_Institution = (int)($_POST['Male_Student_Receiving_Scholarship_Institution'] ?? 0);
    $Female_Student_Receiving_Scholarship_Institution = (int)($_POST['Female_Student_Receiving_Scholarship_Institution'] ?? 0);
    $Other_Student_Receiving_Scholarship_Institution = (int)($_POST['Other_Student_Receiving_Scholarship_Institution'] ?? 0);

    // Scholarship Private Body
    $Male_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Male_Student_Receiving_Scholarship_Private_Body'] ?? 0);
    $Female_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Female_Student_Receiving_Scholarship_Private_Body'] ?? 0);
    $Other_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Other_Student_Receiving_Scholarship_Private_Body'] ?? 0);

    // Check if this is an UPDATE (explicit record_id provided) or INSERT (new entry)
    // Only update if record_id is explicitly provided in POST (edit mode)
    $record_id = (int)($_POST['record_id'] ?? 0);
    $is_update = false;
    
    if ($record_id > 0) {
        // Explicit record_id provided - this is an edit operation
        // Verify the record exists and belongs to this department
        $check_existing = "SELECT ID FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ? LIMIT 1";
        $check_stmt = mysqli_prepare($conn, $check_existing);
        if (!$check_stmt) {
            error_log('[Intake] ERROR: Failed to prepare check_existing query: ' . mysqli_error($conn));
            $_SESSION['error'] = 'Database error: ' . mysqli_error($conn);
            header('Location: IntakeActualStrength.php');
            exit;
        }
        mysqli_stmt_bind_param($check_stmt, "ii", $record_id, $dept);
        mysqli_stmt_execute($check_stmt);
        $existing_result = mysqli_stmt_get_result($check_stmt);
        
        if ($existing_result && mysqli_num_rows($existing_result) > 0) {
            $is_update = true;
        } else {
            // Invalid record_id - treat as new entry
            $record_id = 0;
            $is_update = false;
        }
        mysqli_stmt_close($check_stmt);
    } else {
        // No record_id provided - this is a new entry, always INSERT
        $is_update = false;
        error_log('[Intake] INSERT mode - No record_id provided, creating new entry for DEPT_ID: ' . $dept . ', PROGRAM_CODE: ' . $p_code . ', A_YEAR: ' . $A_YEAR);
    }

    if ($is_update) {
        // UPDATE query - Escape all variables for security
        $A_YEAR_esc = mysqli_real_escape_string($conn, $A_YEAR);
        $dept_esc = mysqli_real_escape_string($conn, $dept);
        $p_code_esc = mysqli_real_escape_string($conn, $p_code);
        $programme_name_esc = mysqli_real_escape_string($conn, $programme_name);

        $update_query = "UPDATE `intake_actual_strength` SET 
        -- `A_YEAR` = ?, 
        -- `DEPT_ID` = ?, 
        -- `PROGRAM_CODE` = ?, 
        -- `PROGRAM_NAME` = ?, 

        `Add_Total_Student_Intake` = ?,
        `Total_number_of_Male_Students` = ?, 
        `Total_number_of_Female_Students` = ?,
        `Total_number_of_Other_Students` = ?,
        `Total_number_of_Male_Students_within_state` = ?,
        `Total_number_of_Female_Students_within_state` = ?,
        `Total_number_of_Other_Students_within_state` = ?,
        `Male_Students_outside_state` = ?,
        `Female_Students_outside_state` = ?,
        `Other_Students_outside_state` = ?,
        `Male_Students_outside_country` = ?,
        `Female_Students_outside_country` = ?,
        `Other_Students_outside_country` = ?,
        `Male_Students_Economic_Backward` = ?,
        `Female_Students_Economic_Backward` = ?,
        `Other_Students_Economic_Backward` = ?,
        `Male_Student_General` = ?,
        `Female_Student_General` = ?,
        `Other_Student_General` = ?,
        `Male_Student_Social_Backward_SC` = ?,
        `Female_Student_Social_Backward_SC` = ?,
        `Other_Student_Social_Backward_SC` = ?,
        `Male_Student_Social_Backward_ST` = ?,
        `Female_Student_Social_Backward_ST` = ?,
        `Other_Student_Social_Backward_ST` = ?,
        `Male_Student_Social_Backward_OBC` = ?,
        `Female_Student_Social_Backward_OBC` = ?,
        `Other_Student_Social_Backward_OBC` = ?,
        `Male_Student_Social_Backward_DTA` = ?,
        `Female_Student_Social_Backward_DTA` = ?,
        `Other_Student_Social_Backward_DTA` = ?,
        `Male_Student_Social_Backward_NTB` = ?,
        `Female_Student_Social_Backward_NTB` = ?,
        `Other_Student_Social_Backward_NTB` = ?,
        `Male_Student_Social_Backward_NTC` = ?,
        `Female_Student_Social_Backward_NTC` = ?,
        `Other_Student_Social_Backward_NTC` = ?,
        `Male_Student_Social_Backward_NTD` = ?,
        `Female_Student_Social_Backward_NTD` = ?,
        `Other_Student_Social_Backward_NTD` = ?,
        `Male_Student_Social_Backward_EWS` = ?,
        `Female_Student_Social_Backward_EWS` = ?,
        `Other_Student_Social_Backward_EWS` = ?,
        `Male_Student_Social_Backward_SEBC` = ?,
        `Female_Student_Social_Backward_SEBC` = ?,
        `Other_Student_Social_Backward_SEBC` = ?,
        `Male_Student_Social_Backward_SBC` = ?,
        `Female_Student_Social_Backward_SBC` = ?,
        `Other_Student_Social_Backward_SBC` = ?,
        `Male_Student_Physically_Handicapped` = ?,
        `Female_Student_Physically_Handicapped` = ?,
        `Other_Student_Physically_Handicapped` = ?,
        `Male_Student_TGO` = ?,
        `Female_Student_TGO` = ?,
        `Other_Student_TGO` = ?,
        `Male_Student_CMIL` = ?,
        `Female_Student_CMIL` = ?,
        `Other_Student_CMIL` = ?,
        `Male_Student_SPCUL` = ?,
        `Female_Student_SPCUL` = ?,
        `Other_Student_SPCUL` = ?,
        `Male_Student_Widow_Single_Mother` = ?,
        `Female_Student_Widow_Single_Mother` = ?,
        `Other_Student_Widow_Single_Mother` = ?,
        `Male_Student_ES` = ?,
        `Female_Student_ES` = ?,
        `Other_Student_ES` = ?,
        `Male_Student_Receiving_Scholarship_Government` = ?,
        `Female_Student_Receiving_Scholarship_Government` = ?,
        `Other_Student_Receiving_Scholarship_Government` = ?,
        `Male_Student_Receiving_Scholarship_Institution` = ?,
        `Female_Student_Receiving_Scholarship_Institution` = ?,
        `Other_Student_Receiving_Scholarship_Institution` = ?,
        `Male_Student_Receiving_Scholarship_Private_Body` = ?,
        `Female_Student_Receiving_Scholarship_Private_Body` = ?,
        `Other_Student_Receiving_Scholarship_Private_Body` = ?,
        `updated_at` = CURRENT_TIMESTAMP
        WHERE `ID` = ? AND `DEPT_ID` = ? AND `A_YEAR` = ?";

        // SECURE FIX: Use prepared statement instead of direct query
        $stmt = mysqli_prepare($conn, $update_query);

        if (!$stmt) {
            $_SESSION['error'] = 'Database prepare error: ' . mysqli_error($conn);
            echo '<script>window.location.href = "IntakeActualStrength.php";</script>';
            exit;
        }

        // Bind all parameters securely
        // UPDATE query has 80 placeholders in SET clause + 3 in WHERE clause = 83 total
        // Type string breakdown:
        //   First 4: "siis" = A_YEAR(s), DEPT_ID(i), PROGRAM_CODE(i), PROGRAM_NAME(s)
        //   Next 76: All 'i' = Add_Total_Student_Intake through Other_Student_Receiving_Scholarship_Private_Body (includes 3 General category fields)
        //   Last 3: "iis" = ID(i), DEPT_ID(i), A_YEAR(s) in WHERE clause

        //$type_string = "siis" . str_repeat("i", 76) . "iis";
        $type_string = str_repeat("i", 76) . "iis";

        mysqli_stmt_bind_param(
            $stmt,
            $type_string,

            // $A_YEAR,
            // $dept,
            // $p_code,
            // $programme_name,

            $Student_Intake,
            $Male_Students,
            $Female_Students,
            $Other_Students,
            $Male_Students_within_state,
            $Female_Students_within_state,
            $Other_Students_within_state,
            $Male_Students_outside_state,
            $Female_Students_outside_state,
            $Other_Students_outside_state,
            $Male_Students_outside_country,
            $Female_Students_outside_country,
            $Other_Students_outside_country,
            $Male_Students_Economic_Backward,
            $Female_Students_Economic_Backward,
            $Other_Students_Economic_Backward,
            $Male_Student_General,
            $Female_Student_General,
            $Other_Student_General,
            $Male_Student_Social_Backward_SC,
            $Female_Student_Social_Backward_SC,
            $Other_Student_Social_Backward_SC,
            $Male_Student_Social_Backward_ST,
            $Female_Student_Social_Backward_ST,
            $Other_Student_Social_Backward_ST,
            $Male_Student_Social_Backward_OBC,
            $Female_Student_Social_Backward_OBC,
            $Other_Student_Social_Backward_OBC,
            $Male_Student_Social_Backward_DTA,
            $Female_Student_Social_Backward_DTA,
            $Other_Student_Social_Backward_DTA,
            $Male_Student_Social_Backward_NTB,
            $Female_Student_Social_Backward_NTB,
            $Other_Student_Social_Backward_NTB,
            $Male_Student_Social_Backward_NTC,
            $Female_Student_Social_Backward_NTC,
            $Other_Student_Social_Backward_NTC,
            $Male_Student_Social_Backward_NTD,
            $Female_Student_Social_Backward_NTD,
            $Other_Student_Social_Backward_NTD,
            $Male_Student_Social_Backward_EWS,
            $Female_Student_Social_Backward_EWS,
            $Other_Student_Social_Backward_EWS,
            $Male_Student_Social_Backward_SEBC,
            $Female_Student_Social_Backward_SEBC,
            $Other_Student_Social_Backward_SEBC,
            $Male_Student_Social_Backward_SBC,
            $Female_Student_Social_Backward_SBC,
            $Other_Student_Social_Backward_SBC,
            $Male_Student_Physically_Handicapped,
            $Female_Student_Physically_Handicapped,
            $Other_Student_Physically_Handicapped,
            $Male_Student_TGO,
            $Female_Student_TGO,
            $Other_Student_TGO,
            $Male_Student_CMIL,
            $Female_Student_CMIL,
            $Other_Student_CMIL,
            $Male_Student_SPCUL,
            $Female_Student_SPCUL,
            $Other_Student_SPCUL,
            $Male_Student_Widow_Single_Mother,
            $Female_Student_Widow_Single_Mother,
            $Other_Student_Widow_Single_Mother,
            $Male_Student_ES,
            $Female_Student_ES,
            $Other_Student_ES,
            $Male_Student_Receiving_Scholarship_Government,
            $Female_Student_Receiving_Scholarship_Government,
            $Other_Student_Receiving_Scholarship_Government,
            $Male_Student_Receiving_Scholarship_Institution,
            $Female_Student_Receiving_Scholarship_Institution,
            $Other_Student_Receiving_Scholarship_Institution,
            $Male_Student_Receiving_Scholarship_Private_Body,
            $Female_Student_Receiving_Scholarship_Private_Body,
            $Other_Student_Receiving_Scholarship_Private_Body,
            $record_id,
            $dept,
            $A_YEAR
        );

        if (mysqli_stmt_execute($stmt)) {
            $affected_rows = mysqli_stmt_affected_rows($stmt);
            error_log('[Intake] UPDATE successful - Affected rows: ' . $affected_rows);
            $_SESSION['success'] = "Data updated successfully for academic year $A_YEAR.";
        } else {
            $_SESSION['error'] = 'Update failed: ' . mysqli_stmt_error($stmt);
        }

        mysqli_stmt_close($stmt);
    } else {
        // INSERT new record

        $A_YEAR_esc = mysqli_real_escape_string($conn, $A_YEAR);
        $dept_esc = mysqli_real_escape_string($conn, $dept);
        $p_code_esc = mysqli_real_escape_string($conn, $p_code);
        $programme_name_esc = mysqli_real_escape_string($conn, $programme_name);

        $insert_query = "INSERT INTO `intake_actual_strength`(
            `A_YEAR`, `DEPT_ID`, `PROGRAM_CODE`, `PROGRAM_NAME`,
            `Add_Total_Student_Intake`, `Total_number_of_Male_Students`, `Total_number_of_Female_Students`, `Total_number_of_Other_Students`,
            `Total_number_of_Male_Students_within_state`, `Total_number_of_Female_Students_within_state`, `Total_number_of_Other_Students_within_state`,
            `Male_Students_outside_state`, `Female_Students_outside_state`, `Other_Students_outside_state`,
            `Male_Students_outside_country`, `Female_Students_outside_country`, `Other_Students_outside_country`,
            `Male_Students_Economic_Backward`, `Female_Students_Economic_Backward`, `Other_Students_Economic_Backward`,
            `Male_Student_General`, `Female_Student_General`, `Other_Student_General`,
            `Male_Student_Social_Backward_SC`, `Female_Student_Social_Backward_SC`, `Other_Student_Social_Backward_SC`,
            `Male_Student_Social_Backward_ST`, `Female_Student_Social_Backward_ST`, `Other_Student_Social_Backward_ST`,
            `Male_Student_Social_Backward_OBC`, `Female_Student_Social_Backward_OBC`, `Other_Student_Social_Backward_OBC`,
            `Male_Student_Social_Backward_DTA`, `Female_Student_Social_Backward_DTA`, `Other_Student_Social_Backward_DTA`,
            `Male_Student_Social_Backward_NTB`, `Female_Student_Social_Backward_NTB`, `Other_Student_Social_Backward_NTB`,
            `Male_Student_Social_Backward_NTC`, `Female_Student_Social_Backward_NTC`, `Other_Student_Social_Backward_NTC`,
            `Male_Student_Social_Backward_NTD`, `Female_Student_Social_Backward_NTD`, `Other_Student_Social_Backward_NTD`,
            `Male_Student_Social_Backward_EWS`, `Female_Student_Social_Backward_EWS`, `Other_Student_Social_Backward_EWS`,
            `Male_Student_Social_Backward_SEBC`, `Female_Student_Social_Backward_SEBC`, `Other_Student_Social_Backward_SEBC`,
            `Male_Student_Social_Backward_SBC`, `Female_Student_Social_Backward_SBC`, `Other_Student_Social_Backward_SBC`,
            `Male_Student_Physically_Handicapped`, `Female_Student_Physically_Handicapped`, `Other_Student_Physically_Handicapped`,
            `Male_Student_TGO`, `Female_Student_TGO`, `Other_Student_TGO`,
            `Male_Student_CMIL`, `Female_Student_CMIL`, `Other_Student_CMIL`,
            `Male_Student_SPCUL`, `Female_Student_SPCUL`, `Other_Student_SPCUL`,
            `Male_Student_Widow_Single_Mother`, `Female_Student_Widow_Single_Mother`, `Other_Student_Widow_Single_Mother`,
            `Male_Student_ES`, `Female_Student_ES`, `Other_Student_ES`,
            `Male_Student_Receiving_Scholarship_Government`, `Female_Student_Receiving_Scholarship_Government`, `Other_Student_Receiving_Scholarship_Government`,
            `Male_Student_Receiving_Scholarship_Institution`, `Female_Student_Receiving_Scholarship_Institution`, `Other_Student_Receiving_Scholarship_Institution`,
            `Male_Student_Receiving_Scholarship_Private_Body`, `Female_Student_Receiving_Scholarship_Private_Body`, `Other_Student_Receiving_Scholarship_Private_Body`,
            `created_at`, `updated_at`
        ) VALUES (
            '$A_YEAR_esc', '$dept_esc', '$p_code_esc', '$programme_name_esc',
            '$Student_Intake', '$Male_Students', '$Female_Students', '$Other_Students',
            '$Male_Students_within_state', '$Female_Students_within_state', '$Other_Students_within_state',
            '$Male_Students_outside_state', '$Female_Students_outside_state', '$Other_Students_outside_state',
            '$Male_Students_outside_country', '$Female_Students_outside_country', '$Other_Students_outside_country',
            '$Male_Students_Economic_Backward', '$Female_Students_Economic_Backward', '$Other_Students_Economic_Backward',
            '$Male_Student_General', '$Female_Student_General', '$Other_Student_General',
            '$Male_Student_Social_Backward_SC', '$Female_Student_Social_Backward_SC', '$Other_Student_Social_Backward_SC',
            '$Male_Student_Social_Backward_ST', '$Female_Student_Social_Backward_ST', '$Other_Student_Social_Backward_ST',
            '$Male_Student_Social_Backward_OBC', '$Female_Student_Social_Backward_OBC', '$Other_Student_Social_Backward_OBC',
            '$Male_Student_Social_Backward_DTA', '$Female_Student_Social_Backward_DTA', '$Other_Student_Social_Backward_DTA',
            '$Male_Student_Social_Backward_NTB', '$Female_Student_Social_Backward_NTB', '$Other_Student_Social_Backward_NTB',
            '$Male_Student_Social_Backward_NTC', '$Female_Student_Social_Backward_NTC', '$Other_Student_Social_Backward_NTC',
            '$Male_Student_Social_Backward_NTD', '$Female_Student_Social_Backward_NTD', '$Other_Student_Social_Backward_NTD',
            '$Male_Student_Social_Backward_EWS', '$Female_Student_Social_Backward_EWS', '$Other_Student_Social_Backward_EWS',
            '$Male_Student_Social_Backward_SEBC', '$Female_Student_Social_Backward_SEBC', '$Other_Student_Social_Backward_SEBC',
            '$Male_Student_Social_Backward_SBC', '$Female_Student_Social_Backward_SBC', '$Other_Student_Social_Backward_SBC',
            '$Male_Student_Physically_Handicapped', '$Female_Student_Physically_Handicapped', '$Other_Student_Physically_Handicapped',
            '$Male_Student_TGO', '$Female_Student_TGO', '$Other_Student_TGO',
            '$Male_Student_CMIL', '$Female_Student_CMIL', '$Other_Student_CMIL',
            '$Male_Student_SPCUL', '$Female_Student_SPCUL', '$Other_Student_SPCUL',
            '$Male_Student_Widow_Single_Mother', '$Female_Student_Widow_Single_Mother', '$Other_Student_Widow_Single_Mother',
            '$Male_Student_ES', '$Female_Student_ES', '$Other_Student_ES',
            '$Male_Student_Receiving_Scholarship_Government', '$Female_Student_Receiving_Scholarship_Government', '$Other_Student_Receiving_Scholarship_Government',
            '$Male_Student_Receiving_Scholarship_Institution', '$Female_Student_Receiving_Scholarship_Institution', '$Other_Student_Receiving_Scholarship_Institution',
            '$Male_Student_Receiving_Scholarship_Private_Body', '$Female_Student_Receiving_Scholarship_Private_Body', '$Other_Student_Receiving_Scholarship_Private_Body',
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )";

        $query_result = mysqli_query($conn, $insert_query);
        if (!$query_result) {
            $_SESSION['error'] = 'Insert failed: ' . mysqli_error($conn);
            header('Location: IntakeActualStrength.php');
            exit;
        }
        $record_id = mysqli_insert_id($conn);
        $_SESSION['success'] = "Data entered successfully for academic year $A_YEAR.";
    }

    // Redirect after successful INSERT or UPDATE - redirect to clean state (no locked parameter)
    header('Location: IntakeActualStrength.php');
    exit;
}

// Handle clear data action like ExecutiveDevelopment.php
if (isset($_GET['action']) && $_GET['action'] === 'clear_data') {
    // Delete from main table for current academic year only
    $stmt = mysqli_prepare($conn, "DELETE FROM intake_actual_strength WHERE DEPT_ID = ? AND A_YEAR = ?");
    mysqli_stmt_bind_param($stmt, 'is', $dept, $A_YEAR);
    $main_deleted = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Delete associated country-wise data
    $stmt = mysqli_prepare($conn, "DELETE FROM country_wise_student WHERE DEPT_ID = ? AND A_YEAR = ?");
    mysqli_stmt_bind_param($stmt, 'is', $dept, $A_YEAR);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Delete uploaded documents using the unified supporting_documents table for current academic year
    $delete_docs_query = "SELECT file_path FROM supporting_documents WHERE dept_id = ? AND page_section = 'intake_actual_strength' AND academic_year = ? AND status = 'active'";
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
    }
    mysqli_stmt_close($stmt_docs);

    // Hard delete from unified supporting_documents table for current academic year only
    $delete_docs_query = "DELETE FROM supporting_documents WHERE dept_id = ? AND page_section = 'intake_actual_strength' AND academic_year = ?";
    $stmt_docs = mysqli_prepare($conn, $delete_docs_query);
    mysqli_stmt_bind_param($stmt_docs, 'is', $dept, $A_YEAR);
    $docs_deleted = mysqli_stmt_execute($stmt_docs);
    mysqli_stmt_close($stmt_docs);

    if ($main_deleted) {
        $_SESSION['success'] = "Data and uploaded documents cleared successfully for academic year $A_YEAR!";
        echo "<script>window.location.href = 'IntakeActualStrength.php';</script>";
    } else {
        $_SESSION['error'] = "Error clearing data: " . mysqli_error($conn);
    }
}
// REMOVED: Duplicate handler - the main handler at line 1173 already handles form submission
// This was causing conflicts. All form submission is now handled by the handler checking for $_POST['p_name']
if (false && isset($_POST['submit'])) {
    // DUPLICATE HANDLER - DISABLED - Use main handler at line 1173 instead
    // Start output buffering to prevent premature output
    ob_start();

    // Suppress errors to prevent HTML output
    error_reporting(0);
    ini_set('display_errors', 0);

    // Check if this is AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    // CSRF validation - use validate_csrf (not validateCSRF)
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
    }
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($csrf) || !function_exists('validate_csrf') || !validate_csrf($csrf)) {
        if ($isAjax) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode([
                'success' => false,
                'message' => 'CSRF token validation failed'
            ]);
            exit;
        } else {
            throw new Exception('CSRF token validation failed');
        }
    }

    try {
        // Debug: Log that handler was triggered

        // Validate required fields
        if (empty($_POST['p_name'])) {
            throw new Exception('Please select a program!');
        }


        $p_code = $_POST['p_name']; // This is now programme_code

        // Get program details from programmes table using prepared statement
        // CRITICAL: Use COALESCE to prefer total_intake over intake_capacity
        // total_intake is calculated from year-wise breakdowns and is the actual value shown in Programmes_Offered
        $query = "SELECT id, programme_code, programme_name, programme_type, 
                  COALESCE(NULLIF(total_intake, 0), intake_capacity, 0) AS intake_capacity 
                  FROM programmes WHERE programme_code = ? AND DEPT_ID = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "si", $p_code, $dept);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (!$result || mysqli_num_rows($result) == 0) {
            throw new Exception('Program not found or access denied.');
        }

        $row = mysqli_fetch_assoc($result);
        $p_code = $row['programme_code']; // Use actual programme_code from programmes table
        $programme_name = $row['programme_name'];
        $programme_type = $row['programme_type'];
        $intake_capacity = (int) $row['intake_capacity'];
        
        // Convert p_code to integer for country_wise_student table (PROGRAM_CODE is int(11))
        // Handle both numeric strings and alphanumeric codes (e.g., "msc1012" -> extract "1012")
        if (is_numeric($p_code)) {
            $p_code_int = (int) $p_code;
        } else {
            // Extract numeric part from alphanumeric codes
            preg_match('/\d+/', $p_code, $matches);
            $p_code_int = !empty($matches) ? (int) $matches[0] : 0;
            if ($p_code_int == 0) {
                error_log("[Intake] WARNING: Could not extract numeric program code from: $p_code - country data will not be saved");
            }
        }

        // Sanitize and validate all input data
        $c_name = $programme_name; // Use programme name as course name
        $Student_Intake = $intake_capacity; // Use intake capacity from programmes table

        // Calculate total students from individual categories
        $Male_Students = (int)($_POST['Total_number_of_Male_Students_within_state'] ?? 0) +
            (int)($_POST['Male_Students_outside_state'] ?? 0) +
            (int)($_POST['Male_Students_outside_country'] ?? 0);
        $Female_Students = (int)($_POST['Total_number_of_Female_Students_within_state'] ?? 0) +
            (int)($_POST['Female_Students_outside_state'] ?? 0) +
            (int)($_POST['Female_Students_outside_country'] ?? 0);
        $Other_Students = (int)($_POST['Total_number_of_Other_Students_within_state'] ?? 0) +
            (int)($_POST['Other_Students_outside_state'] ?? 0) +
            (int)($_POST['Other_Students_outside_country'] ?? 0);

        // Validate that total students don't exceed intake capacity
        $total_students = $Male_Students + $Female_Students + $Other_Students;
        if ($total_students > $Student_Intake) {
            throw new Exception("Total students ($total_students) cannot exceed enrolment capacity ($Student_Intake).");
        }

        // Validate that all numbers are non-negative
        if ($Male_Students < 0 || $Female_Students < 0 || $Other_Students < 0) {
            throw new Exception('Student counts cannot be negative.');
        }

        // Sanitize all form inputs with proper field names matching database
        $Male_Students_within_state = (int)($_POST['Total_number_of_Male_Students_within_state'] ?? 0);
        $Female_Students_within_state = (int)($_POST['Total_number_of_Female_Students_within_state'] ?? 0);
        $Other_Students_within_state = (int)($_POST['Total_number_of_Other_Students_within_state'] ?? 0);
        $Male_Students_outside_state = (int)($_POST['Male_Students_outside_state'] ?? 0);
        $Female_Students_outside_state = (int)($_POST['Female_Students_outside_state'] ?? 0);
        $Other_Students_outside_state = (int)($_POST['Other_Students_outside_state'] ?? 0);
        $Male_Students_outside_country = (int)($_POST['Male_Students_outside_country'] ?? 0);
        $Female_Students_outside_country = (int)($_POST['Female_Students_outside_country'] ?? 0);
        $Other_Students_outside_country = (int)($_POST['Other_Students_outside_country'] ?? 0);

        // Economic Backward
        $Male_Students_Economic_Backward = (int)($_POST['Male_Students_Economic_Backward'] ?? 0);
        $Female_Students_Economic_Backward = (int)($_POST['Female_Students_Economic_Backward'] ?? 0);
        $Other_Students_Economic_Backward = (int)($_POST['Other_Students_Economic_Backward'] ?? 0);

        // Social Backward Categories - Fixed field names to match database

        $Male_Student_Social_Backward_SC = (int)($_POST['Male_Students_Social_Backward_SC'] ?? 0);
        $Female_Student_Social_Backward_SC = (int)($_POST['Female_Student_Social_Backward_SC'] ?? 0);
        $Other_Student_Social_Backward_SC = (int)($_POST['Other_Student_Social_Backward_SC'] ?? 0);

        $Male_Student_Social_Backward_ST = (int)($_POST['Male_Students_Social_Backward_ST'] ?? 0);
        $Female_Student_Social_Backward_ST = (int)($_POST['Female_Student_Social_Backward_ST'] ?? 0);
        $Other_Student_Social_Backward_ST = (int)($_POST['Other_Student_Social_Backward_ST'] ?? 0);
        $Male_Student_Social_Backward_OBC = (int)($_POST['Male_Students_Social_Backward_OBC'] ?? 0);
        $Female_Student_Social_Backward_OBC = (int)($_POST['Female_Student_Social_Backward_OBC'] ?? 0);
        $Other_Student_Social_Backward_OBC = (int)($_POST['Other_Student_Social_Backward_OBC'] ?? 0);

        // Additional Social Backward Categories - Fixed field names
        $Male_Student_Social_Backward_DTA = (int)($_POST['Male_Students_Social_Backward_DTA'] ?? 0);
        $Female_Student_Social_Backward_DTA = (int)($_POST['Female_Student_Social_Backward_DTA'] ?? 0);
        $Other_Student_Social_Backward_DTA = (int)($_POST['Other_Student_Social_Backward_DTA'] ?? 0);
        $Male_Student_Social_Backward_NTB = (int)($_POST['Male_Students_Social_Backward_NTB'] ?? 0);
        $Female_Student_Social_Backward_NTB = (int)($_POST['Female_Student_Social_Backward_NTB'] ?? 0);
        $Other_Student_Social_Backward_NTB = (int)($_POST['Other_Student_Social_Backward_NTB'] ?? 0);
        $Male_Student_Social_Backward_NTC = (int)($_POST['Male_Students_Social_Backward_NTC'] ?? 0);
        $Female_Student_Social_Backward_NTC = (int)($_POST['Female_Student_Social_Backward_NTC'] ?? 0);
        $Other_Student_Social_Backward_NTC = (int)($_POST['Other_Student_Social_Backward_NTC'] ?? 0);
        $Male_Student_Social_Backward_NTD = (int)($_POST['Male_Students_Social_Backward_NTD'] ?? 0);
        $Female_Student_Social_Backward_NTD = (int)($_POST['Female_Student_Social_Backward_NTD'] ?? 0);
        $Other_Student_Social_Backward_NTD = (int)($_POST['Other_Student_Social_Backward_NTD'] ?? 0);
        $Male_Student_Social_Backward_EWS = (int)($_POST['Male_Students_Social_Backward_EWS'] ?? 0);
        $Female_Student_Social_Backward_EWS = (int)($_POST['Female_Student_Social_Backward_EWS'] ?? 0);
        $Other_Student_Social_Backward_EWS = (int)($_POST['Other_Student_Social_Backward_EWS'] ?? 0);
        $Male_Student_Social_Backward_SEBC = (int)($_POST['Male_Students_Social_Backward_SEBC'] ?? 0);
        $Female_Student_Social_Backward_SEBC = (int)($_POST['Female_Student_Social_Backward_SEBC'] ?? 0);
        $Other_Student_Social_Backward_SEBC = (int)($_POST['Other_Student_Social_Backward_SEBC'] ?? 0);
        $Male_Student_Social_Backward_SBC = (int)($_POST['Male_Students_Social_Backward_SBC'] ?? 0);
        $Female_Student_Social_Backward_SBC = (int)($_POST['Female_Student_Social_Backward_SBC'] ?? 0);
        $Other_Student_Social_Backward_SBC = (int)($_POST['Other_Student_Social_Backward_SBC'] ?? 0);

        // Other Categories - Fixed field names
        $Male_Student_Physically_Handicapped = (int)($_POST['Male_Students_Physically_Handicapped'] ?? 0);  //1
        $Female_Student_Physically_Handicapped = (int)($_POST['Female_Students_Physically_Handicapped'] ?? 0); //2
        $Other_Student_Physically_Handicapped = (int)($_POST['Other_Students_Physically_Handicapped'] ?? 0); //3

        $Male_Student_TGO = (int)($_POST['Male_Students_TRANGOVTOF_TGO'] ?? 0); //1
        $Female_Student_TGO = (int)($_POST['Female_Students_TRANGOVTOF_TGO'] ?? 0);
        $Other_Student_TGO = (int)($_POST['Other_Students_TRANGOVTOF_TGO'] ?? 0);

        $Male_Student_CMIL = (int)($_POST['Male_Students_CMIL'] ?? 0);
        $Female_Student_CMIL = (int)($_POST['Female_Student_CMIL'] ?? 0);
        $Other_Student_CMIL = (int)($_POST['Other_Student_CMIL'] ?? 0);

        $Male_Student_SPCUL = (int)($_POST['Male_Student_SPCUL'] ?? 0);    //1
        $Female_Student_SPCUL = (int)($_POST['Female_Student_SPCUL'] ?? 0);
        $Other_Student_SPCUL = (int)($_POST['Other_Student_SPCUL'] ?? 0);


        $Male_Student_Widow_Single_Mother = (int)($_POST['Male_Student_Widow_Single_Mother'] ?? 0);
        $Female_Student_Widow_Single_Mother = (int)($_POST['Female_Student_Widow_Single_Mother'] ?? 0);
        $Other_Student_Widow_Single_Mother = (int)($_POST['Other_Student_Widow_Single_Mother'] ?? 0);

        $Male_Student_ES = (int)($_POST['Male_Student_ES'] ?? 0);
        $Female_Student_ES = (int)($_POST['Female_Student_ES'] ?? 0);
        $Other_Student_ES = (int)($_POST['Other_Student_ES'] ?? 0);

        // Scholarship Categories - Fixed to save separately
        $Male_Student_Receiving_Scholarship_Government = (int)($_POST['Male_Student_Receiving_Scholarship_Government'] ?? 0);
        $Female_Student_Receiving_Scholarship_Government = (int)($_POST['Female_Student_Receiving_Scholarship_Government'] ?? 0);
        $Other_Student_Receiving_Scholarship_Government = (int)($_POST['Other_Student_Receiving_Scholarship_Government'] ?? 0);
        $Male_Student_Receiving_Scholarship_Institution = (int)($_POST['Male_Student_Receiving_Scholarship_Institution'] ?? 0);
        $Female_Student_Receiving_Scholarship_Institution = (int)($_POST['Female_Student_Receiving_Scholarship_Institution'] ?? 0);
        $Other_Student_Receiving_Scholarship_Institution = (int)($_POST['Other_Student_Receiving_Scholarship_Institution'] ?? 0);
        $Male_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Male_Student_Receiving_Scholarship_Private_Body'] ?? 0);
        $Female_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Female_Student_Receiving_Scholarship_Private_Body'] ?? 0);
        $Other_Student_Receiving_Scholarship_Private_Body = (int)($_POST['Other_Student_Receiving_Scholarship_Private_Body'] ?? 0);

        // Process country-wise student data
        $country_codes = $_POST['country_codes'] ?? [];
        $country_students = $_POST['country_students'] ?? [];
        $male_outside = (int) ($_POST['Male_Students_outside_country'] ?? 0);
        $female_outside = (int) ($_POST['Female_Students_outside_country'] ?? 0);

        // Determine if this is INSERT or UPDATE
        // Only update if record_id is explicitly provided in POST (edit mode)
        $record_id = (int)($_POST['record_id'] ?? 0);
        $is_update = false;
        
        if ($record_id > 0) {
            // Explicit record_id provided - this is an edit operation
            // Verify the record exists and belongs to this department
            $check_existing = "SELECT ID FROM intake_actual_strength WHERE ID = ? AND DEPT_ID = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $check_existing);
            if (!$stmt) {
                error_log('[Intake] ERROR: Failed to prepare check_existing query: ' . mysqli_error($conn));
                throw new Exception('Database error: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "ii", $record_id, $dept);
            mysqli_stmt_execute($stmt);
            $existing_result = mysqli_stmt_get_result($stmt);
            
            if ($existing_result && mysqli_num_rows($existing_result) > 0) {
                $is_update = true;
            } else {
                // Invalid record_id - treat as new entry
                $record_id = 0;
                $is_update = false;
            }
            mysqli_stmt_close($stmt);
        } else {
            // No record_id provided - this is a new entry, always INSERT
            $is_update = false;
            error_log('[Intake] INSERT mode - No record_id provided, creating new entry for DEPT_ID: ' . $dept . ', PROGRAM_CODE: ' . $p_code . ', A_YEAR: ' . $A_YEAR);
        }

        if ($is_update) {
            // UPDATE LOGIC - Escape all variables for security
            $A_YEAR_esc = mysqli_real_escape_string($conn, $A_YEAR);
            $dept_esc = mysqli_real_escape_string($conn, $dept);
            $p_code_esc = mysqli_real_escape_string($conn, $p_code);
            $programme_name_esc = mysqli_real_escape_string($conn, $programme_name);

            $update_query = "UPDATE `intake_actual_strength` SET 
                    -- `A_YEAR` = '$A_YEAR_esc', 
                    -- `DEPT_ID` = '$dept_esc', 
                    -- `PROGRAM_CODE` = '$p_code_esc', 
                    -- `PROGRAM_NAME` = '$programme_name_esc', 

                    `Add_Total_Student_Intake` = '$Student_Intake', 
                    `Total_number_of_Male_Students` = '$Male_Students', 
                    `Total_number_of_Female_Students` = '$Female_Students',
                    `Total_number_of_Other_Students` = '$Other_Students',
                    `Total_number_of_Male_Students_within_state` = '$Male_Students_within_state',
                    `Total_number_of_Female_Students_within_state` = '$Female_Students_within_state',
                    `Total_number_of_Other_Students_within_state` = '$Other_Students_within_state',
                    `Male_Students_outside_state` = '$Male_Students_outside_state',
                    `Female_Students_outside_state` = '$Female_Students_outside_state',
                    `Other_Students_outside_state` = '$Other_Students_outside_state',
                    `Male_Students_outside_country` = '$Male_Students_outside_country',
                    `Female_Students_outside_country` = '$Female_Students_outside_country',
                    `Other_Students_outside_country` = '$Other_Students_outside_country',
                    `Male_Students_Economic_Backward` = '$Male_Students_Economic_Backward',
                    `Female_Students_Economic_Backward` = '$Female_Students_Economic_Backward',
                    `Other_Students_Economic_Backward` = '$Other_Students_Economic_Backward',
                    `Male_Student_General` = '$Male_Student_General',
                    `Female_Student_General` = '$Female_Student_General',
                    `Other_Student_General` = '$Other_Student_General',
                    `Male_Student_Social_Backward_SC` = '$Male_Student_Social_Backward_SC',
                    `Female_Student_Social_Backward_SC` = '$Female_Student_Social_Backward_SC',
                    `Other_Student_Social_Backward_SC` = '$Other_Student_Social_Backward_SC',
                    `Male_Student_Social_Backward_ST` = '$Male_Student_Social_Backward_ST',
                    `Female_Student_Social_Backward_ST` = '$Female_Student_Social_Backward_ST',
                    `Other_Student_Social_Backward_ST` = '$Other_Student_Social_Backward_ST',
                    `Male_Student_Social_Backward_OBC` = '$Male_Student_Social_Backward_OBC',
                    `Female_Student_Social_Backward_OBC` = '$Female_Student_Social_Backward_OBC',
                    `Other_Student_Social_Backward_OBC` = '$Other_Student_Social_Backward_OBC',
                    `Male_Student_Social_Backward_DTA` = '$Male_Student_Social_Backward_DTA',
                    `Female_Student_Social_Backward_DTA` = '$Female_Student_Social_Backward_DTA',
                    `Other_Student_Social_Backward_DTA` = '$Other_Student_Social_Backward_DTA',
                    `Male_Student_Social_Backward_NTB` = '$Male_Student_Social_Backward_NTB',
                    `Female_Student_Social_Backward_NTB` = '$Female_Student_Social_Backward_NTB',
                    `Other_Student_Social_Backward_NTB` = '$Other_Student_Social_Backward_NTB',
                    `Male_Student_Social_Backward_NTC` = '$Male_Student_Social_Backward_NTC',
                    `Female_Student_Social_Backward_NTC` = '$Female_Student_Social_Backward_NTC',
                    `Other_Student_Social_Backward_NTC` = '$Other_Student_Social_Backward_NTC',
                    `Male_Student_Social_Backward_NTD` = '$Male_Student_Social_Backward_NTD',
                    `Female_Student_Social_Backward_NTD` = '$Female_Student_Social_Backward_NTD',
                    `Other_Student_Social_Backward_NTD` = '$Other_Student_Social_Backward_NTD',
                    `Male_Student_Social_Backward_EWS` = '$Male_Student_Social_Backward_EWS',
                    `Female_Student_Social_Backward_EWS` = '$Female_Student_Social_Backward_EWS',
                    `Other_Student_Social_Backward_EWS` = '$Other_Student_Social_Backward_EWS',
                    `Male_Student_Social_Backward_SEBC` = '$Male_Student_Social_Backward_SEBC',
                    `Female_Student_Social_Backward_SEBC` = '$Female_Student_Social_Backward_SEBC',
                    `Other_Student_Social_Backward_SEBC` = '$Other_Student_Social_Backward_SEBC',
                    `Male_Student_Social_Backward_SBC` = '$Male_Student_Social_Backward_SBC',
                    `Female_Student_Social_Backward_SBC` = '$Female_Student_Social_Backward_SBC',
                    `Other_Student_Social_Backward_SBC` = '$Other_Student_Social_Backward_SBC',
                    `Male_Student_Physically_Handicapped` = '$Male_Student_Physically_Handicapped',
                    `Female_Student_Physically_Handicapped` = '$Female_Student_Physically_Handicapped',
                    `Other_Student_Physically_Handicapped` = '$Other_Student_Physically_Handicapped',
                    `Male_Student_TGO` = '$Male_Student_TGO',
                    `Female_Student_TGO` = '$Female_Student_TGO',
                    `Other_Student_TGO` = '$Other_Student_TGO',
                    `Male_Student_CMIL` = '$Male_Student_CMIL',
                    `Female_Student_CMIL` = '$Female_Student_CMIL',
                    `Other_Student_CMIL` = '$Other_Student_CMIL',
                    `Male_Student_SPCUL` = '$Male_Student_SPCUL',
                    `Female_Student_SPCUL` = '$Female_Student_SPCUL',
                    `Other_Student_SPCUL` = '$Other_Student_SPCUL',
                    `Male_Student_Widow_Single_Mother` = '$Male_Student_Widow_Single_Mother',
                    `Female_Student_Widow_Single_Mother` = '$Female_Student_Widow_Single_Mother',
                    `Other_Student_Widow_Single_Mother` = '$Other_Student_Widow_Single_Mother',
                    `Male_Student_ES` = '$Male_Student_ES',
                    `Female_Student_ES` = '$Female_Student_ES',
                    `Other_Student_ES` = '$Other_Student_ES',
                    `Male_Student_Receiving_Scholarship_Government` = '$Male_Student_Receiving_Scholarship_Government',
                    `Female_Student_Receiving_Scholarship_Government` = '$Female_Student_Receiving_Scholarship_Government',
                    `Other_Student_Receiving_Scholarship_Government` = '$Other_Student_Receiving_Scholarship_Government',
                    `Male_Student_Receiving_Scholarship_Institution` = '$Male_Student_Receiving_Scholarship_Institution',
                    `Female_Student_Receiving_Scholarship_Institution` = '$Female_Student_Receiving_Scholarship_Institution',
                    `Other_Student_Receiving_Scholarship_Institution` = '$Other_Student_Receiving_Scholarship_Institution',
                    `Male_Student_Receiving_Scholarship_Private_Body` = '$Male_Student_Receiving_Scholarship_Private_Body',
                    `Female_Student_Receiving_Scholarship_Private_Body` = '$Female_Student_Receiving_Scholarship_Private_Body',
                    `Other_Student_Receiving_Scholarship_Private_Body` = '$Other_Student_Receiving_Scholarship_Private_Body',
                    `updated_at` = CURRENT_TIMESTAMP
                    WHERE `ID` = '$record_id' AND `DEPT_ID` = '$dept_esc'";

            // Execute UPDATE with proper error handling
            error_log('[Intake] Executing UPDATE query for record ID: ' . $record_id);
            error_log('[Intake] UPDATE query preview (first 500 chars): ' . substr($update_query, 0, 500));
            error_log('[Intake] Sample field values - Male_Student_Social_Backward_SC: ' . $Male_Student_Social_Backward_SC);
            error_log('[Intake] Sample field values - Male_Student_TGO: ' . $Male_Student_TGO);
            error_log('[Intake] Sample field values - Male_Student_CMIL: ' . $Male_Student_CMIL);
            $query_result = mysqli_query($conn, $update_query);
            if (!$query_result) {
                error_log('[Intake] ERROR: UPDATE query failed: ' . mysqli_error($conn));
                throw new Exception('Update failed: ' . mysqli_error($conn));
            }
            $affected_rows = mysqli_affected_rows($conn);
            error_log('[Intake] UPDATE successful - Affected rows: ' . $affected_rows);
            if ($affected_rows == 0) {
                error_log('[Intake] WARNING: UPDATE affected 0 rows - record may not exist or data unchanged');
            }

            // Process country data for UPDATE
            // Check if we have valid country data to process
            $has_valid_country_data = false;
            if (is_array($country_codes) && is_array($country_students) && count($country_codes) > 0) {
                foreach ($country_codes as $idx => $code) {
                    $code_str = trim($code ?? '');
                    $count = (int)($country_students[$idx] ?? 0);
                    if (!empty($code_str) && $count > 0) {
                        $has_valid_country_data = true;
                        break;
                    }
                }
            }

            // Validate p_code_int and programme_name before processing country data
            if (!isset($p_code_int) || $p_code_int <= 0) {
                error_log("[Intake] UPDATE ERROR: Invalid program code ($p_code) - country data will not be saved. p_code_int: " . ($p_code_int ?? 'undefined'));
            } elseif (empty($programme_name)) {
                error_log("[Intake] UPDATE ERROR: Programme name is empty - attempting to retrieve from database. Programme code: $p_code");
                // Try to get programme name from database if available
                $prog_name_query = "SELECT programme_name FROM programmes WHERE programme_code = ? AND DEPT_ID = ? LIMIT 1";
                $prog_stmt = mysqli_prepare($conn, $prog_name_query);
                if ($prog_stmt) {
                    mysqli_stmt_bind_param($prog_stmt, "si", $p_code, $dept);
                    mysqli_stmt_execute($prog_stmt);
                    $prog_result = mysqli_stmt_get_result($prog_stmt);
                    if ($prog_result && mysqli_num_rows($prog_result) > 0) {
                        $prog_row = mysqli_fetch_assoc($prog_result);
                        $programme_name = $prog_row['programme_name'];
                        error_log("[Intake] Retrieved programme name from database: $programme_name");
                    }
                    mysqli_stmt_close($prog_stmt);
                }
                if (empty($programme_name)) {
                    error_log("[Intake] UPDATE ERROR: Programme name still empty after database lookup - country data will not be saved");
                }
            }
            
            if ((!empty($programme_name) && isset($p_code_int) && $p_code_int > 0) && (($male_outside > 0 || $female_outside > 0) || $has_valid_country_data)) {
                // Clear existing country data for this department and program
                $clear_country_query = "DELETE FROM country_wise_student WHERE DEPT_ID = ? AND PROGRAM_CODE = ? AND A_YEAR = ?";
                $stmt = mysqli_prepare($conn, $clear_country_query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "iis", $dept, $p_code_int, $A_YEAR);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }

                // Insert new country data
                $valid_countries_inserted = 0;
                if (is_array($country_codes) && is_array($country_students)) {
                    for ($i = 0; $i < count($country_codes); $i++) {
                        $country_code_str = trim($country_codes[$i] ?? '');
                        $student_count = (int) ($country_students[$i] ?? 0);

                        // Convert country code to integer (database expects bigint)
                        $country_code = !empty($country_code_str) ? (int) $country_code_str : 0;

                        // ROBUST VALIDATION: Check for valid country code, student count, program code, and programme name
                        if ($country_code > 0 && $student_count > 0 && $p_code_int > 0 && !empty($programme_name)) {
                            $country_query = "INSERT INTO `country_wise_student`(`A_YEAR`, `DEPT_ID`, `PROGRAM_CODE`, `PROGRAM_NAME`, `COUNTRY_CODE`, `NO_OF_STUDENT_COUNTRY`) 
                                VALUES (?, ?, ?, ?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $country_query);
                            if ($stmt) {
                                // Type string: A_YEAR(s), dept(i), p_code(i), programme_name(s), country_code(i), student_count(i)
                                mysqli_stmt_bind_param($stmt, "siiisi", $A_YEAR, $dept, $p_code_int, $programme_name, $country_code, $student_count);

                                if (mysqli_stmt_execute($stmt)) {
                                    $valid_countries_inserted++;
                                    error_log("[Intake] UPDATE: Country inserted - Program: $programme_name, Country: $country_code, Count: $student_count, PROGRAM_CODE: $p_code_int");
                                } else {
                                    error_log("Country insert failed: " . mysqli_stmt_error($stmt) . " - Country code: $country_code, Count: $student_count, Program: $programme_name, p_code_int: $p_code_int");
                                }
                                mysqli_stmt_close($stmt);
                            } else {
                                error_log("Country insert prepare failed: " . mysqli_error($conn) . " - Country code: $country_code, Program: $programme_name");
                            }
                        } elseif (empty($programme_name)) {
                            error_log("[Intake] UPDATE: Skipping country insert - Programme name is empty for Country code: $country_code");
                        }
                    }
                }
                if ($valid_countries_inserted > 0) {
                    error_log("[Intake] UPDATE: Inserted $valid_countries_inserted country records for DEPT_ID: $dept, PROGRAM_CODE: $p_code_int, Program Name: $programme_name");
                }
            }
        } else {
            // INSERT LOGIC - Escape all variables for security
            $A_YEAR_esc = mysqli_real_escape_string($conn, $A_YEAR);
            $dept_esc = mysqli_real_escape_string($conn, $dept);
            $p_code_esc = mysqli_real_escape_string($conn, $p_code);
            $programme_name_esc = mysqli_real_escape_string($conn, $programme_name);

            $insert_query = "INSERT INTO `intake_actual_strength`(
                    `A_YEAR`, `DEPT_ID`, `PROGRAM_CODE`, `PROGRAM_NAME`,
                    `Add_Total_Student_Intake`, `Total_number_of_Male_Students`, `Total_number_of_Female_Students`, `Total_number_of_Other_Students`,
                    `Total_number_of_Male_Students_within_state`, `Total_number_of_Female_Students_within_state`, `Total_number_of_Other_Students_within_state`,
                    `Male_Students_outside_state`, `Female_Students_outside_state`, `Other_Students_outside_state`,
                    `Male_Students_outside_country`, `Female_Students_outside_country`, `Other_Students_outside_country`,
                    `Male_Students_Economic_Backward`, `Female_Students_Economic_Backward`, `Other_Students_Economic_Backward`,
                    `Male_Student_General`, `Female_Student_General`, `Other_Student_General`,
                    `Male_Student_Social_Backward_SC`, `Female_Student_Social_Backward_SC`, `Other_Student_Social_Backward_SC`,
                    `Male_Student_Social_Backward_ST`, `Female_Student_Social_Backward_ST`, `Other_Student_Social_Backward_ST`,
                    `Male_Student_Social_Backward_OBC`, `Female_Student_Social_Backward_OBC`, `Other_Student_Social_Backward_OBC`,
                    `Male_Student_Social_Backward_DTA`, `Female_Student_Social_Backward_DTA`, `Other_Student_Social_Backward_DTA`,
                    `Male_Student_Social_Backward_NTB`, `Female_Student_Social_Backward_NTB`, `Other_Student_Social_Backward_NTB`,
                    `Male_Student_Social_Backward_NTC`, `Female_Student_Social_Backward_NTC`, `Other_Student_Social_Backward_NTC`,
                    `Male_Student_Social_Backward_NTD`, `Female_Student_Social_Backward_NTD`, `Other_Student_Social_Backward_NTD`,
                    `Male_Student_Social_Backward_EWS`, `Female_Student_Social_Backward_EWS`, `Other_Student_Social_Backward_EWS`,
                    `Male_Student_Social_Backward_SEBC`, `Female_Student_Social_Backward_SEBC`, `Other_Student_Social_Backward_SEBC`,
                    `Male_Student_Social_Backward_SBC`, `Female_Student_Social_Backward_SBC`, `Other_Student_Social_Backward_SBC`,
                    `Male_Student_Physically_Handicapped`, `Female_Student_Physically_Handicapped`, `Other_Student_Physically_Handicapped`,
                    `Male_Student_TGO`, `Female_Student_TGO`, `Other_Student_TGO`,
                    `Male_Student_CMIL`, `Female_Student_CMIL`, `Other_Student_CMIL`,
                    `Male_Student_SPCUL`, `Female_Student_SPCUL`, `Other_Student_SPCUL`,
                    `Male_Student_Widow_Single_Mother`, `Female_Student_Widow_Single_Mother`, `Other_Student_Widow_Single_Mother`,
                    `Male_Student_ES`, `Female_Student_ES`, `Other_Student_ES`,
                    `Male_Student_Receiving_Scholarship_Government`, `Female_Student_Receiving_Scholarship_Government`, `Other_Student_Receiving_Scholarship_Government`,
                    `Male_Student_Receiving_Scholarship_Institution`, `Female_Student_Receiving_Scholarship_Institution`, `Other_Student_Receiving_Scholarship_Institution`,
                    `Male_Student_Receiving_Scholarship_Private_Body`, `Female_Student_Receiving_Scholarship_Private_Body`, `Other_Student_Receiving_Scholarship_Private_Body`,
                    `created_at`, `updated_at`
                ) VALUES (
                    '$A_YEAR_esc', '$dept_esc', '$p_code_esc', '$programme_name_esc',
                    '$Student_Intake', '$Male_Students', '$Female_Students', '$Other_Students',
                    '$Male_Students_within_state', '$Female_Students_within_state', '$Other_Students_within_state',
                    '$Male_Students_outside_state', '$Female_Students_outside_state', '$Other_Students_outside_state',
                    '$Male_Students_outside_country', '$Female_Students_outside_country', '$Other_Students_outside_country',
                    '$Male_Students_Economic_Backward', '$Female_Students_Economic_Backward', '$Other_Students_Economic_Backward',
                    '$Male_Student_General', '$Female_Student_General', '$Other_Student_General',
                    '$Male_Student_Social_Backward_SC', '$Female_Student_Social_Backward_SC', '$Other_Student_Social_Backward_SC',
                    '$Male_Student_Social_Backward_ST', '$Female_Student_Social_Backward_ST', '$Other_Student_Social_Backward_ST',
                    '$Male_Student_Social_Backward_OBC', '$Female_Student_Social_Backward_OBC', '$Other_Student_Social_Backward_OBC',
                    '$Male_Student_Social_Backward_DTA', '$Female_Student_Social_Backward_DTA', '$Other_Student_Social_Backward_DTA',
                    '$Male_Student_Social_Backward_NTB', '$Female_Student_Social_Backward_NTB', '$Other_Student_Social_Backward_NTB',
                    '$Male_Student_Social_Backward_NTC', '$Female_Student_Social_Backward_NTC', '$Other_Student_Social_Backward_NTC',
                    '$Male_Student_Social_Backward_NTD', '$Female_Student_Social_Backward_NTD', '$Other_Student_Social_Backward_NTD',
                    '$Male_Student_Social_Backward_EWS', '$Female_Student_Social_Backward_EWS', '$Other_Student_Social_Backward_EWS',
                    '$Male_Student_Social_Backward_SEBC', '$Female_Student_Social_Backward_SEBC', '$Other_Student_Social_Backward_SEBC',
                    '$Male_Student_Social_Backward_SBC', '$Female_Student_Social_Backward_SBC', '$Other_Student_Social_Backward_SBC',
                    '$Male_Student_Physically_Handicapped', '$Female_Student_Physically_Handicapped', '$Other_Student_Physically_Handicapped',
                    '$Male_Student_TGO', '$Female_Student_TGO', '$Other_Student_TGO',
                    '$Male_Student_CMIL', '$Female_Student_CMIL', '$Other_Student_CMIL',
                    '$Male_Student_SPCUL', '$Female_Student_SPCUL', '$Other_Student_SPCUL',
                    '$Male_Student_Widow_Single_Mother', '$Female_Student_Widow_Single_Mother', '$Other_Student_Widow_Single_Mother',
                    '$Male_Student_ES', '$Female_Student_ES', '$Other_Student_ES',
                    '$Male_Student_Receiving_Scholarship_Government', '$Female_Student_Receiving_Scholarship_Government', '$Other_Student_Receiving_Scholarship_Government',
                    '$Male_Student_Receiving_Scholarship_Institution', '$Female_Student_Receiving_Scholarship_Institution', '$Other_Student_Receiving_Scholarship_Institution',
                    '$Male_Student_Receiving_Scholarship_Private_Body', '$Female_Student_Receiving_Scholarship_Private_Body', '$Other_Student_Receiving_Scholarship_Private_Body',
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )";

            // Execute INSERT query directly (query already has escaped values)
            error_log('[Intake] Executing INSERT query');
            error_log('[Intake] INSERT query preview (first 300 chars): ' . substr($insert_query, 0, 300));
            $query_result = mysqli_query($conn, $insert_query);
            if (!$query_result) {
                throw new Exception('Insert failed: ' . mysqli_error($conn));
            }
            $record_id = mysqli_insert_id($conn);
        }

        // Process country data and documents only if query was successful
        if ($query_result) {
            // Save country-wise student data
            // Check if we have valid country data to process
            $has_valid_country_data = false;
            if (is_array($country_codes) && is_array($country_students) && count($country_codes) > 0) {
                foreach ($country_codes as $idx => $code) {
                    $code_str = trim($code ?? '');
                    $count = (int)($country_students[$idx] ?? 0);
                    if (!empty($code_str) && $count > 0) {
                        $has_valid_country_data = true;
                        break;
                    }
                }
            }

            // Validate p_code_int and programme_name before processing country data
            if (!isset($p_code_int) || $p_code_int <= 0) {
                error_log("[Intake] INSERT ERROR: Invalid program code ($p_code) - country data will not be saved. p_code_int: " . ($p_code_int ?? 'undefined'));
            } elseif (empty($programme_name)) {
                error_log("[Intake] INSERT ERROR: Programme name is empty - attempting to retrieve from database. Programme code: $p_code");
                // Try to get programme name from database if available
                $prog_name_query = "SELECT programme_name FROM programmes WHERE programme_code = ? AND DEPT_ID = ? LIMIT 1";
                $prog_stmt = mysqli_prepare($conn, $prog_name_query);
                if ($prog_stmt) {
                    mysqli_stmt_bind_param($prog_stmt, "si", $p_code, $dept);
                    mysqli_stmt_execute($prog_stmt);
                    $prog_result = mysqli_stmt_get_result($prog_stmt);
                    if ($prog_result && mysqli_num_rows($prog_result) > 0) {
                        $prog_row = mysqli_fetch_assoc($prog_result);
                        $programme_name = $prog_row['programme_name'];
                        error_log("[Intake] Retrieved programme name from database: $programme_name");
                    }
                    mysqli_stmt_close($prog_stmt);
                }
                if (empty($programme_name)) {
                    error_log("[Intake] INSERT ERROR: Programme name still empty after database lookup - country data will not be saved");
                }
            }
            
            if ((!empty($programme_name) && isset($p_code_int) && $p_code_int > 0) && (($male_outside > 0 || $female_outside > 0) || $has_valid_country_data)) {
                // Insert new country data
                $valid_countries_inserted = 0;
                if (is_array($country_codes) && is_array($country_students)) {
                    for ($i = 0; $i < count($country_codes); $i++) {
                        $country_code_str = trim($country_codes[$i] ?? '');
                        $student_count = (int) ($country_students[$i] ?? 0);

                        // Convert country code to integer (database expects bigint)
                        $country_code = !empty($country_code_str) ? (int) $country_code_str : 0;

                        // ROBUST VALIDATION: Check for valid country code, student count, program code, and programme name
                        if ($country_code > 0 && $student_count > 0 && $p_code_int > 0 && !empty($programme_name)) {
                            $country_query = "INSERT INTO `country_wise_student`(`A_YEAR`, `DEPT_ID`, `PROGRAM_CODE`, `PROGRAM_NAME`, `COUNTRY_CODE`, `NO_OF_STUDENT_COUNTRY`) 
                                VALUES (?, ?, ?, ?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $country_query);
                            if ($stmt) {
                                // Type string: A_YEAR(s), dept(i), p_code(i), programme_name(s), country_code(i), student_count(i)
                                mysqli_stmt_bind_param($stmt, "siiisi", $A_YEAR, $dept, $p_code_int, $programme_name, $country_code, $student_count);

                                if (mysqli_stmt_execute($stmt)) {
                                    $valid_countries_inserted++;
                                    error_log("[Intake] INSERT: Country inserted - Program: $programme_name, Country: $country_code, Count: $student_count, PROGRAM_CODE: $p_code_int");
                                } else {
                                    error_log("Country insert failed: " . mysqli_stmt_error($stmt) . " - Country code: $country_code, Count: $student_count, Program: $programme_name, p_code_int: $p_code_int");
                                }
                                mysqli_stmt_close($stmt);
                            } else {
                                error_log("Country insert prepare failed: " . mysqli_error($conn) . " - Country code: $country_code, Program: $programme_name");
                            }
                        } elseif (empty($programme_name)) {
                            error_log("[Intake] INSERT: Skipping country insert - Programme name is empty for Country code: $country_code");
                        }
                    }
                }
                if ($valid_countries_inserted > 0) {
                    error_log("[Intake] INSERT: Inserted $valid_countries_inserted country records for DEPT_ID: $dept, PROGRAM_CODE: $p_code_int, Program Name: $programme_name");
                }
            }

            // Process temporary documents and save them to database
            if (isset($_SESSION['temp_intake_docs']) && !empty($_SESSION['temp_intake_docs'])) {
                foreach ($_SESSION['temp_intake_docs'] as $key => $temp_doc) {
                    if ($temp_doc['program_code'] == $p_code) {
                        // Move file from temp to permanent location
                        $permanent_dir = "../uploads/{$A_YEAR}/DEPARTMENT/{$dept}/intake_actual_strength/";
                        if (!file_exists($permanent_dir)) {
                            if (!mkdir($permanent_dir, 0777, true)) {
                                throw new Exception('Failed to create permanent upload directory.');
                            }
                        }

                        $permanent_file_name = "intake_doc_{$p_code}_{$temp_doc['document_type']}_" . time() . ".pdf";
                        $permanent_path = $permanent_dir . $permanent_file_name;

                        if (file_exists($temp_doc['file_path']) && copy($temp_doc['file_path'], $permanent_path)) {
                            // Save to database using supporting_documents table
                            $academic_year = date('Y') . '-' . (date('Y') + 1);
                            $serial_number = crc32($dept . $p_code . $temp_doc['document_type'] . time() . rand());
                            $document_title = $temp_doc['section_name'] . ' Documentation - ' . $temp_doc['programme_name'];
                            $uploaded_by = $_SESSION['admin_username'] ?? 'User';

                            $insert_query = "INSERT INTO supporting_documents 
                                    (academic_year, dept_id, page_section, section_name, serial_number, program_id, document_title, file_path, file_name, file_size, uploaded_by, status) 
                                    VALUES (?, ?, 'intake_actual_strength', ?, ?, ?, ?, ?, ?, ?, ?, 'active')";

                            // Convert physical path to web-accessible path for database storage
                            $web_path = str_replace('../', '', $permanent_path);

                            $stmt = mysqli_prepare($conn, $insert_query);
                            mysqli_stmt_bind_param(
                                $stmt,
                                "siisssiis",
                                $academic_year,
                                $dept,
                                $temp_doc['section_name'],
                                $serial_number,
                                $p_code,
                                $document_title,
                                $web_path,
                                $permanent_file_name,
                                $temp_doc['file_size'],
                                $uploaded_by
                            );

                            if (mysqli_stmt_execute($stmt)) {
                                // Delete temporary file
                                unlink($temp_doc['file_path']);
                                // Remove from session
                                unset($_SESSION['temp_intake_docs'][$key]);
                            }
                        }
                    }
                }

                // Clear any remaining temporary documents from session
                if (empty($_SESSION['temp_intake_docs'])) {
                    unset($_SESSION['temp_intake_docs']);
                }
            }
        } else {
            throw new Exception('Database error: ' . mysqli_error($conn));
        }

        $success_message = $is_update ? 'Data Updated Successfully!' : 'Data Entered Successfully!';
        error_log('[Intake] Success - redirecting to locked view');

        // Always use PHP redirect for natural form submission (like PlacementDetails.php)
        $_SESSION['success'] = $success_message;

        // Redirect to clean state (no locked parameter) - form will automatically show locked if data exists
        header('Location: IntakeActualStrength.php');
        exit;
    } catch (Exception $e) {
        // Always use PHP redirect for errors (like PlacementDetails.php)
        error_log('[Intake] Error during submission: ' . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
        header('Location: IntakeActualStrength.php');
        exit;
    }
}

// ######
// DUPLICATE HANDLER REMOVED - DELETE HANDLERS NOW AT TOP OF FILE (BEFORE unified_header.php)
// This duplicate was causing "headers already sent" errors because it runs AFTER unified_header.php
// All delete/clear actions are now handled by the handlers before unified_header.php (around line 2130)
// #####
?>
<div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
    <div class="main-content-area">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-users me-3"></i>Student Enrolment
                    </h1>
                    <p class="page-subtitle">Manage student enrolment and actual strength data</p>
                </div>
                <a href="export_page_pdf.php?page=IntakeActualStrength" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form class="modern-form" method="POST" enctype="multipart/form-data" autocomplete="off" novalidate>
                    <!-- CSRF Protection -->
                    <?php
                    // Ensure CSRF token is generated
                    if (!isset($_SESSION['csrf_token'])) {
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }
                    ?>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <!-- Hidden field for record ID -->
                    <input type="hidden" name="record_id" id="record_id" value="<?php echo $record_id ?? ''; ?>">
                    <!-- Important Instructions -->
                    <div class="alert alert-warning" id="important-instructions-alert">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i><b>Important Guidelines:</b></h5>
                        <ul class="mb-0">
                            <li><b>📊 Combined Data Entry Required:</b> For multi-year programs (e.g., UG 2 years, UG 3 years, UG 4 years, UG 5 years), you must enter the <strong>combined/total data for ALL program years together</strong>. For example, if it's a 3-year UG program, enter the combined data of Year 1 + Year 2 + Year 3 students together in this form.</li>
                            <li><b>📄 Combined Document Upload:</b> Upload supporting documents that contain <strong>combined data for all years</strong> of the program. For multi-year programs, submit a single document covering all program years (Year 1 + Year 2 + Year 3, etc.) rather than separate documents for each year.</li>
                            <li><b>✅ Complete All Fields:</b> Enter value(s) in all field(s); if not applicable, enter zero [0].</li>
                            <li><b>⚠️ Category Exclusivity:</b> Students counted under socially challenged categories (SC, ST, OBC, etc.) shall <strong>NOT</strong> be counted again in economically backward category and vice versa. Each student should be counted only once.</li>
                            <li><b>💰 Economic Backward Criteria:</b> Students whose parental income is less than the taxable slab shall be considered under economically backward category.</li>
                        </ul>
                    </div>

                    <!-- Form Status Alert -->
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

                    <!-- Program Selection Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-graduation-cap me-2"></i>Program Selection
                        </h3>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label" for="program_select"><b>Select the Program *</b></label>
                                    <select name="p_name" id="program_select" class="form-control" onchange="checkProgramData()" required>
                                        <option value="">Select Program</option>
                                        <?php
                                        // CRITICAL: Use COALESCE to prefer total_intake over intake_capacity
                                        // total_intake is calculated from year-wise breakdowns and is the actual value shown in Programmes_Offered
                                        $sql = "SELECT id, programme_code, programme_name, programme_type, 
                                                COALESCE(NULLIF(total_intake, 0), intake_capacity, 0) AS intake_capacity 
                                                FROM programmes WHERE DEPT_ID = ? ORDER BY programme_name ASC";
                                        $stmt = mysqli_prepare($conn, $sql);
                                        mysqli_stmt_bind_param($stmt, "i", $dept);
                                        mysqli_stmt_execute($stmt);
                                        $result = mysqli_stmt_get_result($stmt);
                                        while ($row = mysqli_fetch_assoc($result)) {
                                            $selected = (isset($selected_program_code) && $selected_program_code == $row['programme_code']) ? 'selected' : '';
                                            // CRITICAL: Ensure intake_capacity is always set (use 0 if NULL)
                                            $intake_capacity = isset($row['intake_capacity']) ? (int)$row['intake_capacity'] : 0;
                                            echo '<option value="' . htmlspecialchars($row['programme_code'], ENT_QUOTES, 'UTF-8') . '" data-id="' . (int)$row['id'] . '" data-intake="' . $intake_capacity . '" data-name="' . htmlspecialchars($row['programme_name'], ENT_QUOTES, 'UTF-8') . '" ' . $selected . '>' . htmlspecialchars($row['programme_name'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($row['programme_type'], ENT_QUOTES, 'UTF-8') . ')</option>';
                                        }
                                        mysqli_stmt_close($stmt);
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label" for="program_name_display"><b>Program Name</b></label>
                                    <input type="text" id="program_name_display" name="Course_Name" placeholder="Program name will be auto-filled" class="form-control" readonly disabled style="background-color: #f8f9fa; cursor: not-allowed;" value="<?php echo isset($existing_record) ? htmlspecialchars($existing_record['PROGRAM_NAME']) : ''; ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label" for="program_code_display"><b>Program Code</b></label>
                                    <input type="text" id="program_code_display" name="Program_Code" placeholder="Program code will be auto-filled" class="form-control" readonly disabled style="background-color: #f8f9fa; cursor: not-allowed;" value="<?php echo isset($existing_record) ? htmlspecialchars($existing_record['PROGRAM_CODE']) : ''; ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label"><b>Total Student Enrolment Capacity</b></label>
                                    <input type="number" id="intake_capacity" name="Add_Total_Student_Intake" placeholder="Enrolment capacity will be auto-filled" class="form-control" readonly disabled style="background-color: #f8f9fa; cursor: not-allowed;" value="<?php 
                                        // CRITICAL: Get intake capacity from programmes table, not from existing_record
                                        // existing_record might have 0, but programmes table has the correct value
                                        if (isset($existing_record) && !empty($existing_record['PROGRAM_CODE'])) {
                                            $prog_code = $existing_record['PROGRAM_CODE'];
                                            // CRITICAL: Use COALESCE to prefer total_intake over intake_capacity
                                            // total_intake is calculated from year-wise breakdowns and is the actual value shown in Programmes_Offered
                                            $cap_query = "SELECT COALESCE(NULLIF(total_intake, 0), intake_capacity, 0) AS intake_capacity 
                                                         FROM programmes WHERE programme_code = ? AND DEPT_ID = ? LIMIT 1";
                                            $cap_stmt = mysqli_prepare($conn, $cap_query);
                                            if ($cap_stmt) {
                                                mysqli_stmt_bind_param($cap_stmt, "si", $prog_code, $dept);
                                                mysqli_stmt_execute($cap_stmt);
                                                $cap_result = mysqli_stmt_get_result($cap_stmt);
                                                if ($cap_result && mysqli_num_rows($cap_result) > 0) {
                                                    $cap_row = mysqli_fetch_assoc($cap_result);
                                                    echo (int)$cap_row['intake_capacity'];
                                                    mysqli_free_result($cap_result);
                                                } else {
                                                    echo isset($existing_record) ? (int)$existing_record['Add_Total_Student_Intake'] : '';
                                                    if ($cap_result) mysqli_free_result($cap_result);
                                                }
                                                mysqli_stmt_close($cap_stmt);
                                            } else {
                                                echo isset($existing_record) ? (int)$existing_record['Add_Total_Student_Intake'] : '';
                                            }
                                        } else {
                                            echo '';
                                        }
                                    ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Student Count Overview Table -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-users me-2"></i>Student Count Overview
                        </h3>

                        <div class="table-responsive">
                            <table class="table table-hover modern-table">
                                <thead class="table-header">
                                    <tr>
                                        <th scope="col">Category</th>
                                        <th scope="col">Male Students</th>
                                        <th scope="col">Female Students</th>
                                        <th scope="col">Other Students</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Within State Students -->
                                    <tr>
                                        <td><strong>Within State</strong></td>
                                        <td>
                                            <input type="number" name="Total_number_of_Male_Students_within_state"
                                                placeholder="Enter Count" class="form-control text-center" min="0"
                                                oninput="calculateTotalStudents()" required
                                                value="<?php echo isset($existing_record) ? $existing_record['Total_number_of_Male_Students_within_state'] : ''; ?>"
                                                <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        </td>
                                        <td>
                                            <input type="number" name="Total_number_of_Female_Students_within_state"
                                                placeholder="Enter Count" class="form-control text-center" min="0"
                                                oninput="calculateTotalStudents()" required
                                                value="<?php echo isset($existing_record) ? $existing_record['Total_number_of_Female_Students_within_state'] : ''; ?>"
                                                <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        </td>
                                        <td>
                                            <input type="number" name="Total_number_of_Other_Students_within_state"
                                                placeholder="Enter Count" class="form-control text-center" min="0"
                                                oninput="calculateTotalStudents()" required
                                                value="<?php echo isset($existing_record) ? $existing_record['Total_number_of_Other_Students_within_state'] : ''; ?>"
                                                <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        </td>
                                    </tr>

                                    <!-- Outside State Students -->
                                    <tr>
                                        <td><strong>Outside State</strong></td>
                                        <td>
                                            <input type="number" name="Male_Students_outside_state"
                                                placeholder="Enter Count" class="form-control text-center" min="0"
                                                oninput="calculateTotalStudents()" required
                                                value="<?php echo isset($existing_record) ? $existing_record['Male_Students_outside_state'] : ''; ?>"
                                                <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        </td>
                                        <td>
                                            <input type="number" name="Female_Students_outside_state"
                                                placeholder="Enter Count" class="form-control text-center" min="0"
                                                oninput="calculateTotalStudents()" required
                                                value="<?php echo isset($existing_record) ? $existing_record['Female_Students_outside_state'] : ''; ?>"
                                                <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        </td>
                                        <td>
                                            <input type="number" name="Other_Students_outside_state"
                                                placeholder="Enter Count" class="form-control text-center" min="0"
                                                oninput="calculateTotalStudents()" required
                                                value="<?php echo isset($existing_record) ? $existing_record['Other_Students_outside_state'] : ''; ?>"
                                                <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        </td>
                                    </tr>

                                    <!-- Outside Country Students -->
                                    <tr>
                                        <td><strong>Outside Country</strong></td>
                                        <td>
                                            <input type="number" id="male_outside_country" name="Male_Students_outside_country"
                                                placeholder="Enter Count" class="form-control text-center" min="0"
                                                oninput="calculateTotalStudents(); toggleCountrySection()" required
                                                value="<?php echo isset($existing_record) ? $existing_record['Male_Students_outside_country'] : ''; ?>"
                                                <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        </td>
                                        <td>
                                            <input type="number" id="female_outside_country" name="Female_Students_outside_country"
                                                placeholder="Enter Count" class="form-control text-center" min="0"
                                                oninput="calculateTotalStudents(); toggleCountrySection()" required
                                                value="<?php echo isset($existing_record) ? $existing_record['Female_Students_outside_country'] : ''; ?>"
                                                <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        </td>
                                        <td>
                                            <input type="number" id="other_outside_country" name="Other_Students_outside_country"
                                                placeholder="Enter Count" class="form-control text-center" min="0"
                                                oninput="calculateTotalStudents(); toggleCountrySection()" required
                                                value="<?php echo isset($existing_record) ? $existing_record['Other_Students_outside_country'] : ''; ?>"
                                                <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        </td>
                                    </tr>

                                    <!-- Total Students (Calculated) -->
                                    <tr style="background-color: rgba(102, 126, 234, 0.1);">
                                        <td><strong>Total Students *</strong></td>
                                        <td class="text-center fw-bold" id="total_male_students">0</td>
                                        <td class="text-center fw-bold" id="total_female_students">0</td>
                                        <td class="text-center fw-bold" id="total_other_students">0</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-info mt-3">
                            <strong>Note:</strong> All fields are required. Enter 0 if no students in that category.
                        </div>
                    </div>

                    <!-- Country-wise Student Details Section -->
                    <div id="country_section">
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class="fas fa-globe me-2"></i>Country-wise Student Details
                            </h3>
                            <div class="alert alert-info">
                                <strong>Instructions:</strong> Add country-wise details for students outside India. Click "Add Country" to add more countries.
                            </div>

                            <!-- Country Table -->
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped" id="countryTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Country</th>
                                            <th>Number of Students</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Country rows will be added dynamically -->
                                    </tbody>
                                </table>
                            </div>

                            <button type="button" class="btn btn-outline-primary" onclick="addCountryRow()">
                                <i class="fas fa-plus me-2"></i>Add Country
                            </button>
                        </div>
                    </div>
                    <hr>

                    <!-- PDF Upload Section -->
                    <div class="form-group">
                        <label>Required Supporting Documents <span class="text-danger">*</span></label>
                        <div class="alert alert-info" style="margin-bottom: 15px;">
                            <strong>📄 File Upload Guidelines:</strong>
                            <ul class="mb-0 mt-2">
                                <li><strong>File Format:</strong> PDF files only</li>
                                <li><strong>Maximum Size:</strong> 5 MB per file</li>
                                <li><strong>File Name:</strong> Use descriptive names (e.g., "Intake_Actual_Strength_2024.pdf")</li>
                                <li><strong>Quality:</strong> Ensure documents are clear and readable</li>
                            </ul>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <strong>💡 File Too Large?</strong> Try compressing your PDF using online tools like SmallPDF, ILovePDF, or Adobe Acrobat's "Reduce File Size" feature.
                                </small>
                            </div>
                        </div>

                        <script>
                            // Enhanced calculate totals function
                            function calculateTotalStudents() {
                                // Calculate male totals
                                const maleWithin = parseInt(document.querySelector('input[name="Total_number_of_Male_Students_within_state"]').value) || 0;
                                const maleOutside = parseInt(document.querySelector('input[name="Male_Students_outside_state"]').value) || 0;
                                const maleCountry = parseInt(document.getElementById('male_outside_country').value) || 0;
                                const totalMale = maleWithin + maleOutside + maleCountry;
                                document.getElementById('total_male_students').textContent = totalMale;

                                // Calculate female totals
                                const femaleWithin = parseInt(document.querySelector('input[name="Total_number_of_Female_Students_within_state"]').value) || 0;
                                const femaleOutside = parseInt(document.querySelector('input[name="Female_Students_outside_state"]').value) || 0;
                                const femaleCountry = parseInt(document.getElementById('female_outside_country').value) || 0;
                                const totalFemale = femaleWithin + femaleOutside + femaleCountry;
                                document.getElementById('total_female_students').textContent = totalFemale;

                                // Calculate other totals
                                const otherWithin = parseInt(document.querySelector('input[name="Total_number_of_Other_Students_within_state"]').value) || 0;
                                const otherOutside = parseInt(document.querySelector('input[name="Other_Students_outside_state"]').value) || 0;
                                const otherCountry = parseInt(document.getElementById('other_outside_country').value) || 0;
                                const totalOther = otherWithin + otherOutside + otherCountry;
                                document.getElementById('total_other_students').textContent = totalOther;

                                // Validate against enrolment capacity
                                validateStudentCount(totalMale, totalFemale, totalOther);
                            }

                            // Alias function for calculateTotalStudents
                            function calculateStudentTotals() {
                                calculateTotalStudents();
                            }

                            // Update program details function
                            function updateProgramDetails() {
                                const programSelect = document.getElementById('program_select');
                                if (!programSelect) {
                                    console.error('[Intake] ERROR: program_select element not found!');
                                    return;
                                }
                                
                                const selectedOption = programSelect.options[programSelect.selectedIndex];

                                if (selectedOption && selectedOption.value) {
                                    // CRITICAL: Use data-name attribute (not text) to get clean program name without type
                                    const programName = selectedOption.getAttribute('data-name') || selectedOption.text.split(' (')[0];
                                    const programCode = selectedOption.value; // This is now programme_code
                                    
                                    // CRITICAL: Get intake capacity from data-intake attribute
                                    // Debug: Log all attributes to troubleshoot
                                    const dataIntakeRaw = selectedOption.getAttribute('data-intake');
                                    const intakeCapacity = parseInt(dataIntakeRaw || 0);
                                    
                                    console.log('[Intake] updateProgramDetails - Program Code:', programCode);
                                    console.log('[Intake] updateProgramDetails - data-intake raw:', dataIntakeRaw);
                                    console.log('[Intake] updateProgramDetails - intakeCapacity parsed:', intakeCapacity);
                                    console.log('[Intake] updateProgramDetails - selectedOption:', selectedOption);

                                    // Update program name display - ALWAYS keep disabled/readonly
                                    const programNameElement = document.getElementById('program_name_display');
                                    if (programNameElement) {
                                        programNameElement.value = programName;
                                        // CRITICAL: Always keep disabled to prevent editing
                                        programNameElement.disabled = true;
                                        programNameElement.readOnly = true;
                                    }

                                    // Update program code display - ALWAYS keep disabled/readonly
                                    const programCodeElement = document.getElementById('program_code_display');
                                    if (programCodeElement) {
                                        programCodeElement.value = programCode;
                                        // CRITICAL: Always keep disabled to prevent editing
                                        programCodeElement.disabled = true;
                                        programCodeElement.readOnly = true;
                                    }

                                    // Update enrolment capacity - ALWAYS keep disabled/readonly
                                    const intakeElement = document.getElementById('intake_capacity');
                                    if (intakeElement) {
                                        // CRITICAL: Only set if we have a valid intake capacity (> 0)
                                        // If intakeCapacity is 0, it might mean the data-intake attribute is missing or 0
                                        if (intakeCapacity > 0) {
                                        intakeElement.value = intakeCapacity;
                                            console.log('[Intake] ✓ Updated intake capacity to:', intakeCapacity);
                                        } else {
                                            console.warn('[Intake] WARNING: intakeCapacity is 0 or invalid. data-intake attribute:', dataIntakeRaw);
                                            console.warn('[Intake] WARNING: This might indicate the programmes table has intake_capacity = 0 for program:', programCode);
                                            // Keep existing value if it's already set, otherwise set to 0
                                            if (!intakeElement.value || intakeElement.value === '0' || intakeElement.value === '') {
                                                intakeElement.value = 0;
                                            }
                                        }
                                        // CRITICAL: Always keep disabled to prevent editing
                                        intakeElement.disabled = true;
                                        intakeElement.readOnly = true;
                                    } else {
                                        console.error('[Intake] ERROR: intake_capacity element not found!');
                                    }
                                } else {
                                    // No program selected - clear fields but keep them disabled
                                    const programNameElement = document.getElementById('program_name_display');
                                    const programCodeElement = document.getElementById('program_code_display');
                                    const intakeElement = document.getElementById('intake_capacity');
                                    
                                    if (programNameElement) {
                                        programNameElement.value = '';
                                        programNameElement.disabled = true;
                                        programNameElement.readOnly = true;
                                    }
                                    if (programCodeElement) {
                                        programCodeElement.value = '';
                                        programCodeElement.disabled = true;
                                        programCodeElement.readOnly = true;
                                    }
                                    if (intakeElement) {
                                        intakeElement.value = '';
                                        intakeElement.disabled = true;
                                        intakeElement.readOnly = true;
                                    }
                                }
                            }

                            // Update record from table (same as dropdown selection)
                            function updateRecordFromTable(recordId) {
                                // Get the program code from the record ID
                                fetch(`IntakeActualStrength.php?action=get_record_data&record_id=${recordId}`)
                                    .then(response => {
                                        const contentType = response.headers.get('content-type');
                                        if (!contentType || !contentType.includes('application/json')) {
                                            return response.text().then(text => {
                                                throw new Error('Response is not JSON. Content-Type: ' + contentType + '. Response: ' + text.substring(0, 200));
                                            });
                                        }
                                        return response.json();
                                    })
                                    .then(data => {
                                        if (data.success) {
                                            const record = data.record;
                                            const programCode = record.PROGRAM_CODE;

                                            // Set the program dropdown to this program
                                            const programSelect = document.getElementById('program_select');
                                            if (programSelect) {
                                                programSelect.value = programCode;

                                                // Load country data for this record BEFORE triggering checkProgramData
                                                // This ensures country data is available when form loads
                                                loadCountryData(recordId);

                                                // Trigger the same behavior as dropdown selection
                                                // This will load all other form fields and lock the form
                                                checkProgramData();

                                                // Scroll to form
                                                const firstCard = document.querySelector('.card:first-of-type');
                                                if (firstCard) {
                                                    firstCard.scrollIntoView({
                                                    behavior: 'smooth'
                                                });
                                                }

                                                showMessage('✅ Record loaded for update. Form is now locked with Edit/Clear buttons available.', 'success');
                                            }
                                        } else {
                                            showMessage('❌ Error loading record: ' + (data.message || 'Unknown error'), 'error');
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error loading record:', error);
                                        showMessage('❌ Error loading record data: ' + error.message, 'error');
                                    });
                            }

                            // Edit functionality - use updateRecordFromTable which properly loads all data including country data
                            function editRecord(recordId) {
                                updateRecordFromTable(recordId);
                            }

                            // Update record function
                            function updateRecord() {
                                // Validate form
                                const form = document.getElementById('editIntakeForm');
                                const formData = new FormData(form);
                                formData.append('update_record', '1');

                                // Show loading
                                const updateBtn = document.querySelector('button[onclick="updateRecord()"]');
                                const originalText = updateBtn.innerHTML;
                                updateBtn.innerHTML = '<div class="loading"></div> Updating...';
                                updateBtn.disabled = true;

                                fetch(window.location.href, {
                                        method: 'POST',
                                        body: formData
                                    })
                                    .then(response => {
                                        if (response.ok) {
                                            showMessage('✅ Record updated successfully!', 'success');
                                            setTimeout(() => {
                                                location.reload();
                                            }, 1500);
                                        } else {
                                            throw new Error('Update failed');
                                        }
                                    })
                                    .catch(error => {
                                        showMessage('❌ Error updating record', 'error');
                                        updateBtn.innerHTML = originalText;
                                        updateBtn.disabled = false;
                                    });
                            }

                            // Cancel edit function
                            function cancelEdit() {
                                // Hide edit form and show main form
                                document.getElementById('editForm').style.display = 'none';
                                const firstCard = document.querySelector('.card:first-of-type');
                                if (firstCard) {
                                    firstCard.style.display = 'block';

                                // Scroll to main form
                                    firstCard.scrollIntoView({
                                    behavior: 'smooth'
                                });
                                }

                                showMessage('Edit cancelled. Form returned to previous state.', 'info');
                            }

                            // Show message function
                            function showMessage(message, type = 'info') {
                                // Remove existing messages (but NOT the Important Guidelines alert)
                                const existingMessages = document.querySelectorAll('.alert-message');
                                existingMessages.forEach(msg => {
                                    // CRITICAL: Never remove the Important Guidelines alert
                                    if (msg.id !== 'important-instructions-alert') {
                                        msg.remove();
                                    }
                                });

                                // Create message element
                                const messageDiv = document.createElement('div');
                                messageDiv.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show alert-message`;
                                messageDiv.style.position = 'fixed';
                                messageDiv.style.top = '120px'; // Moved down to avoid header overlap
                                messageDiv.style.right = '20px';
                                messageDiv.style.zIndex = '9999';
                                messageDiv.style.minWidth = '300px';
                                messageDiv.style.maxWidth = '400px';
                                messageDiv.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';

                                messageDiv.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="flex: 1;">
                    <i class="fas fa-${type === 'error' ? 'exclamation-circle' : type === 'success' ? 'check-circle' : 'info-circle'}" style="margin-right: 8px;"></i>
                    ${message}
                </div>
                <button type="button" onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; font-size: 18px; cursor: pointer; margin-left: 10px;">&times;</button>
            </div>
        `;

                                document.body.appendChild(messageDiv);

                                // Auto remove after 5 seconds
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

                            function toggleCountrySection() {
                                const maleOutside = parseInt(document.getElementById('male_outside_country').value) || 0;
                                const femaleOutside = parseInt(document.getElementById('female_outside_country').value) || 0;
                                const otherOutside = parseInt(document.getElementById('other_outside_country').value) || 0;

                                const totalOutside = maleOutside + femaleOutside + otherOutside;
                                const countrySection = document.getElementById('country_section');

                                if (countrySection) {
                                    if (totalOutside > 0) {
                                        countrySection.style.display = 'block';
                                    } else {
                                        countrySection.style.display = 'none';
                                    }
                                }
                            }

                            function generateCountrymale() {
                                generateCountryStudent('Male')
                            }

                            function generateCountryfemale() {
                                generateCountryStudent('Female')
                            }

                            function generateCountryStudent(gender) {
                                const jrfsCount = parseInt(document.getElementsByName(gender + '_Students_outside_country')[0].value) || 0;
                                const totalMales = jrfsCount; // total target number of students
                                const container = document.getElementById('country_entries_container');

                                if (!container) {
                                    console.error('Container not found: country_entries_container');
                                    return;
                                }

                                // Show/hide country section based on whether there are outside country students
                                const countrySection = document.getElementById('country_section');
                                if (countrySection) {
                                    if (totalMales > 0) {
                                        countrySection.style.display = 'block';
                                    } else {
                                        countrySection.style.display = 'none';
                                    }
                                }

                                container.innerHTML = ''; // Clear existing fields

                                if (totalMales > 0) {
                                    for (let i = 1; i <= totalMales; i++) {
                                        const jrfCard = document.createElement('div');
                                        jrfCard.className = 'card mb-3 p-3';
                                        jrfCard.style.display = (i === 1) ? 'block' : 'none'; // Show only the first row initially

                                        jrfCard.innerHTML = `
                    <h6 class="mb-3">Country ${i}</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fw-bold" for="country_code_${i}">Select Country</label>
                            <select class="form-control" name="country_codes[]" id="country_code_${i}">
                                ${countryOptions}
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold" for="country_students_${i}">Number of Students</label>
                            <input type="number" class="form-control" name="country_students[]" 
                                   id="country_students_${i}" placeholder="Enter number of students" min="0" value="0">
                        </div>
                    </div>
                `;

                                        container.appendChild(jrfCard);

                                        // Add event listener to check sum and reveal next row
                                        jrfCard.querySelector('input').addEventListener('change', function() {
                                            let currentSum = 0;
                                            container.querySelectorAll('input[type="number"]').forEach(inp => {
                                                currentSum += parseInt(inp.value) || 0;
                                            });

                                            if (currentSum < totalMales && i < totalMales) {
                                                container.children[i].style.display = 'block'; // show next row
                                                // } else if (currentSum === totalMales) {
                                                //     alert("✅ All students distributed correctly!");
                                            } else if (currentSum > totalMales) {
                                                showMessage("⚠️ Total exceeds allowed students!", 'warning');
                                                this.value = '';
                                            }
                                        });
                                    }
                                }
                            }
                        </script>



                        <!-- Total Intake Proof Upload Section -->
                        <div class="mb-4">
                            <h5 class="mb-3" style="color: #667eea; font-weight: 700;">Total Enrolment Proof Documentation</h5>
                            <div class="upload-section">
                                <div class="mb-3">
                                    <label class="form-label">Upload Total Enrolment Proof Document (PDF only)</label>
                                    <div class="input-group">
                                        <input type="file" id="total_intake_proof_file" name="total_intake_proof_file" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('total_intake_proof_file', 1)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>Upload</button>
                                    </div>
                                    <div class="form-text">Maximum file size: 5MB. Only PDF files are allowed.</div>
                                    <div id="total_intake_proof_file_status" class="mt-2"></div>
                                </div>
                            </div>
                        </div>
                        <hr>

                        <!-- Regional Diversity Upload Section -->
                        <div class="mb-4">
                            <h5 class="mb-3" style="color: #667eea; font-weight: 700;">Regional Diversity of Students</h5>
                            <div class="alert alert-info">
                                <strong>Instructions:</strong> Upload a combined PDF document containing detailed information about
                                regional diversity of students. The document should include number of students within university,
                                outside university, outside state and outside country both female and male separately for each category
                                and for all programs.
                            </div>
                            <div class="upload-section">
                                <div class="mb-3">
                                    <label class="form-label">Upload Regional Diversity Document (PDF only)</label>
                                    <div class="input-group">
                                        <input type="file" id="regional_diversity_file" name="regional_diversity_file" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                        <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('regional_diversity_file', 2)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>Upload</button>
                                    </div>
                                    <div class="form-text">Maximum file size: 5MB. Only PDF files are allowed.</div>
                                    <div id="regional_diversity_file_status" class="mt-2"></div>
                                </div>
                            </div>
                        </div>
                        <hr>

                        <hr>
                        <!-- sc st obc -->
                        <hr>
                        <div class="form-group">
                            <label>Enrolment Based on Reserved Category</label>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Reserved Categories</th>
                                            <th>Male Students</th>
                                            <th>Female Students</th>
                                            <th>Other Students</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong>General <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="General Category Students"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Student_General" placeholder="General Male Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_General'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_General"
                                                    placeholder="General Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_General'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_General"
                                                    placeholder="General Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_General'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Social Backward(SC) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Scheduled Caste - SC"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_SC" placeholder="SC Male Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_SC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_SC"
                                                    placeholder="SC Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_SC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_SC"
                                                    placeholder="SC Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_SC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Social Backward(ST) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Scheduled Tribe - ST"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_ST" placeholder="ST Male Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_ST'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_ST"
                                                    placeholder="ST Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_ST'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_ST"
                                                    placeholder="ST Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_ST'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Social Backward(OBC) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Other Backward Classes - OBC"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_OBC"
                                                    placeholder="OBC Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_OBC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_OBC"
                                                    placeholder="OBC Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_OBC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_OBC"
                                                    placeholder="OBC Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_OBC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Social Backward(DTA) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Denotified Tribes - DT-A"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_DTA"
                                                    placeholder="DTA Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_DTA'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_DTA"
                                                    placeholder="DTA Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_DTA'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_DTA"
                                                    placeholder="DTA Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_DTA'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td><strong>Social Backward(NTB) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Nomadic Tribes - NT-B"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_NTB"
                                                    placeholder="NTB Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_NTB'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_NTB"
                                                    placeholder="NTB Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_NTB'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_NTB"
                                                    placeholder="NTB Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_NTB'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Social Backward(NTC) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Nomadic Tribes - NT-C"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_NTC"
                                                    placeholder="NTC Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_NTC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_NTC"
                                                    placeholder="NTC Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_NTC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_NTC"
                                                    placeholder="NTC Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_NTC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Social Backward(NTD) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Nomadic Tribes - NT-D"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_NTD"
                                                    placeholder="NTD Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_NTD'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_NTD"
                                                    placeholder="NTD Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_NTD'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_NTD"
                                                    placeholder="NTD Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_NTD'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td><strong>Social Backward(EWS) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Economically Weaker Section - EWS"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_EWS"
                                                    placeholder="EWS Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_EWS'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_EWS"
                                                    placeholder="EWS Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_EWS'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_EWS"
                                                    placeholder="EWS Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_EWS'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td><strong>Social Backward(SEBC) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Socially and Educationally Backward Class - SEBC"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_SEBC"
                                                    placeholder="SEBC Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_SEBC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_SEBC"
                                                    placeholder="SEBC Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_SEBC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_SEBC"
                                                    placeholder="SEBC Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_SEBC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Social Backward(SBC) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Special Backward Class - SBC"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Social_Backward_SBC"
                                                    placeholder="SBC Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Social_Backward_SBC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Social_Backward_SBC"
                                                    placeholder="SBC Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Social_Backward_SBC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Social_Backward_SBC"
                                                    placeholder="SBC Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Social_Backward_SBC'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td><strong>Physically Handicapped <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Physically Handicapped (Divyang) - PH"></i></strong></td>
                                            <td>
                                                <!-- //1 -->
                                                <input type="number" name="Male_Students_Physically_Handicapped"
                                                    placeholder="PH Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Physically_Handicapped'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Students_Physically_Handicapped"
                                                    placeholder="PH Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Physically_Handicapped'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Students_Physically_Handicapped"
                                                    placeholder="PH Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Physically_Handicapped'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td><strong>TRANGOVTOF TGO <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Wards of transferred police/government employees/officers - TRANGOVT OF-TGO"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_TRANGOVTOF_TGO" placeholder="TGO Male Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_TGO'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Students_TRANGOVTOF_TGO" placeholder="TGO Female Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_TGO'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Students_TRANGOVTOF_TGO" placeholder="TGO Other Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_TGO'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td><strong>CMIL <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Current/Retired Military - CMIL"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_CMIL" placeholder="CMIL Male Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_CMIL'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_CMIL" placeholder="CMIL Female Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_CMIL'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_CMIL" placeholder="CMIL Other Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_CMIL'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td><strong>SPCUL <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Students with special achievements at National/State level in Sports and Cultural activities (Sports quota) - SPCUL"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Student_SPCUL" placeholder="SPCUL Male Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_SPCUL'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_SPCUL" placeholder="SPCUL Female Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_SPCUL'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_SPCUL" placeholder="SPCUL Other Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_SPCUL'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Widow/Single Mother <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Widow Single Mother - W/SM"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Student_Widow_Single_Mother"
                                                    placeholder="W/SM Male Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Widow_Single_Mother'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_Widow_Single_Mother"
                                                    placeholder="W/SM Female Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Widow_Single_Mother'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_Widow_Single_Mother"
                                                    placeholder="W/SM Other Count" class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Widow_Single_Mother'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td><strong>ES <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Wards/grandchildren of Freedom Fighters (Ex-servicemen quota) - ES"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Student_ES" placeholder="ES Male Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_ES'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Student_ES" placeholder="ES Female Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_ES'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Student_ES" placeholder="ES Other Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_ES'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Economically Backward Class (EBC) <i class="fas fa-info-circle text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Students whose parental income is less than taxable slab"></i></strong></td>
                                            <td>
                                                <input type="number" name="Male_Students_Economic_Backward" placeholder="EBC Male Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Students_Economic_Backward'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Female_Students_Economic_Backward" placeholder="EBC Female Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Students_Economic_Backward'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="Other_Students_Economic_Backward" placeholder="EBC Other Count"
                                                    class="form-control" style="margin-top: 0;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Students_Economic_Backward'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="row mb-3">


                            <hr>

                            <!-- ESCS Diversity Upload Section -->
                            <div class="mb-4">
                                <h5 class="mb-3" style="color: #667eea; font-weight: 700;">ESCS Diversity of Students as per Govt of
                                    Maharashtra Reservation Policy</h5>
                                <div class="alert alert-info">
                                    <strong>Instructions:</strong> Upload a combined PDF document containing all required data for ESCS
                                    diversity of students as per Government of Maharashtra Reservation Policy. The document should include
                                    total number of reserved category students (both male and female) for each category as per Govt. G.R
                                    enrolled in the department for each program.
                                </div>
                                <div class="upload-section">
                                    <div class="mb-3">
                                        <label class="form-label">Upload ESCS Diversity Document (PDF only)</label>
                                        <div class="input-group">
                                            <input type="file" id="escs_diversity_file" name="escs_diversity_file" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('escs_diversity_file', 3)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>Upload</button>
                                        </div>
                                        <div class="form-text">Maximum file size: 5MB. Only PDF files are allowed.</div>
                                        <div id="escs_diversity_file_status" class="mt-2"></div>
                                    </div>
                                </div>
                            </div>
                            <hr>


                            <!-- Scholarship Table -->
                            <div class="table-responsive mb-4">
                                <table class="table table-bordered table-hover" style="background: rgba(255, 255, 255, 0.95); border-radius: 15px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);">
                                    <thead style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                                        <tr>
                                            <th scope="col" style="border: none; padding: 15px; text-align: center; font-weight: 600;">Scholarship Category</th>
                                            <th scope="col" style="border: none; padding: 15px; text-align: center; font-weight: 600;">Male Students</th>
                                            <th scope="col" style="border: none; padding: 15px; text-align: center; font-weight: 600;">Female Students</th>
                                            <th scope="col" style="border: none; padding: 15px; text-align: center; font-weight: 600;">Other Students</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 15px; font-weight: 600; background: rgba(102, 126, 234, 0.1);">Government Scholarships</td>
                                            <td style="padding: 15px;">
                                                <input type="number" name="Male_Student_Receiving_Scholarship_Government" placeholder="Enter Count"
                                                    class="form-control" style="border: 2px solid #e9ecef; border-radius: 8px;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Receiving_Scholarship_Government'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                                </input>

                                            </td>
                                            <td style="padding: 15px;">
                                                <input type="number" name="Female_Student_Receiving_Scholarship_Government"
                                                    placeholder="Enter Count" class="form-control" style="border: 2px solid #e9ecef; border-radius: 8px;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Receiving_Scholarship_Government'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td style="padding: 15px;">
                                                <input type="number" name="Other_Student_Receiving_Scholarship_Government"
                                                    placeholder="Enter Count" class="form-control" style="border: 2px solid #e9ecef; border-radius: 8px;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Receiving_Scholarship_Government'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 15px; font-weight: 600; background: rgba(102, 126, 234, 0.1);">Institution Scholarships</td>
                                            <td style="padding: 15px;">
                                                <input type="number" name="Male_Student_Receiving_Scholarship_Institution"
                                                    placeholder="Enter Count" class="form-control" style="border: 2px solid #e9ecef; border-radius: 8px;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Receiving_Scholarship_Institution'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td style="padding: 15px;">
                                                <input type="number" name="Female_Student_Receiving_Scholarship_Institution"
                                                    placeholder="Enter Count" class="form-control" style="border: 2px solid #e9ecef; border-radius: 8px;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Receiving_Scholarship_Institution'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td style="padding: 15px;">
                                                <input type="number" name="Other_Student_Receiving_Scholarship_Institution"
                                                    placeholder="Enter Count" class="form-control" style="border: 2px solid #e9ecef; border-radius: 8px;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Receiving_Scholarship_Institution'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 15px; font-weight: 600; background: rgba(102, 126, 234, 0.1);">Private Body Scholarships</td>
                                            <td style="padding: 15px;">
                                                <input type="number" name="Male_Student_Receiving_Scholarship_Private_Body"
                                                    placeholder="Enter Count" class="form-control" style="border: 2px solid #e9ecef; border-radius: 8px;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Male_Student_Receiving_Scholarship_Private_Body'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td style="padding: 15px;">
                                                <input type="number" name="Female_Student_Receiving_Scholarship_Private_Body"
                                                    placeholder="Enter Count" class="form-control" style="border: 2px solid #e9ecef; border-radius: 8px;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Female_Student_Receiving_Scholarship_Private_Body'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                            <td style="padding: 15px;">
                                                <input type="number" name="Other_Student_Receiving_Scholarship_Private_Body"
                                                    placeholder="Enter Count" class="form-control" style="border: 2px solid #e9ecef; border-radius: 8px;" required
                                                    value="<?php echo isset($existing_record) ? $existing_record['Other_Student_Receiving_Scholarship_Private_Body'] : ''; ?>"
                                                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <hr>

                            <!-- Scholarship/Freeship Upload Section -->
                            <div class="mb-4">
                                <h5 class="mb-3" style="color: #667eea; font-weight: 700;">Number of male and female students receiving
                                    scholarships/freeships programwise</h5>
                                <div class="alert alert-info">
                                    <strong>Instructions:</strong> Upload a combined PDF document containing detailed information about male
                                    and female students receiving scholarships/freeships programwise. The document should include
                                    comprehensive data for all scholarship categories (Government, Institution, Private Bodies) with proper
                                    categorization.
                                </div>
                                <div class="upload-section">
                                    <div class="mb-3">
                                        <label class="form-label">Upload Scholarship/Freeship Document (PDF only)</label>
                                        <div class="input-group">
                                            <input type="file" id="scholarship_freeship_file" name="scholarship_freeship_file" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>
                                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('scholarship_freeship_file', 4)" <?php echo $form_locked ? 'readonly disabled' : ''; ?>>Upload</button>
                                        </div>
                                        <div class="form-text">Maximum file size: 5MB. Only PDF files are allowed.</div>
                                        <div id="scholarship_freeship_file_status" class="mt-2"></div>
                                    </div>
                                </div>
                            </div>


                            <script>
                                // Auto-focus on first input field on page load
                                document.addEventListener('DOMContentLoaded', function() {
                                    const programSelect = document.getElementById('program_select');
                                    if (programSelect && !programSelect.disabled) {
                                        programSelect.focus();
                                    }

                                    // Ensure program reference fields are always readonly
                                    ensureProgramFieldsReadonly();

                                    // CRITICAL: Initialize program details AFTER a small delay to ensure select is fully populated
                                    // This ensures data-intake attributes are available
                                    setTimeout(function() {
                                    updateProgramDetails();
                                        
                                        // Double-check: If intake capacity is still 0, try to fetch from selected option again
                                        const intakeElement = document.getElementById('intake_capacity');
                                        if (intakeElement && (intakeElement.value === '0' || intakeElement.value === '')) {
                                            const selectedOption = programSelect.options[programSelect.selectedIndex];
                                            if (selectedOption && selectedOption.value) {
                                                const dataIntake = selectedOption.getAttribute('data-intake');
                                                if (dataIntake && parseInt(dataIntake) > 0) {
                                                    intakeElement.value = dataIntake;
                                                    console.log('[Intake] Fixed intake capacity from data-intake:', dataIntake);
                                                }
                                            }
                                        }
                                    }, 100);

                                    // Initialize form state based on current state
                                    initializeFormState();

                                    // Initialize data summary section
                                    updateDataSummarySection();

                                    // Add event listeners to all number inputs
                                    const numberInputs = document.querySelectorAll('input[type="number"]');
                                    numberInputs.forEach(input => {
                                        input.addEventListener('input', calculateStudentTotals);
                                    });

                                    // Add event listeners to outside country inputs
                                    const maleOutsideInput = document.querySelector('input[name="Male_Students_outside_country"]');
                                    const femaleOutsideInput = document.querySelector('input[name="Female_Students_outside_country"]');

                                    if (maleOutsideInput) {
                                        maleOutsideInput.addEventListener('input', checkCountrySectionVisibility);
                                        maleOutsideInput.addEventListener('change', checkCountrySectionVisibility);
                                    }
                                    if (femaleOutsideInput) {
                                        femaleOutsideInput.addEventListener('input', checkCountrySectionVisibility);
                                        femaleOutsideInput.addEventListener('change', checkCountrySectionVisibility);
                                    }

                                    // Initialize Bootstrap tooltips
                                    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                                    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                                        return new bootstrap.Tooltip(tooltipTriggerEl);
                                    });
                                });

                                // Initialize form state
                                function initializeFormState() {
                                    const formState = '<?php echo $form_state; ?>';

                                    if (formState === 'locked') {
                                        setFormState('locked');
                                        <?php if ($existing_record): ?>
                                            loadExistingData(<?php echo json_encode($existing_record); ?>);
                                        <?php endif; ?>
                                    } else if (formState === 'edit') {
                                        setFormState('edit');
                                        <?php if ($existing_record): ?>
                                            loadExistingData(<?php echo json_encode($existing_record); ?>);
                                        <?php endif; ?>
                                    } else {
                                        // Default to new state - no program selected
                                        setFormState('new');
                                        resetFormToNewState();
                                        showMessage('Please select a program to enter data', 'info');
                                    }

                                    // Calculate totals if we have existing data
                                    if (formState === 'locked' || formState === 'edit') {
                                        calculateTotalStudents();
                                    }

                                    // Load existing documents (only if program is selected)
                                    const programSelect = document.getElementById('program_select');
                                    if (programSelect && programSelect.value) {
                                        loadExistingDocuments();
                                    }
                                }
                                
                                // CRITICAL: Prevent duplicate document loading
                                // The loadExistingDocuments() call above is sufficient
                                // Removed duplicate call to prevent 2x database queries

                                // Initialize country table with one empty row
                                function initializeCountryTable() {
                                    const countryTableBody = document.querySelector('#countryTable tbody');
                                    if (countryTableBody && countryTableBody.children.length === 0) {
                                        addCountryRow();
                                    }
                                }

                                // REWRITTEN: Match PlacementDetails.php exactly - wire on DOMContentLoaded
                                (function wireIntakeFormSubmission() {
                                    function attachHandlers() {
                                        const form = document.querySelector('form.modern-form');
                                        const submitBtn = form ? form.querySelector('button[type="submit"]') : null;

                                        if (!form) {
                                            console.error('[Intake] Form not found!');
                                            return false;
                                        }

                                        if (!submitBtn) {
                                            console.error('[Intake] Submit button not found!');
                                            return false;
                                        }

                                        console.log('[Intake] Wiring form submission handlers...');

                                        // Handle form submit event - validate here (matches PlacementDetails.php pattern)
                                        form.addEventListener('submit', function(e) {
                                            console.log('[Intake] ========== FORM SUBMIT EVENT FIRED ==========');

                                            const programSelect = document.getElementById('program_select');

                                            // Validate program selection
                                            if (!programSelect || !programSelect.value) {
                                                e.preventDefault();
                                                showMessage('Please select a program first.', 'warning');
                                                console.log('[Intake] VALIDATION FAILED: No program selected');
                                                return false;
                                            }
                                            console.log('[Intake] ✓ Program selected:', programSelect.value);

                                            // CRITICAL: Remove readonly and disabled from ALL inputs to ensure they're submitted
                                            // This must happen BEFORE form submission to prevent values defaulting to 0
                                            // SECURITY NOTE: This includes CSRF token field - ensuring it's enabled is CORRECT
                                            // because disabled fields don't submit, which would break CSRF protection.
                                            // Server-side CSRF validation (lines 1370-1381) will catch any missing/invalid tokens.
                                            // CRITICAL: NEVER enable program reference fields - they must always stay disabled
                                            const allInputs = form.querySelectorAll('input, select, textarea');
                                            let removedCount = 0;
                                            allInputs.forEach(input => {
                                                // CRITICAL: NEVER enable program reference fields - they must always be disabled
                                                if (input.id === 'program_name_display' ||
                                                    input.id === 'program_code_display' ||
                                                    input.id === 'intake_capacity') {
                                                    // Keep these fields disabled - they're read-only reference fields
                                                    return; // Skip these fields
                                                }
                                                
                                                // Remove readonly attribute AND property
                                                if (input.hasAttribute('readonly') || input.readOnly) {
                                                    input.removeAttribute('readonly');
                                                    input.readOnly = false;
                                                    removedCount++;
                                                }
                                                // Remove disabled attribute AND property
                                                if (input.hasAttribute('disabled') || input.disabled) {
                                                    input.disabled = false;
                                                    input.removeAttribute('disabled');
                                                    removedCount++;
                                                }
                                                // Ensure the input is enabled and can submit its value
                                                // Note: CSRF token (hidden field) is intentionally included to ensure it submits
                                                if (input.type !== 'hidden' && input.id !== 'program_select') {
                                                    input.style.backgroundColor = '';
                                                    input.style.cursor = '';
                                                }
                                            });
                                            console.log('[Intake] Removed readonly/disabled from', removedCount, 'inputs');
                                            
                                            // Double-check: Verify critical fields are enabled
                                            const criticalFields = ['Male_Students_Social_Backward_SC', 'Male_Student_General', 
                                                'Female_Student_General', 'Other_Student_General'];
                                            criticalFields.forEach(fieldName => {
                                                const field = form.querySelector(`[name="${fieldName}"]`);
                                                if (field && (field.disabled || field.readOnly)) {
                                                    console.warn('[Intake] WARNING: Field', fieldName, 'is still disabled/readonly!');
                                                    field.disabled = false;
                                                    field.readOnly = false;
                                                }
                                            });

                                            console.log('[Intake] ========== ALL VALIDATIONS PASSED! ==========');
                                            console.log('[Intake] Form will submit to server now...');

                                            // Change button text and disable after delay (matches PlacementDetails.php)
                                            const originalHtml = submitBtn.innerHTML;
                                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';

                                            setTimeout(function() {
                                                submitBtn.disabled = true;
                                            }, 50);

                                            // Don't prevent default - allow form to submit naturally
                                            console.log('[Intake] Allowing form to submit - POST request will be sent...');
                                        });

                                        console.log('[Intake] Form submission handlers wired successfully');
                                        return true;
                                    }

                                    // Try to attach immediately, or wait for DOM
                                    if (document.readyState === 'loading') {
                                        document.addEventListener('DOMContentLoaded', attachHandlers);
                                    } else {
                                        attachHandlers();
                                    }
                                })();

                                function editData() {
                                    // Get the value of the input field (example)
                                    var inputValue = document.getElementById("myInput").value;

                                    // Perform editing actions (example: display an alert)
                                    // Removed debug alert
                                }
                            </script>

                            <!-- Action Buttons -->
                            <div class="text-center mt-4">
                                <div class="d-flex flex-wrap justify-content-center gap-3" id="buttonContainer">
                                    <!-- Buttons will be dynamically managed by JavaScript -->
                                    <button type="submit" name="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Data
                                    </button>
                                    <button type="button" class="btn btn-warning btn-lg px-5" id="updateBtn" onclick="enableUpdate()" style="display:none;">
                                        <i class="fas fa-edit me-2"></i>Update Data
                                    </button>
                                    <button type="submit" name="update" class="btn btn-success btn-lg px-5" id="saveBtn" style="display:none;">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-lg px-5" id="cancelUpdateBtn" onclick="cancelUpdate()" style="display:none;">
                                        <i class="fas fa-times me-2"></i>Cancel Update
                                    </button>
                                    <button type="button" class="btn btn-danger btn-lg px-5" id="clearBtn" onclick="clearData()" style="display:none;">
                                        <i class="fas fa-trash me-2"></i>Clear Data
                                    </button>
                                </div>
                            </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    :root {
        --primary-color: #667eea;
        --secondary-color: #764ba2;
        --success-color: #28a745;
        --danger-color: #dc3545;
        --warning-color: #ffc107;
        --info-color: #17a2b8;
        --light-color: #f8f9fa;
        --dark-color: #343a40;
        --border-radius: 1rem;
        --box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.1);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
        box-sizing: border-box;
    }

    body {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        min-height: 100vh;
        margin: 0;
        padding: 2rem 0;
    }

    .container-fluid {
        max-width: 100%;
        margin: 0 auto;
        padding: 0 1rem;
        overflow-x: hidden;
    }

    .div {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        padding: 3rem;
        margin-bottom: 2rem;
        border: 1px solid rgba(255, 255, 255, 0.2);
        position: relative;
        overflow: hidden;
    }

    /* Message animations */
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

    .alert-message {
        animation: slideInRight 0.3s ease-out;
    }

    .div::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    }

    .form-label {
        font-weight: 600;
        color: var(--dark-color);
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
        letter-spacing: 0.025em;
    }

    .form-control {
        border-radius: 0.75rem !important;
        border: 2px solid #e2e8f0 !important;
        background: #fff !important;
        font-size: 1rem;
        padding: 0.875rem 1.25rem !important;
        transition: var(--transition);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        font-weight: 500;
    }

    .form-control:focus {
        border-color: var(--primary-color) !important;
        box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.15) !important;
        background: #fff !important;
        transform: translateY(-2px);
    }

    .form-control:read-only {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%) !important;
        border-color: #dee2e6 !important;
        cursor: not-allowed;
    }

    .btn {
        font-weight: 600;
        letter-spacing: 0.05em;
        border-radius: 0.75rem;
        transition: var(--transition);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        border: none;
        padding: 0.75rem 1.5rem;
        font-size: 0.95rem;
        position: relative;
        overflow: hidden;
    }

    .btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s;
    }

    .btn:hover::before {
        left: 100%;
    }

    .btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
    }

    .btn-outline-primary {
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
        background: transparent;
    }

    .btn-outline-primary:hover {
        background: var(--primary-color);
        color: white;
    }

    .btn-outline-info {
        border: 2px solid var(--info-color);
        color: var(--info-color);
        background: transparent;
    }

    .btn-outline-info:hover {
        background: var(--info-color);
        color: white;
    }

    .btn-outline-danger {
        border: 2px solid var(--danger-color);
        color: var(--danger-color);
        background: transparent;
    }

    .btn-outline-danger:hover {
        background: var(--danger-color);
        color: white;
    }

    .submit {
        background: linear-gradient(135deg, var(--success-color), #20c997);
        color: white;
        padding: 1rem 2rem;
        font-size: 1.1rem;
        border-radius: 0.75rem;
        border: none;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        transition: var(--transition);
        width: 100%;
        margin-top: 2rem;
    }

    .submit:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(40, 167, 69, 0.4);
    }

    .upload-section {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
        border: 2px solid rgba(102, 126, 234, 0.1);
        border-radius: var(--border-radius);
        padding: 2rem;
        margin-bottom: 2rem;
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
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    }

    .upload-section h5 {
        color: var(--primary-color);
        font-weight: 700;
        font-size: 1.2rem;
        margin-bottom: 1rem;
    }

    .upload-status {
        margin-top: 1rem;
        font-size: 0.9rem;
        font-weight: 500;
    }

    .upload-status .text-success {
        color: var(--success-color) !important;
        font-weight: 600;
    }

    .upload-status .text-danger {
        color: var(--danger-color) !important;
        font-weight: 600;
    }

    .upload-status .text-info {
        color: var(--info-color) !important;
        font-weight: 600;
    }

    .document-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        justify-content: center;
    }

    .document-actions .btn {
        font-size: 0.8rem;
        padding: 0.5rem 1rem;
        min-width: auto;
        border-radius: 0.5rem;
    }

    .alert {
        border-radius: var(--border-radius);
        border: none;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .alert-info {
        background: linear-gradient(135deg, rgba(23, 162, 184, 0.1) 0%, rgba(23, 162, 184, 0.05) 100%);
        border-left: 4px solid var(--info-color);
        color: var(--dark-color);
    }

    .alert-danger {
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
        border-left: 4px solid var(--danger-color);
        color: var(--dark-color);
    }

    .table {
        background: rgba(255, 255, 255, 0.9);
        border-radius: var(--border-radius);
        overflow: hidden;
        box-shadow: var(--box-shadow);
    }

    .table thead th {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        font-weight: 600;
        border: none;
        padding: 1rem;
        text-align: center;
        font-size: 0.9rem;
        letter-spacing: 0.025em;
    }

    .table tbody td {
        padding: 1rem;
        border-color: rgba(0, 0, 0, 0.05);
        vertical-align: middle;
        text-align: center;
    }

    .table tbody tr:hover {
        background: rgba(102, 126, 234, 0.05);
    }

    .btn-sm {
        font-size: 0.8rem;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
    }

    .form-text {
        color: #6c757d;
        font-size: 0.85rem;
        margin-top: 0.5rem;
        font-weight: 500;
    }

    .fs-4 {
        font-size: 2rem !important;
        font-weight: 700;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 2rem;
    }

    .mb-3 {
        margin-bottom: 1.5rem !important;
    }

    .mb-4 {
        margin-bottom: 2rem !important;
    }

    .my-5 {
        margin: 3rem 0 !important;
    }

    /* Shake animation for validation errors */
    @keyframes shake {

        0%,
        100% {
            transform: translateX(0);
        }

        10%,
        30%,
        50%,
        70%,
        90% {
            transform: translateX(-5px);
        }

        20%,
        40%,
        60%,
        80% {
            transform: translateX(5px);
        }
    }

    /* Loading animation */
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Success animation */
    @keyframes successPulse {
        0% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.05);
        }

        100% {
            transform: scale(1);
        }
    }

    .success-animation {
        animation: successPulse 0.6s ease-in-out;
    }

    @media (max-width: 768px) {
        .div {
            padding: 1.5rem;
            margin: 1rem;
        }

        .container-fluid {
            padding: 0 0.5rem;
        }

        .table {
            font-size: 0.85rem;
        }

        .table thead th,
        .table tbody td {
            padding: 0.75rem 0.5rem;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Country-wise student management
    let countryEntries = [];
    let countryCounter = 0;

    // Function to check if country section should be shown
    function checkCountrySectionVisibility() {
        const maleOutsideInput = document.querySelector('input[name="Male_Students_outside_country"]');
        const femaleOutsideInput = document.querySelector('input[name="Female_Students_outside_country"]');
        const countrySection = document.getElementById('country_section');

        if (!maleOutsideInput || !femaleOutsideInput || !countrySection) {
            return; // Elements not found, skip
        }

        const maleOutside = parseInt(maleOutsideInput.value) || 0;
        const femaleOutside = parseInt(femaleOutsideInput.value) || 0;

        if (maleOutside > 0 || femaleOutside > 0) {
            countrySection.style.display = 'block';

            // Check if form is locked
            const updateBtn = document.getElementById('updateBtn');
            const isFormLocked = updateBtn && updateBtn.style.display !== 'none';

            // Enable/disable country section based on form state
            const countryInputs = countrySection.querySelectorAll('input, select, button');
            countryInputs.forEach(input => {
                if (isFormLocked) {
                    // In lock mode: disable inputs but keep view buttons
                    if (input.type === 'button' && input.textContent.includes('Delete')) {
                        input.style.display = 'none'; // Hide delete buttons
                    } else if (input.type === 'button' && input.textContent.includes('Add')) {
                        input.style.display = 'none'; // Hide add buttons
                    } else if (input.type === 'input' || input.type === 'select') {
                        input.disabled = true;
                        input.style.backgroundColor = '#f8f9fa';
                    }
                } else {
                    // In unlock/edit mode: enable inputs
                    input.disabled = false;
                    input.style.display = 'inline-block';
                    input.style.backgroundColor = '#fff';
                }
            });

            // Ensure at least one country row exists
            const countryTableBody = document.querySelector('#countryTable tbody');
            if (countryTableBody && countryTableBody.children.length === 0) {
                addCountryRow();
            }
        } else {
            countrySection.style.display = 'none';
            // Don't clear country entries when hiding - keep them for when section is shown again
        }
    }

    // Add event listeners to outside country inputs
    document.addEventListener('DOMContentLoaded', function() {
        const maleOutsideInput = document.querySelector('input[name="Male_Students_outside_country"]');
        const femaleOutsideInput = document.querySelector('input[name="Female_Students_outside_country"]');

        if (maleOutsideInput) {
            maleOutsideInput.addEventListener('input', checkCountrySectionVisibility);
            maleOutsideInput.addEventListener('change', checkCountrySectionVisibility);
        }
        if (femaleOutsideInput) {
            femaleOutsideInput.addEventListener('input', checkCountrySectionVisibility);
            femaleOutsideInput.addEventListener('change', checkCountrySectionVisibility);
        }

        // Initial check
        checkCountrySectionVisibility();
    });

    // Country options (excluding India) - FIXED: Use numeric country codes
    const countryOptions = `
        <option data-countryCode="GB" value="210">UK (+44)</option>  
        <option data-countryCode="US" value="211">USA (+1)</option>  
        <option data-countryCode="NO" value="147" selected>Norway (+47)</option>
        <optgroup label="Other countries">
            <option data-countryCode="AD" value="5">Andorra (+376)</option>
            <option data-countryCode="DZ" value="2">Algeria (+213)</option>
            <option data-countryCode="AI" value="1264">Anguilla (+1264)</option>
            <option data-countryCode="AO" value="244">Angola (+244)</option>
            <option data-countryCode="AG" value="1268">Antigua & Barbuda (+1268)</option>
            <option data-countryCode="AR" value="54">Argentina (+54)</option>
            <option data-countryCode="AM" value="374">Armenia (+374)</option>
            <option data-countryCode="AW" value="297">Aruba (+297)</option>
            <option data-countryCode="AU" value="61">Australia (+61)</option>
            <option data-countryCode="AT" value="43">Austria (+43)</option>
            <option data-countryCode="AZ" value="994">Azerbaijan (+994)</option>
            <option data-countryCode="BS" value="1242">Bahamas (+1242)</option>
            <option data-countryCode="BH" value="973">Bahrain (+973)</option>
            <option data-countryCode="BD" value="880">Bangladesh (+880)</option>
            <option data-countryCode="BB" value="1246">Barbados (+1246)</option>
            <option data-countryCode="BY" value="375">Belarus (+375)</option>
            <option data-countryCode="BE" value="32">Belgium (+32)</option>
            <option data-countryCode="BZ" value="501">Belize (+501)</option>
            <option data-countryCode="BJ" value="229">Benin (+229)</option>
            <option data-countryCode="BM" value="1441">Bermuda (+1441)</option>
            <option data-countryCode="BT" value="975">Bhutan (+975)</option>
            <option data-countryCode="BO" value="591">Bolivia (+591)</option>
            <option data-countryCode="BA" value="387">Bosnia Herzegovina (+387)</option>
            <option data-countryCode="BW" value="267">Botswana (+267)</option>
            <option data-countryCode="BR" value="55">Brazil (+55)</option>
            <option data-countryCode="BN" value="673">Brunei (+673)</option>
            <option data-countryCode="BG" value="359">Bulgaria (+359)</option>
            <option data-countryCode="BF" value="226">Burkina Faso (+226)</option>
            <option data-countryCode="BI" value="257">Burundi (+257)</option>
            <option data-countryCode="KH" value="855">Cambodia (+855)</option>
            <option data-countryCode="CM" value="237">Cameroon (+237)</option>
            <option data-countryCode="CA" value="1">Canada (+1)</option>
            <option data-countryCode="CV" value="238">Cape Verde Islands (+238)</option>
            <option data-countryCode="KY" value="1345">Cayman Islands (+1345)</option>
            <option data-countryCode="CF" value="236">Central African Republic (+236)</option>
            <option data-countryCode="CL" value="56">Chile (+56)</option>
            <option data-countryCode="CN" value="86">China (+86)</option>
            <option data-countryCode="CO" value="57">Colombia (+57)</option>
            <option data-countryCode="KM" value="269">Comoros (+269)</option>
            <option data-countryCode="CG" value="242">Congo (+242)</option>
            <option data-countryCode="CK" value="682">Cook Islands (+682)</option>
            <option data-countryCode="CR" value="506">Costa Rica (+506)</option>
            <option data-countryCode="HR" value="385">Croatia (+385)</option>
            <option data-countryCode="CU" value="53">Cuba (+53)</option>
            <option data-countryCode="CY" value="90392">Cyprus North (+90392)</option>
            <option data-countryCode="CY" value="357">Cyprus South (+357)</option>
            <option data-countryCode="CZ" value="42">Czech Republic (+42)</option>
            <option data-countryCode="DK" value="45">Denmark (+45)</option>
            <option data-countryCode="DJ" value="253">Djibouti (+253)</option>
            <option data-countryCode="DM" value="1809">Dominica (+1809)</option>
            <option data-countryCode="DO" value="1809">Dominican Republic (+1809)</option>
            <option data-countryCode="EC" value="593">Ecuador (+593)</option>
            <option data-countryCode="EG" value="20">Egypt (+20)</option>
            <option data-countryCode="SV" value="503">El Salvador (+503)</option>
            <option data-countryCode="GQ" value="240">Equatorial Guinea (+240)</option>
            <option data-countryCode="ER" value="291">Eritrea (+291)</option>
            <option data-countryCode="EE" value="372">Estonia (+372)</option>
            <option data-countryCode="ET" value="251">Ethiopia (+251)</option>
            <option data-countryCode="FK" value="500">Falkland Islands (+500)</option>
            <option data-countryCode="FO" value="298">Faroe Islands (+298)</option>
            <option data-countryCode="FJ" value="679">Fiji (+679)</option>
            <option data-countryCode="FI" value="358">Finland (+358)</option>
            <option data-countryCode="FR" value="33">France (+33)</option>
            <option data-countryCode="GF" value="594">French Guiana (+594)</option>
            <option data-countryCode="PF" value="689">French Polynesia (+689)</option>
            <option data-countryCode="GA" value="241">Gabon (+241)</option>
            <option data-countryCode="GM" value="220">Gambia (+220)</option>
            <option data-countryCode="GE" value="7880">Georgia (+7880)</option>
            <option data-countryCode="DE" value="49">Germany (+49)</option>
            <option data-countryCode="GH" value="233">Ghana (+233)</option>
            <option data-countryCode="GI" value="350">Gibraltar (+350)</option>
            <option data-countryCode="GR" value="30">Greece (+30)</option>
            <option data-countryCode="GL" value="299">Greenland (+299)</option>
            <option data-countryCode="GD" value="1473">Grenada (+1473)</option>
            <option data-countryCode="GP" value="590">Guadeloupe (+590)</option>
            <option data-countryCode="GU" value="671">Guam (+671)</option>
            <option data-countryCode="GT" value="502">Guatemala (+502)</option>
            <option data-countryCode="GN" value="224">Guinea (+224)</option>
            <option data-countryCode="GW" value="245">Guinea - Bissau (+245)</option>
            <option data-countryCode="GY" value="592">Guyana (+592)</option>
            <option data-countryCode="HT" value="509">Haiti (+509)</option>
            <option data-countryCode="HN" value="504">Honduras (+504)</option>
            <option data-countryCode="HK" value="852">Hong Kong (+852)</option>
            <option data-countryCode="HU" value="36">Hungary (+36)</option>
            <option data-countryCode="IS" value="354">Iceland (+354)</option>
            <option data-countryCode="ID" value="62">Indonesia (+62)</option>
            <option data-countryCode="IR" value="98">Iran (+98)</option>
            <option data-countryCode="IQ" value="964">Iraq (+964)</option>
            <option data-countryCode="IE" value="353">Ireland (+353)</option>
            <option data-countryCode="IL" value="972">Israel (+972)</option>
            <option data-countryCode="IT" value="39">Italy (+39)</option>
            <option data-countryCode="JM" value="1876">Jamaica (+1876)</option>
            <option data-countryCode="JP" value="81">Japan (+81)</option>
            <option data-countryCode="JO" value="962">Jordan (+962)</option>
            <option data-countryCode="KZ" value="7">Kazakhstan (+7)</option>
            <option data-countryCode="KE" value="254">Kenya (+254)</option>
            <option data-countryCode="KI" value="686">Kiribati (+686)</option>
            <option data-countryCode="KP" value="850">Korea North (+850)</option>
            <option data-countryCode="KR" value="82">Korea South (+82)</option>
            <option data-countryCode="KW" value="965">Kuwait (+965)</option>
            <option data-countryCode="KG" value="996">Kyrgyzstan (+996)</option>
            <option data-countryCode="LA" value="856">Laos (+856)</option>
            <option data-countryCode="LV" value="371">Latvia (+371)</option>
            <option data-countryCode="LB" value="961">Lebanon (+961)</option>
            <option data-countryCode="LS" value="266">Lesotho (+266)</option>
            <option data-countryCode="LR" value="231">Liberia (+231)</option>
            <option data-countryCode="LY" value="218">Libya (+218)</option>
            <option data-countryCode="LI" value="417">Liechtenstein (+417)</option>
            <option data-countryCode="LT" value="370">Lithuania (+370)</option>
            <option data-countryCode="LU" value="352">Luxembourg (+352)</option>
            <option data-countryCode="MO" value="853">Macao (+853)</option>
            <option data-countryCode="MK" value="389">Macedonia (+389)</option>
            <option data-countryCode="MG" value="261">Madagascar (+261)</option>
            <option data-countryCode="MW" value="265">Malawi (+265)</option>
            <option data-countryCode="MY" value="60">Malaysia (+60)</option>
            <option data-countryCode="MV" value="960">Maldives (+960)</option>
            <option data-countryCode="ML" value="223">Mali (+223)</option>
            <option data-countryCode="MT" value="356">Malta (+356)</option>
            <option data-countryCode="MH" value="692">Marshall Islands (+692)</option>
            <option data-countryCode="MQ" value="596">Martinique (+596)</option>
            <option data-countryCode="MR" value="222">Mauritania (+222)</option>
            <option data-countryCode="YT" value="269">Mayotte (+269)</option>
            <option data-countryCode="MX" value="52">Mexico (+52)</option>
            <option data-countryCode="FM" value="691">Micronesia (+691)</option>
            <option data-countryCode="MD" value="373">Moldova (+373)</option>
            <option data-countryCode="MC" value="377">Monaco (+377)</option>
            <option data-countryCode="MN" value="976">Mongolia (+976)</option>
            <option data-countryCode="MS" value="1664">Montserrat (+1664)</option>
            <option data-countryCode="MA" value="212">Morocco (+212)</option>
            <option data-countryCode="MZ" value="258">Mozambique (+258)</option>
            <option data-countryCode="MN" value="95">Myanmar (+95)</option>
            <option data-countryCode="NA" value="264">Namibia (+264)</option>
            <option data-countryCode="NR" value="674">Nauru (+674)</option>
            <option data-countryCode="NP" value="977">Nepal (+977)</option>
            <option data-countryCode="NL" value="31">Netherlands (+31)</option>
            <option data-countryCode="NC" value="687">New Caledonia (+687)</option>
            <option data-countryCode="NZ" value="64">New Zealand (+64)</option>
            <option data-countryCode="NI" value="505">Nicaragua (+505)</option>
            <option data-countryCode="NE" value="227">Niger (+227)</option>
            <option data-countryCode="NG" value="234">Nigeria (+234)</option>
            <option data-countryCode="NU" value="683">Niue (+683)</option>
            <option data-countryCode="NF" value="672">Norfolk Islands (+672)</option>
            <option data-countryCode="NP" value="670">Northern Marianas (+670)</option>
            <option data-countryCode="NO" value="47">Norway (+47)</option>
            <option data-countryCode="OM" value="968">Oman (+968)</option>
            <option data-countryCode="PW" value="680">Palau (+680)</option>
            <option data-countryCode="PA" value="507">Panama (+507)</option>
            <option data-countryCode="PG" value="675">Papua New Guinea (+675)</option>
            <option data-countryCode="PY" value="595">Paraguay (+595)</option>
            <option data-countryCode="PE" value="51">Peru (+51)</option>
            <option data-countryCode="PH" value="63">Philippines (+63)</option>
            <option data-countryCode="PL" value="48">Poland (+48)</option>
            <option data-countryCode="PT" value="351">Portugal (+351)</option>
            <option data-countryCode="PR" value="1787">Puerto Rico (+1787)</option>
            <option data-countryCode="QA" value="974">Qatar (+974)</option>
            <option data-countryCode="RE" value="262">Reunion (+262)</option>
            <option data-countryCode="RO" value="40">Romania (+40)</option>
            <option data-countryCode="RU" value="7">Russia (+7)</option>
            <option data-countryCode="RW" value="250">Rwanda (+250)</option>
            <option data-countryCode="SM" value="378">San Marino (+378)</option>
            <option data-countryCode="ST" value="239">Sao Tome & Principe (+239)</option>
            <option data-countryCode="SA" value="966">Saudi Arabia (+966)</option>
            <option data-countryCode="SN" value="221">Senegal (+221)</option>
            <option data-countryCode="CS" value="381">Serbia (+381)</option>
            <option data-countryCode="SC" value="248">Seychelles (+248)</option>
            <option data-countryCode="SL" value="232">Sierra Leone (+232)</option>
            <option data-countryCode="SG" value="65">Singapore (+65)</option>
            <option data-countryCode="SK" value="421">Slovak Republic (+421)</option>
            <option data-countryCode="SI" value="386">Slovenia (+386)</option>
            <option data-countryCode="SB" value="677">Solomon Islands (+677)</option>
            <option data-countryCode="SO" value="252">Somalia (+252)</option>
            <option data-countryCode="ZA" value="27">South Africa (+27)</option>
            <option data-countryCode="ES" value="34">Spain (+34)</option>
            <option data-countryCode="LK" value="94">Sri Lanka (+94)</option>
            <option data-countryCode="SH" value="290">St. Helena (+290)</option>
            <option data-countryCode="KN" value="1869">St. Kitts (+1869)</option>
            <option data-countryCode="SC" value="1758">St. Lucia (+1758)</option>
            <option data-countryCode="SD" value="249">Sudan (+249)</option>
            <option data-countryCode="SR" value="597">Suriname (+597)</option>
            <option data-countryCode="SZ" value="268">Swaziland (+268)</option>
            <option data-countryCode="SE" value="46">Sweden (+46)</option>
            <option data-countryCode="CH" value="41">Switzerland (+41)</option>
            <option data-countryCode="SI" value="963">Syria (+963)</option>
            <option data-countryCode="TW" value="886">Taiwan (+886)</option>
            <option data-countryCode="TJ" value="7">Tajikstan (+7)</option>
            <option data-countryCode="TH" value="66">Thailand (+66)</option>
            <option data-countryCode="TG" value="228">Togo (+228)</option>
            <option data-countryCode="TO" value="676">Tonga (+676)</option>
            <option data-countryCode="TT" value="1868">Trinidad & Tobago (+1868)</option>
            <option data-countryCode="TN" value="216">Tunisia (+216)</option>
            <option data-countryCode="TR" value="90">Turkey (+90)</option>
            <option data-countryCode="TM" value="7">Turkmenistan (+7)</option>
            <option data-countryCode="TM" value="993">Turkmenistan (+993)</option>
            <option data-countryCode="TC" value="1649">Turks & Caicos Islands (+1649)</option>
            <option data-countryCode="TV" value="688">Tuvalu (+688)</option>
            <option data-countryCode="UG" value="256">Uganda (+256)</option>
            <option data-countryCode="GB" value="44">UK (+44)</option>
            <option data-countryCode="UA" value="380">Ukraine (+380)</option>
            <option data-countryCode="AE" value="971">United Arab Emirates (+971)</option>
            <option data-countryCode="UY" value="598">Uruguay (+598)</option>
            <option data-countryCode="US" value="1">USA (+1)</option>
            <option data-countryCode="UZ" value="7">Uzbekistan (+7)</option>
            <option data-countryCode="VU" value="678">Vanuatu (+678)</option>
            <option data-countryCode="VA" value="379">Vatican City (+379)</option>
            <option data-countryCode="VE" value="58">Venezuela (+58)</option>
            <option data-countryCode="VN" value="84">Vietnam (+84)</option>
            <option data-countryCode="VG" value="84">Virgin Islands - British (+1284)</option>
            <option data-countryCode="VI" value="84">Virgin Islands - US (+1340)</option>
            <option data-countryCode="WF" value="681">Wallis & Futuna (+681)</option>
            <option data-countryCode="YE" value="969">Yemen (North)(+969)</option>
            <option data-countryCode="YE" value="967">Yemen (South)(+967)</option>
            <option data-countryCode="ZM" value="260">Zambia (+260)</option>
            <option data-countryCode="ZW" value="263">Zimbabwe (+263)</option>
        </optgroup>
    `;

    function addCountryEntry() {
        countryCounter++;
        const container = document.getElementById('country_entries_container');

        const countryDiv = document.createElement('div');
        countryDiv.className = 'country-entry';
        countryDiv.id = `country_entry_${countryCounter}`;

        countryDiv.innerHTML = `
            <h6>Country Entry ${countryCounter}</h6>
            <button type="button" class="btn btn-danger btn-sm remove-country-btn" onclick="removeCountryEntry(${countryCounter})">
                <i class="fas fa-times"></i>
            </button>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label class="form-label">Select Country</label>
                    <select class="form-control" name="country_codes[]" id="country_code_${countryCounter}">
                        ${countryOptions}
                    </select>
                </div>
                <div class="col-md-6 form-group">
                    <label class="form-label">Number of Students</label>
                    <input type="number" class="form-control" name="country_students[]" id="country_students_${countryCounter}" 
                           placeholder="Enter number of students" min="0" value="0">
                </div>
            </div>
        `;

        container.appendChild(countryDiv);
    }

    function removeCountryEntry(entryId) {
        const entry = document.getElementById(`country_entry_${entryId}`);
        if (entry) {
            entry.remove();
        }
    }


    function showSuccessMessage(message) {
        // Remove any existing success messages
        const existingAlerts = document.querySelectorAll('.alert-success.position-fixed');
        existingAlerts.forEach(alert => alert.remove());

        // Create simple success message
        const successDiv = document.createElement('div');
        successDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
        successDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 250px;';
        successDiv.innerHTML = `
            <i class="fas fa-check-circle me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        // Add to page
        document.body.appendChild(successDiv);

        // Auto remove after 2 seconds
        setTimeout(() => {
            if (successDiv.parentNode) {
                successDiv.remove();
            }
        }, 2000);
    }

    // Initialize tooltips and form state
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Bootstrap tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Initialize country table with one empty row
        initializeCountryTable();

        // Initialize form state
        initializeFormState();

        // Add event listeners to all number inputs
        const numberInputs = document.querySelectorAll('input[type="number"]');
        numberInputs.forEach(input => {
            input.addEventListener('input', calculateTotalStudents);
        });

        // Initial check for country section visibility
        checkCountrySectionVisibility();
    });

    // Clear all data function
    function clearAllData(recordId) {
        if (confirm('⚠️ Are you sure you want to clear ALL intake data for this program? This action cannot be undone and will delete all associated country-wise data!')) {
            // Send AJAX request to delete the record
            const formData = new FormData();
            formData.append('action', 'delete_record');
            formData.append('record_id', recordId);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        // Redirect to new form
                        setTimeout(() => {
                            window.location.href = 'IntakeActualStrength.php';
                        }, 1000);
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error clearing data: ' + error.message, 'error');
                });
        }
    }

    // Enable update function - unlock form for editing
    function enableUpdate() {
        // CRITICAL: Ensure program reference fields are ALWAYS disabled (never editable)
        ensureProgramFieldsReadonly();
        
        // CRITICAL: Enable all form inputs except program select and program reference fields
        // Must clear BOTH readonly attribute AND readOnly property to ensure fields submit values
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            // CRITICAL: NEVER enable program reference fields - they must always be disabled
            if (input.id !== 'program_select' &&
                input.id !== 'program_name_display' &&
                input.id !== 'program_code_display' &&
                input.id !== 'intake_capacity') {
                // Remove readonly attribute AND property
                input.removeAttribute('readonly');
                input.readOnly = false;
                // Remove disabled attribute AND property
                input.disabled = false;
                input.removeAttribute('disabled');
                input.style.pointerEvents = 'auto';
                input.style.cursor = 'text';
                input.style.backgroundColor = '#fff';
            }
        });
        
        // Double-check: Ensure program fields stay disabled
        ensureProgramFieldsReadonly();

        // Keep program select always enabled and selectable
        const programSelect = document.getElementById('program_select');
        if (programSelect) {
            programSelect.disabled = false;
            programSelect.style.backgroundColor = '#fff';
            programSelect.style.cursor = 'pointer';
        }

        // Show Save and Cancel Update buttons, hide Update and Clear Data buttons
        document.getElementById('saveBtn').style.display = 'inline-block';
        document.getElementById('cancelUpdateBtn').style.display = 'inline-block';
        document.getElementById('updateBtn').style.display = 'none';
        document.getElementById('clearBtn').style.display = 'none';

        // Unlock country section - enable Add Country button and country inputs
        const addCountryBtn = document.querySelector('button[onclick="addCountryRow()"]');
        if (addCountryBtn) {
            addCountryBtn.disabled = false;
            addCountryBtn.style.opacity = '1';
            addCountryBtn.style.cursor = 'pointer';
        }

        // Enable all country table inputs
        const countryInputs = document.querySelectorAll('#countryTable input, #countryTable select');
        countryInputs.forEach(input => {
            input.disabled = false;
            input.style.opacity = '1';
            input.style.cursor = 'text';
            input.style.backgroundColor = '#fff';
        });

        // Refresh all document statuses to show delete buttons now that form is unlocked
        setTimeout(() => {
            loadExistingDocuments();
        }, 100);

        showMessage('Form is now editable. Delete buttons are now available for documents.', 'info');

        // Ensure country section is visible if there are outside country students
        const maleOutsideInput = document.querySelector('input[name="Male_Students_outside_country"]');
        const femaleOutsideInput = document.querySelector('input[name="Female_Students_outside_country"]');
        const countrySection = document.getElementById('country_section');

        if (maleOutsideInput && femaleOutsideInput && countrySection) {
            const maleOutside = parseInt(maleOutsideInput.value) || 0;
            const femaleOutside = parseInt(femaleOutsideInput.value) || 0;

            if (maleOutside > 0 || femaleOutside > 0) {
                countrySection.style.display = 'block';
                // Ensure at least one country row exists
                const countryTableBody = document.querySelector('#countryTable tbody');
                if (countryTableBody && countryTableBody.children.length === 0) {
                    addCountryRow();
                }
            }
        }

        showNotification('Form is now editable. Make your changes and click "Save Changes" to update.', 'info');
    }

    // Cancel update function - reload original data and lock form
    function cancelUpdate() {
        const recordId = document.getElementById('record_id')?.value;
        if (!recordId) {
            showNotification('No record to cancel', 'warning');
            return;
        }

        // Reload the record data to restore original values
        updateRecordFromTable(recordId);
        
        // Lock the form again
        lockForm();
        
        showNotification('Update cancelled. Form is locked again.', 'info');
    }

    // Clear data function
    function clearData() {
        if (confirm('Are you sure you want to clear all data for academic year <?php echo $A_YEAR; ?>? This action cannot be undone!')) {
            const recordId = document.getElementById('record_id').value;
            if (recordId) {
                // Use AJAX to delete the record
                fetch('IntakeActualStrength.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=delete_record&record_id=' + recordId
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            setTimeout(() => {
                                window.location.href = 'IntakeActualStrength.php';
                            }, 1000);
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        showNotification('Error clearing data: ' + error.message, 'error');
                    });
            } else {
                showNotification('No record to clear', 'warning');
            }
        }
    }
//    

// Upload document function with proper error handling
function uploadDocument(fileId, srno) {
    const fileInput = document.getElementById(fileId);
    const statusDiv = document.getElementById(fileId + '_status');

    // Validation #1: Check if file is selected
    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
        showNotification('Please select a file to upload.', 'error');
        return;
    }

    // Validation #2: Check PDF type
    if (!validatePDF(fileInput)) {
        return;
    }

    // Validation #3: Check program selection
    const programSelect = document.getElementById('program_select');
    if (!programSelect || !programSelect.value) {
        showNotification('Please select a program first.', 'error');
        return;
    }

    // Validation #4: Check file size (5MB limit)
    const file = fileInput.files[0];
    const maxSize = 5 * 1024 * 1024; // 5MB
    if (file.size > maxSize) {
        const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
        showNotification(`File too large (${sizeMB}MB). Maximum size is 5MB. Please compress the PDF.`, 'error');
        fileInput.value = '';
        return;
    }

    // Get CSRF token (required for common_upload_handler.php)
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                      document.querySelector('input[name="csrf_token"]')?.value || '';
    if (!csrfToken) {
        showNotification('Security token not found. Please refresh the page and try again.', 'error');
        return;
    }

    // Prepare form data
    const formData = new FormData();
    formData.append('document', file);
    formData.append('upload_document', '1');
    formData.append('file_id', programSelect.value); // programme_code
    formData.append('srno', srno);
    formData.append('program_code', programSelect.value); // CRITICAL: Separate documents by program
    formData.append('p_name', programSelect.value);
    formData.append('csrf_token', csrfToken); // CRITICAL: Include CSRF token for validation

    // Show loading state
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Uploading...';
    statusDiv.className = 'mt-2 text-info';

    // Disable upload button and file input during upload
    const uploadBtn = event ? event.target : null;
    if (uploadBtn) {
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
    }
    fileInput.disabled = true;

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
                console.warn('[Intake] Empty response received from upload');
                return { success: false, message: 'Upload failed: Empty server response' };
            }
            
            // Try to parse as JSON
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                console.error('[Intake] Failed to parse JSON response from upload:', e);
                console.error('[Intake] Response text:', trimmed.substring(0, 500));
                // CRITICAL #5: Return error response instead of throwing
                return { success: false, message: 'Upload failed: Invalid server response. Please refresh and try again.' };
            }
        });
    })
    .then(data => {
        // Re-enable controls
        if (uploadBtn) {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = 'Upload';
        }
        fileInput.disabled = false;
        
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            console.error('[Intake] Invalid data structure received from upload:', data);
            statusDiv.innerHTML = '<div class="text-danger"><i class="fas fa-exclamation-circle"></i> Upload failed: Invalid server response</div>';
            statusDiv.className = 'mt-2 text-danger';
            showNotification('Upload failed: Invalid server response', 'error');
            return;
        }

        if (data.success) {
            // Check if form is locked by checking if any input field is disabled
            // This is more reliable than checking button visibility
            const testInput = document.querySelector('input[type="number"]:not([name="academic_year"]):not([name="dept_info"])');
            const isFormLocked = testInput ? testInput.disabled : false;
            
            // Also check if update button exists and is visible (additional check)
            const updateBtn = document.querySelector('button[onclick*="enableUpdate"]');
            const updateBtnVisible = updateBtn && updateBtn.style.display !== 'none';
            const isFormLockedFinal = isFormLocked || updateBtnVisible;

            // Get program_code for view/delete links
            const programCode = programSelect.value;

            // CRITICAL FIX: Use the file_path from response, ensure it includes program_code folder
            let viewPath = data.file_path || '';
            
            // Normalize path separators
            viewPath = viewPath.replace(/\\/g, '/');
            
            // Ensure path has ../ prefix for web access from dept_login directory
            if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http') && !viewPath.startsWith('/')) {
                // Check if path already includes program_code folder structure
                if (!viewPath.includes('/intake_actual_strength/' + programCode + '/')) {
                    // Path doesn't include program_code folder - use download_doc handler instead
                    viewPath = `?download_doc=1&file_id=${fileId}&srno=${srno}&program_code=${encodeURIComponent(programCode)}`;
                } else {
                    viewPath = '../' + viewPath;
                }
            } else if (viewPath && viewPath.startsWith('uploads/')) {
                viewPath = '../' + viewPath;
            } else if (!viewPath || viewPath === '') {
                // Fallback to download_doc handler
                viewPath = `?download_doc=1&file_id=${fileId}&srno=${srno}&program_code=${encodeURIComponent(programCode)}`;
            }

            // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
            const escapedViewPath4 = String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            var viewButton = `<a href="${escapedViewPath4}" target="_blank" class="btn btn-sm btn-outline-primary me-2">
                <i class="fas fa-eye"></i> View
            </a>`;

            // CRITICAL: Show delete button only when form is NOT locked (when Update has been clicked)
            // When form is locked: Only show View button
            // When Update is clicked: Show both View and Delete buttons
            const documentId = data.document_id || data.id;
            let deleteButton = '';
            if (!isFormLockedFinal) {
                if (documentId && documentId !== 'undefined' && documentId !== 'null' && documentId !== '' && !isNaN(parseInt(documentId, 10))) {
                    // Has valid documentId - use documentId pattern
                    // CRITICAL: Escape documentId to prevent JavaScript syntax errors
                    const escapedDocumentId = String(documentId).replace(/'/g, "\\'").replace(/"/g, '\\"');
                    deleteButton = `<button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteDocumentById('${escapedDocumentId}')">
                <i class="fas fa-trash"></i> Delete
            </button>`;
                } else {
                    // No documentId - use fileId/srno pattern (temporary document)
                    // CRITICAL: Escape fileId to prevent JavaScript syntax errors
                    const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
                    deleteButton = `<button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteDocumentByFileId('${escapedFileId}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
                }
            }

            var fileInfo = data.file_name ? `<small class="text-muted d-block">📄 ${data.file_name}</small>` : '';
            var fileSize = data.file_size ? `<small class="text-muted d-block">📏 Size: ${(data.file_size / (1024 * 1024)).toFixed(2)} MB</small>` : '';

            statusDiv.innerHTML = `
                <div class="d-flex align-items-center">
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
            statusDiv.className = 'mt-2 text-success';

            // Clear the file input
            fileInput.value = '';

            showNotification(data.message || 'Document uploaded successfully!', 'success');
        } else {
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">${data.message}</span>`;
            statusDiv.className = 'mt-2 text-danger';
            showNotification(data.message || 'Upload failed', 'error');
        }
    })
    .catch(error => {
        // Re-enable controls on error
        if (uploadBtn) {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = 'Upload';
        }
        fileInput.disabled = false;

        // CRITICAL #5: Handle errors gracefully - return object, don't throw
        console.error('[Intake] Upload error:', error);
        statusDiv.innerHTML = `<div class="text-danger"><i class="fas fa-exclamation-circle"></i> Upload failed: ${error.message || 'Unknown error'}</div>`;
        statusDiv.className = 'mt-2 text-danger';
        showNotification('Upload failed: ' + (error.message || 'Unknown error'), 'error');
    });
}

// Delete document function with proper validation
// CRITICAL: Rename to avoid conflict with deleteDocument(documentId) function
function deleteDocumentByFileId(fileId, srno) {
    // Check if form is locked
    const updateBtn = document.querySelector('button[onclick="enableUpdate()"]');
    var isFormLocked = updateBtn && updateBtn.style.display !== 'none';

    if (isFormLocked) {
        showNotification('Cannot delete document. Form is locked after submission.\n\nTo delete documents, please use the Update button to unlock the form first.', 'error');
        return;
    }

    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }

    // Get program_code from dropdown
    const programSelect = document.getElementById('program_select');
    const programCode = programSelect ? programSelect.value : '';

    if (!programCode) {
        showNotification('Please select a program first.', 'error');
        return;
    }

    const statusDiv = document.getElementById(fileId + '_status');
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
    statusDiv.className = 'mt-2 text-info';

    // Get CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                      document.querySelector('input[name="csrf_token"]')?.value || '';
    if (!csrfToken) {
        showNotification('Security token not found. Please refresh the page and try again.', 'error');
        return;
    }
    
    // Convert to POST request with CSRF token
    const formData = new FormData();
    formData.append('delete_doc', '1');
    formData.append('file_id', fileId);
    formData.append('srno', srno);
    formData.append('program_code', programCode);
    formData.append('csrf_token', csrfToken);
    
    fetch('', {
        method: 'POST',
        body: formData,
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        // CRITICAL #3: Handle empty responses in JS - return object, don't throw
        // Always try to parse as text first to handle empty/malformed responses gracefully
        return response.text().then(text => {
            const trimmed = text.trim();
            
            // Handle empty response gracefully
            if (!trimmed || trimmed === '') {
                console.warn('[Intake] Empty response from delete_doc for', fileId, srno);
                return { success: false, message: 'Delete failed: Empty server response' };
            }
            
            try {
                const json = JSON.parse(trimmed);
                return json;
            } catch (e) {
                console.error('[Intake] Failed to parse delete_doc JSON response:', e);
                console.error('[Intake] Response text:', trimmed.substring(0, 500));
                // CRITICAL #5: Return error response instead of throwing to prevent loops
                return { success: false, message: 'Invalid server response' };
            }
        });
    })
    .catch(error => {
        // CRITICAL #5: Remove active checks on errors to prevent loops
        console.error('[Intake] Network error deleting document:', error);
        // Return error response instead of throwing
        return { success: false, message: 'Network error: ' + error.message };
    })
    .then(data => {
        // CRITICAL: Handle null/undefined data gracefully
        if (!data) {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
            showNotification('Delete failed: No response from server', 'error');
            return;
        }
        
        if (data.success) {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
            showNotification(data.message || 'Document deleted successfully', 'success');
        } else {
            statusDiv.innerHTML = `<div class="text-danger">❌ ${data.message || 'Delete failed'}</div>`;
            statusDiv.className = 'mt-2 text-danger';
            showNotification(data.message || 'Delete failed', 'error');
        }
    });
}

// Track active check requests to prevent duplicate calls and unlimited database requests
const activeDocumentChecks = new Set();

// Check document status function with proper validation and guards
function checkDocumentStatus(fileId, srno) {
    const statusDiv = document.getElementById(fileId + '_status');
    if (!statusDiv) {
        return;
    }
    
    const programSelect = document.getElementById('program_select');
    const programId = programSelect ? programSelect.value : '';

    if (!programId) {
        statusDiv.innerHTML = '<span class="text-muted">No program selected</span>';
        statusDiv.className = 'mt-2 text-muted';
        return;
    }
    
    // CRITICAL: Prevent duplicate checks for the same document - prevent unlimited database requests
    const checkKey = `${fileId}_${srno}_${programId}`;
    if (activeDocumentChecks.has(checkKey)) {
        return; // Already checking this document
    }
    
    // Add to active checks
    activeDocumentChecks.add(checkKey);

    fetch(`?check_doc=1&file_id=${fileId}&srno=${srno}&program_code=${encodeURIComponent(programId)}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        },
        cache: 'no-cache'
    })
    .catch(error => {
        // CRITICAL: Handle fetch errors (network, CORS, etc.) - remove from active checks - prevent loops
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
    .then(response => {
        // CRITICAL: Ensure response is valid before processing
        if (!response || typeof response.text !== 'function') {
            activeDocumentChecks.delete(checkKey);
            return { success: false, message: 'Check failed: Invalid server response' };
        }
        
        // CRITICAL #3: Handle empty responses in JS - return object, don't throw
        return response.text().then(text => {
            const trimmed = text.trim();
            
            // Handle empty response gracefully
            if (!trimmed || trimmed === '') {
                activeDocumentChecks.delete(checkKey);
                console.warn('[Intake] Empty response from check_doc for', fileId, srno);
                return { success: false, message: 'No document found' };
            }
            
            try {
                const json = JSON.parse(trimmed);
                activeDocumentChecks.delete(checkKey);
                return json;
            } catch (e) {
                activeDocumentChecks.delete(checkKey);
                console.error('[Intake] Failed to parse check_doc JSON response:', e);
                console.error('[Intake] Response text:', trimmed.substring(0, 500));
                // CRITICAL #5: Return error response instead of throwing to prevent loops
                return { success: false, message: 'Invalid server response' };
            }
        }).catch(textError => {
            // CRITICAL: Handle text() parsing errors
            activeDocumentChecks.delete(checkKey);
            return { success: false, message: 'Check failed: Error reading server response' };
        });
    })
    .catch(error => {
        // CRITICAL: Remove from active checks on ANY error - prevent loops
        activeDocumentChecks.delete(checkKey);
        // Return resolved promise with error object - prevent unhandled promise rejection
        return Promise.resolve({ success: false, message: 'Network error: ' + (error.message || 'Unknown error') });
    })
    .then(data => {
        // CRITICAL: Handle null/undefined data gracefully
        if (!data) {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
            return;
        }
        
        if (data.success && data.file_path) {
            // Check if form is locked by checking if any input field is disabled
            // This is more reliable than checking button visibility
            const testInput = document.querySelector('input[type="number"]:not([name="academic_year"]):not([name="dept_info"])');
            const isFormLocked = testInput ? testInput.disabled : false;
            
            // Also check if update button exists and is visible (additional check)
            const updateBtn = document.querySelector('button[onclick*="enableUpdate"]');
            const updateBtnVisible = updateBtn && updateBtn.style.display !== 'none';
            const isFormLockedFinal = isFormLocked || updateBtnVisible;

            const programCode = programSelect.value;

            // Adjust file path for viewing - ensure it includes program_code folder
            let viewPath = data.file_path || '';
            
            // Normalize path separators
            viewPath = viewPath.replace(/\\/g, '/');
            
            // Ensure path has ../ prefix for web access from dept_login directory
            if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http') && !viewPath.startsWith('/')) {
                // Check if path already includes program_code folder structure
                if (!viewPath.includes('/intake_actual_strength/' + programCode + '/')) {
                    // Path doesn't include program_code folder - use download_doc handler instead
                    viewPath = `?download_doc=1&file_id=${fileId}&srno=${srno}&program_code=${encodeURIComponent(programCode)}`;
                } else {
                    viewPath = '../' + viewPath;
                }
            } else if (viewPath && viewPath.startsWith('uploads/')) {
                viewPath = '../' + viewPath;
            }

            // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
            const escapedViewPath5 = String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            var viewButton = `<a href="${escapedViewPath5}" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-eye"></i> View
            </a>`;

            // CRITICAL: Show delete button only when form is NOT locked (when Update has been clicked)
            // When form is locked: Only show View button
            // When Update is clicked: Show both View and Delete buttons
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLockedFinal ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocumentByFileId('${escapedFileId}', ${srno})">
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
                    ${viewButton}
                    ${deleteButton}
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
        } else {
            // Show message from server if available, otherwise default message
            const message = data.message || 'No document uploaded';
            statusDiv.innerHTML = `<span class="text-muted">${message}</span>`;
            statusDiv.className = 'mt-2 text-muted';
        }
    });
}

// Enhanced PDF validation function
function validatePDF(input) {
    const file = input.files[0];
    if (!file) {
        showMessage('No file selected', 'error');
        return false;
    }

    const fileSize = file.size / 1024 / 1024; // Convert to MB
    const fileType = file.type;
    const fileName = file.name;
    const fileExtension = fileName.split('.').pop().toLowerCase();
    const maxSizeMB = 5;

    // Check file extension
    if (fileExtension !== 'pdf') {
        showMessage(`❌ Invalid File Type! Please select a PDF file only. Selected: ${fileName} (.${fileExtension})`, 'error');
        input.value = '';
        return false;
    }

    // Check MIME type
    if (fileType !== 'application/pdf') {
        showMessage(`❌ Invalid File Type! MIME type must be 'application/pdf'. Detected: ${fileType}`, 'error');
        input.value = '';
        return false;
    }

    // Check file size
    if (fileSize > maxSizeMB) {
        showMessage(`❌ File Too Large! File size: ${fileSize.toFixed(2)} MB. Maximum allowed: ${maxSizeMB} MB. Please compress the PDF or select a smaller file.`, 'error');
        input.value = '';
        return false;
    }

    // File is valid
    return true;
}

// Load existing documents when program is selected
// CRITICAL PERFORMANCE FIX: Load ALL documents in ONE batch request instead of 4 individual requests
// This prevents unlimited database requests and server crashes
let documentsLoading = false;
let documentsLoadAttempted = false;

function loadExistingDocuments() {
    // CRITICAL: Guard against duplicate calls
    if (documentsLoading || documentsLoadAttempted) {
        return; 
    }
    
    const programSelect = document.getElementById('program_select');
    if (!programSelect || !programSelect.value) {
        // No program selected - clear all document statuses
        clearAllDocumentStatuses();
        return;
    }
    
    documentsLoading = true;
    documentsLoadAttempted = true;
    
    const programCode = programSelect.value;
    const documentTypes = [
        { fileId: 'total_intake_proof_file', srno: 1 },
        { fileId: 'regional_diversity_file', srno: 2 },
        { fileId: 'escs_diversity_file', srno: 3 },
        { fileId: 'scholarship_freeship_file', srno: 4 }
    ];
    
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
    
    // CRITICAL: Use consolidated endpoint with rate limiting - ONE request instead of 4
    executeWithRateLimit(() => {
        return fetch(`?check_all_docs=1&program_code=${encodeURIComponent(programCode)}`, {
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
        const programSelect = document.getElementById('program_select');
        const programCode = programSelect ? programSelect.value : '';
        // CRITICAL: Escape fileId and programCode to prevent JavaScript syntax errors
        const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
        const escapedProgramCode = String(programCode).replace(/'/g, "\\'").replace(/"/g, '\\"');
        actionButtons = `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno}, '${escapedProgramCode}')">
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
    const escapedViewPath6 = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
    const viewButton = escapedViewPath6 ? `<a href="${escapedViewPath6}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
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

// Clear all document status displays
function clearAllDocumentStatuses() {
    const documentIds = [
        'total_intake_proof_file', 
        'regional_diversity_file', 
        'escs_diversity_file', 
        'scholarship_freeship_file'
    ];
    
    documentIds.forEach(fileId => {
        const statusDiv = document.getElementById(fileId + '_status');
        if (statusDiv) {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
        }
    });
}
    // ***************************************
    


    // Confirm clear data function like ExecutiveDevelopment.php
    function confirmClearData() {
        if (confirm('Are you sure you want to clear all data for academic year <?php echo $A_YEAR; ?>? This action cannot be undone!')) {
            return true;
        }
        return false;
    }

    // REMOVED: Complex validateForm function - validation is now handled in handleFormSubmission
    // Server-side validation will catch any issues

    // Check program data and determine form state
    function checkProgramData() {
        if (isCheckingProgram) {
            return;
        }

        isCheckingProgram = true;
        const programSelect = document.getElementById('program_select');
        const programCode = programSelect.value;

        if (!programCode) {
            // No program selected - reset form and show message
            resetFormToNewState();
            showMessage('Please select a program to enter data', 'info');
            updateDataSummarySection();
            isCheckingProgram = false; // Reset flag
            return;
        }

        // Update program details first
        updateProgramDetails();

        // Check if data exists for this program (CSRF temporarily disabled)
        // const csrfTokenInput = document.querySelector('input[name="csrf_token"]');
        // const csrfToken = csrfTokenInput ? csrfTokenInput.value : '';

        // if (!csrfToken) {
        //     console.error('CSRF token not found in form');
        //     showMessage('CSRF token not found. Please refresh the page.', 'error');
        //     isCheckingProgram = false;
        //     return;
        // }

        fetch(`IntakeActualStrength.php?action=check_program_data&program_code=${programCode}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Response is not JSON. Content-Type: ' + contentType);
                }

                return response.json();
            })
            .then(data => {
                // Check if there's an error in the response
                if (data.error) {
                    throw new Error(data.error);
                }


                if (data.exists && data.record) {
                    // Data exists - load existing data and lock form
                    loadExistingData(data.record);

                    // Ensure lockForm is called after data is loaded
                    setTimeout(() => {
                        lockForm();
                        showMessage('Data found for this program. Form is locked. Click "Update" to modify data.', 'success');
                        updateDataSummarySection();
                    }, 150);

                    // Load existing documents for the selected program
                    loadExistingDocuments();
                } else {
                    // No data exists - reset form and keep unlocked
                    resetFormToNewState();

                    setTimeout(() => {
                        unlockForm();
                        showMessage('No data found for this program. Enter the data and click Submit button to save.', 'info');
                        updateDataSummarySection();

                        // Still check for existing documents (they can exist independently of form data)
                        loadExistingDocuments();
                    }, 100);
                }

                // Reset the flag after completion
                isCheckingProgram = false;
            })
            .catch(error => {
                console.error('Error checking program data:', error);
                console.error('Error details:', error.message);
                resetFormToNewState();
                showMessage('Error checking program data: ' + error.message, 'error');
                unlockForm(); // Ensure form is unlocked on error
                updateDataSummarySection();

                // Reset the flag after error
                isCheckingProgram = false;
            });
    }

    // CRITICAL: Ensure program reference fields are ALWAYS disabled and readonly (never editable)
    function ensureProgramFieldsReadonly() {
        const programFields = ['program_name_display', 'program_code_display', 'intake_capacity'];
        programFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                // CRITICAL: Set BOTH disabled AND readonly to prevent any editing
                field.disabled = true;
                field.readOnly = true;
                field.style.backgroundColor = '#f8f9fa';
                field.style.cursor = 'not-allowed';
                // Ensure they're never removed from disabled state
                field.setAttribute('disabled', 'disabled');
                field.setAttribute('readonly', 'readonly');
            }
        });
    }

    // Lock form when data exists (like ExecutiveDevelopment.php)
    function lockForm() {
        // Ensure program reference fields are always readonly
        ensureProgramFieldsReadonly();
        // Disable all form inputs except program select and program reference fields
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            if (input.id !== 'program_select' &&
                input.id !== 'program_name_display' &&
                input.id !== 'program_code_display' &&
                input.id !== 'intake_capacity') {
                input.disabled = true;
                input.readOnly = true;
                input.style.backgroundColor = '#f8f9fa';
                input.style.cursor = 'not-allowed';
            }
        });

        // Keep program select always enabled and selectable
        const programSelect = document.getElementById('program_select');
        if (programSelect) {
            programSelect.disabled = false;
            programSelect.style.backgroundColor = '#fff';
            programSelect.style.cursor = 'pointer';
        }

        // Show Update and Clear Data buttons, hide Submit, Save, and Cancel Update buttons
        const updateBtn = document.getElementById('updateBtn');
        const clearBtn = document.getElementById('clearBtn');
        const submitBtn = document.getElementById('submitBtn');
        const saveBtn = document.getElementById('saveBtn');
        const cancelUpdateBtn = document.getElementById('cancelUpdateBtn');

        if (updateBtn) updateBtn.style.display = 'inline-block';
        if (clearBtn) clearBtn.style.display = 'inline-block';
        if (submitBtn) submitBtn.style.display = 'none';
        if (saveBtn) saveBtn.style.display = 'none';
        if (cancelUpdateBtn) cancelUpdateBtn.style.display = 'none';

        // Lock country section - disable Add Country button and country inputs
        const addCountryBtn = document.querySelector('button[onclick="addCountryRow()"]');
        if (addCountryBtn) {
            addCountryBtn.disabled = true;
            addCountryBtn.style.opacity = '0.5';
            addCountryBtn.style.cursor = 'not-allowed';
        }

        // Disable all country table inputs
        const countryInputs = document.querySelectorAll('#countryTable input, #countryTable select');
        countryInputs.forEach(input => {
            input.disabled = true;
            input.readOnly = true;
            input.style.backgroundColor = '#f8f9fa';
            input.style.cursor = 'not-allowed';
        });

        // Update form status alert
        updateFormStatusAlert('locked');
    }

    // Unlock form for new data entry
    function unlockForm() {
        // CRITICAL: Ensure program reference fields are ALWAYS disabled (never editable)
        ensureProgramFieldsReadonly();

        // CRITICAL: Enable all form inputs except program reference fields
        // Must clear BOTH readonly attribute AND readOnly property to ensure fields submit values
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            // CRITICAL: NEVER enable program reference fields - they must always be disabled
            if (input.id !== 'program_name_display' &&
                input.id !== 'program_code_display' &&
                input.id !== 'intake_capacity' &&
                input.id !== 'program_select') {
                // Remove readonly attribute AND property
                input.removeAttribute('readonly');
                input.readOnly = false;
                // Remove disabled attribute AND property
                input.disabled = false;
                input.removeAttribute('disabled');
                input.style.backgroundColor = '#fff';
                input.style.cursor = 'text';
            }
        });
        
        // Double-check: Ensure program fields stay disabled
        ensureProgramFieldsReadonly();

        // Show Submit button, hide Update, Clear, Save, and Cancel Update buttons
        document.getElementById('submitBtn').style.display = 'inline-block';
        document.getElementById('updateBtn').style.display = 'none';
        document.getElementById('clearBtn').style.display = 'none';
        document.getElementById('saveBtn').style.display = 'none';
        const cancelUpdateBtn = document.getElementById('cancelUpdateBtn');
        if (cancelUpdateBtn) cancelUpdateBtn.style.display = 'none';

        // Unlock country section - enable Add Country button and country inputs
        const addCountryBtn = document.querySelector('button[onclick="addCountryRow()"]');
        if (addCountryBtn) {
            addCountryBtn.disabled = false;
            addCountryBtn.style.opacity = '1';
            addCountryBtn.style.cursor = 'pointer';
        }

        // Enable all country table inputs
        const countryInputs = document.querySelectorAll('#countryTable input, #countryTable select');
        countryInputs.forEach(input => {
            input.disabled = false;
            input.readOnly = false;
            input.style.backgroundColor = '#fff';
            input.style.cursor = 'text';
        });

        // Update form status alert
        updateFormStatusAlert('new');
    }

    // Update form status alert
    function updateFormStatusAlert(state) {
        let statusAlert = document.querySelector('.form-status-alert');
        if (!statusAlert) {
            // Create status alert if it doesn't exist
            statusAlert = document.createElement('div');
            statusAlert.className = 'form-status-alert alert';
            const formSection = document.querySelector('.form-section');
            if (formSection) {
                formSection.insertBefore(statusAlert, formSection.firstChild);
            }
        }

        if (state === 'locked') {
            statusAlert.className = 'form-status-alert alert alert-info';
            statusAlert.innerHTML = '<i class="fas fa-lock me-2"></i><strong>Form Status:</strong> Data has been submitted. Form is locked. Click "Update" to modify existing data.';
        } else {
            statusAlert.className = 'form-status-alert alert alert-success';
            statusAlert.innerHTML = '<i class="fas fa-check-circle me-2"></i><strong>Form Status:</strong> Form is ready for data entry.';
        }
    }

    // Update data summary section based on form state
    function updateDataSummarySection() {
        const dataSection = document.getElementById('existing_data_section');
        const titleElement = document.getElementById('data_summary_title');
        const programSelect = document.getElementById('program_select');

        if (!dataSection || !titleElement) return;

        if (programSelect && programSelect.value) {
            // Program is selected
            const selectedOption = programSelect.options[programSelect.selectedIndex];
            const programName = selectedOption.text;

            // Check if form is locked (existing data) or unlocked (new data)
            const formInputs = document.querySelectorAll('input[type="number"]');
            const isFormLocked = formInputs.length > 0 && formInputs[0].disabled;

            if (isFormLocked) {
                titleElement.innerHTML = `<b>Viewing Existing Data for: ${programName}</b>`;
                dataSection.style.display = 'block';
            } else {
                titleElement.innerHTML = `<b>Entering New Data for: ${programName}</b>`;
                dataSection.style.display = 'block'; // Always show existing data table
            }
        } else {
            // No program selected
            titleElement.innerHTML = `<b>Existing Data Records</b>`;
            dataSection.style.display = 'block';
        }
    }
    // Flags to prevent multiple calls
    let isResetting = false;
    let isCheckingProgram = false;

    function resetFormToNewState() {
        if (isResetting) {
            return;
        }

        isResetting = true;

        // Set all number inputs to 0, clear text inputs
        const allInputs = document.querySelectorAll('input[type="number"], input[type="text"], input[type="tel"]');
        let resetCount = 0;


        allInputs.forEach(input => {
            // Skip readonly fields, disabled fields, and special fields
            if (!input.hasAttribute('readonly') &&
                !input.disabled &&
                input.id !== 'program_select' &&
                input.name !== 'csrf_token' &&
                input.name !== 'record_id' &&
                input.name !== 'hod_email') { // Skip HOD email as it's readonly

                if (input.type === 'number') {
                    input.value = '0';
                    resetCount++;
                } else {
                    input.value = '';
                    resetCount++;
                }
            }
        });


        // Force reset all number inputs to 0 (more aggressive approach)
        const allNumberInputs = document.querySelectorAll('input[type="number"]');
        allNumberInputs.forEach(input => {
            if (input.name !== 'csrf_token' && input.name !== 'record_id' && input.name !== 'hod_email') {
                input.value = '0';
            }
        });

        // Reset all select elements to first option (if not readonly)
        const allSelects = document.querySelectorAll('select');
        allSelects.forEach(select => {
            if (!select.hasAttribute('readonly') && !select.disabled && select.id !== 'program_select') {
                if (select.options.length > 0) {
                    select.selectedIndex = 0;
                }
            }
        });

        // Reset totals
        const totalMale = document.getElementById('total_male_students');
        const totalFemale = document.getElementById('total_female_students');
        const totalOther = document.getElementById('total_other_students');

        if (totalMale) totalMale.textContent = '0';
        if (totalFemale) totalFemale.textContent = '0';
        if (totalOther) totalOther.textContent = '0';

        // Clear country table
        const countryTableBody = document.querySelector('#countryTable tbody');
        if (countryTableBody) {
            countryTableBody.innerHTML = '';
        }

        // Reset country section visibility
        checkCountrySectionVisibility();

        // Clear any existing messages (but NOT the Important Guidelines alert)
        const existingMessages = document.querySelectorAll('.alert');
        existingMessages.forEach(msg => {
            // CRITICAL: Never remove the Important Guidelines alert
            if (msg.id === 'important-instructions-alert') {
                return; // Skip this alert
            }
            if (msg.classList.contains('alert-info') || msg.classList.contains('alert-success') || msg.classList.contains('alert-warning')) {
                msg.remove();
            }
        });

        // Clear record ID
        const recordIdInput = document.getElementById('record_id');
        if (recordIdInput) {
            recordIdInput.value = '';
        }

        // Update program details to ensure they're populated
        updateProgramDetails();

        updateDataSummarySection();

        // Reset the flag after a small delay to prevent rapid successive calls
        setTimeout(() => {
            isResetting = false;
        }, 100);
    }

    // Load existing data into form
    function loadExistingData(record) {

        // Set the record ID first
        const recordIdInput = document.getElementById('record_id');
        if (recordIdInput) {
            recordIdInput.value = record.ID;
        }

        // Clear form first (but don't call resetFormToNewState to avoid double calls)
        const allInputs = document.querySelectorAll('input[type="number"], input[type="text"], input[type="tel"]');
        allInputs.forEach(input => {
            if (!input.hasAttribute('readonly') &&
                !input.disabled &&
                input.id !== 'program_select' &&
                input.name !== 'csrf_token' &&
                input.name !== 'record_id' &&
                input.name !== 'hod_email') {
                if (input.type === 'number') {
                    input.value = '0';
                } else {
                    input.value = '';
                }
            }
        });

        // Reset totals
        const totalMale = document.getElementById('total_male_students');
        const totalFemale = document.getElementById('total_female_students');
        const totalOther = document.getElementById('total_other_students');

        if (totalMale) totalMale.textContent = '0';
        if (totalFemale) totalFemale.textContent = '0';
        if (totalOther) totalOther.textContent = '0';

        // Clear country table
        const countryTableBody = document.querySelector('#countryTable tbody');
        if (countryTableBody) {
            countryTableBody.innerHTML = '';
        }

        // Reset country section visibility
        checkCountrySectionVisibility();

        // Clear any existing messages (but NOT the Important Guidelines alert)
        const existingMessages = document.querySelectorAll('.alert');
        existingMessages.forEach(msg => {
            // CRITICAL: Never remove the Important Guidelines alert
            if (msg.id === 'important-instructions-alert') {
                return; // Skip this alert
            }
            if (msg.classList.contains('alert-info') || msg.classList.contains('alert-success') || msg.classList.contains('alert-warning')) {
                msg.remove();
            }
        });


        // Populate all form fields with existing data
        const fieldMappings = {
            'Total_number_of_Male_Students_within_state': record.Total_number_of_Male_Students_within_state,
            'Total_number_of_Female_Students_within_state': record.Total_number_of_Female_Students_within_state,
            'Total_number_of_Other_Students_within_state': record.Total_number_of_Other_Students_within_state,
            'Male_Students_outside_state': record.Male_Students_outside_state,
            'Female_Students_outside_state': record.Female_Students_outside_state,
            'Other_Students_outside_state': record.Other_Students_outside_state,
            'Male_Students_outside_country': record.Male_Students_outside_country,
            'Female_Students_outside_country': record.Female_Students_outside_country,
            'Other_Students_outside_country': record.Other_Students_outside_country,
            'Male_Students_Economic_Backward': record.Male_Students_Economic_Backward,
            'Female_Students_Economic_Backward': record.Female_Students_Economic_Backward,
            'Other_Students_Economic_Backward': record.Other_Students_Economic_Backward, //1
            'Male_Student_General': record.Male_Student_General,
            'Female_Student_General': record.Female_Student_General,
            'Other_Student_General': record.Other_Student_General,
            'Male_Students_Social_Backward_SC': record.Male_Student_Social_Backward_SC, //2
            'Female_Student_Social_Backward_SC': record.Female_Student_Social_Backward_SC,
            'Other_Student_Social_Backward_SC': record.Other_Student_Social_Backward_SC,
            'Male_Students_Social_Backward_ST': record.Male_Student_Social_Backward_ST,
            'Female_Student_Social_Backward_ST': record.Female_Student_Social_Backward_ST,
            'Other_Student_Social_Backward_ST': record.Other_Student_Social_Backward_ST,
            'Male_Students_Social_Backward_OBC': record.Male_Student_Social_Backward_OBC,
            'Female_Student_Social_Backward_OBC': record.Female_Student_Social_Backward_OBC,
            'Other_Student_Social_Backward_OBC': record.Other_Student_Social_Backward_OBC,
            'Male_Students_Social_Backward_DTA': record.Male_Student_Social_Backward_DTA,
            'Female_Student_Social_Backward_DTA': record.Female_Student_Social_Backward_DTA,
            'Other_Student_Social_Backward_DTA': record.Other_Student_Social_Backward_DTA,
            'Male_Students_Social_Backward_NTB': record.Male_Student_Social_Backward_NTB,
            'Female_Student_Social_Backward_NTB': record.Female_Student_Social_Backward_NTB,
            'Other_Student_Social_Backward_NTB': record.Other_Student_Social_Backward_NTB,
            'Male_Students_Social_Backward_NTC': record.Male_Student_Social_Backward_NTC,
            'Female_Student_Social_Backward_NTC': record.Female_Student_Social_Backward_NTC,
            'Other_Student_Social_Backward_NTC': record.Other_Student_Social_Backward_NTC,
            'Male_Students_Social_Backward_NTD': record.Male_Student_Social_Backward_NTD,
            'Female_Student_Social_Backward_NTD': record.Female_Student_Social_Backward_NTD,
            'Other_Student_Social_Backward_NTD': record.Other_Student_Social_Backward_NTD,
            'Male_Students_Social_Backward_EWS': record.Male_Student_Social_Backward_EWS,
            'Female_Student_Social_Backward_EWS': record.Female_Student_Social_Backward_EWS,
            'Other_Student_Social_Backward_EWS': record.Other_Student_Social_Backward_EWS,
            'Male_Students_Social_Backward_SEBC': record.Male_Student_Social_Backward_SEBC,
            'Female_Student_Social_Backward_SEBC': record.Female_Student_Social_Backward_SEBC,
            'Other_Student_Social_Backward_SEBC': record.Other_Student_Social_Backward_SEBC,
            'Male_Students_Social_Backward_SBC': record.Male_Student_Social_Backward_SBC,
            'Female_Student_Social_Backward_SBC': record.Female_Student_Social_Backward_SBC,
            'Other_Student_Social_Backward_SBC': record.Other_Student_Social_Backward_SBC,
            'Male_Students_Physically_Handicapped': record.Male_Student_Physically_Handicapped, //1
            'Female_Students_Physically_Handicapped': record.Female_Student_Physically_Handicapped,
            'Other_Students_Physically_Handicapped': record.Other_Student_Physically_Handicapped,
            'Male_Students_TRANGOVTOF_TGO': record.Male_Student_TGO,
            'Female_Students_TRANGOVTOF_TGO': record.Female_Student_TGO,
            'Other_Students_TRANGOVTOF_TGO': record.Other_Student_TGO,
            'Male_Students_CMIL': record.Male_Student_CMIL,
            'Female_Student_CMIL': record.Female_Student_CMIL,
            'Other_Student_CMIL': record.Other_Student_CMIL,
            'Male_Student_SPCUL': record.Male_Student_SPCUL,
            'Female_Student_SPCUL': record.Female_Student_SPCUL,
            'Other_Student_SPCUL': record.Other_Student_SPCUL,
            'Male_Student_Widow_Single_Mother': record.Male_Student_Widow_Single_Mother,
            'Female_Student_Widow_Single_Mother': record.Female_Student_Widow_Single_Mother,
            'Other_Student_Widow_Single_Mother': record.Other_Student_Widow_Single_Mother,
            'Male_Student_ES': record.Male_Student_ES,
            'Female_Student_ES': record.Female_Student_ES,
            'Other_Student_ES': record.Other_Student_ES,

            'Male_Student_Receiving_Scholarship_Government': record.Male_Student_Receiving_Scholarship_Government,
            'Female_Student_Receiving_Scholarship_Government': record.Female_Student_Receiving_Scholarship_Government,
            'Other_Student_Receiving_Scholarship_Government': record.Other_Student_Receiving_Scholarship_Government,

            'Male_Student_Receiving_Scholarship_Institution': record.Male_Student_Receiving_Scholarship_Institution,
            'Female_Student_Receiving_Scholarship_Institution': record.Female_Student_Receiving_Scholarship_Institution,
            'Other_Student_Receiving_Scholarship_Institution': record.Other_Student_Receiving_Scholarship_Institution,

            'Male_Student_Receiving_Scholarship_Private_Body': record.Male_Student_Receiving_Scholarship_Private_Body,
            'Female_Student_Receiving_Scholarship_Private_Body': record.Female_Student_Receiving_Scholarship_Private_Body,
            'Other_Student_Receiving_Scholarship_Private_Body': record.Other_Student_Receiving_Scholarship_Private_Body
        };

        // CRITICAL: Set program select FIRST so updateProgramDetails can get intake capacity from programmes table
        const programCode = record.PROGRAM_CODE || '';
        if (programCode) {
            const programSelect = document.getElementById('program_select');
            if (programSelect) {
                // Find and select the option with matching programme_code
                for (let i = 0; i < programSelect.options.length; i++) {
                    if (programSelect.options[i].value === programCode) {
                        programSelect.selectedIndex = i;
                        break;
                    }
                }
            }
        }

        // Populate form fields
        Object.keys(fieldMappings).forEach(fieldName => {
            const input = document.querySelector(`input[name="${fieldName}"]`);
            if (input && fieldMappings[fieldName] !== null) {
                input.value = fieldMappings[fieldName];
            }
        });

        // CRITICAL: Update program details AFTER setting program select
        // This ensures intake capacity is fetched from programmes table (data-intake attribute)
        // NOT from existing_record which might be 0
        updateProgramDetails();
        
        // Double-check: Ensure program fields stay disabled
        ensureProgramFieldsReadonly();

        // Calculate totals
        calculateTotalStudents();

        // Show country section if needed
        checkCountrySectionVisibility();

        // Load country data if exists
        loadCountryData(record.ID);

        // Note: Button visibility will be updated by lockForm() or unlockForm()
    }

    // Add country row function
    function addCountryRow(countryCode = '', studentCount = '') {
        const countryTableBody = document.querySelector('#countryTable tbody');
        if (!countryTableBody) {
            console.error('[Intake] Country table body not found');
            return;
        }

        // Convert to string for comparison
        const countryCodeStr = String(countryCode || '');
        const studentCountNum = parseInt(studentCount || 0);

        console.log('[Intake] addCountryRow called - Code:', countryCodeStr, 'Count:', studentCountNum);

        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <select name="country_codes[]" class="form-control country-code">
                    <option value="">Select Country</option>
                    <option value="44" ${countryCodeStr === '44' ? 'selected' : ''}>UK (+44)</option>
                    <option value="1" ${countryCodeStr === '1' ? 'selected' : ''}>USA (+1)</option>
                    <option value="47" ${countryCodeStr === '47' ? 'selected' : ''}>Norway (+47)</option>
                    <option value="376" ${countryCodeStr === '376' ? 'selected' : ''}>Andorra (+376)</option>
                    <option value="213" ${countryCodeStr === '213' ? 'selected' : ''}>Algeria (+213)</option>
                    <option value="1264" ${countryCodeStr === '1264' ? 'selected' : ''}>Anguilla (+1264)</option>
                    <option value="244" ${countryCodeStr === '244' ? 'selected' : ''}>Angola (+244)</option>
                    <option value="1268" ${countryCodeStr === '1268' ? 'selected' : ''}>Antigua & Barbuda (+1268)</option>
                    <option value="54" ${countryCodeStr === '54' ? 'selected' : ''}>Argentina (+54)</option>
                    <option value="374" ${countryCodeStr === '374' ? 'selected' : ''}>Armenia (+374)</option>
                    <option value="297" ${countryCodeStr === '297' ? 'selected' : ''}>Aruba (+297)</option>
                    <option value="61" ${countryCodeStr === '61' ? 'selected' : ''}>Australia (+61)</option>
                    <option value="43" ${countryCodeStr === '43' ? 'selected' : ''}>Austria (+43)</option>
                    <option value="994" ${countryCodeStr === '994' ? 'selected' : ''}>Azerbaijan (+994)</option>
                    <option value="1242" ${countryCodeStr === '1242' ? 'selected' : ''}>Bahamas (+1242)</option>
                    <option value="973" ${countryCodeStr === '973' ? 'selected' : ''}>Bahrain (+973)</option>
                    <option value="880" ${countryCodeStr === '880' ? 'selected' : ''}>Bangladesh (+880)</option>
                    <option value="1246" ${countryCodeStr === '1246' ? 'selected' : ''}>Barbados (+1246)</option>
                    <option value="375" ${countryCodeStr === '375' ? 'selected' : ''}>Belarus (+375)</option>
                    <option value="32" ${countryCodeStr === '32' ? 'selected' : ''}>Belgium (+32)</option>
                    <option value="501" ${countryCodeStr === '501' ? 'selected' : ''}>Belize (+501)</option>
                    <option value="229" ${countryCodeStr === '229' ? 'selected' : ''}>Benin (+229)</option>
                    <option value="1441" ${countryCodeStr === '1441' ? 'selected' : ''}>Bermuda (+1441)</option>
                    <option value="975" ${countryCodeStr === '975' ? 'selected' : ''}>Bhutan (+975)</option>
                    <option value="591" ${countryCodeStr === '591' ? 'selected' : ''}>Bolivia (+591)</option>
                    <option value="387" ${countryCodeStr === '387' ? 'selected' : ''}>Bosnia Herzegovina (+387)</option>
                    <option value="267" ${countryCodeStr === '267' ? 'selected' : ''}>Botswana (+267)</option>
                    <option value="55" ${countryCodeStr === '55' ? 'selected' : ''}>Brazil (+55)</option>
                    <option value="673" ${countryCodeStr === '673' ? 'selected' : ''}>Brunei (+673)</option>
                    <option value="359" ${countryCodeStr === '359' ? 'selected' : ''}>Bulgaria (+359)</option>
                    <option value="226" ${countryCodeStr === '226' ? 'selected' : ''}>Burkina Faso (+226)</option>
                    <option value="257" ${countryCodeStr === '257' ? 'selected' : ''}>Burundi (+257)</option>
                    <option value="855" ${countryCodeStr === '855' ? 'selected' : ''}>Cambodia (+855)</option>
                    <option value="237" ${countryCodeStr === '237' ? 'selected' : ''}>Cameroon (+237)</option>
                    <option value="238" ${countryCodeStr === '238' ? 'selected' : ''}>Cape Verde Islands (+238)</option>
                    <option value="1345" ${countryCodeStr === '1345' ? 'selected' : ''}>Cayman Islands (+1345)</option>
                    <option value="236" ${countryCodeStr === '236' ? 'selected' : ''}>Central African Republic (+236)</option>
                    <option value="56" ${countryCodeStr === '56' ? 'selected' : ''}>Chile (+56)</option>
                    <option value="86" ${countryCodeStr === '86' ? 'selected' : ''}>China (+86)</option>
                    <option value="57" ${countryCodeStr === '57' ? 'selected' : ''}>Colombia (+57)</option>
                    <option value="269" ${countryCodeStr === '269' ? 'selected' : ''}>Comoros (+269)</option>
                    <option value="242" ${countryCodeStr === '242' ? 'selected' : ''}>Congo (+242)</option>
                    <option value="243" ${countryCodeStr === '243' ? 'selected' : ''}>Congo Democratic Republic (+243)</option>
                    <option value="506" ${countryCodeStr === '506' ? 'selected' : ''}>Costa Rica (+506)</option>
                    <option value="225" ${countryCodeStr === '225' ? 'selected' : ''}>Côte d'Ivoire (+225)</option>
                    <option value="385" ${countryCodeStr === '385' ? 'selected' : ''}>Croatia (+385)</option>
                    <option value="53" ${countryCodeStr === '53' ? 'selected' : ''}>Cuba (+53)</option>
                    <option value="357" ${countryCodeStr === '357' ? 'selected' : ''}>Cyprus (+357)</option>
                    <option value="420" ${countryCodeStr === '420' ? 'selected' : ''}>Czech Republic (+420)</option>
                    <option value="45" ${countryCodeStr === '45' ? 'selected' : ''}>Denmark (+45)</option>
                    <option value="253" ${countryCodeStr === '253' ? 'selected' : ''}>Djibouti (+253)</option>
                    <option value="1767" ${countryCodeStr === '1767' ? 'selected' : ''}>Dominica (+1767)</option>
                    <option value="1809" ${countryCodeStr === '1809' ? 'selected' : ''}>Dominican Republic (+1809)</option>
                    <option value="593" ${countryCodeStr === '593' ? 'selected' : ''}>Ecuador (+593)</option>
                    <option value="20" ${countryCodeStr === '20' ? 'selected' : ''}>Egypt (+20)</option>
                    <option value="503" ${countryCodeStr === '503' ? 'selected' : ''}>El Salvador (+503)</option>
                    <option value="240" ${countryCodeStr === '240' ? 'selected' : ''}>Equatorial Guinea (+240)</option>
                    <option value="291" ${countryCodeStr === '291' ? 'selected' : ''}>Eritrea (+291)</option>
                    <option value="372" ${countryCodeStr === '372' ? 'selected' : ''}>Estonia (+372)</option>
                    <option value="251" ${countryCodeStr === '251' ? 'selected' : ''}>Ethiopia (+251)</option>
                    <option value="679" ${countryCodeStr === '679' ? 'selected' : ''}>Fiji (+679)</option>
                    <option value="358" ${countryCodeStr === '358' ? 'selected' : ''}>Finland (+358)</option>
                    <option value="33" ${countryCodeStr === '33' ? 'selected' : ''}>France (+33)</option>
                    <option value="241" ${countryCodeStr === '241' ? 'selected' : ''}>Gabon (+241)</option>
                    <option value="220" ${countryCodeStr === '220' ? 'selected' : ''}>Gambia (+220)</option>
                    <option value="995" ${countryCodeStr === '995' ? 'selected' : ''}>Georgia (+995)</option>
                    <option value="49" ${countryCodeStr === '49' ? 'selected' : ''}>Germany (+49)</option>
                    <option value="233" ${countryCodeStr === '233' ? 'selected' : ''}>Ghana (+233)</option>
                    <option value="30" ${countryCodeStr === '30' ? 'selected' : ''}>Greece (+30)</option>
                    <option value="1473" ${countryCodeStr === '1473' ? 'selected' : ''}>Grenada (+1473)</option>
                    <option value="502" ${countryCodeStr === '502' ? 'selected' : ''}>Guatemala (+502)</option>
                    <option value="224" ${countryCodeStr === '224' ? 'selected' : ''}>Guinea (+224)</option>
                    <option value="245" ${countryCodeStr === '245' ? 'selected' : ''}>Guinea-Bissau (+245)</option>
                    <option value="592" ${countryCodeStr === '592' ? 'selected' : ''}>Guyana (+592)</option>
                    <option value="509" ${countryCodeStr === '509' ? 'selected' : ''}>Haiti (+509)</option>
                    <option value="504" ${countryCodeStr === '504' ? 'selected' : ''}>Honduras (+504)</option>
                    <option value="852" ${countryCodeStr === '852' ? 'selected' : ''}>Hong Kong (+852)</option>
                    <option value="36" ${countryCodeStr === '36' ? 'selected' : ''}>Hungary (+36)</option>
                    <option value="354" ${countryCodeStr === '354' ? 'selected' : ''}>Iceland (+354)</option>
                    <option value="62" ${countryCodeStr === '62' ? 'selected' : ''}>Indonesia (+62)</option>
                    <option value="98" ${countryCodeStr === '98' ? 'selected' : ''}>Iran (+98)</option>
                    <option value="964" ${countryCodeStr === '964' ? 'selected' : ''}>Iraq (+964)</option>
                    <option value="353" ${countryCodeStr === '353' ? 'selected' : ''}>Ireland (+353)</option>
                    <option value="972" ${countryCodeStr === '972' ? 'selected' : ''}>Israel (+972)</option>
                    <option value="39" ${countryCodeStr === '39' ? 'selected' : ''}>Italy (+39)</option>
                    <option value="1876" ${countryCodeStr === '1876' ? 'selected' : ''}>Jamaica (+1876)</option>
                    <option value="81" ${countryCodeStr === '81' ? 'selected' : ''}>Japan (+81)</option>
                    <option value="962" ${countryCodeStr === '962' ? 'selected' : ''}>Jordan (+962)</option>
                    <option value="7" ${countryCodeStr === '7' ? 'selected' : ''}>Kazakhstan (+7)</option>
                    <option value="254" ${countryCodeStr === '254' ? 'selected' : ''}>Kenya (+254)</option>
                    <option value="101" ${countryCodeStr === '101' ? 'selected' : ''}>Kiribati (+686)</option>
                    <option value="103" ${countryCodeStr === '103' ? 'selected' : ''}>Kuwait (+965)</option>
                    <option value="104" ${countryCodeStr === '104' ? 'selected' : ''}>Kyrgyzstan (+996)</option>
                    <option value="105" ${countryCodeStr === '105' ? 'selected' : ''}>Laos (+856)</option>
                    <option value="106" ${countryCodeStr === '106' ? 'selected' : ''}>Latvia (+371)</option>
                    <option value="107" ${countryCodeStr === '107' ? 'selected' : ''}>Lebanon (+961)</option>
                    <option value="108" ${countryCodeStr === '108' ? 'selected' : ''}>Lesotho (+266)</option>
                    <option value="109" ${countryCodeStr === '109' ? 'selected' : ''}>Liberia (+231)</option>
                    <option value="110" ${countryCodeStr === '110' ? 'selected' : ''}>Libya (+218)</option>
                    <option value="111" ${countryCodeStr === '111' ? 'selected' : ''}>Liechtenstein (+423)</option>
                    <option value="112" ${countryCodeStr === '112' ? 'selected' : ''}>Lithuania (+370)</option>
                    <option value="113" ${countryCodeStr === '113' ? 'selected' : ''}>Luxembourg (+352)</option>
                    <option value="114" ${countryCodeStr === '114' ? 'selected' : ''}>Macau (+853)</option>
                    <option value="115" ${countryCodeStr === '115' ? 'selected' : ''}>Madagascar (+261)</option>
                    <option value="116" ${countryCodeStr === '116' ? 'selected' : ''}>Malawi (+265)</option>
                    <option value="117" ${countryCodeStr === '117' ? 'selected' : ''}>Malaysia (+60)</option>
                    <option value="118" ${countryCodeStr === '118' ? 'selected' : ''}>Maldives (+960)</option>
                    <option value="119" ${countryCodeStr === '119' ? 'selected' : ''}>Mali (+223)</option>
                    <option value="120" ${countryCodeStr === '120' ? 'selected' : ''}>Malta (+356)</option>
                    <option value="121" ${countryCodeStr === '121' ? 'selected' : ''}>Marshall Islands (+692)</option>
                    <option value="122" ${countryCodeStr === '122' ? 'selected' : ''}>Mauritania (+222)</option>
                    <option value="123" ${countryCodeStr === '123' ? 'selected' : ''}>Mauritius (+230)</option>
                    <option value="124" ${countryCodeStr === '124' ? 'selected' : ''}>Mexico (+52)</option>
                    <option value="125" ${countryCodeStr === '125' ? 'selected' : ''}>Micronesia (+691)</option>
                    <option value="126" ${countryCodeStr === '126' ? 'selected' : ''}>Moldova (+373)</option>
                    <option value="127" ${countryCodeStr === '127' ? 'selected' : ''}>Monaco (+377)</option>
                    <option value="128" ${countryCodeStr === '128' ? 'selected' : ''}>Mongolia (+976)</option>
                    <option value="129" ${countryCodeStr === '129' ? 'selected' : ''}>Montenegro (+382)</option>
                    <option value="130" ${countryCodeStr === '130' ? 'selected' : ''}>Morocco (+212)</option>
                    <option value="131" ${countryCodeStr === '131' ? 'selected' : ''}>Mozambique (+258)</option>
                    <option value="132" ${countryCodeStr === '132' ? 'selected' : ''}>Myanmar (+95)</option>
                    <option value="133" ${countryCodeStr === '133' ? 'selected' : ''}>Namibia (+264)</option>
                    <option value="134" ${countryCodeStr === '134' ? 'selected' : ''}>Nauru (+674)</option>
                    <option value="135" ${countryCodeStr === '135' ? 'selected' : ''}>Nepal (+977)</option>
                    <option value="136" ${countryCodeStr === '136' ? 'selected' : ''}>Netherlands (+31)</option>
                    <option value="139" ${countryCodeStr === '139' ? 'selected' : ''}>New Zealand (+64)</option>
                    <option value="140" ${countryCodeStr === '140' ? 'selected' : ''}>Nicaragua (+505)</option>
                    <option value="141" ${countryCodeStr === '141' ? 'selected' : ''}>Niger (+227)</option>
                    <option value="142" ${countryCodeStr === '142' ? 'selected' : ''}>Nigeria (+234)</option>
                    <option value="143" ${countryCodeStr === '143' ? 'selected' : ''}>North Korea (+850)</option>
                    <option value="144" ${countryCodeStr === '144' ? 'selected' : ''}>North Macedonia (+389)</option>
                    <option value="146" ${countryCodeStr === '146' ? 'selected' : ''}>Northern Mariana Islands (+1670)</option>
                    <option value="148" ${countryCodeStr === '148' ? 'selected' : ''}>Oman (+968)</option>
                    <option value="149" ${countryCodeStr === '149' ? 'selected' : ''}>Pakistan (+92)</option>
                    <option value="150" ${countryCodeStr === '150' ? 'selected' : ''}>Palau (+680)</option>
                    <option value="151" ${countryCodeStr === '151' ? 'selected' : ''}>Palestine (+970)</option>
                    <option value="152" ${countryCodeStr === '152' ? 'selected' : ''}>Panama (+507)</option>
                    <option value="153" ${countryCodeStr === '153' ? 'selected' : ''}>Papua New Guinea (+675)</option>
                    <option value="154" ${countryCodeStr === '154' ? 'selected' : ''}>Paraguay (+595)</option>
                    <option value="155" ${countryCodeStr === '155' ? 'selected' : ''}>Peru (+51)</option>
                    <option value="156" ${countryCodeStr === '156' ? 'selected' : ''}>Philippines (+63)</option>
                    <option value="157" ${countryCodeStr === '157' ? 'selected' : ''}>Poland (+48)</option>
                    <option value="158" ${countryCodeStr === '158' ? 'selected' : ''}>Portugal (+351)</option>
                    <option value="159" ${countryCodeStr === '159' ? 'selected' : ''}>Puerto Rico (+1787)</option>
                    <option value="160" ${countryCodeStr === '160' ? 'selected' : ''}>Qatar (+974)</option>
                    <option value="162" ${countryCodeStr === '162' ? 'selected' : ''}>Romania (+40)</option>
                    <option value="163" ${countryCodeStr === '163' ? 'selected' : ''}>Russia (+7)</option>
                    <option value="164" ${countryCodeStr === '164' ? 'selected' : ''}>Rwanda (+250)</option>
                    <option value="165" ${countryCodeStr === '165' ? 'selected' : ''}>Saint Kitts & Nevis (+1869)</option>
                    <option value="166" ${countryCodeStr === '166' ? 'selected' : ''}>Saint Lucia (+1758)</option>
                    <option value="167" ${countryCodeStr === '167' ? 'selected' : ''}>Saint Vincent & Grenadines (+1784)</option>
                    <option value="169" ${countryCodeStr === '169' ? 'selected' : ''}>Samoa (+685)</option>
                    <option value="170" ${countryCodeStr === '170' ? 'selected' : ''}>San Marino (+378)</option>
                    <option value="171" ${countryCodeStr === '171' ? 'selected' : ''}>São Tomé & Príncipe (+239)</option>
                    <option value="172" ${countryCodeStr === '172' ? 'selected' : ''}>Saudi Arabia (+966)</option>
                    <option value="173" ${countryCodeStr === '173' ? 'selected' : ''}>Senegal (+221)</option>
                    <option value="174" ${countryCodeStr === '174' ? 'selected' : ''}>Serbia (+381)</option>
                    <option value="175" ${countryCodeStr === '175' ? 'selected' : ''}>Seychelles (+248)</option>
                    <option value="176" ${countryCodeStr === '176' ? 'selected' : ''}>Sierra Leone (+232)</option>
                    <option value="177" ${countryCodeStr === '177' ? 'selected' : ''}>Singapore (+65)</option>
                    <option value="179" ${countryCodeStr === '179' ? 'selected' : ''}>Slovakia (+421)</option>
                    <option value="180" ${countryCodeStr === '180' ? 'selected' : ''}>Slovenia (+386)</option>
                    <option value="181" ${countryCodeStr === '181' ? 'selected' : ''}>Solomon Islands (+677)</option>
                    <option value="182" ${countryCodeStr === '182' ? 'selected' : ''}>Somalia (+252)</option>
                    <option value="183" ${countryCodeStr === '183' ? 'selected' : ''}>South Africa (+27)</option>
                    <option value="184" ${countryCodeStr === '184' ? 'selected' : ''}>South Korea (+82)</option>
                    <option value="185" ${countryCodeStr === '185' ? 'selected' : ''}>South Sudan (+211)</option>
                    <option value="186" ${countryCodeStr === '186' ? 'selected' : ''}>Spain (+34)</option>
                    <option value="187" ${countryCodeStr === '187' ? 'selected' : ''}>Sri Lanka (+94)</option>
                    <option value="188" ${countryCodeStr === '188' ? 'selected' : ''}>Sudan (+249)</option>
                    <option value="189" ${countryCodeStr === '189' ? 'selected' : ''}>Suriname (+597)</option>
                    <option value="190" ${countryCodeStr === '190' ? 'selected' : ''}>Swaziland (+268)</option>
                    <option value="191" ${countryCodeStr === '191' ? 'selected' : ''}>Sweden (+46)</option>
                    <option value="192" ${countryCodeStr === '192' ? 'selected' : ''}>Switzerland (+41)</option>
                    <option value="193" ${countryCodeStr === '193' ? 'selected' : ''}>Syria (+963)</option>
                    <option value="194" ${countryCodeStr === '194' ? 'selected' : ''}>Taiwan (+886)</option>
                    <option value="195" ${countryCodeStr === '195' ? 'selected' : ''}>Tajikistan (+992)</option>
                    <option value="196" ${countryCodeStr === '196' ? 'selected' : ''}>Tanzania (+255)</option>
                    <option value="197" ${countryCodeStr === '197' ? 'selected' : ''}>Thailand (+66)</option>
                    <option value="198" ${countryCodeStr === '198' ? 'selected' : ''}>Timor-Leste (+670)</option>
                    <option value="199" ${countryCodeStr === '199' ? 'selected' : ''}>Togo (+228)</option>
                    <option value="200" ${countryCodeStr === '200' ? 'selected' : ''}>Tonga (+676)</option>
                    <option value="201" ${countryCodeStr === '201' ? 'selected' : ''}>Trinidad & Tobago (+1868)</option>
                    <option value="202" ${countryCodeStr === '202' ? 'selected' : ''}>Tunisia (+216)</option>
                    <option value="203" ${countryCodeStr === '203' ? 'selected' : ''}>Turkey (+90)</option>
                    <option value="204" ${countryCodeStr === '204' ? 'selected' : ''}>Turkmenistan (+993)</option>
                    <option value="206" ${countryCodeStr === '206' ? 'selected' : ''}>Tuvalu (+688)</option>
                    <option value="207" ${countryCodeStr === '207' ? 'selected' : ''}>Uganda (+256)</option>
                    <option value="208" ${countryCodeStr === '208' ? 'selected' : ''}>Ukraine (+380)</option>
                    <option value="209" ${countryCodeStr === '209' ? 'selected' : ''}>United Arab Emirates (+971)</option>
                    <option value="212" ${countryCodeStr === '212' ? 'selected' : ''}>Uruguay (+598)</option>
                    <option value="213" ${countryCodeStr === '213' ? 'selected' : ''}>Uzbekistan (+998)</option>
                    <option value="214" ${countryCodeStr === '214' ? 'selected' : ''}>Vanuatu (+678)</option>
                    <option value="215" ${countryCodeStr === '215' ? 'selected' : ''}>Vatican City (+379)</option>
                    <option value="216" ${countryCodeStr === '216' ? 'selected' : ''}>Venezuela (+58)</option>
                    <option value="217" ${countryCodeStr === '217' ? 'selected' : ''}>Vietnam (+84)</option>
                    <option value="218" ${countryCodeStr === '218' ? 'selected' : ''}>Virgin Islands US (+1340)</option>
                    <option value="219" ${countryCodeStr === '219' ? 'selected' : ''}>Yemen (+967)</option>
                    <option value="220" ${countryCodeStr === '220' ? 'selected' : ''}>Zambia (+260)</option>
                    <option value="221" ${countryCodeStr === '221' ? 'selected' : ''}>Zimbabwe (+263)</option>
                </select>
            </td>
            <td>
                <input type="number" name="country_students[]" class="form-control student-count" 
                       value="${studentCountNum}" min="0">
            </td>
            <td>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeCountryRow(this)">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;

        countryTableBody.appendChild(row);

        // Add event listeners for calculation
        const studentCountInput = row.querySelector('.student-count');
        studentCountInput.addEventListener('input', calculateTotalStudents);

        // Add validation to prevent empty country selection
        const countrySelect = row.querySelector('.country-code');
        countrySelect.addEventListener('change', function() {
            if (this.value === '') {
                this.setCustomValidity('Please select a country');
            } else {
                this.setCustomValidity('');
            }
        });
    }

    // Remove country row function
    function removeCountryRow(button) {
        const row = button.closest('tr');
        row.remove();
        calculateTotalStudents();
    }

    // Load country data for existing record
    function loadCountryData(recordId) {
        // Fetch country data via AJAX
        fetch(`IntakeActualStrength.php?action=get_country_data&record_id=${recordId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.country_data) {
                    // Clear existing country rows
                    const countryTableBody = document.querySelector('#countryTable tbody');
                    if (countryTableBody) {
                        countryTableBody.innerHTML = '';
                    }

                    // Add country data rows
                    data.country_data.forEach(country => {
                        // Use the correct field name from database - convert to string for comparison
                        const countryCode = String(country.COUNTRY_CODE || country.country_code || '');
                        const studentCount = parseInt(country.NO_OF_STUDENT_COUNTRY || country.student_count || 0);
                        if (countryCode && countryCode !== '0' && countryCode !== '') {
                            addCountryRow(countryCode, studentCount);
                            console.log('[Intake] Loading country row - Code:', countryCode, 'Count:', studentCount);
                        }
                    });

                    if (data.country_data.length > 0) {
                        console.log('[Intake] Loaded ' + data.country_data.length + ' country entries');
                    }

                    // Update totals
                    calculateTotalStudents();
                }
            })
            .catch(error => {
                console.error('Error loading country data:', error);
                console.error('Error details:', error.message);
                // Don't show error to user as this is not critical
            });
    }

    // Update button visibility based on form state
    function updateButtonVisibility(state) {
        const buttonContainer = document.querySelector('.d-flex.flex-wrap.justify-content-center.gap-3');
        if (!buttonContainer) return;

        // Clear existing buttons
        buttonContainer.innerHTML = '';

        if (state === 'new') {
            buttonContainer.innerHTML = `
                <button type="submit" name="submit" class="btn btn-primary btn-lg px-5">
                    <i class="fas fa-paper-plane me-2"></i>Submit Data
                </button>
            `;
        } else if (state === 'edit') {
            const recordId = document.querySelector('input[name="record_id"]')?.value || '';
            buttonContainer.innerHTML = `
                <button type="submit" name="submit" class="btn btn-success btn-lg px-5">
                    <i class="fas fa-save me-2"></i>Update Data
                </button>
                <button type="button" class="btn btn-danger btn-lg px-5" onclick="clearAllData(${recordId})">
                    <i class="fas fa-trash me-2"></i>Clear Data
                </button>
                <a href="IntakeActualStrength.php" class="btn btn-secondary btn-lg px-5">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
            `;
        } else if (state === 'locked') {
            const recordId = document.querySelector('input[name="record_id"]')?.value || '';
            const programName = document.querySelector('#program_select option:checked')?.textContent || 'N/A';
            buttonContainer.innerHTML = `
                <div class="alert alert-success mb-3">
                    <h5><i class="fas fa-lock me-2"></i>Data Successfully Submitted!</h5>
                    <p class="mb-0">Program: <strong>${programName}</strong></p>
                </div>
                <a href="IntakeActualStrength.php?action=edit&ID=${recordId}" class="btn btn-warning btn-lg px-5">
                    <i class="fas fa-edit me-2"></i>Update Data
                </a>
                <button type="button" class="btn btn-danger btn-lg px-5" onclick="clearAllData(${recordId})">
                    <i class="fas fa-trash me-2"></i>Clear Data
                </button>
                <a href="IntakeActualStrength.php" class="btn btn-secondary btn-lg px-5">
                    <i class="fas fa-plus me-2"></i>Add New Program
                </a>
            `;
        }
    }

    // Set form state
    function setFormState(state) {
        const allInputs = document.querySelectorAll('input[type="number"], input[type="text"]');
        const fileInputs = document.querySelectorAll('input[type="file"]');
        const uploadButtons = document.querySelectorAll('button[onclick*="upload"]');

        if (state === 'locked') {
            // Lock all fields
            allInputs.forEach(input => {
                if (!input.hasAttribute('readonly')) {
                    input.setAttribute('readonly', 'readonly');
                }
            });

            fileInputs.forEach(input => {
                input.disabled = true;
            });

            uploadButtons.forEach(button => {
                button.disabled = true;
            });
        } else {
            // Unlock all fields
            allInputs.forEach(input => {
                input.removeAttribute('readonly');
            });

            fileInputs.forEach(input => {
                input.disabled = false;
            });

            uploadButtons.forEach(button => {
                button.disabled = false;
            });
        }
    }

    // Enhanced validation function for student counts
    function validateStudentCount(maleStudents = null, femaleStudents = null, otherStudents = null) {
        // Get values from parameters or calculate from totals
        if (maleStudents === null) {
            maleStudents = parseInt(document.getElementById('total_male_students').textContent) || 0;
        }
        if (femaleStudents === null) {
            femaleStudents = parseInt(document.getElementById('total_female_students').textContent) || 0;
        }
        if (otherStudents === null) {
            otherStudents = parseInt(document.getElementById('total_other_students').textContent) || 0;
        }

        const intakeCapacity = parseInt(document.getElementById('intake_capacity').value) || 0;
        const totalStudents = maleStudents + femaleStudents + otherStudents;

        if (intakeCapacity > 0) {
            if (totalStudents > intakeCapacity) {
                // Show error message
                const errorMsg = `Total students (${totalStudents}) cannot exceed enrolment capacity (${intakeCapacity})`;

                // Add red border styling to all input fields
                const allInputs = document.querySelectorAll('input[name*="Male_Students"], input[name*="Female_Students"], input[name*="Other_Students"]');
                allInputs.forEach(input => {
                    input.style.borderColor = '#dc3545';
                    input.style.borderWidth = '3px';
                    input.style.boxShadow = '0 0 10px rgba(220, 53, 69, 0.5)';
                    input.style.animation = 'shake 0.5s';
                });

                setTimeout(() => {
                    allInputs.forEach(input => {
                        input.style.animation = '';
                    });
                }, 500);

                // Show error message
                let errorDiv = document.getElementById('student-count-error');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.id = 'student-count-error';
                    errorDiv.className = 'alert alert-danger';
                    errorDiv.style.marginTop = '10px';
                    document.querySelector('.form-section').appendChild(errorDiv);
                }
                errorDiv.innerHTML = `<strong>Validation Error:</strong> ${errorMsg}`;
                errorDiv.style.display = 'block';
            } else {
                // Reset border colors
                const allInputs = document.querySelectorAll('input[name*="Male_Students"], input[name*="Female_Students"], input[name*="Other_Students"]');
                allInputs.forEach(input => {
                    input.style.borderColor = '';
                    input.style.borderWidth = '';
                    input.style.boxShadow = '';
                });

                // Hide error message
                const errorDiv = document.getElementById('student-count-error');
                if (errorDiv) {
                    errorDiv.style.display = 'none';
                }
            }
        }
    }

    // Document upload functions - Clean and unified
    function uploadESCSDocument() {
        uploadDocument('escs_diversity', 1);
    }

    function uploadScholarshipDocument() {
        uploadDocument('scholarship_freeship', 2);
    }

    function uploadTotalIntakeProofDocument() {
        uploadDocument('total_intake_proof', 3);
    }

    function uploadRegionalDocument() {
        uploadDocument('regional_diversity', 4);
    }

    // CRITICAL: Rename to avoid conflict with deleteDocumentByFileId function
    function deleteDocumentById(documentId) {
        // Convert to string for consistent handling
        const docIdStr = String(documentId);
        
        // Handle both valid document IDs and temporary IDs (from upload before form submission)
        if (!documentId || documentId === 0 || documentId === '0' || docIdStr.startsWith('temp_')) {
            // Temporary document - just clear from UI
            console.log('[Intake] Removing temporary document from UI');
            const statusDivs = document.querySelectorAll('[id*="_status"]');
            statusDivs.forEach(div => {
                // Check for both quoted and unquoted documentId in onclick
                const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                if (deleteBtn || (div.textContent && div.textContent.includes('Document uploaded'))) {
                    div.innerHTML = '<span class="text-muted">No document uploaded</span>';
                    div.className = 'mt-2 text-muted';
                }
            });
            showNotification('Document removed successfully', 'success');
            return;
        }
        
        if (!confirm('Are you sure you want to delete this document?')) {
            return;
        }
        
        // Show loading state
        const statusDivs = document.querySelectorAll('[id*="_status"]');
        statusDivs.forEach(div => {
            // Check for both quoted and unquoted documentId in onclick
            const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
            if (deleteBtn) {
                deleteBtn.disabled = true;
                deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            }
        });
        
        // Convert to integer for server request - handle string documentIds
        let docIdInt;
        if (typeof documentId === 'string' && documentId.trim() !== '') {
            docIdInt = parseInt(documentId.trim(), 10);
        } else if (typeof documentId === 'number') {
            docIdInt = Math.floor(documentId);
        } else {
            console.error('[Intake] Invalid documentId type:', typeof documentId, documentId);
            showNotification('Invalid document ID: ' + documentId, 'error');
            // Re-enable buttons
            statusDivs.forEach(div => {
                const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                }
            });
            return;
        }
        
        if (isNaN(docIdInt) || docIdInt <= 0) {
            console.error('[Intake] Invalid documentId value:', documentId, '->', docIdInt);
            showNotification('Invalid document ID: ' + documentId, 'error');
            // Re-enable buttons
            statusDivs.forEach(div => {
                const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                }
            });
            return;
        }
        
        fetch('?delete_document=1&document_id=' + docIdInt)
            .catch(error => {
                // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
                console.error('[Intake] Fetch error for delete:', error);
                return { ok: false, text: () => Promise.resolve(JSON.stringify({ success: false, message: 'Network error: ' + (error.message || 'Failed to connect to server') })) };
            })
            .then(response => {
                // CRITICAL: Ensure response is valid before processing
                if (!response || typeof response.text !== 'function') {
                    console.error('[Intake] Invalid response object:', response);
                    return { success: false, message: 'Delete failed: Invalid server response' };
                }
                
                // CRITICAL #3: Handle empty responses in JS - return object, don't throw
                return response.text().then(text => {
                    const trimmed = text.trim();
                    
                    // If empty response, return error object instead of throwing
                    if (!trimmed || trimmed === '') {
                        console.warn('[Intake] Empty response received for delete');
                        return { success: false, message: 'Delete failed: Empty server response' };
                    }
                    
                    // Try to parse as JSON
                    try {
                        return JSON.parse(trimmed);
                    } catch (e) {
                        console.error('[Intake] Failed to parse JSON response for delete:', e);
                        console.error('[Intake] Response text:', trimmed.substring(0, 200));
                        // CRITICAL #5: Return error response instead of throwing
                        return { success: false, message: 'Delete failed: Invalid server response' };
                    }
                }).catch(textError => {
                    // CRITICAL: Handle text() parsing errors
                    console.error('[Intake] Error reading response text:', textError);
                    return { success: false, message: 'Delete failed: Error reading server response' };
                });
            })
                .then(data => {
                // Re-enable delete buttons
                statusDivs.forEach(div => {
                    // Check for both quoted and unquoted documentId in onclick
                    const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                    if (deleteBtn) {
                        deleteBtn.disabled = false;
                        deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                    }
                });
                
                // CRITICAL #3: Handle null/undefined data gracefully
                if (!data || typeof data !== 'object') {
                    console.error('[Intake] Invalid data structure received for delete:', data);
                    showNotification('Delete failed: Invalid server response', 'error');
                    return;
                }
                
                    if (data.success) {
                        showNotification('Document deleted successfully!', 'success');
                    
                    // Remove the document from the UI
                    statusDivs.forEach(div => {
                        // Check for both quoted and unquoted documentId in onclick
                        const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                        if (deleteBtn || (div.textContent && div.textContent.includes('Document uploaded'))) {
                            div.innerHTML = '<span class="text-muted">No document uploaded</span>';
                            div.className = 'mt-2 text-muted';
                        }
                    });
                    
                    // Optionally reload after a delay
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                    // Show message from server if available, otherwise default message
                    const message = data.message || 'Delete failed. Please try again.';
                    showNotification('Delete failed: ' + message, 'error');
                    }
                })
                .catch(error => {
                // Re-enable delete buttons on error
                statusDivs.forEach(div => {
                    // Check for both quoted and unquoted documentId in onclick
                    const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                    if (deleteBtn) {
                        deleteBtn.disabled = false;
                        deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                    }
                });
                
                // CRITICAL #5: Handle errors gracefully - return object, don't throw
                console.error('[Intake] Delete error in promise chain:', error);
                
                // Show error notification
                const errorMsg = error && error.message ? error.message : 'Unknown error';
                showNotification('Delete failed: ' + errorMsg, 'error');
                
                // Return resolved promise to prevent unhandled promise rejection
                return Promise.resolve({ success: false, message: 'Delete failed: ' + errorMsg });
                });
    }

    // Enhanced notification system
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            max-width: 300px;
        `;

        // Set background color based on type
        const colors = {
            success: 'linear-gradient(135deg, #28a745, #20c997)',
            error: 'linear-gradient(135deg, #dc3545, #e74c3c)',
            warning: 'linear-gradient(135deg, #ffc107, #f39c12)',
            info: 'linear-gradient(135deg, #17a2b8, #3498db)'
        };
        notification.style.background = colors[type] || colors.info;

        notification.innerHTML = `
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; margin-left: auto;">×</button>
            </div>
        `;

        // Add to page
        document.body.appendChild(notification);

        // Animate in
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);

        // Auto remove after 3 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }
        }, 3000);
    }
</script>

<!-- Edit Form (Hidden by default) -->
<div class="card" id="editForm" style="display: none;">
    <div class="card-body">
        <h3 class="fs-4 mb-3 text-center"><b>Edit Enrolment & Actual Strength</b></h3>

        <form class="modern-form" method="POST" enctype="multipart/form-data" autocomplete="off" id="editIntakeForm">
            <input type="hidden" id="edit_record_id" name="edit_record_id" value="">

            <!-- Program Selection Section (Read-only) -->
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-graduation-cap me-2"></i>Program Information (Read-only)
                </h3>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label"><b>Program Name</b></label>
                            <input type="text" id="edit_program_name" class="form-control" readonly style="background-color: #f8f9fa;">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label"><b>Program Code</b></label>
                            <input type="text" id="edit_program_code" class="form-control" readonly style="background-color: #f8f9fa;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Student Statistics Section -->
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-chart-bar me-2"></i>Student Statistics
                </h3>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label"><b>Total Student Enrolment *</b></label>
                            <input type="number" id="edit_total_student_intake" name="edit_total_student_intake" class="form-control" placeholder="Enter Count" min="0" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label"><b>Male Students *</b></label>
                            <input type="number" id="edit_male_students" name="edit_male_students" class="form-control" placeholder="Enter Count" min="0" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label"><b>Female Students *</b></label>
                            <input type="number" id="edit_female_students" name="edit_female_students" class="form-control" placeholder="Enter Count" min="0" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label"><b>Other Students *</b></label>
                            <input type="number" id="edit_other_students" name="edit_other_students" class="form-control" placeholder="Enter Count" min="0" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="text-center mt-4">
                <div class="d-flex flex-wrap justify-content-center gap-3">
                    <button type="button" class="btn btn-success btn-lg px-5" onclick="updateRecord()">
                        <i class="fas fa-save me-2"></i>Update Record
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg px-5" onclick="cancelEdit()">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Show Existing Data -->
<div class="row my-5" id="existing_data_section">
    <h3 class="fs-4 mb-3 text-center" id="data_summary_title"><b>Existing Data Records</b></h3>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Note:</strong> This table shows all existing data records. To add new data, select a program above and fill the form.
    </div>
    <div class="col-12">
        <div class="table-responsive" style="max-width: 100%; overflow-x: auto;">
            <table class="table bg-white rounded shadow-sm table-hover" style="min-width: 2000px;">
                <thead>
                    <tr>
                        <th scope="col">Program Code</th>
                        <th scope="col">Program Name</th>
                        <th scope="col">Number of Students Intake</th>
                        <th scope="col">MALE Students</th>
                        <th scope="col">FEMALE Students</th>
                        <th scope="col">MALE Students within state</th>
                        <th scope="col">FEMALE Students within state</th>
                        <th scope="col">MALE Students outside state</th>
                        <th scope="col">FEMALE Students outside state</th>
                        <th scope="col">OTHER Students</th>
                        <th scope="col">OTHER Students within state</th>
                        <th scope="col">OTHER Students outside state</th>
                        <th scope="col">MALE Students outside country</th>
                        <th scope="col">FEMALE Students outside country</th>
                        <th scope="col">OTHER Students outside country</th>
                        <th scope="col">Country-wise Details</th>
                        <th scope="col">MALE Students Economic Backward</th>
                        <th scope="col">FEMALE Students Economic Backward</th>
                        <th scope="col">OTHER Students Economic Backward</th>
                        <th scope="col">MALE Students General</th>
                        <th scope="col">FEMALE Students General</th>
                        <th scope="col">OTHER Students General</th>
                        <th scope="col">MALE Students Social Backward(SC)</th>
                        <th scope="col">FEMALE Students Social Backward(SC)</th>
                        <th scope="col">OTHER Students Social Backward(SC)</th>
                        <th scope="col">MALE Students Social Backward(ST)</th>
                        <th scope="col">FEMALE Students Social Backward(ST)</th>
                        <th scope="col">OTHER Students Social Backward(ST)</th>
                        <th scope="col">MALE Students Social Backward(OBC)</th>
                        <th scope="col">FEMALE Students Social Backward(OBC)</th>
                        <th scope="col">OTHER Students Social Backward(OBC)</th>
                        <th scope="col">MALE Students Social Backward(DTA)</th>
                        <th scope="col">FEMALE Students Social Backward(DTA)</th>
                        <th scope="col">OTHER Students Social Backward(DTA)</th>
                        <th scope="col">MALE Students Social Backward(NTB)</th>
                        <th scope="col">FEMALE Students Social Backward(NTB)</th>
                        <th scope="col">OTHER Students Social Backward(NTB)</th>
                        <th scope="col">MALE Students Social Backward(NTC)</th>
                        <th scope="col">FEMALE Students Social Backward(NTC)</th>
                        <th scope="col">OTHER Students Social Backward(NTC)</th>
                        <th scope="col">MALE Students Social Backward(NTD)</th>
                        <th scope="col">FEMALE Students Social Backward(NTD)</th>
                        <th scope="col">OTHER Students Social Backward(NTD)</th>
                        <th scope="col">MALE Students Social Backward(EWS)</th>
                        <th scope="col">FEMALE Students Social Backward(EWS)</th>
                        <th scope="col">OTHER Students Social Backward(EWS)</th>
                        <th scope="col">MALE Students Social Backward(SEBC)</th>
                        <th scope="col">FEMALE Students Social Backward(SEBC)</th>
                        <th scope="col">OTHER Students Social Backward(SEBC)</th>
                        <th scope="col">MALE Students Social Backward(SBC)</th>
                        <th scope="col">FEMALE Students Social Backward(SBC)</th>
                        <th scope="col">OTHER Students Social Backward(SBC)</th>
                        <th scope="col">MALE Students Physically Handicapped</th>
                        <th scope="col">FEMALE Students Physically Handicapped</th>
                        <th scope="col">OTHER Students Physically Handicapped</th>
                        <th scope="col">MALE Students TGO</th>
                        <th scope="col">FEMALE Students TGO</th>
                        <th scope="col">OTHER Students TGO</th>
                        <th scope="col">MALE Students CMIL</th>
                        <th scope="col">FEMALE Students CMIL</th>
                        <th scope="col">OTHER Students CMIL</th>
                        <th scope="col">MALE Students SPCUL</th>
                        <th scope="col">FEMALE Students SPCUL</th>
                        <th scope="col">OTHER Students SPCUL</th>
                        <th scope="col">MALE Students Widow/Single Mother</th>
                        <th scope="col">FEMALE Students Widow/Single Mother</th>
                        <th scope="col">OTHER Students Widow/Single Mother</th>
                        <th scope="col">MALE Students ES</th>
                        <th scope="col">FEMALE Students ES</th>
                        <th scope="col">OTHER Students ES</th>

                        <th scope="col">MALE Students Receiving scholarship from Government</th>
                        <th scope="col">FEMALE Students Receiving scholarship from Government</th>
                        <th scope="col">OTHER Students Receiving scholarship from Government</th>
                        <th scope="col">MALE Students Receiving scholarship from Institution</th>
                        <th scope="col">FEMALE Students Receiving scholarship from Institution</th>
                        <th scope="col">OTHER Students Receiving scholarship from Institution</th>
                        <th scope="col">MALE Students Receiving scholarship from private Bodies</th>
                        <th scope="col">FEMALE Students Receiving scholarship from private Bodies</th>
                        <th scope="col">OTHER Students Receiving scholarship from private Bodies</th>
                        <th scope="col">ESCS Diversity Document</th>
                        <th scope="col">Scholarship Document</th>
                        <th scope="col">Regional Diversity Document</th>
                        <th scope="col">Total Intake Proof Document</th>
                        <th scope="col">Edit</th>
                        <th scope="col">Delete</th>

                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Helper function to get document and build view button path
                    // SIMPLIFIED: Tries multiple query methods to find documents
                    $get_document_for_table = function($serial_number, $program_code_table, $dept, $A_YEAR) use ($conn) {
                        if (empty($program_code_table)) {
                            return null;
                        }
                        
                        $program_code_table = trim((string)$program_code_table);
                        $unique_section_name = 'Intake Actual Strength_PROG_' . $program_code_table;
                        $project_root = dirname(__DIR__);
                        
                        // Method 1: Try unique section_name with academic_year
                        $query1 = "SELECT file_path, file_name FROM supporting_documents 
                            WHERE dept_id = ? 
                            AND page_section = 'intake_actual_strength' 
                            AND serial_number = ? 
                            AND section_name = ?
                            AND academic_year = ? 
                            AND status = 'active' 
                            ORDER BY id DESC LIMIT 1";
                        $stmt1 = mysqli_prepare($conn, $query1);
                        if ($stmt1) {
                            mysqli_stmt_bind_param($stmt1, "iiss", $dept, $serial_number, $unique_section_name, $A_YEAR);
                            if (mysqli_stmt_execute($stmt1)) {
                                $result1 = mysqli_stmt_get_result($stmt1);
                                if ($result1 && mysqli_num_rows($result1) > 0) {
                                    $doc = mysqli_fetch_assoc($result1);
                                    mysqli_free_result($result1);
                                    mysqli_stmt_close($stmt1);
                                    // Build path and return
                                    $doc_path = $doc['file_path'];
                                    $doc_path = str_replace('\\', '/', trim($doc_path));
                                    if (strpos($doc_path, $project_root) === 0) {
                                        $doc_path = str_replace([$project_root . '/', $project_root . '\\'], '', $doc_path);
                                    }
                                    $doc_path = str_replace('\\', '/', $doc_path);
                                    $doc_path = ltrim($doc_path, './\\/');
                                    if (strpos($doc_path, 'uploads/') === 0) {
                                        $doc_path = '../' . $doc_path;
                                    } elseif (strpos($doc_path, '../') !== 0 && strpos($doc_path, 'http') !== 0) {
                                        $filename = basename($doc_path);
                                        $doc_path = '../uploads/' . $A_YEAR . '/DEPARTMENT/' . $dept . '/intake_actual_strength/' . $program_code_table . '/' . $filename;
                                    }
                                    return ['path' => $doc_path, 'name' => $doc['file_name'] ?? basename($doc_path)];
                                }
                                mysqli_free_result($result1);
                            }
                            mysqli_stmt_close($stmt1);
                        }
                        
                        // Method 2: Try program_id with academic_year
                        $query2 = "SELECT file_path, file_name FROM supporting_documents 
                            WHERE dept_id = ? 
                            AND page_section = 'intake_actual_strength' 
                            AND serial_number = ? 
                            AND program_id = ?
                            AND academic_year = ? 
                            AND status = 'active' 
                            ORDER BY id DESC LIMIT 1";
                        $stmt2 = mysqli_prepare($conn, $query2);
                        if ($stmt2) {
                            mysqli_stmt_bind_param($stmt2, "iiss", $dept, $serial_number, $program_code_table, $A_YEAR);
                            if (mysqli_stmt_execute($stmt2)) {
                                $result2 = mysqli_stmt_get_result($stmt2);
                                if ($result2 && mysqli_num_rows($result2) > 0) {
                                    $doc = mysqli_fetch_assoc($result2);
                                    mysqli_free_result($result2);
                                    mysqli_stmt_close($stmt2);
                                    // Build path and return
                                    $doc_path = $doc['file_path'];
                                    $doc_path = str_replace('\\', '/', trim($doc_path));
                                    if (strpos($doc_path, $project_root) === 0) {
                                        $doc_path = str_replace([$project_root . '/', $project_root . '\\'], '', $doc_path);
                                    }
                                    $doc_path = str_replace('\\', '/', $doc_path);
                                    $doc_path = ltrim($doc_path, './\\/');
                                    if (strpos($doc_path, 'uploads/') === 0) {
                                        $doc_path = '../' . $doc_path;
                                    } elseif (strpos($doc_path, '../') !== 0 && strpos($doc_path, 'http') !== 0) {
                                        $filename = basename($doc_path);
                                        $doc_path = '../uploads/' . $A_YEAR . '/DEPARTMENT/' . $dept . '/intake_actual_strength/' . $program_code_table . '/' . $filename;
                                    }
                                    return ['path' => $doc_path, 'name' => $doc['file_name'] ?? basename($doc_path)];
                                }
                                mysqli_free_result($result2);
                            }
                            mysqli_stmt_close($stmt2);
                        }
                        
                        // Method 3: Try unique section_name without academic_year
                        $query3 = "SELECT file_path, file_name FROM supporting_documents 
                            WHERE dept_id = ? 
                            AND page_section = 'intake_actual_strength' 
                            AND serial_number = ? 
                            AND section_name = ?
                            AND status = 'active' 
                            ORDER BY id DESC LIMIT 1";
                        $stmt3 = mysqli_prepare($conn, $query3);
                        if ($stmt3) {
                            mysqli_stmt_bind_param($stmt3, "iis", $dept, $serial_number, $unique_section_name);
                            if (mysqli_stmt_execute($stmt3)) {
                                $result3 = mysqli_stmt_get_result($stmt3);
                                if ($result3 && mysqli_num_rows($result3) > 0) {
                                    $doc = mysqli_fetch_assoc($result3);
                                    mysqli_free_result($result3);
                                    mysqli_stmt_close($stmt3);
                                    // Build path and return
                                    $doc_path = $doc['file_path'];
                                    $doc_path = str_replace('\\', '/', trim($doc_path));
                                    if (strpos($doc_path, $project_root) === 0) {
                                        $doc_path = str_replace([$project_root . '/', $project_root . '\\'], '', $doc_path);
                                    }
                                    $doc_path = str_replace('\\', '/', $doc_path);
                                    $doc_path = ltrim($doc_path, './\\/');
                                    if (strpos($doc_path, 'uploads/') === 0) {
                                        $doc_path = '../' . $doc_path;
                                    } elseif (strpos($doc_path, '../') !== 0 && strpos($doc_path, 'http') !== 0) {
                                        $filename = basename($doc_path);
                                        $doc_path = '../uploads/' . $A_YEAR . '/DEPARTMENT/' . $dept . '/intake_actual_strength/' . $program_code_table . '/' . $filename;
                                    }
                                    return ['path' => $doc_path, 'name' => $doc['file_name'] ?? basename($doc_path)];
                                }
                                mysqli_free_result($result3);
                            }
                            mysqli_stmt_close($stmt3);
                        }
                        
                        // Method 4: Try program_id without academic_year (final fallback)
                        $query4 = "SELECT file_path, file_name FROM supporting_documents 
                            WHERE dept_id = ? 
                            AND page_section = 'intake_actual_strength' 
                            AND serial_number = ? 
                            AND program_id = ?
                            AND status = 'active' 
                            ORDER BY id DESC LIMIT 1";
                        $stmt4 = mysqli_prepare($conn, $query4);
                        if ($stmt4) {
                            mysqli_stmt_bind_param($stmt4, "iis", $dept, $serial_number, $program_code_table);
                            if (mysqli_stmt_execute($stmt4)) {
                                $result4 = mysqli_stmt_get_result($stmt4);
                                if ($result4 && mysqli_num_rows($result4) > 0) {
                                    $doc = mysqli_fetch_assoc($result4);
                                    mysqli_free_result($result4);
                                    mysqli_stmt_close($stmt4);
                                    // Build path and return
                                    $doc_path = $doc['file_path'];
                                    $doc_path = str_replace('\\', '/', trim($doc_path));
                                    if (strpos($doc_path, $project_root) === 0) {
                                        $doc_path = str_replace([$project_root . '/', $project_root . '\\'], '', $doc_path);
                                    }
                                    $doc_path = str_replace('\\', '/', $doc_path);
                                    $doc_path = ltrim($doc_path, './\\/');
                                    if (strpos($doc_path, 'uploads/') === 0) {
                                        $doc_path = '../' . $doc_path;
                                    } elseif (strpos($doc_path, '../') !== 0 && strpos($doc_path, 'http') !== 0) {
                                        $filename = basename($doc_path);
                                        $doc_path = '../uploads/' . $A_YEAR . '/DEPARTMENT/' . $dept . '/intake_actual_strength/' . $program_code_table . '/' . $filename;
                                    }
                                    return ['path' => $doc_path, 'name' => $doc['file_name'] ?? basename($doc_path)];
                                }
                                mysqli_free_result($result4);
                            }
                            mysqli_stmt_close($stmt4);
                        }
                        
                        return null;
                    };
                    
                    $Record = "SELECT * FROM intake_actual_strength WHERE DEPT_ID = ?";
                    $stmt = mysqli_prepare($conn, $Record);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "i", $dept);
                        if (mysqli_stmt_execute($stmt)) {
                            $Record = mysqli_stmt_get_result($stmt);
                            if ($Record) {
                                while ($row = mysqli_fetch_array($Record)) {
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['PROGRAM_CODE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['PROGRAM_NAME'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $row['Add_Total_Student_Intake'] ?? 0; ?></td>
                            <td><?php echo $row['Total_number_of_Male_Students'] ?? 0; ?></td>
                            <td><?php echo $row['Total_number_of_Female_Students'] ?? 0; ?></td>
                            <td><?php echo $row['Total_number_of_Male_Students_within_state'] ?? 0; ?></td>
                            <td><?php echo $row['Total_number_of_Female_Students_within_state'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Students_outside_state'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Students_outside_state'] ?? 0; ?></td>
                            <td><?php echo $row['Total_number_of_Other_Students'] ?? 0; ?></td>
                            <td><?php echo $row['Total_number_of_Other_Students_within_state'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Students_outside_state'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Students_outside_country'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Students_outside_country'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Students_outside_country'] ?? 0; ?></td>
                            <td>
                                <?php
                                // Get country-wise data for this program
                                // Convert PROGRAM_CODE to integer (country_wise_student.PROGRAM_CODE is int(11))
                                $display_program_code = $row['PROGRAM_CODE'];
                                if (is_numeric($display_program_code)) {
                                    $display_program_code_int = (int) $display_program_code;
                                } else {
                                    // Extract numeric part from alphanumeric codes (e.g., "msc1012" -> 1012)
                                    preg_match('/\d+/', $display_program_code, $matches);
                                    $display_program_code_int = !empty($matches) ? (int) $matches[0] : 0;
                                }
                                
                                $country_query = "SELECT * FROM `country_wise_student` WHERE `DEPT_ID` = ? AND `PROGRAM_CODE` = ? AND `A_YEAR` = ?";
                                $country_stmt = mysqli_prepare($conn, $country_query);
                                $country_data = [];
                                if ($country_stmt) {
                                    // Bind parameters: DEPT_ID (i), PROGRAM_CODE (i), A_YEAR (s)
                                    mysqli_stmt_bind_param($country_stmt, "iis", $dept, $display_program_code_int, $row['A_YEAR']);
                                    if (mysqli_stmt_execute($country_stmt)) {
                                        $country_result = mysqli_stmt_get_result($country_stmt);
                                        if ($country_result) {
                                            while ($country_row = mysqli_fetch_array($country_result)) {
                                                $country_data[] = $country_row;
                                            }
                                            mysqli_free_result($country_result); // CRITICAL: Free result set
                                        }
                                    }
                                    mysqli_stmt_close($country_stmt); // CRITICAL: Close statement
                                }

                                // Country code to name mapping (matching the JavaScript options)
                                // CRITICAL: Includes both sequential codes (101-221) and actual calling codes for backward compatibility
                                $country_names = [
                                    '44' => 'UK (+44)',
                                    '1' => 'USA (+1)',
                                    '47' => 'Norway (+47)',
                                    // Sequential codes (101-221) used in dropdown
                                    '101' => 'Kiribati (+686)',
                                    '103' => 'Kuwait (+965)',
                                    '104' => 'Kyrgyzstan (+996)',
                                    '105' => 'Laos (+856)',
                                    '106' => 'Latvia (+371)',
                                    '107' => 'Lebanon (+961)',
                                    '108' => 'Lesotho (+266)',
                                    '109' => 'Liberia (+231)',
                                    '110' => 'Libya (+218)',
                                    '111' => 'Liechtenstein (+423)',
                                    '112' => 'Lithuania (+370)',
                                    '113' => 'Luxembourg (+352)',
                                    '114' => 'Macau (+853)',
                                    '115' => 'Madagascar (+261)',
                                    '116' => 'Malawi (+265)',
                                    '117' => 'Malaysia (+60)',
                                    '118' => 'Maldives (+960)',
                                    '119' => 'Mali (+223)',
                                    '120' => 'Malta (+356)',
                                    '121' => 'Marshall Islands (+692)',
                                    '122' => 'Mauritania (+222)',
                                    '123' => 'Mauritius (+230)',
                                    '124' => 'Mexico (+52)',
                                    '125' => 'Micronesia (+691)',
                                    '126' => 'Moldova (+373)',
                                    '127' => 'Monaco (+377)',
                                    '128' => 'Mongolia (+976)',
                                    '129' => 'Montenegro (+382)',
                                    '130' => 'Morocco (+212)',
                                    '131' => 'Mozambique (+258)',
                                    '132' => 'Myanmar (+95)',
                                    '133' => 'Namibia (+264)',
                                    '134' => 'Nauru (+674)',
                                    '135' => 'Nepal (+977)',
                                    '136' => 'Netherlands (+31)',
                                    '139' => 'New Zealand (+64)',
                                    '140' => 'Nicaragua (+505)',
                                    '141' => 'Niger (+227)',
                                    '142' => 'Nigeria (+234)',
                                    '143' => 'North Korea (+850)',
                                    '144' => 'North Macedonia (+389)',
                                    '146' => 'Northern Mariana Islands (+1670)',
                                    '148' => 'Oman (+968)',
                                    '149' => 'Pakistan (+92)',
                                    '150' => 'Palau (+680)',
                                    '151' => 'Palestine (+970)',
                                    '152' => 'Panama (+507)',
                                    '153' => 'Papua New Guinea (+675)',
                                    '154' => 'Paraguay (+595)',
                                    '155' => 'Peru (+51)',
                                    '156' => 'Philippines (+63)',
                                    '157' => 'Poland (+48)',
                                    '158' => 'Portugal (+351)',
                                    '159' => 'Puerto Rico (+1787)',
                                    '160' => 'Qatar (+974)',
                                    '162' => 'Romania (+40)',
                                    '163' => 'Russia (+7)',
                                    '164' => 'Rwanda (+250)',
                                    '165' => 'Saint Kitts & Nevis (+1869)',
                                    '166' => 'Saint Lucia (+1758)',
                                    '167' => 'Saint Vincent & Grenadines (+1784)',
                                    '169' => 'Samoa (+685)',
                                    '170' => 'San Marino (+378)',
                                    '171' => 'São Tomé & Príncipe (+239)',
                                    '172' => 'Saudi Arabia (+966)',
                                    '173' => 'Senegal (+221)',
                                    '174' => 'Serbia (+381)',
                                    '175' => 'Seychelles (+248)',
                                    '176' => 'Sierra Leone (+232)',
                                    '177' => 'Singapore (+65)',
                                    '179' => 'Slovakia (+421)',
                                    '180' => 'Slovenia (+386)',
                                    '181' => 'Solomon Islands (+677)',
                                    '182' => 'Somalia (+252)',
                                    '183' => 'South Africa (+27)',
                                    '184' => 'South Korea (+82)',
                                    '185' => 'South Sudan (+211)',
                                    '186' => 'Spain (+34)',
                                    '187' => 'Sri Lanka (+94)', // CRITICAL: Sequential code for Sri Lanka
                                    '188' => 'Sudan (+249)',
                                    '189' => 'Suriname (+597)',
                                    '190' => 'Swaziland (+268)',
                                    '191' => 'Sweden (+46)',
                                    '192' => 'Switzerland (+41)',
                                    '193' => 'Syria (+963)',
                                    '194' => 'Taiwan (+886)',
                                    '195' => 'Tajikistan (+992)',
                                    '196' => 'Tanzania (+255)',
                                    '197' => 'Thailand (+66)',
                                    '198' => 'Timor-Leste (+670)',
                                    '199' => 'Togo (+228)',
                                    '200' => 'Tonga (+676)',
                                    '201' => 'Trinidad & Tobago (+1868)',
                                    '202' => 'Tunisia (+216)',
                                    '203' => 'Turkey (+90)',
                                    '204' => 'Turkmenistan (+993)',
                                    '206' => 'Tuvalu (+688)',
                                    '207' => 'Uganda (+256)',
                                    '208' => 'Ukraine (+380)',
                                    '209' => 'United Arab Emirates (+971)',
                                    '212' => 'Uruguay (+598)',
                                    '213' => 'Uzbekistan (+998)',
                                    '214' => 'Vanuatu (+678)',
                                    '215' => 'Vatican City (+379)',
                                    '216' => 'Venezuela (+58)',
                                    '217' => 'Vietnam (+84)',
                                    '218' => 'Virgin Islands US (+1340)',
                                    '219' => 'Yemen (+967)',
                                    '220' => 'Zambia (+260)',
                                    '221' => 'Zimbabwe (+263)',
                                    '5' => 'Andorra (+376)', // Old sequential code
                                    '376' => 'Andorra (+376)',
                                    '213' => 'Algeria (+213)',
                                    '1264' => 'Anguilla (+1264)',
                                    '244' => 'Angola (+244)',
                                    '1268' => 'Antigua & Barbuda (+1268)',
                                    '54' => 'Argentina (+54)',
                                    '374' => 'Armenia (+374)',
                                    '297' => 'Aruba (+297)',
                                    '61' => 'Australia (+61)',
                                    '43' => 'Austria (+43)',
                                    '994' => 'Azerbaijan (+994)',
                                    '1242' => 'Bahamas (+1242)',
                                    '973' => 'Bahrain (+973)',
                                    '880' => 'Bangladesh (+880)',
                                    '1246' => 'Barbados (+1246)',
                                    '375' => 'Belarus (+375)',
                                    '32' => 'Belgium (+32)',
                                    '501' => 'Belize (+501)',
                                    '229' => 'Benin (+229)',
                                    '1441' => 'Bermuda (+1441)',
                                    '975' => 'Bhutan (+975)',
                                    '591' => 'Bolivia (+591)',
                                    '387' => 'Bosnia Herzegovina (+387)',
                                    '267' => 'Botswana (+267)',
                                    '55' => 'Brazil (+55)',
                                    '673' => 'Brunei (+673)',
                                    '359' => 'Bulgaria (+359)',
                                    '226' => 'Burkina Faso (+226)',
                                    '257' => 'Burundi (+257)',
                                    '855' => 'Cambodia (+855)',
                                    '237' => 'Cameroon (+237)',
                                    '238' => 'Cape Verde Islands (+238)',
                                    '1345' => 'Cayman Islands (+1345)',
                                    '236' => 'Central African Republic (+236)',
                                    '56' => 'Chile (+56)',
                                    '86' => 'China (+86)',
                                    '57' => 'Colombia (+57)',
                                    '269' => 'Comoros (+269)',
                                    '242' => 'Congo (+242)',
                                    '682' => 'Cook Islands (+682)',
                                    '506' => 'Costa Rica (+506)',
                                    '385' => 'Croatia (+385)',
                                    '53' => 'Cuba (+53)',
                                    '90392' => 'Cyprus North (+90392)',
                                    '357' => 'Cyprus South (+357)',
                                    '42' => 'Czech Republic (+42)', // Old code (kept for backward compatibility)
                                    '420' => 'Czech Republic (+420)', // Current code
                                    '45' => 'Denmark (+45)',
                                    '253' => 'Djibouti (+253)',
                                    '1767' => 'Dominica (+1767)', // Current code for Dominica
                                    '1809' => 'Dominican Republic (+1809)', // Note: 1809 is for Dominican Republic, not Dominica
                                    '593' => 'Ecuador (+593)',
                                    '20' => 'Egypt (+20)',
                                    '503' => 'El Salvador (+503)',
                                    '240' => 'Equatorial Guinea (+240)',
                                    '291' => 'Eritrea (+291)',
                                    '372' => 'Estonia (+372)',
                                    '251' => 'Ethiopia (+251)',
                                    '500' => 'Falkland Islands (+500)',
                                    '298' => 'Faroe Islands (+298)',
                                    '679' => 'Fiji (+679)',
                                    '358' => 'Finland (+358)',
                                    '33' => 'France (+33)',
                                    '594' => 'French Guiana (+594)',
                                    '689' => 'French Polynesia (+689)',
                                    '241' => 'Gabon (+241)',
                                    '220' => 'Gambia (+220)',
                                    '7880' => 'Georgia (+7880)', // Old code (kept for backward compatibility)
                                    '995' => 'Georgia (+995)', // Current code
                                    '49' => 'Germany (+49)',
                                    '233' => 'Ghana (+233)',
                                    '350' => 'Gibraltar (+350)',
                                    '30' => 'Greece (+30)',
                                    '299' => 'Greenland (+299)',
                                    '1473' => 'Grenada (+1473)',
                                    '590' => 'Guadeloupe (+590)',
                                    '671' => 'Guam (+671)',
                                    '502' => 'Guatemala (+502)',
                                    '224' => 'Guinea (+224)',
                                    '245' => 'Guinea - Bissau (+245)',
                                    '592' => 'Guyana (+592)',
                                    '509' => 'Haiti (+509)',
                                    '504' => 'Honduras (+504)',
                                    '852' => 'Hong Kong (+852)',
                                    '36' => 'Hungary (+36)',
                                    '354' => 'Iceland (+354)',
                                    '62' => 'Indonesia (+62)',
                                    '98' => 'Iran (+98)',
                                    '964' => 'Iraq (+964)',
                                    '353' => 'Ireland (+353)',
                                    '972' => 'Israel (+972)',
                                    '39' => 'Italy (+39)',
                                    '1876' => 'Jamaica (+1876)',
                                    '81' => 'Japan (+81)',
                                    '962' => 'Jordan (+962)',
                                    '7' => 'Kazakhstan (+7)',
                                    '254' => 'Kenya (+254)',
                                    '686' => 'Kiribati (+686)',
                                    '850' => 'Korea North (+850)',
                                    '82' => 'Korea South (+82)',
                                    '965' => 'Kuwait (+965)',
                                    '996' => 'Kyrgyzstan (+996)',
                                    '856' => 'Laos (+856)',
                                    '371' => 'Latvia (+371)',
                                    '961' => 'Lebanon (+961)',
                                    '266' => 'Lesotho (+266)',
                                    '231' => 'Liberia (+231)',
                                    '218' => 'Libya (+218)',
                                    '417' => 'Liechtenstein (+417)', // Old code (kept for backward compatibility)
                                    '423' => 'Liechtenstein (+423)', // Current code
                                    '370' => 'Lithuania (+370)',
                                    '352' => 'Luxembourg (+352)',
                                    '853' => 'Macao (+853)',
                                    '389' => 'Macedonia (+389)',
                                    '261' => 'Madagascar (+261)',
                                    '265' => 'Malawi (+265)',
                                    '60' => 'Malaysia (+60)',
                                    '960' => 'Maldives (+960)',
                                    '223' => 'Mali (+223)',
                                    '356' => 'Malta (+356)',
                                    '692' => 'Marshall Islands (+692)',
                                    '596' => 'Martinique (+596)',
                                    '222' => 'Mauritania (+222)',
                                    '269' => 'Mayotte (+269)',
                                    '52' => 'Mexico (+52)',
                                    '691' => 'Micronesia (+691)',
                                    '373' => 'Moldova (+373)',
                                    '377' => 'Monaco (+377)',
                                    '976' => 'Mongolia (+976)',
                                    '1664' => 'Montserrat (+1664)',
                                    '212' => 'Morocco (+212)',
                                    '258' => 'Mozambique (+258)',
                                    '95' => 'Myanmar (+95)',
                                    '264' => 'Namibia (+264)',
                                    '674' => 'Nauru (+674)',
                                    '977' => 'Nepal (+977)',
                                    '31' => 'Netherlands (+31)',
                                    '687' => 'New Caledonia (+687)',
                                    '64' => 'New Zealand (+64)',
                                    '505' => 'Nicaragua (+505)',
                                    '227' => 'Niger (+227)',
                                    '234' => 'Nigeria (+234)',
                                    '683' => 'Niue (+683)',
                                    '672' => 'Norfolk Islands (+672)',
                                    '670' => 'Northern Marianas (+670)',
                                    '968' => 'Oman (+968)',
                                    '680' => 'Palau (+680)',
                                    '507' => 'Panama (+507)',
                                    '675' => 'Papua New Guinea (+675)',
                                    '595' => 'Paraguay (+595)',
                                    '51' => 'Peru (+51)',
                                    '63' => 'Philippines (+63)',
                                    '48' => 'Poland (+48)',
                                    '351' => 'Portugal (+351)',
                                    '1787' => 'Puerto Rico (+1787)',
                                    '974' => 'Qatar (+974)',
                                    '262' => 'Reunion (+262)',
                                    '40' => 'Romania (+40)',
                                    '250' => 'Rwanda (+250)',
                                    '378' => 'San Marino (+378)',
                                    '239' => 'Sao Tome & Principe (+239)',
                                    '966' => 'Saudi Arabia (+966)',
                                    '221' => 'Senegal (+221)',
                                    '381' => 'Serbia (+381)',
                                    '248' => 'Seychelles (+248)',
                                    '232' => 'Sierra Leone (+232)',
                                    '65' => 'Singapore (+65)',
                                    '421' => 'Slovak Republic (+421)',
                                    '386' => 'Slovenia (+386)',
                                    '677' => 'Solomon Islands (+677)',
                                    '252' => 'Somalia (+252)',
                                    '27' => 'South Africa (+27)',
                                    '34' => 'Spain (+34)',
                                    '94' => 'Sri Lanka (+94)',
                                    '290' => 'St. Helena (+290)',
                                    '1869' => 'St. Kitts (+1869)',
                                    '1758' => 'St. Lucia (+1758)',
                                    '249' => 'Sudan (+249)',
                                    '597' => 'Suriname (+597)',
                                    '268' => 'Swaziland (+268)',
                                    '46' => 'Sweden (+46)',
                                    '41' => 'Switzerland (+41)',
                                    '963' => 'Syria (+963)',
                                    '886' => 'Taiwan (+886)',
                                    '66' => 'Thailand (+66)',
                                    '228' => 'Togo (+228)',
                                    '676' => 'Tonga (+676)',
                                    '1868' => 'Trinidad & Tobago (+1868)',
                                    '216' => 'Tunisia (+216)',
                                    '90' => 'Turkey (+90)',
                                    '993' => 'Turkmenistan (+993)',
                                    '1649' => 'Turks & Caicos Islands (+1649)',
                                    '688' => 'Tuvalu (+688)',
                                    '256' => 'Uganda (+256)',
                                    '380' => 'Ukraine (+380)',
                                    '971' => 'United Arab Emirates (+971)',
                                    '598' => 'Uruguay (+598)',
                                    '7' => 'Uzbekistan (+7)',
                                    '678' => 'Vanuatu (+678)',
                                    '379' => 'Vatican City (+379)',
                                    '58' => 'Venezuela (+58)',
                                    '84' => 'Vietnam (+84)',
                                    '1284' => 'Virgin Islands - British (+1284)',
                                    '1340' => 'Virgin Islands - US (+1340)',
                                    '681' => 'Wallis & Futuna (+681)',
                                    '969' => 'Yemen (North)(+969)',
                                    '967' => 'Yemen (South)(+967)',
                                    '260' => 'Zambia (+260)',
                                    '263' => 'Zimbabwe (+263)'
                                ];

                                if (!empty($country_data)): ?>
                                    <div class="country-details">
                                        <?php foreach ($country_data as $country):
                                            // Convert country code to string for comparison (database stores as bigint)
                                            $country_code = (string)$country['COUNTRY_CODE'];
                                            // Handle invalid country codes
                                            if ($country_code == '0' || empty($country_code)) {
                                                $country_name = 'Invalid Country Code';
                                            } else {
                                                $country_name = isset($country_names[$country_code]) ? $country_names[$country_code] : 'Unknown Country (' . $country_code . ')';
                                            }
                                        ?>
                                            <small class="d-block">
                                                <strong><?php echo $country_name; ?>:</strong>
                                                <?php echo $country['NO_OF_STUDENT_COUNTRY']; ?> students
                                            </small>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">No country data</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['Male_Students_Economic_Backward'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Students_Economic_Backward'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Students_Economic_Backward'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_General'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_General'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_General'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_SC'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_SC'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_SC'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_ST'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_ST'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_ST'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_OBC'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_OBC'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_OBC'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_DTA'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_DTA'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_DTA'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_NTB'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_NTB'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_NTB'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_NTC'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_NTC'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_NTC'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_NTD'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_NTD'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_NTD'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_EWS'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_EWS'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_EWS'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_SEBC'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_SEBC'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_SEBC'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Social_Backward_SBC'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Social_Backward_SBC'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Social_Backward_SBC'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Physically_Handicapped'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Physically_Handicapped'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Physically_Handicapped'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_TGO'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_TGO'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_TGO'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_CMIL'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_CMIL'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_CMIL'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_SPCUL'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_SPCUL'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_SPCUL'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Widow_Single_Mother'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Widow_Single_Mother'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Widow_Single_Mother'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_ES'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_ES'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_ES'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Receiving_Scholarship_Government'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Receiving_Scholarship_Government'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Receiving_Scholarship_Government'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Receiving_Scholarship_Institution'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Receiving_Scholarship_Institution'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Receiving_Scholarship_Institution'] ?? 0; ?></td>
                            <td><?php echo $row['Male_Student_Receiving_Scholarship_Private_Body'] ?? 0; ?></td>
                            <td><?php echo $row['Female_Student_Receiving_Scholarship_Private_Body'] ?? 0; ?></td>
                            <td><?php echo $row['Other_Student_Receiving_Scholarship_Private_Body'] ?? 0; ?></td>
                            <td>
                                <?php
                                $program_code_table = trim((string)($row['PROGRAM_CODE'] ?? ''));
                                $escs_doc = $get_document_for_table(3, $program_code_table, $dept, $A_YEAR);
                                
                                if ($escs_doc && !empty($escs_doc['path'])): ?>
                                    <a href="<?php echo htmlspecialchars($escs_doc['path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-outline-info" title="<?php echo htmlspecialchars($escs_doc['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                <?php else: ?>
                                    <small class="text-muted">No document</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $program_code_table = trim((string)($row['PROGRAM_CODE'] ?? ''));
                                $scholarship_doc = $get_document_for_table(4, $program_code_table, $dept, $A_YEAR);
                                
                                if ($scholarship_doc && !empty($scholarship_doc['path'])): ?>
                                    <a href="<?php echo htmlspecialchars($scholarship_doc['path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-outline-info" title="<?php echo htmlspecialchars($scholarship_doc['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                <?php else: ?>
                                    <small class="text-muted">No document</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $program_code_table = trim((string)($row['PROGRAM_CODE'] ?? ''));
                                $regional_doc = $get_document_for_table(2, $program_code_table, $dept, $A_YEAR);
                                
                                if ($regional_doc && !empty($regional_doc['path'])): ?>
                                    <a href="<?php echo htmlspecialchars($regional_doc['path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-outline-info" title="<?php echo htmlspecialchars($regional_doc['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                <?php else: ?>
                                    <small class="text-muted">No document</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $program_code_table = trim((string)($row['PROGRAM_CODE'] ?? ''));
                                $total_intake_doc = $get_document_for_table(1, $program_code_table, $dept, $A_YEAR);
                                
                                if ($total_intake_doc && !empty($total_intake_doc['path'])): ?>
                                    <a href="<?php echo htmlspecialchars($total_intake_doc['path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-outline-info" title="<?php echo htmlspecialchars($total_intake_doc['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                <?php else: ?>
                                    <small class="text-muted">No document</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="updateRecordFromTable(<?php echo $row['ID']; ?>)">
                                    <i class="fas fa-edit me-1"></i>Edit
                                </button>
                            </td>
                            <td><a class="btn btn-sm btn-outline-danger"
                                    href="IntakeActualStrength.php?action=delete&ID=<?php echo $row['ID'] ?>"
                                    onclick="return confirm('Are you sure you want to delete this record? This action cannot be undone and will also delete all associated country-wise data.')"><i
                                        class="fas fa-trash me-1"></i>Delete</a></td>
                        </tr>

                    <?php
                                }
                                mysqli_free_result($Record); // CRITICAL: Free result set
                            }
                        }
                        mysqli_stmt_close($stmt); // CRITICAL: Close statement
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>



<?php
require "unified_footer.php";
?>