<?php
/**
 * Section II: NEP Initiatives, Teaching, Learning, and Assessment - COMPLETE Implementation
 * All 6 items with verification fields
 * Include this file in review_complete.php
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

// Get Section 2 data
$sec2 = $dept_data['section_2'] ?? [];

// Include renderer functions
require_once('section_renderer.php');

$decodeList = function($value) {
    if (empty($value)) {
        return [];
    }
    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('trim', $decoded)));
    }
    $parts = preg_split('/\s*,\s*/', (string)$value);
    return array_values(array_filter(array_map('trim', $parts)));
};
?>

<!-- Section II: NEP Initiatives, Teaching, Learning, and Assessment -->
<div class="section-card">
    <h2 class="section-title">
        <i class="fas fa-book-open"></i> Section II: NEP Initiatives, Teaching, Learning, and Assessment Process
        <span class="badge bg-primary float-end">Maximum: 100 Marks</span>
    </h2>
    
    <div class="info-box">
        <p><strong>Instructions:</strong> Review each NEP initiative, pedagogical approach, and assessment method. Verify counts against supporting documents and enter your verified score.</p>
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
    // Item 1: NEP Initiatives (2 marks each, max 30)
    // Use parsed version if available, otherwise decode from JSON string
    if (isset($sec2['nep_initiatives_parsed']) && is_array($sec2['nep_initiatives_parsed'])) {
        $nep_entries = $sec2['nep_initiatives_parsed'];
    } else {
        $nep_entries = $decodeList($sec2['nep_initiatives'] ?? '');
    }
    $nep_count = !empty($nep_entries) ? count($nep_entries) : (int)($sec2['nep_count'] ?? 0);
    $auto_score_1 = min($nep_count * 2, 30);
    $docs_1 = getDocumentsForSection($grouped_docs, 'nep_initiatives', [], 1);
    $nep_view = array_map(function($item) {
        return ['Initiative' => $item];
    }, $nep_entries);
    if (empty($nep_view) && $nep_count > 0) {
        $nep_view[] = ['Initiative' => 'Details not provided'];
    }
    renderJSONArrayItems(
        1,
        'NEP Initiatives and Professional Activities adopted by the Department (2 marks each, Max 30)',
        json_encode($nep_view),
        $auto_score_1,
        30,
        $docs_1,
        $is_locked
    );

    // Item 2: Teaching-learning pedagogical approaches (2 marks each, max 20)
    // Use parsed version if available, otherwise decode from JSON string
    if (isset($sec2['pedagogical_parsed']) && is_array($sec2['pedagogical_parsed'])) {
        $ped_entries = $sec2['pedagogical_parsed'];
    } else {
        $ped_entries = $decodeList($sec2['pedagogical'] ?? '');
    }
    $ped_count = !empty($ped_entries) ? count($ped_entries) : (int)($sec2['ped_count'] ?? 0);
    $auto_score_2 = min($ped_count * 2, 20);
    $docs_2 = getDocumentsForSection($grouped_docs, 'nep_initiatives', [], 2);
    $ped_view = array_map(function($item) {
        return ['Approach' => $item];
    }, $ped_entries);
    if (empty($ped_view) && $ped_count > 0) {
        $ped_view[] = ['Approach' => 'Details not provided'];
    }
    renderJSONArrayItems(
        2,
        'Teaching-learning pedagogical approaches adopted by the department (2 marks each, Max 20)',
        json_encode($ped_view),
        $auto_score_2,
        20,
        $docs_2,
        $is_locked
    );

    // Item 3: Student-centric assessments (2 marks each, max 20)
    // Use parsed version if available, otherwise decode from JSON string
    if (isset($sec2['assessments_parsed']) && is_array($sec2['assessments_parsed'])) {
        $assess_entries = $sec2['assessments_parsed'];
    } else {
        $assess_entries = $decodeList($sec2['assessments'] ?? '');
    }
    $assess_count = !empty($assess_entries) ? count($assess_entries) : (int)($sec2['assess_count'] ?? 0);
    $auto_score_3 = min($assess_count * 2, 20);
    $docs_3 = getDocumentsForSection($grouped_docs, 'nep_initiatives', [], 3);
    $assess_view = array_map(function($item) {
        return ['Assessment' => $item];
    }, $assess_entries);
    if (empty($assess_view) && $assess_count > 0) {
        $assess_view[] = ['Assessment' => 'Details not provided'];
    }
    renderJSONArrayItems(
        3,
        'Student-centric assessments (Formative, Interim, and Summative) adopted (2 marks each, Max 20)',
        json_encode($assess_view),
        $auto_score_3,
        20,
        $docs_3,
        $is_locked
    );

    // Item 4: MOOC courses (2 marks each, max 10)
    $moocs_reported = (int)($sec2['moocs'] ?? 0);
    $docs_4 = getDocumentsForSection($grouped_docs, 'nep_initiatives', [], 4);
    $mooc_entries = [];
    
    // Debug: Log raw data
    error_log("MOOC Debug - moocs_reported: " . $moocs_reported);
    error_log("MOOC Debug - mooc_data_parsed exists: " . (isset($sec2['mooc_data_parsed']) ? 'yes' : 'no'));
    error_log("MOOC Debug - mooc_data exists: " . (isset($sec2['mooc_data']) ? 'yes' : 'no'));
    
    // Use parsed mooc_data if available (new format)
    if (isset($sec2['mooc_data_parsed']) && is_array($sec2['mooc_data_parsed']) && !empty($sec2['mooc_data_parsed'])) {
        error_log("MOOC Debug - Using mooc_data_parsed, count: " . count($sec2['mooc_data_parsed']));
        foreach ($sec2['mooc_data_parsed'] as $idx => $entry) {
            if (!is_array($entry)) {
                error_log("MOOC Debug - Entry $idx is not an array: " . gettype($entry));
                continue;
            }
            $mooc_entries[] = [
                'Platform' => $entry['platform'] ?? '-',
                'Title' => $entry['title'] ?? '-',
                'Students' => (int)($entry['students'] ?? 0),
                'Credits' => (int)($entry['credits'] ?? 0)
            ];
        }
        error_log("MOOC Debug - Processed entries from mooc_data_parsed: " . count($mooc_entries));
    } else {
        // Fallback: Try to parse from mooc_data JSON string
        $mooc_data_field = $sec2['mooc_data'] ?? '';
        if (!empty($mooc_data_field)) {
            error_log("MOOC Debug - Attempting to parse mooc_data JSON string, length: " . strlen($mooc_data_field));
            error_log("MOOC Debug - Raw mooc_data (first 200 chars): " . substr($mooc_data_field, 0, 200));
            
            $mooc_data_decoded = json_decode($mooc_data_field, true);
            $json_error = json_last_error();
            if ($json_error !== JSON_ERROR_NONE) {
                error_log("MOOC Debug - JSON decode error: " . json_last_error_msg());
                // Try double-decode in case it's double-encoded
                $first_decode = json_decode($mooc_data_field, true);
                if (is_string($first_decode)) {
                    $mooc_data_decoded = json_decode($first_decode, true);
                    $json_error = json_last_error();
                    if ($json_error === JSON_ERROR_NONE) {
                        error_log("MOOC Debug - Successfully decoded after double-decode");
                    }
                }
            } else {
                error_log("MOOC Debug - JSON decoded successfully, is_array: " . (is_array($mooc_data_decoded) ? 'yes' : 'no') . ", count: " . (is_array($mooc_data_decoded) ? count($mooc_data_decoded) : 'N/A'));
            }
            
            if (is_array($mooc_data_decoded) && !empty($mooc_data_decoded)) {
                foreach ($mooc_data_decoded as $idx => $entry) {
                    if (!is_array($entry)) {
                        error_log("MOOC Debug - Entry $idx is not an array: " . gettype($entry) . ", value: " . var_export($entry, true));
                        continue;
                    }
                    $mooc_entries[] = [
                        'Platform' => $entry['platform'] ?? '-',
                        'Title' => $entry['title'] ?? '-',
                        'Students' => (int)($entry['students'] ?? 0),
                        'Credits' => (int)($entry['credits'] ?? 0)
                    ];
                }
                error_log("MOOC Debug - Processed entries from mooc_data JSON: " . count($mooc_entries));
            } else {
                error_log("MOOC Debug - mooc_data_decoded is not a valid array or is empty");
            }
        } else {
            error_log("MOOC Debug - mooc_data field is empty");
        }
        
        // Legacy fallback: Try old format (title field)
        if (empty($mooc_entries)) {
            $title_field = $sec2['title'] ?? '';
            if (!empty($title_field)) {
                $title_decoded = json_decode($title_field, true);
                if (is_array($title_decoded) && !empty($title_decoded)) {
                    error_log("MOOC Debug - Using legacy title field, count: " . count($title_decoded));
                    foreach ($title_decoded as $entry) {
                        $mooc_entries[] = [
                            'Platform' => $entry['platform'] ?? ($sec2['platform'] ?? '-'),
                            'Title' => $entry['title'] ?? ($entry['mooc_title'] ?? '-'),
                            'Students' => (int)($entry['students'] ?? ($sec2['students'] ?? 0)),
                            'Credits' => (int)($entry['credits'] ?? ($sec2['credits_transferred'] ?? 0))
                        ];
                    }
                }
            }
        }
        
        // Final fallback: Single entry from old fields
        if (empty($mooc_entries) && ($moocs_reported > 0 || !empty($sec2['platform']) || !empty($sec2['title']))) {
            error_log("MOOC Debug - Using final fallback (single entry)");
            $mooc_entries[] = [
                'Platform' => $sec2['platform'] ?? '-',
                'Title' => $sec2['title'] ?? ($sec2['mooc_title'] ?? '-'),
                'Students' => (int)($sec2['students'] ?? 0),
                'Credits' => (int)($sec2['credits_transferred'] ?? 0)
            ];
        }
    }
    
    error_log("MOOC Debug - Final mooc_entries count: " . count($mooc_entries));
    $mooc_entry_count = count($mooc_entries);
    $mooc_count_for_scoring = max($moocs_reported, $mooc_entry_count);
    $auto_score_4 = min($mooc_count_for_scoring * 2, 10);
    renderJSONArrayItems(
        4,
        'Adoption of MOOC courses like SWAYAM, NPTEL, Coursera in the Curriculum (2 marks each, Max 10)',
        json_encode($mooc_entries),
        $auto_score_4,
        10,
        $docs_4,
        $is_locked
    );

    // Item 5: E-Content Development (1 mark per credit, max 15)
    $econtent = (float)($sec2['econtent'] ?? 0);
    $auto_score_5 = min($econtent, 15);
    $docs_5 = getDocumentsForSection($grouped_docs, 'nep_initiatives', [], 5);
    renderVerifiableItem(
        5,
        'Creation of E-Content Development / Digital Educational Materials (1 mark per credit equivalent, Max 15)',
        number_format($econtent, 2) . ' credits',
        $auto_score_5,
        15,
        $docs_5,
        $is_locked
    );

    // Item 6: Timely Declaration of Results (conditional scoring)
    $result_days = (int)($sec2['result_days'] ?? 999);
    $auto_score_6 = 0;
    if ($result_days <= 30) {
        $auto_score_6 = 5;
    } else if ($result_days <= 45) {
        $auto_score_6 = 2.5;
    }
    $docs_6 = getDocumentsForSection($grouped_docs, 'nep_initiatives', [], 6);
    renderVerifiableItem(
        6,
        'Timely Declaration of Results (Within 30 days: 5 marks, 31-45 days: 2.5 marks, >45 days: 0 marks, Max 5)',
        $result_days . ' days',
        $auto_score_6,
        5,
        $docs_6,
        $is_locked
    );
    ?>

    <!-- Section 2 Summary -->
    <div class="mt-4 p-3 bg-light rounded">
        <h4>Section II Total</h4>
        <div class="row">
            <div class="<?php echo (isset($is_department_view) && $is_department_view) ? 'col-md-12' : 'col-md-4'; ?>">
                <strong>Department Auto Score:</strong>
                <div class="auto-score mt-2"><?php echo number_format($auto_scores['section_2'], 2); ?> / 100</div>
            </div>
            <?php if (!isset($is_department_view) || !$is_department_view): ?>
            <div class="col-md-4">
                <strong>Expert Score:</strong>
                <div class="expert-score mt-2" id="section_2_total_display"><?php echo number_format($expert_scores['section_2'], 2); ?> / 100</div>
                <input type="hidden" id="expert_section_2_total" value="<?php echo $expert_scores['section_2']; ?>">
            </div>
            <div class="col-md-4">
                <strong>Difference:</strong>
                <div class="mt-2" id="section_2_diff_display"><?php echo number_format($expert_scores['section_2'] - $auto_scores['section_2'], 2); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
// Real-time calculation for Section II
function recalculateSection2() {
    let section2Total = 0;
    
    // Collect all Section II scores (6 items)
    const section2Inputs = document.querySelectorAll('input[id*="nep_initiatives"], input[id*="teaching_learning"], input[id*="student_centric"], input[id*="adoption_of_mooc"], input[id*="creation_of_e_content"], input[id*="timely_declaration"]');
    section2Inputs.forEach(input => {
        const value = parseFloat(input.value) || 0;
        section2Total += value;
        
        // Update individual item score display
        const scoreDisplay = document.getElementById(input.id + '_score');
        if (scoreDisplay) {
            scoreDisplay.textContent = value.toFixed(2);
        }
    });
    
    // Cap at 100
    section2Total = Math.min(section2Total, 100);
    
    // Update displays
    document.getElementById('section_2_total_display').textContent = section2Total.toFixed(2) + ' / 100';
    document.getElementById('expert_section_2_total').value = section2Total;
    document.getElementById('display_expert_section_2').textContent = section2Total.toFixed(2);
    
    const autoScore2 = <?php echo $auto_scores['section_2']; ?>;
    const diff2 = section2Total - autoScore2;
    document.getElementById('section_2_diff_display').textContent = diff2.toFixed(2);
    document.getElementById('display_diff_section_2').textContent = diff2.toFixed(2);
    
    // Recalculate grand total
    recalculateGrandTotal();
}

// Attach to global recalculate function
if (typeof window.sectionCalculators === 'undefined') {
    window.sectionCalculators = [];
}
window.sectionCalculators.push(recalculateSection2);
</script>

