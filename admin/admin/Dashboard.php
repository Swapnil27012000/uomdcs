<?php
// admin/Dashboard.php - Admin Dashboard
require('session.php');

// Load progress functions
require_once(__DIR__ . '/../common_progress_functions.php');

// Additional admin-specific functionality
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
// $dotenv->load();
$dotenv->safeLoad();

// Get academic year and departments with progress
$academic_year = getAcademicYear();
$departments = getAllDepartmentsWithProgress($academic_year);

// Calculate statistics
$total_depts = count($departments);
$completed_depts = 0;
$in_progress = 0;
$not_started = 0;
$total_progress = 0;

foreach ($departments as $dept) {
    $progress = isset($dept['progress']) ? $dept['progress']['progress_percentage'] : 0;
    $total_progress += $progress;
    
    if ($progress >= 100) {
        $completed_depts++;
    } elseif ($progress > 0) {
        $in_progress++;
    } else {
        $not_started++;
    }
}

$avg_progress = $total_depts > 0 ? round($total_progress / $total_depts, 1) : 0;

// Database Connection
$database = [
    'host' => $_ENV['DB_HOST'],
    'user' => $_ENV['DB_USER'],
    'pass' => $_ENV['DB_PASS'],
    'name' => $_ENV['DB_NAME']
];

// Tables to fetch years from
// $tables = ['intake_actual_strength', 'phd_details', 'placement_details', 'faculty_details', 'faculty_count', 
//             'academic_peers', 'inter_faculty', 'sponsored_project_details', 'research_staff', 'patent_details', 
//             'patent_info', 'exec_dev', 'consultancy_projects', 'employers_details', 'country_wise_student', 
//             'salary_details', 'online_education_details'];


$tables = [
    'academic_peers',
    'annual_operation_expenditure',
    'boss',
    'brief_details_of_the_department',
    'collaborations',
    'colleges',
    'conferences_workshops',
    'conferences_workshops_new',
    'conferences_workshops_old',
    'consultancy_projects',
    'country_master',
    'country_wise_student',
    'datapoint_question',
    'datapoint_values',
    'department_data',
    'department_master',
    'department_profiles',
    'department_scores',
    'departmentnames',
    'departments',
    'employers_details',
    'exec_dev',
    'faculty_count',
    'faculty_details',
    'faculty_output',
    'financial_expenditure',
    'intake_actual_strength',
    'intake_documents',
    'inter_faculty',
    'nep_docs',
    'nep_documents',
    'nep_initiatives_checkbox',
    'nepmarks',
    'online_education_details',
    'phd_details',
    'placement_details',
    'program_master',
    'programmes',
    'salary_details',
    'studentsupport',
    'supporting_documents',
    'user_invitations',
    'user_type'

];
$years = [];

$conn = new mysqli($database['host'], $database['user'], $database['pass'], $database['name']);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

mysqli_set_charset($conn, 'utf8mb4'); // Ensure proper encoding

// Fetch years from multiple tables
foreach ($tables as $table) {
    // Check if the table exists
    $checkTableQuery = "SHOW TABLES LIKE '$table'";
    $tableExists = $conn->query($checkTableQuery);

    if ($tableExists->num_rows > 0) {
        // Check if A_YEAR column exists
        $query = "SHOW COLUMNS FROM `$table` LIKE 'A_YEAR'";
        $columnExists = $conn->query($query);
        
        if ($columnExists->num_rows > 0) {
            $query = "SELECT DISTINCT A_YEAR FROM `$table` ORDER BY A_YEAR DESC";
            $result = $conn->query($query);

            while ($row = $result->fetch_assoc()) {
                if (!empty($row['A_YEAR'])) { 
                    $years[] = $row['A_YEAR'];
                }
            }            
        }
    }
}

$conn->close();

// Remove duplicate years and sort
$years = array_unique($years);
rsort($years);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" />
    <link rel="stylesheet" href="assets/css/styles.css" />
    <link rel="icon" href="assets/img/mumbai-university-removebg-preview.png" type="image/png">
    <title>UoM Centralized DCS Ranking PORTAL</title>
</head>

<body>
    <div class="d-flex" id="wrapper">

        <!-- Sidebar -->
        <?php
        include('sidebar.php');
        ?>

        <!-- Page Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-transparent py-4 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
                    <h2 class="fs-2 m-0">Dashboard</h2>
                </div>

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

            <div class="container-fluid px-4">
                <h4 class="mt-4">Hello, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></h4>
                <p class="text-muted">Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong></p>

                <!-- Outer Card -->
                <div class="card mt-4">
                    <div class="card-body">
                        <h5 class="card-title"><b>Admin Dashboard</b></h5>
                        <div class="row">

                            <!-- Smaller Box (1/3 width) -->
                            <div class="col-md-4 mb-4">
                                <div class="card h-100 border border-dark"> <!-- Added border classes -->
                                    <div class="card-body d-flex flex-column justify-content-center align-items-center">
                                        <form action="download.php" method="get" class="w-100 text-center">
                                            <label for="downloadButton" class="form-label"><b>Download the Data:</b></label>
                                            <hr>
                                            <label for="year" class="form-label"><b>Select Year:</b></label>

                                            <select name="year" id="year" class="form-select me-2" style="width: 150px;">
                                                <option value="all" selected>All</option>
                                                <?php foreach ($years as $year) { ?>
                                                    <option value="<?php echo $year; ?>">
                                                        <?php echo $year; ?>
                                                    </option>
                                                <?php } ?>
                                            </select>

                                            </hr>
                                            <button type="submit" id="downloadButton" class="btn btn-primary">Download</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Smaller Box (2/3 width) -->
                            <div class="col-md-8 mb-4">
                                <div class="card h-100 border border-dark"> <!-- Added border classes -->
                                    <div class="card-body d-flex flex-column justify-content-center">
                                        <form method="POST" action="send_invitation.php" class="w-100">
                                            <label for="email" class="form-label"><b>Invite User: </b></label>
                                            <input type="email" name="email" id="email" class="form-control" placeholder="Enter User Email" required>
                                            <button type="submit" class="btn btn-success mt-2">Send Invitation</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4 mt-4">
                    <div class="col-md-3 mb-3">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <h3 class="text-primary"><?php echo $total_depts; ?></h3>
                                <p class="mb-0">Total Departments</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <h3 class="text-success"><?php echo $completed_depts; ?></h3>
                                <p class="mb-0">Completed</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h3 class="text-warning"><?php echo $in_progress; ?></h3>
                                <p class="mb-0">In Progress</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <h3 class="text-info"><?php echo $avg_progress; ?>%</h3>
                                <p class="mb-0">Average Progress</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Departments Progress Table -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><b>Department Progress Overview</b></h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($departments)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>No departments found.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped" id="departmentsTable">
                                    <thead>
                                        <tr>
                                            <th>Dept Code</th>
                                            <th>Department Name</th>
                                            <th>Category</th>
                                            <th>Email</th>
                                            <th>Progress</th>
                                            <th>Completed Forms</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($departments as $dept): 
                                            $progress = isset($dept['progress']) ? $dept['progress']['progress_percentage'] : 0;
                                            $completed = isset($dept['progress']) ? $dept['progress']['completed_forms'] : 0;
                                            $total = isset($dept['progress']) ? $dept['progress']['total_forms'] : 0;
                                            
                                            // Determine progress bar color
                                            $progress_class = 'bg-success';
                                            if ($progress < 50) {
                                                $progress_class = 'bg-danger';
                                            } elseif ($progress < 100) {
                                                $progress_class = 'bg-warning';
                                            }
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($dept['DEPT_CODE'] ?? '-'); ?></strong></td>
                                                <td><?php echo htmlspecialchars($dept['DEPT_NAME'] ?? '-'); ?></td>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($dept['CATEGORY'] ?? 'Uncategorized'); ?></span></td>
                                                <td><?php echo htmlspecialchars($dept['EMAIL'] ?? '-'); ?></td>
                                                <td>
                                                    <div class="progress" style="height: 24px;">
                                                        <div class="progress-bar <?php echo $progress_class; ?>" style="width: <?php echo $progress; ?>%">
                                                            <?php echo $progress; ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small><?php echo $completed; ?> / <?php echo $total; ?> forms</small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- /#page-content-wrapper -->
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#departmentsTable').DataTable({
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[4, 'desc']] // Sort by progress descending
            });
        });
        
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