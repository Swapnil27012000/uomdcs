<?php
/**
 * Verification Committee - Department Review
 * Shows only assigned fields for HRDC/AAQA with verification controls
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display on production
ini_set('log_errors', 1);

// Load required files with error handling
try {
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    
    // Load config first
    if (!isset($conn)) {
        require('../config.php');
    }
    
    // Load session verification
    require('session.php');
    
    // Load CSRF utilities (reuse dept_login csrf helper)
    if (file_exists(__DIR__ . '/../dept_login/csrf.php')) {
        require_once(__DIR__ . '/../dept_login/csrf.php');
    }
    
    // Load functions (this also loads expert_functions.php)
    require('functions.php');
    
    // Ensure expert_functions is loaded (functions.php should load it, but double-check)
    if (!function_exists('getAcademicYear')) {
        require('../Expert_comty_login/expert_functions.php');
    }
    
    // Load data fetcher
    require('../Expert_comty_login/data_fetcher.php');
    
} catch (ParseError $e) {
    error_log("Parse error in verification committee department page: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    die("Error loading page. Please contact administrator.");
} catch (Exception $e) {
    error_log("Error loading verification committee department page: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    die("Error loading page. Please contact administrator.");
} catch (Error $e) {
    error_log("Fatal error in verification committee department page: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    die("Fatal error. Please contact administrator.");
}

$dept_id_raw = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$cat_id = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$category_name = isset($_GET['name']) ? urldecode($_GET['name']) : '';

// Resolve department ID - check if function exists, otherwise use direct value
if (function_exists('resolveDepartmentId')) {
    $dept_id = resolveDepartmentId($dept_id_raw);
} else {
    // If function doesn't exist, use the raw ID directly
    $dept_id = $dept_id_raw;
}

if (!$dept_id) {
    die("Valid Department ID is required");
}

// Check database connection
if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
    error_log("ERROR: Database connection not available in verification committee department page");
    die("Database connection error. Please contact administrator.");
}

$academic_year = getAcademicYear();
$email = $_SESSION['admin_username'] ?? '';
$csrf_token = function_exists('csrf_token') ? csrf_token() : '';

if (empty($email)) {
    error_log("ERROR: admin_username not set in session");
    die("Session error. Please login again.");
}

$role_name = getCommitteeRoleName($email);

if (!$role_name) {
    error_log("ERROR: Unable to determine role for email: " . $email);
    die("Unable to determine your role. Please contact administrator.");
}

// Fetch department info with error handling
$dept_name = 'Unknown';
$dept_code = '';

try {
    $dept_query = "SELECT 
            COALESCE(dn.collname, dm.DEPT_NAME) AS dept_name,
            dm.DEPT_COLL_NO AS dept_code,
            COALESCE(dp.category, bd.TYPE) AS category_label
        FROM department_master dm
        LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
        LEFT JOIN department_profiles dp ON dp.A_YEAR = ? 
            AND CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
        LEFT JOIN brief_details_of_the_department bd ON bd.DEPT_ID = dm.DEPT_ID AND bd.A_YEAR = ?
        WHERE dm.DEPT_ID = ?
        LIMIT 1";
    
    $dept_stmt = @$conn->prepare($dept_query);
    if ($dept_stmt) {
        $dept_stmt->bind_param("ssi", $academic_year, $academic_year, $dept_id);
        if ($dept_stmt->execute()) {
            $dept_result = $dept_stmt->get_result();
            if ($dept_result && $dept_row = $dept_result->fetch_assoc()) {
                $dept_name = $dept_row['dept_name'] ?? $dept_name;
                $dept_code = $dept_row['dept_code'] ?? '';
            }
            if ($dept_result) {
                mysqli_free_result($dept_result);
            }
        }
        $dept_stmt->close();
    } else {
        error_log("Error preparing department query: " . mysqli_error($conn));
    }
} catch (Exception $e) {
    error_log("Error fetching department info: " . $e->getMessage());
    // Continue with default values
}

// CRITICAL: Clear cache first to ensure we get the latest data (same as review_complete.php)
// This ensures that any changes made by the department are immediately visible
if (function_exists('clearDepartmentScoreCache')) {
    try {
        clearDepartmentScoreCache($dept_id, $academic_year, false); // Clear cache but don't recalculate
        error_log("[Verification Committee] Cleared cache for dept_id=$dept_id, academic_year=$academic_year");
    } catch (Exception $e) {
        error_log("Warning: Could not clear cache: " . $e->getMessage());
        // Continue anyway - cache clearing is not critical
    }
}

// Fetch ALL department data with error handling - ALWAYS fetch fresh from database
try {
    if (!function_exists('fetchAllDepartmentData')) {
        error_log("ERROR: fetchAllDepartmentData function not found");
        die("Error: Required function not found. Please contact administrator.");
    }
    
    // Force fresh data fetch - no caching
    // Always fetch the latest data from database
    $dept_data = fetchAllDepartmentData($dept_id, $academic_year);
    
    if (empty($dept_data)) {
        error_log("WARNING: fetchAllDepartmentData returned empty data for dept_id: $dept_id, academic_year: $academic_year");
        // Continue anyway - some departments might not have data yet
        $dept_data = [];
    } else {
        error_log("[Verification Committee] Successfully fetched fresh data for dept_id=$dept_id, academic_year=$academic_year");
        // Log sample values to verify we're getting updated data
        if (isset($dept_data['section_a'])) {
            $sec_a = $dept_data['section_a'];
            error_log("[Verification Committee] Section A - SANCTIONED_TEACHING_FACULTY: " . ($sec_a['SANCTIONED_TEACHING_FACULTY'] ?? 'NULL'));
            error_log("[Verification Committee] Section A - REGULAR_TEACHERS: " . ($sec_a['REGULAR_TEACHERS'] ?? 'NULL'));
            error_log("[Verification Committee] Section A - ADHOC_TEACHERS: " . ($sec_a['ADHOC_TEACHERS'] ?? 'NULL'));
        }
        if (isset($dept_data['section_1'])) {
            $sec_1 = $dept_data['section_1'];
            error_log("[Verification Committee] Section 1 - NUM_PERM_PHD: " . ($sec_1['NUM_PERM_PHD'] ?? 'NULL'));
        }
    }
} catch (Exception $e) {
    error_log("Error fetching department data: " . $e->getMessage());
    $dept_data = [];
} catch (Error $e) {
    error_log("Fatal error fetching department data: " . $e->getMessage());
    die("Error loading department data. Please contact administrator.");
}

// CRITICAL: Fetch all supporting documents and group them by section
// This is required for document display in section files
$grouped_docs = [];
try {
    if (function_exists('fetchAllSupportingDocuments') && function_exists('groupDocumentsBySection')) {
        $documents = fetchAllSupportingDocuments($dept_id, $academic_year);
        $grouped_docs = groupDocumentsBySection($documents);
        error_log("[Verification Committee] Fetched " . count($documents) . " documents, grouped into " . count($grouped_docs) . " sections");
    } else {
        error_log("WARNING: fetchAllSupportingDocuments or groupDocumentsBySection function not found");
    }
} catch (Exception $e) {
    error_log("Error fetching documents: " . $e->getMessage());
    $grouped_docs = [];
} catch (Error $e) {
    error_log("Fatal error fetching documents: " . $e->getMessage());
    $grouped_docs = [];
}

// Get assigned items for this role
// CRITICAL: Force fresh fetch by refreshing role name first
$role_name = getCommitteeRoleName($email); // Refresh role name
$assigned_items = getAssignedItems($email, $role_name);

// If no items found, try one more time with fresh role lookup
if (empty($assigned_items)) {
    error_log("[Verification Committee] No items found on first attempt, retrying with fresh role lookup...");
    $role_name = getCommitteeRoleName($email);
    $assigned_items = getAssignedItems($email, $role_name);
}

// Debug: Log assigned items
error_log("[Verification Committee] Role: $role_name, Email: $email");
error_log("[Verification Committee] Assigned items count: " . count($assigned_items));
if (!empty($assigned_items)) {
    error_log("[Verification Committee] Assigned items: " . json_encode($assigned_items));
} else {
    error_log("[Verification Committee] WARNING: No assigned items found for role: $role_name, email: $email");
    error_log("[Verification Committee] DEBUG: Checking verification_committee_users table for user...");
    // Additional debug: Check if user exists in verification_committee_users
    $debug_stmt = @$conn->prepare("SELECT id, EMAIL, ROLE, is_active FROM verification_committee_users WHERE LOWER(EMAIL) = LOWER(?) LIMIT 1");
    if ($debug_stmt) {
        $debug_stmt->bind_param("s", $email);
        $debug_stmt->execute();
        $debug_result = $debug_stmt->get_result();
        if ($debug_result && $debug_result->num_rows > 0) {
            $debug_row = $debug_result->fetch_assoc();
            error_log("[Verification Committee] DEBUG: User found in verification_committee_users - ID: " . $debug_row['id'] . ", ROLE: " . ($debug_row['ROLE'] ?? 'NULL') . ", is_active: " . ($debug_row['is_active'] ?? 'NULL'));
        } else {
            error_log("[Verification Committee] DEBUG: User NOT found in verification_committee_users table");
        }
        if ($debug_result) {
            mysqli_free_result($debug_result);
        }
        $debug_stmt->close();
    }
}

// Get all verification flags for this department (only if table exists)
$all_verification_flags = [];
$table_check = @$conn->query("SHOW TABLES LIKE 'verification_flags'");
if ($table_check && $table_check->num_rows > 0) {
    $flags_query = "SELECT section_number, item_number, verification_status, is_locked, committee_email, COALESCE(remark, '') AS remark
                    FROM verification_flags
                    WHERE dept_id = ? AND academic_year = ?";
    $flags_stmt = $conn->prepare($flags_query);
    if ($flags_stmt) {
        $flags_stmt->bind_param("is", $dept_id, $academic_year);
        $flags_stmt->execute();
        $flags_result = $flags_stmt->get_result();
        while ($flag_row = $flags_result->fetch_assoc()) {
            $key = $flag_row['section_number'] . '_' . $flag_row['item_number'];
            if (!isset($all_verification_flags[$key])) {
                $all_verification_flags[$key] = [];
            }
            $all_verification_flags[$key][] = $flag_row;
        }
        if ($flags_result) {
            mysqli_free_result($flags_result);
        }
        $flags_stmt->close();
    }
}
if ($table_check) {
    mysqli_free_result($table_check);
}

// Set variables for section files
$is_locked = false;
$is_readonly = true;
$is_department_view = false;
$is_chairman_view = false;
$is_verification_view = true; // CRITICAL: Enable verification view mode

// Store in globals for section files
$GLOBALS['email'] = $email;
$GLOBALS['role_name'] = $role_name;
$GLOBALS['dept_id'] = $dept_id;
$GLOBALS['academic_year'] = $academic_year;
$GLOBALS['all_verification_flags'] = $all_verification_flags;
$GLOBALS['grouped_docs'] = $grouped_docs; // CRITICAL: Make grouped_docs available to section files

// CRITICAL: Also make grouped_docs available as a regular variable for section files
// Section files expect $grouped_docs to be available directly (not just in GLOBALS)
// This ensures documents are visible in verification committee view

// Build assigned items map for section files
$assigned_items_map = [];
foreach ($assigned_items as $item) {
    $section = (int)$item['section']; // Ensure section is integer
    $item_num = (string)$item['item']; // Ensure item is string for consistency
    if (!isset($assigned_items_map[$section])) {
        $assigned_items_map[$section] = [];
    }
    // Only add if not already present (avoid duplicates)
    if (!in_array($item_num, $assigned_items_map[$section])) {
        $assigned_items_map[$section][] = $item_num;
        error_log("[Verification Committee] Added to map: Section $section, Item $item_num");
    }
}
$GLOBALS['assigned_items_map'] = $assigned_items_map;

// Debug: Log assigned items map
error_log("[Verification Committee] Final assigned items map: " . json_encode($assigned_items_map));
error_log("[Verification Committee] Section 0 items: " . (isset($assigned_items_map[0]) ? json_encode($assigned_items_map[0]) : 'NONE'));
error_log("[Verification Committee] Section 1 items: " . (isset($assigned_items_map[1]) ? json_encode($assigned_items_map[1]) : 'NONE'));
error_log("[Verification Committee] Section 2 items: " . (isset($assigned_items_map[2]) ? json_encode($assigned_items_map[2]) : 'NONE'));
error_log("[Verification Committee] Section 3 items: " . (isset($assigned_items_map[3]) ? json_encode($assigned_items_map[3]) : 'NONE'));
error_log("[Verification Committee] Section 4 items: " . (isset($assigned_items_map[4]) ? json_encode($assigned_items_map[4]) : 'NONE'));
error_log("[Verification Committee] Section 5 items: " . (isset($assigned_items_map[5]) ? json_encode($assigned_items_map[5]) : 'NONE'));

// Use output buffering to capture section file output
ob_start();

// Include section files (they will check is_verification_view and show only assigned items)
try {
    // CRITICAL: Only include Section A if Section 0 has assigned items
    $has_section0_assignments = false;
    if (isset($assigned_items_map[0]) && !empty($assigned_items_map[0])) {
        $has_section0_assignments = true;
    }
    
    if ($has_section0_assignments) {
        if (file_exists(__DIR__ . '/../Expert_comty_login/section_brief_details.php')) {
            include(__DIR__ . '/../Expert_comty_login/section_brief_details.php');
        } else {
            error_log("ERROR: section_brief_details.php not found");
            echo "<div class='alert alert-danger'>Error: Section file not found. Please contact administrator.</div>";
        }
    }
    
    // Include Section I items if any Section 1 items are assigned (not just AAQA)
    // Check if user has any Section 1 assignments
    $has_section1_assignments = false;
    if (isset($assigned_items_map[1]) && !empty($assigned_items_map[1])) {
        $has_section1_assignments = true;
    }
    
    if ($has_section1_assignments) {
        if (file_exists(__DIR__ . '/../Expert_comty_login/section1_faculty_output.php')) {
            include(__DIR__ . '/../Expert_comty_login/section1_faculty_output.php');
        } else {
            error_log("ERROR: section1_faculty_output.php not found");
            echo "<div class='alert alert-danger'>Error: Section I file not found. Please contact administrator.</div>";
        }
    }
    
    // Include Section II items if any Section 2 items are assigned
    $has_section2_assignments = false;
    if (isset($assigned_items_map[2]) && !empty($assigned_items_map[2])) {
        $has_section2_assignments = true;
    }
    
    if ($has_section2_assignments) {
        if (file_exists(__DIR__ . '/../Expert_comty_login/section2_nep_initiatives.php')) {
            include(__DIR__ . '/../Expert_comty_login/section2_nep_initiatives.php');
        } else {
            error_log("ERROR: section2_nep_initiatives.php not found");
            echo "<div class='alert alert-danger'>Error: Section II file not found. Please contact administrator.</div>";
        }
    }
    
    // Include Section III items if any Section 3 items are assigned
    $has_section3_assignments = false;
    if (isset($assigned_items_map[3]) && !empty($assigned_items_map[3])) {
        $has_section3_assignments = true;
    }
    
    if ($has_section3_assignments) {
        if (file_exists(__DIR__ . '/../Expert_comty_login/section3_governance.php')) {
            include(__DIR__ . '/../Expert_comty_login/section3_governance.php');
        } else {
            error_log("ERROR: section3_governance.php not found");
            echo "<div class='alert alert-danger'>Error: Section III file not found. Please contact administrator.</div>";
        }
    }
    
    // Include Section IV items if any Section 4 items are assigned
    $has_section4_assignments = false;
    if (isset($assigned_items_map[4]) && !empty($assigned_items_map[4])) {
        $has_section4_assignments = true;
    }
    
    if ($has_section4_assignments) {
        if (file_exists(__DIR__ . '/../Expert_comty_login/section4_student_support.php')) {
            include(__DIR__ . '/../Expert_comty_login/section4_student_support.php');
        } else {
            error_log("ERROR: section4_student_support.php not found");
            echo "<div class='alert alert-danger'>Error: Section IV file not found. Please contact administrator.</div>";
        }
    }
    
    // Include Section V items if any Section 5 items are assigned
    $has_section5_assignments = false;
    if (isset($assigned_items_map[5]) && !empty($assigned_items_map[5])) {
        $has_section5_assignments = true;
    }
    
    if ($has_section5_assignments) {
        if (file_exists(__DIR__ . '/../Expert_comty_login/section5_conferences.php')) {
            include(__DIR__ . '/../Expert_comty_login/section5_conferences.php');
        } else {
            error_log("ERROR: section5_conferences.php not found");
            echo "<div class='alert alert-danger'>Error: Section V file not found. Please contact administrator.</div>";
        }
    }
} catch (Exception $e) {
    error_log("Error including section files: " . $e->getMessage());
    echo "<div class='alert alert-danger'>Error loading section data: " . htmlspecialchars($e->getMessage()) . "</div>";
} catch (Error $e) {
    error_log("Fatal error including section files: " . $e->getMessage());
    echo "<div class='alert alert-danger'>Fatal error loading section data. Please contact administrator.</div>";
}

$section_output = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Department - <?php echo htmlspecialchars($dept_name); ?></title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --bg-light: #f8fafc;
        }
        
        body {
            background: var(--bg-light);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .top-bar {
            background: var(--primary-blue);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .top-bar-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .container-main {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #3b82f6 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 1rem;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .section-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            overflow-x: hidden !important;
            overflow-y: visible !important;
            position: relative !important;
        }
        
        /* Ensure all data-grid items stay within section-card */
        .section-card .data-grid {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            position: relative !important;
            float: none !important;
            clear: both !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
        
        /* Ensure all content within section-card stays contained */
        .section-card > * {
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        
        /* Specifically target narrative items to ensure they stay within card */
        .section-card .data-grid[data-item] {
            display: grid !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            position: relative !important;
            float: none !important;
            clear: both !important;
            margin: 0 !important;
            padding: 0.75rem !important;
        }
        
        /* Ensure Expert Remarks section stays within card */
        .section-card .mt-2.mb-3 {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
        
        /* Force all content in Section 3 to stay within card - specifically for items 9-12 */
        .section-card .data-grid[data-item="9"],
        .section-card .data-grid[data-item="10"],
        .section-card .data-grid[data-item="11"],
        .section-card .data-grid[data-item="12"] {
            display: grid !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            position: relative !important;
            float: none !important;
            clear: both !important;
            margin: 0 !important;
            padding: 0.75rem !important;
            overflow: visible !important;
            left: 0 !important;
            right: 0 !important;
        }
        
        /* Ensure script tags don't break layout */
        .section-card script {
            display: none !important;
        }
        
        /* CRITICAL: Ensure Section III items 9-12 match the exact same structure as items 1-8 */
        /* Remove any transforms, absolute positioning, or other layout-breaking properties */
        .section-card .data-grid[data-item] {
            transform: none !important;
            left: auto !important;
            right: auto !important;
            top: auto !important;
            bottom: auto !important;
        }
        
        .verification-controls {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .verification-radio-group {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .verification-radio {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            padding: 0.75rem 1.5rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .verification-radio:hover {
            border-color: var(--primary-blue);
            background: #f0f9ff;
        }
        
        .verification-radio input[type="radio"] {
            margin: 0;
            cursor: pointer;
        }
        
        .verification-radio input[type="radio"]:checked + span {
            font-weight: 600;
            color: var(--primary-blue);
        }
        
        .verification-radio:has(input[type="radio"]:checked) {
            border-color: var(--primary-blue);
            background: #eff6ff;
        }
        
        .verification-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        
        .btn-lock, .btn-update, .btn-clear {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-lock {
            background: var(--accent-green);
            color: white;
        }
        
        .btn-lock:hover {
            background: #059669;
        }
        
        .btn-update {
            background: var(--accent-amber);
            color: white;
        }
        
        .btn-update:hover {
            background: #d97706;
        }
        
        .btn-clear {
            background: #ef4444;
            color: white;
        }
        
        .btn-clear:hover {
            background: #dc2626;
        }
        
        .badge-locked {
            background: #fef3c7;
            color: #92400e;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .data-grid {
            display: grid;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            position: relative !important;
            float: none !important;
            clear: both !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
        
        .field-label {
            font-weight: 600;
            color: #1f2937;
        }
        
        .dept-value {
            color: #4b5563;
        }
        
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="top-bar-content">
            <div>
                <h1><i class="fas fa-check-circle"></i> Verification Committee - Department Review</h1>
                <div style="margin-top: 0.5rem; font-size: 0.875rem; opacity: 0.9;">
                    <span><?php echo htmlspecialchars($role_name); ?> - <?php echo htmlspecialchars($email); ?></span>
                </div>
            </div>
            <a href="../logout.php" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 0.5rem 1.5rem; border-radius: 6px; text-decoration: none;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <div class="container-main">
        <div class="page-header">
            <a href="category.php?cat_id=<?php echo $cat_id; ?>&name=<?php echo urlencode($category_name); ?>" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Category
            </a>
            <h1 style="font-size: 1.75rem; font-weight: 700; color: white; margin-bottom: 0.5rem;">
                <?php echo htmlspecialchars($dept_name); ?>
            </h1>
            <p style="color: white; font-weight: 600; font-size: 1rem;">
                Department Code: <strong><?php echo htmlspecialchars($dept_code); ?></strong> | 
                Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong>
                <button onclick="location.reload(true)" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 0.25rem 0.75rem; border-radius: 4px; margin-left: 1rem; cursor: pointer; font-size: 0.875rem;">
                    <i class="fas fa-sync-alt"></i> Refresh Data
                </button>
                <small style="opacity: 0.8; margin-left: 1rem;">Data fetched at: <?php echo date('Y-m-d H:i:s'); ?></small>
            </p>
        </div>
        
        <div class="section-card">
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--primary-blue); margin-bottom: 1.5rem;">
                <i class="fas fa-list-check"></i> Assigned Fields for Verification
            </h2>
            
            <?php if (empty($assigned_items)): ?>
                <div style="text-align: center; padding: 3rem; color: #6b7280;">
                    <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; display: block; opacity: 0.5;"></i>
                    <p><strong>No fields assigned for verification.</strong></p>
                    <p style="font-size: 0.875rem; margin-top: 1rem;">
                        Role: <strong><?php echo htmlspecialchars($role_name); ?></strong><br>
                        Email: <strong><?php echo htmlspecialchars($email); ?></strong><br>
                        <small>Please contact administrator to assign items for verification.</small>
                    </p>
                </div>
            <?php else: ?>
                <?php echo $section_output; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="toast-container"></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================================================
        // CRITICAL: GLOBAL REQUEST LIMITING - Prevent connection exhaustion with 20+ users
        // Same pattern as dept_login to ensure no unlimited requests reach database
        // ============================================================================
        let globalActiveRequests = 0;
        const MAX_CONCURRENT_REQUESTS = 3; // Maximum 3 simultaneous requests (same as dept_login)
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

        // CRITICAL: Add request timeout to prevent hanging requests
        function fetchWithTimeout(url, options = {}, timeout = 30000) {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), timeout);
            
            return fetch(url, {
                ...options,
                signal: controller.signal
            }).finally(() => {
                clearTimeout(timeoutId);
            });
        }
        // ============================================================================

        const deptId = <?php echo (int)$dept_id; ?>;
        const email = '<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>';
        const csrfToken = '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>';
        
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
            toast.style.minWidth = '300px';
            toast.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.toast-container').appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, 5000);
        }

        async function parseJsonResponse(response) {
            const text = await response.text();
            if (!text) {
                throw new Error('Empty server response');
            }
            try {
                return JSON.parse(text);
            } catch (error) {
                console.error('Invalid JSON response:', text);
                throw new Error('Invalid server response');
            }
        }
        
        function saveVerification(section, item, status, lock) {
            // Ensure section and item are strings
            section = String(section);
            item = String(item);
            
            console.log('[saveVerification] Called with:', {section, item, status, lock});
            
            if (!status && !lock) {
                showToast('Please select a verification status', 'danger');
                return;
            }
            
            // If locking, get current status from radio button
            if (lock) {
                const radio = document.querySelector(`input[name="verification_${section}_${item}"]:checked`);
                if (!radio) {
                    showToast('Please select "Verified and Correct" or "Verified and Incorrect" before submitting', 'danger');
                    return;
                }
                status = radio.value;
            }
            
            // Validate status
            if (!status || (status !== 'verified_correct' && status !== 'verified_incorrect')) {
                showToast('Invalid verification status. Please select a valid option.', 'danger');
                return;
            }
            
            if (lock && !confirm('Are you sure you want to lock this verification? You will need to use the Update button to change it later.')) {
                return;
            }
            
            // Get remark from textarea if it exists
            const remarkTextarea = document.getElementById(`remark_${section}_${item}`);
            const remark = remarkTextarea ? remarkTextarea.value.trim() : '';
            
            const formData = new FormData();
            formData.append('dept_id', deptId);
            formData.append('section_number', section);
            formData.append('item_number', item);
            formData.append('verification_status', status);
            formData.append('lock', lock ? 'true' : 'false');
            if (remark) {
                formData.append('remark', remark);
            }
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }
            
            console.log('[saveVerification] Sending:', {
                dept_id: deptId,
                section_number: section,
                item_number: item,
                verification_status: status,
                lock: lock ? 'true' : 'false'
            });
            
            // CRITICAL: Use rate limiting to prevent unlimited database requests
            executeWithRateLimit(() => {
                return fetchWithTimeout('api/save_verification.php', {
                    method: 'POST',
                    body: formData
                }, 30000);
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    if (lock) {
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    }
                } else {
                    const codeSuffix = data.code ? ` (Code: ${data.code})` : '';
                    showToast(`${data.message}${codeSuffix}`, 'danger');
                }
            })
            .catch(error => {
                showToast('Error saving verification: ' + error.message, 'danger');
            });
        }
        
        function unlockVerification(section, item, buttonElement) {
            if (!confirm('This will unlock the verification so you can update it. Continue?')) {
                return;
            }
            
            // Ensure section and item are strings
            section = String(section);
            item = String(item);
            
            // Disable the update button to prevent double-clicks
            let updateButton = buttonElement;
            if (!updateButton && typeof event !== 'undefined') {
                updateButton = event.target.closest('.verification-actions')?.querySelector('.btn-update');
            }
            if (updateButton) {
                updateButton.disabled = true;
                updateButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Unlocking...';
            }
            
            // Unlock by setting is_locked to 0
            const formData = new FormData();
            formData.append('dept_id', deptId);
            formData.append('section_number', section);
            formData.append('item_number', item);
            formData.append('verification_status', 'verified_correct'); // Dummy, will be updated
            formData.append('lock', 'false');
            formData.append('unlock', 'true');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }
            
            // CRITICAL: Use rate limiting to prevent unlimited database requests
            executeWithRateLimit(() => {
                return fetchWithTimeout('api/save_verification.php', {
                    method: 'POST',
                    body: formData
                }, 30000);
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    showToast('Verification unlocked successfully. You can now update it.', 'success');
                    
                    // Find the verification container (the div with class "mt-2 p-2 border rounded bg-light")
                    // The updateButton is inside .verification-actions, which is inside the .bg-light container
                    const verificationActions = updateButton?.closest('.verification-actions');
                    const verificationContainer = verificationActions?.closest('.bg-light');
                    
                    if (verificationContainer) {
                        // Get the section and item
                        const sectionStr = String(section);
                        const itemStr = String(item);
                        
                        // Get existing remark if any
                        const existingRemark = verificationContainer.querySelector('textarea') ? verificationContainer.querySelector('textarea').value : '';
                        
                        // Create the unlocked UI with radio buttons and remark
                        const unlockedHTML = `
                            <label class="form-label mb-2" style="font-size: 0.9rem; font-weight: 600;">Verification:</label>
                            <div class="verification-radio-group">
                                <label class="verification-radio">
                                    <input type="radio" 
                                           name="verification_${sectionStr}_${itemStr}" 
                                           value="verified_correct"
                                           onchange="if(typeof saveVerification === 'function') { saveVerification('${sectionStr}', '${itemStr}', this.value, false); }">
                                    <span>Verified Correct</span>
                                </label>
                                <label class="verification-radio">
                                    <input type="radio" 
                                           name="verification_${sectionStr}_${itemStr}" 
                                           value="verified_incorrect"
                                           onchange="if(typeof saveVerification === 'function') { saveVerification('${sectionStr}', '${itemStr}', this.value, false); }">
                                    <span>Verified Incorrect</span>
                                </label>
                            </div>
                            <div class="mt-2">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600;">Remark (Optional):</label>
                                <textarea 
                                    id="remark_${sectionStr}_${itemStr}"
                                    class="form-control form-control-sm" 
                                    rows="2" 
                                    placeholder="Enter any remark or note..."
                                    style="font-size: 0.85rem; resize: vertical;">${existingRemark}</textarea>
                            </div>
                            <div class="verification-buttons mt-2">
                                <button type="button"
                                        class="btn btn-sm btn-lock"
                                        onclick="if(typeof saveVerification === 'function') { saveVerification('${sectionStr}', '${itemStr}', null, true); }">
                                    <i class="fas fa-lock"></i> Lock & Submit
                                </button>
                                <button type="button"
                                        class="btn btn-sm btn-clear"
                                        onclick="if(typeof clearVerification === 'function') { clearVerification('${sectionStr}', '${itemStr}'); }">
                                    <i class="fas fa-eraser"></i> Clear
                                </button>
                            </div>
                        `;
                        
                        // Replace the entire content of the verification container
                        verificationContainer.innerHTML = unlockedHTML;
                    } else {
                        // Fallback: reload if we can't find the container
                        console.warn('Could not find verification container, reloading page...');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    }
                } else {
                    showToast(data.message, 'danger');
                    // Re-enable button on error
                    if (updateButton) {
                        updateButton.disabled = false;
                        updateButton.innerHTML = '<i class="fas fa-edit"></i> Update';
                    }
                }
            })
            .catch(error => {
                showToast('Error unlocking verification: ' + error.message, 'danger');
                // Re-enable button on error
                if (updateButton) {
                    updateButton.disabled = false;
                    updateButton.innerHTML = '<i class="fas fa-edit"></i> Update';
                }
            });
        }
        
        function clearVerification(section, item) {
            // Ensure section and item are strings
            section = String(section);
            item = String(item);
            
            if (!confirm('Are you sure you want to clear this verification? This will remove your verification status.')) {
                return;
            }
            
            // Clear verification by sending empty status
            const formData = new FormData();
            formData.append('dept_id', deptId);
            formData.append('section_number', section);
            formData.append('item_number', item);
            formData.append('verification_status', '');
            formData.append('lock', 'false');
            formData.append('clear', 'true');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }
            
            // CRITICAL: Use rate limiting to prevent unlimited database requests
            executeWithRateLimit(() => {
                return fetchWithTimeout('api/save_verification.php', {
                    method: 'POST',
                    body: formData
                }, 30000);
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    showToast('Verification cleared successfully', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(error => {
                showToast('Error clearing verification: ' + error.message, 'danger');
            });
        }
        
        // Make functions globally available
        window.saveVerification = saveVerification;
        window.unlockVerification = unlockVerification;
        window.clearVerification = clearVerification;
    </script>
    
    <!-- Footer -->
     <?php include '../footer_main.php';?>

</body>
</html>
