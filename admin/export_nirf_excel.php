<?php
require('session.php');
// Check connection
if (!isset($conn) || !$conn) {
    require_once(__DIR__ . '/../config.php');
}

error_reporting(0);

// Academic year logic - use same centralized logic as dept_login
if (file_exists(__DIR__ . '/../dept_login/common_functions.php')) {
    require_once(__DIR__ . '/../dept_login/common_functions.php');
}
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    // Fallback: July onwards (month >= 7) is current_year-current_year+1
    $current_year = (int) date('Y');
    $current_month = (int) date('n');
    $A_YEAR = ($current_month >= 7)
        ? $current_year . '-' . ($current_year + 1)
        : ($current_year - 2) . '-' . ($current_year - 1);
}

$type = $_GET['type'] ?? '';
$filename = "NIRF_Data_" . $type . "_" . date('Y-m-d') . ".xls";

// Set headers for Excel download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 5px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
<?php

if ($type === 'faculty') {
    echo "<h3>XI. Faculty Details ($A_YEAR)</h3>";
    echo "<table>
            <thead>
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
            <tbody>";

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
        AND (b.DEPT_COLL_NO IS NULL OR b.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
        ORDER BY b.DEPT_NAME, a.FACULTY_NAME";

    $stmt = mysqli_prepare($conn, $faculty_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            $sr_no = 0;
            while ($row = mysqli_fetch_assoc($result)) {
                $sr_no++;
                echo "<tr>
                    <td>$sr_no</td>
                    <td>" . htmlspecialchars($row['DEPT_NAME'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['FACULTY_NAME'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['GENDER'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['DESIGNATION'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['DOB'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['AGE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['QUALIF'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['EXPERIENCE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['PAN_NUM'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['FACULTY_ASSO_IN_PREV_YEAR'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['FACULTY_EXP_TEACHING'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['FACULTY_EXP_INDUSTRIAL'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['JOINING_INSTITUTE_DATE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['LATEST_DATE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['ASSOC_TYPE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['EMAIL_ID'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['MOBILE_NUM'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                </tr>";
            }
        }
        mysqli_stmt_close($stmt);
    }
    echo "</tbody></table>";

} elseif ($type === 'peers') {
    echo "<h3>XII. Academic Peers ($A_YEAR)</h3>";
    echo "<table>
            <thead>
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
            <tbody>";

    $query = "SELECT ap.SOURCE, ap.TITLE, ap.FIRST_NAME, ap.LAST_NAME, ap.JOB_TITLE, ap.DEPARTMENT, ap.INSTITUTION, ap.COUNTRY, ap.EMAIL, ap.SUBJECT, ap.PHONE
              FROM academic_peers ap
              LEFT JOIN department_master dm ON dm.DEPT_ID = ap.DEPT_ID
              WHERE ap.A_YEAR = ?
              AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
              ORDER BY ap.SOURCE, ap.FIRST_NAME, ap.LAST_NAME";

    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                echo "<tr>
                    <td>" . htmlspecialchars($row['SOURCE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['TITLE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['FIRST_NAME'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['LAST_NAME'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['JOB_TITLE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['DEPARTMENT'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['SUBJECT'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['INSTITUTION'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['COUNTRY'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['EMAIL'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['PHONE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                </tr>";
            }
        }
        mysqli_stmt_close($stmt);
    }
    echo "</tbody></table>";

} elseif ($type === 'employers') {
    echo "<h3>XII (B). Employer Details ($A_YEAR)</h3>";
    echo "<table>
            <thead>
                <tr>
                    <th>FIRST_NAME</th>
                    <th>LAST_NAME</th>
                    <th>DESIGNATION</th>
                    <th>TYPE_OF_INDUSTRY</th>
                    <th>COMPANY</th>
                    <th>TYPE_INDIAN_FOREIGN</th>
                    <th>EMAIL</th>
                    <th>PHONE</th>
                </tr>
            </thead>
            <tbody>";

    $query = "SELECT e.FIRST_NAME, e.LAST_NAME, e.DESIGNATION, e.TYPE_OF_INDUSTRY, e.COMPANY, 
                 e.TYPE_INDIAN_FOREIGN, e.EMAIL_ID, e.PHONE
          FROM employers_details e
          LEFT JOIN department_master dm ON dm.DEPT_ID = e.DEPT_ID
          WHERE e.A_YEAR = ?
          AND e.DEPT_ID NOT IN (119,120,122,136,135)
          ORDER BY e.FIRST_NAME, e.LAST_NAME";

    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                echo "<tr>
                    <td>" . htmlspecialchars($row['FIRST_NAME'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['LAST_NAME'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['DESIGNATION'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['TYPE_OF_INDUSTRY'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['COMPANY'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['TYPE_INDIAN_FOREIGN'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['EMAIL_ID'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($row['PHONE'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
                </tr>";
            }
        }
        mysqli_stmt_close($stmt);
    }
    echo "</tbody></table>";
} else {
    echo "Invalid export type selected.";
}
?>
</body>
</html>
