<?php
/**
 * AAQA - Delete Department
 * SECURITY: Uses prepared statements, proper error handling, transaction management, integrity checks
 */

require('session.php');
require_once(__DIR__ . '/../config.php');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$success_message = '';
$error_message = '';
$dept_id = 0;
$confirm_delete = false;
$dept_info = null;
$dependencies = [];

// SECURITY: Validate and sanitize input
try {
    // Check database connection first
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection not available. Please try again.');
    }
    
    // Validate dept_id input
    $dept_id_input = $_GET['dept_id'] ?? $_POST['dept_id'] ?? '';
    if (!empty($dept_id_input)) {
        $dept_id = (int)$dept_id_input;
        if ($dept_id <= 0) {
            throw new Exception('Invalid department ID provided.');
        }
    }
    
    $confirm_delete = isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'yes';
    
    // Get department details only if valid dept_id provided
    if ($dept_id > 0) {
        try {
            $stmt = $conn->prepare("SELECT DEPT_ID, DEPT_COLL_NO, DEPT_NAME, EMAIL, HOD_NAME FROM department_master WHERE DEPT_ID = ?");
            if (!$stmt) {
                throw new Exception('Database query preparation failed: ' . $conn->error);
            }
            
            $stmt->bind_param("i", $dept_id);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to execute database query.');
            }
            
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $dept_info = $row;
            }
            
            if ($result) {
                mysqli_free_result($result);
            }
            $stmt->close();
            
            // If department not found, set appropriate message
            if (!$dept_info) {
                $error_message = 'Department not found. It may have been already deleted.';
            }
        } catch (Exception $e) {
            error_log("AAQA DeleteDepartment: Error fetching department info - " . $e->getMessage());
            $error_message = 'An error occurred while fetching department information.';
            $dept_info = null;
        }
    }
    
    // Check dependencies before deletion (only if department exists)
    if ($dept_id > 0 && $dept_info && isset($conn) && $conn) {
        try {
            $dept_code = $dept_info['DEPT_COLL_NO'] ?? 0;
            
            // Check various tables for dependencies
            $check_tables = [
                'department_data' => "SELECT COUNT(*) as cnt FROM department_data WHERE DEPT_ID = ?",
                'department_profiles' => "SELECT COUNT(*) as cnt FROM department_profiles WHERE dept_id = ? OR dept_id = ?",
                'department_scores' => "SELECT COUNT(*) as cnt FROM department_scores WHERE dept_id = ?",
                'brief_details_of_the_department' => "SELECT COUNT(*) as cnt FROM brief_details_of_the_department WHERE DEPT_ID = ?",
                'faculty_details' => "SELECT COUNT(*) as cnt FROM faculty_details WHERE DEPT_ID = ?",
                'intake_actual_strength' => "SELECT COUNT(*) as cnt FROM intake_actual_strength WHERE DEPT_ID = ?",
                'placement_details' => "SELECT COUNT(*) as cnt FROM placement_details WHERE DEPT_ID = ?",
                'phd_details' => "SELECT COUNT(*) as cnt FROM phd_details WHERE DEPT_ID = ?",
                'conferences_workshops' => "SELECT COUNT(*) as cnt FROM conferences_workshops WHERE DEPT_ID = ?",
                'collaborations' => "SELECT COUNT(*) as cnt FROM collaborations WHERE DEPT_ID = ?"
            ];
            
            foreach ($check_tables as $table => $query) {
                try {
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        error_log("AAQA DeleteDepartment: Failed to prepare query for table: $table");
                        continue; // Skip this table, continue with others
                    }
                    
                    if (strpos($query, 'department_profiles') !== false) {
                        $dept_id_str = (string)$dept_id;
                        $dept_code_str = (string)$dept_code;
                        $stmt->bind_param("ss", $dept_id_str, $dept_code_str);
                    } else {
                        $stmt->bind_param("i", $dept_id);
                    }
                    
                    if (!$stmt->execute()) {
                        error_log("AAQA DeleteDepartment: Failed to execute query for table: $table");
                        $stmt->close();
                        continue; // Skip this table, continue with others
                    }
                    
                    $result = $stmt->get_result();
                    if ($result && $row = $result->fetch_assoc()) {
                        $count = (int)($row['cnt'] ?? 0);
                        if ($count > 0) {
                            $dependencies[$table] = $count;
                        }
                    }
                    
                    if ($result) {
                        mysqli_free_result($result);
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    error_log("AAQA DeleteDepartment: Error checking dependencies for table $table - " . $e->getMessage());
                    // Continue checking other tables even if one fails
                    if (isset($stmt)) {
                        $stmt->close();
                    }
                }
            }
        } catch (Exception $e) {
            error_log("AAQA DeleteDepartment: Error in dependency check - " . $e->getMessage());
            // Don't block the page, just log the error
        }
    }
} catch (Exception $e) {
    error_log("AAQA DeleteDepartment: Fatal error - " . $e->getMessage());
    $error_message = 'An error occurred. Please try again.';
} catch (Error $e) {
    error_log("AAQA DeleteDepartment: Fatal PHP error - " . $e->getMessage());
    $error_message = 'A system error occurred. Please contact the administrator.';
}

// Process deletion if confirmed
if ($confirm_delete && $dept_id > 0 && $dept_info && isset($conn) && $conn) {
    // SECURITY: Validate CSRF token if available
    if (file_exists(__DIR__ . '/../csrf.php')) {
        require_once __DIR__ . '/../csrf.php';
        if (function_exists('validate_csrf')) {
            $csrf_token = $_POST['csrf_token'] ?? '';
            if (empty($csrf_token) || !validate_csrf($csrf_token)) {
                $error_message = 'Security token validation failed. Please refresh the page and try again.';
            }
        }
    }
    
    // SECURITY: Re-validate department exists before deletion
    if (empty($error_message) && $dept_id > 0) {
        try {
            $verify_stmt = $conn->prepare("SELECT DEPT_ID FROM department_master WHERE DEPT_ID = ?");
            if ($verify_stmt) {
                $verify_stmt->bind_param("i", $dept_id);
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();
                $dept_exists = ($verify_result && $verify_result->num_rows > 0);
                if ($verify_result) {
                    mysqli_free_result($verify_result);
                }
                $verify_stmt->close();
                
                if (!$dept_exists) {
                    $error_message = 'Department not found. It may have been already deleted.';
                }
            }
        } catch (Exception $e) {
            error_log("AAQA DeleteDepartment: Error verifying department - " . $e->getMessage());
            $error_message = 'An error occurred while verifying the department.';
        }
    }
    
    if (empty($error_message) && $dept_id > 0 && $dept_info) {
        // SECURITY: Use transaction for atomic deletion
        if (!$conn->begin_transaction()) {
            $error_message = 'Failed to start database transaction.';
            error_log("AAQA DeleteDepartment: Failed to begin transaction");
        } else {
            try {
                $dept_code = $dept_info['DEPT_COLL_NO'] ?? 0;
                $dept_id_str = (string)$dept_id;
                $dept_code_str = (string)$dept_code;
                
                // Delete from related tables (in order to avoid foreign key issues)
                $delete_queries = [
                    'department_scores' => "DELETE FROM department_scores WHERE dept_id = ?",
                    'department_data' => "DELETE FROM department_data WHERE DEPT_ID = ?",
                    'department_profiles' => "DELETE FROM department_profiles WHERE dept_id = ? OR dept_id = ?",
                    'brief_details_of_the_department' => "DELETE FROM brief_details_of_the_department WHERE DEPT_ID = ?",
                    'faculty_details' => "DELETE FROM faculty_details WHERE DEPT_ID = ?",
                    'intake_actual_strength' => "DELETE FROM intake_actual_strength WHERE DEPT_ID = ?",
                    'placement_details' => "DELETE FROM placement_details WHERE DEPT_ID = ?",
                    'phd_details' => "DELETE FROM phd_details WHERE DEPT_ID = ?",
                    'conferences_workshops' => "DELETE FROM conferences_workshops WHERE DEPT_ID = ?",
                    'collaborations' => "DELETE FROM collaborations WHERE DEPT_ID = ?",
                    'colleges' => "DELETE FROM colleges WHERE department_id = ? OR collno = ?",
                    'departmentnames' => "DELETE FROM departmentnames WHERE collno = ?",
                    'department_master' => "DELETE FROM department_master WHERE DEPT_ID = ?"
                ];
                
                foreach ($delete_queries as $table => $query) {
                    try {
                        $stmt = $conn->prepare($query);
                        if (!$stmt) {
                            error_log("AAQA DeleteDepartment: Failed to prepare delete query for table: $table - " . $conn->error);
                            throw new Exception("Failed to prepare delete query for table: $table");
                        }
                        
                        if (strpos($query, 'department_profiles') !== false || strpos($query, 'colleges') !== false) {
                            $stmt->bind_param("ss", $dept_id_str, $dept_code_str);
                        } else {
                            $stmt->bind_param("i", $dept_id);
                        }
                        
                        if (!$stmt->execute()) {
                            error_log("AAQA DeleteDepartment: Failed to execute delete for table: $table - " . $stmt->error);
                            $stmt->close();
                            throw new Exception("Failed to delete from table: $table");
                        }
                        
                        $stmt->close();
                    } catch (Exception $e) {
                        error_log("AAQA DeleteDepartment: Error deleting from table $table - " . $e->getMessage());
                        throw $e; // Re-throw to trigger rollback
                    }
                }
                
                if (!$conn->commit()) {
                    throw new Exception("Failed to commit transaction: " . $conn->error);
                }
                
                $success_message = "Department deleted successfully!";
                $dept_info = null; // Clear department info after successful deletion
                $dept_id = 0; // Reset dept_id
                
            } catch (Exception $e) {
                if ($conn->in_transaction) {
                    $conn->rollback();
                }
                $error_message = "An error occurred while deleting the department. Please try again.";
                error_log("AAQA DeleteDepartment Error: " . $e->getMessage());
                // Don't expose internal error details to user
            } catch (Error $e) {
                if ($conn->in_transaction) {
                    $conn->rollback();
                }
                $error_message = "A system error occurred. Please contact the administrator.";
                error_log("AAQA DeleteDepartment Fatal Error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Department - AAQA</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-red: #ef4444;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .top-bar {
            background: var(--primary-blue);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .top-bar-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .container-main {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .card {
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .back-link {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="top-bar-content">
            <h1><i class="fas fa-trash me-2"></i>Delete Department</h1>
            <div class="d-flex align-items-center gap-3">
                <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'AAQA User'); ?></span>
                <a href="../logout.php" class="btn btn-sm btn-light">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </div>
    
    <div class="container-main">
        <a href="AllDepartmentDetails.php" class="back-link">
            <i class="fas fa-arrow-left"></i>Back to Department List
        </a>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <div class="mt-3">
                <a href="AllDepartmentDetails.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Department List
                </a>
            </div>
        <?php elseif ((!$dept_info || empty($dept_info)) && !$success_message && $dept_id <= 0): ?>
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-trash-alt me-2"></i>Delete Department</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i><strong>Warning:</strong> This action cannot be undone. All data associated with the selected department will be permanently deleted.
                    </div>
                    
                    <form method="get" action="delete_department.php">
                        <div class="mb-4">
                            <label for="dept_id" class="form-label">
                                <i class="fas fa-search me-2"></i><strong>Search and Select Department to Delete</strong>
                            </label>
                            <select id="dept_id" name="dept_id" class="form-select" style="width: 100%;" required>
                                <option value="">Type to search or select a department...</option>
                                <?php
                                try {
                                    if (isset($conn) && $conn) {
                                        // SECURITY: Use prepared statement to fetch departments
                                        $dept_query = "SELECT dm.DEPT_ID, dm.DEPT_COLL_NO, 
                                                      COALESCE(dn.collname, dm.DEPT_NAME) AS DEPT_NAME, 
                                                      dm.EMAIL, dm.HOD_NAME
                                                      FROM department_master dm
                                                      LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
                                                      ORDER BY COALESCE(dn.collname, dm.DEPT_NAME) ASC";
                                        $dept_stmt = $conn->prepare($dept_query);
                                        if ($dept_stmt) {
                                            if ($dept_stmt->execute()) {
                                                $dept_result = $dept_stmt->get_result();
                                                if ($dept_result) {
                                                    while ($dept_row = $dept_result->fetch_assoc()) {
                                                        $d_id = (int)($dept_row['DEPT_ID'] ?? 0);
                                                        if ($d_id > 0) {
                                                            $d_code = htmlspecialchars($dept_row['DEPT_COLL_NO'] ?? '', ENT_QUOTES, 'UTF-8');
                                                            $d_name = htmlspecialchars($dept_row['DEPT_NAME'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                                                            $display_text = $d_code ? "{$d_code} - {$d_name}" : $d_name;
                                                            echo '<option value="' . $d_id . '">' . $display_text . '</option>';
                                                        }
                                                    }
                                                    mysqli_free_result($dept_result);
                                                }
                                            } else {
                                                error_log("AAQA DeleteDepartment: Failed to execute department query - " . $dept_stmt->error);
                                            }
                                            $dept_stmt->close();
                                        } else {
                                            error_log("AAQA DeleteDepartment: Failed to prepare department query - " . $conn->error);
                                        }
                                    } else {
                                        error_log("AAQA DeleteDepartment: Database connection not available");
                                    }
                                } catch (Exception $e) {
                                    error_log("AAQA DeleteDepartment: Error loading departments - " . $e->getMessage());
                                    echo '<option value="">Error loading departments. Please refresh the page.</option>';
                                } catch (Error $e) {
                                    error_log("AAQA DeleteDepartment: Fatal error loading departments - " . $e->getMessage());
                                    echo '<option value="">Error loading departments. Please contact administrator.</option>';
                                }
                                ?>
                            </select>
                            <div class="form-text">Start typing to search for a department by name or code</div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-arrow-right me-2"></i>Continue to Confirmation
                            </button>
                            <a href="AllDepartmentDetails.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($dept_id > 0 && (!$dept_info || empty($dept_info)) && !$success_message): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>Department not found. It may have been already deleted or the ID is invalid.
            </div>
            <div class="mt-3">
                <a href="delete_department.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Department Selection
                </a>
                <a href="AllDepartmentDetails.php" class="btn btn-secondary">
                    <i class="fas fa-list me-2"></i>View All Departments
                </a>
            </div>
        <?php elseif ($dept_info && !empty($dept_info)): ?>
            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Delete Department</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <strong>Warning:</strong> This action cannot be undone. All data associated with this department will be permanently deleted.
                    </div>
                    
                    <h6>Department Information:</h6>
                    <table class="table table-bordered">
                        <tr>
                            <th>Department ID:</th>
                            <td><?php echo htmlspecialchars($dept_info['DEPT_ID'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr>
                            <th>Department Code:</th>
                            <td><?php echo htmlspecialchars($dept_info['DEPT_COLL_NO'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr>
                            <th>Department Name:</th>
                            <td><?php echo htmlspecialchars($dept_info['DEPT_NAME'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr>
                            <th>Email:</th>
                            <td><?php echo htmlspecialchars($dept_info['EMAIL'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr>
                            <th>HOD Name:</th>
                            <td><?php echo htmlspecialchars($dept_info['HOD_NAME'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    </table>
                    
                    <?php if (!empty($dependencies)): ?>
                        <div class="alert alert-info mt-3">
                            <h6><i class="fas fa-info-circle me-2"></i>Related Data Found:</h6>
                            <ul class="mb-0">
                                <?php foreach ($dependencies as $table => $count): ?>
                                    <li><?php echo htmlspecialchars($table, ENT_QUOTES, 'UTF-8'); ?>: <?php echo $count; ?> record(s)</li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="mb-0 mt-2"><strong>Note:</strong> All related data will also be deleted.</p>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" onsubmit="return confirm('Are you absolutely sure you want to delete this department? This action cannot be undone!');">
                        <?php if (file_exists(__DIR__ . '/../csrf.php')): 
                            require_once __DIR__ . '/../csrf.php';
                            if (function_exists('csrf_field')) {
                                echo csrf_field();
                            }
                        endif; ?>
                        <input type="hidden" name="dept_id" value="<?php echo $dept_id; ?>">
                        <input type="hidden" name="confirm_delete" value="yes">
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash me-2"></i>Delete Department
                            </button>
                            <a href="AllDepartmentDetails.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize Select2 for searchable dropdown
        $(document).ready(function() {
            $('#dept_id').select2({
                placeholder: "Type to search or select a department...",
                allowClear: true,
                width: '100%'
            });
        });
    </script>
</body>
</html>

