<?php
// admin/AddDepartmentDetails.php - Add Department Details
require('session.php');

error_reporting(0);

//Normal year wise logic
$year = date("Y");
$pyear = $year - 1;
$A_YEAR = $pyear . '-' . $year;

$dept = $_SESSION['dept_id'];

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (isset($_POST['dept_submit'])) {
    $conn->begin_transaction();

    $deptid    = mysqli_real_escape_string($conn, $_POST['deptid']);
    $deptcode  = mysqli_real_escape_string($conn, $_POST['deptcode']);
    $deptname  = mysqli_real_escape_string($conn, $_POST['deptname']);
    $deptemail = mysqli_real_escape_string($conn, $_POST['deptemail']);
    $hodname   = mysqli_real_escape_string($conn, $_POST['hodname']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $deptstatus = mysqli_real_escape_string($conn, $_POST['deptstatus']);

    // Check duplicate college code
    $stmt_1 = $conn->prepare("SELECT collno FROM colleges WHERE collno = ?");
    $stmt_1->bind_param("s", $deptcode);
    $stmt_1->execute();
    $stmt_1->store_result();

    // Check duplicate email
    $stmt_2 = $conn->prepare("SELECT email FROM department_master WHERE email = ?");
    $stmt_2->bind_param("s", $deptemail);
    $stmt_2->execute();
    $stmt_2->store_result();

    // Check duplicate deptname
    $stmt_3 = $conn->prepare("SELECT collname FROM colleges WHERE collname = ?");
    $stmt_3->bind_param("s", $deptname);
    $stmt_3->execute();
    $stmt_3->store_result();

    if ($stmt_2->num_rows > 0) {
        echo "<script>alert('This Department Email is already registered.'); window.location.href = 'AddDepartmentDetails.php';</script>";
        exit();
    } elseif ($stmt_1->num_rows > 0) {
        echo "<script>alert('This Department Code is already registered.'); window.location.href = 'AddDepartmentDetails.php';</script>";
        exit();
    } elseif ($stmt_3->num_rows > 0) {
        echo "<script>alert('This Department Name is already registered.'); window.location.href = 'AddDepartmentDetails.php';</script>";
        exit();
    } else {
        // ✅ Generate random password
        function generatePassword($length = 10)
        {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
            return substr(str_shuffle($chars), 0, $length);
        }
        $plainPassword = generatePassword();
        $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

        // Insert into departmentnames
        $deptquery1 = "INSERT INTO `departmentnames`(`collno`, `collname`)   
                       VALUES ('$deptcode', '$deptname')";
        $conn->query($deptquery1);

        // Insert into colleges
        $deptquery2 = "INSERT INTO `colleges`(`department_id`, `collname`, `collno`, `status`)   
                       VALUES ('$deptid', '$deptname', '$deptcode', '$deptstatus')";
        $conn->query($deptquery2);

        // Insert into department_master with generated password
        $deptquery3 = "INSERT INTO `department_master`(`DEPT_COLL_NO`, `DEPT_NAME`, `EMAIL`, `HOD_NAME`, `ADDRESS`, `PASS_WORD`)   
                       VALUES ('$deptcode', '$deptname', '$deptemail', '$hodname', '$address', '$plainPassword')";
        $conn->query($deptquery3);

        $conn->commit();

        // ⚠️ For testing: Show the plain password (Remove in production)
        echo "<script>alert('✅ Data inserted successfully! Login Password: $plainPassword')</script>";
        echo '<script>window.location.href = "AddDepartmentDetails.php";</script>';
    }

    $stmt_1->close();
    $stmt_2->close();
    $stmt_3->close();
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

                    <form method="post">
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label"><i class="fas fa-list-alt me-2" style="color: #667eea;"></i>Category</label>
                                <div class="input-group-icon">
                                    <i class="fas fa-folder"></i>
                                    <select name="deptid" id="department_name" class="form-label" required>
                                        <option value="">Select Category</option>
                                        <?php
                                        $departments_query = "SELECT * FROM departments ORDER BY department_id ASC";
                                        $departments_result = mysqli_query($conn, $departments_query);
                                        while ($info = mysqli_fetch_array($departments_result, MYSQLI_ASSOC)) {
                                            $d_id = $info['department_id'];
                                            $d_name = $info['department_name'];
                                        ?>
                                            <option value="<?php echo $d_id; ?>"><?php echo $d_name; ?></option>
                                        <?php } ?>
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