<?php
/**
 * Skip Form Component
 * Allows departments to mark forms as complete with default values if they don't have data
 * 
 * Usage: include this file and call displaySkipButton($form_name, $table_name, $default_values)
 */

// Handler for skip form requests - MUST BE BEFORE unified_header.php
if (isset($_POST['action']) && $_POST['action'] == 'skip_form_complete') {
    // Clear all output buffers FIRST
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Suppress errors
    error_reporting(0);
    ini_set('display_errors', 0);

    // Set JSON headers
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }

    try {
        // ✅ Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // ✅ Load session.php if userInfo not in session yet
        if (!isset($_SESSION['userInfo']) && !isset($_SESSION['admin_username'])) {
            if (file_exists(__DIR__ . '/session.php')) {
                ob_start();
                require_once(__DIR__ . '/session.php');
                ob_end_clean();
            }
        }

        // ✅ Get database connection
        if (!isset($conn) || !$conn) {
            if (file_exists(__DIR__ . '/../_DBconnection.php')) {
                require_once(__DIR__ . '/../_DBconnection.php');
            } elseif (file_exists(__DIR__ . '/../config.php')) {
                require_once(__DIR__ . '/../config.php');
            } else {
                throw new Exception('Database connection not available.');
            }
        }

        // ✅ CSRF Protection
        if (file_exists(__DIR__ . '/csrf.php')) {
            require_once __DIR__ . '/csrf.php';
            if (function_exists('validate_csrf')) {
                $csrf_token = $_POST['csrf_token'] ?? '';
                if (empty($csrf_token) || !validate_csrf($csrf_token)) {
                    throw new Exception('Security token validation failed. Please refresh the page and try again.');
                }
            }
        }

        // ✅ Get department ID from session (try all possible locations)
        // CHECK $_SESSION['dept_id'] FIRST (most common location based on diagnostic)
        $dept_id = 0;
        
        // Try direct session dept_id FIRST (this is what diagnostic shows exists!)
        if (isset($_SESSION['dept_id'])) {
            $dept_id = (int)$_SESSION['dept_id'];
        }
        // Try global $userInfo (if available)
        elseif (isset($GLOBALS['userInfo']['DEPT_ID'])) {
            $dept_id = (int)$GLOBALS['userInfo']['DEPT_ID'];
        }
        // Try session userInfo
        elseif (isset($_SESSION['userInfo']['DEPT_ID'])) {
            $dept_id = (int)$_SESSION['userInfo']['DEPT_ID'];
        }
        // Last resort: query database using username
        elseif (isset($_SESSION['admin_username'])) {
            $admin_username = $_SESSION['admin_username'];
            $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ?";
            $dept_stmt = mysqli_prepare($conn, $dept_query);
            if ($dept_stmt) {
                mysqli_stmt_bind_param($dept_stmt, "s", $admin_username);
                mysqli_stmt_execute($dept_stmt);
                $dept_result = mysqli_stmt_get_result($dept_stmt);
                if ($dept_result && $dept_row = mysqli_fetch_assoc($dept_result)) {
                    $dept_id = (int)$dept_row['DEPT_ID'];
                }
                if ($dept_result) mysqli_free_result($dept_result);
                mysqli_stmt_close($dept_stmt);
            }
        }

        if (!$dept_id) {
            // Debug: Show what's in session
            $session_keys = implode(', ', array_keys($_SESSION));
            throw new Exception('Department ID not found in session. Available session keys: ' . $session_keys . '. Please login again.');
        }

        // ✅ Validate form name
        $form_name = trim($_POST['form_name'] ?? '');
        if (empty($form_name)) {
            throw new Exception('Form name is required.');
        }

        // ✅ Validate academic year
        $academic_year = trim($_POST['academic_year'] ?? '');
        if (empty($academic_year)) {
            throw new Exception('Academic year is required.');
        }

        // Define allowed forms and their default data
        $allowed_forms = [
            'executive_development' => [
                'table' => 'exec_dev',
                'defaults' => [
                    'A_YEAR' => $academic_year,
                    'DEPT_ID' => $dept_id,
                    'NO_OF_EXEC_PROGRAMS' => 0,
                    'TOTAL_PARTICIPANTS' => 0,
                    'TOTAL_INCOME' => 0.00
                ],
                'types' => 'siiid',
                'check_query' => "SELECT id FROM exec_dev WHERE DEPT_ID = ? AND A_YEAR = ?",
                'insert_query' => "INSERT INTO exec_dev (A_YEAR, DEPT_ID, NO_OF_EXEC_PROGRAMS, TOTAL_PARTICIPANTS, TOTAL_INCOME) VALUES (?, ?, ?, ?, ?)"
            ],
            'phd' => [
                'table' => 'phd_details',
                'defaults' => [
                    'A_YEAR' => $academic_year,
                    'DEPT_ID' => $dept_id,
                    'FULL_TIME_MALE_STUDENTS' => 0,
                    'FULL_TIME_FEMALE_STUDENTS' => 0,
                    'FULL_TIME_OTHER_STUDENTS' => 0,
                    'PART_TIME_MALE_STUDENTS' => 0,
                    'PART_TIME_FEMALE_STUDENTS' => 0,
                    'PART_TIME_OTHER_STUDENTS' => 0,
                    'PHD_AWARDED_MALE_STUDENTS_FULL' => 0,
                    'PHD_AWARDED_FEMALE_STUDENTS_FULL' => 0,
                    'PHD_AWARDED_OTHER_STUDENTS_FULL' => 0,
                    'PHD_AWARDED_MALE_STUDENTS_PART' => 0,
                    'PHD_AWARDED_FEMALE_STUDENTS_PART' => 0,
                    'PHD_AWARDED_OTHER_STUDENTS_PART' => 0,
                    'PHD_AWARDEES_DETAILS' => json_encode([])
                ],
                'types' => 'siiiiiiiiiiiiis',
                'check_query' => "SELECT id FROM phd_details WHERE DEPT_ID = ? AND A_YEAR = ?",
                'insert_query' => "INSERT INTO phd_details (A_YEAR, DEPT_ID, FULL_TIME_MALE_STUDENTS, FULL_TIME_FEMALE_STUDENTS, FULL_TIME_OTHER_STUDENTS, PART_TIME_MALE_STUDENTS, PART_TIME_FEMALE_STUDENTS, PART_TIME_OTHER_STUDENTS, PHD_AWARDED_MALE_STUDENTS_FULL, PHD_AWARDED_FEMALE_STUDENTS_FULL, PHD_AWARDED_OTHER_STUDENTS_FULL, PHD_AWARDED_MALE_STUDENTS_PART, PHD_AWARDED_FEMALE_STUDENTS_PART, PHD_AWARDED_OTHER_STUDENTS_PART, PHD_AWARDEES_DETAILS) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            ],
             'placement' => [
                'table' => 'placement_details',
                'status_only' => true,
                'form_name_db' => 'Placement',  // Match template record exactly
                'check_query' => "SELECT ID FROM placement_details WHERE DEPT_ID = ? AND A_YEAR = ?",
                'status_check_query' => "SELECT id FROM form_completion_status WHERE dept_id = ? AND form_name = ? AND academic_year = ?",
                'status_insert_query' => "INSERT INTO form_completion_status (dept_id, form_name, academic_year, status, notes) VALUES (?, ?, ?, 'skipped', ?)"
            ],
            'salary' => [
                'table' => 'salary_details',
                'status_only' => true,
                'form_name_db' => 'Salary',  // Match template record exactly
                'check_query' => "SELECT ID FROM salary_details WHERE DEPT_ID = ? AND A_YEAR = ?",
                'status_check_query' => "SELECT id FROM form_completion_status WHERE dept_id = ? AND form_name = ? AND academic_year = ?",
                'status_insert_query' => "INSERT INTO form_completion_status (dept_id, form_name, academic_year, status, notes) VALUES (?, ?, ?, 'skipped', ?)"
            ],
            'employer' => [
                'table' => 'employers_details',
                'status_only' => true,
                'form_name_db' => 'Employer',  // Match template record exactly
                'check_query' => "SELECT ID FROM employers_details WHERE DEPT_ID = ? AND A_YEAR = ?",
                'status_check_query' => "SELECT id FROM form_completion_status WHERE dept_id = ? AND form_name = ? AND academic_year = ?",
                'status_insert_query' => "INSERT INTO form_completion_status (dept_id, form_name, academic_year, status, notes) VALUES (?, ?, ?, 'skipped', ?)"
            ],
            'academic_peers' => [
                'table' => 'academic_peers',
                'status_only' => true,
                'form_name_db' => 'Academic Peers',  // Match template record exactly (with space)
                'check_query' => "SELECT id FROM academic_peers WHERE DEPT_ID = ? AND A_YEAR = ?",
                'status_check_query' => "SELECT id FROM form_completion_status WHERE dept_id = ? AND form_name = ? AND academic_year = ?",
                'status_insert_query' => "INSERT INTO form_completion_status (dept_id, form_name, academic_year, status, notes) VALUES (?, ?, ?, 'skipped', ?)"
            ],
            'conferences_workshops' => [
                'table' => 'conferences_workshops',
                'defaults' => [
                    'A_YEAR' => $academic_year,
                    'DEPT_ID' => $dept_id,
                    'A1' => 0,
                    'A2' => 0,
                    'A3' => 0,
                    'A4' => 0,
                    'A5' => 0,
                    'A6' => 0,
                    'TOTAL_CONFERENCE' => 0
                ],
                'types' => 'siiiiiiii',
                'check_query' => "SELECT id FROM conferences_workshops WHERE DEPT_ID = ? AND A_YEAR = ?",
                'insert_query' => "INSERT INTO conferences_workshops (A_YEAR, DEPT_ID, A1, A2, A3, A4, A5, A6, TOTAL_CONFERENCE) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            ],
            'collaborations' => [
                'table' => 'collaborations',
                'defaults' => [
                    'A_YEAR' => $academic_year,
                    'DEPT_ID' => $dept_id,
                    'B1' => 0,
                    'B2' => 0,
                    'B3' => 0,
                    'B4' => 0,
                    'B5' => 0,
                    'TOTAL_COLLAB' => 0
                ],
                'types' => 'siiiiiii',
                'check_query' => "SELECT id FROM collaborations WHERE DEPT_ID = ? AND A_YEAR = ?",
                'insert_query' => "INSERT INTO collaborations (A_YEAR, DEPT_ID, B1, B2, B3, B4, B5, TOTAL_COLLAB) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            ],
            'nep_initiatives' => [
                'table' => 'nepmarks',
                'defaults' => [
                    'A_YEAR' => $academic_year,
                    'DEPT_ID' => $dept_id,
                    'nep_count' => 0,
                    'ped_count' => 0,
                    'assess_count' => 0,
                    'moocs' => 0,
                    'mooc_data' => json_encode([]),
                    'econtent' => 0.0,
                    'nep_score' => 0,
                    'ped_score' => 0,
                    'assess_score' => 0,
                    'mooc_score' => 0,
                    'econtent_score' => 0.0,
                    'result_days' => 0,
                    'result_score' => 0,
                    'total_marks' => 0,
                    'nep_initiatives' => json_encode([]),
                    'pedagogical' => json_encode([]),
                    'assessments' => json_encode([])
                ],
                'types' => 'siiiiisdiididiiisss',
                'check_query' => "SELECT id FROM nepmarks WHERE DEPT_ID = ? AND A_YEAR = ?",
                'insert_query' => "INSERT INTO nepmarks (A_YEAR, DEPT_ID, nep_count, ped_count, assess_count, moocs, mooc_data, econtent, nep_score, ped_score, assess_score, mooc_score, econtent_score, result_days, result_score, total_marks, nep_initiatives, pedagogical, assessments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            ],
             'departmental_governance' => [
                'table' => 'department_data',
                'defaults' => [
                    'DEPT_ID' => $dept_id,
                    'inclusive_practices' => '-',
                    'inclusive_practices_details' => '-',
                    'green_practices' => '-',
                    'green_practices_details' => '-'
                ],
                'types' => 'issss',
                'check_query' => "SELECT id FROM department_data WHERE DEPT_ID = ?",
                'insert_query' => "INSERT INTO department_data (DEPT_ID, inclusive_practices, inclusive_practices_details, green_practices, green_practices_details) VALUES (?, ?, ?, ?, ?)"
            ],
            'student_support' => [
                'table' => 'studentsupport',
                'defaults' => [
                    'dept' => $dept_id,
                    'A_YEAR' => $academic_year,
                    'JRFS_COUNT' => 0,
                    'SRFS_COUNT' => 0,
                    'POST_DOCTORAL_COUNT' => 0,
                    'RESEARCH_ASSOCIATES_COUNT' => 0
                ],
                'types' => 'isiiii',
                'check_query' => "SELECT id FROM studentsupport WHERE dept = ? AND A_YEAR = ?",
                'insert_query' => "INSERT INTO studentsupport (dept, A_YEAR, JRFS_COUNT, SRFS_COUNT, POST_DOCTORAL_COUNT, RESEARCH_ASSOCIATES_COUNT) VALUES (?, ?, ?, ?, ?, ?)"
            ]
        ];

        // ✅ Validate form exists
        if (!isset($allowed_forms[$form_name])) {
            throw new Exception('Invalid form name.');
        }

        $form_config = $allowed_forms[$form_name];

        // ✅ Special handling for forms that just need status tracking (no dummy records)
        $status_only_forms = ['placement', 'salary', 'employer', 'academic_peers'];
        
        if (in_array($form_name, $status_only_forms)) {
            // ✅ Get the correct form_name for database (must match template records)
            $form_name_db = $form_config['form_name_db'] ?? $form_name;
            
            // ✅ First, verify the table exists and has correct structure
            $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'form_completion_status'");
            if (!$table_check || mysqli_num_rows($table_check) === 0) {
                throw new Exception('CRITICAL ERROR: The form_completion_status table does not exist! Run this SQL in phpMyAdmin: DROP TABLE IF EXISTS `form_completion_status`; CREATE TABLE `form_completion_status` (`id` INT(11) NOT NULL AUTO_INCREMENT, `dept_id` INT(11) NOT NULL, `form_name` VARCHAR(100) NOT NULL, `academic_year` VARCHAR(20) NOT NULL, `status` ENUM(\'skipped\', \'na\', \'completed\') DEFAULT \'skipped\', `marked_date` DATETIME DEFAULT CURRENT_TIMESTAMP, `notes` TEXT DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `unique_form_status` (`dept_id`, `form_name`, `academic_year`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
            }
            
            // Check if table has 'status' column
            $column_check = mysqli_query($conn, "SHOW COLUMNS FROM form_completion_status LIKE 'status'");
            if (!$column_check || mysqli_num_rows($column_check) === 0) {
                throw new Exception('CRITICAL ERROR: The form_completion_status table exists but is missing the \'status\' column! Run this SQL in phpMyAdmin: DROP TABLE IF EXISTS `form_completion_status`; CREATE TABLE `form_completion_status` (`id` INT(11) NOT NULL AUTO_INCREMENT, `dept_id` INT(11) NOT NULL, `form_name` VARCHAR(100) NOT NULL, `academic_year` VARCHAR(20) NOT NULL, `status` ENUM(\'skipped\', \'na\', \'completed\') DEFAULT \'skipped\', `marked_date` DATETIME DEFAULT CURRENT_TIMESTAMP, `notes` TEXT DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `unique_form_status` (`dept_id`, `form_name`, `academic_year`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
            }
            
            // Just mark as complete in status table, no dummy data insertion
            // Use form_name_db to match template records exactly
            $status_check = "SELECT id FROM form_completion_status WHERE dept_id = ? AND form_name = ? AND academic_year = ?";
            $status_check_stmt = mysqli_prepare($conn, $status_check);
            
            if (!$status_check_stmt) {
                throw new Exception('ERROR: Cannot prepare statement. MySQL Error: ' . mysqli_error($conn) . ' - Please run SQL/FIX_FORM_STATUS_TABLE_NOW.sql');
            }
            
            mysqli_stmt_bind_param($status_check_stmt, 'iss', $dept_id, $form_name_db, $academic_year);
            mysqli_stmt_execute($status_check_stmt);
            $status_check_result = mysqli_stmt_get_result($status_check_stmt);
            
            if ($status_check_result && mysqli_num_rows($status_check_result) > 0) {
                mysqli_free_result($status_check_result);
                mysqli_stmt_close($status_check_stmt);
                throw new Exception('Form already marked as complete.');
            }
            
            if ($status_check_result) {
                mysqli_free_result($status_check_result);
            }
            mysqli_stmt_close($status_check_stmt);
            
            // Insert into status table
            // Use form_name_db to match template records exactly (capitalized, with spaces)
            $status_insert = "INSERT INTO form_completion_status (dept_id, form_name, academic_year, status, notes) VALUES (?, ?, ?, 'skipped', 'Marked as N/A - No data available')";
            $status_insert_stmt = mysqli_prepare($conn, $status_insert);
            
            if (!$status_insert_stmt) {
                $error = mysqli_error($conn);
                // Check if error is about missing column
                if (strpos($error, "Unknown column 'status'") !== false) {
                    throw new Exception('CRITICAL ERROR: Table exists but missing \'status\' column! Run this SQL in phpMyAdmin: DROP TABLE IF EXISTS `form_completion_status`; CREATE TABLE `form_completion_status` (`id` INT(11) NOT NULL AUTO_INCREMENT, `dept_id` INT(11) NOT NULL, `form_name` VARCHAR(100) NOT NULL, `academic_year` VARCHAR(20) NOT NULL, `status` ENUM(\'skipped\', \'na\', \'completed\') DEFAULT \'skipped\', `marked_date` DATETIME DEFAULT CURRENT_TIMESTAMP, `notes` TEXT DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `unique_form_status` (`dept_id`, `form_name`, `academic_year`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
                }
                throw new Exception('ERROR: Cannot insert into form_completion_status table! MySQL Error: ' . $error . ' - Please run SQL/FIX_FORM_STATUS_TABLE_NOW.sql');
            }
            
            mysqli_stmt_bind_param($status_insert_stmt, 'iss', $dept_id, $form_name_db, $academic_year);
            
            if (mysqli_stmt_execute($status_insert_stmt)) {
                mysqli_stmt_close($status_insert_stmt);
                echo json_encode([
                    'success' => true,
                    'message' => 'Form marked as complete (N/A - No data).'
                ]);
            } else {
                $error = mysqli_stmt_error($status_insert_stmt);
                mysqli_stmt_close($status_insert_stmt);
                throw new Exception('Failed to mark form as complete: ' . $error);
            }
        } else {
            // Regular forms - insert default values
            // ✅ Check if data already exists (prevent duplicates)
            $check_stmt = mysqli_prepare($conn, $form_config['check_query']);
            
            // Bind parameters based on form configuration
            if ($form_name === 'departmental_governance') {
                // Dept governance only checks DEPT_ID
                mysqli_stmt_bind_param($check_stmt, 'i', $dept_id);
            } else {
                // Most forms check DEPT_ID and A_YEAR
                mysqli_stmt_bind_param($check_stmt, 'is', $dept_id, $academic_year);
            }
            
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);

            if ($check_result && mysqli_num_rows($check_result) > 0) {
                mysqli_free_result($check_result);
                mysqli_stmt_close($check_stmt);
                throw new Exception('Data already exists for this form and academic year.');
            }

            if ($check_result) {
                mysqli_free_result($check_result);
            }
            mysqli_stmt_close($check_stmt);

            // ✅ Insert default values using prepared statement
            $insert_stmt = mysqli_prepare($conn, $form_config['insert_query']);
            
            if (!$insert_stmt) {
                throw new Exception('Failed to prepare insert statement.');
            }

            // Bind parameters dynamically
            $bind_values = array_values($form_config['defaults']);
            mysqli_stmt_bind_param($insert_stmt, $form_config['types'], ...$bind_values);

            if (mysqli_stmt_execute($insert_stmt)) {
                mysqli_stmt_close($insert_stmt);
                echo json_encode([
                    'success' => true,
                    'message' => 'Form marked as complete with default values.'
                ]);
            } else {
                $error = mysqli_stmt_error($insert_stmt);
                mysqli_stmt_close($insert_stmt);
                throw new Exception('Failed to insert default values: ' . $error);
            }
        }

    } catch (Exception $e) {
        error_log("Skip form error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}

/**
 * Display skip form button
 * 
 * @param string $form_name Internal form name (e.g., 'phd', 'executive_development')
 * @param string $display_name User-friendly form name (e.g., 'PhD Details', 'Executive Development')
 * @param string $academic_year Current academic year
 * @param bool $has_existing_data Whether form already has data
 */
function displaySkipFormButton($form_name, $display_name, $academic_year, $has_existing_data = false) {
    // Don't show button if data already exists
    if ($has_existing_data) {
        return;
    }

    // ✅ For status-only forms, also check if form has already been skipped
    $status_only_forms = ['placement', 'salary', 'employer', 'academic_peers'];
    if (in_array($form_name, $status_only_forms)) {
        // Get database connection
        global $conn;
        if (!isset($conn) || !$conn) {
            if (file_exists(__DIR__ . '/../_DBconnection.php')) {
                require_once(__DIR__ . '/../_DBconnection.php');
            } elseif (file_exists(__DIR__ . '/../config.php')) {
                require_once(__DIR__ . '/../config.php');
            }
        }
        
        // Get department ID
        $dept_id = 0;
        if (isset($GLOBALS['userInfo']['DEPT_ID'])) {
            $dept_id = (int)$GLOBALS['userInfo']['DEPT_ID'];
        } elseif (isset($_SESSION['userInfo']['DEPT_ID'])) {
            $dept_id = (int)$_SESSION['userInfo']['DEPT_ID'];
        } elseif (isset($_SESSION['dept_id'])) {
            $dept_id = (int)$_SESSION['dept_id'];
        }
        
        if ($dept_id > 0 && isset($conn) && $conn) {
            // Map form_name to database form_name (must match template records)
            $form_name_map = [
                'placement' => 'Placement',
                'salary' => 'Salary',
                'employer' => 'Employer',
                'academic_peers' => 'Academic Peers'
            ];
            $form_name_db = $form_name_map[$form_name] ?? $form_name;
            
            // Check if form has already been skipped
            $status_check = "SELECT id FROM form_completion_status WHERE dept_id = ? AND form_name = ? AND academic_year = ? AND (status IN ('skipped', 'na', 'completed') OR is_completed = 1) LIMIT 1";
            $status_stmt = mysqli_prepare($conn, $status_check);
            if ($status_stmt) {
                mysqli_stmt_bind_param($status_stmt, "iss", $dept_id, $form_name_db, $academic_year);
                mysqli_stmt_execute($status_stmt);
                $status_result = mysqli_stmt_get_result($status_stmt);
                if ($status_result && mysqli_num_rows($status_result) > 0) {
                    // Form already skipped - don't show button
                    mysqli_free_result($status_result);
                    mysqli_stmt_close($status_stmt);
                    return;
                }
                if ($status_result) {
                    mysqli_free_result($status_result);
                }
                mysqli_stmt_close($status_stmt);
            }
        }
    }

    // Get CSRF token
    $csrf_token = $_SESSION['csrf_token'] ?? '';
    ?>
    <div class="alert alert-info border-info" style="margin: 20px 0; padding: 20px;">
        <div style="display: flex; align-items: start; gap: 15px;">
            <i class="fas fa-info-circle" style="font-size: 24px; color: #0dcaf0; margin-top: 5px;"></i>
            <div style="flex: 1;">
                <h5 class="alert-heading" style="margin: 0 0 10px 0; color: #055160;">
                    <strong>No Data for <?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>?</strong>
                </h5>
                <p style="margin: 0 0 10px 0; line-height: 1.6;">
                    If your department has <strong>NO data</strong> for this form, click the button below to mark it complete with default values (zeros/N/A).
                </p>
                <p style="margin: 0 0 10px 0; line-height: 1.6;">
                    <i class="fas fa-exclamation-triangle me-2 text-danger"></i>
                    <strong>Only use if you genuinely have NO data.</strong> You can add real data later by editing the form.
                </p>
                <button type="button" 
                        class="btn btn-outline-info btn-lg" 
                        onclick="skipFormAndMarkComplete('<?php echo htmlspecialchars($form_name, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>')"
                        style="padding: 10px 30px;">
                    <i class="fas fa-forward me-2"></i>
                    Skip This Form & Mark as Complete
                </button>
            </div>
        </div>
    </div>

    <script>
    function skipFormAndMarkComplete(formName, displayName, academicYear, csrfToken) {
        // Double confirmation
        if (!confirm(`⚠️ Are you sure you want to skip "${displayName}" and mark it as complete?\n\n` +
                     `This will submit default/zero values for ${academicYear}.\n\n` +
                     `✅ You CAN update and add real data later if needed.\n\n` +
                     `Only proceed if your department currently has NO data for this form.`)) {
            return;
        }

        // Second confirmation
        if (!confirm(`⚠️ FINAL CONFIRMATION\n\n` +
                     `You are about to mark "${displayName}" as complete with default values.\n\n` +
                     `You can add real data later by editing this form.\n\n` +
                     `Continue?`)) {
            return;
        }

        // Show loading state
        const button = event.target.closest('button');
        const originalHTML = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';

        // Send AJAX request
        const formData = new FormData();
        formData.append('action', 'skip_form_complete');
        formData.append('form_name', formName);
        formData.append('academic_year', academicYear);
        formData.append('csrf_token', csrfToken);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // Check if response is valid JSON
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Response is not valid JSON:', text);
                    throw new Error('Server returned invalid response: ' + text.substring(0, 200));
                }
            });
        })
        .then(data => {
            if (data.success) {
                alert('✅ ' + data.message + '\n\nForm has been marked as complete. Redirecting to dashboard...');
                window.location.href = 'dashboard.php';
            } else {
                alert('❌ Error: ' + data.message);
                button.disabled = false;
                button.innerHTML = originalHTML;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('❌ Error: ' + error.message + '\n\nCheck browser console for details.');
            button.disabled = false;
            button.innerHTML = originalHTML;
        });
    }
    </script>
    <?php
}
?>

