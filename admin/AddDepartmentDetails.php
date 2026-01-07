<?php
/**
 * Admin - Add Department Details
 * SECURITY FIXED: Uses prepared statements, proper error handling, transaction management
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
ini_set('log_errors', 1);

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

// Check database connection
if (!isset($conn) || !$conn) {
    $error_message = 'Database connection not available. Please try again.';
    error_log("Admin AddDepartmentDetails: Database connection not available");
} elseif (isset($_POST['dept_submit'])) {
    // Validate CSRF token if available
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
            if (empty($deptcode) || empty($deptname) || empty($deptemail) || $deptid <= 0) {
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
                if (!$stmt1) {
                    throw new Exception('Database prepare error: ' . $conn->error);
                }
                $stmt1->bind_param("ss", $deptcode, $deptname);
                if (!$stmt1->execute()) {
                    $stmt1->close();
                    throw new Exception('Failed to insert into departmentnames: ' . $stmt1->error);
                }
                $stmt1->close();
                
                // Insert into colleges
                $deptquery2 = "INSERT INTO `colleges`(`department_id`, `collname`, `collno`, `status`) VALUES (?, ?, ?, ?)";
                $stmt2 = $conn->prepare($deptquery2);
                if (!$stmt2) {
                    throw new Exception('Database prepare error: ' . $conn->error);
                }
                $stmt2->bind_param("isss", $deptid, $deptname, $deptcode, $deptstatus);
                if (!$stmt2->execute()) {
                    $stmt2->close();
                    throw new Exception('Failed to insert into colleges: ' . $stmt2->error);
                }
                $stmt2->close();
                
                // Insert into department_master (HOD_EMAIL is required field)
                $deptquery3 = "INSERT INTO `department_master`(`DEPT_COLL_NO`, `DEPT_NAME`, `EMAIL`, `HOD_NAME`, `HOD_EMAIL`, `ADDRESS`, `PASS_WORD`) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt3 = $conn->prepare($deptquery3);
                if (!$stmt3) {
                    throw new Exception('Database prepare error: ' . $conn->error);
                }
                // Use department email as HOD_EMAIL if not provided separately
                $hod_email = $deptemail; // Default to department email
                $stmt3->bind_param("sssssss", $deptcode, $deptname, $deptemail, $hodname, $hod_email, $address, $plainPassword);
                if (!$stmt3->execute()) {
                    $stmt3->close();
                    throw new Exception('Failed to insert into department_master: ' . $stmt3->error);
                }
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
            $error_message = "An error occurred: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            error_log("Admin AddDepartmentDetails Error: " . $e->getMessage());
        } catch (Error $e) {
            $conn->rollback();
            $error_message = "A fatal error occurred.";
            error_log("Admin AddDepartmentDetails Fatal Error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" />
    <link rel="stylesheet" href="assets/css/styles.css" />
    <link rel="icon" href="assets/img/mumbai-university-removebg-preview.png" type="image/png">
    <title>UoM Centralized DCS Ranking PORTAL</title>
    <style>
        /* Additional responsive styles */
        body {
            overflow-x: hidden;
        }

        #page-content-wrapper {
            width: 100%;
            min-height: 100vh;
        }

        .form-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 15px;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .page-title {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
            margin-bottom: 35px;
            text-align: center;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .form-control,
        .form-select {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 15px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        select.form-label {
            width: 100%;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 15px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            background-color: white;
            cursor: pointer;
        }

        select.form-label:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            outline: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 14px 40px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            max-width: 300px;
            display: block;
            margin: 30px auto 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            background: linear-gradient(135deg, #5568d3 0%, #6a3f8f 100%);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        /* Input icons */
        .input-group-icon {
            position: relative;
        }

        .input-group-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            z-index: 10;
        }

        .input-group-icon input,
        .input-group-icon select {
            padding-left: 45px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .form-container {
                padding: 10px;
            }

            .form-card {
                padding: 25px 20px;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .form-label {
                font-size: 0.9rem;
            }

            .form-control,
            .form-select,
            select.form-label {
                font-size: 0.9rem;
                padding: 10px 12px;
            }

            .input-group-icon input,
            .input-group-icon select {
                padding-left: 40px;
            }

            .btn-primary {
                padding: 12px 30px;
                font-size: 0.95rem;
                max-width: 100%;
            }

            .navbar {
                padding: 15px 10px !important;
            }

            .navbar h2 {
                font-size: 1.3rem !important;
            }
        }

        @media (max-width: 576px) {
            .form-card {
                padding: 20px 15px;
            }

            .page-title {
                font-size: 1.3rem;
            }

            .navbar h2 {
                font-size: 1.1rem !important;
            }

            .navbar-nav {
                margin-top: 10px;
            }

            .input-group-icon i {
                font-size: 0.9rem;
            }
        }

        /* Custom styling for readonly/hidden inputs */
        input[type="text"][hidden] {
            display: none;
        }

        /* Improved select dropdown */
        select option {
            padding: 10px;
        }

        select option:hover {
            background-color: #f0f0f0;
        }
    </style>
</head>

<body>
    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <?php include('sidebar.php'); ?>
        <!-- /#sidebar-wrapper -->

        <!-- Page Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-transparent py-4 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
                    <h2 class="fs-2 m-0">Dashboard</h2>
                </div>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
                    aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle second-text fw-bold" href="#" id="navbarDropdown"
                                role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user me-2"></i><?php echo $_SESSION['admin_username'] ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="form-container">
                <div class="form-card">
                    <h3 class="page-title">Department Details</h3>
                    
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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
                                <label class="form-label"><i class="fas fa-list-alt me-2" style="color: #667eea;"></i>Category</label>
                                <div class="input-group-icon">
                                    <i class="fas fa-folder"></i>
                                    <select name="deptid" id="department_name" class="form-label" required>
                                        <option value="">Select Category</option>
                                        <?php
                                        if (isset($conn) && $conn) {
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
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <label class="form-label"><i class="fas fa-hashtag me-2" style="color: #667eea;"></i>Department Code</label>
                                <div class="input-group-icon">
                                    <i class="fas fa-code"></i>
                                    <input type="number" name="deptcode" class="form-control" placeholder="Enter Department Code" required>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <label class="form-label"><i class="fas fa-building me-2" style="color: #667eea;"></i>Department Name</label>
                                <div class="input-group-icon">
                                    <i class="fas fa-university"></i>
                                    <input type="text" name="deptname" class="form-control" placeholder="Enter Department Name" required>
                                </div>
                                <input type="text" name="deptstatus" value="active" hidden>
                            </div>

                            <div class="col-md-6 mb-4">
                                <label class="form-label"><i class="fas fa-envelope me-2" style="color: #667eea;"></i>Department Email ID</label>
                                <div class="input-group-icon">
                                    <i class="fas fa-at"></i>
                                    <input type="email" name="deptemail" class="form-control" placeholder="Enter Department Email ID" required>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <label class="form-label"><i class="fas fa-user-tie me-2" style="color: #667eea;"></i>HOD Name</label>
                                <div class="input-group-icon">
                                    <i class="fas fa-id-badge"></i>
                                    <input type="text" name="hodname" class="form-control" placeholder="Enter HOD Name" required>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <label class="form-label"><i class="fas fa-map-marker-alt me-2" style="color: #667eea;"></i>Department Address</label>
                                <div class="input-group-icon">
                                    <i class="fas fa-location-arrow"></i>
                                    <input type="text" name="address" class="form-control" placeholder="Enter Department Address" required>
                                </div>
                            </div>
                        </div>

                        <button type="submit" name="dept_submit" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-2"></i>ADD DEPARTMENT
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var el = document.getElementById("wrapper");
        var toggleButton = document.getElementById("menu-toggle");

        toggleButton.onclick = function() {
            el.classList.toggle("toggled");
        };
    </script>

    <!-- Footer -->
    <?php include '../footer_main.php'; ?>
</body>

</html>