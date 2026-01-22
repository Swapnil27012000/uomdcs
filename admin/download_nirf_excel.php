<?php
require('session.php');
error_reporting(0);

// Academic year logic
if (file_exists(__DIR__ . '/../dept_login/common_functions.php')) {
    require_once(__DIR__ . '/../dept_login/common_functions.php');
}
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    $current_year = (int) date('Y');
    $current_month = (int) date('n');
    $A_YEAR = ($current_month >= 7)
        ? $current_year . '-' . ($current_year + 1)
        : ($current_year - 2) . '-' . ($current_year - 1);
}

// Database Connection
require_once(__DIR__ . '/../config.php');

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="NIRF_Data_' . $A_YEAR . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

echo '<?xml version="1.0"?>';
echo '<?mso-application progid="Excel.Sheet"?>';
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Bottom"/>
   <Borders/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#000000"/>
   <Interior/>
   <NumberFormat/>
   <Protection/>
  </Style>
  <Style ss:ID="Header">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#000000" ss:Bold="1"/>
   <Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/>
  </Style>
 </Styles>

 <?php
 // --- Sheet 1: I. Sanctioned(Approved) Intake ---
 echo '<Worksheet ss:Name="I. Sanctioned Intake">';
 echo '<Table>';
 // Header Row
 echo '<Row>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Programme Type</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Intake Capacity (All Years Total) ' . htmlspecialchars($A_YEAR) . '</Data></Cell>';
 echo '</Row>';

 $query = "SELECT a.programme_type, 
      SUM(a.year_1_capacity) as total_capacity
  FROM programmes a 
      LEFT JOIN department_master dm ON dm.DEPT_ID = a.DEPT_ID
      WHERE a.A_YEAR = ? 
      AND a.DEPT_ID NOT IN (119,120,122,136,135)
      AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
      GROUP BY a.programme_type
      ORDER BY a.programme_type";
 $stmt = mysqli_prepare($conn, $query);
 if ($stmt) {
     mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
     if (mysqli_stmt_execute($stmt)) {
         $result = mysqli_stmt_get_result($stmt);
         while ($row = mysqli_fetch_assoc($result)) {
             echo '<Row>';
             echo '<Cell><Data ss:Type="String">' . htmlspecialchars($row['programme_type']) . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['total_capacity'] . '</Data></Cell>';
             echo '</Row>';
         }
     }
     mysqli_stmt_close($stmt);
 }
 echo '</Table>';
 echo '</Worksheet>';

 // --- Sheet 2: II. Actual Enrollment ---
 echo '<Worksheet ss:Name="II. Actual Enrollment">';
 echo '<Table>';
 echo '<Row>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Programme Type</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Male Students</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Female Students</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Total Students</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Male Within State</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Female Within State</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Male Outside State</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Female Outside State</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Male Outside Country</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Female Outside Country</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Econamic Backward</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Social Backward</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Govt Scholarship</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Inst Scholarship</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Pvt Scholarship</Data></Cell>';
 echo '</Row>';

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
 AND a.DEPT_ID NOT IN (119,120,122,136)
 AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
 GROUP BY b.programme_type
 ORDER BY b.programme_type";

 $stmt = mysqli_prepare($conn, $query);
 if ($stmt) {
     mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
     if (mysqli_stmt_execute($stmt)) {
         $result = mysqli_stmt_get_result($stmt);
         while ($row = mysqli_fetch_assoc($result)) {
             echo '<Row>';
             echo '<Cell><Data ss:Type="String">' . htmlspecialchars($row['programme_type']) . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Total_number_of_Male_Students'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Total_number_of_Female_Students'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['total'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Male_within_state'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Female_within_state'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Male_outside_state'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Female_outside_state'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Male_outside_country'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Female_outside_country'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['eb'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['sb'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Govt_Scholarship'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Inst_Scholarship'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['Prvt_Scholarship'] . '</Data></Cell>';
             echo '</Row>';
         }
     }
     mysqli_stmt_close($stmt);
 }
 echo '</Table>';
 echo '</Worksheet>';

 // --- Sheet 3: III. Placement & Higher Studies ---
 echo '<Worksheet ss:Name="III. Placement">';
 echo '<Table>';
 echo '<Row>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Programme Type</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Total Students</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Lateral Entry</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Graduated</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Placed</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Higher Studies</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Qualifying Exams</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Median Salary</Data></Cell>';
 echo '</Row>';

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
         AND sd.DEPT_ID NOT IN (119,120,122,136)
         AND (dm_sd.DEPT_COLL_NO IS NULL OR dm_sd.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
     ) t
     WHERE rn IN (
         FLOOR((total_rows + 1) / 2),
         FLOOR((total_rows + 2) / 2)
     )
     GROUP BY t.programme_type
 ) ms ON ms.programme_type = p.programme_type
 WHERE p.A_YEAR = ?
     AND pd.DEPT_ID NOT IN (119,120,122,136)
     AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
 GROUP BY p.A_YEAR, p.programme_type";

 $stmt = mysqli_prepare($conn, $query);
 if ($stmt) {
     mysqli_stmt_bind_param($stmt, 'ss', $A_YEAR, $A_YEAR);
     if (mysqli_stmt_execute($stmt)) {
         $result = mysqli_stmt_get_result($stmt);
         while ($row = mysqli_fetch_assoc($result)) {
             $placed = (int) ($row['TOTAL_NUM_OF_STUDENTS_PLACED'] ?? 0);
             $median_salary_raw = $row['Median_salary'] ?? null;
             if ($placed > 0 && $median_salary_raw !== null && (double) $median_salary_raw > 0) {
                 $median_salary = number_format((double) $median_salary_raw, 0, '.', '');
             } else {
                 $median_salary = 0;
             }
             
             echo '<Row>';
             echo '<Cell><Data ss:Type="String">' . htmlspecialchars($row['programme_type']) . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['TOTAL_NO_OF_STUDENT'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['TOTAL_NUM_OF_STUDENTS_GRADUATED'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $placed . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['NUM_OF_STUDENTS_IN_HIGHER_STUDIES'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['STUDENTS_QUALIFYING_EXAMS'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $median_salary . '</Data></Cell>';
             echo '</Row>';
         }
     }
     mysqli_stmt_close($stmt);
 }
 echo '</Table>';
 echo '</Worksheet>';

 // --- Sheet 4: IV. Ph.D Student Details ---
 echo '<Worksheet ss:Name="IV. Ph.D Details">';
 echo '<Table>';
 echo '<Row>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Type</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Full Time</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Part Time</Data></Cell>';
 echo '</Row>';
 
 $query = "SELECT 
     SUM(FULL_TIME_MALE_STUDENTS + FULL_TIME_FEMALE_STUDENTS) as full_time,
     SUM(PART_TIME_MALE_STUDENTS + PART_TIME_FEMALE_STUDENTS) as part_time
 FROM phd_details pd
 LEFT JOIN department_master dm ON dm.DEPT_ID = pd.DEPT_ID
 WHERE pd.A_YEAR = ?
 AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
 GROUP BY pd.A_YEAR";
 $stmt = mysqli_prepare($conn, $query);
 if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<Row>';
            echo '<Cell><Data ss:Type="String">Total Ph.D Students</Data></Cell>';
            echo '<Cell><Data ss:Type="Number">' . $row['full_time'] . '</Data></Cell>';
            echo '<Cell><Data ss:Type="Number">' . $row['part_time'] . '</Data></Cell>';
            echo '</Row>';
        }
    }
    mysqli_stmt_close($stmt);
 }
 echo '</Table>';
 echo '</Worksheet>';

 // --- Sheet 5: VI. Earings from Patents ---
 echo '<Worksheet ss:Name="VI. Patents">';
 echo '<Table>';
 echo '<Row>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Patents Published</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Patents Granted</Data></Cell>';
 echo '</Row>';

 $query = "SELECT 
     SUM(patents_published_2024) as current_published, 
     SUM(patents_granted_2024) as current_granted
 FROM faculty_output fo
 LEFT JOIN department_master dm ON dm.DEPT_ID = fo.DEPT_ID
 WHERE fo.A_YEAR = ?
 AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
 GROUP BY fo.A_YEAR";
 $stmt = mysqli_prepare($conn, $query);
 if ($stmt) {
     mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
     if (mysqli_stmt_execute($stmt)) {
         $result = mysqli_stmt_get_result($stmt);
         while ($row = mysqli_fetch_assoc($result)) {
             echo '<Row>';
             echo '<Cell><Data ss:Type="Number">' . $row['current_published'] . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $row['current_granted'] . '</Data></Cell>';
             echo '</Row>';
         }
     }
     mysqli_stmt_close($stmt);
 }
 echo '</Table>';
 echo '</Worksheet>';

 // --- Sheet 6: VII. Sponsored Research ---
 echo '<Worksheet ss:Name="VII. Sponsored Research">';
 echo '<Table>';
 echo '<Row>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Department Name</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">No. of Projects (Agencies)</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Amount from Agencies</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">No. of Projects (Industries)</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Amount from Industries</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Total Projects</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Total Amount</Data></Cell>';
 echo '</Row>';

 $sponsored_query = "SELECT 
     fo.A_YEAR, fo.DEPT_ID, fo.projects, fo.sponsored_projects_total,
     fo.sponsored_projects_agencies, fo.sponsored_amount_agencies,
     fo.sponsored_projects_industries, fo.sponsored_amount_industries,
     dm.DEPT_NAME
 FROM faculty_output fo
 LEFT JOIN department_master dm ON dm.DEPT_ID = fo.DEPT_ID
 WHERE fo.A_YEAR = ?
 AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
 ORDER BY dm.DEPT_NAME";
 
 $stmt = mysqli_prepare($conn, $sponsored_query);
 if ($stmt) {
     mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
     mysqli_stmt_execute($stmt);
     $sponsored_result = mysqli_stmt_get_result($stmt);
     while ($row = mysqli_fetch_assoc($sponsored_result)) {
         $dept_total = 0; $dept_agencies = 0; $dept_amount_agencies = 0.0;
         $dept_industries = 0; $dept_amount_industries = 0.0;
         
         if (!empty($row['projects'])) {
             $projects_data = json_decode($row['projects'], true);
             if (is_array($projects_data)) {
                 foreach ($projects_data as $project) {
                     $type = trim($project['type'] ?? '');
                     $amount = (double)($project['amount'] ?? 0.0);
                     if ($amount > 0 && $amount < 1000) $amount *= 100000.0;
                     if ($type === 'Govt-Sponsored') {
                         $dept_agencies++; $dept_amount_agencies += $amount; $dept_total++;
                     } elseif ($type === 'Non-Govt-Sponsored') {
                         $dept_industries++; $dept_amount_industries += $amount; $dept_total++;
                     }
                 }
             }
         }
         
         if ($dept_total == 0) {
             $dept_total = (int)($row['sponsored_projects_total'] ?? 0);
             $dept_agencies = (int)($row['sponsored_projects_agencies'] ?? 0);
             $dept_amount_agencies = (double)($row['sponsored_amount_agencies'] ?? 0.0);
             $dept_industries = (int)($row['sponsored_projects_industries'] ?? 0);
             $dept_amount_industries = (double)($row['sponsored_amount_industries'] ?? 0.0);
         }
         
         if ($dept_total > 0 || $dept_amount_agencies > 0 || $dept_amount_industries > 0) {
             $total_amount = $dept_amount_agencies + $dept_amount_industries;
             echo '<Row>';
             echo '<Cell><Data ss:Type="String">' . htmlspecialchars($row['DEPT_NAME']) . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $dept_agencies . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $dept_amount_agencies . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $dept_industries . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $dept_amount_industries . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $dept_total . '</Data></Cell>';
             echo '<Cell><Data ss:Type="Number">' . $total_amount . '</Data></Cell>';
             echo '</Row>';
         }
     }
     mysqli_stmt_close($stmt);
 }
 echo '</Table>';
 echo '</Worksheet>';

 // --- Sheet 7: VIII. Consultancy ---
 echo '<Worksheet ss:Name="VIII. Consultancy">';
 echo '<Table>';
 echo '<Row>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Department Name</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">No. of Projects</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">No. of Clients</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Total Amount</Data></Cell>';
 echo '</Row>';

 $consultancy_data = [];
 $consultancy_query = "SELECT cp.A_YEAR, cp.DEPT_ID, cp.TOTAL_NO_OF_CP, cp.TOTAL_NO_OF_CLIENT, cp.TOTAL_AMT_RECEIVED, dm.DEPT_NAME
 FROM consultancy_projects cp
 LEFT JOIN department_master dm ON dm.DEPT_ID = cp.DEPT_ID
 WHERE cp.A_YEAR = ?
 AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
 ORDER BY dm.DEPT_NAME";
 $stmt = mysqli_prepare($conn, $consultancy_query);
 if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $dept_id = $row['DEPT_ID'];
        if (!isset($consultancy_data[$dept_id])) {
            $consultancy_data[$dept_id] = [
                'dept_name' => $row['DEPT_NAME'],
                'projects' => 0, 'clients' => 0, 'amount' => 0.0
            ];
        }
        $consultancy_data[$dept_id]['projects'] += (int) $row['TOTAL_NO_OF_CP'];
        $consultancy_data[$dept_id]['clients'] += (int) $row['TOTAL_NO_OF_CLIENT'];
        $consultancy_data[$dept_id]['amount'] += (double) $row['TOTAL_AMT_RECEIVED'];
    }
    mysqli_stmt_close($stmt);
 }
 
 // JSON part
 $consultancy_json_query = "SELECT fo.DEPT_ID, fo.projects, dm.DEPT_NAME
 FROM faculty_output fo
 LEFT JOIN department_master dm ON dm.DEPT_ID = fo.DEPT_ID
 WHERE fo.A_YEAR = ? 
 AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
 AND fo.projects IS NOT NULL AND fo.projects != '' AND fo.projects != '[]'";
 $stmt = mysqli_prepare($conn, $consultancy_json_query);
 if ($stmt) {
     mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
     mysqli_stmt_execute($stmt);
     $result = mysqli_stmt_get_result($stmt);
     while ($row = mysqli_fetch_assoc($result)) {
         $dept_id = $row['DEPT_ID'];
         $projects = json_decode($row['projects'], true);
         if (is_array($projects)) {
             if (!isset($consultancy_data[$dept_id])) {
                 $consultancy_data[$dept_id] = [
                    'dept_name' => $row['DEPT_NAME'],
                    'projects' => 0, 'clients' => 0, 'amount' => 0.0
                 ];
             }
             $unique_clients = [];
             foreach ($projects as $project) {
                 if (trim($project['type'] ?? '') === 'Consultancy') {
                     $consultancy_data[$dept_id]['projects']++;
                     $agency = trim($project['agency'] ?? '');
                     if (!empty($agency) && !in_array($agency, $unique_clients)) {
                         $unique_clients[] = $agency;
                     }
                     $amount = (double)($project['amount'] ?? 0.0);
                     if ($amount > 0 && $amount < 1000) $amount *= 100000.0;
                     $consultancy_data[$dept_id]['amount'] += $amount;
                 }
             }
             $consultancy_data[$dept_id]['clients'] = max($consultancy_data[$dept_id]['clients'], count($unique_clients));
         }
     }
     mysqli_stmt_close($stmt);
 }
 
 foreach ($consultancy_data as $data) {
     if ($data['projects'] > 0 || $data['amount'] > 0) {
         echo '<Row>';
         echo '<Cell><Data ss:Type="String">' . htmlspecialchars($data['dept_name']) . '</Data></Cell>';
         echo '<Cell><Data ss:Type="Number">' . $data['projects'] . '</Data></Cell>';
         echo '<Cell><Data ss:Type="Number">' . $data['clients'] . '</Data></Cell>';
         echo '<Cell><Data ss:Type="Number">' . $data['amount'] . '</Data></Cell>';
         echo '</Row>';
     }
 }
 echo '</Table>';
 echo '</Worksheet>';

 // --- Sheet 8: IX. Executive Development ---
 echo '<Worksheet ss:Name="IX. Executive Development">';
 echo '<Table>';
 echo '<Row>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Financial Year</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Total Programs</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Total Participants</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Total Earnings</Data></Cell>';
 echo '</Row>';

 $exec_query = "SELECT ed.A_YEAR,
      SUM(ed.NO_OF_EXEC_PROGRAMS) AS total_programs,
      SUM(ed.TOTAL_PARTICIPANTS) AS total_participants,
      SUM(CASE WHEN ed.TOTAL_INCOME > 0 AND ed.TOTAL_INCOME < 1000 THEN CAST(ed.TOTAL_INCOME AS DOUBLE) * 100000.0 ELSE CAST(ed.TOTAL_INCOME AS DOUBLE) END) AS total_income_rupees
 FROM exec_dev ed
 LEFT JOIN department_master dm ON dm.DEPT_ID = ed.DEPT_ID
 WHERE ed.A_YEAR = ?
 AND ed.DEPT_ID NOT IN (119, 120, 122, 136)
 AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
 GROUP BY ed.A_YEAR";
 $stmt = mysqli_prepare($conn, $exec_query);
 if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $A_YEAR);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<Row>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($A_YEAR) . '</Data></Cell>';
            echo '<Cell><Data ss:Type="Number">' . $row['total_programs'] . '</Data></Cell>';
            echo '<Cell><Data ss:Type="Number">' . $row['total_participants'] . '</Data></Cell>';
            echo '<Cell><Data ss:Type="Number">' . $row['total_income_rupees'] . '</Data></Cell>';
            echo '</Row>';
        }
    }
    mysqli_stmt_close($stmt);
 }
 echo '</Table>';
 echo '</Worksheet>';

 // --- Sheet 9: X. Online Education ---
 echo '<Worksheet ss:Name="X. Online Education">';
 echo '<Table>';
 echo '<Row>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Department Name</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Course Title</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Platform</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">No. of Students</Data></Cell>';
 echo '<Cell ss:StyleID="Header"><Data ss:Type="String">Credits Transferred</Data></Cell>';
 echo '</Row>';
 
 $year_start = (int) explode('-', $A_YEAR)[0];
 $online_query = "SELECT nm.mooc_data, nm.DEPT_ID, dm.DEPT_NAME
 FROM nepmarks nm
 LEFT JOIN department_master dm ON dm.DEPT_ID = nm.DEPT_ID
 WHERE nm.A_YEAR = ?
 AND (dm.DEPT_COLL_NO IS NULL OR dm.DEPT_COLL_NO NOT IN (9998, 9999956, 99999, 9997, 9995))
 AND (nm.mooc_data IS NOT NULL AND nm.mooc_data != '' AND nm.mooc_data != '[]')
 ORDER BY dm.DEPT_NAME";
 $stmt = mysqli_prepare($conn, $online_query);
 if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $year_start);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        if (!empty($row['mooc_data'])) {
            $mooc_data = json_decode($row['mooc_data'], true);
            if (is_array($mooc_data)) {
                foreach ($mooc_data as $mooc) {
                    if (is_array($mooc)) {
                         $students = (int) ($mooc['students'] ?? 0);
                         $credits = (int) ($mooc['credits'] ?? 0);
                         $platform = trim($mooc['platform'] ?? '');
                         $title = trim($mooc['title'] ?? '');
                         if (!empty($platform) || !empty($title) || $students > 0 || $credits > 0) {
                             echo '<Row>';
                             echo '<Cell><Data ss:Type="String">' . htmlspecialchars($row['DEPT_NAME']) . '</Data></Cell>';
                             echo '<Cell><Data ss:Type="String">' . htmlspecialchars($title) . '</Data></Cell>';
                             echo '<Cell><Data ss:Type="String">' . htmlspecialchars($platform) . '</Data></Cell>';
                             echo '<Cell><Data ss:Type="Number">' . $students . '</Data></Cell>';
                             echo '<Cell><Data ss:Type="Number">' . $credits . '</Data></Cell>';
                             echo '</Row>';
                         }
                    }
                }
            }
        }
    }
    mysqli_stmt_close($stmt);
 }
 echo '</Table>';
 echo '</Worksheet>';
 ?>
</Workbook>
