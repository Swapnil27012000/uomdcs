<?php
/**
 * Section I: Faculty Output & Research - COMPLETE Implementation
 * All 26 items with verification fields
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

// Set readonly mode flag (used throughout the file)
$readonly_mode = isset($is_readonly) && $is_readonly || isset($is_chairman_view) && $is_chairman_view;

// Get Section 1 data
$sec1 = $dept_data['section_1'] ?? [];
$sec_a = $dept_data['section_a'] ?? []; // For faculty counts

// CRITICAL: If section 1 data is empty or missing description fields, try direct database query
// This is a fallback to ensure we get the data even if there's an issue with the data fetcher
// Check if description fields are missing or only contain "-"
$has_desc_data = false;
if (!empty($sec1)) {
    $desc_fields = ['desc_initiative', 'desc_impact', 'desc_collaboration', 'desc_plan', 'desc_recognition'];
    foreach ($desc_fields as $field) {
        // Check if field exists and has actual content (not just "-" or empty)
        if (isset($sec1[$field])) {
            $field_value = trim($sec1[$field]);
            if ($field_value !== '' && $field_value !== '-') {
                $has_desc_data = true;
                error_log("[Section1 Faculty Output] Found valid data in field $field: \"" . substr($field_value, 0, 50) . "\"");
                break;
            } else {
                error_log("[Section1 Faculty Output] Field $field is empty or '-': \"$field_value\"");
            }
        } else {
            error_log("[Section1 Faculty Output] Field $field is NOT SET in sec1");
        }
    }
} else {
    error_log("[Section1 Faculty Output] sec1 is EMPTY");
}

if (empty($sec1) || !$has_desc_data) {
    error_log("[Section1 Faculty Output] WARNING - sec1 is empty or missing description fields, trying direct DB query...");
    global $conn;
    // Get dept_id from review_complete.php scope or try to extract it
    $fallback_dept_id = isset($dept_id) ? $dept_id : (isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : null);
    
    if ($fallback_dept_id) {
        // Try to get the data directly from database (get most recent record)
        // Only select columns that exist - removed startups_incubated as it may not exist in all database versions
        // Include ALL startup-related fields to ensure we can parse them correctly
        $direct_query = "SELECT desc_initiative, desc_impact, desc_collaboration, desc_plan, desc_recognition, 
                        dpiit_startup_details, vc_investment_details, seed_funding_details, 
                        fdi_investment_details, innovation_grants_details, trl_innovations_details, 
                        turnover_achievements_details, forbes_alumni_details, A_YEAR 
                        FROM faculty_output WHERE DEPT_ID = ? ORDER BY A_YEAR DESC LIMIT 1";
        $direct_stmt = $conn->prepare($direct_query);
        if ($direct_stmt) {
            $direct_stmt->bind_param("i", $fallback_dept_id);
            $direct_stmt->execute();
            $direct_result = $direct_stmt->get_result();
            if ($direct_row = $direct_result->fetch_assoc()) {
                error_log("[Section1 Faculty Output] Found data with direct query - A_YEAR: " . ($direct_row['A_YEAR'] ?? 'NULL'));
                // Merge the direct query results with existing sec1 data (direct query takes precedence)
                $sec1 = array_merge($sec1 ?: [], $direct_row);
                
                // CRITICAL: Re-parse startup data from the direct query results
                // This ensures Forbes Alumni and other startup types are included
                $startup_list_parsed = [];
                
                // DPIIT startups
                if (!empty($sec1['dpiit_startup_details'])) {
                    $dpiit_list = json_decode($sec1['dpiit_startup_details'], true) ?: [];
                    foreach ($dpiit_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'DPIIT Recognition';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // VC investments
                if (!empty($sec1['vc_investment_details'])) {
                    $vc_list = json_decode($sec1['vc_investment_details'], true) ?: [];
                    foreach ($vc_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'VC Investment';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // Seed funding
                if (!empty($sec1['seed_funding_details'])) {
                    $seed_list = json_decode($sec1['seed_funding_details'], true) ?: [];
                    foreach ($seed_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'Seed Funding';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // FDI investments
                if (!empty($sec1['fdi_investment_details'])) {
                    $fdi_list = json_decode($sec1['fdi_investment_details'], true) ?: [];
                    foreach ($fdi_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'FDI Investment';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // Innovation grants
                if (!empty($sec1['innovation_grants_details'])) {
                    $grant_list = json_decode($sec1['innovation_grants_details'], true) ?: [];
                    foreach ($grant_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'Innovation Grant';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // TRL innovations
                if (!empty($sec1['trl_innovations_details'])) {
                    $trl_list = json_decode($sec1['trl_innovations_details'], true) ?: [];
                    foreach ($trl_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'TRL Innovation';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // Turnover achievements
                if (!empty($sec1['turnover_achievements_details'])) {
                    $turnover_list = json_decode($sec1['turnover_achievements_details'], true) ?: [];
                    foreach ($turnover_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'Turnover Achievement';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // Forbes alumni - CRITICAL: Parse this correctly
                if (!empty($sec1['forbes_alumni_details'])) {
                    $forbes_list = json_decode($sec1['forbes_alumni_details'], true) ?: [];
                    error_log("[Section1 Faculty Output] Forbes alumni raw: " . substr($sec1['forbes_alumni_details'], 0, 200));
                    error_log("[Section1 Faculty Output] Forbes alumni decoded: " . (is_array($forbes_list) ? count($forbes_list) . " items" : "NOT ARRAY"));
                    if (is_array($forbes_list) && !empty($forbes_list)) {
                        foreach ($forbes_list as $item) {
                            if (is_array($item)) {
                                // Include entry if it has at least one non-empty, non-"-" field
                                // Allow year fields (year_of_passing, year_founded) even if they're "0" or numeric
                                $has_valid_data = false;
                                foreach ($item as $key => $val) {
                                    $clean_val = trim((string)$val);
                                    $key_lower = strtolower($key);
                                    // Allow year fields and text fields, but exclude system fields
                                    if (!in_array($key_lower, ['id', 'dept_id', 'a_year', 'serial_number'])) {
                                        // For year fields, accept any numeric value (including "0")
                                        if (in_array($key_lower, ['year_of_passing', 'year_founded', 'year_passing', 'year_founded'])) {
                                            if (is_numeric($clean_val) && $clean_val !== '') {
                                                $has_valid_data = true;
                                                break;
                                            }
                                        } else {
                                            // For text fields, require non-empty and not "-"
                                            if (!empty($clean_val) && $clean_val !== '-') {
                                                $has_valid_data = true;
                                                break;
                                            }
                                        }
                                    }
                                }
                                if ($has_valid_data) {
                                    $item['type'] = 'Forbes Alumni';
                                    $startup_list_parsed[] = $item;
                                    error_log("[Section1 Faculty Output] Added Forbes Alumni entry: " . json_encode($item));
                                } else {
                                    error_log("[Section1 Faculty Output] Skipped Forbes Alumni entry (no valid data): " . json_encode($item));
                                }
                            }
                        }
                    }
                }
                
                // Store parsed startup list
                $sec1['dpiit_startup_details_parsed'] = $startup_list_parsed;
                error_log("[Section1 Faculty Output] Total startup entries parsed: " . count($startup_list_parsed));
                
                // Log all description fields from direct query
                error_log("[Section1 Faculty Output] desc_initiative from direct query: " . (isset($sec1['desc_initiative']) ? ('"' . substr($sec1['desc_initiative'], 0, 50) . '" (length: ' . strlen($sec1['desc_initiative']) . ')') : 'NOT SET'));
                error_log("[Section1 Faculty Output] desc_impact from direct query: " . (isset($sec1['desc_impact']) ? ('"' . substr($sec1['desc_impact'], 0, 50) . '" (length: ' . strlen($sec1['desc_impact']) . ')') : 'NOT SET'));
                error_log("[Section1 Faculty Output] desc_collaboration from direct query: " . (isset($sec1['desc_collaboration']) ? ('"' . substr($sec1['desc_collaboration'], 0, 50) . '" (length: ' . strlen($sec1['desc_collaboration']) . ')') : 'NOT SET'));
                error_log("[Section1 Faculty Output] desc_plan from direct query: " . (isset($sec1['desc_plan']) ? ('"' . substr($sec1['desc_plan'], 0, 50) . '" (length: ' . strlen($sec1['desc_plan']) . ')') : 'NOT SET'));
                error_log("[Section1 Faculty Output] desc_recognition from direct query: " . (isset($sec1['desc_recognition']) ? ('"' . substr($sec1['desc_recognition'], 0, 50) . '" (length: ' . strlen($sec1['desc_recognition']) . ')') : 'NOT SET'));
            } else {
                error_log("[Section1 Faculty Output] Direct query returned no results for dept_id: $fallback_dept_id");
            }
            $direct_stmt->close();
        }
    } else {
        error_log("[Section1 Faculty Output] Cannot perform direct query - dept_id not available");
    }
}

// CRITICAL DEBUG: Log what we received in $sec1
error_log("[Section1 Faculty Output] DEBUG - sec1 keys: " . implode(', ', array_keys($sec1)));
error_log("[Section1 Faculty Output] DEBUG - sec1 desc_initiative: " . (isset($sec1['desc_initiative']) ? ('"' . substr($sec1['desc_initiative'], 0, 50) . '" (length: ' . strlen($sec1['desc_initiative']) . ')') : 'NOT SET'));
error_log("[Section1 Faculty Output] DEBUG - sec1 desc_impact: " . (isset($sec1['desc_impact']) ? ('"' . substr($sec1['desc_impact'], 0, 50) . '" (length: ' . strlen($sec1['desc_impact']) . ')') : 'NOT SET'));
error_log("[Section1 Faculty Output] DEBUG - sec1 desc_collaboration: " . (isset($sec1['desc_collaboration']) ? ('"' . substr($sec1['desc_collaboration'], 0, 50) . '" (length: ' . strlen($sec1['desc_collaboration']) . ')') : 'NOT SET'));
error_log("[Section1 Faculty Output] DEBUG - sec1 desc_plan: " . (isset($sec1['desc_plan']) ? ('"' . substr($sec1['desc_plan'], 0, 50) . '" (length: ' . strlen($sec1['desc_plan']) . ')') : 'NOT SET'));
error_log("[Section1 Faculty Output] DEBUG - sec1 desc_recognition: " . (isset($sec1['desc_recognition']) ? ('"' . substr($sec1['desc_recognition'], 0, 50) . '" (length: ' . strlen($sec1['desc_recognition']) . ')') : 'NOT SET'));

// Include renderer functions
require_once('section_renderer.php');
?>

<!-- Section I: Faculty Output, Research and Professional Activities -->
<div class="section-card">
    <h2 class="section-title">
        <i class="fas fa-graduation-cap"></i> Section I: Faculty Output, Research and Professional Activities
        <span class="badge bg-primary float-end">Maximum: 300 Marks</span>
    </h2>
    
    <?php if (!isset($is_department_view) || !$is_department_view): ?>
    <div class="info-box">
        <p><strong>Instructions:</strong> Review each item carefully. Check department-submitted values against supporting documents. Enter your verified score in the "Expert Input" column. The system will calculate your expert score automatically.</p>
    </div>
    <?php endif; ?>

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
    // Item 1: Permanent Faculties with PhD (1 mark each, max 10)
    $perm_phd = (int)($sec_a['NUM_PERM_PHD'] ?? 0);
    $auto_score_1 = min($perm_phd * 1, 10);
    // Match by exact document title: "Permanent Faculties with PhD Documentation"
    $docs_1 = getDocumentsForSection($grouped_docs, ['details_of_department', 'details_dept', 'brief_details'], ['permanent', 'phd', 'faculty'], 1);
    renderVerifiableItem(
        1,
        'Number of Permanent Faculties with Ph.D (1 mark each, Max 10)',
        $perm_phd,
        $auto_score_1,
        10,
        $docs_1,
        $is_locked
    );

    // Item 2: Adhoc teachers with PhD (0.5 marks each, max 5)
    $adhoc_phd = (int)($sec_a['NUM_ADHOC_PHD'] ?? 0);
    $auto_score_2 = min($adhoc_phd * 0.5, 5);
    // Match by exact document title: "Adhoc Faculties with PhD Documentation"
    $docs_2 = getDocumentsForSection($grouped_docs, ['details_of_department', 'details_dept', 'brief_details'], ['adhoc', 'phd', 'faculty'], 2);
    renderVerifiableItem(
        2,
        'Number of Adhoc teachers with PhD (0.5 marks each, Max 5)',
        $adhoc_phd,
        $auto_score_2,
        5,
        $docs_2,
        $is_locked
    );

    // Item 3: State Level Awards (2 marks each, max 10)
    $awards = json_decode($sec1['awards'] ?? '[]', true);
    // CRITICAL: Filter awards by level - only show State level awards
    $state_awards_filtered = is_array($awards) ? array_filter($awards, function($a) { 
        return isset($a['level']) && strtolower(trim($a['level'])) == 'state'; 
    }) : [];
    $state_awards = count($state_awards_filtered);
    $auto_score_3 = min($state_awards * 2, 10);
    // CRITICAL: Use serial number 1 (from FacultyOutput.php: awards_state_govt uses srno: 1)
    // Match by exact document title: "State Level Awards Documentation"
    $docs_state_awards = getDocumentsForSection($grouped_docs, 'faculty_output', ['state level awards'], 1);
    // CRITICAL: Pass only filtered State awards, not all awards
    renderJSONArrayItems(
        3,
        'Full-time teachers who received awards at State Level (2 marks each, Max 10)',
        json_encode(array_values($state_awards_filtered)), // Only State level awards
        $auto_score_3,
        10,
        $docs_state_awards,
        $is_locked
    );

    // Item 4: National Level Awards (3 marks each, max 15)
    // CRITICAL: Filter awards by level - only show National level awards
    $national_awards_filtered = is_array($awards) ? array_filter($awards, function($a) { 
        return isset($a['level']) && strtolower(trim($a['level'])) == 'national'; 
    }) : [];
    $national_awards = count($national_awards_filtered);
    $auto_score_4 = min($national_awards * 3, 15);
    // CRITICAL: Use serial number 2 (from FacultyOutput.php: awards_national_govt uses srno: 2)
    // Match by exact document title: "National Level Awards Documentation"
    $docs_national_awards = getDocumentsForSection($grouped_docs, 'faculty_output', ['national level awards'], 2);
    // CRITICAL: Pass only filtered National awards, not all awards
    renderJSONArrayItems(
        4,
        'Full-time teachers who received awards at National Level (3 marks each, Max 15)',
        json_encode(array_values($national_awards_filtered)), // Only National level awards
        $auto_score_4,
        15,
        $docs_national_awards,
        $is_locked
    );

    // Item 5: International Level Awards (5 marks each, max 20)
    // CRITICAL: Filter awards by level - only show International level awards (NOT fellowships)
    $intl_awards_filtered = is_array($awards) ? array_filter($awards, function($a) { 
        $level = isset($a['level']) ? strtolower(trim($a['level'])) : '';
        $type = isset($a['type']) ? strtolower(trim($a['type'])) : '';
        // Must be international level AND NOT a fellowship
        return $level == 'international' && $type != 'fellowship'; 
    }) : [];
    $intl_awards = count($intl_awards_filtered);
    $auto_score_5 = min($intl_awards * 5, 20);
    // CRITICAL: Use serial number 3 (from FacultyOutput.php: awards_international_govt uses srno: 3)
    // Match by exact document title: "International Level Awards Documentation" (NOT Fellowship)
    $docs_intl_awards = getDocumentsForSection($grouped_docs, 'faculty_output', ['international level awards'], 3);
    // CRITICAL: Pass only filtered International awards, not all awards
    renderJSONArrayItems(
        5,
        'Full-time teachers who received awards at International Level (5 marks each, Max 20)',
        json_encode(array_values($intl_awards_filtered)), // Only International level awards (not fellowships)
        $auto_score_5,
        20,
        $docs_intl_awards,
        $is_locked
    );

    // Item 6: International Fellowships (3 marks each, max 15)
    // CRITICAL: Filter awards by type and level - only show International Fellowships
    $fellowships_filtered = is_array($awards) ? array_filter($awards, function($a) { 
        return isset($a['type']) && strtolower(trim($a['type'])) == 'fellowship' && 
               isset($a['level']) && strtolower(trim($a['level'])) == 'international'; 
    }) : [];
    $fellowships = count($fellowships_filtered);
    $auto_score_6 = min($fellowships * 3, 15);
    // CRITICAL: Use serial number 4 (from FacultyOutput.php: awards_international_fellowship uses srno: 4)
    // Match by exact document title: "International Fellowship Documentation"
    $docs_fellowship = getDocumentsForSection($grouped_docs, 'faculty_output', ['international fellowship'], 4);
    // CRITICAL: Pass only filtered Fellowships, not all awards
    renderJSONArrayItems(
        6,
        'Number of teachers awarded international fellowship (3 marks each, Max 15)',
        json_encode(array_values($fellowships_filtered)), // Only International Fellowships
        $auto_score_6,
        15,
        $docs_fellowship,
        $is_locked
    );

    // Item 7: PhDs awarded (2 marks each, max 10)
    $phd_awarded = (int)($dept_data['section_4']['phd']['phd_awarded_count'] ?? 0);
    $auto_score_7 = min($phd_awarded * 2, 10);
    // Match by exact document title: "PhD Awardees Documentation"
    $docs_phd_awarded = getDocumentsForSection($grouped_docs, ['phd_details', 'phd'], ['phd', 'awardee', 'awarded'], 7);
    
    // Get PhD awardees list
    $phd_awardees_list = $dept_data['section_4']['phd']['phd_awardees_parsed'] ?? [];
    
    renderJSONArrayItems(
        7,
        'Number of Ph.D\'s awarded at the Department (2 marks each, Max 10)',
        json_encode($phd_awardees_list),
        $auto_score_7,
        10,
        $docs_phd_awarded,
        $is_locked
    );

    // Item 8: Non-government sponsored projects (2 marks each, max 20)
    $projects = json_decode($sec1['projects'] ?? '[]', true);
    // CRITICAL: Projects use 'type' field with values: "Non-Govt-Sponsored", "Govt-Sponsored", "Consultancy"
    $non_govt_projects_filtered = is_array($projects) ? array_filter($projects, function($p) { 
        $type = strtolower(trim($p['type'] ?? ''));
        return $type == 'non-govt-sponsored' || $type == 'non_govt_sponsored' || $type == 'non-government sponsored research';
    }) : [];
    $non_govt_projects = count($non_govt_projects_filtered);
    $auto_score_8 = min($non_govt_projects * 2, 20);
    // CRITICAL: Use serial number 5 (from FacultyOutput.php: projects_non_govt uses srno: 5)
    $docs_non_govt = getDocumentsForSection($grouped_docs, 'faculty_output', [], 5);
    // CRITICAL: Pass only filtered Non-Government projects, not all projects
    renderJSONArrayItems(
        8,
        'Research projects sponsored by non-government sources (2 marks each, Max 20)',
        json_encode(array_values($non_govt_projects_filtered)), // Only Non-Government projects
        $auto_score_8,
        20,
        $docs_non_govt,
        $is_locked
    );

    // Item 9: Government sponsored projects (2 marks each, max 20)
    // CRITICAL: Projects use 'type' field with values: "Non-Govt-Sponsored", "Govt-Sponsored", "Consultancy"
    $govt_projects_filtered = is_array($projects) ? array_filter($projects, function($p) { 
        $type = strtolower(trim($p['type'] ?? ''));
        return $type == 'govt-sponsored' || $type == 'govt_sponsored' || $type == 'government sponsored research';
    }) : [];
    $govt_projects = count($govt_projects_filtered);
    $auto_score_9 = min($govt_projects * 2, 20);
    // CRITICAL: Use serial number 6 (from FacultyOutput.php: projects_govt uses srno: 6)
    $docs_govt = getDocumentsForSection($grouped_docs, 'faculty_output', [], 6);
    // CRITICAL: Pass only filtered Government projects, not all projects
    renderJSONArrayItems(
        9,
        'Grants for research projects sponsored by government sources (2 marks each, Max 20)',
        json_encode(array_values($govt_projects_filtered)), // Only Government projects
        $auto_score_9,
        20,
        $docs_govt,
        $is_locked
    );

    // Item 10: Consultancy Projects (1 mark per 1 lakh, max 30)
    // CRITICAL: Consultancy projects are in the projects array with type = "Consultancy"
    $consultancy_projects = is_array($projects) ? array_filter($projects, function($p) { 
        $type = strtolower(trim($p['type'] ?? ''));
        return $type == 'consultancy';
    }) : [];
    // Sum amounts from consultancy projects (amount is in lakhs)
    $consultancy_amount = 0.0;
    foreach ($consultancy_projects as $cp) {
        $amount = (float)($cp['amount'] ?? 0);
        $consultancy_amount += $amount;
    }
    $auto_score_10 = min($consultancy_amount, 30);
    // CRITICAL: Use serial number 7 (from FacultyOutput.php: projects_consultancy uses srno: 7)
    $docs_consultancy = getDocumentsForSection($grouped_docs, 'faculty_output', [], 7);
    renderVerifiableItem(
        10,
        'Consultancy Projects - Amount in INR Lakhs (1 mark per 1 lakh, Max 30)',
        number_format($consultancy_amount, 2) . ' Lakhs',
        $auto_score_10,
        30,
        $docs_consultancy,
        $is_locked
    );

    // Item 11: Corporate training programs (1 mark each, max 10)
    $trainings = json_decode($sec1['trainings'] ?? '[]', true);
    $training_count = is_array($trainings) ? count($trainings) : 0;
    $auto_score_11 = min($training_count, 10);
    // CRITICAL: Use serial number 8 (from FacultyOutput.php: training_corporate uses srno: 8)
    $docs_training = getDocumentsForSection($grouped_docs, 'faculty_output', [], 8);
    renderJSONArrayItems(
        11,
        'Number of corporate training programs organised (1 mark each, Max 10)',
        $sec1['trainings'] ?? '[]',
        $auto_score_11,
        10,
        $docs_training,
        $is_locked
    );

    // Item 12: UGC-SAP, CAS, DST-FIST recognitions + Infrastructure funding
    // CRITICAL: Recognitions are stored in 'recognitions' field as JSON array using encodeFacultyJson
    $recognitions_text = $sec1['recognitions'] ?? '';
    $recognitions_list = [];
    
    // Debug: Log recognitions data
    error_log("[Expert Review] Recognitions raw: " . var_export($recognitions_text, true));
    
    if (!empty($recognitions_text) && $recognitions_text !== '[]' && $recognitions_text !== 'null') {
        // Check if it's JSON array
        $recognitions_array = json_decode($recognitions_text, true);
        error_log("[Expert Review] Recognitions decoded: " . (is_array($recognitions_array) ? count($recognitions_array) . " items: " . json_encode($recognitions_array) : "NOT ARRAY"));
        
        if (is_array($recognitions_array) && !empty($recognitions_array)) {
            // Filter out empty values and keep only valid recognitions (UGC-SAP, UGC-CAS, DST-FIST, DBT, ICSSR)
            $valid_recognitions = array_filter($recognitions_array, function($r) {
                $r_lower = strtolower(trim($r));
                $is_valid = !empty($r) && (
                    stripos($r_lower, 'ugc-sap') !== false || 
                    stripos($r_lower, 'ugc-cas') !== false || 
                    stripos($r_lower, 'cas') !== false ||
                    stripos($r_lower, 'dst-fist') !== false || 
                    stripos($r_lower, 'dbt') !== false || 
                    stripos($r_lower, 'icssr') !== false
                );
                if (!$is_valid && !empty($r)) {
                    error_log("[Expert Review] Recognition '$r' is not a valid recognition type (UGC-SAP, UGC-CAS, DST-FIST, DBT, ICSSR)");
                }
                return $is_valid;
            });
            
            // Convert to array format for renderJSONArrayItems
            foreach ($valid_recognitions as $rec) {
                $recognitions_list[] = [
                    'name' => $rec,
                    'recognition' => $rec,
                    'type' => 'Recognition'
                ];
            }
            error_log("[Expert Review] Valid recognitions count: " . count($recognitions_list));
        }
    }
    
    // CRITICAL: Infrastructure funding column is 'infra_funding' (not 'infrastructure_fund')
    $infra_fund = (float)($sec1['infra_funding'] ?? 0);
    
    // Add infrastructure funding as a separate entry if > 0
    if ($infra_fund > 0) {
        $recognitions_list[] = [
            'name' => 'Infrastructure Funding',
            'amount_lakhs' => number_format($infra_fund, 2),
            'type' => 'Infrastructure Funding'
        ];
    }
    
    $recognitions_count = count(array_filter($recognitions_list, function($r) {
        return ($r['type'] ?? '') === 'Recognition';
    }));
    $auto_score_12 = min($recognitions_count * 2.5, 5) + min(($infra_fund / 25) * 2, 10);
    
    // CRITICAL: Use serial number 9 (from FacultyOutput.php: recognitions_infra uses srno: 9)
    $docs_recognitions = getDocumentsForSection($grouped_docs, 'faculty_output', [], 9);
    
    renderJSONArrayItems(
        12,
        'UGC-SAP/CAS/DST-FIST/DBT/ICSSR recognitions (2.5 marks each, Max 5) + Infrastructure funding (2 marks per 25 lakhs, Max 10) = Max 15',
        json_encode($recognitions_list),
        $auto_score_12,
        15,
        $docs_recognitions,
        $is_locked
    );

    // Item 13: Start-ups incubated (2 marks each, max 10)
    // Get startup details list first
    $startup_list = $sec1['dpiit_startup_details_parsed'] ?? [];
    
    // Calculate count from the actual parsed list (more accurate)
    $startups_count = count($startup_list);
    
    // Also check database field as fallback
    $startups_db = (int)($sec1['startups_incubated'] ?? 0);
    
    // Use the higher of the two counts to ensure accuracy
    $startups = max($startups_count, $startups_db);
    
    $auto_score_13 = min($startups * 2, 10);
    
    error_log("[Expert Review] Item 13 - Startup count from list: $startups_count, from DB: $startups_db, final: $startups, auto_score: $auto_score_13");
    
    // Get all supporting documents grouped by type
    $docs_dpiit = getDocumentsForSection($grouped_docs, 'faculty_output', ['dpiit', 'recognition', 'certificate'], 10);
    $docs_investment = getDocumentsForSection($grouped_docs, 'faculty_output', ['investment', 'agreement', 'funding', 'proof'], 11);
    $docs_grants = getDocumentsForSection($grouped_docs, 'faculty_output', ['grant', 'government', 'letter'], 12);
    $docs_trl = getDocumentsForSection($grouped_docs, 'faculty_output', ['trl', 'technology', 'readiness', 'level'], 13);
    $docs_turnover = getDocumentsForSection($grouped_docs, 'faculty_output', ['turnover', 'certificate', 'financial', 'statement'], 14);
    $docs_alumni = getDocumentsForSection($grouped_docs, 'faculty_output', ['alumni', 'founder', 'verification', 'forbes'], 15);
    
    // Debug: Log document counts
    error_log("[Expert Review] Item 13 - Document counts: DPIIT=" . count($docs_dpiit) . ", Investment=" . count($docs_investment) . ", Grants=" . count($docs_grants) . ", TRL=" . count($docs_trl) . ", Turnover=" . count($docs_turnover) . ", Alumni=" . count($docs_alumni));
    if (count($docs_alumni) > 0) {
        error_log("[Expert Review] Item 13 - Alumni documents found: " . json_encode(array_map(function($d) { return ['title' => $d['document_title'] ?? '', 'serial' => $d['serial_number'] ?? '', 'section' => $d['page_section'] ?? '']; }, $docs_alumni)));
    }
    
    // Render with custom format: all data first, then all documents grouped by heading
    renderStartupsWithGroupedDocuments(
        13,
        'Number of start-ups incubated (2 marks each, Max 10)',
        json_encode($startup_list),
        $auto_score_13,
        10,
        [
            'DPIIT Recognition Certificates' => $docs_dpiit,
            'Investment Agreements & Proof of Funding' => $docs_investment,
            'Government Grant Letters' => $docs_grants,
            'Technology Readiness Level Documentation' => $docs_trl,
            'Turnover Certificates & Financial Statements' => $docs_turnover,
            'Alumni Founder Verification Documents' => $docs_alumni
        ],
        $is_locked
    );

    // Item 14: Patents/IPR (3 marks each, max 15)
    $patents = (int)($sec1['patents_count'] ?? 0);
    $auto_score_14 = min($patents * 3, 15);
    
    // Get patent details list
    $patent_list = $sec1['patent_details_parsed'] ?? [];
    
    // Callback function to get entry-specific documents for patents
    // Maps each patent status to its specific document serial number
    $getPatentEntryDocs = function($item, $item_number) use ($grouped_docs) {
        $patent_status = strtolower(trim($item['status'] ?? $item['DGF Status of Patent'] ?? ''));
        $serial_number = null;
        
        // Map patent status to document serial numbers (from FacultyOutput.php)
        if (stripos($patent_status, 'filed') !== false) {
            $serial_number = 17; // Patents Filed Documentation
        } elseif (stripos($patent_status, 'published') !== false) {
            $serial_number = 18; // Patents Published Documentation
        } elseif (stripos($patent_status, 'granted') !== false) {
            $serial_number = 19; // Patents Granted Documentation
        } else {
            // Fallback to general IPR documentation
            $serial_number = 16; // IPR (Patents/Copyright/Trademarks/Designs) Documentation
        }
        
        if ($serial_number !== null) {
            return getDocumentsForSection($grouped_docs, 'faculty_output', [], $serial_number);
        }
        
        // Fallback: return empty array if no match
        return [];
    };
    
    // Get general supporting documents for patents (fallback)
    $patent_docs = getDocumentsForSection($grouped_docs, 'faculty_output', [], 16);
    
    renderJSONArrayItems(
        14,
        'Number of IPR (Patents/Copyright/Trademarks) Filed/Published/Awarded (3 marks each, Max 15)',
        json_encode($patent_list),
        $auto_score_14,
        15,
        $patent_docs,
        $is_locked,
        $getPatentEntryDocs
    );

    // Item 15: Scopus/Web of Science papers (2 marks each, max 30)
    $publications = json_decode($sec1['publications'] ?? '[]', true);
    
    // Handle case where publications might be stored as string instead of JSON
    if (is_string($publications)) {
        $publications = json_decode($publications, true);
    }
    
    // If still not an array, try to decode the raw value
    if (!is_array($publications) && !empty($sec1['publications'])) {
        $raw_pub = $sec1['publications'];
        if (is_string($raw_pub)) {
            $publications = json_decode($raw_pub, true);
        }
    }
    
    // Final fallback
    if (!is_array($publications)) {
        $publications = [];
    }
    
    // Debug: Log all publications for troubleshooting
    if (is_array($publications) && !empty($publications)) {
        error_log("[Item 15/20] Total publications found: " . count($publications));
        error_log("[Item 15/20] Raw publications JSON: " . substr($sec1['publications'] ?? 'NULL', 0, 500));
        foreach ($publications as $idx => $pub) {
            error_log("[Item 15/20] Publication $idx: " . json_encode($pub));
        }
    } else {
        error_log("[Item 15/20] No publications found. Raw value type: " . gettype($sec1['publications'] ?? null));
        error_log("[Item 15/20] Raw value (first 500 chars): " . substr($sec1['publications'] ?? 'NULL', 0, 500));
    }
    // CRITICAL: Filter Scopus/Web of Science publications before displaying
    // Dropdown value: "Journal" = Journal (Scopus/Web of Sciences) - goes to Item 15
    $scopus_papers_filtered = is_array($publications) ? array_filter($publications, function($p) { 
        // Check 'type' field (from dept_login dropdown: "Journal" = Scopus/Web of Science)
        $type = trim($p['type'] ?? $p['journal_type'] ?? '');
        
        // Simple check: if type is exactly "Journal", it's Scopus/Web of Science (Item 15)
        // Exclude Conference (Item 16) and ISSN_Journals (Item 20)
        return $type === 'Journal';
    }) : [];
    $scopus_papers = count($scopus_papers_filtered);
    $auto_score_15 = min($scopus_papers * 2, 30);
    // CRITICAL: Use serial number 22 (from FacultyOutput.php: publications_scopus uses srno: 22)
    $docs_scopus = getDocumentsForSection($grouped_docs, 'faculty_output', [], 22);
    // CRITICAL: Pass only filtered Scopus/Web of Science publications, not all publications
    renderJSONArrayItems(
        15,
        'Research papers published in Journals (Scopus/Web of Science) (2 marks each, Max 30)',
        json_encode(array_values($scopus_papers_filtered)), // Only Scopus/Web of Science publications
        $auto_score_15,
        30,
        $docs_scopus,
        $is_locked
    );

    // Item 16: Conference papers (1 mark each, max 15)
    // CRITICAL: Filter Conference publications before displaying
    $conf_papers_filtered = is_array($publications) ? array_filter($publications, function($p) { 
        $type = strtolower(trim($p['type'] ?? ''));
        return $type == 'conference';
    }) : [];
    $conf_papers = count($conf_papers_filtered);
    $auto_score_16 = min($conf_papers, 15);
    // CRITICAL: Use serial number 23 (from FacultyOutput.php: publications_conference uses srno: 23)
    $docs_conference = getDocumentsForSection($grouped_docs, 'faculty_output', [], 23);
    // CRITICAL: Pass only filtered Conference publications, not all publications
    renderJSONArrayItems(
        16,
        'Research papers published in Conferences (1 mark each, Max 15)',
        json_encode(array_values($conf_papers_filtered)), // Only Conference publications
        $auto_score_16,
        15,
        $docs_conference,
        $is_locked
    );

    // Item 17-19: Bibliometrics (Impact Factor, Citations, h-index)
    // Calculate from bibliometrics JSON array (sum of all values)
    $bibliometrics_raw = $sec1['bibliometrics'] ?? '[]';
    $bibliometrics = json_decode($bibliometrics_raw, true);
    
    // Debug: Log raw bibliometrics data
    error_log("[Expert Review] Bibliometrics raw: " . substr($bibliometrics_raw, 0, 500));
    error_log("[Expert Review] Bibliometrics decoded: " . (is_array($bibliometrics) ? count($bibliometrics) . " items" : "NOT ARRAY"));
    if (is_array($bibliometrics) && !empty($bibliometrics)) {
        error_log("[Expert Review] First bibliometric entry: " . json_encode($bibliometrics[0] ?? []));
    }
    
    $impact_factor = 0.0;
    $total_citations = 0;
    $h_index_total = 0.0;
    $impact_display_total = 0.0;
    $citations_display_total = 0;
    $h_index_display_total = 0.0;
    $bibliometrics_list = []; // Store for display (with parsed values, not raw)
    
    // Helper function to safely parse numeric values - CRITICAL: Must handle string concatenation
    // Added max_limit parameter for different value types
    $parseNumeric = function($value, $max_limit = 10000) {
        if ($value === null || $value === '' || $value === false) return 0.0;
        
        // CRITICAL: If value is already a number, check limits first
        if (is_numeric($value)) {
            $num = (float)$value;
            // Sanity check: clamp values exceeding the limit
            if ($num > $max_limit) {
                error_log("[Expert Review] WARNING: Value exceeds limit ($max_limit): $num (original: $value) - clamping to limit");
                return (float)$max_limit;
            }
            return $num;
        }
        
        // Convert to string and clean
        $cleaned = trim((string)$value);
        $original_cleaned = $cleaned;
        
        // Remove ALL commas first (they might be in numbers like "1,234.56")
        $cleaned = str_replace(',', '', $cleaned);
        
        // Remove other non-numeric characters except decimal point and minus
        $cleaned = preg_replace('/[^0-9.\-]/', '', $cleaned);
        
        // If empty after cleaning, return 0
        if (empty($cleaned) || $cleaned === '-' || $cleaned === '.') {
            return 0.0;
        }
        
        // Convert to float
        $parsed = (float)$cleaned;
        
        // Sanity check: clamp values exceeding the limit
        if ($parsed > $max_limit) {
            error_log("[Expert Review] WARNING: Parsed value exceeds limit ($max_limit): $parsed (original: '$value', cleaned: '$cleaned') - clamping to limit");
            return (float)$max_limit;
        }
        
        return $parsed;
    };
    
    // Helper to parse raw values for display purposes (no clamping)
    $parseForDisplay = function($value) {
        if ($value === null || $value === '' || $value === false) return 0.0;
        $cleaned = (string)$value;
        $cleaned = str_replace([',', ' ', '$', '%'], '', $cleaned);
        if (empty($cleaned) || $cleaned === '-' || $cleaned === '.') return 0.0;
        return (float)$cleaned;
    };
    
    if (is_array($bibliometrics) && !empty($bibliometrics)) {
        foreach ($bibliometrics as $index => $bib) {
            if (!is_array($bib)) {
                error_log("[Expert Review] WARNING: Bibliometric entry $index is not an array: " . gettype($bib) . " - " . var_export($bib, true));
                continue;
            }
            
            // Debug: Log the entire entry first
            error_log("[Expert Review] Bibliometric entry $index: " . json_encode($bib));
            
            // Extract values (check multiple possible field names from FacultyOutput.php)
            // Field names in FacultyOutput.php: teacher_name_X, impact_factor_X, citations_X, h_index_X
            $name = trim($bib['teacher_name'] ?? $bib['name'] ?? $bib['Name'] ?? $bib['teacher_name_1'] ?? "Teacher " . ($index + 1));
            $impact_val_raw = $bib['impact_factor'] ?? $bib['Impact Factor'] ?? $bib['impactFactor'] ?? $bib['cumulative_impact_factor'] ?? '';
            $citations_val_raw = $bib['citations'] ?? $bib['total_citations'] ?? $bib['Citations'] ?? $bib['Total Citations'] ?? '';
            $h_index_val_raw = $bib['h_index'] ?? $bib['h-index'] ?? $bib['H-Index'] ?? $bib['hIndex'] ?? '';
            
            // CRITICAL: Parse each value individually with appropriate limits FOR CALCULATION ONLY
            // Impact Factor: typically < 100 (some high-impact journals can be 50-100, but rarely > 100)
            // Citations: can be large (thousands), but reject > 10,000,000 (likely concatenated)
            // h-index: typically < 1000 (most are < 200), reject > 10,000
            $impact_val_calc = $parseNumeric($impact_val_raw, 100); // Max 100 for Impact Factor (for calculation)
            $citations_val_calc = (int)$parseNumeric($citations_val_raw, 10000000); // Max 10M for Citations (for calculation)
            $h_index_val_calc = $parseNumeric($h_index_val_raw, 10000); // Max 10,000 for h-index (for calculation)
            
            // Debug: Log each entry with raw and parsed values
            error_log("[Expert Review] Teacher $index: name='$name'");
            error_log("  - Impact Factor: raw='" . var_export($impact_val_raw, true) . "' (type: " . gettype($impact_val_raw) . ") -> parsed=$impact_val_calc");
            error_log("  - Citations: raw='" . var_export($citations_val_raw, true) . "' (type: " . gettype($citations_val_raw) . ") -> parsed=$citations_val_calc");
            error_log("  - h-index: raw='" . var_export($h_index_val_raw, true) . "' (type: " . gettype($h_index_val_raw) . ") -> parsed=$h_index_val_calc");
            
            // Use parsed values for calculation (0 if invalid)
            $impact_factor = (float)$impact_factor + (float)$impact_val_calc;
            $total_citations = (int)$total_citations + (int)$citations_val_calc;
            $h_index_total = (float)$h_index_total + (float)$h_index_val_calc;
            
            $impact_display = $parseForDisplay($impact_val_raw);
            $citations_display = (int)$parseForDisplay($citations_val_raw);
            $h_index_display = $parseForDisplay($h_index_val_raw);
            
            $impact_display_total += $impact_display;
            $citations_display_total += $citations_display;
            $h_index_display_total += $h_index_display;
            
            // Store for display - show what department actually submitted
            $bibliometrics_list[] = [
                'name' => $name,
                'impact_factor' => $impact_display, // Show raw value (parsed but not rejected)
                'citations' => $citations_display, // Show raw value (parsed but not rejected)
                'h_index' => $h_index_display // Show raw value (parsed but not rejected)
            ];
        }
    }
    
    // Debug: Log final totals
    error_log("[Expert Review] Final totals - Impact: $impact_factor, Citations: $total_citations, h-index: $h_index_total");
    
    // Get supporting documents for bibliometrics - match exact document titles from FacultyOutput.php
    // Item 17 (Impact Factor): "Impact Factor & Bibliometrics Documentation" (serial 27)
    // Item 19 (h-index): "h-index Documentation" (serial 28)
    // CRITICAL: Use serial numbers only, no keywords
    $docs_impact_factor = getDocumentsForSection($grouped_docs, 'faculty_output', [], 27);
    $docs_h_index = getDocumentsForSection($grouped_docs, 'faculty_output', [], 28);
    // Citations use the same documents as Impact Factor
    $docs_citations = $docs_impact_factor;
    
    // Render bibliometrics items with individual teacher entries and documents
    // Helper function to render document links
    $renderDocLinks = function($documents) {
        if (empty($documents)) {
            return '<small class="text-muted">No supporting documents found.</small>';
        }
        
        $html = '<div class="mt-2">';
        $html .= '<small class="text-muted">Supporting Documents (' . count($documents) . '):</small>';
        $html .= '<div class="d-flex flex-wrap gap-2 mt-1">';
        
        foreach ($documents as $doc) {
            $doc_path = $doc['file_path'] ?? '';
            if (strpos($doc_path, '../') === 0) {
                $web_path = $doc_path;
            } elseif (strpos($doc_path, 'uploads/') === 0) {
                $web_path = '../' . $doc_path;
            } else {
                $web_path = '../' . ltrim($doc_path, '/');
            }
            
            $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
            $file_exists = file_exists($physical_path);
            $doc_title = htmlspecialchars($doc['document_title'] ?? 'View Document');
            $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
            
            $html .= '<a href="' . htmlspecialchars($web_path) . '" ';
            $html .= 'target="_blank" ';
            $html .= 'class="btn btn-sm btn-outline-primary ' . ($file_exists ? '' : 'disabled') . '" ';
            $html .= 'title="' . $doc_title . '"';
            if (!$file_exists) {
                $html .= ' onclick="alert(\'Document file not found. Please contact administrator.\'); return false;"';
            }
            $html .= '>';
            $html .= '<i class="fas fa-file-pdf"></i> ';
            $html .= (strlen($doc_name) > 30 ? substr($doc_name, 0, 30) . '...' : $doc_name);
            $html .= '</a>';
        }
        
        $html .= '</div></div>';
        return $html;
    };
    ?>
    <!-- Item 17: Impact Factor -->
    <div class="data-grid" <?php if ($readonly_mode): ?>style="grid-template-columns: 2fr 1fr 1fr 1fr;"<?php endif; ?> data-item="17">
        <div class="field-label">
            <strong>17. Cumulative IMPACT Factor per teacher based on JCR (1 mark per unit, Max 10)</strong>
            <?php if (!empty($bibliometrics_list)): ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#impact_factor_entries" aria-expanded="false">
                        <i class="fas fa-list"></i> View Teacher Details (<?php echo count($bibliometrics_list); ?>)
                    </button>
                    <div class="collapse mt-2" id="impact_factor_entries">
                        <div class="card card-body bg-light">
                            <?php foreach ($bibliometrics_list as $idx => $bib): ?>
                                <div class="mb-3 p-2 border rounded">
                                    <strong><?php echo ($idx + 1); ?>. <?php echo htmlspecialchars($bib['name']); ?></strong>
                                    <div class="mt-1">
                                        <span class="text-muted">Value: <?php echo number_format($bib['impact_factor'], 2); ?> (Impact Factor), <?php echo number_format($bib['citations']); ?> (Citations), <?php echo number_format($bib['h_index'], 2); ?> (h-index)</span>
                                    </div>
                                    <?php echo $renderDocLinks($docs_impact_factor); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (empty($bibliometrics_list) && !empty($docs_impact_factor)): ?>
                <?php echo $renderDocLinks($docs_impact_factor); ?>
            <?php endif; ?>
        </div>
        <div class="dept-value">
            <?php echo number_format($impact_display_total, 2); ?>
        </div>
        <div class="auto-score" data-auto-score="<?php echo min($impact_factor, 10); ?>">
            <?php echo number_format(min($impact_factor, 10), 2); ?> / 10.00
        </div>
        <?php 
        // Initialize saved_score_17 before use
        $saved_score_17 = 0;
        if (isset($expert_item_scores) && is_array($expert_item_scores) && isset($expert_item_scores['item_17_impact_factor'])) {
            $saved_score_17 = (float)$expert_item_scores['item_17_impact_factor'];
        }
        if (!$readonly_mode): 
        ?>
        <div class="expert-input">
            <input type="number" 
                   step="0.01" 
                   min="0" 
                   max="10" 
                   class="form-control expert-input" 
                   id="item_17_impact_factor"
                   name="expert_item_17"
                   value="<?php echo number_format($saved_score_17, 2); ?>"
                   data-initial-value="<?php echo $saved_score_17; ?>"
                   data-auto-score="<?php echo number_format(min($impact_factor, 10), 2); ?>"
                   <?php echo $is_locked ? 'readonly' : ''; ?>
                   onchange="updateItemScore(17, this.value)">
        </div>
        <?php endif; ?>
        <div class="expert-score" id="item_17_impact_factor_score">
            <?php 
            $display_score_17 = $readonly_mode ? ($saved_score_17 > 0 ? $saved_score_17 : min($impact_factor, 10)) : $saved_score_17;
            echo number_format($display_score_17, 2); 
            ?> / 10.00
        </div>
    </div>

    <!-- Item 18: Citations -->
    <div class="data-grid" <?php if ($readonly_mode): ?>style="grid-template-columns: 2fr 1fr 1fr 1fr;"<?php endif; ?> data-item="18">
        <div class="field-label">
            <strong>18. Bibliometrics - cumulative citations (1 mark per 100 citations, Max 20)</strong>
            <?php if (!empty($bibliometrics_list)): ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#citations_entries" aria-expanded="false">
                        <i class="fas fa-list"></i> View Teacher Details (<?php echo count($bibliometrics_list); ?>)
                    </button>
                    <div class="collapse mt-2" id="citations_entries">
                        <div class="card card-body bg-light">
                            <?php foreach ($bibliometrics_list as $idx => $bib): ?>
                                <div class="mb-3 p-2 border rounded">
                                    <strong><?php echo ($idx + 1); ?>. <?php echo htmlspecialchars($bib['name']); ?></strong>
                                    <div class="mt-1">
                                        <span class="text-muted">Value: <?php echo number_format($bib['citations']); ?> citations</span>
                                    </div>
                                    <?php echo $renderDocLinks($docs_citations); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (empty($bibliometrics_list) && !empty($docs_citations)): ?>
                <?php echo $renderDocLinks($docs_citations); ?>
            <?php endif; ?>
        </div>
        <div class="dept-value">
            <?php echo number_format($citations_display_total, 0); ?> citations
        </div>
        <div class="auto-score" data-auto-score="<?php echo min(($total_citations / 100), 20); ?>">
            <?php echo number_format(min(($total_citations / 100), 20), 2); ?> / 20.00
        </div>
        <?php 
        // Initialize saved_score_18 before use
        $saved_score_18 = 0;
        if (isset($expert_item_scores) && is_array($expert_item_scores) && isset($expert_item_scores['item_18_citations'])) {
            $saved_score_18 = (float)$expert_item_scores['item_18_citations'];
        }
        if (!$readonly_mode): ?>
        <div class="expert-input">
            <input type="number" 
                   step="0.01" 
                   min="0" 
                   max="20" 
                   class="form-control expert-input" 
                   id="item_18_citations"
                   name="expert_item_18"
                   value="<?php echo number_format($saved_score_18, 2); ?>"
                   data-initial-value="<?php echo $saved_score_18; ?>"
                   data-auto-score="<?php echo number_format(min(($total_citations / 100), 20), 2); ?>"
                   <?php echo $is_locked ? 'readonly' : ''; ?>
                   onchange="updateItemScore(18, this.value)">
        </div>
        <?php endif; ?>
        <div class="expert-score" id="item_18_citations_score">
            <?php 
            $display_score_18 = $readonly_mode ? ($saved_score_18 > 0 ? $saved_score_18 : min(($total_citations / 100), 20)) : $saved_score_18;
            echo number_format($display_score_18, 2); 
            ?> / 20.00
        </div>
    </div>

    <!-- Item 19: h-index -->
    <div class="data-grid" <?php if ($readonly_mode): ?>style="grid-template-columns: 2fr 1fr 1fr 1fr;"<?php endif; ?> data-item="19">
        <div class="field-label">
            <strong>19. h-index of the Department - cumulative h-index (1 mark per unit, Max 20)</strong>
            <?php if (!empty($bibliometrics_list)): ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#h_index_entries" aria-expanded="false">
                        <i class="fas fa-list"></i> View Teacher Details (<?php echo count($bibliometrics_list); ?>)
                    </button>
                    <div class="collapse mt-2" id="h_index_entries">
                        <div class="card card-body bg-light">
                            <?php foreach ($bibliometrics_list as $idx => $bib): ?>
                                <div class="mb-3 p-2 border rounded">
                                    <strong><?php echo ($idx + 1); ?>. <?php echo htmlspecialchars($bib['name']); ?></strong>
                                    <div class="mt-1">
                                        <span class="text-muted">Value: <?php echo number_format($bib['h_index'], 2); ?> (h-index)</span>
                                    </div>
                                    <?php echo $renderDocLinks($docs_h_index); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (empty($bibliometrics_list) && !empty($docs_h_index)): ?>
                <?php echo $renderDocLinks($docs_h_index); ?>
            <?php endif; ?>
        </div>
        <div class="dept-value">
            <?php echo number_format($h_index_display_total, 2); ?>
        </div>
        <div class="auto-score" data-auto-score="<?php echo min($h_index_total, 20); ?>">
            <?php echo number_format(min($h_index_total, 20), 2); ?> / 20.00
        </div>
        <?php 
        // Initialize saved_score_19 before use
        $saved_score_19 = 0;
        if (isset($expert_item_scores) && is_array($expert_item_scores) && isset($expert_item_scores['item_19_h_index'])) {
            $saved_score_19 = (float)$expert_item_scores['item_19_h_index'];
        }
        if (!$readonly_mode): ?>
        <div class="expert-input">
            <input type="number" 
                   step="0.01" 
                   min="0" 
                   max="20" 
                   class="form-control expert-input" 
                   id="item_19_h_index"
                   name="expert_item_19"
                   value="<?php echo number_format($saved_score_19, 2); ?>"
                   data-initial-value="<?php echo $saved_score_19; ?>"
                   data-auto-score="<?php echo number_format(min($h_index_total, 20), 2); ?>"
                   <?php echo $is_locked ? 'readonly' : ''; ?>
                   onchange="updateItemScore(19, this.value)">
        </div>
        <?php endif; ?>
        <div class="expert-score" id="item_19_h_index_score">
            <?php 
            $display_score_19 = $readonly_mode ? ($saved_score_19 > 0 ? $saved_score_19 : min($h_index_total, 20)) : $saved_score_19;
            echo number_format($display_score_19, 2); 
            ?> / 20.00
        </div>
    </div>
    
    <script>
    function updateItemScore(itemNum, value) {
        const input = document.getElementById('item_' + itemNum + '_' + (itemNum == 17 ? 'impact_factor' : (itemNum == 18 ? 'citations' : 'h_index')));
        if (input) {
            const scoreDisplay = document.getElementById(input.id + '_score');
            if (scoreDisplay) {
                const maxScore = parseFloat(input.getAttribute('max')) || 0;
                const score = Math.min(Math.max(parseFloat(value) || 0, 0), maxScore);
                scoreDisplay.textContent = score.toFixed(2) + ' / ' + maxScore.toFixed(2);
            }
        }
        if (typeof recalculateScores === 'function') {
            recalculateScores();
        }
    }
    </script>
    <?php

    // Item 20: UGC Listed non-Scopus papers (1 mark each, max 15)
    // CRITICAL: Filter UGC/ISSN publications before displaying
    // Dropdown value: "ISSN_Journals" = ISSN Journals + Special Issue Articles - goes to Item 20
    $ugc_papers_filtered = is_array($publications) ? array_filter($publications, function($p) { 
        // Check 'type' field (from dept_login dropdown: "ISSN_Journals" = UGC Listed non-Scopus)
        $type = trim($p['type'] ?? $p['journal_type'] ?? '');
        
        // Simple check: if type is exactly "ISSN_Journals", it's UGC/ISSN (Item 20)
        return $type === 'ISSN_Journals';
    }) : [];
    $ugc_papers = count($ugc_papers_filtered);
    $auto_score_20 = min($ugc_papers, 15);
    
    // CRITICAL: Use serial number 24 (from FacultyOutput.php: publications_ugc uses srno: 24)
    $docs_ugc = getDocumentsForSection($grouped_docs, 'faculty_output', [], 24);
    
    
    // CRITICAL: Pass only filtered UGC/ISSN publications, not all publications
    renderJSONArrayItems(
        20,
        'UGC Listed non-Scopus/Web of Sciences research papers/ISSN Journals (1 mark each, Max 15)',
        json_encode(array_values($ugc_papers_filtered)), // Only UGC/ISSN publications
        $auto_score_20,
        15,
        $docs_ugc,
        $is_locked
    );

    // Item 21: Books and MOOCs (3 for MOOC, 2 for authored, 1 for edited, max 20)
    $books = json_decode($sec1['books'] ?? '[]', true);
    // CRITICAL: Book types are: "Authored Book", "Authored Reference Book", "Edited Book", "Edited Chapter", "MOOC", "MOOC (Created)"
    $mooc_count = is_array($books) ? count(array_filter($books, function($b) { 
        $type = strtolower(trim($b['type'] ?? ''));
        return stripos($type, 'mooc') !== false;
    })) : 0;
    $authored_books = is_array($books) ? count(array_filter($books, function($b) { 
        $type = strtolower(trim($b['type'] ?? ''));
        return stripos($type, 'authored') !== false;
    })) : 0;
    $edited_books = is_array($books) ? count(array_filter($books, function($b) { 
        $type = strtolower(trim($b['type'] ?? ''));
        return stripos($type, 'edited') !== false;
    })) : 0;
    $auto_score_21 = min(($mooc_count * 3) + ($authored_books * 2) + ($edited_books * 1), 20);
    // CRITICAL: Use serial number 29 (from FacultyOutput.php: books_moocs uses srno: 29)
    $docs_books = getDocumentsForSection($grouped_docs, 'faculty_output', [], 29);
    // Debug: Log books data
    error_log("[Expert Review] Books raw: " . ($sec1['books'] ?? '[]'));
    error_log("[Expert Review] Books decoded: " . (is_array($books) ? count($books) . " items" : "NOT ARRAY"));
    if (is_array($books) && !empty($books)) {
        foreach ($books as $idx => $book) {
            error_log("[Expert Review] Book $idx: " . json_encode($book));
        }
    }
    error_log("[Expert Review] Books counts - MOOC: $mooc_count, Authored: $authored_books, Edited: $edited_books");
    
    // Use renderJSONArrayItems for books/MOOCs
    renderJSONArrayItems(
        21,
        'Books, chapters, and MOOCs (3 for MOOC, 2 for authored book, 1 for edited book/chapter, Max 20)',
        json_encode($books),
        $auto_score_21,
        20,
        $docs_books,
        $is_locked
    );
    ?>

    <!-- Section 1 Summary -->
    <div class="mt-4 p-3 bg-light rounded">
        <h4>Section I Total</h4>
        <div class="row">
            <div class="<?php echo (isset($is_department_view) && $is_department_view) ? 'col-md-12' : 'col-md-4'; ?>">
                <strong>Department Auto Score:</strong>
                <div class="auto-score mt-2"><?php echo number_format($auto_scores['section_1'], 2); ?> / 300</div>
            </div>
            <?php if (!isset($is_department_view) || !$is_department_view): ?>
            <div class="col-md-4">
                <strong>Expert Score:</strong>
                <div class="expert-score mt-2" id="section_1_total_display"><?php echo number_format($expert_scores['section_1'], 2); ?> / 300</div>
                <input type="hidden" id="expert_section_1_total" value="<?php echo $expert_scores['section_1']; ?>">
            </div>
            <div class="col-md-4">
                <strong>Difference:</strong>
                <div class="mt-2" id="section_1_diff_display"><?php echo number_format($expert_scores['section_1'] - $auto_scores['section_1'], 2); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
// Real-time calculation for Section I (scoped to Section I card only)
function recalculateSection1() {
    let section1Total = 0;
    
    // Find Section I card
    const allSectionCards = Array.from(document.querySelectorAll('.section-card'));
    const section1Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section I');
    });
    
    if (section1Card) {
        // Collect all Section I scores (regular inputs and narrative scores)
        // Regular numeric inputs
        section1Card.querySelectorAll('.expert-input').forEach(input => {
            const value = parseFloat(input.value) || 0;
            if (!isNaN(value)) {
                section1Total += value;
            }
        });
        
        // Narrative question scores in Section I (items 22-26)
        section1Card.querySelectorAll('input[id^="narrative_"][id$="_score"]').forEach(input => {
            const value = parseFloat(input.value) || 0;
            if (!isNaN(value)) {
                section1Total += value;
            }
        });
    }
    
    // Cap at 300 (as per official UDRF scheme)
    section1Total = Math.min(section1Total, 300);
    
    // Update displays
    const section1Display = document.getElementById('section_1_total_display');
    if (section1Display) {
        section1Display.textContent = section1Total.toFixed(2) + ' / 300';
    }
    const section1Input = document.getElementById('expert_section_1_total');
    if (section1Input) {
        section1Input.value = section1Total;
    }
    const displayExpert1 = document.getElementById('display_expert_section_1');
    if (displayExpert1) {
        displayExpert1.textContent = section1Total.toFixed(2);
    }
    
    const autoScore1 = <?php echo $auto_scores['section_1']; ?>;
    const diff1 = section1Total - autoScore1;
    const section1Diff = document.getElementById('section_1_diff_display');
    if (section1Diff) {
        section1Diff.textContent = diff1.toFixed(2);
    }
    const displayDiff1 = document.getElementById('display_diff_section_1');
    if (displayDiff1) {
        displayDiff1.textContent = diff1.toFixed(2);
    }
    
    // Recalculate grand total
    if (typeof recalculateGrandTotal === 'function') {
        recalculateGrandTotal();
    }
}

// Override global recalculateScores to include Section I calculation
if (typeof window.originalRecalculateScores === 'undefined') {
    window.originalRecalculateScores = window.recalculateScores;
}

// Enhanced recalculateScores that includes Section I
window.recalculateScores = function() {
    recalculateSection1();
    // Call original if it exists
    if (typeof window.originalRecalculateScores === 'function') {
        window.originalRecalculateScores();
    }
};
</script>

