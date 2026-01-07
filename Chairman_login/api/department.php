<?php
/**
 * Chairman Review Console - Step 3: Department Detail (Read-Only)
 * Complete rewrite to match expert review_complete.php UI exactly
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start output buffering
if (ob_get_level() === 0) {
    ob_start();
}

try {
    require('session.php');
    require('functions.php');
    require('../Expert_comty_login/data_fetcher.php');
    // expert_functions.php is already included via functions.php
} catch (Exception $e) {
    ob_end_clean();
    die("Error loading page: " . $e->getMessage() . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine());
} catch (Error $e) {
    ob_end_clean();
    die("Fatal error: " . $e->getMessage() . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine());
}

$dept_id_raw = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$dept_id = resolveDepartmentId($dept_id_raw);
$cat_id = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$category_name = isset($_GET['name']) ? urldecode($_GET['name']) : '';

if (!$dept_id) {
    die("Valid Department ID is required");
}

$academic_year = getAcademicYear();

// Fetch department info
$dept_query = "SELECT 
        COALESCE(dn.collname, dm.DEPT_NAME) AS dept_name,
        dm.DEPT_COLL_NO AS dept_code,
        COALESCE(dp.category, bd.TYPE) AS category_label
    FROM department_master dm
    LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
    LEFT JOIN department_profiles dp ON dp.A_YEAR = ? 
        AND CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
    LEFT JOIN brief_details_of_the_department bd ON bd.DEPT_ID = dm.DEPT_ID AND bd.A_YEAR = ?
    WHERE dm.DEPT_ID = ?
    LIMIT 1";
$dept_stmt = $conn->prepare($dept_query);
$dept_name = 'Unknown';
$dept_code = '';
if ($dept_stmt) {
    $dept_stmt->bind_param("ssi", $academic_year, $academic_year, $dept_id);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    if ($dept_row = $dept_result->fetch_assoc()) {
        $dept_name = $dept_row['dept_name'] ?? $dept_name;
        $dept_code = $dept_row['dept_code'] ?? '';
    }
    $dept_stmt->close();
}

// Fetch ALL department data
$dept_data = fetchAllDepartmentData($dept_id, $academic_year);
$documents = fetchAllSupportingDocuments($dept_id, $academic_year);
$grouped_docs = groupDocumentsBySection($documents);

// Get department scores
$auto_scores = getDepartmentScores($dept_id, $academic_year);

// Recalculate Section III total from actual data (more accurate than database value)
$sec3 = $dept_data['section_3'] ?? [];
$section_3_recalc = 0;
// Item 1: Inclusive Practices
$inclusive_items = !empty($sec3['inclusive_practices']) ? (is_array($sec3['inclusive_practices']) ? $sec3['inclusive_practices'] : explode(',', $sec3['inclusive_practices'])) : [];
$section_3_recalc += min(count($inclusive_items), 10);
// Item 2: Green Practices
$green_items = !empty($sec3['green_practices']) ? (is_array($sec3['green_practices']) ? $sec3['green_practices'] : explode(',', $sec3['green_practices'])) : [];
$section_3_recalc += min(count($green_items), 10);
// Item 3: Admin Involvement
$section_3_recalc += min((int)($sec3['teachers_in_admin'] ?? 0), 5);
// Item 4: Extension Awards
$section_3_recalc += min((int)($sec3['awards_extension'] ?? 0) * 2, 10);
// Item 5: Budget (narrative, 0)
$section_3_recalc += 0;
// Item 6: Alumni Funding
$section_3_recalc += min((float)($sec3['alumni_contribution'] ?? 0) * 0.5, 5);
// Items 7-12: Narrative (0 each)
$section_3_recalc += 0; // 7
$section_3_recalc += 0; // 8
$section_3_recalc += 0; // 9
$section_3_recalc += 0; // 10
$section_3_recalc += 0; // 11
$section_3_recalc += 0; // 12
// Item 13: ISR Initiatives
$section_3_recalc += min((int)($sec3['isr_total'] ?? 0), 5);
// Item 14: ISR Budget %
$section_3_recalc += min((float)($sec3['isr_budget_percent'] ?? 0) * 0.5, 5);
// Item 15: ISR Students %
$section_3_recalc += min((float)($sec3['isr_students_percent'] ?? 0) * 0.5, 5);
// Item 16: ISR Faculty %
$section_3_recalc += min((float)($sec3['isr_faculty_percent'] ?? 0) * 0.5, 5);
// Item 17: ISR Sponsors
$section_3_recalc += min((int)($sec3['sponsors_total'] ?? 0), 5);
// Item 18: ISR Sponsor Amount
$section_3_recalc += min((float)($sec3['sponsors_amount'] ?? 0) * 0.1, 5);
// Item 19: Volunteer Hours
$section_3_recalc += min((int)($sec3['isr_volunteer_hours'] ?? 0) * 0.01, 5);
// Item 20: ISR Partnerships
$section_3_recalc += min((int)($sec3['isr_active_partnerships'] ?? 0), 5);
// Items 21-22: Narrative (0 each)
$section_3_recalc += 0; // 21
$section_3_recalc += 0; // 22
// Update auto_scores with recalculated value
$auto_scores['section_3'] = $section_3_recalc;

// Recalculate Section II total from actual data (more accurate than database value)
$sec2 = $dept_data['section_2'] ?? [];
$section_2_recalc = 0;
// Item 1: NEP Initiatives (2 marks each, max 30)
$nep_count = (int)($sec2['nep_count'] ?? 0);
// If nep_count is 0, try to count from JSON array
if ($nep_count == 0 && !empty($sec2['nep_initiatives'])) {
    $nep_initiatives = is_string($sec2['nep_initiatives']) ? json_decode($sec2['nep_initiatives'], true) : $sec2['nep_initiatives'];
    if (is_array($nep_initiatives)) {
        $nep_count = count($nep_initiatives);
    }
}
$section_2_recalc += min($nep_count * 2, 30);
// Item 2: Pedagogical Approaches (2 marks each, max 20)
$ped_count = (int)($sec2['ped_count'] ?? 0);
if ($ped_count == 0 && !empty($sec2['pedagogical'])) {
    $pedagogical = is_string($sec2['pedagogical']) ? json_decode($sec2['pedagogical'], true) : $sec2['pedagogical'];
    if (is_array($pedagogical)) {
        $ped_count = count($pedagogical);
    }
}
$section_2_recalc += min($ped_count * 2, 20);
// Item 3: Assessments (2 marks each, max 20)
$assess_count = (int)($sec2['assess_count'] ?? 0);
if ($assess_count == 0 && !empty($sec2['assessments'])) {
    $assessments = is_string($sec2['assessments']) ? json_decode($sec2['assessments'], true) : $sec2['assessments'];
    if (is_array($assessments)) {
        $assess_count = count($assessments);
    }
}
$section_2_recalc += min($assess_count * 2, 20);
// Item 4: MOOCs (2 marks each, max 10)
$moocs = (int)($sec2['moocs'] ?? 0);
$section_2_recalc += min($moocs * 2, 10);
// Item 5: E-Content (1 mark per credit, max 15)
$econtent = (float)($sec2['econtent'] ?? 0);
$section_2_recalc += min($econtent, 15);
// Item 6: Result Declaration (conditional, max 5)
$result_days = (int)($sec2['result_days'] ?? 0);
if ($result_days > 0 && $result_days <= 30) {
    $section_2_recalc += 5;
} elseif ($result_days > 30 && $result_days <= 45) {
    $section_2_recalc += 2.5;
}
// If result_days is 0 or > 45, no marks (already 0)
// Update auto_scores with recalculated value
$auto_scores['section_2'] = $section_2_recalc;

// Recalculate Section IV total from actual data (more accurate than database value)
$sec4 = $dept_data['section_4'] ?? [];
$intake_data = $sec4['intake'] ?? [];
$placement_data = $sec4['placement'] ?? [];
$support_data = $sec4['support'] ?? [];

$section_4_recalc = 0;
// Item 1: Intake/Enrollment
$total_intake = (int)($intake_data['total_intake'] ?? 0);
$total_enrolled = (int)($intake_data['total_enrolled'] ?? 0);
$enrollment_percent = $total_intake > 0 ? ($total_enrolled / $total_intake) * 100 : 0;
$section_4_recalc += min($enrollment_percent * 0.1, 10);

// Item 2: Research Fellows
$research_fellows = (int)($support_data['research_fellows_count'] ?? 0);
$section_4_recalc += min($research_fellows, 10);

// Item 3: ESCS Diversity
$reserved_students = (int)($intake_data['reserved_category_students'] ?? 0);
$section_4_recalc += min($reserved_students * 0.1, 10);

// Item 4: Scholarships
$scholarship_students = (int)($intake_data['scholarship_students'] ?? 0);
$section_4_recalc += min($scholarship_students * 0.1, 10);

// Item 5: Regional Diversity
$outside_state = (int)($intake_data['outside_state_students'] ?? 0);
$outside_country = (int)($intake_data['outside_country_students'] ?? 0);
$section_4_recalc += min(($outside_state * 0.2) + ($outside_country * 0.5), 10);

// Item 6: Executive Development
$exec_dev_students = (int)($support_data['exec_dev_students'] ?? 0);
$section_4_recalc += min($exec_dev_students * 0.5, 10);

// Item 7: Support Initiatives
$support_initiatives = (int)($support_data['support_initiatives_count'] ?? 0);
$section_4_recalc += min($support_initiatives, 10);

// Item 8: Internship/OJT
$internship_students = (int)($support_data['internship_students'] ?? 0);
$section_4_recalc += min($internship_students * 0.1, 10);

// Item 9: Graduation Outcome
$appeared = (int)($intake_data['final_sem_appeared'] ?? 0);
$passed = (int)($intake_data['final_sem_passed'] ?? 0);
$pass_percent = $appeared > 0 ? ($passed / $appeared) * 100 : 0;
$section_4_recalc += min($pass_percent * 0.1, 10);

// Item 10: Placement
$placed_students = (int)($placement_data['total_placed'] ?? 0);
$section_4_recalc += min($placed_students * 0.1, 10);

// Item 11: Competitive Exams
$competitive_exam_students = (int)($placement_data['total_qualifying_exams'] ?? 0);
$section_4_recalc += min($competitive_exam_students, 10);

// Item 12: Higher Studies
$higher_studies = (int)($placement_data['total_higher_studies'] ?? 0);
$section_4_recalc += min($higher_studies * 0.5, 10);

// Item 13: Research Activities
$student_research = (int)($support_data['student_research_activities'] ?? 0);
$section_4_recalc += min($student_research, 10);

// Item 14: Sports Awards
$state_sports = (int)($support_data['awards_sports_state'] ?? 0);
$national_sports = (int)($support_data['awards_sports_national'] ?? 0);
$intl_sports = (int)($support_data['awards_sports_international'] ?? 0);
$section_4_recalc += min(($state_sports * 1) + ($national_sports * 2) + ($intl_sports * 3), 10);

// Item 15: Cultural Awards
$cultural_awards = (int)($support_data['awards_cultural_count'] ?? 0);
$section_4_recalc += min($cultural_awards, 10);

// Item 16: MOOC
$mooc_courses = (int)($support_data['mooc_courses_adopted'] ?? 0);
$section_4_recalc += min($mooc_courses, 10);

// Item 17: Other (narrative, 0 auto score)
$section_4_recalc += 0;

// Update auto_scores with recalculated value
$auto_scores['section_4'] = $section_4_recalc;

// Recalculate Section V total from actual data (more accurate than database value)
$sec5 = $dept_data['section_5'] ?? [];
$conferences_data = $sec5['conferences'] ?? [];
$collaborations_data = $sec5['collaborations'] ?? [];
$section_5_recalc = 0;
// Part A: Conferences (Items 1-6)
$section_5_recalc += min((int)($conferences_data['A1'] ?? 0) * 2, 10); // Item 1
$section_5_recalc += min((int)($conferences_data['A2'] ?? 0) * 2, 10); // Item 2
$section_5_recalc += min((int)($conferences_data['A3'] ?? 0) * 2, 10); // Item 3
$section_5_recalc += min((int)($conferences_data['A4'] ?? 0) * 3, 10); // Item 4
$section_5_recalc += min((int)($conferences_data['A5'] ?? 0), 5); // Item 5
$section_5_recalc += min((int)($conferences_data['A6'] ?? 0), 5); // Item 6
// Part B: Collaborations (Items 7-11)
$section_5_recalc += min((int)($collaborations_data['B1'] ?? 0) * 2, 10); // Item 7
$section_5_recalc += min((int)($collaborations_data['B2'] ?? 0) * 2, 10); // Item 8
$section_5_recalc += min((int)($collaborations_data['B3'] ?? 0) * 2, 5); // Item 9
$section_5_recalc += min((int)($collaborations_data['B4'] ?? 0) * 2, 10); // Item 10
$section_5_recalc += min((int)($collaborations_data['B5'] ?? 0) * 2, 5); // Item 11
// Update auto_scores with recalculated value
$auto_scores['section_5'] = $section_5_recalc;

// Recalculate total from all recalculated sections
$auto_scores['total'] = $auto_scores['section_1'] + $auto_scores['section_2'] + $auto_scores['section_3'] + $auto_scores['section_4'] + $auto_scores['section_5'];

// Get expert review if exists
$expert_email = getExpertForCategory($category_name);
$expert_review = null;
if ($expert_email) {
    $expert_review = getExpertReview($expert_email, $dept_id, $academic_year);
}

// Parse individual item scores from JSON (if stored)
$expert_item_scores = [];
$expert_narrative_scores = [];
if ($expert_review) {
    if (!empty($expert_review['expert_item_scores'])) {
        $item_scores_json = is_string($expert_review['expert_item_scores']) 
            ? $expert_review['expert_item_scores'] 
            : json_encode($expert_review['expert_item_scores']);
        $expert_item_scores = json_decode($item_scores_json, true) ?? [];
    }
    if (!empty($expert_review['expert_narrative_scores'])) {
        $narrative_scores_json = is_string($expert_review['expert_narrative_scores']) 
            ? $expert_review['expert_narrative_scores'] 
            : json_encode($expert_review['expert_narrative_scores']);
        $expert_narrative_scores = json_decode($narrative_scores_json, true) ?? [];
    }
}

// Set flags for read-only mode
$is_readonly = true;
$is_chairman_view = true;
$is_locked = false;
$expert_scores = [
    'section_1' => $expert_review ? (float)($expert_review['expert_score_section_1'] ?? 0) : 0,
    'section_2' => $expert_review ? (float)($expert_review['expert_score_section_2'] ?? 0) : 0,
    'section_3' => $expert_review ? (float)($expert_review['expert_score_section_3'] ?? 0) : 0,
    'section_4' => $expert_review ? (float)($expert_review['expert_score_section_4'] ?? 0) : 0,
    'section_5' => $expert_review ? (float)($expert_review['expert_score_section_5'] ?? 0) : 0,
];
$expert_scores['total'] = array_sum($expert_scores);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Review - <?php echo htmlspecialchars($dept_name); ?> - Chairman Console</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            padding-bottom: 250px; /* Increased to ensure consolidated score summary is fully visible above remark bar */
        }
        
        .main-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem 1rem;
            padding-bottom: 250px; /* Ensure consolidated score summary is fully visible above remark bar */
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        
        .section-card {
            background: white;
            border-radius: 15px;
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            border-left: 6px solid var(--primary-color);
        }
        
        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 3px solid var(--primary-color);
        }
        
        .subsection-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--secondary-color);
            margin: 2rem 0 1rem;
        }
        
        .data-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 1rem;
            padding: 1rem;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            transition: background 0.3s;
        }
        
        .data-grid:hover {
            background: #f8f9fa;
        }
        
        .data-grid.header {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            align-items: center;
        }
        
        .data-grid.header > div {
            text-align: center;
        }
        
        .data-grid.header > div:first-child {
            text-align: left;
        }
        
        .field-label {
            font-weight: 600;
            color: #333;
        }
        
        .dept-value {
            color: #666;
            padding: 0.5rem;
            background: #e3f2fd;
            border-radius: 5px;
            text-align: center;
        }
        
        .auto-score {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            padding: 0.75rem;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            color: #1976d2;
        }
        
        .expert-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd !important;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s;
            background-color: #f9fafb !important;
            color: #6b7280 !important;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .expert-score {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            padding: 0.75rem;
            border-radius: 8px;
            text-align: center;
            font-weight: 700;
            color: #2e7d32;
        }
        
        .read-only-badge {
            color: #6b7280;
            font-style: italic;
            padding: 0.5rem 0.75rem;
            background: #f3f4f6;
            border-radius: 6px;
            text-align: center;
            font-size: 0.9rem;
            border: 1px solid #d1d5db;
        }
        
        .score-summary {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            padding: 2rem;
            margin: 2rem 0;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .score-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #dee2e6;
            align-items: center;
        }
        
        .score-row > div {
            text-align: center;
        }
        
        .score-row > div:first-child {
            text-align: left;
        }
        
        .score-row.total {
            border-top: 3px solid var(--primary-color);
            font-weight: 700;
            font-size: 1.3rem;
            padding-top: 1.5rem;
            margin-top: 1rem;
        }
        
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #999;
            font-style: italic;
            font-size: 1.1rem;
        }
        
        /* Professional Floating Remark Bar */
        .remark-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-top: 3px solid var(--primary-color);
            padding: 1.5rem 2rem;
            box-shadow: 0 -8px 24px rgba(0,0,0,0.15);
            z-index: 1000;
        }
        
        .remark-bar-content {
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            gap: 1.25rem;
            align-items: flex-end;
        }
        
        .remark-input-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .remark-options-row {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        
        .remark-options-row select {
            border: 2px solid #d1d5db;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            background: white;
            color: #374151;
            transition: all 0.2s;
            min-width: 180px;
        }
        
        .remark-options-row select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .remark-textarea {
            width: 100%;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            padding: 1rem;
            font-family: inherit;
            font-size: 0.95rem;
            resize: none;
            min-height: 70px;
            max-height: 150px;
            transition: all 0.2s;
            background: white;
            color: #1f2937;
        }
        
        .remark-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .remark-textarea::placeholder {
            color: #9ca3af;
        }
        
        .char-count {
            font-size: 0.75rem;
            color: #6b7280;
            text-align: right;
            font-weight: 500;
        }
        
        .char-count.warning {
            color: #f59e0b;
            font-weight: 600;
        }
        
        .char-count.danger {
            color: #ef4444;
            font-weight: 700;
        }
        
        .btn-send {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 1rem 2.5rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-height: 70px;
        }
        
        .btn-send:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }
        
        .btn-send:active:not(:disabled) {
            transform: translateY(0);
        }
        
        .btn-send:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }
        
        .remark-bar-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .remark-bar-label i {
            color: var(--primary-color);
        }
        
        .toast-container {
            position: fixed;
            top: 80px;
            right: 2rem;
            z-index: 2000;
        }
        
        .toast {
            background: var(--success-color);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin-bottom: 1rem;
            display: none;
            animation: slideIn 0.3s ease;
        }
        
        .toast.show {
            display: block;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2">
                    <i class="fas fa-clipboard-check"></i> Department Review (Read-Only)
                </h1>
                <h3 class="mb-3"><?php echo htmlspecialchars($dept_name); ?> (Code: <?php echo $dept_code; ?>)</h3>
                <p class="mb-0"><i class="fas fa-calendar"></i> Academic Year: <strong><?php echo $academic_year; ?></strong></p>
            </div>
            <div class="d-flex gap-2">
                <a href="category.php?cat_id=<?php echo $cat_id; ?>&name=<?php echo urlencode($category_name); ?>" class="btn btn-light btn-lg">
                    <i class="fas fa-arrow-left"></i> Back to Departments
                </a>
                <a href="../logout.php" class="btn btn-danger btn-lg">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Score Summary Card -->
    <div class="section-card">
        <h2 class="section-title"><i class="fas fa-chart-bar"></i> Score Summary</h2>
        <?php if ($expert_review && $expert_scores['total'] > 0): ?>
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle"></i> <strong>Note:</strong> Individual item scores are not stored in the database. 
            Only section totals are saved. The "Expert Score" column for individual items shows the auto-calculated score. 
            The actual expert-reviewed scores are shown in the <strong>Consolidated Score Summary</strong> below.
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Section</th>
                        <th>Dept Auto Score</th>
                        <th>Expert Score</th>
                        <th>Max Marks</th>
                        <th>Difference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sections = [
                        'section_1' => ['label' => 'Section I: Faculty Output & Research', 'max' => 300],
                        'section_2' => ['label' => 'Section II: NEP Initiatives', 'max' => 100],
                        'section_3' => ['label' => 'Section III: Departmental Governance', 'max' => 110],
                        'section_4' => ['label' => 'Section IV: Student Support & Progression', 'max' => 140],
                        'section_5' => ['label' => 'Section V: Conferences & Collaborations', 'max' => 75],
                    ];
                    foreach ($sections as $key => $section):
                        $auto = $auto_scores[$key] ?? 0;
                        $expert = $expert_scores[$key] ?? 0;
                        $diff = $expert - $auto;
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($section['label']); ?></td>
                            <td><strong><?php echo number_format($auto, 2); ?></strong></td>
                            <td><strong style="color: var(--success-color);"><?php echo number_format($expert, 2); ?></strong></td>
                            <td><?php echo $section['max']; ?></td>
                            <td style="color: <?php echo $diff > 0 ? 'var(--success-color)' : ($diff < 0 ? 'var(--danger-color)' : '#6b7280'); ?>;">
                                <?php echo ($diff >= 0 ? '+' : '') . number_format($diff, 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-info">
                        <th>TOTAL</th>
                        <th><strong><?php echo number_format($auto_scores['total'] ?? 0, 2); ?></strong></th>
                        <th><strong style="color: var(--success-color);"><?php echo number_format($expert_scores['total'], 2); ?></strong></th>
                        <th>725</th>
                        <th style="color: <?php 
                            $total_diff = $expert_scores['total'] - ($auto_scores['total'] ?? 0);
                            echo $total_diff > 0 ? 'var(--success-color)' : ($total_diff < 0 ? 'var(--danger-color)' : '#6b7280'); 
                        ?>;">
                            <?php echo ($total_diff >= 0 ? '+' : '') . number_format($total_diff, 2); ?>
                        </th>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Include ALL Sections (read-only mode) -->
    <?php 
    try {
        include('../Expert_comty_login/section1_faculty_output.php');
        include('../Expert_comty_login/section2_nep_initiatives.php');
        include('../Expert_comty_login/section3_governance.php');
        include('../Expert_comty_login/section4_student_support.php');
        include('../Expert_comty_login/section5_conferences.php');
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error loading section: ' . htmlspecialchars($e->getMessage()) . '</div>';
        error_log("Error including section files: " . $e->getMessage());
    } catch (Error $e) {
        echo '<div class="alert alert-danger">Fatal error loading section: ' . htmlspecialchars($e->getMessage()) . '</div>';
        error_log("Fatal error including section files: " . $e->getMessage());
    }
    ?>
    
    <!-- Note: Communication History has been moved to the dedicated Notifications page -->
    <!-- View all remarks at: <a href="notifications.php">Notifications Page</a> -->
</div>

<!-- Toast Notification -->
<div class="toast-container">
    <div id="toast" class="toast">
        <i class="fas fa-check-circle"></i> Remark sent successfully!
    </div>
</div>

<!-- Professional Floating Remark Bar -->
<div class="remark-bar">
    <div class="remark-bar-content">
        <div class="remark-input-section">
            <div class="remark-bar-label">
                <i class="fas fa-comment-dots"></i>
                Send Remark to Expert
            </div>
            <div class="remark-options-row">
                <select id="remarkType" class="form-select">
                    <option value="general">📝 General</option>
                    <option value="score_adjustment">📊 Score Adjustment</option>
                    <option value="data_verification">✅ Data Verification</option>
                    <option value="other">🔖 Other</option>
                </select>
                <select id="remarkPriority" class="form-select">
                    <option value="low">🟢 Low Priority</option>
                    <option value="medium" selected>🟡 Medium Priority</option>
                    <option value="high">🟠 High Priority</option>
                    <option value="urgent">🔴 Urgent Priority</option>
                </select>
            </div>
            <textarea 
                id="remarkText" 
                class="remark-textarea" 
                placeholder="Type your remark or feedback for the expert here... (max 500 characters)"
                maxlength="500"></textarea>
            <div class="char-count" id="charCountDisplay">
                <span id="charCount">0</span> / 500 characters
            </div>
        </div>
        <button id="sendRemarkBtn" class="btn-send" onclick="sendRemark()">
            <i class="fas fa-paper-plane"></i> 
            <span>Send Remark</span>
        </button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const deptId = <?php echo $dept_id; ?>;
    const remarkTextarea = document.getElementById('remarkText');
    const charCount = document.getElementById('charCount');
    const sendBtn = document.getElementById('sendRemarkBtn');
    
    // Character counter with professional styling
    remarkTextarea.addEventListener('input', function() {
        const count = this.value.length;
        const charCountDisplay = document.getElementById('charCountDisplay');
        charCount.textContent = count;
        
        // Update styling based on character count
        charCountDisplay.classList.remove('warning', 'danger');
        if (count > 450) {
            charCountDisplay.classList.add('danger');
        } else if (count > 400) {
            charCountDisplay.classList.add('warning');
        }
        
        sendBtn.disabled = count === 0 || count > 500;
    });
    
    // Send remark
    function sendRemark() {
        const text = remarkTextarea.value.trim();
        if (!text || text.length > 500) {
            return;
        }
        
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        
        fetch('api/remark.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                dept_id: deptId,
                remark_text: text,
                academic_year: '<?php echo $academic_year; ?>',
                category: '<?php echo htmlspecialchars($category_name, ENT_QUOTES); ?>',
                remark_type: document.getElementById('remarkType').value,
                priority: document.getElementById('remarkPriority').value
            })
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error(text || 'Server error: ' + response.status);
                    }
                });
            }
            return response.json();
        })
        .then(data => {
            if (data && data.success) {
                // Show toast
                const toast = document.getElementById('toast');
                toast.classList.add('show');
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
                
                // Clear textarea
                remarkTextarea.value = '';
                charCount.textContent = '0';
                charCount.style.color = '#6b7280';
            } else {
                alert('Error: ' + (data?.message || 'Failed to send remark'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to send remark: ' + (error.message || 'Please try again.'));
        })
        .finally(() => {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Remark';
        });
    }
    
    // Allow Enter+Shift for new line, Enter alone submits
    remarkTextarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (this.value.trim().length > 0) {
                sendRemark();
            }
        }
    });
    
    // Delete chairman remark
    function deleteChairmanRemark(remarkId, button) {
        if (!confirm('Are you sure you want to delete this remark? This action cannot be undone.')) {
            return;
        }
        
        const item = button.closest('.remark-item');
        item.style.opacity = '0.5';
        button.disabled = true;
        
        fetch('api/delete_remark.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ remark_id: remarkId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                item.style.transition = 'opacity 0.3s';
                item.style.opacity = '0';
                setTimeout(() => {
                    item.remove();
                    // Reload page if no remarks left
                    const remainingRemarks = document.querySelectorAll('.remark-item').length;
                    if (remainingRemarks === 0) {
                        location.reload();
                    }
                }, 300);
            } else {
                alert('Failed to delete remark: ' + (data.message || 'Unknown error'));
                item.style.opacity = '1';
                button.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to delete remark. Please try again.');
            item.style.opacity = '1';
            button.disabled = false;
        });
    }
</script>
</body>
</html>
