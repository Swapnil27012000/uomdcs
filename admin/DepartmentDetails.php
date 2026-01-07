<?php
// admin/DepartmentDetails.php - Department Details
require('session.php');

error_reporting(0);

//Normal year wise logic
$year = date("Y");
$pyear = $year - 1;
$A_YEAR = $pyear . '-' . $year;

$dept = $_SESSION['dept_id'];

if (isset($_POST['submit'])) {

    $Male_Students_FT = $_POST['Male_Students_FT'];

    $query = "INSERT INTO `phd_details`(`A_YEAR`, `DEPT_ID`, `FULL_TIME_MALE_STUDENTS`, `FULL_TIME_FEMALE_STUDENTS`, `PART_TIME_MALE_STUDENTS`, `PART_TIME_FEMALE_STUDENTS`, `PHD_AWARDED_MALE_STUDENTS_FULL`, `PHD_AWARDED_FEMALE_STUDENTS_FULL`, `PHD_AWARDED_MALE_STUDENTS_PART`,`PHD_AWARDED_FEMALE_STUDENTS_PART`) 
    VALUES ('$A_YEAR', '$dept','$Male_Students_FT','$Female_Students_FT','$Male_Students_PT', '$Female_Students_PT', '$Male_Students_AWD_FT', '$Female_Students_AWD_FT', '$Male_Students_AWD_PT', '$Female_Students_AWD_PT')";
    // $q=mysqli_query($conn,$query);
    if (mysqli_query($conn, $query)) {
        echo "Records inserted successfully.";
    } else {
        echo "ERROR: Could not able to execute $query. " . mysqli_error($conn);
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
    <!-- <link rel="stylesheet" href="/bootstrap-5.0.2-dist/css/bootstrap.min.css"> -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" />
    <link rel="stylesheet" href="assets/css/styles.css" />
    <link rel="icon" href="assets/img/mumbai-university-removebg-preview.png" type="image/png">
    <title>UoM Centralized DCS Ranking PORTAL</title>
    <style>
        body {
            background-color: #f4f6f8;
        }

        .form-container {
            background-color: #ffffff;
            padding: 50px;
            border-radius: 20px;
            width: 100%;
            max-width: 700px;
            margin-top: 30px;
        }

        .form-title {
            font-weight: bold;
            color: #2c3e50;
            text-align: center;
            margin-bottom: 40px;
            font-size: 24px;
        }

        .form-label {
            font-weight: 500;
            color: #34495e;
        }

        .form-control {
            border-radius: 12px;
            padding: 14px;
            border: 1px solid #ccc;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .btn-primary {
            background-color: #3498db;
            border-radius: 12px;
            padding: 14px;
            font-weight: 600;
            border: none;
            transition: background-color 0.3s;
            font-size: 14px;
        }

        .btn-primary:hover {
            background-color: #2980b9;
        }
    </style>
</head>

<body>
    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <div class="bg-white" id="sidebar-wrapper">
            <div class="sidebar-heading text-center py-4 primary-text fs-4 fw-bold text-uppercase border-bottom">
                <a href="Dashboard.php"><img src="assets/img/mumbai-university-removebg-preview.png" alt="Logo" height="85px" width="85px"></a>
                <div>UoM Centralized DCS Ranking PORTAL</div>
            </div>

            <div class="list-group list-group-flush my-3">
                <a href="Dashboard.php" class="list-group-item list-group-item-action bg-transparent second-text fw-bold"><i></i>Dashboard</a>
                <a href="FinancialExpenditure.php" class="list-group-item list-group-item-action bg-transparent second-text fw-bold"><i></i>Financial Expenditure</a>
                <a href="DepartmentDetails.php" class="list-group-item list-group-item-action bg-transparent second-text fw-bold">
                    Department Details</a>
                <a href="../logout.php" class="list-group-item list-group-item-action bg-transparent text-danger fw-bold"><i></i>Logout</a>
            </div>
        </div>
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

            <!-- <div class="div"> -->
                <div class="container d-flex justify-content-center align-items-center">
                    <div class="form-container">
                        <h4 class="form-title">DEPARTMENT DETAILS</h4>
                        <!-- Display Message -->
                        <form method="post">
                            <div class="mb-4">
                                <label class="form-label">Department Code</label>
                                <input type="number" name="deptcode" class="form-control" placeholder="Enter Department Code"
                                    value="" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Department Name</label>
                                <input type="text" name="deptname" class="form-control" placeholder="Enter Department Name"
                                    value="" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Department Email ID</label>
                                <input type="email" name="deptemail" class="form-control" placeholder="Enter Department Email ID"
                                    value="" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">ADD DEPARTMENT</button>
                        </form>
                        <br /><br />
                        <p>After adding Department Click <a href="AllDepartmentDetails.php">"All Department Details"</a> to send the Login Credentials</p>
                        <p>Edit Department <a href="EditDepartmentDetails.php">Click Here</a></p>
                    </div>
                </div>
            <!-- </div> -->
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
<?php include '../footer_main.php';?>
</body>


</html>