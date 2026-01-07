<?php
/**
 * Section IV: Student Support, Achievements and Progression - OFFICIAL UDRF COMPLIANT
 * 14 items (15 with sports/cultural split) - 140 marks total
 * Restructured to match official UDRF document exactly
 */

// Ensure this is included from review_complete.php or view_department.php
if (!isset($dept_data)) {
    die("This file must be included from review_complete.php or view_department.php");
}
// Set default values if not set (for chairman view)
if (!isset($is_locked)) {
    $is_locked = false;
}
if (!isset($is_readonly)) {
    $is_readonly = false;
}
if (!isset($is_chairman_view)) {
    $is_chairman_view = false;
}

// Get Section 4 data (multiple tables: intake, placement, phd, support)
$sec4 = $dept_data['section_4'] ?? [];
$intake_data = $sec4['intake'] ?? [];
$placement_data = $sec4['placement'] ?? [];
$phd_data = $sec4['phd'] ?? [];
$support_data = $sec4['support'] ?? [];

// Include renderer functions
require_once('section_renderer.php');

// Calculate section total from individual items (more accurate than database value)
$section_4_auto_total = 0;
?>

<!-- Section IV: Student Support, Achievements and Progression -->
<div class="section-card">
    <h2 class="section-title">
        <i class="fas fa-users"></i> Section IV: Student Support, Achievements and Progression
        <span class="badge bg-primary float-end">Maximum: 140 Marks</span>
    </h2>
    
    <div class="info-box">
        <p><strong>Instructions:</strong> Review student intake, diversity, support initiatives, placements, and achievements. Verify data against supporting documents.</p>
        <p><strong>Official UDRF Structure:</strong> 14 items (15 with sports/cultural split) totaling 140 marks.</p>
    </div>

    <!-- Header Row -->
    <div class="data-grid header" <?php if ((isset($is_chairman_view) && $is_chairman_view) || (isset($is_department_view) && $is_department_view)): ?>style="grid-template-columns: 2fr 1fr 1fr;"<?php endif; ?>>
        <div>Data Point</div>
        <div>Dept Value</div>
        <div>Auto Score</div>
        <?php if ((!isset($is_chairman_view) || !$is_chairman_view) && (!isset($is_department_view) || !$is_department_view)): ?>
        <div>Expert Input</div>
        <?php endif; ?>
        <?php if (!isset($is_department_view) || !$is_department_view): ?>
        <div>Expert Score</div>
        <?php endif; ?>
    </div>

    <?php
    // ========================================
    // ITEM 1: Enrolment Ratio (Max 10 marks)
    // ========================================
    // UDRF Formula: (Number of eligible applications received / Number of Sanctioned Seats/Intake)
    // NOTE: Database currently doesn't have "applications received" field in intake_actual_strength table
    // Using enrolled/intake ratio as best available alternative until applications tracking is implemented
    // 1:1 = 1 mark, 1:2 = 2 marks, 1:3 = 4 marks, 1:4 = 6 marks, 1:5 = 8 marks, 1:>=5 = 10 marks
    $total_intake = (int)($intake_data['total_intake'] ?? 0);
    $total_enrolled = (int)($intake_data['total_enrolled'] ?? 0);
    
    // Calculate enrolment ratio (using enrolled as proxy for applications until proper data is available)
    if ($total_intake > 0) {
        $enrolment_ratio = $total_enrolled / $total_intake;
        if ($enrolment_ratio < 1) {
            $auto_score_1 = 0;
        } elseif ($enrolment_ratio >= 1 && $enrolment_ratio < 2) {
            $auto_score_1 = 1;
        } elseif ($enrolment_ratio >= 2 && $enrolment_ratio < 3) {
            $auto_score_1 = 2;
        } elseif ($enrolment_ratio >= 3 && $enrolment_ratio < 4) {
            $auto_score_1 = 4;
        } elseif ($enrolment_ratio >= 4 && $enrolment_ratio < 5) {
            $auto_score_1 = 6;
        } elseif ($enrolment_ratio >= 5 && $enrolment_ratio < 6) {
            $auto_score_1 = 8;
        } else {
            $auto_score_1 = 10;
        }
    } else {
        $enrolment_ratio = 0;
        $auto_score_1 = 0;
    }
    $section_4_auto_total += $auto_score_1;
    
    // Get program-wise data
    $intake_programs = $intake_data['programs'] ?? [];
    $programs_for_item1 = [];
    foreach ($intake_programs as $prog) {
        $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
        $prog_intake = (int)($prog['program_intake'] ?? 0);
        $prog_enrolled = (int)($prog['program_enrolled'] ?? 0);
        $prog_ratio = $prog_intake > 0 ? ($prog_enrolled / $prog_intake) : 0;
        $programs_for_item1[] = [
            'PROGRAM_NAME' => $prog['PROGRAM_NAME'] ?? 'N/A',
            'actual_program_code' => $prog_code,
            'PROGRAM_CODE' => $prog['PROGRAM_CODE'] ?? '',
            'value' => "Intake: $prog_intake, Enrolled: $prog_enrolled (Ratio: 1:" . number_format($prog_ratio, 2) . ")"
        ];
    }
    
    if (!empty($programs_for_item1)) {
        renderProgramSpecificItem(
            1,
            'Enrolment Ratio (Max 10 marks)',
            $programs_for_item1,
            "Intake: $total_intake, Enrolled: $total_enrolled (Ratio: 1:" . number_format($enrolment_ratio, 2) . ")",
            $auto_score_1,
            10,
            function($prog_code, $prog) use ($grouped_docs) {
                return getProgramSpecificDocuments($grouped_docs ?? [], 'intake_actual_strength', $prog_code, 1);
            },
            $is_locked
        );
    } else {
        renderVerifiableItem(
            1,
            'Enrolment Ratio (Max 10 marks)',
            "Intake: $total_intake, Enrolled: $total_enrolled (Ratio: 1:" . number_format($enrolment_ratio, 2) . ")",
            $auto_score_1,
            10,
            getDocumentsForSection($grouped_docs ?? [], 'intake_actual_strength', ['intake', 'enrolment', 'enrollment', 'capacity'], 1),
            $is_locked
        );
    }

    // ========================================
    // ITEM 2: Admission Percentage (Max 10 marks) - NEW ITEM
    // ========================================
    // Formula: Average admission % across all programs
    // 100% = 10 marks, 90-100% = 9 marks, 80-90% = 8 marks, etc.
    $total_intake = (int)($intake_data['total_intake'] ?? 0);
    $total_enrolled = (int)($intake_data['total_enrolled'] ?? 0);
    
    if ($total_intake > 0) {
        $admission_percent = ($total_enrolled / $total_intake) * 100;
        if ($admission_percent >= 100) {
            $auto_score_2 = 10;
        } elseif ($admission_percent >= 90) {
            $auto_score_2 = 9;
        } elseif ($admission_percent >= 80) {
            $auto_score_2 = 8;
        } elseif ($admission_percent >= 70) {
            $auto_score_2 = 7;
        } elseif ($admission_percent >= 60) {
            $auto_score_2 = 6;
        } elseif ($admission_percent >= 50) {
            $auto_score_2 = 5;
        } elseif ($admission_percent >= 40) {
            $auto_score_2 = 4;
        } elseif ($admission_percent >= 30) {
            $auto_score_2 = 3;
        } elseif ($admission_percent >= 20) {
            $auto_score_2 = 2;
        } elseif ($admission_percent >= 10) {
            $auto_score_2 = 1;
        } else {
            $auto_score_2 = 0;
        }
    } else {
        $admission_percent = 0;
        $auto_score_2 = 0;
    }
    $section_4_auto_total += $auto_score_2;
    
    // Get program-wise data
    $programs_for_item2 = [];
    foreach ($intake_programs as $prog) {
        $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
        $prog_intake = (int)($prog['program_intake'] ?? 0);
        $prog_enrolled = (int)($prog['program_enrolled'] ?? 0);
        $prog_percent = $prog_intake > 0 ? ($prog_enrolled / $prog_intake) * 100 : 0;
        $programs_for_item2[] = [
            'PROGRAM_NAME' => $prog['PROGRAM_NAME'] ?? 'N/A',
            'actual_program_code' => $prog_code,
            'PROGRAM_CODE' => $prog['PROGRAM_CODE'] ?? '',
            'value' => number_format($prog_percent, 1) . "%"
        ];
    }
    
    if (!empty($programs_for_item2)) {
        renderProgramSpecificItem(
            2,
            'Admission Percentage in various programs run by the Department (Max 10 marks)',
            $programs_for_item2,
            number_format($admission_percent, 1) . "%",
            $auto_score_2,
            10,
            function($prog_code, $prog) use ($grouped_docs) {
                return getProgramSpecificDocuments($grouped_docs ?? [], 'intake_actual_strength', $prog_code, 1);
            },
            $is_locked
        );
    } else {
        renderVerifiableItem(
            2,
            'Admission Percentage in various programs run by the Department (Max 10 marks)',
            number_format($admission_percent, 1) . "%",
            $auto_score_2,
            10,
            getDocumentsForSection($grouped_docs ?? [], 'intake_actual_strength', ['admission', 'percentage', 'intake'], 1),
            $is_locked
        );
    }

    // ========================================
    // ITEM 3: JRFs, SRFs, Post Doctoral Fellows (Max 10 marks)
    // ========================================
    // Formula: (PhD students with fellowship / Total PhD students) * 100
    // 1 mark for 20%, 10 marks for 100% (proportionate)
    $phd_with_fellowship = (int)($phd_data['phd_with_fellowship'] ?? 0);
    $total_phd = (int)($phd_data['total_phd'] ?? 0);
    
    if ($total_phd > 0) {
        $fellowship_percent = ($phd_with_fellowship / $total_phd) * 100;
        $auto_score_3 = min(($fellowship_percent / 20), 10); // 1 mark per 20%
    } else {
        $fellowship_percent = 0;
        $auto_score_3 = 0;
    }
    $section_4_auto_total += $auto_score_3;
    
    // Build research fellows array
    $research_fellows_list = [];
    $jrfs_list = $support_data['jrfs_data_parsed'] ?? [];
    $srfs_list = $support_data['srfs_data_parsed'] ?? [];
    $post_doc_list = $support_data['post_doctoral_data_parsed'] ?? [];
    $research_assoc_list = $support_data['research_associates_data_parsed'] ?? [];
    $other_research_list = $support_data['other_research_data_parsed'] ?? [];
    
    foreach ($jrfs_list as $jrf) {
        $research_fellows_list[] = [
            'name' => $jrf['name'] ?? 'N/A',
            'type' => 'JRF',
            'fellowship' => $jrf['fellowship'] ?? 'N/A'
        ];
    }
    foreach ($srfs_list as $srf) {
        $research_fellows_list[] = [
            'name' => $srf['name'] ?? 'N/A',
            'type' => 'SRF',
            'fellowship' => $srf['fellowship'] ?? 'N/A'
        ];
    }
    foreach ($post_doc_list as $postdoc) {
        $research_fellows_list[] = [
            'name' => $postdoc['name'] ?? 'N/A',
            'type' => 'Post Doctoral',
            'fellowship' => $postdoc['fellowship'] ?? 'N/A'
        ];
    }
    foreach ($research_assoc_list as $ra) {
        $research_fellows_list[] = [
            'name' => $ra['name'] ?? 'N/A',
            'type' => 'Research Associate',
            'fellowship' => $ra['fellowship'] ?? 'N/A'
        ];
    }
    foreach ($other_research_list as $other) {
        $research_fellows_list[] = [
            'name' => $other['name'] ?? 'N/A',
            'type' => 'Other',
            'fellowship' => $other['fellowship'] ?? 'N/A'
        ];
    }
    
    $docs_research_fellows = getDocumentsForSection($grouped_docs ?? [], 'student_support', ['research', 'fellows', 'jrf', 'srf'], 2);
    
    renderJSONArrayItems(
        3,
        'Number of JRFs, SRFs, Post Doctoral Fellows, Research Associates, and other research fellows in the Department enrolled during the last year (Max 10 marks)',
        json_encode($research_fellows_list),
        $auto_score_3,
        10,
        $docs_research_fellows,
        $is_locked
    );

    // ========================================
    // ITEM 4: ESCS Diversity (Max 10 marks)
    // ========================================
    // If intake filled as per Government Norms of Reservation - full marks
    $reserved_students = (int)($intake_data['reserved_category_students'] ?? 0);
    $auto_score_4 = min($reserved_students * 0.1, 10);
    $section_4_auto_total += $auto_score_4;
    
    // Get program-wise data
    $programs_for_item4 = [];
    foreach ($intake_programs as $prog) {
        $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
        $prog_reserved = (int)($prog['program_reserved_category'] ?? 0);
        $programs_for_item4[] = [
            'PROGRAM_NAME' => $prog['PROGRAM_NAME'] ?? 'N/A',
            'actual_program_code' => $prog_code,
            'PROGRAM_CODE' => $prog['PROGRAM_CODE'] ?? '',
            'value' => $prog_reserved . ' students'
        ];
    }
    
    if (!empty($programs_for_item4)) {
        renderProgramSpecificItem(
            4,
            'ESCS Diversity of Students as per Govt of Maharashtra Reservation Policy (Max 10 marks)',
            $programs_for_item4,
            $reserved_students . ' students',
            $auto_score_4,
            10,
            function($prog_code, $prog) use ($grouped_docs) {
                return getProgramSpecificDocuments($grouped_docs ?? [], 'intake_actual_strength', $prog_code, 3);
            },
            $is_locked
        );
    } else {
        renderVerifiableItem(
            4,
            'ESCS Diversity of Students as per Govt of Maharashtra Reservation Policy (Max 10 marks)',
            $reserved_students . ' students',
            $auto_score_4,
            10,
            getDocumentsForSection($grouped_docs ?? [], 'intake_actual_strength', ['escs', 'diversity', 'reservation', 'reserved category'], 3),
            $is_locked
        );
    }

    // ========================================
    // ITEM 5: Women Diversity (Max 5 marks) - REPLACED SCHOLARSHIPS
    // ========================================
    // If filled as per Government norms of quota - full marks
    $female_students = (int)($intake_data['female_students'] ?? 0);
    $total_students_intake = (int)($intake_data['total_enrolled'] ?? 0);
    
    if ($total_students_intake > 0) {
        $female_percent = ($female_students / $total_students_intake) * 100;
        // Assuming 50% is the government norm for women quota
        $auto_score_5 = min(($female_percent / 50) * 5, 5);
    } else {
        $female_percent = 0;
        $auto_score_5 = 0;
    }
    $section_4_auto_total += $auto_score_5;
    
    // Get program-wise data
    $programs_for_item5 = [];
    foreach ($intake_programs as $prog) {
        $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
        $prog_female = (int)($prog['program_female_students'] ?? 0);
        $prog_total = (int)($prog['program_enrolled'] ?? 0);
        $prog_female_percent = $prog_total > 0 ? ($prog_female / $prog_total) * 100 : 0;
        $programs_for_item5[] = [
            'PROGRAM_NAME' => $prog['PROGRAM_NAME'] ?? 'N/A',
            'actual_program_code' => $prog_code,
            'PROGRAM_CODE' => $prog['PROGRAM_CODE'] ?? '',
            'value' => "$prog_female female students (" . number_format($prog_female_percent, 1) . "%)"
        ];
    }
    
    if (!empty($programs_for_item5)) {
        renderProgramSpecificItem(
            5,
            'Women Diversity of Students (Max 5 marks)',
            $programs_for_item5,
            "$female_students female students (" . number_format($female_percent, 1) . "%)",
            $auto_score_5,
            5,
            function($prog_code, $prog) use ($grouped_docs) {
                return getProgramSpecificDocuments($grouped_docs ?? [], 'intake_actual_strength', $prog_code, 5);
            },
            $is_locked
        );
    } else {
        renderVerifiableItem(
            5,
            'Women Diversity of Students (Max 5 marks)',
            "$female_students female students (" . number_format($female_percent, 1) . "%)",
            $auto_score_5,
            5,
            getDocumentsForSection($grouped_docs ?? [], 'intake_actual_strength', ['women', 'female', 'diversity', 'gender'], 5),
            $is_locked
        );
    }

    // ========================================
    // ITEM 6: Regional Diversity (Max 5 marks) - FIXED MAX FROM 10 TO 5
    // ========================================
    $outside_state = (int)($intake_data['outside_state_students'] ?? 0);
    $outside_country = (int)($intake_data['outside_country_students'] ?? 0);
    $auto_score_6 = min(($outside_state * 0.1) + ($outside_country * 0.25), 5); // Adjusted for max 5
    $section_4_auto_total += $auto_score_6;
    
    // Get program-wise data
    $programs_for_item6 = [];
    foreach ($intake_programs as $prog) {
        $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
        $prog_outside_state = (int)($prog['program_outside_state'] ?? 0);
        $prog_outside_country = (int)($prog['program_outside_country'] ?? 0);
        $programs_for_item6[] = [
            'PROGRAM_NAME' => $prog['PROGRAM_NAME'] ?? 'N/A',
            'actual_program_code' => $prog_code,
            'PROGRAM_CODE' => $prog['PROGRAM_CODE'] ?? '',
            'value' => "Outside State: $prog_outside_state, Outside Country: $prog_outside_country"
        ];
    }
    
    if (!empty($programs_for_item6)) {
        renderProgramSpecificItem(
            6,
            'Regional Diversity of Students (Max 5 marks)',
            $programs_for_item6,
            "Outside State: $outside_state, Outside Country: $outside_country",
            $auto_score_6,
            5,
            function($prog_code, $prog) use ($grouped_docs) {
                return getProgramSpecificDocuments($grouped_docs ?? [], 'intake_actual_strength', $prog_code, 2);
            },
            $is_locked
        );
    } else {
        renderVerifiableItem(
            6,
            'Regional Diversity of Students (Max 5 marks)',
            "Outside State: $outside_state, Outside Country: $outside_country",
            $auto_score_6,
            5,
            getDocumentsForSection($grouped_docs ?? [], 'intake_actual_strength', ['regional', 'diversity', 'outside state', 'outside country'], 2),
            $is_locked
        );
    }

    // ========================================
    // ITEM 7: Support Initiatives (Max 10 marks)
    // ========================================
    // 2 marks per support initiative
    $support_initiatives = (int)($support_data['support_initiatives_count'] ?? 0);
    $auto_score_7 = min($support_initiatives * 2, 10); // 2 marks each
    $section_4_auto_total += $auto_score_7;
    
    $support_initiatives_list = $support_data['support_initiatives_data_parsed'] ?? [];
    $docs_support_initiatives = getDocumentsForSection($grouped_docs ?? [], 'student_support', ['support', 'initiatives', 'enrichment'], 7);
    
    renderJSONArrayItems(
        7,
        'Various Support Initiatives for Enrichment of Campus Life and Academic Growth of Students (2 marks each, Max 10)',
        json_encode($support_initiatives_list),
        $auto_score_7,
        10,
        $docs_support_initiatives,
        $is_locked
    );

    // ========================================
    // ITEM 8: Internship/OJT (Max 10 marks)
    // ========================================
    // 1 mark per 10%
    $internship_students = (int)($support_data['internship_students'] ?? 0);
    $total_students_for_internship = (int)($placement_data['total_students'] ?? 1);
    $internship_percent = ($internship_students / $total_students_for_internship) * 100;
    $auto_score_8 = min($internship_percent / 10, 10); // 1 mark per 10%
    $section_4_auto_total += $auto_score_8;
    
    $internship_list = $support_data['internship_data_parsed'] ?? [];
    $docs_internship = getDocumentsForSection($grouped_docs ?? [], 'student_support', ['internship', 'ojt', 'on job training'], 8);
    
    renderJSONArrayItems(
        8,
        'Average percentage of Internship/OJT of students in the last year (1 mark per 10%, Max 10 marks)',
        json_encode($internship_list),
        $auto_score_8,
        10,
        $docs_internship,
        $is_locked
    );

    // ========================================
    // ITEM 9: Graduation Outcome (Max 5 marks) - FIXED MAX FROM 10 TO 5
    // ========================================
    // 100% = 5 marks, 80-100% = 4 marks, 60-80% = 3 marks, etc.
    $total_graduated = (int)($placement_data['total_graduated'] ?? 0);
    $total_students = (int)($placement_data['total_students'] ?? 0);
    
    if ($total_students > 0) {
        $graduation_percent = ($total_graduated / $total_students) * 100;
        if ($graduation_percent >= 100) {
            $auto_score_9 = 5;
        } elseif ($graduation_percent >= 80) {
            $auto_score_9 = 4;
        } elseif ($graduation_percent >= 60) {
            $auto_score_9 = 3;
        } elseif ($graduation_percent >= 40) {
            $auto_score_9 = 2;
        } elseif ($graduation_percent >= 20) {
            $auto_score_9 = 1;
        } else {
            $auto_score_9 = 0;
        }
    } else {
        $graduation_percent = 0;
        $auto_score_9 = 0;
    }
    $section_4_auto_total += $auto_score_9;
    
    // Get program-wise data
    $placement_programs = $placement_data['programs'] ?? [];
    $programs_for_item9 = [];
    foreach ($placement_programs as $prog) {
        $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
        $prog_graduated = (int)($prog['TOTAL_NUM_OF_STUDENTS_GRADUATED'] ?? 0);
        $prog_total = (int)($prog['TOTAL_NO_OF_STUDENT'] ?? 0);
        $prog_percent = $prog_total > 0 ? ($prog_graduated / $prog_total) * 100 : 0;
        $programs_for_item9[] = [
            'PROGRAM_NAME' => $prog['PROGRAM_NAME'] ?? 'N/A',
            'actual_program_code' => $prog_code,
            'PROGRAM_CODE' => $prog['PROGRAM_CODE'] ?? '',
            'value' => "Appeared: $prog_total, Passed: $prog_graduated (" . number_format($prog_percent, 1) . "%)"
        ];
    }
    
    if (!empty($programs_for_item9)) {
        renderProgramSpecificItem(
            9,
            'Graduation Outcome in various programs run by the Department (Max 5 marks)',
            $programs_for_item9,
            "Appeared: $total_students, Passed: $total_graduated (" . number_format($graduation_percent, 1) . "%)",
            $auto_score_9,
            5,
            function($prog_code, $prog) use ($grouped_docs) {
                return getProgramSpecificDocuments($grouped_docs ?? [], 'placement_details', $prog_code, 1);
            },
            $is_locked
        );
    } else {
        renderVerifiableItem(
            9,
            'Graduation Outcome in various programs run by the Department (Max 5 marks)',
            "Appeared: $total_students, Passed: $total_graduated (" . number_format($graduation_percent, 1) . "%)",
            $auto_score_9,
            5,
            getDocumentsForSection($grouped_docs ?? [], 'placement_details', ['placement', 'graduation', 'passed'], 1),
            $is_locked
        );
    }

    // ========================================
    // ITEM 10: Placement & Self-Employment (Max 10 marks)
    // ========================================
    // 1 mark per 10%
    $placed_students = (int)($placement_data['total_placed'] ?? 0);
    $outgoing_students = (int)($placement_data['total_graduated'] ?? 0);
    
    if ($outgoing_students > 0) {
        $placement_percent = ($placed_students / $outgoing_students) * 100;
        $auto_score_10 = min($placement_percent / 10, 10);
    } else {
        $placement_percent = 0;
        $auto_score_10 = 0;
    }
    $section_4_auto_total += $auto_score_10;
    
    // Get program-wise data
    $programs_for_item10 = [];
    foreach ($placement_programs as $prog) {
        $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
        $prog_placed = (int)($prog['TOTAL_NUM_OF_STUDENTS_PLACED'] ?? 0);
        $programs_for_item10[] = [
            'PROGRAM_NAME' => $prog['PROGRAM_NAME'] ?? 'N/A',
            'actual_program_code' => $prog_code,
            'PROGRAM_CODE' => $prog['PROGRAM_CODE'] ?? '',
            'value' => "Placed: $prog_placed"
        ];
    }
    
    if (!empty($programs_for_item10)) {
        renderProgramSpecificItem(
            10,
            'Average percentage of Placement and Self-Employment of outgoing students in the last year (1 mark per 10%, Max 10 marks)',
            $programs_for_item10,
            "Placed: $placed_students (" . number_format($placement_percent, 1) . "%)",
            $auto_score_10,
            10,
            function($prog_code, $prog) use ($grouped_docs) {
                return getProgramSpecificDocuments($grouped_docs ?? [], 'placement_details', $prog_code, 1);
            },
            $is_locked
        );
    } else {
        renderVerifiableItem(
            10,
            'Average percentage of Placement and Self-Employment of outgoing students in the last year (1 mark per 10%, Max 10 marks)',
            "Placed: $placed_students (" . number_format($placement_percent, 1) . "%)",
            $auto_score_10,
            10,
            getDocumentsForSection($grouped_docs ?? [], 'placement_details', ['placement', 'job', 'employment'], 1),
            $is_locked
        );
    }

    // ========================================
    // ITEM 11: Competitive Exams (Max 10 marks)
    // ========================================
    // 1 mark per 10%
    $competitive_exam_students = (int)($placement_data['total_qualifying_exams'] ?? 0);
    $outgoing_students_for_exams = (int)($placement_data['total_graduated'] ?? 0);
    
    if ($outgoing_students_for_exams > 0) {
        $exam_percent = ($competitive_exam_students / $outgoing_students_for_exams) * 100;
        $auto_score_11 = min($exam_percent / 10, 10);
    } else {
        $exam_percent = 0;
        $auto_score_11 = 0;
    }
    $section_4_auto_total += $auto_score_11;
    
    // Get program-wise data
    $programs_for_item11 = [];
    foreach ($placement_programs as $prog) {
        $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
        $prog_exams = (int)($prog['STUDENTS_QUALIFYING_EXAMS'] ?? 0);
        $programs_for_item11[] = [
            'PROGRAM_NAME' => $prog['PROGRAM_NAME'] ?? 'N/A',
            'actual_program_code' => $prog_code,
            'PROGRAM_CODE' => $prog['PROGRAM_CODE'] ?? '',
            'value' => $prog_exams
        ];
    }
    
    if (!empty($programs_for_item11)) {
        renderProgramSpecificItem(
            11,
            'The average percentage of students qualifying in the state/national/international level examinations during the last year (1 mark per 10%, Max 10 marks)',
            $programs_for_item11,
            (string)$competitive_exam_students . " (" . number_format($exam_percent, 1) . "%)",
            $auto_score_11,
            10,
            function($prog_code, $prog) use ($grouped_docs) {
                return getProgramSpecificDocuments($grouped_docs ?? [], 'placement_details', $prog_code, 2);
            },
            $is_locked
        );
    } else {
        renderVerifiableItem(
            11,
            'The average percentage of students qualifying in the state/national/international level examinations during the last year (1 mark per 10%, Max 10 marks)',
            $competitive_exam_students . " (" . number_format($exam_percent, 1) . "%)",
            $auto_score_11,
            10,
            getDocumentsForSection($grouped_docs ?? [], 'placement_details', ['exam', 'qualification', 'net', 'gate', 'cat'], 2),
            $is_locked
        );
    }

    // ========================================
    // ITEM 12: Higher Studies (Max 10 marks)
    // ========================================
    // 1 mark per 10%
    $higher_studies = (int)($placement_data['total_higher_studies'] ?? 0);
    $outgoing_students_for_higher = (int)($placement_data['total_graduated'] ?? 0);
    
    if ($outgoing_students_for_higher > 0) {
        $higher_studies_percent = ($higher_studies / $outgoing_students_for_higher) * 100;
        $auto_score_12 = min($higher_studies_percent / 10, 10);
    } else {
        $higher_studies_percent = 0;
        $auto_score_12 = 0;
    }
    $section_4_auto_total += $auto_score_12;
    
    // Get program-wise data
    $programs_for_item12 = [];
    foreach ($placement_programs as $prog) {
        $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
        $prog_higher = (int)($prog['NUM_OF_STUDENTS_IN_HIGHER_STUDIES'] ?? 0);
        $programs_for_item12[] = [
            'PROGRAM_NAME' => $prog['PROGRAM_NAME'] ?? 'N/A',
            'actual_program_code' => $prog_code,
            'PROGRAM_CODE' => $prog['PROGRAM_CODE'] ?? '',
            'value' => $prog_higher
        ];
    }
    
    if (!empty($programs_for_item12)) {
        renderProgramSpecificItem(
            12,
            'Average percentage of Students going for Higher Studies in Foreign Universities/IIT/IIM/Eminent Institutions in the previous year (1 mark per 10%, Max 10 marks)',
            $programs_for_item12,
            (string)$higher_studies . " (" . number_format($higher_studies_percent, 1) . "%)",
            $auto_score_12,
            10,
            function($prog_code, $prog) use ($grouped_docs) {
                return getProgramSpecificDocuments($grouped_docs ?? [], 'placement_details', $prog_code, 3);
            },
            $is_locked
        );
    } else {
        renderVerifiableItem(
            12,
            'Average percentage of Students going for Higher Studies in Foreign Universities/IIT/IIM/Eminent Institutions in the previous year (1 mark per 10%, Max 10 marks)',
            $higher_studies . " (" . number_format($higher_studies_percent, 1) . "%)",
            $auto_score_12,
            10,
            getDocumentsForSection($grouped_docs ?? [], 'placement_details', ['higher', 'studies', 'foreign', 'iit', 'iim'], 3),
            $is_locked
        );
    }

    // ========================================
    // ITEM 13: Student Research Activity (Max 15 marks) - FIXED MAX FROM 10 TO 15
    // ========================================
    // 1 mark per activity
    $student_research = (int)($support_data['student_research_activities'] ?? 0);
    $auto_score_13 = min($student_research, 15); // Max 15
    $section_4_auto_total += $auto_score_13;
    
    $research_activities_list = $support_data['research_activities_data_parsed'] ?? [];
    $docs_research_activities = getDocumentsForSection($grouped_docs ?? [], 'student_support', ['research', 'activity', 'avishkar', 'anveshan'], 13);
    
    renderJSONArrayItems(
        13,
        'Students Research Activity: Research Publications/Award at State Level Avishkar/Anveshan Award/National Conference Presentation Award etc (1 mark per activity, Max 15 marks)',
        json_encode($research_activities_list),
        $auto_score_13,
        15,
        $docs_research_activities,
        $is_locked
    );

    // ========================================
    // ITEM 14: Sports Awards (Max 10 marks)
    // ========================================
    // 1 mark per State, 2 marks per National, 3 marks per International
    $state_sports = (int)($support_data['awards_sports_state'] ?? 0);
    $national_sports = (int)($support_data['awards_sports_national'] ?? 0);
    $intl_sports = (int)($support_data['awards_sports_international'] ?? 0);
    $auto_score_14 = min(($state_sports * 1) + ($national_sports * 2) + ($intl_sports * 3), 10);
    $section_4_auto_total += $auto_score_14;
    
    $awards_list = $support_data['awards_data_parsed'] ?? [];
    $sports_awards_list = array_values(array_filter($awards_list, function($award) {
        return strtolower(trim($award['category'] ?? '')) === 'sports';
    }));
    
    $docs_sports_awards = getDocumentsForSection($grouped_docs ?? [], 'student_support', ['sports', 'awards', 'medals'], 14);
    
    renderJSONArrayItems(
        14,
        'Number of awards/medals for outstanding performance in sports activities at State (1 mark)/National (2 marks)/International (3 marks) level (Max 10 marks)',
        json_encode($sports_awards_list),
        $auto_score_14,
        10,
        $docs_sports_awards,
        $is_locked
    );

    // ========================================
    // ITEM 15: Cultural Awards (Max 10 marks)
    // ========================================
    // 1 mark per award
    $cultural_awards = (int)($support_data['awards_cultural_count'] ?? 0);
    $auto_score_15 = min($cultural_awards, 10);
    $section_4_auto_total += $auto_score_15;
    
    $cultural_awards_list = array_values(array_filter($awards_list, function($award) {
        return strtolower(trim($award['category'] ?? '')) === 'cultural';
    }));
    
    $docs_cultural_awards = getDocumentsForSection($grouped_docs ?? [], 'student_support', ['cultural', 'awards', 'medals'], 15);
    
    renderJSONArrayItems(
        15,
        'Number of awards/medals for outstanding performance in cultural activities at State/National/International level (1 mark per award, Max 10 marks)',
        json_encode($cultural_awards_list),
        $auto_score_15,
        10,
        $docs_cultural_awards,
        $is_locked
    );
    ?>

    <!-- Section 4 Summary -->
    <div class="mt-4 p-3 bg-light rounded">
        <h4>Section IV Total</h4>
        <div class="row">
            <div class="<?php echo (isset($is_department_view) && $is_department_view) ? 'col-md-12' : 'col-md-4'; ?>">
                <strong>Department Auto Score:</strong>
                <div class="auto-score mt-2"><?php echo number_format($section_4_auto_total, 2); ?> / 140</div>
            </div>
            <?php if (!isset($is_department_view) || !$is_department_view): ?>
            <div class="col-md-4">
                <strong>Expert Score:</strong>
                <div class="expert-score mt-2" id="section_4_total_display"><?php echo number_format($expert_scores['section_4'], 2); ?> / 140</div>
                <input type="hidden" id="expert_section_4_total" value="<?php echo $expert_scores['section_4']; ?>">
            </div>
            <div class="col-md-4">
                <strong>Difference:</strong>
                <div class="mt-2" id="section_4_diff_display"><?php echo number_format($expert_scores['section_4'] - $section_4_auto_total, 2); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
// Real-time calculation for Section IV
function recalculateSection4() {
    let section4Total = 0;
    
    // Collect all Section IV scores (15 items: 1-15)
    const section4Inputs = document.querySelectorAll('input[id*="enrolment_ratio"], input[id*="admission_percentage"], input[id*="number_of_jrfs"], input[id*="escs_diversity"], input[id*="women_diversity"], input[id*="regional_diversity"], input[id*="various_support"], input[id*="average_percentage_of_internship"], input[id*="graduation_outcome"], input[id*="average_percentage_of_placement"], input[id*="the_average_percentage_of_students_qualifying"], input[id*="average_percentage_of_students_going"], input[id*="students_research"], input[id*="number_of_awards_medals_for_outstanding_performance_in_sports"], input[id*="number_of_awards_medals_for_outstanding_performance_in_cultural"]');
    section4Inputs.forEach(input => {
        const value = parseFloat(input.value) || 0;
        section4Total += value;
        
        // Update individual item score display
        const scoreDisplay = document.getElementById(input.id + '_score');
        if (scoreDisplay) {
            scoreDisplay.textContent = value.toFixed(2);
        }
    });
    
    // Cap at 140
    section4Total = Math.min(section4Total, 140);
    
    // Update displays
    document.getElementById('section_4_total_display').textContent = section4Total.toFixed(2) + ' / 140';
    document.getElementById('expert_section_4_total').value = section4Total;
    document.getElementById('display_expert_section_4').textContent = section4Total.toFixed(2);
    
    const autoScore4 = <?php echo $section_4_auto_total; ?>;
    const diff4 = section4Total - autoScore4;
    document.getElementById('section_4_diff_display').textContent = diff4.toFixed(2);
    document.getElementById('display_diff_section_4').textContent = diff4.toFixed(2);
    
    // Recalculate grand total
    recalculateGrandTotal();
}

// Attach to global recalculate function
if (typeof window.sectionCalculators === 'undefined') {
    window.sectionCalculators = [];
}
window.sectionCalculators.push(recalculateSection4);
</script>

