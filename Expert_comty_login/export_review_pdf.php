<?php
/**
 * Export Expert Review as PDF
 * Mirrors the Department Verification Review page so committee can download a full snapshot.
 * Uses same header format as dept_login/generate_report.php
 */

require('session.php');
require('expert_functions.php');
require('data_fetcher.php');

$dept_id_raw = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$dept_id = resolveDepartmentId($dept_id_raw);
$academic_year = getAcademicYear();

if (!$dept_id) {
    die('Invalid department identifier.');
}

// Fetch department meta
$dept_name = 'Unknown Department';
$dept_code = '';
$dept_category = '';
$meta_sql = "SELECT 
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
$meta_stmt = $conn->prepare($meta_sql);
if ($meta_stmt) {
    $meta_stmt->bind_param("ssi", $academic_year, $academic_year, $dept_id);
    $meta_stmt->execute();
    $meta_res = $meta_stmt->get_result();
    if ($meta = $meta_res->fetch_assoc()) {
        $dept_name = $meta['dept_name'] ?? $dept_name;
        $dept_code = $meta['dept_code'] ?? '';
        $dept_category = $meta['category_label'] ?? '';
    }
    $meta_stmt->close();
}

// Gather review data
$dept_data = fetchAllDepartmentData($dept_id, $academic_year);
$documents = fetchAllSupportingDocuments($dept_id, $academic_year);
$grouped_docs = groupDocumentsBySection($documents);
$expert_review = getExpertReview($email, $dept_id, $academic_year);

// Debug: Log section_1 data availability
error_log("[PDF Export] dept_data['section_1'] exists: " . (isset($dept_data['section_1']) ? 'YES' : 'NO'));
if (isset($dept_data['section_1'])) {
    $sec1 = $dept_data['section_1'];
    error_log("[PDF Export] section_1 keys: " . implode(', ', array_keys($sec1)));
    error_log("[PDF Export] section_1 has dpiit_startup_details: " . (isset($sec1['dpiit_startup_details']) ? 'YES (' . strlen($sec1['dpiit_startup_details']) . ' chars)' : 'NO'));
    error_log("[PDF Export] section_1 has desc_initiative: " . (isset($sec1['desc_initiative']) ? 'YES (' . strlen($sec1['desc_initiative']) . ' chars)' : 'NO'));
}

// CRITICAL: Use centralized calculation function for consistency
// This ensures all sections are calculated using the same logic as review_complete.php
$auto_scores = recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, true);

// Map to variables used in PDF (for backward compatibility)
$section_2_recalc = $auto_scores['section_2'];
$section_3_recalc = $auto_scores['section_3'];
$section_4_recalc = $auto_scores['section_4'];
$section_5_recalc = $auto_scores['section_5'];

// Note: All section scores are already calculated by recalculateAllSectionsFromData() above
// The $auto_scores array is already populated with all correct values

// Debug logging to verify all scores
error_log("[PDF Export] FINAL SCORES - S1: " . $auto_scores['section_1'] . ", S2: " . $auto_scores['section_2'] . ", S3: " . $auto_scores['section_3'] . ", S4: " . $auto_scores['section_4'] . ", S5: " . $auto_scores['section_5'] . ", TOTAL: " . $auto_scores['total']);

// Locate TCPDF
$tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdf_path)) {
    die('TCPDF library not found. Please ensure vendor/tecnickcom/tcpdf is installed.');
}
require_once $tcpdf_path;

// Custom PDF class with header matching dept_login format
class ReviewPDF extends TCPDF {
    private $dept_name = '';
    private $dept_code = '';
    private $A_YEAR = '';
    
    public function setHeaderInfo($dept_name, $dept_code, $A_YEAR) {
        $this->dept_name = $dept_name;
        $this->dept_code = $dept_code;
        $this->A_YEAR = $A_YEAR;
    }
    
    public function Header() {
        // Professional Header Design - Logo on left, text on right (matching dept_login)
        $page_width = $this->getPageWidth();
        $margin = 15;
        
        // Logo - on the left side
        $logo_path = __DIR__ . '/../assets/images/mu-logo-small.png';
        if (!file_exists($logo_path)) {
            // Fallback to existing logo
            $logo_path = __DIR__ . '/../assets/img/mumbai-university-removebg-preview.png';
        }
        $logo_size = 20;
        $logo_x = $margin;
        $logo_y = 8;
        
        if (file_exists($logo_path)) {
            try {
                $this->Image($logo_path, $logo_x, $logo_y, $logo_size, $logo_size, '', '', '', false, 300, '', false, false, 0);
            } catch (Exception $e) {
                // Logo failed, continue without it
            }
        }
        
        // Text area - beside logo on the right
        $text_x = $logo_x + $logo_size + 8;
        $text_width = $page_width - $text_x - $margin;
        
        // "University of Mumbai" - Times font, bold, size 18 (matching dept_login)
        $this->SetFont('times', 'B', 18);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($text_x, $logo_y);
        $this->Cell($text_width, 8, 'University of Mumbai', 0, 1, 'L', false, '', 0, false, 'T', 'M');
        
        // "Centralized DCS Ranking Portal" - Helvetica, bold, size 10
        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(60, 60, 60);
        $this->SetXY($text_x, $logo_y + 9);
        $this->Cell($text_width, 6, 'Centralized DCS Ranking Portal', 0, 1, 'L', false, '', 0, false, 'T', 'M');
        
        // Department info - Helvetica, bold, size 8
        $this->SetFont('helvetica', 'B', 8);
        $this->SetTextColor(0, 0, 0);
        $info_y = $logo_y + 16;
        $this->SetXY($text_x, $info_y);
        $header_text = 'Department: ' . $this->dept_name . ' | Code: ' . $this->dept_code . ' | Academic Year: ' . $this->A_YEAR . ' | Generated: ' . date('Y-m-d H:i:s');
        $this->Cell($text_width, 5, $header_text, 0, 1, 'L', false, '', 0, false, 'T', 'M');
        
        // Horizontal line separator
        $line_y = $info_y + 6;
        $this->SetLineWidth(0.5);
        $this->SetDrawColor(200, 200, 200);
        $this->Line($margin, $line_y, $page_width - $margin, $line_y);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 7);
        $this->Cell(0, 8, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C');
    }
}

$pdf = new ReviewPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setHeaderInfo($dept_name, $dept_code, $academic_year);
$pdf->SetCreator('MU Expert Review Portal');
$pdf->SetAuthor('MU Expert Committee');
$pdf->SetTitle("Expert Review - {$dept_name}");
$pdf->SetMargins(15, 35, 15); // Top margin increased for header
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();
$pdf->SetFont('dejavusans', '', 9);

// Helper functions ---------------------------------------------------------
function formatLabel($key) {
    $key = str_replace(['_', '-'], ' ', $key);
    return ucwords(trim($key));
}

function decodePossibleJson($value) {
    if (!is_string($value)) {
        return $value;
    }
    $trim = trim($value);
    if ($trim === '' || $trim === '[]' || $trim === '{}') {
        return '';
    }
    if (($trim[0] === '{' && substr($trim, -1) === '}') || ($trim[0] === '[' && substr($trim, -1) === ']')) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return $value;
}

function formatScalar($value) {
    if (is_null($value)) {
        return '—';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if ($value === '') {
        return '—';
    }
    return nl2br(htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
}

function formatArrayValue($value) {
    $value = decodePossibleJson($value);
    if (!is_array($value)) {
        return formatScalar($value);
    }
    if (empty($value)) {
        return '—';
    }
    $items = [];
    $isAssoc = array_keys($value) !== range(0, count($value) - 1);
    if ($isAssoc) {
        foreach ($value as $k => $v) {
            $items[] = '<strong>' . formatLabel($k) . ':</strong> ' . formatArrayValue($v);
        }
    } else {
        foreach ($value as $idx => $item) {
            if (is_array($item)) {
                $items[] = '<strong>Item ' . ($idx + 1) . '</strong><br>' . formatArrayValue($item);
            } else {
                $items[] = formatArrayValue($item);
            }
        }
    }
    return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
}

function buildRows($data, $prefix = '') {
    $rows = [];
    if (!is_array($data) || empty($data)) {
        return $rows;
    }
    foreach ($data as $key => $value) {
        $label = trim($prefix . formatLabel($key));
        $decoded = decodePossibleJson($value);
        if (is_array($decoded)) {
            if (empty($decoded)) {
                $rows[] = [$label, '—'];
                continue;
            }
            $isAssoc = array_keys($decoded) !== range(0, count($decoded) - 1);
            if ($isAssoc) {
                $rows[] = [$label, formatArrayValue($decoded)];
            } else {
                foreach ($decoded as $idx => $item) {
                    $itemLabel = $label . ' #' . ($idx + 1);
                    $rows = array_merge($rows, buildRows([$itemLabel => $item]));
                }
            }
        } else {
            $rows[] = [$label, formatScalar($decoded)];
        }
    }
    return $rows;
}

function renderTable($title, $rows) {
    if (empty($rows)) {
        return "<h3>{$title}</h3><p>Data not submitted.</p>";
    }
    $html = "<h3>{$title}</h3><table class=\"kv-table\" cellpadding=\"4\">";
    foreach ($rows as $row) {
        $html .= '<tr><th>' . $row[0] . '</th><td>' . $row[1] . '</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

// Updated renderDocs to show only document titles (not file names)
function renderDocs($title, $docs) {
    if (empty($docs)) {
        return '';
    }
    // Group documents by document_title
    $grouped_by_title = [];
    foreach ($docs as $doc) {
        $doc_title = trim($doc['document_title'] ?? 'Other Documents');
        if (empty($doc_title)) {
            $doc_title = 'Other Documents';
        }
        if (!isset($grouped_by_title[$doc_title])) {
            $grouped_by_title[$doc_title] = [];
        }
        $grouped_by_title[$doc_title][] = $doc;
    }
    
    $html = "<h4>{$title} - Supporting Documents</h4>";
    $html .= '<ul>';
    foreach ($grouped_by_title as $doc_heading => $documents) {
        // Just show the document title, not file names
        $count_text = count($documents) > 1 ? ' (' . count($documents) . ' documents)' : '';
        $html .= '<li>' . htmlspecialchars($doc_heading, ENT_QUOTES, 'UTF-8') . $count_text . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function rowsFromSection4($section4) {
    $rows = [];
    if (!$section4) {
        return $rows;
    }
    $map = [
        'intake' => 'Intake & Enrolment',
        'placement' => 'Placement Summary',
        'phd' => 'PhD Details',
        'support' => 'Student Support Snapshot'
    ];
    foreach ($map as $key => $label) {
        if (!empty($section4[$key])) {
            $rows = array_merge($rows, buildRows($section4[$key], $label . ' - '));
        }
    }
    return $rows;
}

function rowsFromSection5($section5) {
    $rows = [];
    if (!$section5) {
        return $rows;
    }
    // Part A: Conferences (Total: 40 marks)
    if (!empty($section5['conferences'])) {
        $conf = $section5['conferences'];
        $rows[] = ['Part A: Conferences, Workshops, STTP and Seminars', ''];
        $rows[] = ['1. Industry-Academia Innovative practices/Workshop', ($conf['A1'] ?? 0) . ' (2 marks each, Max 5)'];
        $rows[] = ['2. Workshops/STTP/Refresher or Orientation Programme', ($conf['A2'] ?? 0) . ' (2 marks each, Max 5)'];
        $rows[] = ['3. National Conferences/Seminars/Workshops', ($conf['A3'] ?? 0) . ' (2 marks each, Max 5)'];
        $rows[] = ['4. International Conferences/Seminars/Workshops', ($conf['A4'] ?? 0) . ' (2 marks each, Max 10)'];
        $rows[] = ['5. Teachers invited as speakers/resource persons/Session Chair', ($conf['A5'] ?? 0) . ' (2 marks each, Max 10)'];
        $rows[] = ['6. Teachers who presented at Conferences/Seminars/Workshops', ($conf['A6'] ?? 0) . ' (1 mark each, Max 5)'];
    }
    // Part B: Collaborations (Total: 35 marks)
    if (!empty($section5['collaborations'])) {
        $collab = $section5['collaborations'];
        $rows[] = ['Part B: Collaborations', ''];
        $rows[] = ['7. Industry collaborations for Programs', ($collab['B1'] ?? 0) . ' (2 marks per functional collaboration, Max 10)'];
        $rows[] = ['8. National Academic collaborations', ($collab['B2'] ?? 0) . ' (2 marks per functional collaboration, Max 5)'];
        $rows[] = ['9. Government/Semi-Government Collaboration Projects', ($collab['B3'] ?? 0) . ' (2 marks per functional collaboration, Max 5)'];
        $rows[] = ['10. International Academic collaborations', ($collab['B4'] ?? 0) . ' (2 marks per functional collaboration, Max 10)'];
        $rows[] = ['11. Outreach/Social Activity Collaborations', ($collab['B5'] ?? 0) . ' (2 marks per functional collaboration, Max 5)'];
    }
    return $rows;
}

function rowsFromSection1($section1, $dept_id = null, $academic_year_param = null) {
    global $conn;
    $rows = [];
    
    // Get academic year from parameter, global scope, or use current year
    global $academic_year;
    $query_academic_year = $academic_year_param ?? $academic_year ?? getAcademicYear();
    error_log("[PDF Export] rowsFromSection1: Using academic year: " . $query_academic_year);
    
    // CRITICAL: Always try direct database query to ensure we get the data
    // Check if key fields are missing or empty
    $needs_fallback = false;
    if (empty($section1)) {
        $needs_fallback = true;
        error_log("[PDF Export] rowsFromSection1: section1 is empty, trying direct DB query...");
    } else {
        // Check if key startup/narrative fields are missing or empty
        $has_startup_data = !empty($section1['dpiit_startup_details']) || 
                           !empty($section1['vc_investment_details']) || 
                           !empty($section1['seed_funding_details']) ||
                           !empty($section1['forbes_alumni_details']) ||
                           !empty($section1['dpiit_startup_details_parsed']);
        $has_narrative_data = !empty($section1['desc_initiative']) || 
                             !empty($section1['desc_impact']) || 
                             !empty($section1['desc_collaboration']);
        
        if (!$has_startup_data && !$has_narrative_data) {
            $needs_fallback = true;
            error_log("[PDF Export] rowsFromSection1: section1 exists but missing startup/narrative fields, trying direct DB query...");
        }
    }
    
    // CRITICAL: Always try direct query to ensure we get the data, even if section1 is not empty
    // This is necessary because fetchAllDepartmentData might not return all fields
    // CRITICAL FIX: Select ALL fields from faculty_output to match department UDRF report
    if ($dept_id && $conn) {
        $direct_query = "SELECT * FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
        
        $direct_stmt = $conn->prepare($direct_query);
        if ($direct_stmt) {
            $direct_stmt->bind_param("is", $dept_id, $query_academic_year);
            $direct_stmt->execute();
            $direct_result = $direct_stmt->get_result();
            $direct_row = $direct_result->fetch_assoc();
            $direct_stmt->close();
            
            if ($direct_row) {
                error_log("[PDF Export] rowsFromSection1: Found data with direct query - A_YEAR: " . ($direct_row['A_YEAR'] ?? 'NULL'));
                // Merge direct query results, with direct query taking precedence for missing fields
                if (empty($section1)) {
                    $section1 = $direct_row;
                } else {
                    // Merge all fields from direct query, prioritizing direct query for non-empty values
                    foreach ($direct_row as $key => $value) {
                        // Always use direct query value if it's not empty, or if section1 value is empty
                        if (!empty($value) || empty($section1[$key])) {
                            $section1[$key] = $value;
                            error_log("[PDF Export] rowsFromSection1: Merged field $key from direct query");
                        }
                    }
                }
            } else {
                error_log("[PDF Export] rowsFromSection1: Direct query returned no data for dept_id=$dept_id, academic_year=$query_academic_year");
            }
        } else {
            error_log("[PDF Export] rowsFromSection1: Failed to prepare direct query statement");
        }
    }
    
    // If no data found with exact academic year, try alternative formats
    if (empty($section1) && $dept_id && $conn) {
        error_log("[PDF Export] rowsFromSection1: Trying alternative academic year formats...");
        // Try with just the ending year (e.g., "2025" for "2024-2025")
        $ending_year = substr($query_academic_year, -4);
        $alt_query = "SELECT * FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
        $alt_stmt = $conn->prepare($alt_query);
        if ($alt_stmt) {
            $alt_stmt->bind_param("is", $dept_id, $ending_year);
            $alt_stmt->execute();
            $alt_result = $alt_stmt->get_result();
            $alt_row = $alt_result->fetch_assoc();
            $alt_stmt->close();
            
            if ($alt_row) {
                error_log("[PDF Export] rowsFromSection1: Found data with alternative year format: $ending_year");
                $section1 = $alt_row;
            }
        }
    }
    
    // Re-parse startup data from the query results (same as portal)
    // This ensures Forbes Alumni and other startup types are included
    if (!empty($section1)) {
        $startup_list_parsed = [];
                
                // DPIIT startups
                if (!empty($section1['dpiit_startup_details'])) {
                    $dpiit_list = json_decode($section1['dpiit_startup_details'], true) ?: [];
                    foreach ($dpiit_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'DPIIT Recognition';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // VC investments
                if (!empty($section1['vc_investment_details'])) {
                    $vc_list = json_decode($section1['vc_investment_details'], true) ?: [];
                    foreach ($vc_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'VC Investment';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // Seed funding
                if (!empty($section1['seed_funding_details'])) {
                    $seed_list = json_decode($section1['seed_funding_details'], true) ?: [];
                    foreach ($seed_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'Seed Funding';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // FDI investments
                if (!empty($section1['fdi_investment_details'])) {
                    $fdi_list = json_decode($section1['fdi_investment_details'], true) ?: [];
                    foreach ($fdi_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'FDI Investment';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // Innovation grants
                if (!empty($section1['innovation_grants_details'])) {
                    $grant_list = json_decode($section1['innovation_grants_details'], true) ?: [];
                    foreach ($grant_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'Innovation Grant';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // TRL innovations
                if (!empty($section1['trl_innovations_details'])) {
                    $trl_list = json_decode($section1['trl_innovations_details'], true) ?: [];
                    foreach ($trl_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'TRL Innovation';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // Turnover achievements
                if (!empty($section1['turnover_achievements_details'])) {
                    $turnover_list = json_decode($section1['turnover_achievements_details'], true) ?: [];
                    foreach ($turnover_list as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'Turnover Achievement';
                            $startup_list_parsed[] = $item;
                        }
                    }
                }
                
                // Forbes alumni
                if (!empty($section1['forbes_alumni_details'])) {
                    $forbes_list = json_decode($section1['forbes_alumni_details'], true) ?: [];
                    if (is_array($forbes_list) && !empty($forbes_list)) {
                        foreach ($forbes_list as $item) {
                            if (is_array($item)) {
                                // Include entry if it has at least one non-empty, non-"-" field
                                // Allow year fields (year_of_passing, year_founded) even if they're "0" or numeric
                                $has_valid_data = false;
                                foreach ($item as $key => $val) {
                                    $clean_val = trim((string)$val);
                                    $key_lower = strtolower($key);
                                    if (!in_array($key_lower, ['id', 'dept_id', 'a_year', 'serial_number'])) {
                                        if (in_array($key_lower, ['year_of_passing', 'year_founded', 'year_passing', 'year_founded'])) {
                                            if (is_numeric($clean_val) && $clean_val !== '') {
                                                $has_valid_data = true;
                                                break;
                                            }
                                        } else {
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
                                }
                            }
                        }
                    }
                }
                
        // Store parsed startup list in section1 (same as portal)
        $section1['dpiit_startup_details_parsed'] = $startup_list_parsed;
        error_log("[PDF Export] rowsFromSection1: Re-parsed startup list, count: " . count($startup_list_parsed));
    }
    
    // If section1 is still empty after all attempts, return empty rows (but still show the structure)
    if (empty($section1)) {
        error_log("[PDF Export] rowsFromSection1: WARNING - section1 is still empty after all fallback attempts");
        // Don't return early - continue to build rows structure even if empty
        // This ensures the PDF shows the section structure even if data is missing
    }
    
    // CRITICAL FIX: Use buildRows to get ALL standard fields first (like department UDRF report)
    // This ensures all faculty data fields are included (recognitions, awards, projects, publications, etc.)
    $rows = [];
    if (!empty($section1)) {
        // Exclude startup and narrative fields from buildRows - we'll format those separately
        $standard_fields = $section1;
        $exclude_fields = [
            'dpiit_startup_details', 'vc_investment_details', 'seed_funding_details',
            'fdi_investment_details', 'innovation_grants_details', 'trl_innovations_details',
            'turnover_achievements_details', 'forbes_alumni_details',
            'desc_initiative', 'desc_impact', 'desc_collaboration', 'desc_plan', 'desc_recognition',
            'dpiit_startup_details_parsed', 'id', 'DEPT_ID', 'A_YEAR', 'department_id',
            'created_at', 'updated_at', 'total_marks'
        ];
        foreach ($exclude_fields as $exclude) {
            unset($standard_fields[$exclude]);
        }
        
        // Use buildRows to format all standard fields automatically
        $standard_rows = buildRows($standard_fields);
        $rows = array_merge($rows, $standard_rows);
        error_log("[PDF Export] rowsFromSection1: Added " . count($standard_rows) . " standard field rows via buildRows");
    }
    
    // Debug: Log what we received
    error_log("[PDF Export] rowsFromSection1: Received section1 with keys: " . implode(', ', array_keys($section1 ?? [])));
    error_log("[PDF Export] rowsFromSection1: dpiit_startup_details exists: " . (isset($section1['dpiit_startup_details']) ? 'YES' : 'NO'));
    error_log("[PDF Export] rowsFromSection1: dpiit_startup_details_parsed exists: " . (isset($section1['dpiit_startup_details_parsed']) ? 'YES' : 'NO'));
    error_log("[PDF Export] rowsFromSection1: forbes_alumni_details exists: " . (isset($section1['forbes_alumni_details']) ? 'YES' : 'NO'));
    if (isset($section1['dpiit_startup_details'])) {
        error_log("[PDF Export] rowsFromSection1: dpiit_startup_details length: " . strlen($section1['dpiit_startup_details']));
        error_log("[PDF Export] rowsFromSection1: dpiit_startup_details preview: " . substr($section1['dpiit_startup_details'], 0, 200));
    }
    
    // Startup Ecosystem Data - check both parsed and raw fields
    $startup_list = [];
    if (!empty($section1['dpiit_startup_details_parsed'])) {
        $startup_list = $section1['dpiit_startup_details_parsed'];
        if (is_string($startup_list)) {
            $startup_list = json_decode($startup_list, true) ?: [];
        }
        error_log("[PDF Export] rowsFromSection1: Using parsed startup list, count: " . count($startup_list));
    } else {
        error_log("[PDF Export] rowsFromSection1: Parsed list not available, parsing raw fields");
        // Fallback: parse raw JSON fields if parsed not available
        $all_startups = [];
        
        // DPIIT startups
        if (!empty($section1['dpiit_startup_details'])) {
            $dpiit_val = $section1['dpiit_startup_details'];
            // Handle empty JSON strings
            if (is_string($dpiit_val) && trim($dpiit_val) !== '' && trim($dpiit_val) !== '[]' && trim($dpiit_val) !== 'null') {
                $dpiit_raw = json_decode($dpiit_val, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dpiit_raw) && !empty($dpiit_raw)) {
                    foreach ($dpiit_raw as $item) {
                        if (is_array($item)) {
                            $item['type'] = 'DPIIT Recognition';
                            $all_startups[] = $item;
                        }
                    }
                    error_log("[PDF Export] rowsFromSection1: Parsed " . count($dpiit_raw) . " DPIIT startups");
                } else {
                    error_log("[PDF Export] rowsFromSection1: Failed to parse dpiit_startup_details JSON: " . json_last_error_msg());
                }
            } elseif (is_array($dpiit_val) && !empty($dpiit_val)) {
                foreach ($dpiit_val as $item) {
                    if (is_array($item)) {
                        $item['type'] = 'DPIIT Recognition';
                        $all_startups[] = $item;
                    }
                }
            }
        }
        
        // VC investments
        if (!empty($section1['vc_investment_details'])) {
            $vc_raw = is_string($section1['vc_investment_details']) ? json_decode($section1['vc_investment_details'], true) : $section1['vc_investment_details'];
            if (is_array($vc_raw)) {
                foreach ($vc_raw as $item) {
                    $item['type'] = 'VC Investment';
                    $all_startups[] = $item;
                }
            }
        }
        
        // Seed funding
        if (!empty($section1['seed_funding_details'])) {
            $seed_raw = is_string($section1['seed_funding_details']) ? json_decode($section1['seed_funding_details'], true) : $section1['seed_funding_details'];
            if (is_array($seed_raw)) {
                foreach ($seed_raw as $item) {
                    $item['type'] = 'Seed Funding';
                    $all_startups[] = $item;
                }
            }
        }
        
        // FDI investments
        if (!empty($section1['fdi_investment_details'])) {
            $fdi_raw = is_string($section1['fdi_investment_details']) ? json_decode($section1['fdi_investment_details'], true) : $section1['fdi_investment_details'];
            if (is_array($fdi_raw)) {
                foreach ($fdi_raw as $item) {
                    $item['type'] = 'FDI Investment';
                    $all_startups[] = $item;
                }
            }
        }
        
        // Innovation grants
        if (!empty($section1['innovation_grants_details'])) {
            $grant_raw = is_string($section1['innovation_grants_details']) ? json_decode($section1['innovation_grants_details'], true) : $section1['innovation_grants_details'];
            if (is_array($grant_raw)) {
                foreach ($grant_raw as $item) {
                    $item['type'] = 'Innovation Grant';
                    $all_startups[] = $item;
                }
            }
        }
        
        // TRL innovations
        if (!empty($section1['trl_innovations_details'])) {
            $trl_raw = is_string($section1['trl_innovations_details']) ? json_decode($section1['trl_innovations_details'], true) : $section1['trl_innovations_details'];
            if (is_array($trl_raw)) {
                foreach ($trl_raw as $item) {
                    $item['type'] = 'TRL Innovation';
                    $all_startups[] = $item;
                }
            }
        }
        
        // Turnover achievements
        if (!empty($section1['turnover_achievements_details'])) {
            $turnover_raw = is_string($section1['turnover_achievements_details']) ? json_decode($section1['turnover_achievements_details'], true) : $section1['turnover_achievements_details'];
            if (is_array($turnover_raw)) {
                foreach ($turnover_raw as $item) {
                    $item['type'] = 'Turnover Achievement';
                    $all_startups[] = $item;
                }
            }
        }
        
        // Forbes Alumni - parse from raw field and add to startup list
        if (!empty($section1['forbes_alumni_details'])) {
            $forbes_raw = is_string($section1['forbes_alumni_details']) ? json_decode($section1['forbes_alumni_details'], true) : $section1['forbes_alumni_details'];
            if (is_array($forbes_raw) && !empty($forbes_raw)) {
                foreach ($forbes_raw as $item) {
                    if (is_array($item)) {
                        // Include entry if it has at least one non-empty, non-"-" field
                        // Allow year fields (year_of_passing, year_founded) even if they're "0" or numeric
                        $has_valid_data = false;
                        foreach ($item as $key => $val) {
                            $clean_val = trim((string)$val);
                            $key_lower = strtolower($key);
                            if (!in_array($key_lower, ['id', 'dept_id', 'a_year', 'serial_number'])) {
                                if (in_array($key_lower, ['year_of_passing', 'year_founded', 'year_passing', 'year_founded'])) {
                                    if (is_numeric($clean_val) && $clean_val !== '') {
                                        $has_valid_data = true;
                                        break;
                                    }
                                } else {
                                    if (!empty($clean_val) && $clean_val !== '-') {
                                        $has_valid_data = true;
                                        break;
                                    }
                                }
                            }
                        }
                        if ($has_valid_data) {
                            $item['type'] = 'Forbes Alumni';
                            $all_startups[] = $item;
                        }
                    }
                }
            }
        }
        
        $startup_list = $all_startups;
        error_log("[PDF Export] rowsFromSection1: Parsed startup list count: " . count($startup_list));
    }
    
    // Group startups by type
    $startups_by_type = [];
    foreach ($startup_list as $startup) {
        $type = $startup['type'] ?? 'Unknown';
        if (!isset($startups_by_type[$type])) {
            $startups_by_type[$type] = [];
        }
        $startups_by_type[$type][] = $startup;
    }
    
    // DPIIT Startups
    $dpiit_count = count($startups_by_type['DPIIT Recognition'] ?? []);
    $rows[] = ['Total Dpiit Startups', $dpiit_count];
    if ($dpiit_count > 0) {
        $dpiit_details = [];
        foreach ($startups_by_type['DPIIT Recognition'] as $idx => $startup) {
            $details = [];
            if (!empty($startup['startup_name'])) $details[] = 'Name: ' . $startup['startup_name'];
            if (!empty($startup['founder_name'])) $details[] = 'Founder: ' . $startup['founder_name'];
            if (!empty($startup['year_founded'])) $details[] = 'Year: ' . $startup['year_founded'];
            if (!empty($startup['recognition_number'])) $details[] = 'Recognition #: ' . $startup['recognition_number'];
            $dpiit_details[] = 'Startup ' . ($idx + 1) . ': ' . implode(', ', $details);
        }
        $rows[] = ['Dpiit Startup Details', implode('<br>', $dpiit_details)];
    } else {
        $rows[] = ['Dpiit Startup Details', '—'];
    }
    
    // VC Investments
    $vc_count = count($startups_by_type['VC Investment'] ?? []);
    $rows[] = ['Total Vc Investments', $vc_count];
    if ($vc_count > 0) {
        $vc_details = [];
        foreach ($startups_by_type['VC Investment'] as $idx => $startup) {
            $details = [];
            if (!empty($startup['startup_name'])) $details[] = 'Name: ' . $startup['startup_name'];
            if (!empty($startup['investment_amount'])) $details[] = 'Amount: ' . $startup['investment_amount'];
            if (!empty($startup['investor_name'])) $details[] = 'Investor: ' . $startup['investor_name'];
            if (!empty($startup['year'])) $details[] = 'Year: ' . $startup['year'];
            $vc_details[] = 'Investment ' . ($idx + 1) . ': ' . implode(', ', $details);
        }
        $rows[] = ['Vc Investment Details', implode('<br>', $vc_details)];
    } else {
        $rows[] = ['Vc Investment Details', '—'];
    }
    
    // Seed Funding
    $seed_count = count($startups_by_type['Seed Funding'] ?? []);
    $rows[] = ['Total Seed Funding', $seed_count];
    if ($seed_count > 0) {
        $seed_details = [];
        foreach ($startups_by_type['Seed Funding'] as $idx => $startup) {
            $details = [];
            if (!empty($startup['startup_name'])) $details[] = 'Name: ' . $startup['startup_name'];
            if (!empty($startup['funding_amount'])) $details[] = 'Amount: ' . $startup['funding_amount'];
            if (!empty($startup['year'])) $details[] = 'Year: ' . $startup['year'];
            $seed_details[] = 'Funding ' . ($idx + 1) . ': ' . implode(', ', $details);
        }
        $rows[] = ['Seed Funding Details', implode('<br>', $seed_details)];
    } else {
        $rows[] = ['Seed Funding Details', '—'];
    }
    
    // FDI Investments
    $fdi_count = count($startups_by_type['FDI Investment'] ?? []);
    $rows[] = ['Total Fdi Investments', $fdi_count];
    if ($fdi_count > 0) {
        $fdi_details = [];
        foreach ($startups_by_type['FDI Investment'] as $idx => $startup) {
            $details = [];
            if (!empty($startup['startup_name'])) $details[] = 'Name: ' . $startup['startup_name'];
            if (!empty($startup['investment_amount'])) $details[] = 'Amount: ' . $startup['investment_amount'];
            if (!empty($startup['country'])) $details[] = 'Country: ' . $startup['country'];
            if (!empty($startup['year'])) $details[] = 'Year: ' . $startup['year'];
            $fdi_details[] = 'Investment ' . ($idx + 1) . ': ' . implode(', ', $details);
        }
        $rows[] = ['Fdi Investment Details', implode('<br>', $fdi_details)];
    } else {
        $rows[] = ['Fdi Investment Details', '—'];
    }
    
    // Innovation Grants
    $grant_count = count($startups_by_type['Innovation Grant'] ?? []);
    $rows[] = ['Total Innovation Grants', $grant_count];
    if ($grant_count > 0) {
        $grant_details = [];
        foreach ($startups_by_type['Innovation Grant'] as $idx => $startup) {
            $details = [];
            if (!empty($startup['grant_name'])) $details[] = 'Grant: ' . $startup['grant_name'];
            if (!empty($startup['grant_amount'])) $details[] = 'Amount: ' . $startup['grant_amount'];
            if (!empty($startup['granting_agency'])) $details[] = 'Agency: ' . $startup['granting_agency'];
            if (!empty($startup['year'])) $details[] = 'Year: ' . $startup['year'];
            $grant_details[] = 'Grant ' . ($idx + 1) . ': ' . implode(', ', $details);
        }
        $rows[] = ['Innovation Grants Details', implode('<br>', $grant_details)];
    } else {
        $rows[] = ['Innovation Grants Details', '—'];
    }
    
    // TRL Innovations
    $trl_count = count($startups_by_type['TRL Innovation'] ?? []);
    $rows[] = ['Total Trl Innovations', $trl_count];
    if ($trl_count > 0) {
        $trl_details = [];
        foreach ($startups_by_type['TRL Innovation'] as $idx => $startup) {
            $details = [];
            if (!empty($startup['innovation_name'])) $details[] = 'Innovation: ' . $startup['innovation_name'];
            if (!empty($startup['trl_level'])) $details[] = 'TRL Level: ' . $startup['trl_level'];
            if (!empty($startup['year'])) $details[] = 'Year: ' . $startup['year'];
            $trl_details[] = 'Innovation ' . ($idx + 1) . ': ' . implode(', ', $details);
        }
        $rows[] = ['Trl Innovations Details', implode('<br>', $trl_details)];
    } else {
        $rows[] = ['Trl Innovations Details', '—'];
    }
    
    // Turnover Achievements
    $turnover_count = count($startups_by_type['Turnover Achievement'] ?? []);
    $rows[] = ['Total Turnover Achievements', $turnover_count];
    if ($turnover_count > 0) {
        $turnover_details = [];
        foreach ($startups_by_type['Turnover Achievement'] as $idx => $startup) {
            $details = [];
            if (!empty($startup['startup_name'])) $details[] = 'Name: ' . $startup['startup_name'];
            if (!empty($startup['turnover_amount'])) $details[] = 'Turnover: ' . $startup['turnover_amount'];
            if (!empty($startup['year'])) $details[] = 'Year: ' . $startup['year'];
            $turnover_details[] = 'Achievement ' . ($idx + 1) . ': ' . implode(', ', $details);
        }
        $rows[] = ['Turnover Achievements Details', implode('<br>', $turnover_details)];
    } else {
        $rows[] = ['Turnover Achievements Details', '—'];
    }
    
    // Forbes Alumni - extract from startup list (they're added to startup_list with type='Forbes Alumni')
    $forbes_list = $startups_by_type['Forbes Alumni'] ?? [];
    
    // Also check raw field if not in startup list
    if (empty($forbes_list) && !empty($section1['forbes_alumni_details'])) {
        $forbes_raw = $section1['forbes_alumni_details'];
        if (is_string($forbes_raw)) {
            $forbes_raw_list = json_decode($forbes_raw, true) ?: [];
        } elseif (is_array($forbes_raw)) {
            $forbes_raw_list = $forbes_raw;
        } else {
            $forbes_raw_list = [];
        }
        
        // Filter out invalid entries (same logic as data_fetcher)
        foreach ($forbes_raw_list as $item) {
            if (is_array($item)) {
                $has_valid_data = false;
                foreach ($item as $key => $val) {
                    $clean_val = trim((string)$val);
                    $key_lower = strtolower($key);
                    if (!in_array($key_lower, ['id', 'dept_id', 'a_year', 'serial_number'])) {
                        if (in_array($key_lower, ['year_of_passing', 'year_founded', 'year_passing', 'year_founded'])) {
                            if (is_numeric($clean_val) && $clean_val !== '') {
                                $has_valid_data = true;
                                break;
                            }
                        } else {
                            if (!empty($clean_val) && $clean_val !== '-') {
                                $has_valid_data = true;
                                break;
                            }
                        }
                    }
                }
                if ($has_valid_data) {
                    $forbes_list[] = $item;
                }
            }
        }
    }
    
    $forbes_count = count($forbes_list);
    $rows[] = ['Total Forbes Alumni', $forbes_count];
    if ($forbes_count > 0) {
        $forbes_details = [];
        foreach ($forbes_list as $idx => $alumni) {
            $details = [];
            if (!empty($alumni['program_name'])) $details[] = 'Program: ' . $alumni['program_name'];
            if (!empty($alumni['year_of_passing'])) $details[] = 'Year: ' . $alumni['year_of_passing'];
            if (!empty($alumni['founder_company'])) $details[] = 'Company: ' . $alumni['founder_company'];
            if (!empty($alumni['year_founded'])) $details[] = 'Founded: ' . $alumni['year_founded'];
            $forbes_details[] = 'Alumni ' . ($idx + 1) . ': ' . implode(', ', $details);
        }
        $rows[] = ['Forbes Alumni Details', implode('<br>', $forbes_details)];
    } else {
        $rows[] = ['Forbes Alumni Details', '—'];
    }
    
    // Narrative Questions - with better null/empty handling
    $desc_initiative = isset($section1['desc_initiative']) ? trim($section1['desc_initiative']) : '';
    if ($desc_initiative === '' || $desc_initiative === '-' || $desc_initiative === null) {
        $desc_initiative = '—';
    } else {
        error_log("[PDF Export] rowsFromSection1: desc_initiative has data: " . substr($desc_initiative, 0, 50));
    }
    $rows[] = ['Desc Initiative', htmlspecialchars($desc_initiative, ENT_QUOTES, 'UTF-8')];
    
    $desc_impact = isset($section1['desc_impact']) ? trim($section1['desc_impact']) : '';
    if ($desc_impact === '' || $desc_impact === '-' || $desc_impact === null) {
        $desc_impact = '—';
    } else {
        error_log("[PDF Export] rowsFromSection1: desc_impact has data: " . substr($desc_impact, 0, 50));
    }
    $rows[] = ['Desc Impact', htmlspecialchars($desc_impact, ENT_QUOTES, 'UTF-8')];
    
    $desc_collaboration = isset($section1['desc_collaboration']) ? trim($section1['desc_collaboration']) : '';
    if ($desc_collaboration === '' || $desc_collaboration === '-' || $desc_collaboration === null) {
        $desc_collaboration = '—';
    } else {
        error_log("[PDF Export] rowsFromSection1: desc_collaboration has data: " . substr($desc_collaboration, 0, 50));
    }
    $rows[] = ['Desc Collaboration', htmlspecialchars($desc_collaboration, ENT_QUOTES, 'UTF-8')];
    
    $desc_plan = isset($section1['desc_plan']) ? trim($section1['desc_plan']) : '';
    if ($desc_plan === '' || $desc_plan === '-' || $desc_plan === null) {
        $desc_plan = '—';
    } else {
        error_log("[PDF Export] rowsFromSection1: desc_plan has data: " . substr($desc_plan, 0, 50));
    }
    $rows[] = ['Desc Plan', htmlspecialchars($desc_plan, ENT_QUOTES, 'UTF-8')];
    
    $desc_recognition = isset($section1['desc_recognition']) ? trim($section1['desc_recognition']) : '';
    if ($desc_recognition === '' || $desc_recognition === '-' || $desc_recognition === null) {
        $desc_recognition = '—';
    } else {
        error_log("[PDF Export] rowsFromSection1: desc_recognition has data: " . substr($desc_recognition, 0, 50));
    }
    $rows[] = ['Desc Recognition', htmlspecialchars($desc_recognition, ENT_QUOTES, 'UTF-8')];
    
    error_log("[PDF Export] rowsFromSection1: Total rows generated: " . count($rows));
    return $rows;
}

function docSections($section) {
    $map = [
        'section_a' => ['details_dept'],
        'section_b' => ['department_profiles'],
        'section_1' => ['faculty_output'],
        'section_2' => ['nepmarks', 'nep_initiatives'],
        'section_3' => ['department_data', 'departmental_governance'],
        'section_4' => ['studentsupport', 'student_support', 'placement_details', 'intake_actual_strength'],
        'section_5' => ['conferences_workshops', 'collaborations']
    ];
    return $map[$section] ?? [];
}

// Build PDF content --------------------------------------------------------
$style = <<<CSS
<style>
h1,h2,h3,h4 { color: #333333; margin-bottom: 6px; }
.summary-box { border:1px solid #cccccc; padding:8px; margin-bottom:10px; }
.kv-table { width:100%; border-collapse:collapse; margin-bottom:12px; }
.kv-table th { width:35%; background:#f2f2f2; border:1px solid #dddddd; text-align:left; font-weight:bold; }
.kv-table td { border:1px solid #dddddd; }
.score-table { width:100%; border-collapse:collapse; margin-bottom:12px; }
.score-table th, .score-table td { border:1px solid #dddddd; padding:6px; text-align:center; }
.score-table th { background:#f2f2f2; }
ul { margin:4px 0 8px 16px; }
</style>
CSS;

$html = $style;
$html .= '<h1>Department Verification Review</h1>';

// Note: Department info is already in the header, so we don't duplicate it here

// Show Expert Review Information prominently if review exists
if ($expert_review) {
    $html .= '<div class="summary-box" style="background:#e8f4f8; border:2px solid #4a90e2; padding:12px; margin-bottom:15px;">';
    $html .= '<h3 style="margin-top:0; color:#2c5aa0;">Expert Review Information</h3>';
    
    // Show review status
    $status = $expert_review['review_status'] ?? 'pending';
    $is_locked = (int)($expert_review['is_locked'] ?? 0);
    $status_text = $is_locked ? 'Locked/Finalized' : ucfirst($status);
    $html .= '<p><strong>Review Status:</strong> ' . htmlspecialchars($status_text, ENT_QUOTES, 'UTF-8') . '</p>';
    
    // Show expert email
    $html .= '<p><strong>Reviewed By:</strong> ' . htmlspecialchars($expert_review['expert_email'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') . '</p>';
    
    // Show review dates
    if (!empty($expert_review['review_started_at'])) {
        $html .= '<p><strong>Review Started:</strong> ' . htmlspecialchars($expert_review['review_started_at'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if (!empty($expert_review['review_completed_at'])) {
        $html .= '<p><strong>Review Completed:</strong> ' . htmlspecialchars($expert_review['review_completed_at'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if (!empty($expert_review['review_locked_at'])) {
        $html .= '<p><strong>Review Locked:</strong> ' . htmlspecialchars($expert_review['review_locked_at'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
    
    // Show expert remarks/notes prominently
    if (!empty($expert_review['review_notes'])) {
        $html .= '<div style="margin-top:10px; padding:10px; background:#fff; border:1px solid #ccc;">';
        $html .= '<strong>Expert Remarks/Notes:</strong><br>';
        $html .= '<div style="margin-top:5px;">' . nl2br(htmlspecialchars($expert_review['review_notes'], ENT_QUOTES, 'UTF-8')) . '</div>';
        $html .= '</div>';
    } else {
        $html .= '<p><em>No expert remarks provided.</em></p>';
    }
    
    // Show chairman feedback if exists
    if (!empty($expert_review['chairman_notes'])) {
        $html .= '<div style="margin-top:10px; padding:10px; background:#fff3cd; border:1px solid #ffc107;">';
        $html .= '<strong>Chairman Feedback:</strong><br>';
        $html .= '<div style="margin-top:5px;">' . nl2br(htmlspecialchars($expert_review['chairman_notes'], ENT_QUOTES, 'UTF-8')) . '</div>';
        $html .= '</div>';
    }
    
    if (!empty($expert_review['chairman_flag']) && $expert_review['chairman_flag'] !== 'none') {
        $flag_text = ucfirst(str_replace('_', ' ', $expert_review['chairman_flag']));
        $html .= '<p><strong>Chairman Flag:</strong> <span style="color:#d32f2f;">' . htmlspecialchars($flag_text, ENT_QUOTES, 'UTF-8') . '</span></p>';
    }
    
    $html .= '</div>';
} else {
    $html .= '<div class="summary-box" style="background:#fff3cd; border:1px solid #ffc107; padding:10px; margin-bottom:15px;">';
    $html .= '<p><strong>Note:</strong> No expert review has been submitted yet for this department.</p>';
    $html .= '</div>';
}

// Section outputs (BEFORE consolidated score)
$sections = [
    'Section A: Brief Details of Department' => ['key' => 'section_a', 'max' => 0, 'section_key' => 'section_a'],
    'Section B: Department Profile' => ['key' => 'section_b', 'max' => 0, 'section_key' => 'section_b'],
    'Section I: Faculty Output, Research & Professional Activities' => ['key' => 'section_1', 'max' => 300, 'section_key' => 'section_1'],
    'Section II: NEP Initiatives, Teaching, Learning & Assessment' => ['key' => 'section_2', 'max' => 100, 'section_key' => 'section_2'],
    'Section III: Departmental Governance & Practices' => ['key' => 'section_3', 'max' => 110, 'section_key' => 'section_3'],
    'Section IV: Student Support, Achievements & Progression' => ['custom' => 'section_4', 'max' => 140, 'section_key' => 'section_4'],
    'Section V: Conferences, Workshops & Collaborations' => ['custom' => 'section_5', 'max' => 75, 'section_key' => 'section_5'],
];

foreach ($sections as $title => $config) {
    if (isset($config['custom']) && $config['custom'] === 'section_4') {
        $rows = rowsFromSection4($dept_data['section_4'] ?? []);
    } elseif (isset($config['custom']) && $config['custom'] === 'section_5') {
        $rows = rowsFromSection5($dept_data['section_5'] ?? []);
    } elseif (isset($config['key']) && $config['key'] === 'section_1') {
        // Use custom function for Section 1 to properly display startup and narrative data
        // rowsFromSection1 formats the data correctly with proper labels and grouping
        $rows = rowsFromSection1($dept_data['section_1'] ?? [], $dept_id, $academic_year);
    } else {
        $rows = buildRows($dept_data[$config['key']] ?? []);
    }
    $html .= renderTable($title, $rows);
    
    $doc_sections = docSections($config['custom'] ?? $config['key']);
    $docs_html = '';
    foreach ($doc_sections as $doc_key) {
        if (!empty($grouped_docs[$doc_key])) {
            $docs_html .= renderDocs(formatLabel($doc_key), $grouped_docs[$doc_key]);
        }
    }
    $html .= $docs_html;
    
    // Add section summary after each section (if it has max marks)
    if (isset($config['max']) && $config['max'] > 0 && isset($config['section_key'])) {
        $section_key = $config['section_key'];
        $section_auto = number_format($auto_scores[$section_key] ?? 0, 2);
        $section_expert = $expert_review ? number_format((float)$expert_review['expert_score_' . $section_key] ?? 0, 2) : '0.00';
        $section_diff = number_format((float)$section_expert - (float)$section_auto, 2);
        $section_labels = [
            'section_1' => 'Section I: Faculty Output & Research',
            'section_2' => 'Section II: NEP Initiatives',
            'section_3' => 'Section III: Departmental Governance',
            'section_4' => 'Section IV: Student Support & Progression',
            'section_5' => 'Section V: Conferences & Collaborations',
        ];
        $section_label = $section_labels[$section_key] ?? $title;
        $html .= '<div style="margin-top:15px; margin-bottom:20px; padding:10px; background:#f9f9f9; border:1px solid #ddd;">';
        $html .= '<h4 style="margin-top:0;">' . $section_label . ' - Summary</h4>';
        $html .= '<table class="score-table" style="width:100%;"><tr><th>Dept Auto Score</th><th>Expert Score</th><th>Max Marks</th><th>Difference</th></tr>';
        $html .= '<tr><td>' . $section_auto . '</td><td>' . $section_expert . '</td><td>' . $config['max'] . '</td><td>' . $section_diff . '</td></tr>';
        $html .= '</table></div>';
    }
}

// Write all sections first
$pdf->writeHTML($html, true, false, true, false, '');

// Consolidated Score Summary (at the end, after all sections) - Always on a new page
$pdf->AddPage();

$expert_scores = [
    'section_1' => $expert_review ? (float)$expert_review['expert_score_section_1'] : 0,
    'section_2' => $expert_review ? (float)$expert_review['expert_score_section_2'] : 0,
    'section_3' => $expert_review ? (float)$expert_review['expert_score_section_3'] : 0,
    'section_4' => $expert_review ? (float)$expert_review['expert_score_section_4'] : 0,
    'section_5' => $expert_review ? (float)$expert_review['expert_score_section_5'] : 0,
];
$expert_total = $expert_review ? (float)$expert_review['expert_total_score'] : array_sum($expert_scores);

$section_max = [
    'section_1' => 300,
    'section_2' => 100,
    'section_3' => 110,
    'section_4' => 140,
    'section_5' => 75,
];

$score_html = $style;
$score_html .= '<h2>Consolidated Score Summary</h2>';
$score_html .= '<table class="score-table"><tr><th>Section</th><th>Dept Auto</th><th>Expert</th><th>Max</th><th>Difference</th></tr>';
foreach ($section_max as $key => $max) {
    $labels = [
        'section_1' => 'Section I – Faculty Output & Research',
        'section_2' => 'Section II – NEP Initiatives',
        'section_3' => 'Section III – Governance',
        'section_4' => 'Section IV – Student Support',
        'section_5' => 'Section V – Conferences & Collaborations',
    ];
    
    // Use the values from $auto_scores which should already include narrative scores and recalculations
    // This matches the logic in review_complete.php lines 760-780
    $auto_raw = $auto_scores[$key] ?? 0;
    $auto = number_format($auto_raw, 2);
    $expert = number_format($expert_scores[$key] ?? 0, 2);
    $diff_value = ($expert_scores[$key] ?? 0) - $auto_raw;
    $diff = number_format($diff_value, 2);
    
    // Debug logging for consolidated score
    error_log("[PDF Export] Consolidated Score - $key: auto=$auto (raw: $auto_raw), expert=$expert, diff=$diff");
    
    // CRITICAL CHECK: Verify Section I is correct (should be 94.50 for dept 9998)
    if ($key === 'section_1' && $auto_raw < 90) {
        error_log("[PDF Export] WARNING: Section I score seems incorrect! Expected ~94.50, got $auto_raw");
        error_log("[PDF Export] auto_scores_db['section_1'] = " . ($auto_scores_db['section_1'] ?? 'NOT SET'));
        error_log("[PDF Export] Section I score check");
    }
    
    $score_html .= '<tr><td>' . $labels[$key] . '</td><td>' . $auto . '</td><td>' . $expert . '</td><td>' . $max . '</td><td>' . $diff . '</td></tr>';
}
$score_html .= '<tr><th>Total</th><th>' . number_format($auto_scores['total'] ?? 0, 2) . '</th><th>' . number_format($expert_total, 2) . '</th><th>725</th><th>' . number_format($expert_total - ($auto_scores['total'] ?? 0), 2) . '</th></tr></table>';

$pdf->writeHTML($score_html, true, false, true, false, '');

// Signature page removed as requested

$filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $dept_name) . '_Expert_Review.pdf';
$pdf->Output($filename, 'I');
exit;
