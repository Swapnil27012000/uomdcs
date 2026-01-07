<?php
/**
 * Chairman Helper Functions
 */

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');

/**
 * Get all 6 categories with department counts
 */
function getAllCategoriesWithCounts($academic_year = null) {
    global $conn;
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
    
    // Get base scores
    $auto_scores = getDepartmentScores($dept_id, $academic_year);
    
    // Fetch department data for recalculation
    $dept_data = fetchAllDepartmentData($dept_id, $academic_year);
    
    // Recalculate Section II
    $sec2 = $dept_data['section_2'] ?? [];
    $section_2_recalc = 0;
    $nep_count = (int)($sec2['nep_count'] ?? 0);
    if ($nep_count == 0 && !empty($sec2['nep_initiatives'])) {
        $nep_initiatives = is_string($sec2['nep_initiatives']) ? json_decode($sec2['nep_initiatives'], true) : $sec2['nep_initiatives'];
        if (is_array($nep_initiatives)) {
            $nep_count = count($nep_initiatives);
        }
    }
    $section_2_recalc += min($nep_count * 2, 30);
    $ped_count = (int)($sec2['ped_count'] ?? 0);
    if ($ped_count == 0 && !empty($sec2['pedagogical'])) {
        $pedagogical = is_string($sec2['pedagogical']) ? json_decode($sec2['pedagogical'], true) : $sec2['pedagogical'];
        if (is_array($pedagogical)) {
            $ped_count = count($pedagogical);
        }
    }
    $section_2_recalc += min($ped_count * 2, 20);
    $assess_count = (int)($sec2['assess_count'] ?? 0);
    if ($assess_count == 0 && !empty($sec2['assessments'])) {
        $assessments = is_string($sec2['assessments']) ? json_decode($sec2['assessments'], true) : $sec2['assessments'];
        if (is_array($assessments)) {
            $assess_count = count($assessments);
        }
    }
    $section_2_recalc += min($assess_count * 2, 20);
    $moocs = (int)($sec2['moocs'] ?? 0);
    $section_2_recalc += min($moocs * 2, 10);
    $econtent = (float)($sec2['econtent'] ?? 0);
    $section_2_recalc += min($econtent, 15);
    $result_days = (int)($sec2['result_days'] ?? 0);
    if ($result_days > 0 && $result_days <= 30) {
        $section_2_recalc += 5;
    } elseif ($result_days > 30 && $result_days <= 45) {
        $section_2_recalc += 2.5;
    }
    // If result_days is 0 or > 45, no marks (already 0)
    $auto_scores['section_2'] = $section_2_recalc;
    
    // Recalculate Section III
    $sec3 = $dept_data['section_3'] ?? [];
    $section_3_recalc = 0;
    $inclusive_items = !empty($sec3['inclusive_practices']) ? (is_array($sec3['inclusive_practices']) ? $sec3['inclusive_practices'] : explode(',', $sec3['inclusive_practices'])) : [];
    $section_3_recalc += min(count($inclusive_items), 10);
    $green_items = !empty($sec3['green_practices']) ? (is_array($sec3['green_practices']) ? $sec3['green_practices'] : explode(',', $sec3['green_practices'])) : [];
    $section_3_recalc += min(count($green_items), 10);
    $section_3_recalc += min((int)($sec3['teachers_in_admin'] ?? 0), 5);
    $section_3_recalc += min((int)($sec3['awards_extension'] ?? 0) * 2, 10);
    $section_3_recalc += min((float)($sec3['alumni_contribution'] ?? 0) * 0.5, 5);
    $section_3_recalc += min((int)($sec3['isr_total'] ?? 0), 5);
    $section_3_recalc += min((float)($sec3['isr_budget_percent'] ?? 0) * 0.5, 5);
    $section_3_recalc += min((float)($sec3['isr_students_percent'] ?? 0) * 0.5, 5);
    $section_3_recalc += min((float)($sec3['isr_faculty_percent'] ?? 0) * 0.5, 5);
    $section_3_recalc += min((int)($sec3['sponsors_total'] ?? 0), 5);
    $section_3_recalc += min((float)($sec3['sponsors_amount'] ?? 0) * 0.1, 5);
    $section_3_recalc += min((int)($sec3['isr_volunteer_hours'] ?? 0) * 0.01, 5);
    $section_3_recalc += min((int)($sec3['isr_active_partnerships'] ?? 0), 5);
    $auto_scores['section_3'] = $section_3_recalc;
    
    // Recalculate Section IV
    $sec4 = $dept_data['section_4'] ?? [];
    $intake_data = $sec4['intake'] ?? [];
    $placement_data = $sec4['placement'] ?? [];
    $support_data = $sec4['support'] ?? [];
    $section_4_recalc = 0;
    $total_intake = (int)($intake_data['total_intake'] ?? 0);
    $total_enrolled = (int)($intake_data['total_enrolled'] ?? 0);
    $enrollment_percent = $total_intake > 0 ? ($total_enrolled / $total_intake) * 100 : 0;
    $section_4_recalc += min($enrollment_percent * 0.1, 10);
    $section_4_recalc += min((int)($support_data['research_fellows_count'] ?? 0), 10);
    $section_4_recalc += min((int)($intake_data['reserved_category_students'] ?? 0) * 0.1, 10);
    $section_4_recalc += min((int)($intake_data['scholarship_students'] ?? 0) * 0.1, 10);
    $outside_state = (int)($intake_data['outside_state_students'] ?? 0);
    $outside_country = (int)($intake_data['outside_country_students'] ?? 0);
    $section_4_recalc += min(($outside_state * 0.2) + ($outside_country * 0.5), 10);
    $section_4_recalc += min((int)($support_data['exec_dev_students'] ?? 0) * 0.5, 10);
    $section_4_recalc += min((int)($support_data['support_initiatives_count'] ?? 0), 10);
    $section_4_recalc += min((int)($support_data['internship_students'] ?? 0) * 0.1, 10);
    $appeared = (int)($intake_data['final_sem_appeared'] ?? 0);
    $passed = (int)($intake_data['final_sem_passed'] ?? 0);
    $pass_percent = $appeared > 0 ? ($passed / $appeared) * 100 : 0;
    $section_4_recalc += min($pass_percent * 0.1, 10);
    $section_4_recalc += min((int)($placement_data['total_placed'] ?? 0) * 0.1, 10);
    $section_4_recalc += min((int)($placement_data['total_qualifying_exams'] ?? 0), 10);
    $section_4_recalc += min((int)($placement_data['total_higher_studies'] ?? 0) * 0.5, 10);
    $section_4_recalc += min((int)($support_data['student_research_activities'] ?? 0), 10);
    $state_sports = (int)($support_data['awards_sports_state'] ?? 0);
    $national_sports = (int)($support_data['awards_sports_national'] ?? 0);
    $intl_sports = (int)($support_data['awards_sports_international'] ?? 0);
    $section_4_recalc += min(($state_sports * 1) + ($national_sports * 2) + ($intl_sports * 3), 10);
    $section_4_recalc += min((int)($support_data['awards_cultural_count'] ?? 0), 10);
    $section_4_recalc += min((int)($support_data['mooc_courses_adopted'] ?? 0), 10);
    $auto_scores['section_4'] = $section_4_recalc;
    
    // Recalculate Section V
    $sec5 = $dept_data['section_5'] ?? [];
    $conferences_data = $sec5['conferences'] ?? [];
    $collaborations_data = $sec5['collaborations'] ?? [];
    $section_5_recalc = 0;
    $section_5_recalc += min((int)($conferences_data['A1'] ?? 0) * 2, 10);
    $section_5_recalc += min((int)($conferences_data['A2'] ?? 0) * 2, 10);
    $section_5_recalc += min((int)($conferences_data['A3'] ?? 0) * 2, 10);
    $section_5_recalc += min((int)($conferences_data['A4'] ?? 0) * 3, 10);
    $section_5_recalc += min((int)($conferences_data['A5'] ?? 0), 5);
    $section_5_recalc += min((int)($conferences_data['A6'] ?? 0), 5);
    $section_5_recalc += min((int)($collaborations_data['B1'] ?? 0) * 2, 10);
    $section_5_recalc += min((int)($collaborations_data['B2'] ?? 0) * 2, 10);
    $section_5_recalc += min((int)($collaborations_data['B3'] ?? 0) * 2, 5);
    $section_5_recalc += min((int)($collaborations_data['B4'] ?? 0) * 2, 10);
    $section_5_recalc += min((int)($collaborations_data['B5'] ?? 0) * 2, 5);
    $auto_scores['section_5'] = $section_5_recalc;
    
    // Recalculate total
    $auto_scores['total'] = $auto_scores['section_1'] + $auto_scores['section_2'] + $auto_scores['section_3'] + $auto_scores['section_4'] + $auto_scores['section_5'];
    
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
        mysqli_free_result($result);
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
 * Get expert email for a category
 */
function getExpertForCategory($category) {
    global $conn;
    $stmt = $conn->prepare("SELECT expert_email FROM expert_categories WHERE category = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $category);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $expert_email = $row['expert_email'];
            mysqli_free_result($result);
            $stmt->close();
            return $expert_email;
        }
        mysqli_free_result($result);
        $stmt->close();
    }
    return null;
}

/**
 * Send chairman remark
 */
function sendChairmanRemark($dept_id, $remark_text, $academic_year = null, $remark_data = []) {
    global $conn;
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Get category for this department - try multiple approaches
    $category = null;
    
    // First try: direct match with dept_id as string
    $category_query = "SELECT category FROM department_profiles 
                       WHERE dept_id = ? AND A_YEAR = ? LIMIT 1";
    $stmt = $conn->prepare($category_query);
    if ($stmt) {
        $dept_id_str = (string)$dept_id;
        $stmt->bind_param("ss", $dept_id_str, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $category = $row['category'];
        }
        mysqli_free_result($result);
        $stmt->close();
    }
    
    // Second try: if not found, try with CAST
    if (!$category) {
        $category_query = "SELECT category FROM department_profiles 
                           WHERE CAST(dept_id AS UNSIGNED) = ? AND A_YEAR = ? LIMIT 1";
        $stmt = $conn->prepare($category_query);
        if ($stmt) {
            $stmt->bind_param("is", $dept_id, $academic_year);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $category = $row['category'];
            }
            $stmt->close();
        }
    }
    
    // Third try: get from department_master via brief_details
    if (!$category) {
        $category_query = "SELECT dp.category FROM department_profiles dp
                           INNER JOIN department_master dm ON CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
                           WHERE dm.DEPT_ID = ? AND dp.A_YEAR = ? LIMIT 1";
        $stmt = $conn->prepare($category_query);
        if ($stmt) {
            $stmt->bind_param("is", $dept_id, $academic_year);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $category = $row['category'];
            }
            $stmt->close();
        }
    }
    
    if (!$category) {
        return ['success' => false, 'message' => 'Category not found for department'];
    }
    
    // Get expert email for this category
    $expert_email = getExpertForCategory($category);
    if (!$expert_email) {
        return ['success' => false, 'message' => 'No expert assigned to this category'];
    }
    
    // Insert remark with priority and remark_type
    $remark_type = isset($remark_data['remark_type']) ? $remark_data['remark_type'] : 'general';
    $priority = isset($remark_data['priority']) ? $remark_data['priority'] : 'high';
    
    $insert_query = "INSERT INTO chairman_remarks 
                    (dept_id, academic_year, category, expert_email, remark_text, remark_type, priority, status, is_read)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'new', 0)";
    $stmt = $conn->prepare($insert_query);
    if ($stmt) {
        $stmt->bind_param("issssss", $dept_id, $academic_year, $category, $expert_email, $remark_text, $remark_type, $priority);
        if ($stmt->execute()) {
            $stmt->close();
            return ['success' => true, 'message' => 'Remark sent successfully'];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to save remark: ' . $conn->error];
    }
    return ['success' => false, 'message' => 'Failed to prepare statement'];
}

/**
 * Get unread remarks count for an expert
 */
function getUnreadRemarksCount($expert_email) {
    global $conn;
    $count = 0;
    
    // Check if table exists first
    $check = $conn->query("SHOW TABLES LIKE 'chairman_remarks'");
    if (!$check || $check->num_rows == 0) {
        return 0;
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM chairman_remarks 
        WHERE expert_email = ? AND is_read = 0 AND status != 'resolved'");
    if ($stmt) {
        $stmt->bind_param("s", $expert_email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $count = (int)$row['count'];
        }
        mysqli_free_result($result);
        $stmt->close();
    }
    return $count;
}

/**
 * Get all remarks for an expert
 */
function getExpertRemarks($expert_email, $status = null) {
    global $conn;
    $remarks = [];
    
    // Check if table exists first
    $check = $conn->query("SHOW TABLES LIKE 'chairman_remarks'");
    if (!$check || $check->num_rows == 0) {
        return [];
    }
    
    $query = "SELECT cr.*, 
              dm.DEPT_COLL_NO AS DEPT_CODE,
              COALESCE(dn.collname, dm.DEPT_NAME) AS DEPT_NAME
              FROM chairman_remarks cr
              INNER JOIN department_master dm ON cr.dept_id = dm.DEPT_ID
              LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
              WHERE cr.expert_email = ?";
    
    if ($status) {
        $query .= " AND cr.status = ?";
    }
    
    $query .= " ORDER BY cr.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        if ($status) {
            $stmt->bind_param("ss", $expert_email, $status);
        } else {
            $stmt->bind_param("s", $expert_email);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $remarks[] = $row;
        }
        mysqli_free_result($result);
        $stmt->close();
    }
    return $remarks;
}

/**
 * Get chairman remarks for a specific department
 */
function getChairmanRemarks($dept_id, $academic_year = null) {
    global $conn;
    $remarks = [];
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Check if table exists first
    $check = $conn->query("SHOW TABLES LIKE 'chairman_remarks'");
    if (!$check || $check->num_rows == 0) {
        return [];
    }
    
    $query = "SELECT cr.*, 
              dm.DEPT_COLL_NO AS DEPT_CODE,
              COALESCE(dn.collname, dm.DEPT_NAME) AS DEPT_NAME
              FROM chairman_remarks cr
              INNER JOIN department_master dm ON cr.dept_id = dm.DEPT_ID
              LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
              WHERE cr.dept_id = ? AND cr.academic_year = ?
              ORDER BY cr.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("is", $dept_id, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $remarks[] = $row;
        }
        mysqli_free_result($result);
        $stmt->close();
    }
    return $remarks;
}

/**
 * Get count of unread expert responses for chairman
 * Counts remarks that have expert responses but are not yet resolved
 * (New responses that chairman should check)
 */
function getUnreadExpertResponsesCount($academic_year = null) {
    global $conn;
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Check if table exists first
    $check = $conn->query("SHOW TABLES LIKE 'chairman_remarks'");
    if (!$check || $check->num_rows == 0) {
        return 0;
    }
    
    // Count remarks that have expert responses but are not resolved
    // This means there's a new response for chairman to check
    $query = "SELECT COUNT(*) as count FROM chairman_remarks 
              WHERE academic_year = ? 
              AND expert_response IS NOT NULL 
              AND expert_response != ''
              AND status != 'resolved'";
    
    $stmt = $conn->prepare($query);
    $count = 0;
    if ($stmt) {
        $stmt->bind_param("s", $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $count = (int)$row['count'];
        }
        mysqli_free_result($result);
        $stmt->close();
    }
    return $count;
}

/**
 * Get overall ranking of all departments across all categories
 * CRITICAL: Ranking is now based on expert scores, not dept auto scores
 */
function getAllDepartmentsOverallRanking($academic_year = null) {
    global $conn;
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
        mysqli_free_result($result);
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

