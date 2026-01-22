<?php
/**
 * Admin UDRF Helper Functions
 * Copied from Chairman_login/functions.php
 */

// CRITICAL: Ensure config.php is loaded and connection is available
if (!isset($GLOBALS['db_connection']) || !$GLOBALS['db_connection']) {
    require_once(__DIR__ . '/../config.php');
}

// CRITICAL: Ensure $conn is set from config.php
if (!isset($conn) || !$conn) {
    if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection']) {
        $conn = $GLOBALS['db_connection'];
    } else {
        require_once(__DIR__ . '/../config.php');
    }
}

// CRITICAL: Verify connection is alive
if (isset($conn) && $conn && !@mysqli_ping($conn)) {
    // Connection is dead - reconnect
    if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection']) {
        @mysqli_close($GLOBALS['db_connection']);
        unset($GLOBALS['db_connection']);
    }
    require_once(__DIR__ . '/../config.php');
    // Ensure $conn is set after reconnection
    if (!isset($conn) && isset($GLOBALS['db_connection'])) {
        $conn = $GLOBALS['db_connection'];
    }
}

// CRITICAL: Final check - if connection still not available, log error
if (!isset($conn) || !$conn) {
    error_log("CRITICAL: Database connection unavailable in admin/udrf_functions.php");
    die("Database connection error. Please contact administrator.");
}

require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');

/**
 * Get all 6 categories with department counts
 */
function getAllCategoriesWithCounts($academic_year = null) {
    global $conn;
    
    // CRITICAL: Check connection before use (Security Guide Section 12)
    if (!isset($conn) || !$conn) {
        error_log("Database connection unavailable in getAllCategoriesWithCounts - conn not set");
        return [];
    }
    
    // CRITICAL: Verify connection is alive
    if (!@mysqli_ping($conn)) {
        error_log("Database connection dead in getAllCategoriesWithCounts - attempting reconnect");
        // Try to reconnect
        if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection']) {
            @mysqli_close($GLOBALS['db_connection']);
            unset($GLOBALS['db_connection']);
        }
        require_once(__DIR__ . '/../config.php');
        if (isset($GLOBALS['db_connection'])) {
            $conn = $GLOBALS['db_connection'];
        } else {
            error_log("Failed to reconnect in getAllCategoriesWithCounts");
            return [];
        }
    }
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    $categories = [
        'Sciences and Technology',
        'Management, Institutions,   Sub-campus, Constituent or Conducted/ Model Colleges',
        'Languages',
        'Humanities, and Social Sciences, Commerce',
        'Interdisciplinary',
        'Centre of Studies, Centre of Excellence, Chairs'
    ];
    
    $result = [];
    foreach ($categories as $index => $category) {
        $count_query = "SELECT COUNT(DISTINCT dm.DEPT_ID) as count
                       FROM department_profiles dp
                       INNER JOIN department_master dm 
                         ON CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
                       WHERE dp.category = ? AND dp.A_YEAR = ?";
        $stmt = $conn->prepare($count_query);
        $count = 0;
        if ($stmt) {
            $stmt->bind_param("ss", $category, $academic_year);
            $stmt->execute();
            $result_set = $stmt->get_result();
            if ($row = $result_set->fetch_assoc()) {
                $count = (int)$row['count'];
            }
            if ($result_set) {
                mysqli_free_result($result_set);
            }
            $stmt->close();
        }
        
        $result[] = [
            'id' => $index + 1,
            'name' => $category,
            'count' => $count
        ];
    }
    
    return $result;
}

/**
 * Recalculate department total score from actual data (accurate calculation)
 */
function recalculateDepartmentTotalScore($dept_id, $academic_year) {
    require_once(__DIR__ . '/../Expert_comty_login/data_fetcher.php');
    require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
    
    // CRITICAL: Use the same calculation method as Consolidated_Score.php
    // Include section files to get accurate calculated values (same as department_review.php)
    $dept_data = fetchAllDepartmentData($dept_id, $academic_year);
    
    // Set variables needed by section files
    $email = '';
    $is_locked = false;
    $is_readonly = true;
    $is_department_view = false;
    $is_chairman_view = false;
    $expert_scores = [
        'section_1' => 0,
        'section_2' => 0,
        'section_3' => 0,
        'section_4' => 0,
        'section_5' => 0,
        'total' => 0
    ];
    $auto_scores = [
        'section_1' => 0,
        'section_2' => 0,
        'section_3' => 0,
        'section_4' => 0,
        'section_5' => 0,
        'total' => 0
    ];
    
    // Use output buffering to capture and discard HTML output from section files
    ob_start();
    
    // Include section files from Expert_comty_login directory
    include(__DIR__ . '/../Expert_comty_login/section1_faculty_output.php');
    if (isset($section_1_auto_total_calculated)) {
        $auto_scores['section_1'] = $section_1_auto_total_calculated;
    }
    
    include(__DIR__ . '/../Expert_comty_login/section2_nep_initiatives.php');
    // Section II doesn't set $section_2_auto_total, so calculate it using the function
    $sec2 = $dept_data['section_2'] ?? [];
    $auto_scores['section_2'] = calculateSection2FromArray($sec2);
    
    include(__DIR__ . '/../Expert_comty_login/section3_governance.php');
    if (isset($section_3_auto_total)) {
        $auto_scores['section_3'] = $section_3_auto_total;
    }
    
    include(__DIR__ . '/../Expert_comty_login/section4_student_support.php');
    if (isset($section_4_auto_total)) {
        $auto_scores['section_4'] = $section_4_auto_total;
    }
    
    include(__DIR__ . '/../Expert_comty_login/section5_conferences.php');
    if (isset($section_5_auto_total)) {
        $auto_scores['section_5'] = $section_5_auto_total;
    }
    
    // Discard all HTML output from section files
    ob_end_clean();
    
    // CRITICAL: Recalculate total after all sections are included
    $auto_scores['total'] = (float)$auto_scores['section_1'] + (float)$auto_scores['section_2'] + (float)$auto_scores['section_3'] + (float)$auto_scores['section_4'] + (float)$auto_scores['section_5'];
    
    error_log("[Admin UDRF recalculateDepartmentTotalScore] FINAL SCORES - S1: " . $auto_scores['section_1'] . ", S2: " . $auto_scores['section_2'] . ", S3: " . $auto_scores['section_3'] . ", S4: " . $auto_scores['section_4'] . ", S5: " . $auto_scores['section_5'] . ", TOTAL: " . $auto_scores['total']);
    
    return $auto_scores['total'];
}

/**
 * Get expert score for a department (with fallback to dept auto score)
 * CRITICAL: Ranking should be based on expert scores, not dept auto scores
 */
function getDepartmentScoreForRanking($dept_id, $category, $academic_year) {
    // Get expert for this category
    $expert_email = getExpertForCategory($category);
    
    if ($expert_email) {
        $expert_review = getExpertReview($expert_email, $dept_id, $academic_year);
        if ($expert_review && $expert_review['expert_total_score'] !== null) {
            // Use expert score if available
            return (float)$expert_review['expert_total_score'];
        }
    }
    
    // Fallback to dept auto score if no expert review exists
    return recalculateDepartmentTotalScore($dept_id, $academic_year);
}

/**
 * Get departments for a category with scores and ranks
 * CRITICAL: Ranking is now based on expert scores, not dept auto scores
 */
function getDepartmentsWithScores($category, $academic_year = null) {
    global $conn;
    
    // CRITICAL: Check connection before use (Security Guide Section 12)
    if (!isset($conn) || !$conn) {
        error_log("Database connection unavailable in getDepartmentsWithScores - conn not set");
        return [];
    }
    
    // CRITICAL: Verify connection is alive
    if (!@mysqli_ping($conn)) {
        error_log("Database connection dead in getDepartmentsWithScores - attempting reconnect");
        // Try to reconnect
        if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection']) {
            @mysqli_close($GLOBALS['db_connection']);
            unset($GLOBALS['db_connection']);
        }
        require_once(__DIR__ . '/../config.php');
        if (isset($GLOBALS['db_connection'])) {
            $conn = $GLOBALS['db_connection'];
        } else {
            error_log("Failed to reconnect in getDepartmentsWithScores");
            return [];
        }
    }
    
    // CRITICAL: Validate input (Security Guide Section 5)
    $category = trim($category ?? '');
    if (empty($category)) {
        error_log("Empty category in getDepartmentsWithScores");
        return [];
    }
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    $query = "SELECT DISTINCT 
                dm.DEPT_ID,
                dm.DEPT_COLL_NO AS DEPT_CODE,
                COALESCE(dn.collname, dm.DEPT_NAME) AS DEPT_NAME,
                dp.category AS CATEGORY
              FROM department_profiles dp
              INNER JOIN department_master dm 
                ON CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
              LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
              WHERE dp.category = ? AND dp.A_YEAR = ?
              ORDER BY COALESCE(dn.collname, dm.DEPT_NAME) ASC";
    
    $stmt = $conn->prepare($query);
    $departments = [];
    if ($stmt) {
        $stmt->bind_param("ss", $category, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            error_log("Query failed in getDepartmentsWithScores: " . $conn->error);
            $stmt->close();
            return [];
        }
        while ($row = $result->fetch_assoc()) {
            // Get score for ranking (expert score if available, else dept auto score)
            $row['total_score'] = getDepartmentScoreForRanking($row['DEPT_ID'], $category, $academic_year);
            
            // Also store dept auto score for display
            $row['dept_auto_score'] = recalculateDepartmentTotalScore($row['DEPT_ID'], $academic_year);
            
            // Get expert score if available
            $expert_email = getExpertForCategory($category);
            $expert_review = null;
            if ($expert_email) {
                $expert_review = getExpertReview($expert_email, $row['DEPT_ID'], $academic_year);
            }
            $row['expert_total_score'] = $expert_review ? (float)($expert_review['expert_total_score'] ?? null) : null;
            
            $departments[] = $row;
        }
        if ($result) {
            mysqli_free_result($result);
        }
        $stmt->close();
    }
    
    // Sort by expert_total_score first (if available), then by dept_auto_score as fallback
    // Safety: Ensure departments is an array before sorting
    if (!is_array($departments)) {
        return [];
    }
    
    try {
        usort($departments, function($a, $b) {
            // Safety: Ensure arrays exist and have required keys
            if (!is_array($a) || !is_array($b)) {
                return 0;
            }
            
            $score_a = isset($a['expert_total_score']) && $a['expert_total_score'] !== null 
                ? (float)$a['expert_total_score'] 
                : (float)($a['dept_auto_score'] ?? 0);
            $score_b = isset($b['expert_total_score']) && $b['expert_total_score'] !== null 
                ? (float)$b['expert_total_score'] 
                : (float)($b['dept_auto_score'] ?? 0);
            
            // Prioritize departments with expert scores
            $a_has_expert = isset($a['expert_total_score']) && $a['expert_total_score'] !== null;
            $b_has_expert = isset($b['expert_total_score']) && $b['expert_total_score'] !== null;
            
            if ($a_has_expert && !$b_has_expert) {
                return -1; // a comes first
            }
            if (!$a_has_expert && $b_has_expert) {
                return 1; // b comes first
            }
            // If both have expert scores or both don't, sort by score descending
            return $score_b <=> $score_a;
        });
        
        $rank = 1;
        foreach ($departments as &$dept) {
            if (is_array($dept)) {
                $dept['rank'] = $rank++;
            }
        }
        unset($dept); // Important: unset reference after loop
    } catch (Exception $e) {
        error_log("Error sorting departments: " . $e->getMessage());
        // Return unsorted array if sorting fails
    }
    
    return $departments;
}

/**
 * Get all expert emails for a category (returns array of expert emails)
 */
function getAllExpertsForCategory($category) {
    global $conn;
    
    // CRITICAL: Check connection before use (Security Guide Section 12)
    if (!isset($conn) || !$conn) {
        error_log("Database connection unavailable in getAllExpertsForCategory - conn not set");
        return [];
    }
    
    // CRITICAL: Verify connection is alive
    if (!@mysqli_ping($conn)) {
        error_log("Database connection dead in getAllExpertsForCategory - attempting reconnect");
        // Try to reconnect
        if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection']) {
            @mysqli_close($GLOBALS['db_connection']);
            unset($GLOBALS['db_connection']);
        }
        require_once(__DIR__ . '/../config.php');
        if (isset($GLOBALS['db_connection'])) {
            $conn = $GLOBALS['db_connection'];
        } else {
            error_log("Failed to reconnect in getAllExpertsForCategory");
            return [];
        }
    }
    
    // CRITICAL: Validate input (Security Guide Section 5)
    $category = trim($category ?? '');
    if (empty($category)) {
        error_log("Empty category in getAllExpertsForCategory");
        return [];
    }
    
    $experts = [];
    $stmt = $conn->prepare("SELECT expert_email FROM expert_categories WHERE category = ? ORDER BY expert_email ASC");
    if ($stmt) {
        $stmt->bind_param("s", $category);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $experts[] = $row['expert_email'];
        }
        if ($result) {
            mysqli_free_result($result);
        }
        $stmt->close();
    }
    return $experts;
}

/**
 * Get expert email for a category (returns first expert for backward compatibility)
 */
function getExpertForCategory($category) {
    $experts = getAllExpertsForCategory($category);
    return !empty($experts) ? $experts[0] : null;
}

/**
 * Get overall ranking of all departments across all categories
 * CRITICAL: Ranking is now based on expert scores, not dept auto scores
 */
function getAllDepartmentsOverallRanking($academic_year = null) {
    global $conn;
    
    // CRITICAL: Check connection before use (Security Guide Section 12)
    if (!isset($conn) || !$conn) {
        error_log("Database connection unavailable in getAllDepartmentsOverallRanking - conn not set");
        return [];
    }
    
    // CRITICAL: Verify connection is alive
    if (!@mysqli_ping($conn)) {
        error_log("Database connection dead in getAllDepartmentsOverallRanking - attempting reconnect");
        // Try to reconnect
        if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection']) {
            @mysqli_close($GLOBALS['db_connection']);
            unset($GLOBALS['db_connection']);
        }
        require_once(__DIR__ . '/../config.php');
        if (isset($GLOBALS['db_connection'])) {
            $conn = $GLOBALS['db_connection'];
        } else {
            error_log("Failed to reconnect in getAllDepartmentsOverallRanking");
            return [];
        }
    }
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    $query = "SELECT DISTINCT 
                dm.DEPT_ID,
                dm.DEPT_COLL_NO AS DEPT_CODE,
                COALESCE(dn.collname, dm.DEPT_NAME) AS DEPT_NAME,
                dp.category AS CATEGORY
              FROM department_profiles dp
              INNER JOIN department_master dm 
                ON CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
              LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
              WHERE dp.A_YEAR = ?
              ORDER BY COALESCE(dn.collname, dm.DEPT_NAME) ASC";
    
    $stmt = $conn->prepare($query);
    $departments = [];
    if ($stmt) {
        $stmt->bind_param("s", $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Get score for ranking (expert score if available, else dept auto score)
            $row['total_score'] = getDepartmentScoreForRanking($row['DEPT_ID'], $row['CATEGORY'], $academic_year);
            
            // Also store dept auto score for display
            $row['dept_auto_score'] = recalculateDepartmentTotalScore($row['DEPT_ID'], $academic_year);
            
            // Get expert score if available
            $expert_email = getExpertForCategory($row['CATEGORY']);
            $expert_review = null;
            if ($expert_email) {
                $expert_review = getExpertReview($expert_email, $row['DEPT_ID'], $academic_year);
            }
            $row['expert_total_score'] = $expert_review ? (float)($expert_review['expert_total_score'] ?? null) : null;
            
            $departments[] = $row;
        }
        if ($result) {
            mysqli_free_result($result);
        }
        $stmt->close();
    }
    
    // Sort by expert_total_score first (if available), then by dept_auto_score as fallback
    // Safety: Ensure departments is an array before sorting
    if (!is_array($departments)) {
        return [];
    }
    
    try {
        usort($departments, function($a, $b) {
            // Safety: Ensure arrays exist and have required keys
            if (!is_array($a) || !is_array($b)) {
                return 0;
            }
            
            $score_a = isset($a['expert_total_score']) && $a['expert_total_score'] !== null 
                ? (float)$a['expert_total_score'] 
                : (float)($a['dept_auto_score'] ?? 0);
            $score_b = isset($b['expert_total_score']) && $b['expert_total_score'] !== null 
                ? (float)$b['expert_total_score'] 
                : (float)($b['dept_auto_score'] ?? 0);
            
            // Prioritize departments with expert scores
            $a_has_expert = isset($a['expert_total_score']) && $a['expert_total_score'] !== null;
            $b_has_expert = isset($b['expert_total_score']) && $b['expert_total_score'] !== null;
            
            if ($a_has_expert && !$b_has_expert) {
                return -1; // a comes first
            }
            if (!$a_has_expert && $b_has_expert) {
                return 1; // b comes first
            }
            // If both have expert scores or both don't, sort by score descending
            return $score_b <=> $score_a;
        });
        
        $rank = 1;
        foreach ($departments as &$dept) {
            if (is_array($dept)) {
                $dept['overall_rank'] = $rank++;
            }
        }
        unset($dept); // Important: unset reference after loop
    } catch (Exception $e) {
        error_log("Error sorting overall departments: " . $e->getMessage());
        // Return unsorted array if sorting fails
    }
    
    return $departments;
}

