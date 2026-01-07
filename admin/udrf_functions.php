<?php
/**
 * Admin UDRF Helper Functions
 * Copied from Chairman_login/functions.php
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
    
    // CRITICAL: Use centralized calculation function for consistency
    // This ensures all sections are calculated using the same logic across all views
    $dept_data = fetchAllDepartmentData($dept_id, $academic_year);
    $auto_scores = recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, true);
    
    // Note: All section scores and total are already calculated by recalculateAllSectionsFromData()
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
            if ($result) {
                mysqli_free_result($result);
            }
            $stmt->close();
            return $expert_email;
        }
        if ($result) {
            mysqli_free_result($result);
        }
        $stmt->close();
    }
    return null;
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

