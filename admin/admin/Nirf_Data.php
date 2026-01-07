<?php


require('session.php');


error_reporting(0);

// CRITICAL: Academic year logic - use centralized function from root common_functions.php
// This ensures consistency across all modules (dept_login, AAQA_login, admin)
if (file_exists(__DIR__ . '/../common_functions.php')) {
    require_once(__DIR__ . '/../common_functions.php');
}

// Ensure getAcademicYear() is available
if (!function_exists('getAcademicYear')) {
    // Fallback if common_functions.php doesn't have it (should not happen now)
    function getAcademicYear() {
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        
        // Match dept_login logic: month < 7 uses (current_year-2) to (current_year-1)
        if ($current_month >= 7) {
            return $current_year . '-' . ($current_year + 1);
        } else {
            return ($current_year - 2) . '-' . ($current_year - 1);
        }
    }
}

$A_YEAR = getAcademicYear();
error_log("[Admin Nirf_Data] Academic Year: $A_YEAR");

$dept = $_SESSION['dept_id'];

// Removed insecure AJAX handler - Faculty Details now loads directly with proper security
// The old AJAX handler used direct SQL queries without prepared statements and had XSS vulnerabilities





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
<script src="https://code.jquery.com/jquery-3.6.0.min.js">
</script>


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

                <h3 class="page-title">NIRF Data</h3>

                <div class="form-card">

                    <div class="section-header">
                        <h4><i></i>I. Sanctioned(Approved) Intake </h4>
                        <div style="border: 1px solid #dee2e6; border-radius: 4px;">
                            <div style="max-height: 450px; overflow-y: auto; overflow-x: auto;">
                                <table class="table table-bordered table-striped mb-0" id="collegetable" style="width:100%; margin-bottom: 0;">
                                    <thead class="table-light" style="position: sticky; top: 0; z-index: 10; background-color: #f8f9fa;">
                                    <tr>
                                        <th>Programme Type</th>
                                        <th>Intake Capacity (First Year Only) <?php echo htmlspecialchars($A_YEAR, ENT_QUOTES, 'UTF-8'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Check connection before use
                                    if (!isset($conn) || !$conn) {
                                        require_once(__DIR__ . '/../config.php');
                                    }
                                    
                                    // Use prepared statement and exclude test departments
                                    // CRITICAL: Show only year_1_capacity (first year intake) for each programme type
                                    // For UG 4 years, show only UG 1st year intake
                                    // For PG 2 years, show only PG 1st year intake
                                    // CRITICAL: Use ONLY year_1_capacity - no fallback to intake_capacity
                                    // If year_1_capacity is 0 or NULL, it should remain 0 (don't use intake_capacity)
                                    // This matches the CSV data structure where year_1_capacity is the source of truth
                                    // CRITICAL: Exclude ONLY test departments by DEPT_COLL_NO (9998, 9999956, 99999, 9997, 9995)
                                    // CRITICAL: Include ALL departments regardless of DEPT_ID - only exclude by DEPT_COLL_NO
                                    // CRITICAL: Include departments that don't exist in department_master (dm.DEPT_ID IS NULL)
                                    // CRITICAL: Include departments with NULL DEPT_COLL_NO (valid departments)
                                    // CRITICAL: Match CSV data exactly - exclude only test DEPT_COLL_NO values
                                    // CRITICAL: Sum year_1_capacity per programme_type, excluding test departments
                                    // CRITICAL: Exclude only departments with DEPT_COLL_NO in (9998, 9999956, 99999, 9997, 9995)
                                    // CRITICAL: Include all other departments regardless of DEPT_ID
                                    // CRITICAL: Filter STRICTLY by A_YEAR = '2024-2025' - exclude NULL and other years
                                    // CRITICAL: This ensures only current academic year data is included
                                    $query = "SELECT a.programme_type, 
                                              SUM(COALESCE(a.year_1_capacity, 0)) as first_year_capacity
                                          FROM programmes a 
                                              LEFT JOIN department_master dm ON dm.DEPT_ID = a.DEPT_ID
                                              WHERE a.A_YEAR = ? 
                                              AND a.A_YEAR IS NOT NULL
                                              AND a.A_YEAR != ''
                                              AND (
                                                  dm.DEPT_ID IS NULL 
                                                  OR dm.DEPT_COLL_NO IS NULL 
                                                  OR (
                                                      dm.DEPT_COLL_NO IS NOT NULL 
                                                      AND dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995)
                                                  )
                                              )
                                              GROUP BY a.programme_type
                                              HAVING SUM(COALESCE(a.year_1_capacity, 0)) > 0
                                              ORDER BY a.programme_type";
                                    $stmt = mysqli_prepare($conn, $query);
                                    $grand_total_intake = 0;
                                    $has_data = false;
                                    
                                    if ($stmt) {
                                        mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
                                        if (mysqli_stmt_execute($stmt)) {
                                            $result = mysqli_stmt_get_result($stmt);
                                    if ($result) {
                                        while ($row = mysqli_fetch_assoc($result)) {
                                                    $has_data = true;
                                                    $programme_type = htmlspecialchars($row['programme_type'] ?? '', ENT_QUOTES, 'UTF-8');
                                                    $intake_capacity = (int)($row['first_year_capacity'] ?? 0);
                                                    $grand_total_intake += $intake_capacity;
                                            ?>
                                            <tr>
                                                <td class="text-center"><?php echo $programme_type; ?></td>
                                                <td><?php echo $intake_capacity; ?></td>
                                            </tr>
                                            <?php
                                        }
                                                mysqli_free_result($result);
                                            }
                                        }
                                        mysqli_stmt_close($stmt);
                                    }
                                    
                                    if (!$has_data) {
                                        echo '<tr><td colspan="2">No data found for the selected academic year.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                            </div>
                            <?php
                            if (isset($has_data) && $has_data) {
                                ?>
                                <table class="table table-bordered mb-0" style="width:100%; margin-top: 0; border-top: 2px solid #0dcaf0;">
                                    <tfoot>
                                        <tr class="table-info fw-bold" style="background-color: #d1ecf1;">
                                            <td class="text-end"><strong>TOTAL</strong></td>
                                            <td class="text-center"><strong>Total Intake Capacity: <?php echo $grand_total_intake; ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php
                            }
                            ?>
                        </div>

                    </div>












                    <div class="section-header">
                        <h4><i></i>II. Actual Enrollment </h4>
                        <div style="border: 1px solid #dee2e6; border-radius: 4px;">
                            <div style="max-height: 450px; overflow-y: auto; overflow-x: auto;">
                                <table class="table table-bordered table-striped mb-0" id="collegetable" style="width:100%; margin-bottom: 0;">
                                    <thead class="table-light" style="position: sticky; top: 0; z-index: 10; background-color: #f8f9fa;">
                                    <tr>
                                        <th>Programme Type</th>
                                        <th>Male Students</th>
                                        <th>Female Students</th>
                                        <th>Total Students</th>
                                        <th>Male Within State</th>
                                        <th>Female Within State</th>
                                        <th>Male Outside State Within Country</th>
                                        <th>Female Outside State Within Country</th>
                                        <th>Male Outside Country</th>
                                        <th>Female Outside Country</th>
                                        <th>Econamic Backward (Male+Female)</th>
                                        <th>Social Backward (SC+ST+OBC) (Male+Female)</th>
                                        <th>Government Freeship/Scholarship (Male+Female)</th>
                                        <th>Institution Freeship/Scholarship (Male+Female)</th>
                                        <th>Private Body Freeship/Scholarship (Male+Female)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Check connection before use
                                    if (!isset($conn) || !$conn) {
                                        require_once(__DIR__ . '/../config.php');
                                    }
                                    
                                    // Use prepared statement and exclude test departments
                                    // CRITICAL: Join on both DEPT_ID AND PROGRAM_CODE to prevent cartesian product
                                    // intake_actual_strength.PROGRAM_CODE must match programmes.programme_code
                                    $query = "SELECT b.programme_type, 
                                     SUM(a.Total_number_of_Male_Students) as Total_number_of_Male_Students,
                                     SUM(a.Total_number_of_Female_Students) as Total_number_of_Female_Students, 
                                     SUM(a.Total_number_of_Male_Students + a.Total_number_of_Female_Students) as total,
                                     SUM(a.Total_number_of_Male_Students_within_state) as Male_within_state, 
                                     SUM(a.Total_number_of_Female_Students_within_state) as Female_within_state, 
                                     SUM(a.Male_Students_outside_state) as Male_outside_state,
                                     SUM(a.Female_Students_outside_state) as Female_outside_state,
                                     SUM(a.Male_Students_outside_country) as Male_outside_country,
                                     SUM(a.Female_Students_outside_country) as Female_outside_country, 
                                     SUM(a.Male_Students_Economic_Backward + a.Female_Students_Economic_Backward) as eb,
                                     SUM(a.Male_Student_Social_Backward_SC + a.Female_Student_Social_Backward_SC + a.Male_Student_Social_Backward_ST + a.Female_Student_Social_Backward_ST + a.Male_Student_Social_Backward_OBC + a.Female_Student_Social_Backward_OBC) as sb,
                                     SUM(a.Male_Student_Receiving_Scholarship_Government + a.Female_Student_Receiving_Scholarship_Government) as Govt_Scholarship,
                                     SUM(a.Male_Student_Receiving_Scholarship_Institution + a.Female_Student_Receiving_Scholarship_Institution) as Inst_Scholarship,
                                     SUM(a.Male_Student_Receiving_Scholarship_Private_Body + a.Female_Student_Receiving_Scholarship_Private_Body) as Prvt_Scholarship
                                        FROM intake_actual_strength a
                                        INNER JOIN programmes b ON b.DEPT_ID = a.DEPT_ID AND b.programme_code COLLATE utf8mb4_unicode_ci = a.PROGRAM_CODE COLLATE utf8mb4_unicode_ci
                                        LEFT JOIN department_master dm ON dm.DEPT_ID = a.DEPT_ID
                                        WHERE a.A_YEAR = ? 
                                        AND a.A_YEAR IS NOT NULL
                                        AND a.A_YEAR != ''
                                        AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                        GROUP BY b.programme_type
                                        ORDER BY b.programme_type";
                                    $stmt = mysqli_prepare($conn, $query);
                                    $grand_total_male = 0;
                                    $grand_total_female = 0;
                                    $grand_total_students = 0;
                                    $has_data = false;
                                    
                                    if ($stmt) {
                                        mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
                                        if (mysqli_stmt_execute($stmt)) {
                                            $result = mysqli_stmt_get_result($stmt);
                                    if ($result) {
                                        while ($row = mysqli_fetch_assoc($result)) {
                                                    $has_data = true;
                                                    $programme_type = htmlspecialchars($row['programme_type'] ?? '', ENT_QUOTES, 'UTF-8');
                                                    $male = (int)($row['Total_number_of_Male_Students'] ?? 0);
                                                    $female = (int)($row['Total_number_of_Female_Students'] ?? 0);
                                                    $total = (int)($row['total'] ?? 0);
                                                    $grand_total_male += $male;
                                                    $grand_total_female += $female;
                                                    $grand_total_students += $total;
                                            ?>
                                            <tr>
                                                        <td><?php echo $programme_type; ?></td>
                                                        <td><?php echo $male; ?></td>
                                                        <td><?php echo $female; ?></td>
                                                        <td><?php echo $total; ?></td>
                                                        <td><?php echo htmlspecialchars($row['Male_within_state'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['Female_within_state'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['Male_outside_state'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['Female_outside_state'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['Male_outside_country'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['Female_outside_country'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['eb'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['sb'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['Govt_Scholarship'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['Inst_Scholarship'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['Prvt_Scholarship'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                            <?php
                                        }
                                                mysqli_free_result($result);
                                            }
                                        }
                                        mysqli_stmt_close($stmt);
                                    }
                                    
                                    if (!$has_data) {
                                        echo '<tr><td colspan="15">No data found for the selected academic year.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                            </div>
                            <?php
                            if (isset($has_data) && $has_data) {
                                ?>
                                <table class="table table-bordered mb-0" style="width:100%; margin-top: 0; border-top: 2px solid #0dcaf0;">
                                    <tfoot>
                                        <tr class="table-info fw-bold" style="background-color: #d1ecf1;">
                                            <td class="text-end"><strong>TOTAL</strong></td>
                                            <td class="text-center"><strong>Total Male: <?php echo $grand_total_male; ?></strong></td>
                                            <td class="text-center"><strong>Total Female: <?php echo $grand_total_female; ?></strong></td>
                                            <td class="text-center"><strong>Total Students: <?php echo $grand_total_students; ?></strong></td>
                                            <td colspan="11"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    <div class="section-header">
                        <h4><i></i>III. Placement & Higher Studies</h4>
     <div class="table-responsive" style="max-width: 100%; overflow-x: auto;">
                            <table class="table table-bordered" id="collegetable" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th>Programme Type</th>
                                        <th>Total No. of Students</th>
                                        <th>No. of Students Admitted through Lateral Entry</th> 
                                        <th>Total No. of Students Graduated</th>
                                        <th>Total No. of Students Placed</th>
                                        <th>No. of Students in Higher Studies</th>
                                        <th>No. of Students Qualifying Exams like GATE, CAT, NET, etc.</th>
                                        <th>Median Salary of Placed Students (in INR)</th>
                                       
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Check connection before use
                                    if (!isset($conn) || !$conn) {
                                        require_once(__DIR__ . '/../config.php');
                                    }
                                    
                                    // Use prepared statement and exclude test departments
                                    $query = "SELECT 
    p.A_YEAR,
    p.programme_type,
    SUM(pd.TOTAL_NO_OF_STUDENT) AS TOTAL_NO_OF_STUDENT,
    SUM(pd.NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY) AS NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY,
    SUM(pd.TOTAL_NUM_OF_STUDENTS_GRADUATED) AS TOTAL_NUM_OF_STUDENTS_GRADUATED,
    SUM(pd.TOTAL_NUM_OF_STUDENTS_PLACED) AS TOTAL_NUM_OF_STUDENTS_PLACED,
    SUM(pd.NUM_OF_STUDENTS_IN_HIGHER_STUDIES) AS NUM_OF_STUDENTS_IN_HIGHER_STUDIES,
    SUM(pd.STUDENTS_QUALIFYING_EXAMS) AS STUDENTS_QUALIFYING_EXAMS,
    ms.median_salary as Median_salary
FROM placement_details pd
INNER JOIN programmes p ON p.ID = pd.PROGRAM_CODE
LEFT JOIN department_master dm ON dm.DEPT_ID = pd.DEPT_ID
LEFT JOIN (
    SELECT 
        t.programme_type,
        AVG(t.SALARY) AS median_salary
    FROM (
        SELECT 
            p.programme_type,
            sd.SALARY,
            ROW_NUMBER() OVER (
                PARTITION BY p.programme_type 
                ORDER BY sd.SALARY
            ) AS rn,
            COUNT(*) OVER (
                PARTITION BY p.programme_type
            ) AS total_rows
        FROM salary_details sd
        INNER JOIN programmes p
            ON p.programme_code COLLATE utf8mb4_unicode_ci
             = sd.PROGRAM_CODE COLLATE utf8mb4_unicode_ci
        LEFT JOIN department_master dm_sd ON dm_sd.DEPT_ID = sd.DEPT_ID
        WHERE sd.SALARY IS NOT NULL
        AND sd.SALARY > 0
        AND sd.A_YEAR = ?
        AND sd.A_YEAR IS NOT NULL
        AND sd.A_YEAR != ''
        AND (dm_sd.DEPT_COLL_NO IS NULL OR dm_sd.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
    ) t
    WHERE rn IN (
        FLOOR((total_rows + 1) / 2),
        FLOOR((total_rows + 2) / 2)
    )
    GROUP BY t.programme_type
) ms ON ms.programme_type = p.programme_type
WHERE p.A_YEAR = ?
    AND p.A_YEAR IS NOT NULL
    AND p.A_YEAR != ''
    AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
GROUP BY p.A_YEAR, p.programme_type";

                                    $stmt = mysqli_prepare($conn, $query);
                                    $grand_total_students = 0;
                                    $grand_total_lateral = 0;
                                    $grand_total_graduated = 0;
                                    $grand_total_placed = 0;
                                    $grand_total_higher_studies = 0;
                                    $grand_total_qualifying = 0;
                                    $has_data = false;
                                    
                                    if ($stmt) {
                                        // Bind A_YEAR twice: once for the median salary subquery, once for the main query
                                        mysqli_stmt_bind_param($stmt, 'ss', $A_YEAR, $A_YEAR);
                                        if (mysqli_stmt_execute($stmt)) {
                                            $result = mysqli_stmt_get_result($stmt);
                                    if ($result) {
                                        while ($row = mysqli_fetch_assoc($result)) {
                                                    $has_data = true;
                                                    $programme_type = htmlspecialchars($row['programme_type'] ?? '', ENT_QUOTES, 'UTF-8');
                                                    $total_students = (int)($row['TOTAL_NO_OF_STUDENT'] ?? 0);
                                                    $lateral = (int)($row['NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY'] ?? 0);
                                                    $graduated = (int)($row['TOTAL_NUM_OF_STUDENTS_GRADUATED'] ?? 0);
                                                    $placed = (int)($row['TOTAL_NUM_OF_STUDENTS_PLACED'] ?? 0);
                                                    $higher_studies = (int)($row['NUM_OF_STUDENTS_IN_HIGHER_STUDIES'] ?? 0);
                                                    $qualifying = (int)($row['STUDENTS_QUALIFYING_EXAMS'] ?? 0);
                                                    
                                                    // Format median salary: show "-" if NULL, 0, or no placed students
                                                    $median_salary_raw = $row['Median_salary'] ?? null;
                                                    if ($placed > 0 && $median_salary_raw !== null && (double)$median_salary_raw > 0) {
                                                        $median_salary = number_format((double)$median_salary_raw, 0);
                                                    } else {
                                                        $median_salary = '-';
                                                    }
                                                    
                                                    $grand_total_students += $total_students;
                                                    $grand_total_lateral += $lateral;
                                                    $grand_total_graduated += $graduated;
                                                    $grand_total_placed += $placed;
                                                    $grand_total_higher_studies += $higher_studies;
                                                    $grand_total_qualifying += $qualifying;
                                            ?>
                                            <tr>
                                                        <td><?php echo $programme_type; ?></td>
                                                        <td><?php echo $total_students; ?></td>
                                                        <td><?php echo $lateral; ?></td>
                                                        <td><?php echo $graduated; ?></td>
                                                        <td><?php echo $placed; ?></td>
                                                        <td><?php echo $higher_studies; ?></td>
                                                        <td><?php echo $qualifying; ?></td>
                                                        <td><?php echo $median_salary; ?></td>
                                            </tr>
                                            <?php
                                        }
                                                mysqli_free_result($result);
                                            }
                                        }
                                        mysqli_stmt_close($stmt);
                                    }
                                    
                                    if (!$has_data) {
                                        echo '<tr><td colspan="8">No data found for the selected academic year.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                            <?php
                            if (isset($has_data) && $has_data) {
                                ?>
                                <table class="table table-bordered mb-0" style="width:100%; margin-top: 0; border-top: 2px solid #0dcaf0;">
                                    <tfoot>
                                        <tr class="table-info fw-bold" style="background-color: #d1ecf1;">
                                            <td class="text-end"><strong>TOTAL</strong></td>
                                            <td class="text-center"><strong>Total Students: <?php echo $grand_total_students; ?></strong></td>
                                            <td class="text-center"><strong>Total Lateral Entry: <?php echo $grand_total_lateral; ?></strong></td>
                                            <td class="text-center"><strong>Total Graduated: <?php echo $grand_total_graduated; ?></strong></td>
                                            <td class="text-center"><strong>Total Placed: <?php echo $grand_total_placed; ?></strong></td>
                                            <td class="text-center"><strong>Total Higher Studies: <?php echo $grand_total_higher_studies; ?></strong></td>
                                            <td class="text-center"><strong>Total Qualifying Exams: <?php echo $grand_total_qualifying; ?></strong></td>
                                            <td>-</td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php
                            }
                            ?>
                        </div>
                    </div>

                    <div class="section-header">
                        <h4><i></i>IV. Ph.D Student Details</h4>
                        <div>
                            <table class="table table-bordered" id="collegetable" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Full Time</th>
                                        <th>Part Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Check connection before use
                                    if (!isset($conn) || !$conn) {
                                        require_once(__DIR__ . '/../config.php');
                                    }
                                    
                                    // CRITICAL: Use prepared statement for security
                                    $query = "SELECT 
                                        SUM(FULL_TIME_MALE_STUDENTS + FULL_TIME_FEMALE_STUDENTS) as full_time,
                                        SUM(PART_TIME_MALE_STUDENTS + PART_TIME_FEMALE_STUDENTS) as part_time
                                    FROM phd_details pd
                                    LEFT JOIN department_master dm ON dm.DEPT_ID = pd.DEPT_ID
                                    WHERE pd.A_YEAR = ?
                                    AND pd.A_YEAR IS NOT NULL
                                    AND pd.A_YEAR != ''
                                    AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                    GROUP BY pd.A_YEAR";
                                    $stmt = mysqli_prepare($conn, $query);
                                    $has_data = false;
                                    
                                    if ($stmt) {
                                        mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
                                        if (mysqli_stmt_execute($stmt)) {
                                            $result = mysqli_stmt_get_result($stmt);
                                            if ($result && mysqli_num_rows($result) > 0) {
                                                while ($row = mysqli_fetch_assoc($result)) {
                                                    $has_data = true;
                                                    $full_time = (int)($row['full_time'] ?? 0);
                                                    $part_time = (int)($row['part_time'] ?? 0);
                                            ?>
                                            <tr>
                                                <td>Total</td>
                                                <td><?php echo $full_time; ?></td>
                                                <td><?php echo $part_time; ?></td>
                                            </tr>
                                            <?php
                                                }
                                                mysqli_free_result($result);
                                            }
                                        }
                                        mysqli_stmt_close($stmt);
                                    }
                                    
                                    if (!$has_data) {
                                        echo '<tr><td colspan="3">No Ph.D student data found for the selected academic year.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section-header">
                        <h4><i></i>VI. Earning From Patents(IPR) </h4>
                        <div>
                            <table class="table table-bordered" id="collegetable" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Patents Published</th>
                                        <th>Patents Granted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Check connection before use
                                    if (!isset($conn) || !$conn) {
                                        require_once(__DIR__ . '/../config.php');
                                    }
                                    
                                    // CRITICAL: Use prepared statement for security
                                    $query = "SELECT 
                                        SUM(patents_published_2024) as current_published, 
                                        SUM(patents_granted_2024) as current_granted
                                    FROM faculty_output fo
                                    LEFT JOIN department_master dm ON dm.DEPT_ID = fo.DEPT_ID
                                    WHERE fo.A_YEAR = ?
                                    AND fo.A_YEAR IS NOT NULL
                                    AND fo.A_YEAR != ''
                                    AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                    GROUP BY fo.A_YEAR";
                                    $stmt = mysqli_prepare($conn, $query);
                                    $has_data = false;
                                    
                                    if ($stmt) {
                                        mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
                                        if (mysqli_stmt_execute($stmt)) {
                                            $result = mysqli_stmt_get_result($stmt);
                                            if ($result && mysqli_num_rows($result) > 0) {
                                                while ($row = mysqli_fetch_assoc($result)) {
                                                    $has_data = true;
                                                    $current_published = (int)($row['current_published'] ?? 0);
                                                    $current_granted = (int)($row['current_granted'] ?? 0);
                                            ?>
                                            <tr>
                                                <td>Total</td>
                                                <td><?php echo $current_published; ?></td>
                                                <td><?php echo $current_granted; ?></td>
                                            </tr>
                                            <?php
                                                }
                                                mysqli_free_result($result);
                                            }
                                        }
                                        mysqli_stmt_close($stmt);
                                    }
                                    
                                    if (!$has_data) {
                                        echo '<tr><td colspan="3">No patent data found for the selected academic year.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="section-header">
                        <h4><i></i>VII. Sponsored Research</h4>
                        <div style="border: 1px solid #dee2e6; border-radius: 4px;">
                            <div style="max-height: 450px; overflow-y: auto; overflow-x: auto;">
                                <table class="table table-bordered table-striped mb-0" id="collegetable" style="width:100%; margin-bottom: 0;">
                                    <thead class="table-light" style="position: sticky; top: 0; z-index: 10; background-color: #f8f9fa;">
                                        <tr>
                                            <th>Sr. No</th>
                                            <th>Department Name</th>
                                            <th>No. of Projects (Agencies)</th>
                                            <th>Amount from Agencies (₹)</th>
                                            <th>No. of Projects (Industries)</th>
                                            <th>Amount from Industries (₹)</th>
                                            <th>Total Projects</th>
                                            <th>Total Amount (₹)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // CRITICAL: Use prepared statements for security
                                        // Show detailed breakdown by department
                                        $sponsored_query = "SELECT 
                                            fo.A_YEAR,
                                            fo.DEPT_ID,
                                            fo.projects,
                                            fo.sponsored_projects_total,
                                            fo.sponsored_projects_agencies,
                                            fo.sponsored_amount_agencies,
                                            fo.sponsored_projects_industries,
                                            fo.sponsored_amount_industries,
                                            dm.DEPT_NAME
                                        FROM faculty_output fo
                                        LEFT JOIN department_master dm ON dm.DEPT_ID = fo.DEPT_ID
                                        WHERE fo.A_YEAR = ?
                                        AND fo.A_YEAR IS NOT NULL
                                        AND fo.A_YEAR != ''
                                        AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                        ORDER BY dm.DEPT_NAME";
                                        
                                        $stmt = mysqli_prepare($conn, $sponsored_query);
                                        if ($stmt) {
                                            mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
                                            mysqli_stmt_execute($stmt);
                                            $sponsored_result = mysqli_stmt_get_result($stmt);
                                            
                                            $sr_no = 0;
                                            $has_data = false;
                                            // Initialize totals
                                            $grand_total_projects = 0;
                                            $grand_total_agencies = 0;
                                            $grand_total_amount_agencies = 0.0; // Use double precision
                                            $grand_total_industries = 0;
                                            $grand_total_amount_industries = 0.0; // Use double precision
                                            
                                            if ($sponsored_result && mysqli_num_rows($sponsored_result) > 0) {
                                                while ($row = mysqli_fetch_assoc($sponsored_result)) {
                                                    // Initialize counters
                                                    $dept_total = 0;
                                                    $dept_agencies = 0;
                                                    $dept_amount_agencies = 0.0; // Use double precision
                                                    $dept_industries = 0;
                                                    $dept_amount_industries = 0.0; // Use double precision
                                                    
                                                    // Prefer JSON projects field if it exists
                                                    if (!empty($row['projects'])) {
                                                        $projects_json = $row['projects'];
                                                        $projects_data = json_decode($projects_json, true);
                                                        if (is_array($projects_data) && count($projects_data) > 0) {
                                                            foreach ($projects_data as $project) {
                                                                if (empty($project['title']) && empty($project['type']) && empty($project['agency'])) {
                                                                    continue;
                                                                }
                                                                $type = trim($project['type'] ?? '');
                                                                $amount = (double)($project['amount'] ?? 0.0);
                                                                
                                                                if ($amount > 0 && $amount < 1000) {
                                                                    $amount = (double)($amount * 100000.0);
                                                                }
                                                                
                                                                if ($type === 'Govt-Sponsored') {
                                                                    $dept_agencies++;
                                                                    $dept_amount_agencies += $amount;
                                                                    $dept_total++;
                                                                } elseif ($type === 'Non-Govt-Sponsored') {
                                                                    $dept_industries++;
                                                                    $dept_amount_industries += $amount;
                                                                    $dept_total++;
                                                                }
                                                            }
                                                        }
                                                    }
                                                    
                                                    // Fallback to summary fields
                                                    if ($dept_total == 0) {
                                                        $dept_total = (int)($row['sponsored_projects_total'] ?? 0);
                                                        $dept_agencies = (int)($row['sponsored_projects_agencies'] ?? 0);
                                                        $dept_amount_agencies = (double)($row['sponsored_amount_agencies'] ?? 0.0);
                                                        $dept_industries = (int)($row['sponsored_projects_industries'] ?? 0);
                                                        $dept_amount_industries = (double)($row['sponsored_amount_industries'] ?? 0.0);
                                                    }
                                                    
                                                    // Only show rows with data
                                                    if ($dept_total > 0 || $dept_amount_agencies > 0 || $dept_amount_industries > 0) {
                                                        $has_data = true;
                                                        $sr_no++;
                                                        $total_amount = $dept_amount_agencies + $dept_amount_industries;
                                                        $dept_name = htmlspecialchars($row['DEPT_NAME'] ?? 'Unknown Department', ENT_QUOTES, 'UTF-8');
                                                        
                                                        // Add to grand totals
                                                        $grand_total_projects += $dept_total;
                                                        $grand_total_agencies += $dept_agencies;
                                                        $grand_total_amount_agencies += $dept_amount_agencies;
                                                        $grand_total_industries += $dept_industries;
                                                        $grand_total_amount_industries += $dept_amount_industries;
                                                        ?>
                                                        <tr>
                                                            <td><?php echo $sr_no; ?></td>
                                                            <td><?php echo $dept_name; ?></td>
                                                            <td><?php echo $dept_agencies; ?></td>
                                                            <td><?php echo number_format($dept_amount_agencies, 2); ?></td>
                                                            <td><?php echo $dept_industries; ?></td>
                                                            <td><?php echo number_format($dept_amount_industries, 2); ?></td>
                                                            <td><?php echo $dept_total; ?></td>
                                                            <td><?php echo number_format($total_amount, 2); ?></td>
                                                        </tr>
                                                        <?php
                                                    }
                                                }
                                                
                                                if (!$has_data) {
                                                    echo '<tr><td colspan="8">No sponsored research data found for the selected academic year.</td></tr>';
                                                }
                                                mysqli_free_result($sponsored_result);
                                            } else {
                                                echo '<tr><td colspan="8">No sponsored research data found for the selected academic year.</td></tr>';
                                            }
                                            mysqli_stmt_close($stmt);
                                        } else {
                                            echo '<tr><td colspan="8">Database query error. Please try again.</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php
                            if (isset($has_data) && $has_data) {
                                $grand_total_amount = $grand_total_amount_agencies + $grand_total_amount_industries;
                                ?>
                                <table class="table table-bordered mb-0" style="width:100%; margin-top: 0; border-top: 2px solid #0dcaf0;">
                                    <tfoot>
                                        <tr class="table-info fw-bold" style="background-color: #d1ecf1;">
                                            <td colspan="2" class="text-end"><strong>TOTAL</strong></td>
                                            <td class="text-center"><strong>Projects (Agencies): <?php echo $grand_total_agencies; ?></strong></td>
                                            <td class="text-center"><strong>Amount: <?php echo number_format($grand_total_amount_agencies, 2); ?></strong></td>
                                            <td class="text-center"><strong>Projects (Industries): <?php echo $grand_total_industries; ?></strong></td>
                                            <td class="text-center"><strong>Amount: <?php echo number_format($grand_total_amount_industries, 2); ?></strong></td>
                                            <td class="text-center"><strong>Total Projects: <?php echo $grand_total_projects; ?></strong></td>
                                            <td class="text-center"><strong>Total Amount: <?php echo number_format($grand_total_amount, 2); ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    <div class="section-header">
                        <h4><i></i>VIII. Consultancy Project Details</h4>
                        <div style="border: 1px solid #dee2e6; border-radius: 4px;">
                            <div style="max-height: 450px; overflow-y: auto; overflow-x: auto;">
                                <table class="table table-bordered table-striped mb-0" id="collegetable" style="width:100%; margin-bottom: 0;">
                                    <thead class="table-light" style="position: sticky; top: 0; z-index: 10; background-color: #f8f9fa;">
                                        <tr>
                                            <th>Sr. No</th>
                                            <th>Department Name</th>
                                            <th>No. of Consultancy Projects</th>
                                            <th>No. of Client Organizations</th>
                                            <th>Total Amount Received (₹)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // CRITICAL: Use prepared statements for security
                                        // Show detailed breakdown by department
                                        $consultancy_query = "SELECT 
                                            cp.A_YEAR,
                                            cp.DEPT_ID,
                                            cp.TOTAL_NO_OF_CP,
                                            cp.TOTAL_NO_OF_CLIENT,
                                            cp.TOTAL_AMT_RECEIVED,
                                            dm.DEPT_NAME
                                        FROM consultancy_projects cp
                                        LEFT JOIN department_master dm ON dm.DEPT_ID = cp.DEPT_ID
                                        WHERE cp.A_YEAR = ?
                                        AND cp.A_YEAR IS NOT NULL
                                        AND cp.A_YEAR != ''
                                        AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                        ORDER BY dm.DEPT_NAME";
                                        
                                        $stmt = mysqli_prepare($conn, $consultancy_query);
                                        if ($stmt) {
                                            mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
                                            mysqli_stmt_execute($stmt);
                                            $consultancy_result = mysqli_stmt_get_result($stmt);
                                            
                                            $consultancy_data = [];
                                            
                                            if ($consultancy_result && mysqli_num_rows($consultancy_result) > 0) {
                                                while ($row = mysqli_fetch_assoc($consultancy_result)) {
                                                    $dept_id = (int)($row['DEPT_ID'] ?? 0);
                                                    if (!isset($consultancy_data[$dept_id])) {
                                                        $consultancy_data[$dept_id] = [
                                                            'dept_name' => $row['DEPT_NAME'] ?? 'Unknown Department',
                                                            'projects' => 0,
                                                            'clients' => 0,
                                                            'amount' => 0.0
                                                        ];
                                                    }
                                                    $consultancy_data[$dept_id]['projects'] += (int)($row['TOTAL_NO_OF_CP'] ?? 0);
                                                    $consultancy_data[$dept_id]['clients'] += (int)($row['TOTAL_NO_OF_CLIENT'] ?? 0);
                                                    $consultancy_data[$dept_id]['amount'] += (double)($row['TOTAL_AMT_RECEIVED'] ?? 0.0);
                                                }
                                                mysqli_free_result($consultancy_result);
                                            }
                                            mysqli_stmt_close($stmt);
                                            
                                            // Also check faculty_output.projects JSON for Consultancy type projects
                                            $consultancy_json_query = "SELECT 
                                                fo.DEPT_ID,
                                                fo.projects,
                                                dm.DEPT_NAME
                                            FROM faculty_output fo
                                            LEFT JOIN department_master dm ON dm.DEPT_ID = fo.DEPT_ID
                                            WHERE fo.A_YEAR = ? 
                                            AND fo.A_YEAR IS NOT NULL
                                            AND fo.A_YEAR != ''
                                            AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                            AND fo.projects IS NOT NULL AND fo.projects != '' AND fo.projects != '[]'";
                                            $stmt_json = mysqli_prepare($conn, $consultancy_json_query);
                                            if ($stmt_json) {
                                                mysqli_stmt_bind_param($stmt_json, 's', $A_YEAR);
                                                mysqli_stmt_execute($stmt_json);
                                                $json_result = mysqli_stmt_get_result($stmt_json);
                                                
                                                if ($json_result && mysqli_num_rows($json_result) > 0) {
                                                    while ($row_json = mysqli_fetch_assoc($json_result)) {
                                                        $dept_id = (int)($row_json['DEPT_ID'] ?? 0);
                                                        $projects_data = json_decode($row_json['projects'], true);
                                                        if (is_array($projects_data)) {
                                                            if (!isset($consultancy_data[$dept_id])) {
                                                                $consultancy_data[$dept_id] = [
                                                                    'dept_name' => $row_json['DEPT_NAME'] ?? 'Unknown Department',
                                                                    'projects' => 0,
                                                                    'clients' => 0,
                                                                    'amount' => 0.0
                                                                ];
                                                            }
                                                            $unique_clients = [];
                                                            foreach ($projects_data as $project) {
                                                                $type = trim($project['type'] ?? '');
                                                                if ($type === 'Consultancy') {
                                                                    $consultancy_data[$dept_id]['projects']++;
                                                                    $agency = trim($project['agency'] ?? '');
                                                                    if (!empty($agency) && !in_array($agency, $unique_clients)) {
                                                                        $unique_clients[] = $agency;
                                                                    }
                                                                    $amount = (double)($project['amount'] ?? 0.0);
                                                                    if ($amount > 0 && $amount < 1000) {
                                                                        $amount = (double)($amount * 100000.0);
                                                                    }
                                                                    $consultancy_data[$dept_id]['amount'] += $amount;
                                                                }
                                                            }
                                                            $consultancy_data[$dept_id]['clients'] = max($consultancy_data[$dept_id]['clients'], count($unique_clients));
                                                        }
                                                    }
                                                    mysqli_free_result($json_result);
                                                }
                                                mysqli_stmt_close($stmt_json);
                                            }
                                            
                                            $sr_no = 0;
                                            $has_data = false;
                                            // Initialize grand totals
                                            $grand_total_projects = 0;
                                            $grand_total_clients = 0;
                                            $grand_total_amount = 0.0; // Use double precision
                                            
                                            foreach ($consultancy_data as $dept_id => $data) {
                                                if ($data['projects'] > 0 || $data['amount'] > 0) {
                                                    $has_data = true;
                                                    $sr_no++;
                                                    $dept_name = htmlspecialchars($data['dept_name'], ENT_QUOTES, 'UTF-8');
                                                    
                                                    // Add to grand totals
                                                    $grand_total_projects += $data['projects'];
                                                    $grand_total_clients += $data['clients'];
                                                    $grand_total_amount += $data['amount'];
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $sr_no; ?></td>
                                                        <td><?php echo $dept_name; ?></td>
                                                        <td><?php echo $data['projects']; ?></td>
                                                        <td><?php echo $data['clients']; ?></td>
                                                        <td><?php echo number_format($data['amount'], 2); ?></td>
                                                    </tr>
                                                    <?php
                                                }
                                            }
                                            
                                            if (!$has_data) {
                                                echo '<tr><td colspan="5">No consultancy project data found for the selected academic year.</td></tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="5">Database query error. Please try again.</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php
                            if (isset($has_data) && $has_data) {
                                ?>
                                <table class="table table-bordered mb-0" style="width:100%; margin-top: 0; border-top: 2px solid #0dcaf0;">
                                    <tfoot>
                                        <tr class="table-info fw-bold" style="background-color: #d1ecf1;">
                                            <td colspan="2" class="text-end"><strong>TOTAL</strong></td>
                                            <td class="text-center"><strong>Total Projects: <?php echo $grand_total_projects; ?></strong></td>
                                            <td class="text-center"><strong>Total Clients: <?php echo $grand_total_clients; ?></strong></td>
                                            <td class="text-center"><strong>Total Amount: <?php echo number_format($grand_total_amount, 2); ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    <div class="section-header">
                        <h4><i></i>IX. Executive Development Programs (Minimum one year duration)</h4>
                        <div class="table-responsive" style="max-width: 100%; overflow-x: auto;">
                            <table class="table table-bordered" id="collegetable" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th>Financial Year</th>
                                        <th>Total No. of Executive Development Programs</th>
                                        <th>Total No. of Participants</th>
                                        <th>Total Annual Earnings (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Check connection before use
                                    if (!isset($conn) || !$conn) {
                                        require_once(__DIR__ . '/../config.php');
                                    }
                                    
                                    // CRITICAL: Use prepared statements for security
                                    // NOTE: Some departments store TOTAL_INCOME in lakhs (e.g., 1.5 for ₹1.5 Lakhs)
                                    // while others store it in rupees. We need to handle both cases.
                                    // If value < 1000, assume it's in lakhs and convert to rupees (multiply by 100000)
                                    // CRITICAL: Use SQL aggregation with CASE statement for accurate conversion
                                    // CRITICAL: Use CAST to DOUBLE for precision to avoid decimal loss
                                    // CRITICAL: Filter test departments properly - exclude ONLY by DEPT_COLL_NO (9998, 9999956, 99999, 9997, 9995)
                                    // CRITICAL: Include all departments regardless of DEPT_ID
                                    $exec_query = "SELECT 
                                        ed.A_YEAR,
                                        SUM(ed.NO_OF_EXEC_PROGRAMS) AS total_programs,
                                        SUM(ed.TOTAL_PARTICIPANTS) AS total_participants,
                                        SUM(
                                            CASE 
                                                WHEN ed.TOTAL_INCOME > 0 AND ed.TOTAL_INCOME < 1000 THEN 
                                                    CAST(ed.TOTAL_INCOME AS DOUBLE) * 100000.0
                                                ELSE 
                                                    CAST(ed.TOTAL_INCOME AS DOUBLE)
                                            END
                                        ) AS total_income_rupees
                                    FROM exec_dev ed
                                    LEFT JOIN department_master dm ON dm.DEPT_ID = ed.DEPT_ID
                                    WHERE ed.A_YEAR = ?
                                    AND ed.A_YEAR IS NOT NULL
                                    AND ed.A_YEAR != ''
                                    AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                    GROUP BY ed.A_YEAR";
                                    
                                    $stmt = mysqli_prepare($conn, $exec_query);
                                    $grand_total_programs = 0;
                                    $grand_total_participants = 0;
                                    $grand_total_income_rupees = 0.0; // Use double precision
                                    $has_data = false;
                                    
                                    if ($stmt) {
                                        mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
                                        if (mysqli_stmt_execute($stmt)) {
                                            $exec_result = mysqli_stmt_get_result($stmt);
                                            
                                            if ($exec_result && mysqli_num_rows($exec_result) > 0) {
                                                while ($row = mysqli_fetch_assoc($exec_result)) {
                                                    $has_data = true;
                                                    $grand_total_programs = (int)($row['total_programs'] ?? 0);
                                                    $grand_total_participants = (int)($row['total_participants'] ?? 0);
                                                    $grand_total_income_rupees = (double)($row['total_income_rupees'] ?? 0.0); // Use double for precision
                                                }
                                                
                                                // Display aggregated row
                                                $academic_year = htmlspecialchars($A_YEAR, ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <tr>
                                                    <td><?php echo $academic_year; ?></td>
                                                    <td><?php echo $grand_total_programs; ?></td>
                                                    <td><?php echo $grand_total_participants; ?></td>
                                                    <td><?php echo number_format($grand_total_income_rupees, 2); ?></td>
                                            </tr>
                                            <?php
                                                mysqli_free_result($exec_result);
                                            } else {
                                                echo '<tr><td colspan="4">No executive development program data found for the selected academic year.</td></tr>';
                                        }
                                    } else {
                                            echo '<tr><td colspan="4">Database query execution error. Please try again.</td></tr>';
                                        }
                                        mysqli_stmt_close($stmt);
                                    } else {
                                        echo '<tr><td colspan="4">Database query preparation error. Please try again.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                            <?php
                            if (isset($has_data) && $has_data) {
                                // Calculate total in lakhs using double precision
                                $grand_total_income_lakhs = (double)($grand_total_income_rupees / 100000);
                                ?>
                                <table class="table table-bordered mb-0" style="width:100%; margin-top: 0; border-top: 2px solid #0dcaf0;">
                                    <tfoot>
                                        <tr class="table-info fw-bold" style="background-color: #d1ecf1;">
                                            <td class="text-end"><strong>TOTAL</strong></td>
                                            <td class="text-center"><strong>Total Programs: <?php echo $grand_total_programs; ?></strong></td>
                                            <td class="text-center"><strong>Total Participants: <?php echo $grand_total_participants; ?></strong></td>
                                            <td class="text-center">
                                                <strong>
                                                    Total Amount: ₹<?php echo number_format($grand_total_income_rupees, 2); ?> 
                                                    (<?php echo number_format($grand_total_income_lakhs, 4); ?> Lakhs)
                                                </strong>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    <div class="section-header">
                        <h4><i></i>X. Online Education</h4>
                        <div style="border: 1px solid #dee2e6; border-radius: 4px;">
                            <div style="max-height: 450px; overflow-y: auto; overflow-x: auto;">
                                <table class="table table-bordered table-striped mb-0" id="collegetable" style="width:100%; margin-bottom: 0;">
                                    <thead class="table-light" style="position: sticky; top: 0; z-index: 10; background-color: #f8f9fa;">
                                        <tr>
                                            <th>Sr. No</th>
                                            <th>Department Name</th>
                                            <th>Course Title</th>
                                            <th>Platform</th>
                                            <th>No. of Students</th>
                                            <th>No. of Credits Transferred</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // CRITICAL: Use prepared statements for security
                                        // Online education data is stored in nepmarks.mooc_data JSON field
                                        // nepmarks.A_YEAR is INT (stores year like 2024), not VARCHAR
                                        $year_start = (int)explode('-', $A_YEAR)[0];
                                        
                                        // Query nepmarks table for mooc_data JSON field with department info
                                        $online_query = "SELECT 
                                            nm.mooc_data,
                                            nm.DEPT_ID,
                                            dm.DEPT_NAME
                                        FROM nepmarks nm
                                        LEFT JOIN department_master dm ON dm.DEPT_ID = nm.DEPT_ID
                                        WHERE nm.A_YEAR = ?
                                        AND nm.A_YEAR IS NOT NULL
                                        AND CAST(nm.A_YEAR AS CHAR) != ''
                                        AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                        AND (nm.mooc_data IS NOT NULL AND nm.mooc_data != '' AND nm.mooc_data != '[]')
                                        ORDER BY dm.DEPT_NAME";
                                        
                                        $stmt = mysqli_prepare($conn, $online_query);
                                        if ($stmt) {
                                            mysqli_stmt_bind_param($stmt, 'i', $year_start);
                                            mysqli_stmt_execute($stmt);
                                            $online_result = mysqli_stmt_get_result($stmt);
                                            
                                            $sr_no = 0;
                                            $has_data = false;
                                            // Initialize grand totals
                                            $grand_total_students = 0;
                                            $grand_total_courses = 0;
                                            $grand_total_credits = 0;
                                            
                                            if ($online_result && mysqli_num_rows($online_result) > 0) {
                                                while ($row = mysqli_fetch_assoc($online_result)) {
                                                    if (!empty($row['mooc_data'])) {
                                                        $mooc_data = json_decode($row['mooc_data'], true);
                                                        $dept_name = htmlspecialchars($row['DEPT_NAME'] ?? 'Unknown Department', ENT_QUOTES, 'UTF-8');
                                                        
                                                        if (is_array($mooc_data) && count($mooc_data) > 0) {
                                                            foreach ($mooc_data as $mooc) {
                                                                if (is_array($mooc)) {
                                                                    $students = (int)($mooc['students'] ?? 0);
                                                                    $credits = (int)($mooc['credits'] ?? 0);
                                                                    $platform = trim($mooc['platform'] ?? '');
                                                                    $title = trim($mooc['title'] ?? '');
                                                                    
                                                                    // Show entry if it has any data
                                                                    if (!empty($platform) || !empty($title) || $students > 0 || $credits > 0) {
                                                                        $has_data = true;
                                                                        $sr_no++;
                                                                        $grand_total_courses++;
                                                                        $grand_total_students += $students;
                                                                        $grand_total_credits += $credits;
                                                                        ?>
                                                                        <tr>
                                                                            <td><?php echo $sr_no; ?></td>
                                                                            <td><?php echo $dept_name; ?></td>
                                                                            <td><?php echo htmlspecialchars($title ?: 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                                                            <td><?php echo htmlspecialchars($platform ?: 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                                                            <td><?php echo $students; ?></td>
                                                                            <td><?php echo $credits; ?></td>
                                                                        </tr>
                                                                        <?php
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                                mysqli_free_result($online_result);
                                                
                                                if (!$has_data) {
                                                    echo '<tr><td colspan="6">No online education data found for the selected academic year.</td></tr>';
                                                }
                                            } else {
                                                echo '<tr><td colspan="6">No online education data found for the selected academic year.</td></tr>';
                                            }
                                            mysqli_stmt_close($stmt);
                                        } else {
                                            echo '<tr><td colspan="6">Database query error. Please try again.</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php
                            if (isset($has_data) && $has_data) {
                                ?>
                                <table class="table table-bordered mb-0" style="width:100%; margin-top: 0; border-top: 2px solid #0dcaf0;">
                                    <tfoot>
                                        <tr class="table-info fw-bold" style="background-color: #d1ecf1;">
                                            <td colspan="2" class="text-end"><strong>TOTAL</strong></td>
                                            <td class="text-center"><strong>Total Courses: <?php echo $grand_total_courses; ?></strong></td>
                                            <td class="text-center">-</td>
                                            <td class="text-center"><strong>Total Students: <?php echo $grand_total_students; ?></strong></td>
                                            <td class="text-center"><strong>Total Credits: <?php echo $grand_total_credits; ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php
                            }
                            ?>
                        </div>
                        </div>
                    <div class="section-header">
                        <h4><i></i>XI. Faculty Details</h4>
                        <div style="border: 1px solid #dee2e6; border-radius: 4px;">
                            <div style="max-height: 450px; overflow-y: auto; overflow-x: auto;">
                                <table class="table table-bordered table-striped mb-0" id="collegetable" style="width:100%; margin-bottom: 0;">
                                    <thead class="table-light" style="position: sticky; top: 0; z-index: 10; background-color: #f8f9fa;">
                                    <tr>
                                            <th>Sr. No</th>
                                            <th>Department Name</th>
                                            <th>Faculty Name</th>
                                            <th>Gender</th>
                                            <th>Designation</th>
                                            <th>Date of Birth</th>
                                            <th>Age</th>
                                            <th>Qualification</th>
                                            <th>Experience</th>
                                            <th>PAN Number</th>
                                            <th>Previous Year</th>
                                            <th>Teaching Exp</th>
                                            <th>Industrial Exp</th>
                                            <th>Joining Date</th>
                                            <th>Latest Date</th>
                                            <th>Association Type</th>
                                            <th>Email ID</th>
                                            <th>Mobile Number</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        // Check connection before use
                                        if (!isset($conn) || !$conn) {
                                            require_once(__DIR__ . '/../config.php');
                                        }
                                        
                                        // Use prepared statement for security
                                        // CRITICAL: Use DISTINCT or GROUP BY to prevent duplicate faculty records
                                        // Some departments may have duplicate entries, so we need to ensure unique counting
                                        // CRITICAL: Exclude ONLY test departments by DEPT_COLL_NO (9998, 9999956, 99999, 9997, 9995)
                                        // CRITICAL: Ensure all departments are properly excluded to prevent missing data
                                        $faculty_query = "SELECT DISTINCT
                                            a.A_YEAR, 
                                            a.DEPT_ID, 
                                            a.FACULTY_NAME, 
                                            a.GENDER,
                                            a.DESIGNATION, 
                                            a.DOB, 
                                            a.AGE, 
                                            a.QUALIF, 
                                            a.EXPERIENCE, 
                                            a.PAN_NUM, 
                                            a.FACULTY_ASSO_IN_PREV_YEAR, 
                                            a.FACULTY_EXP_TEACHING, 
                                            a.FACULTY_EXP_INDUSTRIAL, 
                                            a.JOINING_INSTITUTE_DATE, 
                                            a.LATEST_DATE, 
                                            a.ASSOC_TYPE, 
                                            a.EMAIL_ID,  
                                            a.MOBILE_NUM, 
                                            b.DEPT_NAME
                                    FROM faculty_details a 
                                        INNER JOIN department_master b ON b.DEPT_ID = a.DEPT_ID
                                        WHERE a.A_YEAR = ?
                                        AND a.A_YEAR IS NOT NULL
                                        AND a.A_YEAR != ''
                                        AND (b.DEPT_COLL_NO IS NULL OR b.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                        ORDER BY b.DEPT_NAME, a.FACULTY_NAME";
                                        
                                        $stmt = mysqli_prepare($conn, $faculty_query);
                                        if ($stmt) {
                                            mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
                                            if (mysqli_stmt_execute($stmt)) {
                                                $faculty_result = mysqli_stmt_get_result($stmt);
                                                if ($faculty_result) {
                                                    $sr_no = 0;
                                                    $has_data = false;
                                                    $grand_total_faculty = 0;
                                                    
                                                    while ($row = mysqli_fetch_assoc($faculty_result)) {
                                                        $has_data = true;
                                                        $sr_no++;
                                                        $grand_total_faculty++;
                                                        
                                                        // Escape all output to prevent XSS
                                                        $dept_name = htmlspecialchars($row['DEPT_NAME'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $faculty_name = htmlspecialchars($row['FACULTY_NAME'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $gender = htmlspecialchars($row['GENDER'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $designation = htmlspecialchars($row['DESIGNATION'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $dob = htmlspecialchars($row['DOB'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $age = htmlspecialchars($row['AGE'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $qualif = htmlspecialchars($row['QUALIF'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $experience = htmlspecialchars($row['EXPERIENCE'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $pan_num = htmlspecialchars($row['PAN_NUM'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $prev_year = htmlspecialchars($row['FACULTY_ASSO_IN_PREV_YEAR'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $teaching_exp = htmlspecialchars($row['FACULTY_EXP_TEACHING'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $industrial_exp = htmlspecialchars($row['FACULTY_EXP_INDUSTRIAL'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $joining_date = htmlspecialchars($row['JOINING_INSTITUTE_DATE'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $latest_date = htmlspecialchars($row['LATEST_DATE'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $assoc_type = htmlspecialchars($row['ASSOC_TYPE'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $email = htmlspecialchars($row['EMAIL_ID'] ?? '', ENT_QUOTES, 'UTF-8');
                                                        $mobile = htmlspecialchars($row['MOBILE_NUM'] ?? '', ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <tr>
                                                            <td><?php echo $sr_no; ?></td>
                                                            <td><?php echo $dept_name; ?></td>
                                                            <td><?php echo $faculty_name; ?></td>
                                                            <td><?php echo $gender; ?></td>
                                                            <td><?php echo $designation; ?></td>
                                                            <td><?php echo $dob; ?></td>
                                                            <td><?php echo $age; ?></td>
                                                            <td><?php echo $qualif; ?></td>
                                                            <td><?php echo $experience; ?></td>
                                                            <td><?php echo $pan_num; ?></td>
                                                            <td><?php echo $prev_year; ?></td>
                                                            <td><?php echo $teaching_exp; ?></td>
                                                            <td><?php echo $industrial_exp; ?></td>
                                                            <td><?php echo $joining_date; ?></td>
                                                            <td><?php echo $latest_date; ?></td>
                                                            <td><?php echo $assoc_type; ?></td>
                                                            <td><?php echo $email; ?></td>
                                                            <td><?php echo $mobile; ?></td>
                                            </tr>
                                            <?php
                                        }
                                                    
                                                    if (!$has_data) {
                                                        echo '<tr><td colspan="18">No faculty data found for the selected academic year.</td></tr>';
                                                    }
                                                    
                                                    mysqli_free_result($faculty_result);
                                    } else {
                                                    echo '<tr><td colspan="18">Database query error. Please try again.</td></tr>';
                                                }
                                            } else {
                                                echo '<tr><td colspan="18">Database query execution error. Please try again.</td></tr>';
                                            }
                                            mysqli_stmt_close($stmt);
                                        } else {
                                            echo '<tr><td colspan="18">Database query preparation error. Please try again.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                            </div>
                            <?php
                            if (isset($has_data) && $has_data && isset($grand_total_faculty)) {
                                ?>
                                <table class="table table-bordered mb-0" style="width:100%; margin-top: 0; border-top: 2px solid #0dcaf0;">
                                    <tfoot>
                                        <tr class="table-info fw-bold" style="background-color: #d1ecf1;">
                                            <td colspan="2" class="text-end"><strong>TOTAL</strong></td>
                                            <td colspan="16" class="text-center"><strong>Total Faculty: <?php echo $grand_total_faculty; ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php
                            }
                            ?>
                        </div>
                    </div>

                    <div class="section-header">
                        <h4><i></i>XII. Acadamic Peers</h4>
                        <div style="border: 1px solid #dee2e6; border-radius: 4px;">
                            <div style="max-height: 450px; overflow-y: auto; overflow-x: auto;">
                                <table class="table table-bordered table-striped mb-0" id="collegetable" style="width:100%; margin-bottom: 0;">
                                    <thead class="table-light" style="position: sticky; top: 0; z-index: 10; background-color: #f8f9fa;">
                                        <tr>
                                            <th>SOURCE</th>                                       
                                            <th>TITLE</th>
                                            <th>FIRST_NAME</th>
                                            <th>LAST_NAME</th>
                                            <th>JOB_TITLE</th>
                                            <th>DEPARTMENT</th>
                                            <th>SUBJECT</th>
                                            <th>INSTITUTION</th>
                                            <th>COUNTRY</th>
                                            <th>EMAIL</th>
                                            <th>PHONE</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Check connection before use
                                    if (!isset($conn) || !$conn) {
                                        require_once(__DIR__ . '/../config.php');
                                    }
                                    
                                    // Use prepared statement and exclude test departments
                                    $query = "SELECT ap.SOURCE, ap.TITLE, ap.FIRST_NAME, ap.LAST_NAME, ap.JOB_TITLE, ap.DEPARTMENT, ap.INSTITUTION, ap.COUNTRY, ap.EMAIL, ap.SUBJECT, ap.PHONE
                                     FROM academic_peers ap
                                     LEFT JOIN department_master dm ON dm.DEPT_ID = ap.DEPT_ID
                                     WHERE ap.A_YEAR = ?
                                     AND ap.A_YEAR IS NOT NULL
                                     AND ap.A_YEAR != ''
                                     AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
                                     ORDER BY ap.SOURCE, ap.FIRST_NAME, ap.LAST_NAME";
                                    $stmt = mysqli_prepare($conn, $query);
                                    $grand_total_peers = 0;
                                    $has_data = false;
                                    
                                    if ($stmt) {
                                        mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
                                        if (mysqli_stmt_execute($stmt)) {
                                            $result = mysqli_stmt_get_result($stmt);
                                    if ($result) {
                                        while ($row = mysqli_fetch_assoc($result)) {
                                                    $has_data = true;
                                                    $grand_total_peers++;
                                            ?>
                                            <tr>
                                                        <td><?php echo htmlspecialchars($row['SOURCE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['TITLE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['FIRST_NAME'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['LAST_NAME'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['JOB_TITLE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['DEPARTMENT'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['SUBJECT'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['INSTITUTION'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['COUNTRY'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['EMAIL'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['PHONE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                            <?php
                                        }
                                                mysqli_free_result($result);
                                            }
                                        }
                                        mysqli_stmt_close($stmt);
                                    }
                                    
                                    if (!$has_data) {
                                        echo '<tr><td colspan="11">No academic peers data found for the selected academic year.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                            </div>
                            <?php
                            if (isset($has_data) && $has_data) {
                                ?>
                                <table class="table table-bordered mb-0" style="width:100%; margin-top: 0; border-top: 2px solid #0dcaf0;">
                                    <tfoot>
                                        <tr class="table-info fw-bold" style="background-color: #d1ecf1;">
                                            <td colspan="11" class="text-center"><strong>TOTAL Academic Peers: <?php echo $grand_total_peers; ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php
                            }
                            ?>
                        </div> 
                    </div>

                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            var el = document.getElementById("wrapper");
            var toggleButton = document.getElementById("menu-toggle");

            toggleButton.onclick = function () {
                el.classList.toggle("toggled");
            };
        </script>
        <!-- Footer -->
        <?php include '../footer_main.php'; ?>
</body>

</html>