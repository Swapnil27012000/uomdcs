<?php
/**
 * Expert Review System - Data Fetcher
 * Fetches ALL department data from all tables for expert review
 * Based on generate_report.php logic
 */

require_once(__DIR__ . '/../config.php');

/**
 * Helper function to convert academic year format
 * Converts "2024-2025" to integer ending year (2025) for tables that use integer A_YEAR
 * Returns string ending year (e.g., "2025") for tables that use VARCHAR A_YEAR
 */
function getAcademicYearForTable($academic_year, $table_type = 'string') {
    $ending_year = substr($academic_year, -4); // Get last 4 digits (ending year)
    return $table_type === 'int' ? (int)$ending_year : $ending_year;
}

/**
 * Helper function to try fetching data with multiple academic year formats
 * Returns data if found, or null if not found
 */
function tryFetchWithMultipleYears($query_template, $dept_id, $academic_year, $bind_type = "is") {
    global $conn;
    
    // Try exact match first
    $stmt = $conn->prepare($query_template);
    if ($stmt) {
        if ($bind_type === "is") {
            $stmt->bind_param("is", $dept_id, $academic_year);
        } else {
            $stmt->bind_param("i", $dept_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        if ($data) {
            return $data;
        }
    }
    
    // If no exact match, try alternative academic year formats
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    
    // Generate possible alternative years
    $alt_years = [];
    
    // Format 1: (current_year - 1) - current_year (department format for month >= 6)
    $alt_years[] = ($current_year - 1) . '-' . $current_year;
    
    // Format 2: current_year - (current_year + 1) (expert format for month >= 7)
    $alt_years[] = $current_year . '-' . ($current_year + 1);
    
    // Format 3: (current_year - 2) - (current_year - 1) (expert format for month < 7)
    $alt_years[] = ($current_year - 2) . '-' . ($current_year - 1);
    
    // Format 4: (current_year - 1) - current_year (for month < 7)
    // Already added above
    
    // Remove duplicates and the original academic_year
    $alt_years = array_unique($alt_years);
    $alt_years = array_filter($alt_years, function($y) use ($academic_year) {
        return $y !== $academic_year;
    });
    
    // Try each alternative year
    foreach ($alt_years as $alt_year) {
        $stmt = $conn->prepare($query_template);
        if ($stmt) {
            if ($bind_type === "is") {
                $stmt->bind_param("is", $dept_id, $alt_year);
            } else {
                $stmt->bind_param("i", $dept_id);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            if ($data = $result->fetch_assoc()) {
                error_log("[Data Fetcher] Found data with alternative A_YEAR: $alt_year (requested: $academic_year)");
                $stmt->close();
                return $data;
            }
            $stmt->close();
        }
    }
    
    // If still no data, try to find ANY record for this department (for debugging)
    $debug_query = str_replace("A_YEAR = ?", "1=1", $query_template);
    $debug_query = preg_replace('/LIMIT \d+/', 'ORDER BY A_YEAR DESC LIMIT 5', $debug_query);
    $debug_stmt = $conn->prepare($debug_query);
    if ($debug_stmt) {
        $debug_stmt->bind_param("i", $dept_id);
        $debug_stmt->execute();
        $debug_result = $debug_stmt->get_result();
        $found_years = [];
        while ($row = $debug_result->fetch_assoc()) {
            if (isset($row['A_YEAR'])) {
                $found_years[] = $row['A_YEAR'];
            }
        }
        $debug_stmt->close();
        if (!empty($found_years)) {
            error_log("[Data Fetcher] No data found for A_YEAR=$academic_year, but found records for: " . implode(', ', array_unique($found_years)));
        }
    }
    
    return null;
}

/**
 * Fetch ALL department data for expert review
 * Returns comprehensive data from all sections
 */
function fetchAllDepartmentData($dept_id, $academic_year) {
    global $conn;
    
    // Debug: Log what we're fetching
    error_log("[Data Fetcher] Fetching data for dept_id=$dept_id, academic_year=$academic_year");
    
    $all_data = [
        'section_a' => fetchSectionA($dept_id, $academic_year),
        'section_b' => fetchSectionB($dept_id, $academic_year),
        'section_1' => fetchSection1($dept_id, $academic_year), // Faculty Output
        'section_2' => fetchSection2($dept_id, $academic_year), // NEP Initiatives
        'section_3' => fetchSection3($dept_id, $academic_year), // Governance
        'section_4' => fetchSection4($dept_id, $academic_year), // Student Support
        'section_5' => fetchSection5($dept_id, $academic_year)  // Conferences & Collaborations
    ];
    
    // Debug: Log what we found
    $has_section1 = !empty($all_data['section_1']);
    $has_section2 = !empty($all_data['section_2']);
    $has_section3 = !empty($all_data['section_3']);
    $has_section4 = !empty($all_data['section_4']);
    $has_section5 = !empty($all_data['section_5']);
    
    error_log("[Data Fetcher] Section 1 (Faculty Output): " . ($has_section1 ? "HAS DATA" : "NO DATA"));
    error_log("[Data Fetcher] Section 2 (NEP): " . ($has_section2 ? "HAS DATA" : "NO DATA"));
    error_log("[Data Fetcher] Section 3 (Governance): " . ($has_section3 ? "HAS DATA" : "NO DATA"));
    error_log("[Data Fetcher] Section 4 (Student Support): " . ($has_section4 ? "HAS DATA" : "NO DATA"));
    error_log("[Data Fetcher] Section 5 (Conferences): " . ($has_section5 ? "HAS DATA" : "NO DATA"));
    
    // Sample some key values for debugging
    if ($has_section1) {
        $sample = [
            'NUM_PERM_PHD' => $all_data['section_1']['NUM_PERM_PHD'] ?? 'N/A',
            'NUM_ADHOC_PHD' => $all_data['section_1']['NUM_ADHOC_PHD'] ?? 'N/A',
            'awards' => !empty($all_data['section_1']['awards']) ? 'HAS JSON' : 'NO JSON',
            'publications' => !empty($all_data['section_1']['publications']) ? 'HAS JSON' : 'NO JSON',
            'bibliometrics' => !empty($all_data['section_1']['bibliometrics']) ? 'HAS JSON' : 'NO JSON'
        ];
        error_log("[Data Fetcher] Section 1 sample: " . json_encode($sample));
    }
    
    return $all_data;
}

/**
 * Section A: Brief Details of Department
 */
function fetchSectionA($dept_id, $academic_year) {
    global $conn;
    
    $query = "SELECT * FROM brief_details_of_the_department WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("is", $dept_id, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data ?: [];
    }
    return [];
}

/**
 * Section B: Department Profile (Category)
 */
function fetchSectionB($dept_id, $academic_year) {
    global $conn;
    
    // Get dept_code first
    $code_query = "SELECT DEPT_COLL_NO FROM department_master WHERE DEPT_ID = ? LIMIT 1";
    $code_stmt = $conn->prepare($code_query);
    $dept_code = '';
    if ($code_stmt) {
        $code_stmt->bind_param("i", $dept_id);
        $code_stmt->execute();
        $code_result = $code_stmt->get_result();
        if ($code_row = $code_result->fetch_assoc()) {
            $dept_code = $code_row['DEPT_COLL_NO'];
        }
        $code_stmt->close();
    }
    
    // Get profile data
    $query = "SELECT * FROM department_profiles WHERE dept_id = ? AND A_YEAR = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("ss", $dept_code, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data ?: [];
    }
    return [];
}

/**
 * Section I: Faculty Output, Research & Professional Activities
 */
function fetchSection1($dept_id, $academic_year) {
    global $conn;
    
    $query = "SELECT * FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $data = tryFetchWithMultipleYears($query, $dept_id, $academic_year, "is");
    
    // Debug: Log what we found - show actual values, not just if they're empty
    if ($data) {
        error_log("[Data Fetcher Section 1] Found data for dept_id=$dept_id, academic_year=$academic_year");
        // Log description fields with proper escaping
        $desc_initiative_val = isset($data['desc_initiative']) ? $data['desc_initiative'] : null;
        $desc_impact_val = isset($data['desc_impact']) ? $data['desc_impact'] : null;
        $desc_collaboration_val = isset($data['desc_collaboration']) ? $data['desc_collaboration'] : null;
        $desc_plan_val = isset($data['desc_plan']) ? $data['desc_plan'] : null;
        $desc_recognition_val = isset($data['desc_recognition']) ? $data['desc_recognition'] : null;
        
        if ($desc_initiative_val !== null) {
            $is_dash = ($desc_initiative_val === '-') ? 'YES' : 'NO';
            error_log("[Data Fetcher Section 1] desc_initiative: VALUE: \"" . substr($desc_initiative_val, 0, 100) . "\" (length: " . strlen($desc_initiative_val) . ", is \"-\": " . $is_dash . ")");
        } else {
            error_log("[Data Fetcher Section 1] desc_initiative: NOT SET");
        }
        
        if ($desc_impact_val !== null) {
            $is_dash = ($desc_impact_val === '-') ? 'YES' : 'NO';
            error_log("[Data Fetcher Section 1] desc_impact: VALUE: \"" . substr($desc_impact_val, 0, 100) . "\" (length: " . strlen($desc_impact_val) . ", is \"-\": " . $is_dash . ")");
        } else {
            error_log("[Data Fetcher Section 1] desc_impact: NOT SET");
        }
        
        if ($desc_collaboration_val !== null) {
            $is_dash = ($desc_collaboration_val === '-') ? 'YES' : 'NO';
            error_log("[Data Fetcher Section 1] desc_collaboration: VALUE: \"" . substr($desc_collaboration_val, 0, 100) . "\" (length: " . strlen($desc_collaboration_val) . ", is \"-\": " . $is_dash . ")");
        } else {
            error_log("[Data Fetcher Section 1] desc_collaboration: NOT SET");
        }
        
        if ($desc_plan_val !== null) {
            $is_dash = ($desc_plan_val === '-') ? 'YES' : 'NO';
            error_log("[Data Fetcher Section 1] desc_plan: VALUE: \"" . substr($desc_plan_val, 0, 100) . "\" (length: " . strlen($desc_plan_val) . ", is \"-\": " . $is_dash . ")");
        } else {
            error_log("[Data Fetcher Section 1] desc_plan: NOT SET");
        }
        
        if ($desc_recognition_val !== null) {
            $is_dash = ($desc_recognition_val === '-') ? 'YES' : 'NO';
            error_log("[Data Fetcher Section 1] desc_recognition: VALUE: \"" . substr($desc_recognition_val, 0, 100) . "\" (length: " . strlen($desc_recognition_val) . ", is \"-\": " . $is_dash . ")");
        } else {
            error_log("[Data Fetcher Section 1] desc_recognition: NOT SET");
        }
        
        if (isset($data['dpiit_startup_details'])) {
            error_log("[Data Fetcher Section 1] dpiit_startup_details: HAS DATA (" . strlen($data['dpiit_startup_details']) . " chars)");
        } else {
            error_log("[Data Fetcher Section 1] dpiit_startup_details: NOT SET");
        }
        
        if (isset($data['forbes_alumni_details'])) {
            error_log("[Data Fetcher Section 1] forbes_alumni_details: HAS DATA (" . strlen($data['forbes_alumni_details']) . " chars), first 200 chars: " . substr($data['forbes_alumni_details'], 0, 200));
        } else {
            error_log("[Data Fetcher Section 1] forbes_alumni_details: NOT SET");
        }
        error_log("[Data Fetcher Section 1] startups_incubated: " . ($data['startups_incubated'] ?? 'NULL'));
    } else {
        error_log("[Data Fetcher Section 1] NO DATA found for dept_id=$dept_id, academic_year=$academic_year");
    }
    
    // Parse JSON fields for individual entries
    if ($data) {
        // Parse patent details
        if (!empty($data['patent_details'])) {
            $data['patent_details_parsed'] = json_decode($data['patent_details'], true) ?: [];
        } else {
            $data['patent_details_parsed'] = [];
        }
        
        // Parse all startup ecosystem details and combine them
        $startup_list = [];
        
        // DPIIT startups
        if (!empty($data['dpiit_startup_details'])) {
            $dpiit_list = json_decode($data['dpiit_startup_details'], true) ?: [];
            foreach ($dpiit_list as $item) {
                $item['type'] = 'DPIIT Recognition';
                $startup_list[] = $item;
            }
        }
        
        // VC investments
        if (!empty($data['vc_investment_details'])) {
            $vc_list = json_decode($data['vc_investment_details'], true) ?: [];
            foreach ($vc_list as $item) {
                $item['type'] = 'VC Investment';
                $startup_list[] = $item;
            }
        }
        
        // Seed funding
        if (!empty($data['seed_funding_details'])) {
            $seed_list = json_decode($data['seed_funding_details'], true) ?: [];
            foreach ($seed_list as $item) {
                $item['type'] = 'Seed Funding';
                $startup_list[] = $item;
            }
        }
        
        // FDI investments
        if (!empty($data['fdi_investment_details'])) {
            $fdi_list = json_decode($data['fdi_investment_details'], true) ?: [];
            foreach ($fdi_list as $item) {
                $item['type'] = 'FDI Investment';
                $startup_list[] = $item;
            }
        }
        
        // Innovation grants
        if (!empty($data['innovation_grants_details'])) {
            $grant_list = json_decode($data['innovation_grants_details'], true) ?: [];
            foreach ($grant_list as $item) {
                $item['type'] = 'Innovation Grant';
                $startup_list[] = $item;
            }
        }
        
        // TRL innovations
        if (!empty($data['trl_innovations_details'])) {
            $trl_list = json_decode($data['trl_innovations_details'], true) ?: [];
            foreach ($trl_list as $item) {
                $item['type'] = 'TRL Innovation';
                $startup_list[] = $item;
            }
        }
        
        // Turnover achievements
        if (!empty($data['turnover_achievements_details'])) {
            $turnover_list = json_decode($data['turnover_achievements_details'], true) ?: [];
            foreach ($turnover_list as $item) {
                $item['type'] = 'Turnover Achievement';
                $startup_list[] = $item;
            }
        }
        
        // Forbes alumni
        if (!empty($data['forbes_alumni_details'])) {
            $forbes_list = json_decode($data['forbes_alumni_details'], true) ?: [];
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
                            $startup_list[] = $item;
                            error_log("[Data Fetcher Section 1] Added Forbes Alumni entry: " . json_encode($item));
                        } else {
                            error_log("[Data Fetcher Section 1] Skipped Forbes Alumni entry (no valid data): " . json_encode($item));
                        }
                    }
                }
            }
        }
        
        $data['dpiit_startup_details_parsed'] = $startup_list;
        
        // Debug: Log startup data
        error_log("[Data Fetcher Section 1] Startup list count: " . count($startup_list));
        if (!empty($startup_list)) {
            error_log("[Data Fetcher Section 1] First startup entry: " . json_encode($startup_list[0] ?? []));
        }
        error_log("[Data Fetcher Section 1] forbes_alumni_details raw: " . (!empty($data['forbes_alumni_details']) ? substr($data['forbes_alumni_details'], 0, 200) : 'EMPTY'));
    }
    
    // CRITICAL: If data is empty but we should have data, try a direct query as fallback
    // This helps catch cases where tryFetchWithMultipleYears might have missed the data
    // Check both for null and empty array
    if (!$data || (is_array($data) && empty($data))) {
        error_log("[Data Fetcher Section 1] Data is empty, trying direct query fallback...");
        $fallback_query = "SELECT * FROM faculty_output WHERE DEPT_ID = ? ORDER BY A_YEAR DESC LIMIT 1";
        $fallback_stmt = $conn->prepare($fallback_query);
        if ($fallback_stmt) {
            $fallback_stmt->bind_param("i", $dept_id);
            $fallback_stmt->execute();
            $fallback_result = $fallback_stmt->get_result();
            if ($fallback_data = $fallback_result->fetch_assoc()) {
                error_log("[Data Fetcher Section 1] Found data with fallback query - A_YEAR: " . ($fallback_data['A_YEAR'] ?? 'NULL'));
                $data = $fallback_data;
                // Re-parse JSON fields if we got data from fallback
                if ($data) {
                    // Parse patent details
                    if (!empty($data['patent_details'])) {
                        $data['patent_details_parsed'] = json_decode($data['patent_details'], true) ?: [];
                    } else {
                        $data['patent_details_parsed'] = [];
                    }
                    
                    // Parse all startup ecosystem details and combine them
                    $startup_list = [];
                    
                    // DPIIT startups
                    if (!empty($data['dpiit_startup_details'])) {
                        $dpiit_list = json_decode($data['dpiit_startup_details'], true) ?: [];
                        foreach ($dpiit_list as $item) {
                            $item['type'] = 'DPIIT Recognition';
                            $startup_list[] = $item;
                        }
                    }
                    
                    // VC investments
                    if (!empty($data['vc_investment_details'])) {
                        $vc_list = json_decode($data['vc_investment_details'], true) ?: [];
                        foreach ($vc_list as $item) {
                            $item['type'] = 'VC Investment';
                            $startup_list[] = $item;
                        }
                    }
                    
                    // Seed funding
                    if (!empty($data['seed_funding_details'])) {
                        $seed_list = json_decode($data['seed_funding_details'], true) ?: [];
                        foreach ($seed_list as $item) {
                            $item['type'] = 'Seed Funding';
                            $startup_list[] = $item;
                        }
                    }
                    
                    // FDI investments
                    if (!empty($data['fdi_investment_details'])) {
                        $fdi_list = json_decode($data['fdi_investment_details'], true) ?: [];
                        foreach ($fdi_list as $item) {
                            $item['type'] = 'FDI Investment';
                            $startup_list[] = $item;
                        }
                    }
                    
                    // Innovation grants
                    if (!empty($data['innovation_grants_details'])) {
                        $grant_list = json_decode($data['innovation_grants_details'], true) ?: [];
                        foreach ($grant_list as $item) {
                            $item['type'] = 'Innovation Grant';
                            $startup_list[] = $item;
                        }
                    }
                    
                    // TRL innovations
                    if (!empty($data['trl_innovations_details'])) {
                        $trl_list = json_decode($data['trl_innovations_details'], true) ?: [];
                        foreach ($trl_list as $item) {
                            $item['type'] = 'TRL Innovation';
                            $startup_list[] = $item;
                        }
                    }
                    
                    // Turnover achievements
                    if (!empty($data['turnover_achievements_details'])) {
                        $turnover_list = json_decode($data['turnover_achievements_details'], true) ?: [];
                        foreach ($turnover_list as $item) {
                            $item['type'] = 'Turnover Achievement';
                            $startup_list[] = $item;
                        }
                    }
                    
                    // Forbes alumni
                    if (!empty($data['forbes_alumni_details'])) {
                        $forbes_list = json_decode($data['forbes_alumni_details'], true) ?: [];
                        if (is_array($forbes_list) && !empty($forbes_list)) {
                            foreach ($forbes_list as $item) {
                                if (is_array($item)) {
                                    // Include entry if it has at least one non-empty, non-"-" field
                                    $has_valid_data = false;
                                    foreach ($item as $key => $val) {
                                        $clean_val = trim((string)$val);
                                        if (!empty($clean_val) && $clean_val !== '-' && !in_array(strtolower($key), ['id', 'dept_id', 'a_year', 'serial_number'])) {
                                            $has_valid_data = true;
                                            break;
                                        }
                                    }
                                    if ($has_valid_data) {
                                        $item['type'] = 'Forbes Alumni';
                                        $startup_list[] = $item;
                                    }
                                }
                            }
                        }
                    }
                    
                    $data['dpiit_startup_details_parsed'] = $startup_list;
                }
            }
            $fallback_stmt->close();
        }
    }
    
    return $data ?: [];
}

/**
 * Section II: NEP Initiatives
 * Note: nepmarks table now uses VARCHAR(20) A_YEAR (full format "2024-2025")
 */
function fetchSection2($dept_id, $academic_year) {
    global $conn;
    
    // Try VARCHAR format first (new format: "2024-2025")
    $query = "SELECT * FROM nepmarks WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $data = tryFetchWithMultipleYears($query, $dept_id, $academic_year, "is");
    
    // If found with VARCHAR format, return it
    if ($data) {
        // Parse JSON fields if they exist
        if (isset($data['nep_initiatives']) && is_string($data['nep_initiatives'])) {
            $data['nep_initiatives_parsed'] = json_decode($data['nep_initiatives'], true) ?: [];
        }
        if (isset($data['pedagogical']) && is_string($data['pedagogical'])) {
            $data['pedagogical_parsed'] = json_decode($data['pedagogical'], true) ?: [];
        }
        if (isset($data['assessments']) && is_string($data['assessments'])) {
            $data['assessments_parsed'] = json_decode($data['assessments'], true) ?: [];
        }
        if (isset($data['mooc_data']) && is_string($data['mooc_data']) && !empty(trim($data['mooc_data']))) {
            $mooc_decoded = json_decode($data['mooc_data'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($mooc_decoded)) {
                $data['mooc_data_parsed'] = $mooc_decoded;
                error_log("Data Fetcher - Parsed mooc_data successfully, count: " . count($mooc_decoded));
            } else {
                error_log("Data Fetcher - Failed to parse mooc_data: " . json_last_error_msg() . ", raw: " . substr($data['mooc_data'], 0, 100));
                $data['mooc_data_parsed'] = [];
            }
        } else {
            $data['mooc_data_parsed'] = [];
        }
        return $data;
    }
    
    // Fallback: Try INTEGER format (old format: ending year only, e.g., 2025 for "2024-2025")
    $a_year_int = (int)substr($academic_year, -4); // Get last 4 digits (ending year)
    $query_fallback = "SELECT * FROM nepmarks WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $stmt_fallback = $conn->prepare($query_fallback);
    if ($stmt_fallback) {
        $stmt_fallback->bind_param("ii", $dept_id, $a_year_int);
        $stmt_fallback->execute();
        $result_fallback = $stmt_fallback->get_result();
        $data_fallback = $result_fallback->fetch_assoc();
        $stmt_fallback->close();
        
        if ($data_fallback) {
            // Parse JSON fields if they exist
            if (isset($data_fallback['nep_initiatives']) && is_string($data_fallback['nep_initiatives'])) {
                $data_fallback['nep_initiatives_parsed'] = json_decode($data_fallback['nep_initiatives'], true) ?: [];
            }
            if (isset($data_fallback['pedagogical']) && is_string($data_fallback['pedagogical'])) {
                $data_fallback['pedagogical_parsed'] = json_decode($data_fallback['pedagogical'], true) ?: [];
            }
            if (isset($data_fallback['assessments']) && is_string($data_fallback['assessments'])) {
                $data_fallback['assessments_parsed'] = json_decode($data_fallback['assessments'], true) ?: [];
            }
            if (isset($data_fallback['mooc_data']) && is_string($data_fallback['mooc_data']) && !empty(trim($data_fallback['mooc_data']))) {
                $mooc_decoded = json_decode($data_fallback['mooc_data'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($mooc_decoded)) {
                    $data_fallback['mooc_data_parsed'] = $mooc_decoded;
                    error_log("Data Fetcher - Parsed mooc_data (fallback) successfully, count: " . count($mooc_decoded));
                } else {
                    error_log("Data Fetcher - Failed to parse mooc_data (fallback): " . json_last_error_msg());
                    $data_fallback['mooc_data_parsed'] = [];
                }
            } else {
                $data_fallback['mooc_data_parsed'] = [];
            }
            return $data_fallback;
        }
    }
    
    return [];
}

/**
 * Section III: Departmental Governance
 */
function fetchSection3($dept_id, $academic_year) {
    global $conn;
    
    $query = "SELECT * FROM department_data WHERE DEPT_ID = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $dept_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data ?: [];
    }
    return [];
}

/**
 * Section IV: Student Support
 */
function fetchSection4($dept_id, $academic_year) {
    global $conn;
    
    // Fetch from multiple tables
    $data = [];
    
    // Intake data - get both aggregated totals AND program-wise breakdown
    // First get aggregated totals for auto-scoring
    $query1_agg = "SELECT 
                DEPT_ID,
                A_YEAR,
                SUM(Add_Total_Student_Intake) as total_intake,
                SUM(Total_number_of_Male_Students + Total_number_of_Female_Students + Total_number_of_Other_Students) as total_enrolled,
                SUM(Total_number_of_Male_Students) as total_male,
                SUM(Total_number_of_Female_Students) as total_female,
                SUM(Total_number_of_Other_Students) as total_other,
                -- Regional Diversity (outside state)
                SUM(Male_Students_outside_state + Female_Students_outside_state + Other_Students_outside_state) as outside_state_students,
                -- Regional Diversity (outside country)
                SUM(Male_Students_outside_country + Female_Students_outside_country + Other_Students_outside_country) as outside_country_students,
                -- ESCS Diversity (sum all reserved categories)
                SUM(
                    Male_Students_Economic_Backward + Female_Students_Economic_Backward + Other_Students_Economic_Backward +
                    Male_Student_General + Female_Student_General + Other_Student_General +
                    Male_Student_Social_Backward_SC + Female_Student_Social_Backward_SC + Other_Student_Social_Backward_SC +
                    Male_Student_Social_Backward_ST + Female_Student_Social_Backward_ST + Other_Student_Social_Backward_ST +
                    Male_Student_Social_Backward_OBC + Female_Student_Social_Backward_OBC + Other_Student_Social_Backward_OBC +
                    Male_Student_Social_Backward_DTA + Female_Student_Social_Backward_DTA + Other_Student_Social_Backward_DTA +
                    Male_Student_Social_Backward_NTB + Female_Student_Social_Backward_NTB + Other_Student_Social_Backward_NTB +
                    Male_Student_Social_Backward_NTC + Female_Student_Social_Backward_NTC + Other_Student_Social_Backward_NTC +
                    Male_Student_Social_Backward_NTD + Female_Student_Social_Backward_NTD + Other_Student_Social_Backward_NTD +
                    Male_Student_Social_Backward_EWS + Female_Student_Social_Backward_EWS + Other_Student_Social_Backward_EWS +
                    Male_Student_Social_Backward_SEBC + Female_Student_Social_Backward_SEBC + Other_Student_Social_Backward_SEBC +
                    Male_Student_Social_Backward_SBC + Female_Student_Social_Backward_SBC + Other_Student_Social_Backward_SBC +
                    Male_Student_Physically_Handicapped + Female_Student_Physically_Handicapped + Other_Student_Physically_Handicapped +
                    Male_Student_TGO + Female_Student_TGO + Other_Student_TGO +
                    Male_Student_CMIL + Female_Student_CMIL + Other_Student_CMIL +
                    Male_Student_SPCUL + Female_Student_SPCUL + Other_Student_SPCUL +
                    Male_Student_Widow_Single_Mother + Female_Student_Widow_Single_Mother + Other_Student_Widow_Single_Mother +
                    Male_Student_ES + Female_Student_ES + Other_Student_ES
                ) as reserved_category_students,
                -- Scholarships (sum all scholarship types)
                SUM(
                    Male_Student_Receiving_Scholarship_Government + Female_Student_Receiving_Scholarship_Government + Other_Student_Receiving_Scholarship_Government +
                    Male_Student_Receiving_Scholarship_Institution + Female_Student_Receiving_Scholarship_Institution + Other_Student_Receiving_Scholarship_Institution +
                    Male_Student_Receiving_Scholarship_Private_Body + Female_Student_Receiving_Scholarship_Private_Body + Other_Student_Receiving_Scholarship_Private_Body
                ) as scholarship_students,
                -- Final semester appeared/passed (from placement_details or calculate from intake)
                0 as final_sem_appeared,
                0 as final_sem_passed
               FROM intake_actual_strength 
               WHERE DEPT_ID = ? AND A_YEAR = ? 
               GROUP BY DEPT_ID, A_YEAR
               LIMIT 1";
    $stmt1_agg = $conn->prepare($query1_agg);
    if ($stmt1_agg) {
        $stmt1_agg->bind_param("is", $dept_id, $academic_year);
        $stmt1_agg->execute();
        $result1_agg = $stmt1_agg->get_result();
        if ($intake_agg = $result1_agg->fetch_assoc()) {
            // Add female_students field for Item 5 (Women Diversity)
            $intake_agg['female_students'] = $intake_agg['total_female'] ?? 0;
            $data['intake'] = $intake_agg;
            error_log("[Section IV] Found aggregated intake data: total_intake=" . ($intake_agg['total_intake'] ?? 0) . ", total_enrolled=" . ($intake_agg['total_enrolled'] ?? 0) . ", female_students=" . ($intake_agg['female_students'] ?? 0) . ", reserved_category=" . ($intake_agg['reserved_category_students'] ?? 0) . ", scholarships=" . ($intake_agg['scholarship_students'] ?? 0));
        } else {
            error_log("[Section IV] No aggregated intake data found for dept_id=$dept_id, A_YEAR=$academic_year");
        }
        $stmt1_agg->close();
    }
    
    // Now get program-wise breakdown for display
    $query1_programs = "SELECT 
                ias.ID,
                ias.PROGRAM_CODE,
                ias.PROGRAM_NAME,
                ias.Add_Total_Student_Intake as program_intake,
                (ias.Total_number_of_Male_Students + ias.Total_number_of_Female_Students + ias.Total_number_of_Other_Students) as program_enrolled,
                -- Women Diversity (for Item 5)
                ias.Total_number_of_Female_Students as program_female_students,
                -- Regional Diversity
                (ias.Male_Students_outside_state + ias.Female_Students_outside_state + ias.Other_Students_outside_state) as program_outside_state,
                (ias.Male_Students_outside_country + ias.Female_Students_outside_country + ias.Other_Students_outside_country) as program_outside_country,
                -- ESCS Diversity
                (
                    ias.Male_Students_Economic_Backward + ias.Female_Students_Economic_Backward + ias.Other_Students_Economic_Backward +
                    ias.Male_Student_General + ias.Female_Student_General + ias.Other_Student_General +
                    ias.Male_Student_Social_Backward_SC + ias.Female_Student_Social_Backward_SC + ias.Other_Student_Social_Backward_SC +
                    ias.Male_Student_Social_Backward_ST + ias.Female_Student_Social_Backward_ST + ias.Other_Student_Social_Backward_ST +
                    ias.Male_Student_Social_Backward_OBC + ias.Female_Student_Social_Backward_OBC + ias.Other_Student_Social_Backward_OBC +
                    ias.Male_Student_Social_Backward_DTA + ias.Female_Student_Social_Backward_DTA + ias.Other_Student_Social_Backward_DTA +
                    ias.Male_Student_Social_Backward_NTB + ias.Female_Student_Social_Backward_NTB + ias.Other_Student_Social_Backward_NTB +
                    ias.Male_Student_Social_Backward_NTC + ias.Female_Student_Social_Backward_NTC + ias.Other_Student_Social_Backward_NTC +
                    ias.Male_Student_Social_Backward_NTD + ias.Female_Student_Social_Backward_NTD + ias.Other_Student_Social_Backward_NTD +
                    ias.Male_Student_Social_Backward_EWS + ias.Female_Student_Social_Backward_EWS + ias.Other_Student_Social_Backward_EWS +
                    ias.Male_Student_Social_Backward_SEBC + ias.Female_Student_Social_Backward_SEBC + ias.Other_Student_Social_Backward_SEBC +
                    ias.Male_Student_Social_Backward_SBC + ias.Female_Student_Social_Backward_SBC + ias.Other_Student_Social_Backward_SBC +
                    ias.Male_Student_Physically_Handicapped + ias.Female_Student_Physically_Handicapped + ias.Other_Student_Physically_Handicapped +
                    ias.Male_Student_TGO + ias.Female_Student_TGO + ias.Other_Student_TGO +
                    ias.Male_Student_CMIL + ias.Female_Student_CMIL + ias.Other_Student_CMIL +
                    ias.Male_Student_SPCUL + ias.Female_Student_SPCUL + ias.Other_Student_SPCUL +
                    ias.Male_Student_Widow_Single_Mother + ias.Female_Student_Widow_Single_Mother + ias.Other_Student_Widow_Single_Mother +
                    ias.Male_Student_ES + ias.Female_Student_ES + ias.Other_Student_ES
                ) as program_reserved_category,
                -- Scholarships
                (
                    ias.Male_Student_Receiving_Scholarship_Government + ias.Female_Student_Receiving_Scholarship_Government + ias.Other_Student_Receiving_Scholarship_Government +
                    ias.Male_Student_Receiving_Scholarship_Institution + ias.Female_Student_Receiving_Scholarship_Institution + ias.Other_Student_Receiving_Scholarship_Institution +
                    ias.Male_Student_Receiving_Scholarship_Private_Body + ias.Female_Student_Receiving_Scholarship_Private_Body + ias.Other_Student_Receiving_Scholarship_Private_Body
                ) as program_scholarship_students,
                p.programme_code as actual_program_code
               FROM intake_actual_strength ias
               LEFT JOIN programmes p ON ias.PROGRAM_CODE = p.id AND ias.DEPT_ID = p.DEPT_ID
               WHERE ias.DEPT_ID = ? AND ias.A_YEAR = ?
               ORDER BY ias.PROGRAM_NAME";
    $stmt1_programs = $conn->prepare($query1_programs);
    $intake_programs = [];
    if ($stmt1_programs) {
        $stmt1_programs->bind_param("is", $dept_id, $academic_year);
        $stmt1_programs->execute();
        $result1_programs = $stmt1_programs->get_result();
        while ($prog_row = $result1_programs->fetch_assoc()) {
            $intake_programs[] = $prog_row;
        }
        $data['intake']['programs'] = $intake_programs;
        error_log("[Section IV] Found " . count($intake_programs) . " program records in intake_actual_strength");
        $stmt1_programs->close();
    }
    
    // Placement data - get both aggregated totals AND program-wise breakdown
    // First get aggregated totals for auto-scoring
    $query2_agg = "SELECT 
                DEPT_ID,
                A_YEAR,
                SUM(TOTAL_NO_OF_STUDENT) as total_students,
                SUM(TOTAL_NUM_OF_STUDENTS_GRADUATED) as total_graduated,
                SUM(TOTAL_NUM_OF_STUDENTS_PLACED) as total_placed,
                SUM(NUM_OF_STUDENTS_IN_HIGHER_STUDIES) as total_higher_studies,
                SUM(STUDENTS_QUALIFYING_EXAMS) as total_qualifying_exams
               FROM placement_details 
               WHERE DEPT_ID = ? AND A_YEAR = ? 
               GROUP BY DEPT_ID, A_YEAR
               LIMIT 1";
    $stmt2_agg = $conn->prepare($query2_agg);
    if ($stmt2_agg) {
        $stmt2_agg->bind_param("is", $dept_id, $academic_year);
        $stmt2_agg->execute();
        $result2_agg = $stmt2_agg->get_result();
        if ($placement_agg = $result2_agg->fetch_assoc()) {
            $data['placement'] = $placement_agg;
            error_log("[Section IV] Found aggregated placement data: total_placed=" . ($placement_agg['total_placed'] ?? 0) . ", total_higher_studies=" . ($placement_agg['total_higher_studies'] ?? 0) . ", total_qualifying_exams=" . ($placement_agg['total_qualifying_exams'] ?? 0));
        } else {
            error_log("[Section IV] No aggregated placement data found for dept_id=$dept_id, A_YEAR=$academic_year");
        }
        $stmt2_agg->close();
    }
    
    // Now get program-wise breakdown for display
    $query2_programs = "SELECT 
                pd.ID,
                pd.PROGRAM_CODE,
                pd.PROGRAM_NAME,
                pd.TOTAL_NO_OF_STUDENT,
                pd.TOTAL_NUM_OF_STUDENTS_GRADUATED,
                pd.TOTAL_NUM_OF_STUDENTS_PLACED,
                pd.NUM_OF_STUDENTS_IN_HIGHER_STUDIES,
                pd.STUDENTS_QUALIFYING_EXAMS,
                p.programme_code as actual_program_code
               FROM placement_details pd
               LEFT JOIN programmes p ON pd.PROGRAM_CODE = p.id AND pd.DEPT_ID = p.DEPT_ID
               WHERE pd.DEPT_ID = ? AND pd.A_YEAR = ?
               ORDER BY pd.PROGRAM_NAME";
    $stmt2_programs = $conn->prepare($query2_programs);
    $placement_programs = [];
    if ($stmt2_programs) {
        $stmt2_programs->bind_param("is", $dept_id, $academic_year);
        $stmt2_programs->execute();
        $result2_programs = $stmt2_programs->get_result();
        while ($prog_row = $result2_programs->fetch_assoc()) {
            $placement_programs[] = $prog_row;
        }
        $data['placement']['programs'] = $placement_programs;
        error_log("[Section IV] Found " . count($placement_programs) . " program records in placement_details");
        $stmt2_programs->close();
    }
    
    // PhD data
    $query3 = "SELECT * FROM phd_details WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $stmt3 = $conn->prepare($query3);
    if ($stmt3) {
        $stmt3->bind_param("is", $dept_id, $academic_year);
        $stmt3->execute();
        $result3 = $stmt3->get_result();
        if ($phd = $result3->fetch_assoc()) {
            // Calculate total PhD students enrolled (currently enrolled, not awarded)
            $phd['total_phd'] = 
                (int)($phd['FULL_TIME_MALE_STUDENTS'] ?? 0) +
                (int)($phd['FULL_TIME_FEMALE_STUDENTS'] ?? 0) +
                (int)($phd['FULL_TIME_OTHER_STUDENTS'] ?? 0) +
                (int)($phd['PART_TIME_MALE_STUDENTS'] ?? 0) +
                (int)($phd['PART_TIME_FEMALE_STUDENTS'] ?? 0) +
                (int)($phd['PART_TIME_OTHER_STUDENTS'] ?? 0);
            
            $phd['phd_awarded_count'] = 
                (int)($phd['PHD_AWARDED_MALE_STUDENTS_FULL'] ?? 0) +
                (int)($phd['PHD_AWARDED_FEMALE_STUDENTS_FULL'] ?? 0) +
                (int)($phd['PHD_AWARDED_OTHER_STUDENTS_FULL'] ?? 0) +
                (int)($phd['PHD_AWARDED_MALE_STUDENTS_PART'] ?? 0) +
                (int)($phd['PHD_AWARDED_FEMALE_STUDENTS_PART'] ?? 0) +
                (int)($phd['PHD_AWARDED_OTHER_STUDENTS_PART'] ?? 0);
            
            // Parse PhD awardees details JSON
            if (!empty($phd['PHD_AWARDEES_DETAILS'])) {
                $phd['phd_awardees_parsed'] = json_decode($phd['PHD_AWARDEES_DETAILS'], true) ?: [];
            } else {
                $phd['phd_awardees_parsed'] = [];
            }
            
            // Will be updated below with JRF/SRF count (PhD students with fellowships)
            $phd['phd_with_fellowship'] = 0;
            
            error_log("[Section IV] Found PhD data: total_phd=" . $phd['total_phd'] . ", phd_awarded=" . $phd['phd_awarded_count']);
            
            $data['phd'] = $phd;
        }
        $stmt3->close();
    }
    
    // Initialize support data array
    $data['support'] = [];
    
    // Fetch MOOC courses from nepmarks table (Section II data, but needed for Section IV Item 16)
    // Note: nepmarks now uses VARCHAR(20) A_YEAR (full format "2024-2025")
    $query_mooc = "SELECT moocs FROM nepmarks WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $stmt_mooc = $conn->prepare($query_mooc);
    if ($stmt_mooc) {
        $stmt_mooc->bind_param("is", $dept_id, $academic_year);
        $stmt_mooc->execute();
        $result_mooc = $stmt_mooc->get_result();
        if ($mooc_row = $result_mooc->fetch_assoc()) {
            $data['support']['mooc_courses_adopted'] = (int)($mooc_row['moocs'] ?? 0);
            error_log("[Section IV] Found MOOC courses: " . ($mooc_row['moocs'] ?? 0));
        } else {
            // Fallback: Try INTEGER format (old format)
            $a_year_int = (int)substr($academic_year, -4);
            $stmt_mooc_fallback = $conn->prepare("SELECT moocs FROM nepmarks WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
            if ($stmt_mooc_fallback) {
                $stmt_mooc_fallback->bind_param("ii", $dept_id, $a_year_int);
                $stmt_mooc_fallback->execute();
                $result_mooc_fallback = $stmt_mooc_fallback->get_result();
                if ($mooc_row_fallback = $result_mooc_fallback->fetch_assoc()) {
                    $data['support']['mooc_courses_adopted'] = (int)($mooc_row_fallback['moocs'] ?? 0);
                    error_log("[Section IV] Found MOOC courses (fallback): " . ($mooc_row_fallback['moocs'] ?? 0));
                } else {
                    $data['support']['mooc_courses_adopted'] = 0;
                    error_log("[Section IV] No MOOC data found for dept_id=$dept_id, A_YEAR=$academic_year or $a_year_int");
                }
                $stmt_mooc_fallback->close();
            } else {
                $data['support']['mooc_courses_adopted'] = 0;
            }
        }
        $stmt_mooc->close();
    } else {
        $data['support']['mooc_courses_adopted'] = 0;
    }
    
    // Executive Development data (from exec_dev table - uses full A_YEAR format "2024-2025")
    $query_exec = "SELECT * FROM exec_dev WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $stmt_exec = $conn->prepare($query_exec);
    if ($stmt_exec) {
        $stmt_exec->bind_param("is", $dept_id, $academic_year);
        $stmt_exec->execute();
        $result_exec = $stmt_exec->get_result();
        if ($exec_dev = $result_exec->fetch_assoc()) {
            // Map to support_data structure for consistency
            $data['support']['exec_dev_students'] = (int)($exec_dev['TOTAL_PARTICIPANTS'] ?? 0);
            $data['support']['exec_dev_fee'] = (float)($exec_dev['TOTAL_INCOME'] ?? 0);
            error_log("[Section IV] Found exec_dev data: programs=" . ($exec_dev['NO_OF_EXEC_PROGRAMS'] ?? 0) . ", participants=" . ($exec_dev['TOTAL_PARTICIPANTS'] ?? 0) . ", income=" . ($exec_dev['TOTAL_INCOME'] ?? 0));
        } else {
            error_log("[Section IV] No exec_dev data found for dept_id=$dept_id, A_YEAR=$academic_year");
            $data['support']['exec_dev_students'] = 0;
            $data['support']['exec_dev_fee'] = 0;
        }
        $stmt_exec->close();
    } else {
        $data['support']['exec_dev_students'] = 0;
        $data['support']['exec_dev_fee'] = 0;
    }
    
    // Student support data (from studentsupport table - uses integer A_YEAR)
    // Note: studentsupport table uses integer A_YEAR (STARTING year, first 4 digits)
    // StudentSupport.php stores: $A_YEAR_DB = (int)substr($A_YEAR, 0, 4);
    $a_year_int = (int)substr($academic_year, 0, 4); // Get first 4 digits (starting year)
    error_log("[Data Fetcher] Fetching studentsupport for dept_id=$dept_id, A_YEAR=$a_year_int (from academic_year=$academic_year)");
    $query4 = "SELECT * FROM studentsupport WHERE dept = ? AND A_YEAR = ? LIMIT 1";
    $stmt4 = $conn->prepare($query4);
    if ($stmt4) {
        $stmt4->bind_param("ii", $dept_id, $a_year_int);
        $stmt4->execute();
        $result4 = $stmt4->get_result();
        if ($support = $result4->fetch_assoc()) {
            error_log("[Data Fetcher] Found studentsupport record. jrfs_data length: " . strlen($support['jrfs_data'] ?? ''));
            error_log("[Data Fetcher] jrfs_count: " . ($support['jrfs_count'] ?? 0) . ", srfs_count: " . ($support['srfs_count'] ?? 0));
            // Parse JSON data for research fellows
            $jrfs_data = json_decode($support['jrfs_data'] ?? '[]', true) ?: [];
            $srfs_data = json_decode($support['srfs_data'] ?? '[]', true) ?: [];
            $post_doctoral_data = json_decode($support['post_doctoral_data'] ?? '[]', true) ?: [];
            $research_associates_data = json_decode($support['research_associates_data'] ?? '[]', true) ?: [];
            $other_research_data = json_decode($support['other_research_data'] ?? '[]', true) ?: [];
            
            // Calculate research_fellows_count from actual JSON data (more accurate)
            $support['research_fellows_count'] = 
                count($jrfs_data) +
                count($srfs_data) +
                count($post_doctoral_data) +
                count($research_associates_data) +
                count($other_research_data);
            
            // Store parsed JSON data
            $support['jrfs_data_parsed'] = $jrfs_data;
            $support['srfs_data_parsed'] = $srfs_data;
            $support['post_doctoral_data_parsed'] = $post_doctoral_data;
            $support['research_associates_data_parsed'] = $research_associates_data;
            $support['other_research_data_parsed'] = $other_research_data;
            
            // Item 3 calculation: Count ONLY JRFs and SRFs as "PhD students with fellowship"
            // (Post-docs, Research Associates, and Others are NOT PhD students)
            $phd_with_fellowship = count($jrfs_data) + count($srfs_data);
            
            // Update PhD data with fellowship count
            if (isset($data['phd'])) {
                $data['phd']['phd_with_fellowship'] = $phd_with_fellowship;
                error_log("[Section IV] Updated PhD with fellowship count: $phd_with_fellowship (JRFs: " . count($jrfs_data) . ", SRFs: " . count($srfs_data) . ")");
            }
            
            // Parse support initiatives JSON
            $support_initiatives_data = json_decode($support['support_initiatives_data'] ?? '[]', true) ?: [];
            $support['support_initiatives_count'] = count($support_initiatives_data);
            $support['support_initiatives_data_parsed'] = $support_initiatives_data;
            
            // Parse internship JSON and calculate total students
            $internship_data = json_decode($support['internship_data'] ?? '[]', true) ?: [];
            $total_internship_students = 0;
            foreach ($internship_data as $internship) {
                $total_internship_students += (int)($internship['male_count'] ?? 0) + (int)($internship['female_count'] ?? 0);
            }
            $support['internship_students'] = $total_internship_students;
            $support['internship_data_parsed'] = $internship_data;
            
            // Parse research activities JSON
            $research_activities_data = json_decode($support['research_activities_data'] ?? '[]', true) ?: [];
            $support['student_research_activities'] = count($research_activities_data);
            $support['research_activities_data_parsed'] = $research_activities_data;
            
            // Parse awards JSON and separate by category and level
            $awards_data = json_decode($support['awards_data'] ?? '[]', true) ?: [];
            $sports_state = 0;
            $sports_national = 0;
            $sports_international = 0;
            $cultural_count = 0;
            
            foreach ($awards_data as $award) {
                $category = strtolower(trim($award['category'] ?? ''));
                $level = strtolower(trim($award['level'] ?? ''));
                
                if ($category === 'sports') {
                    if ($level === 'state') $sports_state++;
                    elseif ($level === 'national') $sports_national++;
                    elseif ($level === 'international') $sports_international++;
                } elseif ($category === 'cultural') {
                    $cultural_count++;
                }
            }
            
            $support['awards_sports_state'] = $sports_state;
            $support['awards_sports_national'] = $sports_national;
            $support['awards_sports_international'] = $sports_international;
            $support['awards_cultural_count'] = $cultural_count;
            $support['awards_data_parsed'] = $awards_data;
            
            error_log("[Data Fetcher] Parsed counts - Research Fellows: " . $support['research_fellows_count'] . 
                     ", Support Initiatives: " . $support['support_initiatives_count'] . 
                     ", Internship Students: " . $support['internship_students'] . 
                     ", Research Activities: " . $support['student_research_activities'] . 
                     ", Sports Awards: State=" . $support['awards_sports_state'] . ", National=" . $support['awards_sports_national'] . ", Intl=" . $support['awards_sports_international']);
            
            // Merge studentsupport data with existing support data (exec_dev)
            $data['support'] = array_merge($data['support'] ?? [], $support);
        } else {
            error_log("[Data Fetcher] No studentsupport record found for dept_id=$dept_id, A_YEAR=$a_year_int");
        }
        $stmt4->close();
    } else {
        error_log("[Data Fetcher] Failed to prepare studentsupport query");
    }
    
    return $data;
}

/**
 * Section V: Conferences, Workshops & Collaborations
 * CRITICAL FIX: The save handlers now store the FULL academic year format (e.g., "2024-2025")
 * but we need to try both formats for backward compatibility
 */
function fetchSection5($dept_id, $academic_year) {
    global $conn;
    
    $data = [];
    
    // Try full academic year format first (e.g., "2024-2025") - this is what save handlers now use
    // Then fallback to starting year format (e.g., "2024") for backward compatibility
    $a_year_full = $academic_year; // Full format: "2024-2025"
    $a_year_str = substr($academic_year, 0, 4); // Starting year: "2024"
    
    // Conferences & Workshops - Try full format first, then fallback to starting year
    $query1 = "SELECT * FROM conferences_workshops WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $stmt1 = $conn->prepare($query1);
    $conferences = null;
    if ($stmt1) {
        // Try full format first (e.g., "2024-2025")
        $stmt1->bind_param("is", $dept_id, $a_year_full);
        $stmt1->execute();
        $result1 = $stmt1->get_result();
        $conferences = $result1->fetch_assoc();
        
        // If not found, try starting year format (e.g., "2024") for backward compatibility
        if (!$conferences) {
            $stmt1->close();
            $stmt1 = $conn->prepare($query1);
            if ($stmt1) {
                $stmt1->bind_param("is", $dept_id, $a_year_str);
                $stmt1->execute();
                $result1 = $stmt1->get_result();
                $conferences = $result1->fetch_assoc();
                if ($conferences) {
                    error_log("[Section V] Found conferences data with starting year format: $a_year_str (requested: $a_year_full)");
                }
            }
        } else {
            error_log("[Section V] Found conferences data with full year format: $a_year_full");
        }
        
        if ($conferences) {
            $data['conferences'] = $conferences;
            // CRITICAL: Log all possible field name variations for A3 to debug case sensitivity issues
            error_log("[Section V] Conferences data: A1=" . ($conferences['A1'] ?? 'NULL') . ", A2=" . ($conferences['A2'] ?? 'NULL') . ", A3=" . ($conferences['A3'] ?? 'NULL') . ", A4=" . ($conferences['A4'] ?? 'NULL') . ", A5=" . ($conferences['A5'] ?? 'NULL') . ", A6=" . ($conferences['A6'] ?? 'NULL'));
            error_log("[Section V] A3 field check - A3: " . (isset($conferences['A3']) ? $conferences['A3'] : 'NOT SET') . ", a3: " . (isset($conferences['a3']) ? $conferences['a3'] : 'NOT SET') . ", A_3: " . (isset($conferences['A_3']) ? $conferences['A_3'] : 'NOT SET'));
        } else {
            error_log("[Section V] No conferences data found for dept_id=$dept_id, tried A_YEAR=$a_year_full and $a_year_str");
        }
        if ($stmt1) {
            $stmt1->close();
        }
    }
    
    // Collaborations - Try full format first, then fallback to starting year
    $query2 = "SELECT * FROM collaborations WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $stmt2 = $conn->prepare($query2);
    $collaborations = null;
    if ($stmt2) {
        // Try full format first (e.g., "2024-2025")
        $stmt2->bind_param("is", $dept_id, $a_year_full);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $collaborations = $result2->fetch_assoc();
        
        // If not found, try starting year format (e.g., "2024") for backward compatibility
        if (!$collaborations) {
            $stmt2->close();
            $stmt2 = $conn->prepare($query2);
            if ($stmt2) {
                $stmt2->bind_param("is", $dept_id, $a_year_str);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                $collaborations = $result2->fetch_assoc();
                if ($collaborations) {
                    error_log("[Section V] Found collaborations data with starting year format: $a_year_str (requested: $a_year_full)");
                }
            }
        } else {
            error_log("[Section V] Found collaborations data with full year format: $a_year_full");
        }
        
        if ($collaborations) {
            $data['collaborations'] = $collaborations;
            error_log("[Section V] Collaborations data: B1=" . ($collaborations['B1'] ?? 0) . ", B2=" . ($collaborations['B2'] ?? 0) . ", B3=" . ($collaborations['B3'] ?? 0) . ", B4=" . ($collaborations['B4'] ?? 0) . ", B5=" . ($collaborations['B5'] ?? 0));
        } else {
            error_log("[Section V] No collaborations data found for dept_id=$dept_id, tried A_YEAR=$a_year_full and $a_year_str");
        }
        if ($stmt2) {
            $stmt2->close();
        }
    }
    
    return $data;
}

/**
 * Get all supporting documents for a department
 */
function fetchAllSupportingDocuments($dept_id, $academic_year) {
    global $conn;
    
    $query = "SELECT * FROM supporting_documents 
              WHERE dept_id = ? AND academic_year = ? AND status = 'active'
              ORDER BY page_section ASC, serial_number ASC";
    $stmt = $conn->prepare($query);
    $documents = [];
    if ($stmt) {
        $stmt->bind_param("is", $dept_id, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $documents[] = $row;
        }
        $stmt->close();
    }
    return $documents;
}

/**
 * Group documents by section
 */
function groupDocumentsBySection($documents) {
    $grouped = [];
    foreach ($documents as $doc) {
        $section = $doc['page_section'];
        if (!isset($grouped[$section])) {
            $grouped[$section] = [];
        }
        $grouped[$section][] = $doc;
    }
    return $grouped;
}

/**
 * Get documents for a specific section/item
 * Maps common page_section values to their variations
 * Also searches by item-specific keywords in page_section and document_title
 * Can also match by item_number (serial number) if provided
 */
function getDocumentsForSection($grouped_docs, $section_keys, $item_keywords = [], $item_number = null) {
    $docs = [];
    if (!is_array($section_keys)) {
        $section_keys = [$section_keys];
    }
    
    // Common mappings for page_section values
    $section_mappings = [
        'details_of_department' => ['details_of_department', 'details_dept', 'brief_details', 'department_details'],
        'faculty_output' => ['faculty_output', 'faculty_details', 'research_details'],
        'nep_initiatives' => ['nep_initiatives', 'nepmarks', 'nep'],
        'departmental_governance' => ['departmental_governance', 'governance', 'department_data'],
        'student_support' => ['student_support', 'studentsupport'],
        'placement_details' => ['placement_details', 'placement'],
        'intake_actual_strength' => ['intake_actual_strength', 'intake', 'enrolment'],
        'conferences_workshops' => ['conferences_workshops', 'conferences', 'workshops'],
        'collaborations' => ['collaborations', 'collaboration']
    ];
    
    // Exact document title mappings from dept_login (most precise matching)
    $exact_document_titles = [
        // Section I - Faculty Output
        'permanent_faculties_phd' => ['Permanent Faculties with PhD Documentation', 'Permanent Faculties with Ph.D Documentation'],
        'adhoc_faculties_phd' => ['Adhoc Faculties with PhD Documentation', 'Adhoc Faculties with Ph.D Documentation'],
        'state_awards' => ['State Level Awards Documentation'],
        'national_awards' => ['National Level Awards Documentation'],
        'international_awards' => ['International Level Awards Documentation'],
        'international_fellowship' => ['International Fellowship Documentation'],
        'phd_awardees' => ['PhD Awardees Documentation', 'Ph.D Awardees Documentation'],
        'non_govt_projects' => ['Non-Government Sponsored Projects Documentation'],
        'govt_projects' => ['Government Sponsored Projects Documentation'],
        'consultancy' => ['Consultancy Projects Documentation'],
        'corporate_training' => ['Corporate Training Programs Documentation'],
        'recognitions_infra' => ['Department Recognitions & Infrastructure Documentation', 'UGC-SAP', 'CAS', 'DST-FIST', 'DBT', 'ICSSR'],
        'patents_ipr' => ['IPR (Patents/Copyright/Trademarks/Designs) Documentation', 'Patents Filed Documentation', 'Patents Published Documentation', 'Patents Granted Documentation', 'Copyrights Documentation', 'Designs Documentation'],
        'publications_scopus' => ['Scopus/Web of Sciences Journals Documentation'],
        'publications_conference' => ['Conference Publications Documentation'],
        'publications_ugc' => ['UGC Listed Non-Scopus Journals Documentation', 'ISSN Journals & Special Issues Documentation', 'UGC Listed Non-Scopus/Web of Sciences research papers/ISSN Journals Documentation'],
        'books_moocs' => ['Books, Chapters & MOOCs Documentation'],
        // Bibliometrics - exact titles from FacultyOutput.php
        'bibliometrics_impact' => ['Impact Factor & Bibliometrics Documentation'],
        'bibliometrics_hindex' => ['h-index Documentation'],
        
        // Section I - Startup/Innovation Ecosystem Documents
        'dpiit_certificates' => ['DPIIT Recognition Certificates', 'DPIIT Recognition Certificates Documentation'],
        'investment_agreements' => ['Investment Agreements & Proof of Funding', 'Investment Agreements & Proof of Funding Documentation'],
        'grant_letters' => ['Government Grant Letters', 'Government Grant Letters Documentation'],
        'trl_documentation' => ['Technology Readiness Level Documentation', 'Technology Readiness Level Documentation Documentation'],
        'turnover_certificates' => ['Turnover Certificates & Financial Statements', 'Turnover Certificates & Financial Statements Documentation'],
        'alumni_verification' => ['Alumni Founder Verification Documents', 'Alumni Founder Verification Documents Documentation'],
        
        // Section IV - Student Support
        'intake_enrolment' => ['Total Enrolment Proof Documentation'],
        'regional_diversity' => ['Regional Diversity Documentation'],
        'escs_diversity' => ['ESCS Diversity Documentation'],
        'scholarship_freeship' => ['Scholarship/Freeship Documentation'],
        'research_fellows' => ['Research Fellows Documentation', 'Student Support Documentation'],
        // Section IV - Placement Details (from PlacementDetails.php)
        'placement_details' => ['Placement Details', 'Placement Details Documentation'],
        'exam_qualification' => ['Exam Qualifications', 'Exam Qualifications Documentation'],
        'higher_studies' => ['Higher Studies', 'Higher Studies Documentation'],
        
        // Section V - Collaborations
        'industry_collaboration' => ['Industry collaborations', 'Industry Collaboration'],
        'national_academic_collaboration' => ['National Academic collaborations', 'National Academic Collaboration'],
        'international_academic_collaboration' => ['International Academic collaborations', 'International Academic Collaboration'],
    ];
    
    // Item-specific keyword mappings (for finding documents by item description)
    $item_keyword_mappings = [
        'awards_international' => ['international', 'award', 'awards', 'international level awards'],
        'awards_national' => ['national', 'award', 'awards', 'national level awards'],
        'awards_state' => ['state', 'award', 'awards', 'state level awards'],
        'phd' => ['phd', 'ph.d', 'doctorate', 'permanent faculties with phd', 'adhoc faculties with phd'],
        'publications_scopus' => ['scopus', 'publication', 'journal', 'web of science'],
        'publications_conference' => ['conference', 'publication'],
        'ipr' => ['patent', 'ipr', 'copyright', 'trademark', 'designs'],
        'consultancy' => ['consultancy', 'consulting'],
        'nep' => ['nep', 'initiative'],
        'placement' => ['placement', 'job', 'employment'],
        'collaboration_industry' => ['industry', 'collaboration'],
        'collaboration_international' => ['international', 'collaboration']
    ];
    
    foreach ($section_keys as $key) {
        // Check direct match
        if (isset($grouped_docs[$key])) {
            $docs = array_merge($docs, $grouped_docs[$key]);
        }
        
        // Check mapped variations
        if (isset($section_mappings[$key])) {
            foreach ($section_mappings[$key] as $mapped_key) {
                if (isset($grouped_docs[$mapped_key])) {
                    $docs = array_merge($docs, $grouped_docs[$mapped_key]);
                }
            }
        }
        
        // Also check if key contains any of the mapped values
        foreach ($section_mappings as $main_key => $variations) {
            foreach ($variations as $variation) {
                if (stripos($key, $variation) !== false || stripos($variation, $key) !== false) {
                    foreach ($variations as $v) {
                        if (isset($grouped_docs[$v])) {
                            $docs = array_merge($docs, $grouped_docs[$v]);
                        }
                    }
                    break 2;
                }
            }
        }
    }
    
    // CRITICAL: Filter documents by item_number (serial_number) if provided
    // This is the STRICTEST matching - ONLY return documents with exact serial_number match
    // NO FALLBACK - serial number must match exactly to prevent cross-contamination
    // 
    // COMPLETE SERIAL NUMBER MAPPINGS FROM dept_login:
    // 
    // Section I - FacultyOutput.php:
    // 1=State Awards, 2=National Awards, 3=International Awards, 4=Fellowship,
    // 5=Non-govt Projects, 6=Govt Projects, 7=Consultancy, 8=Training, 9=Recognitions,
    // 10=DPIIT Recognition Certificates, 11=Investment Agreements & Proof of Funding,
    // 12=Government Grant Letters, 13=Technology Readiness Level Documentation,
    // 14=Turnover Certificates & Financial Statements, 15=Alumni Founder Verification Documents,
    // 16=IPR/Patents, 22=Scopus, 23=Conference, 24=UGC/ISSN, 27=Impact Factor, 28=h-index, 29=Books,
    // 30=Research Initiative Description Documentation, 31=Collaboration Description Documentation
    //
    // Section II - NEPInitiatives.php:
    // 1=NEP Initiatives, 2=Pedagogical Approaches, 3=Assessments, 4=MOOCs, 5=E-Content, 6=Result Declaration
    //
    // Section IV - StudentSupport.php:
    // 2=Research Fellows, 7=Support Initiatives, 8=Internship/OJT, 13=Research Activity, 14=Sports Awards, 15=Cultural Awards
    //
    // Section IV - IntakeActualStrength.php:
    // 1=Total Enrolment Proof, 2=Regional Diversity, 3=ESCS Diversity, 4=Scholarship/Freeship
    //
    // Section IV - PlacementDetails.php:
    // 1=Placement Details, 2=Exam Qualifications, 3=Higher Studies
    //
    // Section III - Departmental_Governance.php:
    // 1=Inclusive Practices, 2=Green Practices, 3=Teachers in Admin, 4=Awards, 5=Budget,
    // 6=Alumni, 7=CSR, 8=Infrastructure, 9=Peer Perception, 10=Student Feedback,
    // 11=Best Practice, 12=Leadership, 13=ISR Initiatives, 14=ISR Budget, 15=ISR Students,
    // 16=ISR Faculty, 17=Sponsors Total, 18=Sponsors Amount, 19=Volunteer Hours,
    // 20=Partnerships, 21=Key Partners, 22=Department Plan
    //
    // Section V - ConferencesWorkshops.php:
    // 1=Industry-Academia, 2=STTP/Refresher, 3=National Conferences, 4=International Conferences,
    // 5=Teachers as Speakers, 6=Teachers Presentations
    //
    // Section V - Collaborations.php:
    // 1=Industry Collaborations, 2=National Academic, 3=Government, 4=International Academic, 5=Outreach/Social
    //
    // DetailsOfDepartment.php (for Section I Items 1-2):
    // 1=Permanent Faculties with PhD, 2=Adhoc Faculties with PhD
    if ($item_number !== null) {
        $filtered_by_serial = [];
        foreach ($docs as $doc) {
            $doc_serial = (int)($doc['serial_number'] ?? 0);
            
            // CRITICAL: ONLY match by exact serial number - NO FALLBACK to prevent wrong document placement
            if ($doc_serial == $item_number) {
                $filtered_by_serial[] = $doc;
            }
        }
        
        // CRITICAL: ONLY use filtered documents - do NOT fall back to keyword matching
        // If no documents match the serial number, return empty array
        $docs = $filtered_by_serial;
    }
    
    // First, try exact document title matching (most precise)
    if (!empty($item_keywords)) {
        $exact_matches = [];
        $doc_title_lower = '';
        
        foreach ($docs as $doc) {
            $doc_title = $doc['document_title'] ?? '';
            $doc_title_lower = strtolower($doc_title);
            $matched_exact = false;
            
            // Check against exact document title mappings
            foreach ($exact_document_titles as $key => $titles) {
                foreach ($titles as $exact_title) {
                    if (stripos($doc_title, $exact_title) !== false) {
                        // Check if this exact title matches our item keywords
                        foreach ($item_keywords as $keyword) {
                            $keyword_lower = strtolower($keyword);
                            // Map keywords to exact title keys
                            // CRITICAL: Be VERY specific - require exact document title match, no cross-contamination
                            if ($key == 'state_awards' && stripos($doc_title, 'state level awards') !== false && 
                                stripos($doc_title, 'national') === false && 
                                stripos($doc_title, 'international') === false) {
                                // Only match if keyword is 'state' or 'award' AND it's specifically state level
                                if ($keyword_lower == 'state' || ($keyword_lower == 'award' && stripos($doc_title, 'state') !== false)) {
                                    $exact_matches[] = $doc;
                                    $matched_exact = true;
                                    break 2;
                                }
                            } elseif ($key == 'national_awards' && stripos($doc_title, 'national level awards') !== false && 
                                      stripos($doc_title, 'state') === false && 
                                      stripos($doc_title, 'international') === false) {
                                // Only match if keyword is 'national' or 'award' AND it's specifically national level
                                if ($keyword_lower == 'national' || ($keyword_lower == 'award' && stripos($doc_title, 'national') !== false)) {
                                    $exact_matches[] = $doc;
                                    $matched_exact = true;
                                    break 2;
                                }
                            } elseif ($key == 'international_awards' && stripos($doc_title, 'international level awards') !== false && 
                                      stripos($doc_title, 'fellowship') === false) {
                                // Only match if keyword is 'international' or 'award' AND it's specifically international level (NOT fellowship)
                                if ($keyword_lower == 'international' || ($keyword_lower == 'award' && stripos($doc_title, 'international') !== false && stripos($doc_title, 'fellowship') === false)) {
                                    $exact_matches[] = $doc;
                                    $matched_exact = true;
                                    break 2;
                                }
                            } elseif ($key == 'international_fellowship' && stripos($doc_title, 'international fellowship') !== false) {
                                // Only match if keyword is 'international' and 'fellowship'
                                if (($keyword_lower == 'international' || $keyword_lower == 'fellowship') && stripos($doc_title, 'fellowship') !== false) {
                                    $exact_matches[] = $doc;
                                    $matched_exact = true;
                                    break 2;
                                }
                            } elseif ($keyword_lower == 'phd' && in_array($key, ['permanent_faculties_phd', 'adhoc_faculties_phd', 'phd_awardees'])) {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif ($keyword_lower == 'patent' || ($keyword_lower == 'ipr' && $key == 'patents_ipr')) {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif ($keyword_lower == 'scopus' && $key == 'publications_scopus') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif ($keyword_lower == 'conference' && $key == 'publications_conference') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif (($keyword_lower == 'ugc' || $keyword_lower == 'issn' || $keyword_lower == 'non-scopus') && $key == 'publications_ugc') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif (($keyword_lower == 'impact' || $keyword_lower == 'bibliometric') && $key == 'bibliometrics_impact') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif (($keyword_lower == 'h-index' || $keyword_lower == 'h index') && $key == 'bibliometrics_hindex') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif (($keyword_lower == 'dpiit' || $keyword_lower == 'recognition' || $keyword_lower == 'certificate') && $key == 'dpiit_certificates') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif (($keyword_lower == 'investment' || $keyword_lower == 'agreement' || $keyword_lower == 'funding' || $keyword_lower == 'proof') && $key == 'investment_agreements') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif (($keyword_lower == 'grant' || $keyword_lower == 'government' || $keyword_lower == 'letter') && $key == 'grant_letters') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif (($keyword_lower == 'trl' || $keyword_lower == 'technology' || $keyword_lower == 'readiness' || $keyword_lower == 'level') && $key == 'trl_documentation') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif (($keyword_lower == 'turnover' || $keyword_lower == 'certificate' || $keyword_lower == 'financial' || $keyword_lower == 'statement') && $key == 'turnover_certificates') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif (($keyword_lower == 'alumni' || $keyword_lower == 'founder' || $keyword_lower == 'verification' || $keyword_lower == 'forbes') && $key == 'alumni_verification') {
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            } elseif ($key == 'alumni_verification' && (
                                stripos($doc_title, 'alumni') !== false && 
                                (stripos($doc_title, 'founder') !== false || stripos($doc_title, 'verification') !== false || stripos($doc_title, 'forbes') !== false)
                            )) {
                                // Also match if document title contains "alumni" and any of "founder", "verification", or "forbes"
                                $exact_matches[] = $doc;
                                $matched_exact = true;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        
        // If we found exact matches, use those; otherwise fall back to keyword matching
        if (!empty($exact_matches)) {
            $docs = $exact_matches;
        } else {
            // Fall back to keyword matching
            $filtered_docs = [];
            foreach ($docs as $doc) {
                $page_section = strtolower($doc['page_section'] ?? '');
                $doc_title = strtolower($doc['document_title'] ?? '');
                $combined_text = $page_section . ' ' . $doc_title;
                
                $matches = false;
                foreach ($item_keywords as $keyword) {
                    $keyword_lower = strtolower($keyword);
                    // Check if keyword matches any item keyword mapping
                    foreach ($item_keyword_mappings as $item_key => $keywords) {
                        if (in_array($keyword_lower, $keywords)) {
                            foreach ($keywords as $kw) {
                                if (stripos($combined_text, $kw) !== false) {
                                    $matches = true;
                                    break 2;
                                }
                            }
                        }
                    }
                    // Also check direct keyword match (but be more restrictive for UGC/ISSN)
                    if ($keyword_lower == 'ugc' || $keyword_lower == 'issn' || $keyword_lower == 'non-scopus') {
                        // For UGC/ISSN, require VERY specific matching to avoid false positives
                        // Only match if document title contains exact phrases
                        $ugc_phrases = [
                            'ugc listed non-scopus',
                            'ugc listed non scopus',
                            'non-scopus journal',
                            'non scopus journal',
                            'issn journal',
                            'issn journals',
                            'ugc listed non-scopus journals',
                            'ugc listed non scopus journals'
                        ];
                        $matched_ugc = false;
                        foreach ($ugc_phrases as $phrase) {
                            if (stripos($combined_text, $phrase) !== false) {
                                $matched_ugc = true;
                                break;
                            }
                        }
                        // Also check exact document title mapping
                        if (!$matched_ugc) {
                            foreach ($exact_document_titles['publications_ugc'] ?? [] as $exact_title) {
                                if (stripos($doc_title, $exact_title) !== false) {
                                    $matched_ugc = true;
                                    break;
                                }
                            }
                        }
                        if ($matched_ugc) {
                            $matches = true;
                            break;
                        }
                    } else {
                        // For other keywords, use direct match
                        if (stripos($combined_text, $keyword_lower) !== false) {
                            $matches = true;
                            break;
                        }
                    }
                }
                
                if ($matches) {
                    $filtered_docs[] = $doc;
                }
            }
            // Only replace if we found matches, otherwise keep original docs
            if (!empty($filtered_docs)) {
                $docs = $filtered_docs;
            }
        }
    }
    
    // Remove duplicates based on file_path
    $unique_docs = [];
    $seen_paths = [];
    foreach ($docs as $doc) {
        $path = $doc['file_path'] ?? '';
        if (!in_array($path, $seen_paths) && !empty($path)) {
            $seen_paths[] = $path;
            $unique_docs[] = $doc;
        }
    }
    
    return $unique_docs;
}

/**
 * Get program-specific documents for placement_details and intake_actual_strength
 * Documents are stored with program_id (programme_code) and modified section_name format
 * Format: "Placement Details_PROG_{program_code}" or "Intake Actual Strength_PROG_{program_code}"
 */
function getProgramSpecificDocuments($grouped_docs, $page_section, $program_code, $serial_number) {
    $docs = [];
    
    // Get all documents from the section
    $section_docs = [];
    if (isset($grouped_docs[$page_section])) {
        $section_docs = $grouped_docs[$page_section];
    }
    
    // Also check mapped variations
    $section_mappings = [
        'placement_details' => ['placement_details', 'placement'],
        'intake_actual_strength' => ['intake_actual_strength', 'intake', 'enrolment'],
    ];
    
    if (isset($section_mappings[$page_section])) {
        foreach ($section_mappings[$page_section] as $mapped_key) {
            if (isset($grouped_docs[$mapped_key])) {
                $section_docs = array_merge($section_docs, $grouped_docs[$mapped_key]);
            }
        }
    }
    
    // Filter by serial number and program_code
    foreach ($section_docs as $doc) {
        $doc_serial = (int)($doc['serial_number'] ?? 0);
        $doc_program_id = $doc['program_id'] ?? '';
        $doc_section_name = $doc['section_name'] ?? '';
        
        // Must match serial number
        if ($doc_serial != $serial_number) {
            continue;
        }
        
        // Match by program_id (programme_code) OR modified section_name format
        $modified_section_name = '';
        if ($page_section === 'placement_details') {
            if ($serial_number == 1) {
                $modified_section_name = 'Placement Details_PROG_' . $program_code;
            } elseif ($serial_number == 2) {
                $modified_section_name = 'Exam Qualifications_PROG_' . $program_code;
            } elseif ($serial_number == 3) {
                $modified_section_name = 'Higher Studies_PROG_' . $program_code;
            }
        } elseif ($page_section === 'intake_actual_strength') {
            // Intake documents use: "Intake Actual Strength_PROG_{program_code}"
            // Serial numbers: 1=Total Enrolment, 2=Regional Diversity, 3=ESCS Diversity, 4=Scholarship/Freeship
            $modified_section_name = 'Intake Actual Strength_PROG_' . $program_code;
        }
        
        // Match if:
        // 1. program_id matches exactly (programme_code), OR
        // 2. section_name matches the modified format (Placement Details_PROG_{code}), OR
        // 3. section_name matches the base format AND program_code is empty (fallback for older records without program_id)
        $matches = false;
        
        // Convert to strings for comparison (program_code might be stored as string or int)
        $program_code_str = (string)$program_code;
        $doc_program_id_str = (string)$doc_program_id;
        
        if (!empty($program_code_str)) {
            // Primary match: program_id (programme_code) must match exactly
            if ($doc_program_id_str === $program_code_str || $doc_program_id_str == $program_code_str) {
                $matches = true;
            }
            // Secondary match: modified section_name format (e.g., "Placement Details_PROG_456")
            if (!$matches && !empty($modified_section_name)) {
                if ($doc_section_name === $modified_section_name || stripos($doc_section_name, $modified_section_name) !== false) {
                    $matches = true;
                }
            }
        } else {
            // If no program_code provided, only match base section_name (fallback for older records)
            // This should only happen if program_code is truly empty
            if ($page_section === 'placement_details') {
                if (($serial_number == 1 && (stripos($doc_section_name, 'Placement Details') !== false && stripos($doc_section_name, '_PROG_') === false)) ||
                    ($serial_number == 2 && (stripos($doc_section_name, 'Exam Qualifications') !== false && stripos($doc_section_name, '_PROG_') === false)) ||
                    ($serial_number == 3 && (stripos($doc_section_name, 'Higher Studies') !== false && stripos($doc_section_name, '_PROG_') === false))) {
                    $matches = true;
                }
            } elseif ($page_section === 'intake_actual_strength') {
                // For intake, match base section_name without _PROG_ suffix
                if (stripos($doc_section_name, 'Intake Actual Strength') !== false && stripos($doc_section_name, '_PROG_') === false) {
                    $matches = true;
                }
            }
        }
        
        if ($matches) {
            $docs[] = $doc;
        }
    }
    
    // Remove duplicates based on file_path
    $unique_docs = [];
    $seen_paths = [];
    foreach ($docs as $doc) {
        $path = $doc['file_path'] ?? '';
        if (!in_array($path, $seen_paths) && !empty($path)) {
            $seen_paths[] = $path;
            $unique_docs[] = $doc;
        }
    }
    
    return $unique_docs;
}

