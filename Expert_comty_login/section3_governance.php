<?php
/**
 * Section III: Departmental Governance and Practices - OFFICIAL UDRF COMPLIANT
 * 12 items - 110 marks total
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

// Get Section 3 data
$sec3 = $dept_data['section_3'] ?? [];

// Include renderer functions
require_once('section_renderer.php');

/**
 * Calculate auto-score for narrative items based on text quality
 * Same logic as renderNarrativeItem
 */
function calculateNarrativeAutoScore($dept_text, $max_score) {
    $auto_score = 0.00;
    if (isset($dept_text) && $dept_text !== null) {
        $clean_text = trim($dept_text);
        // Check if response has actual content (not empty, not "-")
        if ($clean_text !== '' && $clean_text !== '-' && $clean_text !== 'Not provided') {
            // Calculate auto score based on response length and quality
            $text_length = strlen($clean_text);
            // Minimum threshold: at least 10 characters for any score
            if ($text_length >= 10) {
                // Base score: 30% of max for minimum response (10-50 chars)
                // Progressive score: up to 70% of max for substantial responses (200+ chars)
                if ($text_length < 50) {
                    $auto_score = $max_score * 0.30; // 30% for short responses
                } elseif ($text_length < 100) {
                    $auto_score = $max_score * 0.50; // 50% for medium responses
                } elseif ($text_length < 200) {
                    $auto_score = $max_score * 0.60; // 60% for good responses
                } else {
                    $auto_score = $max_score * 0.70; // 70% for substantial responses
                }
                // Cap at max_score
                $auto_score = min($auto_score, $max_score);
            }
        }
    }
    return $auto_score;
}

// Calculate section total from individual items (more accurate than database value)
$section_3_auto_total = 0;

$parseGovernanceSelections = function($value) {
    if (empty($value) || $value === '-' || $value === null) {
        return [];
    }
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value)));
    }
    $parts = preg_split('/\s*,\s*/', (string)$value);
    return array_values(array_filter(array_map('trim', $parts)));
};
?>

<!-- Section III: Departmental Governance and Practices -->
<div class="section-card">
    <h2 class="section-title">
        <i class="fas fa-building"></i> Section III: Departmental Governance and Practices
        <span class="badge bg-primary float-end">Maximum: 110 Marks</span>
    </h2>
    
    <div class="info-box">
        <p><strong>Instructions:</strong> Review governance practices, inclusive initiatives, green practices, and departmental management. Verify implementation with supporting evidence.</p>
        <p><strong>Official UDRF Structure:</strong> 12 items totaling 110 marks.</p>
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
    // ITEM 1: Inclusive Practices (Max 10 marks)
    // ========================================
    // Official UDRF: 2 marks per practice (0.5/1/1.5 marks for partial fulfillment)
    $inclusive_items = $parseGovernanceSelections($sec3['inclusive_practices'] ?? '');
    $inclusive_count = count($inclusive_items);
    // For now, assume full marks per practice (2 marks each)
    // TODO: Implement partial fulfillment logic when department provides fulfillment levels
    $auto_score_1 = min($inclusive_count * 2, 10);
    $section_3_auto_total += $auto_score_1;
    $inclusive_docs = getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 1);
    
    // Convert to array format for renderJSONArrayItems
    $inclusive_list = array_map(function($item) {
        return ['name' => $item, 'practice' => $item];
    }, $inclusive_items);
    
    renderJSONArrayItems(
        1,
        'No of Inclusive Practices and Support Initiatives, as per UGC Norms (2 marks per practice, 0.5/1/1.5 for partial fulfillment, Max 10)',
        json_encode($inclusive_list),
        $auto_score_1,
        10,
        $inclusive_docs,
        $is_locked
    );

    // ========================================
    // ITEM 2: Green/Eco-friendly Practices (Max 10 marks)
    // ========================================
    // 1 mark per green practice
    $green_items = $parseGovernanceSelections($sec3['green_practices'] ?? '');
    $green_count = count($green_items);
    $auto_score_2 = min($green_count, 10);
    $section_3_auto_total += $auto_score_2;
    $green_docs = getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 2);
    
    // Convert to array format for renderJSONArrayItems
    $green_list = array_map(function($item) {
        return ['name' => $item, 'practice' => $item];
    }, $green_items);
    
    renderJSONArrayItems(
        2,
        'Green/Eco-friendly/Sustainability Practices and Conducive Management steps implemented at the Department (1 mark per practice, Max 10)',
        json_encode($green_list),
        $auto_score_2,
        10,
        $green_docs,
        $is_locked
    );

    // ========================================
    // ITEM 3: Teachers in Administrative Roles (Max 10 marks) - FIXED MAX FROM 5 TO 10
    // ========================================
    // Formula: 10% of teachers = 1 mark
    $teachers_in_admin = (int)($sec3['teachers_in_admin'] ?? 0);
    $total_teachers = (int)($sec3['total_teachers'] ?? 0);
    
    if ($total_teachers > 0) {
        $admin_percent = ($teachers_in_admin / $total_teachers) * 100;
        $auto_score_3 = min($admin_percent / 10, 10); // 1 mark per 10%
    } else {
        $admin_percent = 0;
        $auto_score_3 = 0;
    }
    $section_3_auto_total += $auto_score_3;
    $docs_admin = getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 3);
    
    renderVerifiableItem(
        3,
        'Percentage of teachers involved in University and Government Administrative authorities/bodies/Committees (10% = 1 mark, Max 10)',
        "$teachers_in_admin teachers out of $total_teachers (" . number_format($admin_percent, 1) . "%)",
        $auto_score_3,
        10,
        $docs_admin,
        $is_locked
    );

    // ========================================
    // ITEM 4: Awards for Extension Activities (Max 10 marks)
    // ========================================
    // 2 marks per award
    $extension_awards = (int)($sec3['awards_extension'] ?? 0);
    $auto_score_4 = min($extension_awards * 2, 10);
    $section_3_auto_total += $auto_score_4;
    $docs_extension = getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 4);
    
    renderVerifiableItem(
        4,
        'Number of awards and recognitions received for extension activities from Government/recognized bodies during the last year (2 marks per award, Max 10)',
        $extension_awards . ' awards',
        $auto_score_4,
        10,
        $docs_extension,
        $is_locked
    );

    // ========================================
    // ITEM 5: Budgetary Allocation and Expenditure (Max 5 marks) - ADDED AUTO-SCORING
    // ========================================
    // 50% utilization = 2.5 marks (proportionate)
    $budget_allocated = (float)($sec3['budget_allocated'] ?? 0);
    $budget_utilized = (float)($sec3['budget_utilized'] ?? 0);
    
    if ($budget_allocated > 0) {
        $budget_utilization_percent = ($budget_utilized / $budget_allocated) * 100;
        $auto_score_5 = min(($budget_utilization_percent / 50) * 2.5, 5); // 50% = 2.5 marks, proportionate
    } else {
        $budget_utilization_percent = 0;
        $auto_score_5 = 0;
    }
    $section_3_auto_total += $auto_score_5;
    $budget_docs = getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 5);
    
    renderVerifiableItem(
        5,
        'Budgetary Allocation of the Department and Expenditure (50% utilization = 2.5 marks, proportionate, Max 5)',
        "Allocated: " . number_format($budget_allocated, 2) . " Lakhs, Utilized: " . number_format($budget_utilized, 2) . " Lakhs (" . number_format($budget_utilization_percent, 1) . "% utilization)",
        $auto_score_5,
        5,
        $budget_docs,
        $is_locked
    );

    // ========================================
    // ITEM 6: Alumni Contribution (Max 10 marks) - FIXED MAX FROM 5 TO 10, ADDED BRACKETS
    // ========================================
    // Bracket-based scoring per official UDRF
    // Amount is stored in Lakhs in database, convert to INR for bracket comparison
    $alumni_funding = (float)($sec3['alumni_contribution'] ?? 0);
    
    // Apply official UDRF brackets (brackets are in INR)
    // Convert Lakhs to INR for comparison
    $alumni_inr = $alumni_funding * 100000; // Convert Lakhs to INR
    
    if ($alumni_inr >= 1000000) { // >= 10 Lakhs
        $auto_score_6 = 10;
    } elseif ($alumni_inr >= 800000) { // 8-10 Lakhs
        $auto_score_6 = 9;
    } elseif ($alumni_inr >= 600000) { // 6-8 Lakhs
        $auto_score_6 = 8;
    } elseif ($alumni_inr >= 500000) { // 5-6 Lakhs
        $auto_score_6 = 7;
    } elseif ($alumni_inr >= 400000) { // 4-5 Lakhs
        $auto_score_6 = 6;
    } elseif ($alumni_inr >= 300000) { // 3-4 Lakhs
        $auto_score_6 = 5;
    } elseif ($alumni_inr >= 200000) { // 2-3 Lakhs
        $auto_score_6 = 4;
    } elseif ($alumni_inr >= 100000) { // 1-2 Lakhs
        $auto_score_6 = 3;
    } elseif ($alumni_inr >= 50000) { // 50K-1 Lakh
        $auto_score_6 = 2;
    } elseif ($alumni_inr >= 10000) { // 10K-50K
        $auto_score_6 = 1;
    } else {
        $auto_score_6 = 0;
    }
    $section_3_auto_total += $auto_score_6;
    $alumni_docs = getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 6);
    
    renderVerifiableItem(
        6,
        'Alumni contribution/Funding Support during the previous year (INR) (Bracket-based, Max 10)',
        number_format($alumni_funding, 2) . " Lakhs (INR " . number_format($alumni_inr) . ")",
        $auto_score_6,
        10,
        $alumni_docs,
        $is_locked
    );

    // ========================================
    // ITEM 7: CSR and Philanthropic Funding (Max 10 marks) - FIXED MAX FROM 5 TO 10, ADDED BRACKETS
    // ========================================
    // Bracket-based scoring per official UDRF
    $csr_funding = (float)($sec3['csr_funding'] ?? 0);
    
    // Apply official UDRF brackets (amount in Lakhs)
    if ($csr_funding >= 15) { // >= 15 Lakhs
        $auto_score_7 = 10;
    } elseif ($csr_funding >= 12) { // 12-15 Lakhs
        $auto_score_7 = 9;
    } elseif ($csr_funding >= 10) { // 10-12 Lakhs
        $auto_score_7 = 8;
    } elseif ($csr_funding >= 8) { // 8-10 Lakhs
        $auto_score_7 = 7;
    } elseif ($csr_funding >= 6) { // 6-8 Lakhs
        $auto_score_7 = 4;
    } elseif ($csr_funding >= 4) { // 4-6 Lakhs
        $auto_score_7 = 3;
    } elseif ($csr_funding >= 2) { // 2-4 Lakhs
        $auto_score_7 = 2;
    } elseif ($csr_funding >= 1) { // 1-2 Lakhs
        $auto_score_7 = 1;
    } else {
        $auto_score_7 = 0;
    }
    $section_3_auto_total += $auto_score_7;
    $csr_docs = getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 7);
    
    renderVerifiableItem(
        7,
        'CSR and Philanthropic Funding support to the Department during the previous year (Bracket-based, Max 10)',
        number_format($csr_funding, 2) . " Lakhs",
        $auto_score_7,
        10,
        $csr_docs,
        $is_locked
    );

    // ========================================
    // ITEM 8: Infrastructure Strengthening (Max 10 marks) - EXPERT EVALUATED WITH 4 AREAS
    // ========================================
    // Max 2.5 marks each for 4 areas: Infrastructural, IT/Digital, Library, Laboratory
    // Department provides descriptions, system calculates auto-score, expert reviews and adjusts
    
    // Calculate auto-scores for infrastructure
    $infra_texts = [
        trim($sec3['infrastructure_infrastructural'] ?? ''),
        trim($sec3['infrastructure_it_digital'] ?? ''),
        trim($sec3['infrastructure_library'] ?? ''),
        trim($sec3['infrastructure_laboratory'] ?? '')
    ];
    
    $infra_auto_score = 0.0;
    foreach ($infra_texts as $text) {
        if (!empty($text) && $text !== '-' && $text !== 'Not provided') {
            $text_length = strlen($text);
            if ($text_length >= 10) {
                if ($text_length < 50) {
                    $infra_auto_score += 0.75; // 30% of 2.5
                } elseif ($text_length < 100) {
                    $infra_auto_score += 1.25; // 50% of 2.5
                } elseif ($text_length < 200) {
                    $infra_auto_score += 1.50; // 60% of 2.5
                } else {
                    $infra_auto_score += 1.75; // 70% of 2.5
                }
            }
        }
    }
    
    $section_3_auto_total += $infra_auto_score;
    $infra_docs = getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 8);
    
    // Render 4-part infrastructure item
    renderInfrastructureItem(
        8,
        $sec3,
        $infra_docs,
        $is_locked
    );

    // ========================================
    // ITEM 9: Perception from Industry/Employers and Academia (PEER) (Max 10 marks)
    // ========================================
    // 5 marks each: Employer Perception + Academic Peer Perception
    // Calculated by BoD/Dean-Academics, so narrative/expert evaluated
    $item9_text = trim(($sec3['peer_perception_rate'] ?? '') . "\n" . ($sec3['peer_perception_notes'] ?? ''));
    $item9_auto_score = calculateNarrativeAutoScore($item9_text, 10);
    $section_3_auto_total += $item9_auto_score;
    renderNarrativeItem(
        9,
        'Perception from Industry/Employers and Academia (PEER) during the last year (5 marks each, Max 10) [Calculated by BoD/Dean-Academics]',
        $item9_text,
        10,
        getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 9),
        $is_locked
    );

    // ========================================
    // ITEM 10: Students' Feedback (Max 10 marks)
    // ========================================
    // 5 marks each: Feedback about Teachers + Feedback about Department
    // Calculated by BoD/Dean-Academics, so narrative/expert evaluated
    $item10_text = trim(($sec3['student_feedback_rate'] ?? '') . "\n" . ($sec3['student_feedback_notes'] ?? ''));
    $item10_auto_score = calculateNarrativeAutoScore($item10_text, 10);
    $section_3_auto_total += $item10_auto_score;
    renderNarrativeItem(
        10,
        'Students\' Feedback about Teachers and Department (5 marks each, Max 10) [Calculated by BoD/Dean-Academics]',
        $item10_text,
        10,
        getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 10),
        $is_locked
    );

    // ========================================
    // ITEM 11: Best Practice/Unique Activity (Max 5 marks)
    // ========================================
    // Narrative (Max 100 words), expert evaluated
    $item11_text = trim($sec3['best_practice'] ?? '');
    $item11_auto_score = calculateNarrativeAutoScore($item11_text, 5);
    $section_3_auto_total += $item11_auto_score;
    renderNarrativeItem(
        11,
        'Best Practice/Unique Activity of the Department (Description in Max 100 words, Max 5)',
        $item11_text,
        5,
        getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 11),
        $is_locked
    );

    // ========================================
    // ITEM 12: Synchronization Initiatives (Max 10 marks) - FIXED MAX FROM 5 TO 10
    // ========================================
    // Narrative (Max 100 words), expert evaluated
    $item12_text = trim($sec3['leadership_sync'] ?? '');
    $item12_auto_score = calculateNarrativeAutoScore($item12_text, 10);
    $section_3_auto_total += $item12_auto_score;
    renderNarrativeItem(
        12,
        'Details of various initiatives taken at the department level to ensure synchronization through cohesive leadership, conducive environment, and strong teamwork (Description in Max 100 words, Max 10)',
        $item12_text,
        10,
        getDocumentsForSection($grouped_docs ?? [], 'departmental_governance', [], 12),
        $is_locked
    );
    ?>

    <!-- Section 3 Summary -->
    <div class="mt-4 p-3 bg-light rounded">
        <h4>Section III Total</h4>
        <div class="row">
            <div class="<?php echo (isset($is_department_view) && $is_department_view) ? 'col-md-12' : 'col-md-4'; ?>">
                <strong>Department Auto Score:</strong>
                <div class="auto-score mt-2"><?php echo number_format($section_3_auto_total, 2); ?> / 110</div>
            </div>
            <?php if (!isset($is_department_view) || !$is_department_view): ?>
            <div class="col-md-4">
                <strong>Expert Score:</strong>
                <div class="expert-score mt-2" id="section_3_total_display"><?php echo number_format($expert_scores['section_3'], 2); ?> / 110</div>
                <input type="hidden" id="expert_section_3_total" value="<?php echo $expert_scores['section_3']; ?>">
            </div>
            <div class="col-md-4">
                <strong>Difference:</strong>
                <div class="mt-2" id="section_3_diff_display"><?php echo number_format($expert_scores['section_3'] - $section_3_auto_total, 2); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
// Real-time calculation for Section III
function recalculateSection3() {
    let section3Total = 0;
    
    // Collect all Section III narrative scores (items 1-7, 9-12)
    const section3NarrativeInputs = document.querySelectorAll('input[id^="narrative_"][id$="_score"]');
    section3NarrativeInputs.forEach(input => {
        const itemNumber = input.id.match(/narrative_(\d+)_score/);
        if (itemNumber && parseInt(itemNumber[1]) <= 12) { // Only Section III items (1-12)
            const value = parseFloat(input.value) || 0;
            section3Total += value;
        }
    });
    
    // Collect infrastructure scores (Item 8 - 4 sub-items)
    const infrastructureInputs = document.querySelectorAll('.infrastructure-score-input');
    infrastructureInputs.forEach(input => {
        const value = parseFloat(input.value) || 0;
        section3Total += value;
    });
    
    // Cap at 110
    section3Total = Math.min(section3Total, 110);
    
    // Update displays
    document.getElementById('section_3_total_display').textContent = section3Total.toFixed(2) + ' / 110';
    document.getElementById('expert_section_3_total').value = section3Total;
    document.getElementById('display_expert_section_3').textContent = section3Total.toFixed(2);
    
    const autoScore3 = <?php echo $section_3_auto_total; ?>;
    const diff3 = section3Total - autoScore3;
    document.getElementById('section_3_diff_display').textContent = diff3.toFixed(2);
    document.getElementById('display_diff_section_3').textContent = diff3.toFixed(2);
    
    // Recalculate grand total
    recalculateGrandTotal();
}

// Attach to global recalculate function
if (typeof window.sectionCalculators === 'undefined') {
    window.sectionCalculators = [];
}
window.sectionCalculators.push(recalculateSection3);
</script>
