<?php
/**
 * Section V: Conferences, Workshops, and Collaborations - COMPLETE Implementation
 * All 11 items with verification fields (Part A: 6 items, Part B: 5 items)
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

// Get Section 5 data (conferences and collaborations)
$sec5 = $dept_data['section_5'] ?? [];
$conferences_data = $sec5['conferences'] ?? [];
$collaborations_data = $sec5['collaborations'] ?? [];

// Include renderer functions
require_once('section_renderer.php');

// Calculate section total from individual items (more accurate than database value)
$section_5_auto_total = 0;
?>

<!-- Section V: Conferences, Workshops, and Collaborations -->
<div class="section-card">
    <h2 class="section-title">
        <i class="fas fa-handshake"></i> Section V: Conferences, Workshops, and Collaborations
        <span class="badge bg-primary float-end">Maximum: 75 Marks</span>
    </h2>
    
    <div class="info-box">
        <p><strong>Instructions:</strong> Review conferences, workshops, seminars, and collaborations organized and participated in by the department. Verify participation and outputs.</p>
    </div>

    <!-- Part A: Conferences, Workshops, STTP and Seminars -->
    <div class="subsection-title">Part A: Conferences, Workshops, STTP and Seminars (Max 40 Marks)</div>

    <!-- Header Row -->
    <div class="data-grid header" <?php if (isset($is_chairman_view) && $is_chairman_view): ?>style="grid-template-columns: 2fr 1fr 1fr 1fr;"<?php endif; ?>>
        <div>Data Point</div>
        <div>Dept Value</div>
        <div>Auto Score</div>
        <?php if (!isset($is_chairman_view) || !$is_chairman_view): ?>
        <div>Expert Input</div>
        <?php endif; ?>
        <div>Expert Score</div>
    </div>

    <?php
    // Item 1: Industry-Academia Innovative practices/Workshops (2 marks each, max 5)
    // A1 = Industry-Academia Innovative practices/Workshop
    $industry_workshops = (int)($conferences_data['A1'] ?? 0);
    $auto_score_1 = min($industry_workshops * 2, 5);
    $section_5_auto_total += $auto_score_1;
    renderVerifiableItem(
        1,
        'Number of Industry-Academia Innovative practices/Workshop conducted during the last year (2 marks each, Max 5)',
        $industry_workshops,
        $auto_score_1,
        5,
        getDocumentsForSection($grouped_docs ?? [], 'conferences_workshops', ['industry', 'academia', 'workshop'], 1), // Serial 1: industry_academia_pdf
        $is_locked
    );

    // Item 2: Workshops/STTP/Refresher Programmes (2 marks each, max 5)
    // A2 = Workshops/STTP/Refresher or Orientation Programme
    $sttp_workshops = (int)($conferences_data['A2'] ?? 0);
    $auto_score_2 = min($sttp_workshops * 2, 5);
    $section_5_auto_total += $auto_score_2;
    renderVerifiableItem(
        2,
        'Number of Workshops/STTP/Refresher or Orientation Programme Organized (2 marks each, Max 5)',
        $sttp_workshops,
        $auto_score_2,
        5,
        getDocumentsForSection($grouped_docs ?? [], 'conferences_workshops', ['sttp', 'workshop', 'refresher'], 2), // Serial 2: sttp_refresher_pdf
        $is_locked
    );

    // Item 3: National Conferences/Seminars/Workshops (2 marks each, max 5)
    // A3 = National Conferences/Seminars/Workshops
    $national_conferences = (int)($conferences_data['A3'] ?? 0);
    $auto_score_3 = min($national_conferences * 2, 5);
    $section_5_auto_total += $auto_score_3;
    renderVerifiableItem(
        3,
        'Number of National Conferences/Seminars/Workshops organized (2 marks each, Max 5)',
        $national_conferences,
        $auto_score_3,
        5,
        getDocumentsForSection($grouped_docs ?? [], 'conferences_workshops', ['national', 'conference', 'seminar'], 3), // Serial 3: national_conferences_pdf
        $is_locked
    );

    // Item 4: International Conferences/Seminars/Workshops (2 marks each, max 10)
    // A4 = International Conferences/Seminars/Workshops
    $intl_conferences = (int)($conferences_data['A4'] ?? 0);
    $auto_score_4 = min($intl_conferences * 2, 10);
    $section_5_auto_total += $auto_score_4;
    renderVerifiableItem(
        4,
        'Number of International Conferences/Seminars/Workshops organized (2 marks each, Max 10)',
        $intl_conferences,
        $auto_score_4,
        10,
        getDocumentsForSection($grouped_docs ?? [], 'conferences_workshops', ['international', 'conference', 'seminar'], 4), // Serial 4: international_conferences_pdf
        $is_locked
    );

    // Item 5: Teachers invited as speakers/resource persons/Session Chair (2 marks each, max 10)
    // A5 = Teachers invited as speakers/resource persons/Session Chair
    $teachers_as_speakers = (int)($conferences_data['A5'] ?? 0);
    $auto_score_5 = min($teachers_as_speakers * 2, 10);
    $section_5_auto_total += $auto_score_5;
    renderVerifiableItem(
        5,
        'Number of Teachers invited as speakers/resource persons/Session Chair (2 marks each, Max 10)',
        $teachers_as_speakers,
        $auto_score_5,
        10,
        getDocumentsForSection($grouped_docs ?? [], 'conferences_workshops', ['teachers', 'speakers', 'resource persons'], 5), // Serial 5: teachers_speakers_pdf
        $is_locked
    );

    // Item 6: Teachers who presented at Conferences (1 mark each, max 5)
    // A6 = Teachers who presented at Conferences/Seminars/Workshops
    $teachers_presenters = (int)($conferences_data['A6'] ?? 0);
    $auto_score_6 = min($teachers_presenters, 5);
    $section_5_auto_total += $auto_score_6;
    renderVerifiableItem(
        6,
        'Number of Teachers who presented at Conferences/Seminars/Workshops (1 mark each, Max 5)',
        $teachers_presenters,
        $auto_score_6,
        5,
        getDocumentsForSection($grouped_docs ?? [], 'conferences_workshops', ['teachers', 'presented', 'conferences'], 6), // Serial 6: teachers_presentations_pdf
        $is_locked
    );
    ?>

    <!-- Part B: Collaborations -->
    <div class="subsection-title mt-4">Part B: Collaborations (Max 35 Marks)</div>

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
    // Item 7: Industry collaborations (2 marks each, max 10)
    // B1 = Industry collaborations
    $industry_collab = (int)($collaborations_data['B1'] ?? 0);
    $auto_score_7 = min($industry_collab * 2, 10);
    $section_5_auto_total += $auto_score_7;
    renderVerifiableItem(
        7,
        'Number of Industry collaborations for Programs and their output (2 marks per functional collaboration, Max 10)',
        $industry_collab,
        $auto_score_7,
        10,
        getDocumentsForSection($grouped_docs ?? [], 'collaborations', ['industry', 'collaboration'], 1), // Serial 1: industry_collaborations_pdf
        $is_locked
    );

    // Item 8: National Academic collaborations (2 marks each, max 5)
    // B2 = National Academic collaborations
    $national_collab = (int)($collaborations_data['B2'] ?? 0);
    $auto_score_8 = min($national_collab * 2, 5);
    $section_5_auto_total += $auto_score_8;
    renderVerifiableItem(
        8,
        'Number of National Academic collaborations for Programs and their output (2 marks per functional collaboration, Max 5)',
        $national_collab,
        $auto_score_8,
        5,
        getDocumentsForSection($grouped_docs ?? [], 'collaborations', ['national', 'academic', 'collaboration'], 2), // Serial 2: national_academic_pdf
        $is_locked
    );

    // Item 9: Government/Semi-Government Collaborations (2 marks each, max 5)
    // B3 = Government/Semi-Government Collaboration Projects/Programs
    $govt_collab = (int)($collaborations_data['B3'] ?? 0);
    $auto_score_9 = min($govt_collab * 2, 5);
    $section_5_auto_total += $auto_score_9;
    renderVerifiableItem(
        9,
        'Number of Government/Semi-Government Collaboration Projects Programs (2 marks per functional collaboration, Max 5)',
        $govt_collab,
        $auto_score_9,
        5,
        getDocumentsForSection($grouped_docs ?? [], 'collaborations', ['government', 'semi-government', 'collaboration'], 3), // Serial 3: government_collaborations_pdf
        $is_locked
    );

    // Item 10: International Academic collaborations (2 marks each, max 10)
    // B4 = International Academic collaborations
    $intl_collab = (int)($collaborations_data['B4'] ?? 0);
    $auto_score_10 = min($intl_collab * 2, 10);
    $section_5_auto_total += $auto_score_10;
    renderVerifiableItem(
        10,
        'Number of International Academic collaborations for Programs and their output (2 marks per functional collaboration, Max 10)',
        $intl_collab,
        $auto_score_10,
        10,
        getDocumentsForSection($grouped_docs ?? [], 'collaborations', ['international', 'academic', 'collaboration'], 4), // Serial 4: international_academic_pdf
        $is_locked
    );

    // Item 11: Outreach/Social Activity Collaborations (2 marks each, max 5)
    // B5 = Outreach/Social Activity Collaborations
    $outreach_collab = (int)($collaborations_data['B5'] ?? 0);
    $auto_score_11 = min($outreach_collab * 2, 5);
    $section_5_auto_total += $auto_score_11;
    renderVerifiableItem(
        11,
        'Number of Outreach/Social Activity Collaborations and their output (2 marks per functional collaboration, Max 5)',
        $outreach_collab,
        $auto_score_11,
        5,
        getDocumentsForSection($grouped_docs ?? [], 'collaborations', ['outreach', 'social', 'activity', 'collaboration'], 5), // Serial 5: outreach_social_pdf
        $is_locked
    );
    ?>

    <!-- Section 5 Summary -->
    <div class="mt-4 p-3 bg-light rounded">
        <h4>Section V Total</h4>
        <div class="row">
            <div class="<?php echo (isset($is_department_view) && $is_department_view) ? 'col-md-12' : 'col-md-4'; ?>">
                <strong>Department Auto Score:</strong>
                <div class="auto-score mt-2"><?php echo number_format($section_5_auto_total, 2); ?> / 75</div>
            </div>
            <?php if (!isset($is_department_view) || !$is_department_view): ?>
            <div class="col-md-4">
                <strong>Expert Score:</strong>
                <div class="expert-score mt-2" id="section_5_total_display"><?php echo number_format($expert_scores['section_5'], 2); ?> / 75</div>
                <input type="hidden" id="expert_section_5_total" value="<?php echo $expert_scores['section_5']; ?>">
            </div>
            <div class="col-md-4">
                <strong>Difference:</strong>
                <div class="mt-2" id="section_5_diff_display"><?php echo number_format($expert_scores['section_5'] - $section_5_auto_total, 2); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
// Real-time calculation for Section V
function recalculateSection5() {
    let section5Total = 0;
    
    // Collect all Section V scores (11 items)
    const section5Inputs = document.querySelectorAll('input[id*="number_of_industry_academia"], input[id*="number_of_workshops_sttp"], input[id*="number_of_national_conferences"], input[id*="number_of_international_conferences"], input[id*="number_of_teachers_invited"], input[id*="number_of_teachers_who"], input[id*="number_of_industry_collaborations"], input[id*="number_of_national_academic"], input[id*="number_of_government"], input[id*="number_of_international_academic"], input[id*="number_of_outreach"]');
    section5Inputs.forEach(input => {
        const value = parseFloat(input.value) || 0;
        section5Total += value;
        
        // Update individual item score display
        const scoreDisplay = document.getElementById(input.id + '_score');
        if (scoreDisplay) {
            scoreDisplay.textContent = value.toFixed(2);
        }
    });
    
    // Cap at 75
    section5Total = Math.min(section5Total, 75);
    
    // Update displays
    document.getElementById('section_5_total_display').textContent = section5Total.toFixed(2) + ' / 75';
    document.getElementById('expert_section_5_total').value = section5Total;
    document.getElementById('display_expert_section_5').textContent = section5Total.toFixed(2);
    
    const autoScore5 = <?php echo $section_5_auto_total; ?>;
    const diff5 = section5Total - autoScore5;
    document.getElementById('section_5_diff_display').textContent = diff5.toFixed(2);
    document.getElementById('display_diff_section_5').textContent = diff5.toFixed(2);
    
    // Recalculate grand total
    recalculateGrandTotal();
}

// Attach to global recalculate function
if (typeof window.sectionCalculators === 'undefined') {
    window.sectionCalculators = [];
}
window.sectionCalculators.push(recalculateSection5);
</script>

