<?php
// admin/FinancialExpenditure.php - Financial Expenditure
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

        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .section-header h5 {
            margin: 0;
            font-size: 1.1rem;
        }

        .subsection-header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 3px 5px rgba(0, 0, 0, 0.1);
        }

        .subsection-header h5 {
            margin: 0;
            font-size: 1rem;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 15px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .form-control:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        .submit {
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

        .submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .submit:active {
            transform: translateY(0);
        }

        .page-title {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
            margin-bottom: 30px;
            text-align: center;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .form-container {
                padding: 10px;
            }

            .form-card {
                padding: 20px 15px;
            }

            .section-header,
            .subsection-header {
                padding: 12px 15px;
            }

            .section-header h5 {
                font-size: 1rem;
            }

            .subsection-header h5 {
                font-size: 0.9rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .form-label {
                font-size: 0.9rem;
            }

            .form-control {
                font-size: 0.9rem;
                padding: 10px 12px;
            }

            .submit {
                padding: 12px 30px;
                font-size: 0.95rem;
            }

            .navbar {
                padding: 15px 10px !important;
            }

            .navbar h2 {
                font-size: 1.3rem !important;
            }
        }

        @media (max-width: 576px) {
            .section-header h5,
            .subsection-header h5 {
                font-size: 0.85rem;
                line-height: 1.4;
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
        }

        /* Input number spinner styling */
        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            opacity: 1;
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
                <form class="fw-bold" method="POST" enctype="multipart/form-data" onsubmit="return validateForm()">
                    <h3 class="page-title">Financial Expenditure</h3>

                    <div class="form-card">
                        <div class="mb-3">
                            <label class="form-label">Academic Year</label>
                            <input type="year" name="year" value="<?php echo $A_YEAR ?>" class="form-control" disabled>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" style="font-size: 1.05rem; color: #555;">
                                Financial Resources: Utilised Amount for the Capital & Operational expenditure
                            </label>
                        </div>

                        <!-- Capital Expenditure Section -->
                        <div class="section-header">
                            <h5><i class="fas fa-chart-line me-2"></i>i) Annual Capital Expenditure on Academic Activities and Resources</h5>
                            <small style="opacity: 0.9;">(excluding expenditure on buildings)</small>
                        </div>

                        <div class="subsection-header">
                            <h5><i class="far fa-calendar-alt me-2"></i>Financial Year 2022-23</h5>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Library</label>
                                <input type="number" name="library" class="form-control" placeholder="Enter Amount spent" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">New Equipment for Laboratories</label>
                                <input type="number" name="laboratory" class="form-control" placeholder="Enter Amount spent" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Engineering Workshops</label>
                                <input type="number" name="Engineering Workshops" class="form-control" placeholder="Enter Amount spent" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Studios</label>
                                <input type="number" name="studios" class="form-control" placeholder="Enter Amount spent" required>
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Other expenditure on creation of Capital Assets</label>
                                <small class="d-block text-muted mb-2">(excluding expenditure on Land and Building)</small>
                                <input type="number" name="other expenditure" class="form-control" placeholder="Enter Amount spent" required>
                            </div>
                        </div>

                        <!-- Operational Expenditure Section -->
                        <div class="section-header mt-4">
                            <h5><i class="fas fa-cogs me-2"></i>ii) Annual Operational Expenditure</h5>
                        </div>

                        <div class="subsection-header">
                            <h5><i class="far fa-calendar-alt me-2"></i>Financial Year 2022-23</h5>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Salaries</label>
                                <small class="d-block text-muted mb-2">(Teaching and Non-Teaching staff)</small>
                                <input type="number" name="salary" class="form-control" placeholder="Enter Amount spent" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Maintenance of Academic Infrastructure</label>
                                <small class="d-block text-muted mb-2">(consumables, running expenditures, etc.)</small>
                                <input type="number" name="Maintenance of Academic" class="form-control" placeholder="Enter Amount spent" required>
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Seminars/Conferences/Workshops</label>
                                <input type="number" name="Seminars/Conferences/Workshops" class="form-control" placeholder="Enter Amount spent" required>
                            </div>
                        </div>

                        <input type="submit" class="submit" value="Submit" name="submit" onclick="return Validate()">
                    </div>
                </form>
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