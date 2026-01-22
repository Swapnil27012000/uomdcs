<?php
/**
 * Verification Committee Helper Functions
 * Handles HRDC and AAQA role-based verification
 */

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');

if (!function_exists('verification_committee_log')) {
    function verification_committee_log(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $line = '[' . $timestamp . '] ' . $message . PHP_EOL;
        $log_path = __DIR__ . '/api/verification_debug.log';
        @file_put_contents($log_path, $line, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Get committee role name from email (HRDC, AAQA, etc.)
 * verification_committee_users is a STANDALONE table with EMAIL and ROLE columns
 */
function getCommitteeRoleName($email) {
    global $conn;
    
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        return null;
    }
    
    // Check if verification_committee_users table exists
    $table_check = @$conn->query("SHOW TABLES LIKE 'verification_committee_users'");
    if (!$table_check || $table_check->num_rows == 0) {
        if ($table_check) {
            mysqli_free_result($table_check);
        }
        return null;
    }
    mysqli_free_result($table_check);
    
    // Query verification_committee_users table directly (STANDALONE table)
    // Table structure: EMAIL, PASS_WORD, ROLE, is_active
    $stmt = @$conn->prepare("SELECT ROLE FROM verification_committee_users WHERE LOWER(EMAIL) = LOWER(?) AND is_active = 1 LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $execute_ok = @$stmt->execute();
        if ($execute_ok) {
            if (is_callable([$stmt, 'get_result'])) {
                $result = $stmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $role = trim($row['ROLE'] ?? '');
                    if ($result) {
                        mysqli_free_result($result);
                    }
                    $stmt->close();
                    if (!empty($role)) {
                        return $role;
                    }
                }
                if ($result) {
                    mysqli_free_result($result);
                }
            } else {
                $stmt->store_result();
                $stmt->bind_result($role_value);
                if ($stmt->fetch()) {
                    $role = trim((string)$role_value);
                    $stmt->close();
                    if (!empty($role)) {
                        return $role;
                    }
                }
            }
        }
        $stmt->close();
    }
    
    return null;
}

/**
 * Get assigned items for a committee member based on their role
 * Returns array of [section_number, item_number] pairs
 */
function getAssignedItems($committee_email, $role_name = null) {
    global $conn;
    
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        return [];
    }
    
    if (!$role_name) {
        $role_name = getCommitteeRoleName($committee_email);
    }
    
    if (!$role_name) {
        return [];
    }
    
    // CRITICAL: ALWAYS check database FIRST (even for HRDC/AAQA)
    // Get verification_user_id first
    $verification_user_id = null;
    
    // Check if verification_committee_users table exists
    $table_check = @$conn->query("SHOW TABLES LIKE 'verification_committee_users'");
    if ($table_check && $table_check->num_rows > 0) {
        mysqli_free_result($table_check);
        // Query verification_committee_users directly using EMAIL (STANDALONE table)
        $stmt = @$conn->prepare("SELECT id FROM verification_committee_users WHERE LOWER(EMAIL) = LOWER(?) AND is_active = 1 LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $committee_email);
            $execute_ok = @$stmt->execute();
            if ($execute_ok) {
                $result = $stmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $verification_user_id = (int)($row['id'] ?? 0);
                }
                if ($result) {
                    mysqli_free_result($result);
                }
            }
            $stmt->close();
        }
    } else {
        if ($table_check) {
            mysqli_free_result($table_check);
        }
    }
    
    // Get assignments using verification_user_id (preferred) or email (fallback)
    $items = [];
    
    // Debug logging
    error_log("[getAssignedItems] Looking for assignments - Email: $committee_email, Role: $role_name, verification_user_id: " . ($verification_user_id ?? 'NULL'));
    
    // Check if verification_committee_assignments table exists
    $assignments_table_check = @$conn->query("SHOW TABLES LIKE 'verification_committee_assignments'");
    if ($assignments_table_check && $assignments_table_check->num_rows > 0) {
        if ($verification_user_id) {
            $stmt = $conn->prepare("SELECT section_number, item_number 
                                   FROM verification_committee_assignments 
                                   WHERE verification_user_id = ? 
                                   ORDER BY section_number, item_number");
            if ($stmt) {
                $stmt->bind_param("i", $verification_user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    error_log("[getAssignedItems] Query executed for verification_user_id=$verification_user_id, found " . $result->num_rows . " rows");
                    while ($row = $result->fetch_assoc()) {
                        $items[] = [
                            'section' => (int)$row['section_number'],
                            'item' => (int)$row['item_number']
                        ];
                        error_log("[getAssignedItems] Found assignment: Section " . $row['section_number'] . ", Item " . $row['item_number']);
                    }
                    mysqli_free_result($result);
                } else {
                    // Fallback for environments without mysqlnd
                    $stmt->store_result();
                    $stmt->bind_result($section_number, $item_number);
                    error_log("[getAssignedItems] Query executed for verification_user_id=$verification_user_id, found " . $stmt->num_rows . " rows (bind_result fallback)");
                    while ($stmt->fetch()) {
                        $items[] = [
                            'section' => (int)$section_number,
                            'item' => (int)$item_number
                        ];
                        error_log("[getAssignedItems] Found assignment: Section " . $section_number . ", Item " . $item_number);
                    }
                }
                $stmt->close();
            } else {
                error_log("[getAssignedItems] ERROR: Failed to prepare statement for verification_user_id");
            }
        } else {
            // Fallback: use email (backward compatibility)
            error_log("[getAssignedItems] verification_user_id not found, using email fallback: $committee_email");
            $stmt = $conn->prepare("SELECT section_number, item_number 
                                   FROM verification_committee_assignments 
                                   WHERE committee_email = ? 
                                   ORDER BY section_number, item_number");
            if ($stmt) {
                $stmt->bind_param("s", $committee_email);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    error_log("[getAssignedItems] Query executed for email=$committee_email, found " . $result->num_rows . " rows");
                    while ($row = $result->fetch_assoc()) {
                        $items[] = [
                            'section' => (int)$row['section_number'],
                            'item' => (int)$row['item_number']
                        ];
                        error_log("[getAssignedItems] Found assignment: Section " . $row['section_number'] . ", Item " . $row['item_number']);
                    }
                    mysqli_free_result($result);
                } else {
                    // Fallback for environments without mysqlnd
                    $stmt->store_result();
                    $stmt->bind_result($section_number, $item_number);
                    error_log("[getAssignedItems] Query executed for email=$committee_email, found " . $stmt->num_rows . " rows (bind_result fallback)");
                    while ($stmt->fetch()) {
                        $items[] = [
                            'section' => (int)$section_number,
                            'item' => (int)$item_number
                        ];
                        error_log("[getAssignedItems] Found assignment: Section " . $section_number . ", Item " . $item_number);
                    }
                }
                $stmt->close();
            } else {
                error_log("[getAssignedItems] ERROR: Failed to prepare statement for email");
            }
        }
    } else {
        error_log("[getAssignedItems] WARNING: verification_committee_assignments table does not exist");
    }
    if ($assignments_table_check) {
        mysqli_free_result($assignments_table_check);
    }
    
    // CRITICAL: Only use hardcoded defaults if NO database assignments found
    if (empty($items)) {
        error_log("[getAssignedItems] No DB assignments found, using hardcoded defaults for role: $role_name");
        
        // Special handling for HRDC - only Section A, Non-teaching Employee fields
        if (strtoupper($role_name) === 'HRDC') {
            // HRDC can verify: Section 0 (Brief Details), Item 3 - Non-teaching Employee fields
            $items = [
                ['section' => 0, 'item' => 3] // Non-teaching employees (Class I/II/III/IV)
            ];
        }
        // Special handling for AAQA - faculty-related fields
        elseif (strtoupper($role_name) === 'AAQA') {
            $items = [
                ['section' => 0, 'item' => 1], // Sanctioned Teaching Faculty
                ['section' => 0, 'item' => 2], // Ad hoc/Contract Teachers
                ['section' => 1, 'item' => 1], // Permanent Faculties with PhD
                ['section' => 1, 'item' => 3], // Full-time teachers who received awards (State)
                ['section' => 1, 'item' => 4], // Full-time teachers who received awards (National)
                ['section' => 1, 'item' => 5], // Full-time teachers who received awards (International)
                ['section' => 1, 'item' => 6], // Number of teachers awarded international fellowship
                ['section' => 1, 'item' => 7], // Number of Ph.D's awarded
                ['section' => 1, 'item' => 20], // Teachers invited as speakers
                ['section' => 1, 'item' => 21], // Teachers who presented at Conferences
            ];
        }
    } else {
        error_log("[getAssignedItems] Using database assignments (found " . count($items) . " items), ignoring hardcoded defaults");
    }
    
    error_log("[getAssignedItems] Returning " . count($items) . " assigned items for role: $role_name");
    return $items;
}

/**
 * Get verification status for a specific item
 * Returns: 'not_verified', 'verified_correct_hrdc', 'verified_incorrect_hrdc', 
 *          'verified_correct_aaqa', 'verified_incorrect_aaqa', etc.
 */
function getItemVerificationStatus($dept_id, $section_number, $item_number, $academic_year = null, $committee_email = null) {
    global $conn;
    
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        return 'not_verified';
    }
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Check if verification_flags table exists
    $check_table = @$conn->query("SHOW TABLES LIKE 'verification_flags'");
    if (!$check_table || $check_table->num_rows == 0) {
        if ($check_table) {
            mysqli_free_result($check_table);
        }
        return 'not_verified';
    }
    if ($check_table) {
        mysqli_free_result($check_table);
    }
    
    // Convert section/item to string for comparison
    $section_str = is_numeric($section_number) ? (string)$section_number : $section_number;
    $item_str = is_numeric($item_number) ? (string)$item_number : (string)$item_number;
    
    // Get verification status for this specific item
    $query = "SELECT vf.verification_status, vf.is_locked, vf.committee_email, vca.role_name
              FROM verification_flags vf
              LEFT JOIN verification_committee_assignments vca 
                ON vf.committee_email = vca.committee_email 
                AND vf.section_number = vca.section_number 
                AND vf.item_number = vca.item_number
              WHERE vf.dept_id = ? 
                AND vf.academic_year = ? 
                AND vf.section_number = ?
                AND vf.item_number = ?";
    
    $params = [$dept_id, $academic_year, $section_str, $item_str];
    $types = "isis";
    
    if ($committee_email) {
        $query .= " AND vf.committee_email = ?";
        $params[] = $committee_email;
        $types .= "s";
    }
    
    $query .= " ORDER BY vf.verified_at DESC LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $status = 'not_verified';
    
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $verification_status = $row['verification_status'] ?? '';
            $role_name = strtoupper($row['role_name'] ?? '');
            
            // Build status string
            if ($verification_status === 'verified_correct') {
                $status = 'verified_correct_' . strtolower($role_name);
            } elseif ($verification_status === 'verified_incorrect') {
                $status = 'verified_incorrect_' . strtolower($role_name);
            }
        }
        if ($result) {
            mysqli_free_result($result);
        }
        $stmt->close();
    }
    
    return $status;
}

/**
 * Get list of other users who have verified the same item (for expert/chairman view)
 * Returns array of ['email' => email, 'role' => role_name, 'status' => verification_status, 'verified_at' => timestamp]
 */
function getOtherVerifiersForItem($dept_id, $section_number, $item_number, $academic_year = null, $exclude_email = null) {
    global $conn;
    
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        return [];
    }
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Check if verification_flags table exists
    $check_table = @$conn->query("SHOW TABLES LIKE 'verification_flags'");
    if (!$check_table || $check_table->num_rows == 0) {
        if ($check_table) {
            mysqli_free_result($check_table);
        }
        return [];
    }
    if ($check_table) {
        mysqli_free_result($check_table);
    }
    
    // Convert section/item to string for comparison
    $section_str = is_numeric($section_number) ? (string)$section_number : (string)$section_number;
    $item_str = is_numeric($item_number) ? (string)$item_number : (string)$item_number;
    
    // Get all verifiers for this item (excluding current user if specified)
    $query = "SELECT DISTINCT 
                vf.committee_email,
                vf.verification_status,
                vf.is_locked,
                vf.verified_at,
                COALESCE(vcu.ROLE, vca.role_name, 'Unknown') AS role_name,
                COALESCE(vcu.NAME, '') AS name
              FROM verification_flags vf
              LEFT JOIN verification_committee_users vcu ON LOWER(vf.committee_email) = LOWER(vcu.EMAIL)
              LEFT JOIN verification_committee_assignments vca 
                ON LOWER(vf.committee_email) = LOWER(vca.committee_email)
                AND vf.section_number = vca.section_number 
                AND vf.item_number = vca.item_number
              WHERE vf.dept_id = ? 
                AND vf.academic_year = ? 
                AND vf.section_number = ?
                AND vf.item_number = ?";
    
    $params = [$dept_id, $academic_year, $section_str, $item_str];
    $types = "isis";
    
    if ($exclude_email) {
        $query .= " AND LOWER(vf.committee_email) != LOWER(?)";
        $params[] = $exclude_email;
        $types .= "s";
    }
    
    $query .= " ORDER BY vf.verified_at DESC";
    
    $verifiers = [];
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $verifiers[] = [
                'email' => $row['committee_email'],
                'role' => $row['role_name'] ?? 'Unknown',
                'name' => $row['name'] ?? '',
                'status' => $row['verification_status'] ?? 'not_verified',
                'is_locked' => (bool)($row['is_locked'] ?? false),
                'verified_at' => $row['verified_at'] ?? null
            ];
        }
        if ($result) {
            mysqli_free_result($result);
        }
        $stmt->close();
    }
    
    return $verifiers;
}

/**
 * Get ALL verifiers for an item with their individual statuses
 * Returns: [['role' => 'HRDC', 'status' => 'verified_incorrect'], ['role' => 'AAQA', 'status' => 'verified_correct'], ...]
 * Each verifier is returned separately with their own status
 */
function getAllVerifiersForItem($dept_id, $section_number, $item_number, $academic_year = null) {
    global $conn;
    
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        return [];
    }
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Check if verification_flags table exists
    $check_table = @$conn->query("SHOW TABLES LIKE 'verification_flags'");
    if (!$check_table || $check_table->num_rows == 0) {
        if ($check_table) {
            mysqli_free_result($check_table);
        }
        return [];
    }
    if ($check_table) {
        mysqli_free_result($check_table);
    }
    
    // Convert section/item to string for comparison
    $section_str = is_numeric($section_number) ? (string)$section_number : (string)$section_number;
    $item_str = is_numeric($item_number) ? (string)$item_number : (string)$item_number;
    
    // Get ALL verifiers for this item (only locked ones) with their individual statuses
    // CRITICAL: Remove DISTINCT to ensure each user's verification is returned separately
    // Each user has their own row in verification_flags (unique constraint on committee_email)
    // CRITICAL: Use CAST to handle both VARCHAR and INT item_number columns
    // CRITICAL: Include remark column if it exists
    $query = "SELECT 
                vf.id,
                vf.committee_email,
                vf.verification_status,
                vf.is_locked,
                COALESCE(vf.remark, '') AS remark,
                COALESCE(vcu.ROLE, vca.role_name, 'Unknown') AS role_name
              FROM verification_flags vf
              LEFT JOIN verification_committee_users vcu ON LOWER(vf.committee_email) = LOWER(vcu.EMAIL)
              LEFT JOIN verification_committee_assignments vca 
                ON LOWER(vf.committee_email) = LOWER(vca.committee_email)
                AND vf.section_number = vca.section_number 
                AND CAST(vf.item_number AS CHAR) = CAST(vca.item_number AS CHAR)
              WHERE vf.dept_id = ? 
                AND vf.academic_year = ? 
                AND vf.section_number = ?
                AND CAST(vf.item_number AS CHAR) = ?
                AND vf.is_locked = 1
              ORDER BY vf.verified_at DESC";
    
    $verifiers = [];
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("isis", $dept_id, $academic_year, $section_str, $item_str);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $role = trim($row['role_name'] ?? '');
            $status = $row['verification_status'] ?? 'not_verified';
            $email = trim($row['committee_email'] ?? '');
            
            // CRITICAL: Include email in the array to ensure uniqueness
            // This ensures each user's verification is stored separately
            if (!empty($role) && $role !== 'Unknown' && !empty($email)) {
                // Check if we already have this email (shouldn't happen due to unique constraint, but safety check)
                $already_exists = false;
                foreach ($verifiers as $existing) {
                    if ($existing['email'] === $email && $existing['role'] === $role) {
                        $already_exists = true;
                        break;
                    }
                }
                
                if (!$already_exists) {
                    $verifiers[] = [
                        'email' => $email,
                        'role' => $role,
                        'status' => $status,
                        'remark' => trim($row['remark'] ?? '')
                    ];
                }
            }
        }
        if ($result) {
            mysqli_free_result($result);
        }
        $stmt->close();
    }
    
    return $verifiers;
}

/**
 * Save verification status for an item
 * @param string $remark Optional remark/note from verification committee member
 */
function saveVerificationStatus($dept_id, $section_number, $item_number, $committee_email, $verification_status, $academic_year = null, $lock = false, $remark = null) {
    global $conn;
    
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        return ['success' => false, 'message' => 'Database connection error'];
    }
    
    // Enforce numeric casting for IDs
    $dept_id = (int)$dept_id;
    
    try {
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Validate verification_status
    if (!in_array($verification_status, ['verified_correct', 'verified_incorrect'])) {
        return ['success' => false, 'message' => 'Invalid verification status'];
    }
    
    // Convert section/item to string
    $section_str = is_numeric($section_number) ? (string)$section_number : $section_number;
    $item_str = is_numeric($item_number) ? (string)$item_number : (string)$item_number;
    
    // Get verification_user_id from verification_committee_users table (if exists)
    // SECURITY: Use prepared statement for email lookup
    $verification_user_id = null;
    // EXCEPTION: SHOW TABLES doesn't support prepared statements, but table name is hardcoded (safe)
    $table_check = @$conn->query("SHOW TABLES LIKE 'verification_committee_users'");
    if ($table_check && $table_check->num_rows > 0) {
        // SECURITY: Free result set per UNIFIED_SECURITY_GUIDE.md
        mysqli_free_result($table_check);
        // SECURITY: Use prepared statement for user lookup
        $user_stmt = @$conn->prepare("SELECT id FROM verification_committee_users WHERE LOWER(EMAIL) = LOWER(?) AND is_active = 1 LIMIT 1");
        if ($user_stmt) {
            $user_stmt->bind_param("s", $committee_email);
            if ($user_stmt->execute()) {
                if (is_callable([$user_stmt, 'get_result'])) {
                    $user_result = $user_stmt->get_result();
                    if ($user_result && $user_result->num_rows > 0) {
                        $user_row = $user_result->fetch_assoc();
                        // SECURITY: Type cast numeric parameter per UNIFIED_SECURITY_GUIDE.md
                        $verification_user_id = (int)($user_row['id'] ?? 0);
                    }
                    // SECURITY: Free result set per UNIFIED_SECURITY_GUIDE.md
                    if ($user_result) {
                        mysqli_free_result($user_result);
                    }
                } else {
                    $user_stmt->store_result();
                    $user_stmt->bind_result($user_id);
                    if ($user_stmt->fetch()) {
                        // SECURITY: Type cast numeric parameter per UNIFIED_SECURITY_GUIDE.md
                        $verification_user_id = (int)$user_id;
                    }
                }
            }
            // SECURITY: Close statement per UNIFIED_SECURITY_GUIDE.md
            $user_stmt->close();
        }
    } else {
        // SECURITY: Free result set per UNIFIED_SECURITY_GUIDE.md (in ALL code paths)
        if ($table_check) {
            mysqli_free_result($table_check);
        }
    }
    
    // SECURITY: Check if verification_user_id column exists
    // EXCEPTION: SHOW COLUMNS doesn't support prepared statements in MySQL/MariaDB
    // Since column_name is a hardcoded constant (not user input), this is safe
    // Using mysqli_real_escape_string as additional safety measure per security guide
    $has_verification_user_id = false;
    $column_name = 'verification_user_id'; // Hardcoded constant - safe
    $column_name_escaped = mysqli_real_escape_string($conn, $column_name);
    $column_check_query = "SHOW COLUMNS FROM verification_flags LIKE '" . $column_name_escaped . "'";
    $column_result = @$conn->query($column_check_query);
    if ($column_result) {
        $has_verification_user_id = ($column_result->num_rows > 0);
        // SECURITY: Free result set per UNIFIED_SECURITY_GUIDE.md
        mysqli_free_result($column_result);
    } else {
        // If query fails, assume column doesn't exist (safe fallback)
        error_log("[saveVerificationStatus] Column check failed for verification_user_id: " . $conn->error);
        $has_verification_user_id = false;
    }
    
    // SECURITY: Check if remark column exists
    // EXCEPTION: SHOW COLUMNS doesn't support prepared statements in MySQL/MariaDB
    // Since column_name is a hardcoded constant (not user input), this is safe
    // Using mysqli_real_escape_string as additional safety measure per security guide
    $has_remark = false;
    $remark_column_name = 'remark'; // Hardcoded constant - safe
    $remark_column_escaped = mysqli_real_escape_string($conn, $remark_column_name);
    $remark_check_query = "SHOW COLUMNS FROM verification_flags LIKE '" . $remark_column_escaped . "'";
    $remark_result = @$conn->query($remark_check_query);
    if ($remark_result) {
        $has_remark = ($remark_result->num_rows > 0);
        // SECURITY: Free result set per UNIFIED_SECURITY_GUIDE.md
        mysqli_free_result($remark_result);
    } else {
        // If query fails, assume column doesn't exist (safe fallback)
        error_log("[saveVerificationStatus] Column check failed for remark: " . $conn->error);
        $has_remark = false;
    }
    
    // SECURITY: Sanitize remark input per UNIFIED_SECURITY_GUIDE.md
    $remark_clean = $remark !== null ? trim($remark) : null;
    if ($remark_clean === '') {
        $remark_clean = null;
    }
    // Note: Remark will be bound as string parameter in prepared statement, so no additional escaping needed
    
    // Check if record exists
    $check_query = "SELECT id, is_locked FROM verification_flags 
                    WHERE dept_id = ? 
                      AND academic_year = ? 
                      AND section_number = ? 
                      AND item_number = ? 
                      AND committee_email = ? 
                    LIMIT 1";
    $stmt = $conn->prepare($check_query);
    $exists = false;
    $is_locked = false;
    $flag_id = null;
    
    if ($stmt) {
        $stmt->bind_param("issss", $dept_id, $academic_year, $section_str, $item_str, $committee_email);
        if ($stmt->execute()) {
            if (is_callable([$stmt, 'get_result'])) {
                $result = $stmt->get_result();
                if ($result && ($row = $result->fetch_assoc())) {
                    $exists = true;
                    $is_locked = (bool)($row['is_locked'] ?? false);
                    $flag_id = (int)($row['id'] ?? 0);
                }
                // SECURITY: Free result set per UNIFIED_SECURITY_GUIDE.md (in ALL code paths)
                if ($result) {
                    mysqli_free_result($result);
                }
            } else {
                $stmt->store_result();
                $stmt->bind_result($row_id, $row_is_locked);
                if ($stmt->fetch()) {
                    $exists = true;
                    $is_locked = (bool)$row_is_locked;
                    $flag_id = (int)$row_id;
                }
            }
        }
        // SECURITY: Close statement per UNIFIED_SECURITY_GUIDE.md (in ALL code paths)
        $stmt->close();
    } else {
        // SECURITY: Log error if prepare fails
        error_log("[saveVerificationStatus] Prepare failed for check_query: " . $conn->error);
        verification_committee_log("[saveVerificationStatus] Prepare failed for check_query: " . $conn->error);
    }
    
    // If locked and trying to update without unlock, return error
    if ($exists && $is_locked && !$lock) {
        return ['success' => false, 'message' => 'This verification is locked. Use Update button to modify.'];
    }
    
    // SECURITY: Type cast numeric parameters per UNIFIED_SECURITY_GUIDE.md
    $is_locked_value = $lock ? 1 : ($exists ? (int)$is_locked : 0);
    $verified_at = $lock ? date('Y-m-d H:i:s') : null;
    $verification_user_id_param = $verification_user_id ? (int)$verification_user_id : 0;
    
    if ($exists) {
        // Update existing record (also update verification_user_id and remark if columns exist)
        if ($has_verification_user_id && $has_remark) {
            $update_query = "UPDATE verification_flags 
                            SET verification_status = ?, 
                                is_locked = ?,
                                verified_at = ?,
                                verification_user_id = COALESCE(verification_user_id, NULLIF(?, 0)),
                                remark = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?";
        } elseif ($has_verification_user_id) {
            $update_query = "UPDATE verification_flags 
                            SET verification_status = ?, 
                                is_locked = ?,
                                verified_at = ?,
                                verification_user_id = COALESCE(verification_user_id, NULLIF(?, 0)),
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?";
        } elseif ($has_remark) {
            $update_query = "UPDATE verification_flags 
                            SET verification_status = ?, 
                                is_locked = ?,
                                verified_at = ?,
                                remark = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?";
        } else {
            $update_query = "UPDATE verification_flags 
                            SET verification_status = ?, 
                                is_locked = ?,
                                verified_at = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?";
        }
        // SECURITY: Use prepared statement per UNIFIED_SECURITY_GUIDE.md
        $stmt = $conn->prepare($update_query);
        if ($stmt) {
            if ($has_verification_user_id && $has_remark) {
                $bind_success = $stmt->bind_param("sisisi", $verification_status, $is_locked_value, $verified_at, $verification_user_id_param, $remark_clean, $flag_id);
            } elseif ($has_verification_user_id) {
                $bind_success = $stmt->bind_param("sisii", $verification_status, $is_locked_value, $verified_at, $verification_user_id_param, $flag_id);
            } elseif ($has_remark) {
                $bind_success = $stmt->bind_param("sissi", $verification_status, $is_locked_value, $verified_at, $remark_clean, $flag_id);
            } else {
                $bind_success = $stmt->bind_param("sisi", $verification_status, $is_locked_value, $verified_at, $flag_id);
            }
            
            if (!$bind_success) {
                error_log("[saveVerificationStatus] bind_param failed: " . $stmt->error);
                verification_committee_log("[saveVerificationStatus] bind_param failed: " . $stmt->error);
                $stmt->close();
                return ['success' => false, 'message' => 'Failed to bind parameters: ' . $stmt->error];
            }
            
            $success = $stmt->execute();
            if (!$success) {
                error_log("[saveVerificationStatus] Update execute failed: " . $stmt->error . " | Connection error: " . $conn->error);
                verification_committee_log("[saveVerificationStatus] Update execute failed: stmt_error=" . $stmt->error . " conn_error=" . $conn->error);
            }
            $stmt->close();
            
            if ($success) {
                return ['success' => true, 'message' => 'Verification status updated successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to update verification status: ' . $stmt->error];
            }
        } else {
            error_log("[saveVerificationStatus] Prepare failed: " . $conn->error);
            verification_committee_log("[saveVerificationStatus] Prepare failed: " . $conn->error);
            return ['success' => false, 'message' => 'Failed to prepare update query: ' . $conn->error];
        }
    } else {
        // Insert new record (include verification_user_id and remark if columns exist)
        if ($has_verification_user_id && $has_remark) {
            $insert_query = "INSERT INTO verification_flags 
                            (dept_id, academic_year, section_number, item_number, verification_user_id, committee_email, verification_status, is_locked, verified_at, remark)
                            VALUES (?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?)";
        } elseif ($has_verification_user_id) {
            $insert_query = "INSERT INTO verification_flags 
                            (dept_id, academic_year, section_number, item_number, verification_user_id, committee_email, verification_status, is_locked, verified_at)
                            VALUES (?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?)";
        } elseif ($has_remark) {
            $insert_query = "INSERT INTO verification_flags 
                            (dept_id, academic_year, section_number, item_number, committee_email, verification_status, is_locked, verified_at, remark)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        } else {
            $insert_query = "INSERT INTO verification_flags 
                            (dept_id, academic_year, section_number, item_number, committee_email, verification_status, is_locked, verified_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        }
        // SECURITY: Use prepared statement per UNIFIED_SECURITY_GUIDE.md
        $stmt = $conn->prepare($insert_query);
        if ($stmt) {
            if ($has_verification_user_id && $has_remark) {
                $bind_success = $stmt->bind_param("isssississ", $dept_id, $academic_year, $section_str, $item_str, $verification_user_id_param, $committee_email, $verification_status, $is_locked_value, $verified_at, $remark_clean);
            } elseif ($has_verification_user_id) {
                $bind_success = $stmt->bind_param("isssissis", $dept_id, $academic_year, $section_str, $item_str, $verification_user_id_param, $committee_email, $verification_status, $is_locked_value, $verified_at);
            } elseif ($has_remark) {
                $bind_success = $stmt->bind_param("isssssiss", $dept_id, $academic_year, $section_str, $item_str, $committee_email, $verification_status, $is_locked_value, $verified_at, $remark_clean);
            } else {
                $bind_success = $stmt->bind_param("isssssis", $dept_id, $academic_year, $section_str, $item_str, $committee_email, $verification_status, $is_locked_value, $verified_at);
            }
            
            if (!$bind_success) {
                error_log("[saveVerificationStatus] INSERT bind_param failed: " . $stmt->error);
                verification_committee_log("[saveVerificationStatus] INSERT bind_param failed: " . $stmt->error);
                $stmt->close();
                return ['success' => false, 'message' => 'Failed to bind parameters: ' . $stmt->error];
            }
            
            $success = $stmt->execute();
            if (!$success) {
                error_log("[saveVerificationStatus] INSERT execute failed: " . $stmt->error . " | Connection error: " . $conn->error);
                verification_committee_log("[saveVerificationStatus] INSERT execute failed: stmt_error=" . $stmt->error . " conn_error=" . $conn->error);
            }
            $stmt->close();
            
            if ($success) {
                return ['success' => true, 'message' => 'Verification status saved successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to insert verification status: ' . $stmt->error];
            }
        } else {
            error_log("[saveVerificationStatus] INSERT Prepare failed: " . $conn->error);
            verification_committee_log("[saveVerificationStatus] INSERT Prepare failed: " . $conn->error);
            return ['success' => false, 'message' => 'Failed to prepare insert query: ' . $conn->error];
        }
    }
    
    // Fallback return (should not reach here, but safety measure)
    error_log("[saveVerificationStatus] Unexpected code path reached");
    return ['success' => false, 'message' => 'Failed to save verification status'];
    } catch (Exception $e) {
        // SECURITY: Error handling per UNIFIED_SECURITY_GUIDE.md - log error, don't expose internal details
        error_log("[saveVerificationStatus] Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        verification_committee_log("[saveVerificationStatus] Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        return ['success' => false, 'message' => 'An error occurred. Please try again.'];
    } catch (Error $e) {
        // SECURITY: Error handling per UNIFIED_SECURITY_GUIDE.md - log fatal error, don't expose internal details
        error_log("[saveVerificationStatus] Fatal error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        verification_committee_log("[saveVerificationStatus] Fatal error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        return ['success' => false, 'message' => 'A fatal error occurred. Please contact administrator.'];
    }
}

/**
 * Get all categories with verification progress for committee member
 */
function getCategoriesWithVerificationProgress($committee_email, $role_name = null, $academic_year = null) {
    global $conn;
    
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        return [];
    }
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    if (!$role_name) {
        $role_name = getCommitteeRoleName($committee_email);
    }
    
    $categories = [
        'Sciences and Technology',
        'Management, Institutions,   Sub-campus, Constituent or Conducted/ Model Colleges',
        'Languages',
        'Humanities, and Social Sciences, Commerce',
        'Interdisciplinary',
        'Centre of Studies, Centre of Excellence, Chairs'
    ];
    
    $assigned_items = getAssignedItems($committee_email, $role_name);
    
    if (empty($assigned_items)) {
        return [];
    }
    
    $result = [];
    foreach ($categories as $index => $category) {
        // Get departments in this category
        $dept_query = "SELECT DISTINCT dm.DEPT_ID, dm.DEPT_NAME, dm.DEPT_COLL_NO
                      FROM department_profiles dp
                      INNER JOIN department_master dm 
                        ON CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
                      WHERE dp.category = ? AND dp.A_YEAR = ?";
        $stmt = $conn->prepare($dept_query);
        $departments = [];
        $total_depts = 0;
        $reviewed_depts = 0;
        $pending_depts = 0;
        
        if ($stmt) {
            $stmt->bind_param("ss", $category, $academic_year);
            if (@$stmt->execute()) {
                $result_set = $stmt->get_result();
                if ($result_set) {
                    while ($row = $result_set->fetch_assoc()) {
                        $dept_id = (int)$row['DEPT_ID'];
                        $total_depts++;
                        
                        // Check if all assigned items are verified for this department
                        $all_verified = true;
                        try {
                            foreach ($assigned_items as $item) {
                                $item_status = getItemVerificationStatus($dept_id, $item['section'], $item['item'], $academic_year, $committee_email);
                                if ($item_status === 'not_verified') {
                                    $all_verified = false;
                                    break;
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Error checking verification status for dept $dept_id: " . $e->getMessage());
                            $all_verified = false;
                        }
                        
                        if ($all_verified && $total_depts > 0) {
                            $reviewed_depts++;
                        } else {
                            $pending_depts++;
                        }
                    }
                    // CRITICAL: Always free result set (same as dept_login)
                    mysqli_free_result($result_set);
                }
            }
            // CRITICAL: Always close statement to free resources (same as dept_login)
            $stmt->close();
        }
        
        $result[] = [
            'id' => $index + 1,
            'name' => $category,
            'total_departments' => $total_depts,
            'reviewed' => $reviewed_depts,
            'pending' => $pending_depts
        ];
    }
    
    return $result;
}

/**
 * Get departments in a category with verification status
 */
function getDepartmentsInCategoryForVerification($category_name, $committee_email, $role_name = null, $academic_year = null) {
    global $conn;
    
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        return [];
    }
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    if (!$role_name) {
        $role_name = getCommitteeRoleName($committee_email);
    }
    
    $assigned_items = getAssignedItems($committee_email, $role_name);
    
    $dept_query = "SELECT DISTINCT dm.DEPT_ID, dm.DEPT_NAME, dm.DEPT_COLL_NO
                   FROM department_profiles dp
                   INNER JOIN department_master dm 
                     ON CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
                   WHERE dp.category = ? AND dp.A_YEAR = ?
                   ORDER BY dm.DEPT_NAME";
    $stmt = $conn->prepare($dept_query);
    $departments = [];
    
    if ($stmt) {
        $stmt->bind_param("ss", $category_name, $academic_year);
        if (@$stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $dept_id = (int)$row['DEPT_ID'];
                    
                    // Check verification status for all assigned items
                    $verification_status = 'not_verified';
                    $all_verified = true;
                    $has_verified = false;
                    
                    foreach ($assigned_items as $item) {
                        $item_status = getItemVerificationStatus($dept_id, $item['section'], $item['item'], $academic_year, $committee_email);
                        if ($item_status !== 'not_verified') {
                            $has_verified = true;
                            $verification_status = $item_status;
                        } else {
                            $all_verified = false;
                        }
                    }
                    
                    if ($all_verified && $has_verified) {
                        $verification_status = 'verified';
                    } elseif ($has_verified) {
                        $verification_status = 'partially_verified';
                    }
                    
                    $departments[] = [
                        'DEPT_ID' => $dept_id,
                        'DEPT_NAME' => $row['DEPT_NAME'] ?? 'Unknown',
                        'DEPT_CODE' => $row['DEPT_COLL_NO'] ?? '',
                        'verification_status' => $verification_status
                    ];
                }
                // CRITICAL: Always free result set (same as dept_login)
                mysqli_free_result($result);
            }
        }
        // CRITICAL: Always close statement to free resources (same as dept_login)
        $stmt->close();
    }
    
    return $departments;
}
