<?php
/**
 * Generic PDF Export Handler for Department Pages
 * Exports form data from any dept_login page as PDF
 * Follows UNIFIED_SECURITY_GUIDE.md requirements
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

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Get page parameter
$page = isset($_GET['page']) ? trim($_GET['page']) : '';
if (empty($page)) {
    die('Page parameter is required.');
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
    $academic_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
}
$A_YEAR_DB = (int)explode('-', $academic_year)[0];

// Map page names to table names and titles
$page_config = [
    'profile' => [
        'table' => 'department_profiles',
        'title' => 'Department Profile',
        'dept_field' => 'dept_id'
    ],
    'DetailsOfDepartment' => [
        'table' => 'brief_details_of_the_department',
        'title' => 'Brief Details of Department',
        'dept_field' => 'DEPT_ID'
    ],
    'Programmes_Offered' => [
        'table' => 'programmes',
        'title' => 'Programmes Offered',
        'dept_field' => 'DEPT_ID'
    ],
    'ExecutiveDevelopment' => [
        'table' => 'exec_dev',
        'title' => 'Executive Development Programmes',
        'dept_field' => 'DEPT_ID'
    ],
    'IntakeActualStrength' => [
        'table' => 'intake_actual_strength',
        'title' => 'Intake & Actual Strength',
        'dept_field' => 'DEPT_ID'
    ],
    'PlacementDetails' => [
        'table' => 'placement_details',
        'title' => 'Placement Details',
        'dept_field' => 'DEPT_ID'
    ],
    'SalaryDetails' => [
        'table' => 'salary_details',
        'title' => 'Salary Details',
        'dept_field' => 'DEPT_ID'
    ],
    'EmployerDetails' => [
        'table' => 'employers_details',
        'title' => 'Employer Details',
        'dept_field' => 'DEPT_ID'
    ],
    'phd' => [
        'table' => 'phd_details',
        'title' => 'PhD Details',
        'dept_field' => 'DEPT_ID'
    ],
    'FacultyDetails' => [
        'table' => 'faculty_details',
        'title' => 'Faculty Details',
        'dept_field' => 'DEPT_ID'
    ],
    'AcademicPeers' => [
        'table' => 'academic_peers',
        'title' => 'Academic Peers',
        'dept_field' => 'DEPT_ID'
    ],
    'FacultyOutput' => [
        'table' => 'faculty_output',
        'title' => 'Faculty Output, Research & Professional Activities',
        'dept_field' => 'DEPT_ID'
    ],
    'NEPInitiatives' => [
        'table' => 'nepmarks',
        'title' => 'NEP Initiatives, Teaching, Learning & Assessment',
        'dept_field' => 'DEPT_ID'
    ],
    'Departmental_Governance' => [
        'table' => 'department_data',
        'title' => 'Departmental Governance & Practices',
        'dept_field' => 'DEPT_ID'
    ],
    'StudentSupport' => [
        'table' => 'studentsupport',
        'title' => 'Student Support, Achievements & Progression',
        'dept_field' => 'dept'  // Note: studentsupport table uses 'dept' not 'DEPT_ID'
    ],
    'ConferencesWorkshops' => [
        'table' => 'conferences_workshops',
        'title' => 'Conferences, Workshops & Seminars',
        'dept_field' => 'DEPT_ID'
    ],
    'Collaborations' => [
        'table' => 'collaborations',
        'title' => 'Collaborations',
        'dept_field' => 'DEPT_ID'
    ]
];

// Locate TCPDF early (needed for Consolidated Score)
$tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdf_path)) {
    die('TCPDF library not found. Please ensure vendor/tecnickcom/tcpdf is installed.');
}
require_once $tcpdf_path;

// Custom PDF class with header (define early for Consolidated Score)
class DepartmentPagePDF extends TCPDF {
    private $dept_name = '';
    private $dept_code = '';
    private $A_YEAR = '';
    private $page_title = '';
    
    public function setHeaderInfo($dept_name, $dept_code, $A_YEAR, $page_title) {
        $this->dept_name = $dept_name;
        $this->dept_code = $dept_code;
        $this->A_YEAR = $A_YEAR;
        $this->page_title = $page_title;
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
        
        // Page title
        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $title_y = $info_y + 6;
        $this->SetXY($margin, $title_y);
        $this->Cell($page_width - ($margin * 2), 6, $this->page_title, 0, 1, 'L', false, '', 0, false, 'T', 'M');
        
        // Horizontal line
        $line_y = $title_y + 8;
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

// Special handling for Consolidated Score (calculated, not from a table)
if ($page === 'Consolidated_Score') {
    // Load expert functions for score calculation
    ob_start();
    require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
    require_once(__DIR__ . '/../Expert_comty_login/data_fetcher.php');
    ob_end_clean();
    
    // Fetch department data for recalculation
    $dept_data = fetchAllDepartmentData($dept_id, $academic_year);
    
    // CRITICAL: Use centralized calculation function for consistency
    // This ensures all sections are calculated using the same logic as review_complete.php
    $auto_scores = recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, true);
    
    // Debug logging
    error_log("[Dept PDF Export] FINAL SCORES (ALL RECALCULATED) - S1: " . $auto_scores['section_1'] . ", S2: " . $auto_scores['section_2'] . ", S3: " . $auto_scores['section_3'] . ", S4: " . $auto_scores['section_4'] . ", S5: " . $auto_scores['section_5'] . ", TOTAL: " . $auto_scores['total']);
    
    // Note: Cache is already updated by recalculateAllSectionsFromData(), no need to update again
    
    // Build HTML for consolidated score
    $style = '<style>
h1,h2,h3 { color: #333333; margin-bottom: 10px; }
.score-table { width:100%; border-collapse:collapse; margin-bottom:15px; }
.score-table th { background:#667eea; color:white; border:1px solid #ddd; padding:8px; text-align:left; font-weight:bold; }
.score-table td { border:1px solid #ddd; padding:8px; }
.score-table tr.total-row { background:#e8f5e9; font-weight:bold; }
.score-value { font-weight:bold; color:#667eea; text-align:center; }
.info-box { background:#e3f2fd; border-left:4px solid #2196f3; padding:10px; margin:15px 0; }
</style>';
    
    $html = $style;
    $html .= '<h1 style="text-align:center; color:#667eea;">Consolidated Score Summary</h1>';
    $html .= '<p style="text-align:center; color:#666; margin-bottom:20px;">Auto-calculated scores based on department submitted data</p>';
    
     $html .= '<table class="score-table">';
     $html .= '<thead><tr><th style="width:5%;">Sr.</th><th style="width:50%;">Section</th><th style="width:20%; text-align:center;">Department Auto Score</th><th style="width:25%; text-align:center;">Max Marks</th></tr></thead>';
     $html .= '<tbody>';
     
     $sections = [
         ['num' => 'I', 'name' => 'Faculty Output, Research & Professional Activities', 'score' => $auto_scores['section_1'], 'max' => 300],
         ['num' => 'II', 'name' => 'NEP Initiatives, Teaching, Learning & Assessment', 'score' => $auto_scores['section_2'], 'max' => 100],
         ['num' => 'III', 'name' => 'Departmental Governance & Practices', 'score' => $auto_scores['section_3'], 'max' => 110],
         ['num' => 'IV', 'name' => 'Student Support, Achievements & Progression', 'score' => $auto_scores['section_4'], 'max' => 140],
         ['num' => 'V', 'name' => 'Conferences, Workshops & Collaborations', 'score' => $auto_scores['section_5'], 'max' => 75],
     ];
     
     foreach ($sections as $section) {
         $html .= '<tr>';
         $html .= '<td style="text-align:center; font-weight:bold;">' . htmlspecialchars($section['num'], ENT_QUOTES, 'UTF-8') . '</td>';
         $html .= '<td><strong>' . htmlspecialchars($section['name'], ENT_QUOTES, 'UTF-8') . '</strong></td>';
         $html .= '<td class="score-value">' . number_format($section['score'], 2) . '</td>';
         $html .= '<td style="text-align:center;">' . $section['max'] . '</td>';
         $html .= '</tr>';
     }
     
     // Total row
     $html .= '<tr class="total-row">';
     $html .= '<td colspan="2" style="text-align:right; font-size:1.1em;"><strong>GRAND TOTAL</strong></td>';
     $html .= '<td class="score-value" style="font-size:1.2em;">' . number_format($auto_scores['total'], 2) . '</td>';
     $html .= '<td style="text-align:center; font-size:1.1em;"><strong>725</strong></td>';
     $html .= '</tr>';
    
    $html .= '</tbody></table>';
    
    // Information box
    $html .= '<div class="info-box">';
    $html .= '<h3 style="margin-top:0; color:#1976d2;"><i class="fas fa-info-circle"></i> About These Scores</h3>';
    $html .= '<p><strong>Auto-Calculated Scores:</strong> These scores are automatically calculated based on the data you have submitted in various forms. The calculation follows UDRF guidelines.</p>';
    $html .= '<p class="mb-0"><strong>Note:</strong> These are preliminary auto-calculated scores. Final scores will be determined after expert committee review and verification.</p>';
    $html .= '</div>';
    
    // Create PDF
    $pdf = new DepartmentPagePDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setHeaderInfo($dept_name, $dept_code, $academic_year, 'Consolidated Score Summary');
    $pdf->SetCreator('MU Department Portal');
    $pdf->SetAuthor('University of Mumbai');
    $pdf->SetTitle("Consolidated Score - {$dept_name}");
    $pdf->SetMargins(15, 50, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetFont('dejavusans', '', 9);
    
    // Add first page and write HTML immediately
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Output PDF
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $dept_name) . '_Consolidated_Score.pdf';
    $pdf->Output($filename, 'I');
    exit;
}

if (!isset($page_config[$page])) {
    die('Invalid page specified.');
}

$config = $page_config[$page];
$table_name = $config['table'];
$page_title = $config['title'];
$dept_field = $config['dept_field'];

// TCPDF and DepartmentPagePDF class already loaded above

// Fetch data from database
$data_rows = [];
// Special handling for different pages
if ($page === 'profile') {
    // Profile page uses DEPT_COLL_NO and string A_YEAR
    $dept_identifier = $userInfo['DEPT_COLL_NO'] ?? $dept_code;
    $query = "SELECT * FROM `{$table_name}` WHERE `{$dept_field}` = ? AND A_YEAR = ?";
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ss', $dept_identifier, $academic_year);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $data_rows[] = $row;
                }
                mysqli_free_result($result);
            }
        }
        mysqli_stmt_close($stmt);
    }
} elseif ($page === 'StudentSupport') {
    // StudentSupport table uses 'dept' field and integer A_YEAR
    $query = "SELECT * FROM `{$table_name}` WHERE `{$dept_field}` = ? AND A_YEAR = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("PDF Export Error: Failed to prepare query for StudentSupport - " . mysqli_error($conn));
        die('Database error: Failed to prepare query.');
    }
    mysqli_stmt_bind_param($stmt, 'ii', $dept_id, $A_YEAR_DB);
    if (!mysqli_stmt_execute($stmt)) {
        error_log("PDF Export Error: Failed to execute query for StudentSupport - " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        die('Database error: Failed to execute query.');
    }
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $data_rows[] = $row;
        }
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
} elseif ($page === 'Departmental_Governance') {
    // Departmental_Governance table may not have A_YEAR column - check by DEPT_ID only
    // Try with A_YEAR first, if column doesn't exist, try without
    $query = "SELECT * FROM `{$table_name}` WHERE `{$dept_field}` = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("PDF Export Error: Failed to prepare query for Departmental_Governance - " . mysqli_error($conn));
        die('Database error: Failed to prepare query.');
    }
    mysqli_stmt_bind_param($stmt, 'i', $dept_id);
    if (!mysqli_stmt_execute($stmt)) {
        error_log("PDF Export Error: Failed to execute query for Departmental_Governance - " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        die('Database error: Failed to execute query.');
    }
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $data_rows[] = $row;
        }
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
} else {
    // Default: most tables use DEPT_ID and integer A_YEAR
    $query = "SELECT * FROM `{$table_name}` WHERE `{$dept_field}` = ? AND A_YEAR = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("PDF Export Error: Failed to prepare query for {$page} - " . mysqli_error($conn));
        die('Database error: Failed to prepare query. Table: ' . htmlspecialchars($table_name, ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_bind_param($stmt, 'ii', $dept_id, $A_YEAR_DB);
    if (!mysqli_stmt_execute($stmt)) {
        error_log("PDF Export Error: Failed to execute query for {$page} - " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        die('Database error: Failed to execute query.');
    }
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $data_rows[] = $row;
        }
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
}

// Helper functions
function formatValue($value) {
    if (is_null($value) || $value === '') {
        return '—';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if (is_numeric($value)) {
        return $value;
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatLabel($key) {
    $key = str_replace(['_', '-'], ' ', $key);
    return ucwords(trim($key));
}

// Build HTML content FIRST - before creating PDF
$style = <<<CSS
<style>
h1,h2,h3 { color: #333333; margin-bottom: 8px; }
.kv-table { width:100%; border-collapse:collapse; margin-bottom:12px; }
.kv-table th { width:35%; background:#f2f2f2; border:1px solid #dddddd; text-align:left; font-weight:bold; padding:6px; }
.kv-table td { border:1px solid #dddddd; padding:6px; }
.no-data { padding:20px; text-align:center; color:#666; }
</style>
CSS;

$html = $style;
$html .= '<h2>' . htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') . '</h2>';

if (empty($data_rows)) {
    $html .= '<div class="no-data"><p>No data has been submitted for this form yet.</p></div>';
} else {
    // Process each row - build all HTML first
    foreach ($data_rows as $row_index => $row) {
        // Add page break marker for multiple records (but don't add page yet)
        if ($row_index > 0) {
            $html .= '<div style="page-break-before: always;"></div>';
        }
        
        $html_rows = [];
        foreach ($row as $key => $value) {
            // Skip internal fields
            if (in_array(strtolower($key), ['id', 'dept_id', 'dept_col_no', 'a_year', 'created_at', 'updated_at'])) {
                continue;
            }
            
            $label = formatLabel($key);
            $formatted_value = formatValue($value);
            
            // Handle JSON fields - improved formatting for arrays of objects
            if (is_string($value) && !empty($value)) {
                $trimmed = trim($value);
                if (($trimmed[0] === '{' && substr($trimmed, -1) === '}') || 
                    ($trimmed[0] === '[' && substr($trimmed, -1) === ']')) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        if (!empty($decoded)) {
                            // Check if it's an array of objects (like PhD awardees)
                            // An array of objects has numeric keys (0,1,2...) with object values
                            $is_array_of_objects = false;
                            if (!empty($decoded)) {
                                $array_keys = array_keys($decoded);
                                // Check if array has numeric sequential keys (0,1,2...)
                                $has_numeric_keys = ($array_keys === range(0, count($decoded) - 1));
                                
                                if ($has_numeric_keys) {
                                    // Check if first value is an associative array (object)
                                    $first_item = reset($decoded);
                                    if (is_array($first_item) && !empty($first_item)) {
                                        $item_keys = array_keys($first_item);
                                        // If item keys are NOT sequential numeric, it's an object
                                        if ($item_keys !== range(0, count($first_item) - 1)) {
                                            $is_array_of_objects = true;
                                        }
                                    }
                                }
                            }
                            
                            if ($is_array_of_objects) {
                                // Format as table for array of objects (like PhD awardees)
                                $formatted_value = '<table style="width:100%; border-collapse:collapse; margin:5px 0; font-size:8pt;">';
                                // Get headers from first object
                                $headers = array_keys($first_item);
                                $formatted_value .= '<tr style="background:#f2f2f2;">';
                                // Add "No." column first for numbering (starts at 1, not 0)
                                $formatted_value .= '<th style="border:1px solid #ddd; padding:4px; text-align:center; font-weight:bold; width:40px;">No.</th>';
                                foreach ($headers as $header) {
                                    $formatted_value .= '<th style="border:1px solid #ddd; padding:4px; text-align:left; font-weight:bold;">' . htmlspecialchars(formatLabel($header), ENT_QUOTES, 'UTF-8') . '</th>';
                                }
                                $formatted_value .= '</tr>';
                                
                                // Add rows for each object - numbering starts at 1
                                $row_number = 1;
                                foreach ($decoded as $index => $obj) {
                                    if (is_array($obj)) {
                                        $formatted_value .= '<tr>';
                                        // Add row number (starts at 1)
                                        $formatted_value .= '<td style="border:1px solid #ddd; padding:4px; text-align:center; font-weight:bold;">' . $row_number . '</td>';
                                        foreach ($headers as $header) {
                                            $cell_value = isset($obj[$header]) ? formatValue($obj[$header]) : '—';
                                            $formatted_value .= '<td style="border:1px solid #ddd; padding:4px;">' . $cell_value . '</td>';
                                        }
                                        $formatted_value .= '</tr>';
                                        $row_number++;
                                    }
                                }
                                $formatted_value .= '</table>';
                            } else {
                                // Check if it's a simple indexed array (0,1,2...) with string/number values
                                $array_keys = array_keys($decoded);
                                $is_indexed_array = ($array_keys === range(0, count($decoded) - 1));
                                $first_value = reset($decoded);
                                $is_simple_array = $is_indexed_array && !is_array($first_value);
                                
                                if ($is_simple_array) {
                                    // Simple indexed array - format as numbered list starting from 1
                                    $formatted_value = '<ol style="margin:5px 0; padding-left:25px;">';
                                    foreach ($decoded as $v) {
                                        $formatted_value .= '<li style="margin-bottom:5px;">' . formatValue($v) . '</li>';
                                    }
                                    $formatted_value .= '</ol>';
                                } else {
                                    // Regular associative array - format as list
                                    $formatted_value = '<ul style="margin:5px 0; padding-left:20px;">';
                                    foreach ($decoded as $k => $v) {
                                        if (is_array($v)) {
                                            // Nested array - format recursively
                                            $nested = '';
                                            foreach ($v as $nk => $nv) {
                                                $nested .= '<strong>' . formatLabel($nk) . ':</strong> ' . formatValue($nv) . '; ';
                                            }
                                            $formatted_value .= '<li><strong>' . formatLabel($k) . ':</strong> ' . rtrim($nested, '; ') . '</li>';
                                        } else {
                                            $formatted_value .= '<li><strong>' . formatLabel($k) . ':</strong> ' . formatValue($v) . '</li>';
                                        }
                                    }
                                    $formatted_value .= '</ul>';
                                }
                            }
                        } else {
                            $formatted_value = '—';
                        }
                    }
                }
            }
            
            $html_rows[] = '<tr><th>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th><td>' . $formatted_value . '</td></tr>';
        }
        
        if (!empty($html_rows)) {
            $html .= '<table class="kv-table">';
            $html .= implode('', $html_rows);
            $html .= '</table>';
        }
    }
}

// Create PDF AFTER building HTML content
$pdf = new DepartmentPagePDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setHeaderInfo($dept_name, $dept_code, $academic_year, $page_title);
$pdf->SetCreator('MU Department Portal');
$pdf->SetAuthor('University of Mumbai');
$pdf->SetTitle("{$page_title} - {$dept_name}");
$pdf->SetMargins(15, 50, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetFont('dejavusans', '', 9);

// Add first page and write HTML immediately
$pdf->AddPage();
// Write HTML to PDF - this will automatically handle page breaks
$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $dept_name) . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $page_title) . '.pdf';
$pdf->Output($filename, 'I');
exit;

