<?php
/**
 * AAQA - Add Department Details
 */

require('session.php');
require_once(__DIR__ . '/../config.php');

// Load common functions for getAcademicYear()
if (file_exists(__DIR__ . '/../common_functions.php')) {
    require_once(__DIR__ . '/../common_functions.php');
} elseif (file_exists(__DIR__ . '/../common_progress_functions.php')) {
    require_once(__DIR__ . '/../common_progress_functions.php');
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Get academic year using standardized function
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    // Fallback calculation (matches getAcademicYear logic)
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    if ($current_month >= 7) {
        $A_YEAR = $current_year . '-' . ($current_year + 1);
    } else {
        $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
    }
}

$success_message = '';
$error_message = '';

if (isset($_POST['dept_submit'])) {
    // Validate CSRF token
    if (file_exists(__DIR__ . '/../csrf.php')) {
        require_once __DIR__ . '/../csrf.php';
        if (function_exists('validate_csrf')) {
            $csrf_token = $_POST['csrf_token'] ?? '';
            if (empty($csrf_token) || !validate_csrf($csrf_token)) {
                $error_message = 'Security token validation failed. Please refresh the page and try again.';
            }
        }
    }
    
    if (empty($error_message)) {
        $conn->begin_transaction();
        
        try {
            // Validate and sanitize input (type casting for numeric)
            $deptid = (int)($_POST['deptid'] ?? 0);
            $deptcode = trim($_POST['deptcode'] ?? '');
            $deptname = trim($_POST['deptname'] ?? '');
            $deptemail = trim($_POST['deptemail'] ?? '');
            $hodname = trim($_POST['hodname'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $deptstatus = trim($_POST['deptstatus'] ?? 'active');
            
            // Validate required fields
            if (empty($deptcode) || empty($deptname) || empty($deptemail)) {
                throw new Exception('Required fields are missing.');
            }
            
            // Validate email format
            if (!filter_var($deptemail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address format.');
            }
        
            // Check duplicate college code
            $stmt_1 = $conn->prepare("SELECT collno FROM colleges WHERE collno = ?");
            if (!$stmt_1) {
                throw new Exception('Database prepare error: ' . $conn->error);
            }
            $stmt_1->bind_param("s", $deptcode);
            $stmt_1->execute();
            $result_1 = $stmt_1->get_result();
            
            // Check duplicate email
            $stmt_2 = $conn->prepare("SELECT EMAIL FROM department_master WHERE EMAIL = ?");
            if (!$stmt_2) {
                mysqli_free_result($result_1);
                $stmt_1->close();
                throw new Exception('Database prepare error: ' . $conn->error);
            }
            $stmt_2->bind_param("s", $deptemail);
            $stmt_2->execute();
            $result_2 = $stmt_2->get_result();
            
            // Check duplicate deptname
            $stmt_3 = $conn->prepare("SELECT collname FROM colleges WHERE collname = ?");
            if (!$stmt_3) {
                mysqli_free_result($result_1);
                mysqli_free_result($result_2);
                $stmt_1->close();
                $stmt_2->close();
                throw new Exception('Database prepare error: ' . $conn->error);
            }
            $stmt_3->bind_param("s", $deptname);
            $stmt_3->execute();
            $result_3 = $stmt_3->get_result();
            
            if ($result_2->num_rows > 0) {
                mysqli_free_result($result_1);
                mysqli_free_result($result_2);
                mysqli_free_result($result_3);
                $stmt_1->close();
                $stmt_2->close();
                $stmt_3->close();
                throw new Exception("This Department Email is already registered.");
            } elseif ($result_1->num_rows > 0) {
                mysqli_free_result($result_1);
                mysqli_free_result($result_2);
                mysqli_free_result($result_3);
                $stmt_1->close();
                $stmt_2->close();
                $stmt_3->close();
                throw new Exception("This Department Code is already registered.");
            } elseif ($result_3->num_rows > 0) {
                mysqli_free_result($result_1);
                mysqli_free_result($result_2);
                mysqli_free_result($result_3);
                $stmt_1->close();
                $stmt_2->close();
                $stmt_3->close();
                throw new Exception("This Department Name is already registered.");
            } else {
                // Free results before proceeding
                mysqli_free_result($result_1);
                mysqli_free_result($result_2);
                mysqli_free_result($result_3);
                $stmt_1->close();
                $stmt_2->close();
                $stmt_3->close();
            // Generate random password
            function generatePassword($length = 10) {
                $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
                return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
            }
            $plainPassword = generatePassword();
            
            // Insert into departmentnames
            $deptquery1 = "INSERT INTO `departmentnames`(`collno`, `collname`) VALUES (?, ?)";
            $stmt1 = $conn->prepare($deptquery1);
            $stmt1->bind_param("ss", $deptcode, $deptname);
            $stmt1->execute();
            $stmt1->close();
            
            // Insert into colleges
            $deptquery2 = "INSERT INTO `colleges`(`department_id`, `collname`, `collno`, `status`) VALUES (?, ?, ?, ?)";
            $stmt2 = $conn->prepare($deptquery2);
            $stmt2->bind_param("isss", $deptid, $deptname, $deptcode, $deptstatus);
            $stmt2->execute();
            $stmt2->close();
            
            // Insert into department_master (HOD_EMAIL is required field)
            $deptquery3 = "INSERT INTO `department_master`(`DEPT_COLL_NO`, `DEPT_NAME`, `EMAIL`, `HOD_NAME`, `HOD_EMAIL`, `ADDRESS`, `PASS_WORD`) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt3 = $conn->prepare($deptquery3);
            // Use department email as HOD_EMAIL if not provided separately
            $hod_email = $deptemail; // Default to department email
            $stmt3->bind_param("sssssss", $deptcode, $deptname, $deptemail, $hodname, $hod_email, $address, $plainPassword);
            $stmt3->execute();
            $new_dept_id = (int)$stmt3->insert_id;
            $stmt3->close();
            
            // Save category to department_profiles if category is selected
            if ($deptid > 0) {
                // Get category name from departments table
                $category_query = "SELECT department_name FROM departments WHERE department_id = ?";
                $category_stmt = $conn->prepare($category_query);
                if ($category_stmt) {
                    $category_stmt->bind_param("i", $deptid);
                    $category_stmt->execute();
                    $category_result = $category_stmt->get_result();
                    if ($category_result && $category_row = $category_result->fetch_assoc()) {
                        $category_name = $category_row['department_name'];
                        mysqli_free_result($category_result);
                        
                        // Insert category into department_profiles
                        $profile_query = "INSERT INTO `department_profiles`(`dept_id`, `A_YEAR`, `category`) VALUES (?, ?, ?) 
                                         ON DUPLICATE KEY UPDATE `category` = VALUES(`category`)";
                        $profile_stmt = $conn->prepare($profile_query);
                        if ($profile_stmt) {
                            $profile_stmt->bind_param("sss", $deptcode, $A_YEAR, $category_name);
                            $profile_stmt->execute();
                            $profile_stmt->close();
                        }
                    } else {
                        if ($category_result) {
                            mysqli_free_result($category_result);
                        }
                    }
                    $category_stmt->close();
                }
            }
            
                $conn->commit();
                $success_message = "Department added successfully! Generated Password: " . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "An error occurred. Please try again.";
            error_log("Add Department Error: " . $e->getMessage());
        } catch (Error $e) {
            $conn->rollback();
            $error_message = "A fatal error occurred.";
            error_log("Add Department Fatal Error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Department - AAQA Dashboard</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
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
            max-width: 1400px;
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
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        
        .form-control, .form-select {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        
        .input-group-icon {
            position: relative;
        }
        
        .input-group-icon i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-blue);
            z-index: 10;
        }
        
        .input-group-icon input,
        .input-group-icon select {
            padding-left: 3rem;
        }
        
        .btn-primary {
            background: var(--primary-blue);
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: #1e40af;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .alert {
            border-radius: 8px;
            border: none;
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
            <h1><i class="fas fa-plus-circle me-2"></i>Add Department</h1>
            <div class="d-flex align-items-center gap-3">
                <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'AAQA User'); ?></span>
                <a href="../logout.php" class="btn btn-sm btn-light">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </div>
    
    <div class="container-main">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>Back to Dashboard
        </a>
        
        <div class="form-card">
            <h2 class="page-title">Add New Department</h2>
            <p class="text-muted mb-4">Fill in the details to register a new department</p>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <?php if (file_exists(__DIR__ . '/../csrf.php')): 
                    require_once __DIR__ . '/../csrf.php';
                    if (function_exists('csrf_field')) {
                        echo csrf_field();
                    }
                endif; ?>
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <label class="form-label"><i class="fas fa-list-alt me-2"></i>Category</label>
                        <div class="input-group-icon">
                            <i class="fas fa-folder"></i>
                            <select name="deptid" id="department_name" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php
                                $departments_query = "SELECT department_id, department_name FROM departments ORDER BY department_id ASC";
                                $departments_stmt = $conn->prepare($departments_query);
                                if ($departments_stmt) {
                                    $departments_stmt->execute();
                                    $departments_result = $departments_stmt->get_result();
                                    if ($departments_result) {
                                        while ($info = $departments_result->fetch_assoc()) {
                                            $d_id = (int)$info['department_id'];
                                            $d_name = htmlspecialchars($info['department_name'], ENT_QUOTES, 'UTF-8');
                                            echo '<option value="' . $d_id . '">' . $d_name . '</option>';
                                        }
                                        mysqli_free_result($departments_result);
                                    }
                                    $departments_stmt->close();
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-4">
                        <label class="form-label"><i class="fas fa-hashtag me-2"></i>Department Code</label>
                        <div class="input-group-icon">
                            <i class="fas fa-code"></i>
                            <input type="number" name="deptcode" class="form-control" placeholder="Enter Department Code" required>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-4">
                        <label class="form-label"><i class="fas fa-building me-2"></i>Department Name</label>
                        <div class="input-group-icon">
                            <i class="fas fa-university"></i>
                            <input type="text" name="deptname" class="form-control" placeholder="Enter Department Name" required>
                        </div>
                        <input type="hidden" name="deptstatus" value="active">
                    </div>
                    
                    <div class="col-md-6 mb-4">
                        <label class="form-label"><i class="fas fa-envelope me-2"></i>Department Email ID</label>
                        <div class="input-group-icon">
                            <i class="fas fa-at"></i>
                            <input type="email" name="deptemail" class="form-control" placeholder="Enter Department Email ID" required>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-4">
                        <label class="form-label"><i class="fas fa-user-tie me-2"></i>HOD Name</label>
                        <div class="input-group-icon">
                            <i class="fas fa-id-badge"></i>
                            <input type="text" name="hodname" class="form-control" placeholder="Enter HOD Name" required>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-4">
                        <label class="form-label"><i class="fas fa-map-marker-alt me-2"></i>Department Address</label>
                        <div class="input-group-icon">
                            <i class="fas fa-location-arrow"></i>
                            <input type="text" name="address" class="form-control" placeholder="Enter Department Address" required>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-3 mt-4">
                    <button type="submit" name="dept_submit" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i>Add Department
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

