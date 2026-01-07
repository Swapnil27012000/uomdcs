<?php
/**
 * Expert Review System - Helper Functions
 * Functions to calculate department scores and fetch review data
 */

require_once(__DIR__ . '/../config.php');

/**
 * Get academic year in YYYY-YYYY format
 * Academic year runs from July to June:
 * - July to June: e.g., July 2024 to June 2025 = "2024-2025"
 * - After June, new academic year starts: July 2025 onwards = "2025-2026"
 * 
 * @return string Academic year in format "YYYY-YYYY"
 */
if (!function_exists('getAcademicYear')) {
    function getAcademicYear() {
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        
        // If month >= 7 (July onwards), academic year is current_year to current_year+1
        // If month < 7 (January to June), academic year is (current_year-2) to (current_year-1)
        if ($current_month >= 7) {
            return $current_year . '-' . ($current_year + 1);
        } else {
            return ($current_year - 2) . '-' . ($current_year - 1);
        }
    }
}

/**
 * Resolve any department identifier (DEPT_ID or DEPT_COLL_NO/code) to canonical DEPT_ID
 */
function resolveDepartmentId($identifier) {
    global $conn;
    if (!$identifier || !$conn) {
        return 0;
    }
    
    $id = (int)$identifier;
    
    // First try matching DEPT_ID directly
    $stmt = $conn->prepare("SELECT DEPT_ID FROM department_master WHERE DEPT_ID = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($found_id);
        if ($stmt->fetch()) {
            $stmt->close();
            return (int)$found_id;
        }
        $stmt->close();
    }
    
    // Fallback: treat identifier as DEPT_COLL_NO / department code
    $stmt = $conn->prepare("SELECT DEPT_ID FROM department_master WHERE DEPT_COLL_NO = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($found_id);
        if ($stmt->fetch()) {
            $stmt->close();
            return (int)$found_id;
        }
        $stmt->close();
    }
    
    return 0;
}

/**
 * Get expert's assigned categories (can have multiple)
 */
function getExpertCategories($expert_email) {
    global $conn;
    $categories = [];
    $stmt = $conn->prepare("SELECT category FROM expert_categories WHERE expert_email = ? ORDER BY category ASC");
    if ($stmt) {
        $stmt->bind_param("s", $expert_email);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row['category'];
        }
        $stmt->close();
    }
    return $categories;
}

/**
 * Get expert's assigned category (first one, for backward compatibility)
 */
function getExpertCategory($expert_email) {
    $categories = getExpertCategories($expert_email);
    return !empty($categories) ? $categories[0] : null;
}

/**
 * Get all departments for a specific category
 * Uses department_profiles.category as the primary source (where departments actually select their category)
 */
function getDepartmentsByCategory($category, $academic_year = null) {
    global $conn;
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Use department_profiles.category as primary source (this is where departments select their category)
    // dp.dept_id may store either DEPT_ID or DEPT_COLL_NO, so we match against both via IN(...)
    $query = "SELECT DISTINCT 
                dm.DEPT_ID,
                dm.DEPT_COLL_NO AS DEPT_CODE,
                COALESCE(dn.collname, dm.DEPT_NAME) AS DEPT_NAME,
                dp.category AS CATEGORY,
                dp.A_YEAR
              FROM department_profiles dp
              INNER JOIN department_master dm 
                ON CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
              LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
              WHERE dp.category = ? AND dp.A_YEAR = ?
              ORDER BY COALESCE(dn.collname, dm.DEPT_NAME) ASC";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("ss", $category, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $departments = [];
        while ($row = $result->fetch_assoc()) {
            $departments[] = $row;
        }
        $stmt->close();
        
        // If no results, log for debugging
        if (empty($departments)) {
            // Debug: Get actual categories in department_profiles
            $debug_query = "SELECT DISTINCT category, COUNT(*) as count 
                           FROM department_profiles 
                           WHERE A_YEAR = ? 
                             AND category IS NOT NULL
                             AND category != ''
                           GROUP BY category
                           ORDER BY category";
            $debug_stmt = $conn->prepare($debug_query);
            if ($debug_stmt) {
                $debug_stmt->bind_param("s", $academic_year);
                $debug_stmt->execute();
                $debug_result = $debug_stmt->get_result();
                $actual_categories = [];
                while ($debug_row = $debug_result->fetch_assoc()) {
                    $actual_categories[] = $debug_row['category'] . " (" . $debug_row['count'] . " depts)";
                }
                $debug_stmt->close();
                
                // Log for debugging
                error_log("[Expert] Looking for category: '$category' (length: " . strlen($category) . ")");
                error_log("[Expert] Academic Year: $academic_year");
                error_log("[Expert] Actual categories in department_profiles: " . implode(", ", $actual_categories));
            }
        }
        
        return $departments;
    }
    return [];
}

/**
 * Calculate marks for a specific item using marks configuration
 */
function calculateItemMarks($item_config, $dept_id, $academic_year) {
    global $conn;
    $marks = 0.0;
    
    if (!$item_config || $item_config['is_active'] != 1) {
        return 0.0;
    }
    
    $calculation_type = $item_config['calculation_type'];
    $points_per_unit = (float)$item_config['points_per_unit'];
    $max_marks = (float)$item_config['max_marks'];
    $data_source_table = $item_config['data_source_table'];
    $data_source_field = $item_config['data_source_field'];
    
    // Skip if no data source configured
    if (empty($data_source_table) || empty($data_source_field)) {
        return 0.0;
    }
    
    try {
        switch ($calculation_type) {
            case 'count':
                // Count records or array items
                if ($item_config['json_field_path']) {
                    // Handle JSON field with path
                    // For nepmarks, handle both VARCHAR and INTEGER A_YEAR formats
                    if ($data_source_table == 'nepmarks') {
                        // Try VARCHAR format first (new format: "2024-2025")
                        $stmt = $conn->prepare("SELECT $data_source_field FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("is", $dept_id, $academic_year);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($row = $result->fetch_assoc()) {
                                $json_data = json_decode($row[$data_source_field] ?? '[]', true);
                                if (is_array($json_data)) {
                                    $count = count($json_data);
                                    $marks = min($count * $points_per_unit, $max_marks);
                                }
                                $stmt->close();
                            } else {
                                $stmt->close();
                                // Fallback: Try INTEGER format (old format: ending year only, e.g., 2025 for "2024-2025")
                                $a_year_int = (int)substr($academic_year, -4);
                                $stmt_fallback = $conn->prepare("SELECT $data_source_field FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
                                if ($stmt_fallback) {
                                    $stmt_fallback->bind_param("ii", $dept_id, $a_year_int);
                                    $stmt_fallback->execute();
                                    $result_fallback = $stmt_fallback->get_result();
                                    if ($row_fallback = $result_fallback->fetch_assoc()) {
                                        $json_data = json_decode($row_fallback[$data_source_field] ?? '[]', true);
                                        if (is_array($json_data)) {
                                            $count = count($json_data);
                                            $marks = min($count * $points_per_unit, $max_marks);
                                        }
                                    }
                                    $stmt_fallback->close();
                                }
                            }
                        }
                    } else {
                        // For other tables, use standard query
                        $stmt = $conn->prepare("SELECT $data_source_field FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("is", $dept_id, $academic_year);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($row = $result->fetch_assoc()) {
                                $json_data = json_decode($row[$data_source_field] ?? '[]', true);
                                if (is_array($json_data)) {
                                    $count = count($json_data);
                                    $marks = min($count * $points_per_unit, $max_marks);
                                }
                            }
                            $stmt->close();
                        }
                    }
                } else {
                    // Count numeric value - for nepmarks, get the count value directly (not SUM)
                    if ($data_source_table == 'nepmarks' && in_array($data_source_field, ['nep_count', 'ped_count', 'assess_count', 'moocs', 'econtent'])) {
                        // Try VARCHAR format first (new format: "2024-2025")
                        $stmt = $conn->prepare("SELECT $data_source_field FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("is", $dept_id, $academic_year);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($row = $result->fetch_assoc()) {
                                $count = (float)($row[$data_source_field] ?? 0);
                                $marks = min($count * $points_per_unit, $max_marks);
                                $stmt->close();
                            } else {
                                $stmt->close();
                                // Fallback: Try INTEGER format (old format: ending year only, e.g., 2025 for "2024-2025")
                                $a_year_int = (int)substr($academic_year, -4);
                                $stmt_fallback = $conn->prepare("SELECT $data_source_field FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
                                if ($stmt_fallback) {
                                    $stmt_fallback->bind_param("ii", $dept_id, $a_year_int);
                                    $stmt_fallback->execute();
                                    $result_fallback = $stmt_fallback->get_result();
                                    if ($row_fallback = $result_fallback->fetch_assoc()) {
                                        $count = (float)($row_fallback[$data_source_field] ?? 0);
                                        $marks = min($count * $points_per_unit, $max_marks);
                                    }
                                    $stmt_fallback->close();
                                }
                            }
                        }
                    } else {
                        // For other tables, use SUM
                        $stmt = $conn->prepare("SELECT SUM($data_source_field) as total FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ?");
                        if ($stmt) {
                            $stmt->bind_param("is", $dept_id, $academic_year);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($row = $result->fetch_assoc()) {
                                $count = (float)($row['total'] ?? 0);
                                $marks = min($count * $points_per_unit, $max_marks);
                            }
                            $stmt->close();
                        }
                    }
                }
                break;
                
            case 'sum':
                // Sum values
                $multiplier_field = $item_config['multiplier_field'];
                if ($multiplier_field) {
                    // Multiply with another field (e.g., amount * points_per_unit)
                    $stmt = $conn->prepare("SELECT SUM($data_source_field * $multiplier_field) as total FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ?");
                } else {
                    $stmt = $conn->prepare("SELECT SUM($data_source_field) as total FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ?");
                }
                if ($stmt) {
                    $stmt->bind_param("is", $dept_id, $academic_year);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $sum = (float)($row['total'] ?? 0);
                        if ($multiplier_field) {
                            $marks = min($sum * $points_per_unit, $max_marks);
                        } else {
                            $marks = min($sum, $max_marks);
                        }
                    }
                    $stmt->close();
                }
                break;
                
            case 'conditional':
                // Conditional calculation (e.g., result days: 0-30=5, 31-45=2.5, 46+=0)
                // For nepmarks, handle both VARCHAR and INTEGER A_YEAR formats
                if ($data_source_table == 'nepmarks') {
                    // Try VARCHAR format first (new format: "2024-2025")
                    $stmt = $conn->prepare("SELECT $data_source_field FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param("is", $dept_id, $academic_year);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $value = (float)($row[$data_source_field] ?? 0);
                            $condition_value = $item_config['condition_value'];
                            
                            // Parse condition (e.g., "0-30:5,31-45:2.5,46+:0")
                            if ($condition_value) {
                                $conditions = explode(',', $condition_value);
                                foreach ($conditions as $condition) {
                                    $parts = explode(':', $condition);
                                    if (count($parts) == 2) {
                                        $range = trim($parts[0]);
                                        $marks_value = (float)trim($parts[1]);
                                        
                                        if (strpos($range, '+') !== false) {
                                            // Greater than or equal
                                            $min = (float)str_replace('+', '', $range);
                                            if ($value >= $min) {
                                                $marks = $marks_value;
                                                break;
                                            }
                                        } elseif (strpos($range, '-') !== false) {
                                            // Range
                                            $range_parts = explode('-', $range);
                                            $min = (float)trim($range_parts[0]);
                                            $max = (float)trim($range_parts[1]);
                                            if ($value >= $min && $value <= $max) {
                                                $marks = $marks_value;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                            $stmt->close();
                        } else {
                            $stmt->close();
                            // Fallback: Try INTEGER format (old format: ending year only, e.g., 2025 for "2024-2025")
                            $a_year_int = (int)substr($academic_year, -4);
                            $stmt_fallback = $conn->prepare("SELECT $data_source_field FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
                            if ($stmt_fallback) {
                                $stmt_fallback->bind_param("ii", $dept_id, $a_year_int);
                                $stmt_fallback->execute();
                                $result_fallback = $stmt_fallback->get_result();
                                if ($row_fallback = $result_fallback->fetch_assoc()) {
                                    $value = (float)($row_fallback[$data_source_field] ?? 0);
                                    $condition_value = $item_config['condition_value'];
                                    
                                    // Parse condition
                                    if ($condition_value) {
                                        $conditions = explode(',', $condition_value);
                                        foreach ($conditions as $condition) {
                                            $parts = explode(':', $condition);
                                            if (count($parts) == 2) {
                                                $range = trim($parts[0]);
                                                $marks_value = (float)trim($parts[1]);
                                                
                                                if (strpos($range, '+') !== false) {
                                                    $min = (float)str_replace('+', '', $range);
                                                    if ($value >= $min) {
                                                        $marks = $marks_value;
                                                        break;
                                                    }
                                                } elseif (strpos($range, '-') !== false) {
                                                    $range_parts = explode('-', $range);
                                                    $min = (float)trim($range_parts[0]);
                                                    $max = (float)trim($range_parts[1]);
                                                    if ($value >= $min && $value <= $max) {
                                                        $marks = $marks_value;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                $stmt_fallback->close();
                            }
                        }
                    }
                } else {
                    // For other tables, use standard query
                    $stmt = $conn->prepare("SELECT $data_source_field FROM $data_source_table WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param("is", $dept_id, $academic_year);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $value = (float)($row[$data_source_field] ?? 0);
                            $condition_value = $item_config['condition_value'];
                            
                            // Parse condition (e.g., "0-30:5,31-45:2.5,46+:0")
                            if ($condition_value) {
                                $conditions = explode(',', $condition_value);
                                foreach ($conditions as $condition) {
                                    $parts = explode(':', $condition);
                                    if (count($parts) == 2) {
                                        $range = trim($parts[0]);
                                        $marks_value = (float)trim($parts[1]);
                                        
                                        if (strpos($range, '+') !== false) {
                                            // Greater than or equal
                                            $min = (float)str_replace('+', '', $range);
                                            if ($value >= $min) {
                                                $marks = $marks_value;
                                                break;
                                            }
                                        } elseif (strpos($range, '-') !== false) {
                                            // Range
                                            $range_parts = explode('-', $range);
                                            $min = (float)trim($range_parts[0]);
                                            $max = (float)trim($range_parts[1]);
                                            if ($value >= $min && $value <= $max) {
                                                $marks = $marks_value;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $stmt->close();
                    }
                }
                break;
                
            case 'custom':
                // Custom calculation - use custom query if provided
                if ($item_config['data_source_query']) {
                    // Execute custom query (be careful with SQL injection - queries should be predefined)
                    $query = str_replace(['{dept_id}', '{academic_year}'], [$dept_id, $academic_year], $item_config['data_source_query']);
                    $result = $conn->query($query);
                    if ($result && $row = $result->fetch_assoc()) {
                        $marks = (float)($row['marks'] ?? 0);
                        $marks = min($marks, $max_marks);
                    }
                }
                break;
        }
    } catch (Exception $e) {
        error_log("Error calculating marks for item {$item_config['id']}: " . $e->getMessage());
    }
    
    return min($marks, $max_marks);
}

/**
 * Calculate Section I Score (Faculty Output, Research) - Max 300
 * Uses marks_configuration table dynamically
 */
function calculateSection1Score($dept_id, $academic_year) {
    global $conn;
    $total_score = 0.0;
    
    // Get all active items for Section I
    $stmt = $conn->prepare("SELECT * FROM marks_configuration 
        WHERE section_number = 1 AND is_active = 1 
        ORDER BY priority ASC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($item = $result->fetch_assoc()) {
            $item_marks = calculateItemMarks($item, $dept_id, $academic_year);
            $total_score += $item_marks;
        }
        $stmt->close();
    }
    
    return min($total_score, 300); // Cap at 300
}

/**
 * Calculate Section II Score (NEP Initiatives) - Max 100
 * Uses marks_configuration table dynamically
 */
function calculateSection2Score($dept_id, $academic_year) {
    global $conn;
    $total_score = 0.0;
    
    // Get all active items for Section II
    $stmt = $conn->prepare("SELECT * FROM marks_configuration 
        WHERE section_number = 2 AND is_active = 1 
        ORDER BY priority ASC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($item = $result->fetch_assoc()) {
            $item_marks = calculateItemMarks($item, $dept_id, $academic_year);
            $total_score += $item_marks;
        }
        $stmt->close();
    }
    
    return min($total_score, 100); // Cap at 100
}

/**
 * Calculate Section III Score (Departmental Governance) - Max 110
 * Uses marks_configuration table dynamically
 */
function calculateSection3Score($dept_id, $academic_year) {
    global $conn;
    
    // Fetch Section III data from department_data (no A_YEAR column, just DEPT_ID)
    $stmt = $conn->prepare("SELECT * FROM department_data WHERE DEPT_ID = ? LIMIT 1");
    if (!$stmt) {
        error_log("calculateSection3Score: Failed to prepare statement for dept_id=$dept_id");
        return 0.0;
    }
    
    $stmt->bind_param("i", $dept_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $dept_data = $result->fetch_assoc();
    $stmt->close();
    
    if (!$dept_data) {
        error_log("calculateSection3Score: No data found for dept_id=$dept_id");
        return 0.0;
    }
    
    // Debug log
    error_log("calculateSection3Score: dept_id=$dept_id, alumni=" . ($dept_data['alumni_contribution_amount'] ?? 'NULL') . 
              ", csr=" . ($dept_data['csr_funding_amount'] ?? 'NULL') . 
              ", infra_infrastructural=" . (isset($dept_data['infrastructure_infrastructural']) ? 'EXISTS' : 'MISSING'));
    
    // NEW UDRF Section III: 12 items, 110 marks total (same logic as dashboard/review_complete)
    $section_3_score = 0;
    
    // Item 1: Inclusive Practices (2 marks each, max 10)
    $inclusive_items = !empty($dept_data['inclusive_practices']) ? 
        (is_array($dept_data['inclusive_practices']) ? $dept_data['inclusive_practices'] : explode(',', $dept_data['inclusive_practices'])) : [];
    $section_3_score += min(count($inclusive_items) * 2, 10);
    
    // Item 2: Green Practices (1 mark each, max 10)
    $green_items = !empty($dept_data['green_practices']) ? 
        (is_array($dept_data['green_practices']) ? $dept_data['green_practices'] : explode(',', $dept_data['green_practices'])) : [];
    $section_3_score += min(count($green_items), 10);
    
    // Item 3: Teachers in Admin Roles (1 mark per 10%, max 10)
    $teachers_in_admin_count = (int)($dept_data['teachers_in_admin_count'] ?? 0);
    $total_teachers = (int)($dept_data['total_teachers'] ?? 1);
    $teachers_admin_percent = ($total_teachers > 0) ? ($teachers_in_admin_count / $total_teachers) * 100 : 0;
    $section_3_score += min(floor($teachers_admin_percent / 10), 10);
    
    // Item 4: Extension Awards (2 marks per award, max 10)
    $section_3_score += min((int)($dept_data['awards_extension'] ?? 0) * 2, 10);
    
    // Item 5: Budget Utilization (50% = 2.5 marks, proportionate, max 5)
    $budget_allocated = (float)($dept_data['budget_allocated'] ?? 0);
    $budget_utilized = (float)($dept_data['budget_utilized'] ?? 0);
    if ($budget_allocated > 0) {
        $utilization_percent = ($budget_utilized / $budget_allocated) * 100;
        $section_3_score += min(($utilization_percent / 50) * 2.5, 5);
    }
    
    // Item 6: Alumni Contribution (bracket-based, max 10)
    $alumni_funding = (float)($dept_data['alumni_contribution_amount'] ?? 0);
    if ($alumni_funding >= 10) $section_3_score += 10;
    elseif ($alumni_funding > 8 && $alumni_funding < 10) $section_3_score += 9;
    elseif ($alumni_funding > 6 && $alumni_funding <= 8) $section_3_score += 8;
    elseif ($alumni_funding > 5 && $alumni_funding <= 6) $section_3_score += 7;
    elseif ($alumni_funding > 4 && $alumni_funding <= 5) $section_3_score += 6;
    elseif ($alumni_funding > 3 && $alumni_funding <= 4) $section_3_score += 5;
    elseif ($alumni_funding > 2 && $alumni_funding <= 3) $section_3_score += 4;
    elseif ($alumni_funding > 1 && $alumni_funding <= 2) $section_3_score += 3;
    elseif ($alumni_funding > 0.5 && $alumni_funding <= 1) $section_3_score += 2;
    elseif ($alumni_funding >= 0.1 && $alumni_funding <= 0.5) $section_3_score += 1;
    
    // Item 7: CSR Funding (bracket-based, max 10)
    $csr_funding = (float)($dept_data['csr_funding_amount'] ?? 0);
    if ($csr_funding >= 15) $section_3_score += 10;
    elseif ($csr_funding > 12) $section_3_score += 9;
    elseif ($csr_funding > 10) $section_3_score += 8;
    elseif ($csr_funding > 8) $section_3_score += 7;
    elseif ($csr_funding > 6) $section_3_score += 4;
    elseif ($csr_funding > 4) $section_3_score += 3;
    elseif ($csr_funding > 2) $section_3_score += 2;
    elseif ($csr_funding >= 1) $section_3_score += 1;
    
    // Item 8: Infrastructure (auto-score based on text quality, max 10)
    $infra_texts = [
        trim($dept_data['infrastructure_infrastructural'] ?? ''),
        trim($dept_data['infrastructure_it_digital'] ?? ''),
        trim($dept_data['infrastructure_library'] ?? ''),
        trim($dept_data['infrastructure_laboratory'] ?? '')
    ];
    foreach ($infra_texts as $text) {
        if (!empty($text) && $text !== '-' && $text !== 'Not provided') {
            $text_length = strlen($text);
            if ($text_length >= 10) {
                if ($text_length < 50) {
                    $section_3_score += 0.75;
                } elseif ($text_length < 100) {
                    $section_3_score += 1.25;
                } elseif ($text_length < 200) {
                    $section_3_score += 1.50;
                } else {
                    $section_3_score += 1.75;
                }
            }
        }
    }
    
    // Items 9-12: Narrative/Expert Evaluated (auto-score based on text quality)
    // Item 9: Peer Perception (Max 10)
    $item9_text = trim(($dept_data['peer_perception_rate'] ?? '') . "\n" . ($dept_data['peer_perception_notes'] ?? ''));
    $item9_auto = 0;
    if (!empty($item9_text) && $item9_text !== '-' && $item9_text !== 'Not provided') {
        $len = strlen($item9_text);
        if ($len >= 10) {
            if ($len < 50) $item9_auto = 10 * 0.30;
            elseif ($len < 100) $item9_auto = 10 * 0.50;
            elseif ($len < 200) $item9_auto = 10 * 0.60;
            else $item9_auto = 10 * 0.70;
        }
    }
    $section_3_score += min($item9_auto, 10);
    
    // Item 10: Student Feedback (Max 10)
    $item10_text = trim(($dept_data['student_feedback_rate'] ?? '') . "\n" . ($dept_data['student_feedback_notes'] ?? ''));
    $item10_auto = 0;
    if (!empty($item10_text) && $item10_text !== '-' && $item10_text !== 'Not provided') {
        $len = strlen($item10_text);
        if ($len >= 10) {
            if ($len < 50) $item10_auto = 10 * 0.30;
            elseif ($len < 100) $item10_auto = 10 * 0.50;
            elseif ($len < 200) $item10_auto = 10 * 0.60;
            else $item10_auto = 10 * 0.70;
        }
    }
    $section_3_score += min($item10_auto, 10);
    
    // Item 11: Best Practice (Max 5)
    $item11_text = trim($dept_data['best_practice'] ?? '');
    $item11_auto = 0;
    if (!empty($item11_text) && $item11_text !== '-' && $item11_text !== 'Not provided') {
        $len = strlen($item11_text);
        if ($len >= 10) {
            if ($len < 50) $item11_auto = 5 * 0.30;
            elseif ($len < 100) $item11_auto = 5 * 0.50;
            elseif ($len < 200) $item11_auto = 5 * 0.60;
            else $item11_auto = 5 * 0.70;
        }
    }
    $section_3_score += min($item11_auto, 5);
    
    // Item 12: Synchronization (Max 10)
    $item12_text = trim($dept_data['leadership_sync'] ?? '');
    $item12_auto = 0;
    if (!empty($item12_text) && $item12_text !== '-' && $item12_text !== 'Not provided') {
        $len = strlen($item12_text);
        if ($len >= 10) {
            if ($len < 50) $item12_auto = 10 * 0.30;
            elseif ($len < 100) $item12_auto = 10 * 0.50;
            elseif ($len < 200) $item12_auto = 10 * 0.60;
            else $item12_auto = 10 * 0.70;
        }
    }
    $section_3_score += min($item12_auto, 10);
    
    return min($section_3_score, 110); // Cap at 110
}

/**
 * Calculate Section III Score from section_3 data array
 * This is a reusable function that accepts section_3 array (from dept_data['section_3'])
 * Use this for consistent calculation across all views (PDF, dashboard, etc.)
 * 
 * @param array $sec3 Section III data array (from dept_data['section_3'] ?? [])
 * @return float Section III score (capped at 110)
 */
function calculateSection3FromArray($sec3) {
    $section_3_score = 0;
    
    // CRITICAL: If sec3 is empty or null, return 0 immediately
    if (empty($sec3) || !is_array($sec3)) {
        error_log("[calculateSection3FromArray] Empty or invalid sec3 data, returning 0");
        return 0;
    }
    
    // Item 1: Inclusive Practices (2 marks each, max 10)
    // CRITICAL FIX: Only count if there are actual items (not empty string, not null)
    $inclusive_items = [];
    if (!empty($sec3['inclusive_practices'])) {
        if (is_array($sec3['inclusive_practices'])) {
            $inclusive_items = array_filter($sec3['inclusive_practices'], function($item) {
                return !empty($item) && $item !== '-' && $item !== '0' && trim($item) !== '';
            });
        } else {
            $items = explode(',', $sec3['inclusive_practices']);
            $inclusive_items = array_filter($items, function($item) {
                return !empty($item) && $item !== '-' && $item !== '0' && trim($item) !== '';
            });
        }
    }
    $section_3_score += min(count($inclusive_items) * 2, 10);
    
    // Item 2: Green Practices (1 mark each, max 10)
    // CRITICAL FIX: Only count if there are actual items (not empty string, not null)
    $green_items = [];
    if (!empty($sec3['green_practices'])) {
        if (is_array($sec3['green_practices'])) {
            $green_items = array_filter($sec3['green_practices'], function($item) {
                return !empty($item) && $item !== '-' && $item !== '0' && trim($item) !== '';
            });
        } else {
            $items = explode(',', $sec3['green_practices']);
            $green_items = array_filter($items, function($item) {
                return !empty($item) && $item !== '-' && $item !== '0' && trim($item) !== '';
            });
        }
    }
    $section_3_score += min(count($green_items), 10);
    
    // Item 3: Teachers in Admin Roles (1 mark per 10%, max 10)
    // CRITICAL FIX: Only calculate if both values are actually set and > 0
    $teachers_in_admin_count = (int)($sec3['teachers_in_admin_count'] ?? 0);
    $total_teachers = (int)($sec3['total_teachers'] ?? 0);
    if ($total_teachers > 0 && $teachers_in_admin_count >= 0) {
        $teachers_admin_percent = ($teachers_in_admin_count / $total_teachers) * 100;
        $section_3_score += min(floor($teachers_admin_percent / 10), 10);
    }
    // If total_teachers is 0 or not set, give 0 marks (no data entered)
    
    // Item 4: Extension Awards (2 marks per award, max 10)
    // CRITICAL FIX: Only give marks if awards_extension is actually set and > 0
    $awards_extension = (int)($sec3['awards_extension'] ?? 0);
    if ($awards_extension > 0) {
        $section_3_score += min($awards_extension * 2, 10);
    }
    
    // Item 5: Budget Utilization (50% = 2.5 marks, proportionate, max 5)
    $budget_allocated = (float)($sec3['budget_allocated'] ?? 0);
    $budget_utilized = (float)($sec3['budget_utilized'] ?? 0);
    if ($budget_allocated > 0) {
        $utilization_percent = ($budget_utilized / $budget_allocated) * 100;
        $section_3_score += min(($utilization_percent / 50) * 2.5, 5);
    }
    
    // Item 6: Alumni Contribution (bracket-based, max 10)
    // CRITICAL FIX: Only give marks if alumni_funding is actually set and > 0
    $alumni_funding = (float)($sec3['alumni_contribution_amount'] ?? 0); // in Lakhs
    if ($alumni_funding > 0) {
        if ($alumni_funding >= 10) $section_3_score += 10;
        elseif ($alumni_funding > 8 && $alumni_funding < 10) $section_3_score += 9;
        elseif ($alumni_funding > 6 && $alumni_funding <= 8) $section_3_score += 8;
        elseif ($alumni_funding > 5 && $alumni_funding <= 6) $section_3_score += 7;
        elseif ($alumni_funding > 4 && $alumni_funding <= 5) $section_3_score += 6;
        elseif ($alumni_funding > 3 && $alumni_funding <= 4) $section_3_score += 5;
        elseif ($alumni_funding > 2 && $alumni_funding <= 3) $section_3_score += 4;
        elseif ($alumni_funding > 1 && $alumni_funding <= 2) $section_3_score += 3;
        elseif ($alumni_funding > 0.5 && $alumni_funding <= 1) $section_3_score += 2;
        elseif ($alumni_funding >= 0.1 && $alumni_funding <= 0.5) $section_3_score += 1;
    }
    // If alumni_funding is 0 or not set, give 0 marks (no data entered)
    
    // Item 7: CSR Funding (bracket-based, max 10)
    // CRITICAL FIX: Only give marks if csr_funding is actually set and > 0
    $csr_funding = (float)($sec3['csr_funding_amount'] ?? 0); // in Lakhs
    if ($csr_funding > 0) {
        if ($csr_funding >= 15) $section_3_score += 10;
        elseif ($csr_funding > 12) $section_3_score += 9;
        elseif ($csr_funding > 10) $section_3_score += 8;
        elseif ($csr_funding > 8) $section_3_score += 7;
        elseif ($csr_funding > 6) $section_3_score += 4;
        elseif ($csr_funding > 4) $section_3_score += 3;
        elseif ($csr_funding > 2) $section_3_score += 2;
        elseif ($csr_funding >= 1) $section_3_score += 1;
    }
    // If csr_funding is 0 or not set, give 0 marks (no data entered)
    
    // Item 8: Infrastructure (auto-score based on text quality, max 10)
    // Calculate auto-scores based on response quality (max 2.5 per area, 4 areas = 10 total)
    $infra_texts = [
        'infrastructural' => trim($sec3['infrastructure_infrastructural'] ?? ''),
        'it_digital' => trim($sec3['infrastructure_it_digital'] ?? ''),
        'library' => trim($sec3['infrastructure_library'] ?? ''),
        'laboratory' => trim($sec3['infrastructure_laboratory'] ?? '')
    ];
    
    foreach ($infra_texts as $area_key => $text) {
        if (!empty($text) && $text !== '-' && $text !== 'Not provided') {
            $text_length = strlen($text);
            // Auto-scoring based on response quality (max 2.5 per area)
            if ($text_length >= 10) {
                if ($text_length < 50) {
                    $section_3_score += 0.75; // 30% of 2.5 for short responses
                } elseif ($text_length < 100) {
                    $section_3_score += 1.25; // 50% of 2.5 for medium responses
                } elseif ($text_length < 200) {
                    $section_3_score += 1.50; // 60% of 2.5 for good responses
                } else {
                    $section_3_score += 1.75; // 70% of 2.5 for substantial responses
                }
            }
        }
    }
    
    // Items 9-12: Narrative/Expert Evaluated (auto-score based on text quality)
    // Item 9: Peer Perception (Max 10)
    $item9_text = trim(($sec3['peer_perception_rate'] ?? '') . "\n" . ($sec3['peer_perception_notes'] ?? ''));
    $item9_auto = 0;
    if (!empty($item9_text) && $item9_text !== '-' && $item9_text !== 'Not provided') {
        $len = strlen($item9_text);
        if ($len >= 10) {
            if ($len < 50) $item9_auto = 10 * 0.30;
            elseif ($len < 100) $item9_auto = 10 * 0.50;
            elseif ($len < 200) $item9_auto = 10 * 0.60;
            else $item9_auto = 10 * 0.70;
        }
    }
    $section_3_score += min($item9_auto, 10);
    
    // Item 10: Student Feedback (Max 10)
    $item10_text = trim(($sec3['student_feedback_rate'] ?? '') . "\n" . ($sec3['student_feedback_notes'] ?? ''));
    $item10_auto = 0;
    if (!empty($item10_text) && $item10_text !== '-' && $item10_text !== 'Not provided') {
        $len = strlen($item10_text);
        if ($len >= 10) {
            if ($len < 50) $item10_auto = 10 * 0.30;
            elseif ($len < 100) $item10_auto = 10 * 0.50;
            elseif ($len < 200) $item10_auto = 10 * 0.60;
            else $item10_auto = 10 * 0.70;
        }
    }
    $section_3_score += min($item10_auto, 10);
    
    // Item 11: Best Practice (Max 5)
    $item11_text = trim($sec3['best_practice'] ?? '');
    $item11_auto = 0;
    if (!empty($item11_text) && $item11_text !== '-' && $item11_text !== 'Not provided') {
        $len = strlen($item11_text);
        if ($len >= 10) {
            if ($len < 50) $item11_auto = 5 * 0.30;
            elseif ($len < 100) $item11_auto = 5 * 0.50;
            elseif ($len < 200) $item11_auto = 5 * 0.60;
            else $item11_auto = 5 * 0.70;
        }
    }
    $section_3_score += min($item11_auto, 5);
    
    // Item 12: Synchronization (Max 10)
    $item12_text = trim($sec3['leadership_sync'] ?? '');
    $item12_auto = 0;
    if (!empty($item12_text) && $item12_text !== '-' && $item12_text !== 'Not provided') {
        $len = strlen($item12_text);
        if ($len >= 10) {
            if ($len < 50) $item12_auto = 10 * 0.30;
            elseif ($len < 100) $item12_auto = 10 * 0.50;
            elseif ($len < 200) $item12_auto = 10 * 0.60;
            else $item12_auto = 10 * 0.70;
        }
    }
    $section_3_score += min($item12_auto, 10);
    
    return min($section_3_score, 110); // Cap at 110
}

/**
 * Calculate Section II Score from data array (NEP Initiatives) - Max 100
 * Centralized calculation function for consistency
 */
function calculateSection2FromArray($sec2_data) {
    $section_2_score = 0;
    
    // Item 1: NEP Initiatives (2 marks each, max 30)
    $nep_count = (int)($sec2_data['nep_count'] ?? 0);
    if ($nep_count == 0 && !empty($sec2_data['nep_initiatives'])) {
        $nep_initiatives = is_string($sec2_data['nep_initiatives']) ? json_decode($sec2_data['nep_initiatives'], true) : $sec2_data['nep_initiatives'];
        if (is_array($nep_initiatives)) {
            $nep_count = count($nep_initiatives);
        }
    }
    $section_2_score += min($nep_count * 2, 30);
    
    // Item 2: Pedagogical Approaches (2 marks each, max 20)
    $ped_count = (int)($sec2_data['ped_count'] ?? 0);
    if ($ped_count == 0 && !empty($sec2_data['pedagogical'])) {
        $pedagogical = is_string($sec2_data['pedagogical']) ? json_decode($sec2_data['pedagogical'], true) : $sec2_data['pedagogical'];
        if (is_array($pedagogical)) {
            $ped_count = count($pedagogical);
        }
    }
    $section_2_score += min($ped_count * 2, 20);
    
    // Item 3: Assessments (2 marks each, max 20)
    $assess_count = (int)($sec2_data['assess_count'] ?? 0);
    if ($assess_count == 0 && !empty($sec2_data['assessments'])) {
        $assessments = is_string($sec2_data['assessments']) ? json_decode($sec2_data['assessments'], true) : $sec2_data['assessments'];
        if (is_array($assessments)) {
            $assess_count = count($assessments);
        }
    }
    $section_2_score += min($assess_count * 2, 20);
    
    // Item 4: MOOCs (2 marks each, max 10)
    $moocs = (int)($sec2_data['moocs'] ?? 0);
    $section_2_score += min($moocs * 2, 10);
    
    // Item 5: E-Content (1 mark per credit, max 15)
    $econtent = (float)($sec2_data['econtent'] ?? 0);
    $section_2_score += min($econtent, 15);
    
    // Item 6: Result Declaration (conditional, max 5)
    $result_days = (int)($sec2_data['result_days'] ?? 0);
    if ($result_days > 0 && $result_days <= 30) {
        $section_2_score += 5;
    } elseif ($result_days > 30 && $result_days <= 45) {
        $section_2_score += 2.5;
    }
    
    return min($section_2_score, 100); // Cap at 100
}

/**
 * Calculate Section IV Score from data array (Student Support) - Max 140
 * Centralized calculation function for consistency
 */
function calculateSection4FromArray($sec4_data) {
    $section_4_score = 0;
    
    // CRITICAL: If sec4_data is empty or null, return 0 immediately
    if (empty($sec4_data) || !is_array($sec4_data)) {
        error_log("[calculateSection4FromArray] Empty or invalid sec4_data, returning 0");
        return 0;
    }
    
    $intake_data = $sec4_data['intake'] ?? [];
    $placement_data = $sec4_data['placement'] ?? [];
    $phd_data = $sec4_data['phd'] ?? [];
    $support_data = $sec4_data['support'] ?? [];
    
    $total_intake = (int)($intake_data['total_intake'] ?? 0);
    $total_enrolled = (int)($intake_data['total_enrolled'] ?? 0);
    
    // Item 1: Enrolment Ratio (Max 10 marks)
    if ($total_intake > 0) {
        $enrolment_ratio = $total_enrolled / $total_intake;
        if ($enrolment_ratio < 1) {
            $section_4_score += 0;
        } elseif ($enrolment_ratio >= 1 && $enrolment_ratio < 2) {
            $section_4_score += 1;
        } elseif ($enrolment_ratio >= 2 && $enrolment_ratio < 3) {
            $section_4_score += 2;
        } elseif ($enrolment_ratio >= 3 && $enrolment_ratio < 4) {
            $section_4_score += 4;
        } elseif ($enrolment_ratio >= 4 && $enrolment_ratio < 5) {
            $section_4_score += 6;
        } elseif ($enrolment_ratio >= 5 && $enrolment_ratio < 6) {
            $section_4_score += 8;
        } else {
            $section_4_score += 10;
        }
    }
    
    // Item 2: Admission Percentage (Max 10 marks)
    if ($total_intake > 0) {
        $admission_percent = ($total_enrolled / $total_intake) * 100;
        if ($admission_percent >= 100) {
            $section_4_score += 10;
        } elseif ($admission_percent >= 90) {
            $section_4_score += 9;
        } elseif ($admission_percent >= 80) {
            $section_4_score += 8;
        } elseif ($admission_percent >= 70) {
            $section_4_score += 7;
        } elseif ($admission_percent >= 60) {
            $section_4_score += 6;
        } elseif ($admission_percent >= 50) {
            $section_4_score += 5;
        } elseif ($admission_percent >= 40) {
            $section_4_score += 4;
        } elseif ($admission_percent >= 30) {
            $section_4_score += 3;
        } elseif ($admission_percent >= 20) {
            $section_4_score += 2;
        } elseif ($admission_percent >= 10) {
            $section_4_score += 1;
        }
    }
    
    // Item 3: JRFs, SRFs, Post Doctoral Fellows (Max 10 marks)
    $phd_with_fellowship = (int)($phd_data['phd_with_fellowship'] ?? 0);
    $total_phd = (int)($phd_data['total_phd'] ?? 0);
    if ($total_phd > 0) {
        $fellowship_percent = ($phd_with_fellowship / $total_phd) * 100;
        $section_4_score += min(($fellowship_percent / 20), 10);
    }
    
    // Item 4: ESCS Diversity (Max 10 marks)
    // CRITICAL FIX: Only give marks if reserved_students is actually set and > 0
    $reserved_students = (int)($intake_data['reserved_category_students'] ?? 0);
    if ($reserved_students > 0) {
        $section_4_score += min($reserved_students * 0.1, 10);
    }
    
    // Item 5: Women Diversity (Max 5 marks)
    $female_students = (int)($intake_data['female_students'] ?? 0);
    $total_students_intake = (int)($intake_data['total_enrolled'] ?? 0);
    if ($total_students_intake > 0 && $female_students >= 0) {
        $female_percent = ($female_students / $total_students_intake) * 100;
        $section_4_score += min(($female_percent / 50) * 5, 5);
    }
    
    // Item 6: Regional Diversity (Max 5 marks)
    // CRITICAL FIX: Only give marks if at least one value is > 0
    $outside_state = (int)($intake_data['outside_state_students'] ?? 0);
    $outside_country = (int)($intake_data['outside_country_students'] ?? 0);
    if ($outside_state > 0 || $outside_country > 0) {
        $section_4_score += min(($outside_state * 0.1) + ($outside_country * 0.25), 5);
    }
    
    // Item 7: Support Initiatives (Max 10 marks)
    // CRITICAL FIX: Only give marks if support_initiatives_count is actually set and > 0
    $support_initiatives = (int)($support_data['support_initiatives_count'] ?? 0);
    if ($support_initiatives > 0) {
        $section_4_score += min($support_initiatives * 2, 10);
    }
    
    // Item 8: Internship/OJT (Max 10 marks)
    // CRITICAL FIX: Only calculate if both values are actually set and > 0
    $internship_students = (int)($support_data['internship_students'] ?? 0);
    $total_students_for_internship = (int)($placement_data['total_students'] ?? 0);
    if ($total_students_for_internship > 0 && $internship_students >= 0) {
        $internship_percent = ($internship_students / $total_students_for_internship) * 100;
        $section_4_score += min($internship_percent / 10, 10);
    }
    // If total_students is 0 or not set, give 0 marks (no data entered)
    
    // Item 9: Graduation Outcome (Max 5 marks)
    $total_graduated = (int)($placement_data['total_graduated'] ?? 0);
    $total_students = (int)($placement_data['total_students'] ?? 0);
    if ($total_students > 0) {
        $graduation_percent = ($total_graduated / $total_students) * 100;
        if ($graduation_percent >= 100) {
            $section_4_score += 5;
        } elseif ($graduation_percent >= 80) {
            $section_4_score += 4;
        } elseif ($graduation_percent >= 60) {
            $section_4_score += 3;
        } elseif ($graduation_percent >= 40) {
            $section_4_score += 2;
        } elseif ($graduation_percent >= 20) {
            $section_4_score += 1;
        }
    }
    
    // Item 10: Placement & Self-Employment (Max 10 marks)
    $placed_students = (int)($placement_data['total_placed'] ?? 0);
    $outgoing_students = (int)($placement_data['total_graduated'] ?? 0);
    if ($outgoing_students > 0) {
        $placement_percent = ($placed_students / $outgoing_students) * 100;
        $section_4_score += min($placement_percent / 10, 10);
    }
    
    // Item 11: Competitive Exams (Max 10 marks)
    $competitive_exam_students = (int)($placement_data['total_qualifying_exams'] ?? 0);
    $outgoing_students_for_exams = (int)($placement_data['total_graduated'] ?? 0);
    if ($outgoing_students_for_exams > 0) {
        $exam_percent = ($competitive_exam_students / $outgoing_students_for_exams) * 100;
        $section_4_score += min($exam_percent / 10, 10);
    }
    
    // Item 12: Higher Studies (Max 10 marks)
    $higher_studies = (int)($placement_data['total_higher_studies'] ?? 0);
    $outgoing_students_for_higher = (int)($placement_data['total_graduated'] ?? 0);
    if ($outgoing_students_for_higher > 0) {
        $higher_studies_percent = ($higher_studies / $outgoing_students_for_higher) * 100;
        $section_4_score += min($higher_studies_percent / 10, 10);
    }
    
    // Item 13: Student Research Activity (Max 15 marks)
    // CRITICAL FIX: Only give marks if student_research_activities is actually set and > 0
    $student_research = (int)($support_data['student_research_activities'] ?? 0);
    if ($student_research > 0) {
        $section_4_score += min($student_research, 15);
    }
    
    // Item 14: Sports Awards (Max 10 marks)
    // CRITICAL FIX: Only give marks if at least one sports award value is > 0
    $state_sports = (int)($support_data['awards_sports_state'] ?? 0);
    $national_sports = (int)($support_data['awards_sports_national'] ?? 0);
    $intl_sports = (int)($support_data['awards_sports_international'] ?? 0);
    if ($state_sports > 0 || $national_sports > 0 || $intl_sports > 0) {
        $section_4_score += min(($state_sports * 1) + ($national_sports * 2) + ($intl_sports * 3), 10);
    }
    
    // Item 15: Cultural Awards (Max 10 marks)
    // CRITICAL FIX: Only give marks if awards_cultural_count is actually set and > 0
    $cultural_awards = (int)($support_data['awards_cultural_count'] ?? 0);
    if ($cultural_awards > 0) {
        $section_4_score += min($cultural_awards, 10);
    }
    
    return min($section_4_score, 140); // Cap at 140
}

/**
 * Calculate Section V Score from data array (Conferences & Collaborations) - Max 75
 * Centralized calculation function for consistency
 * FIXES: A3 case sensitivity issue
 */
function calculateSection5FromArray($sec5_data) {
    $section_5_score = 0;
    
    $conferences_data = $sec5_data['conferences'] ?? [];
    $collaborations_data = $sec5_data['collaborations'] ?? [];
    
    // Part A: Conferences (Items 1-6) - Total: 40 marks
    // Official marks: 2 marks per STTP/workshops/Seminar Activities conducted
    //                 2 marks for session chair/plenary talk/conference proceedings
    //                 1 mark for poster presentation
    
    // Item 1: Industry-Academia Innovative practices/Workshop (Max 5 marks)
    // Formula: 2 marks each, max 5 marks (2.5 activities max, but we cap at 5 marks)
    $section_5_score += min((int)($conferences_data['A1'] ?? 0) * 2, 5);
    
    // Item 2: Workshops/STTP/Refresher or Orientation Programme (Max 5 marks)
    // Formula: 2 marks each, max 5 marks
    $section_5_score += min((int)($conferences_data['A2'] ?? 0) * 2, 5);
    
    // Item 3: National Conferences/Seminars/Workshops (Max 5 marks)
    // CRITICAL FIX: Handle case sensitivity
    $item3_value = 0;
    if (isset($conferences_data['A3'])) {
        $item3_value = (int)$conferences_data['A3'];
    } elseif (isset($conferences_data['a3'])) {
        $item3_value = (int)$conferences_data['a3'];
    } elseif (isset($conferences_data['A_3'])) {
        $item3_value = (int)$conferences_data['A_3'];
    }
    // Formula: 2 marks each, max 5 marks
    $section_5_score += min($item3_value * 2, 5);
    
    // Item 4: International Conferences/Seminars/Workshops (Max 10 marks)
    // Formula: 2 marks each, max 10 marks (5 activities max)
    $section_5_score += min((int)($conferences_data['A4'] ?? 0) * 2, 10);
    
    // Item 5: Teachers invited as speakers/resource persons/Session Chair (Max 10 marks)
    // Formula: 2 marks for session chair/plenary talk/conference proceedings, max 10 marks
    $section_5_score += min((int)($conferences_data['A5'] ?? 0) * 2, 10);
    
    // Item 6: Teachers who presented at Conferences/Seminars/Workshops (Max 5 marks)
    // Formula: 1 mark for poster presentation, max 5 marks
    $section_5_score += min((int)($conferences_data['A6'] ?? 0), 5);
    
    // Part B: Collaborations (Items 7-11) - Total: 35 marks
    // Official marks: 2 marks per Functional Collaboration
    
    // Item 7: Industry collaborations (Max 10 marks)
    // Formula: 2 marks per functional collaboration, max 10 marks (5 collaborations max)
    $section_5_score += min((int)($collaborations_data['B1'] ?? 0) * 2, 10);
    
    // Item 8: National Academic collaborations (Max 5 marks)
    // Formula: 2 marks per functional collaboration, max 5 marks (2.5 collaborations max, but we cap at 5 marks)
    $section_5_score += min((int)($collaborations_data['B2'] ?? 0) * 2, 5);
    
    // Item 9: Government/Semi-Government (2 marks each, max 5)
    $section_5_score += min((int)($collaborations_data['B3'] ?? 0) * 2, 5);
    
    // Item 10: International Academic (2 marks each, max 10)
    $section_5_score += min((int)($collaborations_data['B4'] ?? 0) * 2, 10);
    
    // Item 11: Outreach/Social Activity (2 marks each, max 5)
    $section_5_score += min((int)($collaborations_data['B5'] ?? 0) * 2, 5);
    
    return min($section_5_score, 75); // Cap at 75
}

/**
 * Clear and optionally recalculate department score cache
 * Call this function after any data update to ensure fresh scores are displayed
 * 
 * @param int $dept_id Department ID
 * @param string $academic_year Academic year (e.g., "2024-2025")
 * @param bool $recalculate If true, recalculate and update cache immediately
 * @return bool Success status
 */
function clearDepartmentScoreCache($dept_id, $academic_year, $recalculate = true) {
    global $conn;
    
    if (!$conn) {
        error_log("[clearDepartmentScoreCache] Database connection not available");
        return false;
    }
    
    // Delete existing cache entry
    $delete_stmt = $conn->prepare("DELETE FROM department_scores WHERE dept_id = ? AND academic_year = ?");
    if ($delete_stmt) {
        $delete_stmt->bind_param("is", $dept_id, $academic_year);
        $delete_stmt->execute();
        $delete_stmt->close();
        error_log("[clearDepartmentScoreCache] Cleared cache for dept_id=$dept_id, academic_year=$academic_year");
    }
    
    // If recalculate is true, fetch fresh data and recalculate
    if ($recalculate) {
        try {
            // Load data fetcher if not already loaded
            if (!function_exists('fetchAllDepartmentData')) {
                require_once(__DIR__ . '/data_fetcher.php');
            }
            
            $dept_data = fetchAllDepartmentData($dept_id, $academic_year);
            recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, true);
            error_log("[clearDepartmentScoreCache] Recalculated and updated cache for dept_id=$dept_id, academic_year=$academic_year");
            return true;
        } catch (Exception $e) {
            error_log("[clearDepartmentScoreCache] Error recalculating: " . $e->getMessage());
            return false;
        }
    }
    
    return true;
}

/**
 * Recalculate all sections from department data array
 * This is the SINGLE SOURCE OF TRUTH for score calculations
 * Returns complete auto_scores array and optionally updates cache
 */
function recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, $update_cache = true) {
    global $conn;
    
    // Get Section I from cache (it's calculated using marks_configuration table)
    $cached_scores = getDepartmentScores($dept_id, $academic_year);
    $section_1_score = (float)($cached_scores['section_1'] ?? 0);
    
    // Recalculate Sections II, III, IV, V from data array using centralized functions
    $sec2 = $dept_data['section_2'] ?? [];
    $sec3 = $dept_data['section_3'] ?? [];
    $sec4 = $dept_data['section_4'] ?? [];
    $sec5 = $dept_data['section_5'] ?? [];
    
    $section_2_score = calculateSection2FromArray($sec2);
    $section_3_score = calculateSection3FromArray($sec3);
    $section_4_score = calculateSection4FromArray($sec4);
    $section_5_score = calculateSection5FromArray($sec5);
    
    // Calculate total
    $total_score = (float)$section_1_score + (float)$section_2_score + (float)$section_3_score + (float)$section_4_score + (float)$section_5_score;
    
    $auto_scores = [
        'section_1' => $section_1_score,
        'section_2' => $section_2_score,
        'section_3' => $section_3_score,
        'section_4' => $section_4_score,
        'section_5' => $section_5_score,
        'total' => $total_score
    ];
    
    // Update cache if requested
    if ($update_cache) {
        $stmt = $conn->prepare("INSERT INTO department_scores 
            (dept_id, academic_year, section_1_score, section_2_score, section_3_score, section_4_score, section_5_score, total_score, last_calculated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            section_1_score = VALUES(section_1_score),
            section_2_score = VALUES(section_2_score),
            section_3_score = VALUES(section_3_score),
            section_4_score = VALUES(section_4_score),
            section_5_score = VALUES(section_5_score),
            total_score = VALUES(total_score),
            last_calculated_at = NOW()");
        
        if ($stmt) {
            $stmt->bind_param("isdddddd", $dept_id, $academic_year,
                $auto_scores['section_1'], $auto_scores['section_2'], $auto_scores['section_3'],
                $auto_scores['section_4'], $auto_scores['section_5'], $auto_scores['total']);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    return $auto_scores;
}

/**
 * Calculate Section IV Score (Student Support) - Max 140
 * Uses marks_configuration table dynamically
 */
function calculateSection4Score($dept_id, $academic_year) {
    global $conn;
    $total_score = 0.0;
    
    // Get all active items for Section IV
    $stmt = $conn->prepare("SELECT * FROM marks_configuration 
        WHERE section_number = 4 AND is_active = 1 
        ORDER BY priority ASC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($item = $result->fetch_assoc()) {
            $item_marks = calculateItemMarks($item, $dept_id, $academic_year);
            $total_score += $item_marks;
        }
        $stmt->close();
    }
    
    return min($total_score, 140); // Cap at 140
}

/**
 * Calculate Section V Score (Conferences & Collaborations) - Max 75
 * Uses marks_configuration table dynamically
 */
function calculateSection5Score($dept_id, $academic_year) {
    global $conn;
    $total_score = 0.0;
    
    // Get all active items for Section V
    $stmt = $conn->prepare("SELECT * FROM marks_configuration 
        WHERE section_number = 5 AND is_active = 1 
        ORDER BY priority ASC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($item = $result->fetch_assoc()) {
            $item_marks = calculateItemMarks($item, $dept_id, $academic_year);
            $total_score += $item_marks;
        }
        $stmt->close();
    }
    
    return min($total_score, 75); // Cap at 75
}

/**
 * Calculate all section scores for a department
 */
function calculateAllScores($dept_id, $academic_year = null) {
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    $scores = [
        'section_1' => calculateSection1Score($dept_id, $academic_year),
        'section_2' => calculateSection2Score($dept_id, $academic_year),
        'section_3' => calculateSection3Score($dept_id, $academic_year),
        'section_4' => calculateSection4Score($dept_id, $academic_year),
        'section_5' => calculateSection5Score($dept_id, $academic_year),
    ];
    
    $scores['total'] = array_sum($scores);
    
    return $scores;
}

/**
 * Get or calculate department scores (with caching in department_scores table)
 */
function getDepartmentScores($dept_id, $academic_year = null) {
    global $conn;
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Try to get from cache first
    $stmt = $conn->prepare("SELECT * FROM department_scores WHERE dept_id = ? AND academic_year = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("is", $dept_id, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return [
                'section_1' => (float)$row['section_1_score'],
                'section_2' => (float)$row['section_2_score'],
                'section_3' => (float)$row['section_3_score'],
                'section_4' => (float)$row['section_4_score'],
                'section_5' => (float)$row['section_5_score'],
                'total' => (float)$row['total_score'],
            ];
        }
        $stmt->close();
    }
    
    // Calculate and cache
    $scores = calculateAllScores($dept_id, $academic_year);
    
    // Save to cache
    $stmt = $conn->prepare("INSERT INTO department_scores 
        (dept_id, academic_year, section_1_score, section_2_score, section_3_score, section_4_score, section_5_score, total_score, last_calculated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
        section_1_score = VALUES(section_1_score),
        section_2_score = VALUES(section_2_score),
        section_3_score = VALUES(section_3_score),
        section_4_score = VALUES(section_4_score),
        section_5_score = VALUES(section_5_score),
        total_score = VALUES(total_score),
        last_calculated_at = NOW()");
    
    if ($stmt) {
        $stmt->bind_param("isdddddd", $dept_id, $academic_year, 
            $scores['section_1'], $scores['section_2'], $scores['section_3'], 
            $scores['section_4'], $scores['section_5'], $scores['total']);
        $stmt->execute();
        $stmt->close();
    }
    
    return $scores;
}

/**
 * REMOVED: calculateSection1WithNarrative function
 * Narrative questions (Items 22-26) have been removed from the expert review system
 * Section I score no longer includes narrative auto-scores
 * Section I max score is now 240 (was 300)
 */

/**
 * Get expert review for a department
 */
function getExpertReview($expert_email, $dept_id, $academic_year = null) {
    global $conn;
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    $stmt = $conn->prepare("SELECT * FROM expert_reviews 
        WHERE expert_email = ? AND dept_id = ? AND academic_year = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("sis", $expert_email, $dept_id, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $review = $result->fetch_assoc();
        $stmt->close();
        return $review;
    }
    return null;
}

/**
 * Get review status for a department (for expert)
 */
function getDepartmentReviewStatus($expert_email, $dept_id, $academic_year = null) {
    $review = getExpertReview($expert_email, $dept_id, $academic_year);
    if ($review) {
        return [
            'status' => $review['review_status'],
            'is_locked' => (bool)$review['is_locked'],
            'has_review' => true
        ];
    }
    return [
        'status' => 'pending',
        'is_locked' => false,
        'has_review' => false
    ];
}

/**
 * Get supporting documents for a department section
 */
function getSupportingDocuments($dept_id, $page_section, $academic_year = null) {
    global $conn;
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    $stmt = $conn->prepare("SELECT * FROM supporting_documents 
        WHERE dept_id = ? AND page_section = ? AND academic_year = ? AND status = 'active'
        ORDER BY serial_number ASC");
    if ($stmt) {
        $stmt->bind_param("iss", $dept_id, $page_section, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $docs = [];
        while ($row = $result->fetch_assoc()) {
            $docs[] = $row;
        }
        $stmt->close();
        return $docs;
    }
    return [];
}

