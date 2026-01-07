<?php
/**
 * Export UDRF Data Report as PDF
 * Shows all department data in expert review format but ONLY with department auto scores
 * NO expert scores should be shown - strictly department auto-generated scores only
 */

// Clear all output buffers first
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load config silently
ob_start();
if (!isset($conn) || !$conn) {
    require_once(__DIR__ . '/../config.php');
}
ob_end_clean();

// Load session silently
ob_start();
require_once(__DIR__ . '/session.php');
ob_end_clean();

// Clear buffers again
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Validate session
if (!isset($userInfo) || empty($userInfo)) {
    die('Access denied. Please login.');
}

$dept_id = (int)($userInfo['DEPT_ID'] ?? 0);
$dept_code = htmlspecialchars($userInfo['DEPT_COLL_NO'] ?? '', ENT_QUOTES, 'UTF-8');
$dept_name = htmlspecialchars($userInfo['DEPT_NAME'] ?? '', ENT_QUOTES, 'UTF-8');

if (!$dept_id) {
    die('Department ID not found.');
}

// Load common functions for getAcademicYear()
if (file_exists(__DIR__ . '/common_functions.php')) {
    require_once(__DIR__ . '/common_functions.php');
}

// Calculate academic year - use centralized function
if (function_exists('getAcademicYear')) {
    $academic_year = getAcademicYear();
} else {
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    $academic_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 2) . '-' . ($current_year - 1);
}

// Load expert functions for data fetching and score calculation
require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
require_once(__DIR__ . '/../Expert_comty_login/data_fetcher.php');

// CRITICAL: Always fetch fresh data (bypass any potential caching)
clearDepartmentScoreCache($dept_id, $academic_year, false);

// Fetch ALL department data (fresh from database)
$dept_data = fetchAllDepartmentData($dept_id, $academic_year);
$documents = fetchAllSupportingDocuments($dept_id, $academic_year);
$grouped_docs = groupDocumentsBySection($documents);

// CRITICAL: Use centralized calculation function for consistency
// This ensures all sections are calculated using the same logic
$auto_scores = recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, true);

// Locate TCPDF
$tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdf_path)) {
    die('TCPDF library not found. Please ensure vendor/tecnickcom/tcpdf is installed.');
}
require_once $tcpdf_path;

// Custom PDF class with header matching dept_login format
class UDRFDataReportPDF extends TCPDF {
    private $dept_name = '';
    private $dept_code = '';
    private $A_YEAR = '';
    
    public function setHeaderInfo($dept_name, $dept_code, $A_YEAR) {
        $this->dept_name = $dept_name;
        $this->dept_code = $dept_code;
        $this->A_YEAR = $A_YEAR;
    }
    
    public function Header() {
        $page_width = $this->getPageWidth();
        $margin = 15;
        
        // Logo
        $logo_path = __DIR__ . '/../assets/img/mumbai-university-removebg-preview.png';
        $logo_size = 20;
        $logo_x = $margin;
        $logo_y = 8;
        
        if (file_exists($logo_path)) {
            try {
                $this->Image($logo_path, $logo_x, $logo_y, $logo_size, $logo_size, '', '', '', false, 300, '', false, false, 0);
            } catch (Exception $e) {
                // Continue without logo
            }
        }
        
        // Text area
        $text_x = $logo_x + $logo_size + 8;
        $text_width = $page_width - $text_x - $margin;
        
        // University name
        $this->SetFont('times', 'B', 18);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($text_x, $logo_y);
        $this->Cell($text_width, 8, 'University of Mumbai', 0, 1, 'L', false, '', 0, false, 'T', 'M');
        
        // Portal name
        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(60, 60, 60);
        $this->SetXY($text_x, $logo_y + 9);
        $this->Cell($text_width, 6, 'Centralized DCS Ranking Portal', 0, 1, 'L', false, '', 0, false, 'T', 'M');
        
        // Department info
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

// Include helper functions from export_review_pdf.php
// These functions format the data for display
function decodePossibleJson($value) {
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value) && !empty($value)) {
        $trimmed = trim($value);
        if (($trimmed[0] === '{' && substr($trimmed, -1) === '}') || 
            ($trimmed[0] === '[' && substr($trimmed, -1) === ']')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
    }
    return $value;
}

function formatLabel($key) {
    $key = str_replace(['_', '-'], ' ', $key);
    return ucwords(trim($key));
}

function formatScalar($value) {
    if (is_null($value) || $value === '') {
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

function renderDocs($title, $docs) {
    if (empty($docs)) {
        return '';
    }
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

// Use the same rowsFromSection1 function as expert export
// We'll include it by reading the expert export file and extracting just the function
// For now, use buildRows which handles most formatting correctly
// The key difference: UDRF report shows ONLY dept auto scores, no expert scores
function rowsFromSection1UDRF($section1, $dept_id = null, $academic_year_param = null) {
    global $conn;
    $rows = [];
    
    // Get academic year
    global $academic_year;
    $query_academic_year = $academic_year_param ?? $academic_year ?? getAcademicYear();
    
    // Try direct database query to ensure we get all data (same as expert export)
    if ($dept_id && $conn) {
        $direct_query = "SELECT dpiit_startup_details, vc_investment_details, seed_funding_details, 
                        fdi_investment_details, innovation_grants_details, trl_innovations_details, 
                        turnover_achievements_details, forbes_alumni_details, 
                        desc_initiative, desc_impact, desc_collaboration, desc_plan, desc_recognition, A_YEAR 
                        FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
        
        $direct_stmt = $conn->prepare($direct_query);
        if ($direct_stmt) {
            $direct_stmt->bind_param("is", $dept_id, $query_academic_year);
            $direct_stmt->execute();
            $direct_result = $direct_stmt->get_result();
            $direct_row = $direct_result->fetch_assoc();
            $direct_stmt->close();
            
            if ($direct_row) {
                // Merge direct query results with section1 data
                if (empty($section1)) {
                    $section1 = $direct_row;
                } else {
                    foreach ($direct_row as $key => $value) {
                        if (!empty($value) && ($value !== '[]' && $value !== '{}' && $value !== 'null')) {
                            $section1[$key] = $value;
                        }
                    }
                }
            }
        }
    }
    
    // Build rows from section1 data using buildRows (handles JSON fields automatically)
    // This will format all fields including startups, narrative questions, etc.
    $rows = buildRows($section1);
    
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

// Build PDF content
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
$html .= '<h1>UDRF Data Report</h1>';
$html .= '<p style="color:#666; margin-bottom:15px;"><strong>Note:</strong> This report shows department auto-calculated scores only. Expert scores are not included.</p>';

// Section outputs
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
        // Use rowsFromSection1UDRF function for proper formatting
        $rows = rowsFromSection1UDRF($dept_data['section_1'] ?? [], $dept_id, $academic_year);
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
    
    // Add section summary after each section (ONLY department auto scores, NO expert scores)
    if (isset($config['max']) && $config['max'] > 0 && isset($config['section_key'])) {
        $section_key = $config['section_key'];
        $section_auto = number_format($auto_scores[$section_key] ?? 0, 2);
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
        $html .= '<table class="score-table" style="width:100%;"><tr><th>Department Auto Score</th><th>Max Marks</th></tr>';
        $html .= '<tr><td>' . $section_auto . '</td><td>' . $config['max'] . '</td></tr>';
        $html .= '</table></div>';
    }
}

// Write all sections first
$pdf = new UDRFDataReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setHeaderInfo($dept_name, $dept_code, $academic_year);
$pdf->SetCreator('MU Department Portal');
$pdf->SetAuthor('University of Mumbai');
$pdf->SetTitle("UDRF Data Report - {$dept_name}");
$pdf->SetMargins(15, 50, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetFont('dejavusans', '', 9);

$pdf->AddPage();
$pdf->writeHTML($html, true, false, true, false, '');

// Consolidated Score Summary (at the end, after all sections) - Always on a new page
$pdf->AddPage();

$section_max = [
    'section_1' => 300,
    'section_2' => 100,
    'section_3' => 110,
    'section_4' => 140,
    'section_5' => 75,
];

$score_html = $style;
$score_html .= '<h2>Consolidated Score Summary</h2>';
$score_html .= '<p style="color:#666; margin-bottom:15px;"><strong>Note:</strong> These are department auto-calculated scores only. Expert scores are not included in this report.</p>';
$score_html .= '<table class="score-table"><tr><th>Section</th><th>Department Auto Score</th><th>Max Marks</th></tr>';
foreach ($section_max as $key => $max) {
    $labels = [
        'section_1' => 'Section I – Faculty Output & Research',
        'section_2' => 'Section II – NEP Initiatives',
        'section_3' => 'Section III – Governance',
        'section_4' => 'Section IV – Student Support',
        'section_5' => 'Section V – Conferences & Collaborations',
    ];
    
    $auto_raw = $auto_scores[$key] ?? 0;
    $auto = number_format($auto_raw, 2);
    
    $score_html .= '<tr><td>' . $labels[$key] . '</td><td>' . $auto . '</td><td>' . $max . '</td></tr>';
}
$score_html .= '<tr><th>Total</th><th>' . number_format($auto_scores['total'] ?? 0, 2) . '</th><th>725</th></tr></table>';

$pdf->writeHTML($score_html, true, false, true, false, '');

$filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $dept_name) . '_UDRF_Data_Report.pdf';
$pdf->Output($filename, 'I');
exit;

