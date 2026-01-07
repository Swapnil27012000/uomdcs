<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include required files
require_once('config.php');
require __DIR__ . '/vendor/autoload.php';

// Check required extensions and classes
if (!class_exists('TCPDF')) {
    die('TCPDF class not found. Please ensure you have run composer install.');
}

// Check for GD or Imagick extension
if (!extension_loaded('gd') && !extension_loaded('imagick')) {
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>PHP Extension Required</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    </head>
    <body class="bg-light">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card shadow">
                        <div class="card-body">
                            <h3 class="card-title text-danger mb-4">PHP Extension Required</h3>
                            <p>TCPDF requires either the GD or Imagick extension to handle PNG images with alpha channel.</p>
                            <hr>
                            <h5>How to Enable GD Extension:</h5>
                            <ol class="mb-4">
                                <li>Open your php.ini file (usually located at C:\xampp\php\php.ini)</li>
                                <li>Find the line <code>;extension=gd</code></li>
                                <li>Remove the semicolon to uncomment it: <code>extension=gd</code></li>
                                <li>Save the file</li>
                                <li>Restart your Apache server</li>
                            </ol>
                            <div class="alert alert-info">
                                <strong>Note:</strong> After enabling the extension and restarting Apache, refresh this page to generate the PDF.
                            </div>
                            <a href="unified_login.php" class="btn btn-primary">Return to Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit;
}

use TCPDF;

class OperationalGuidePDF extends TCPDF {
    public function Header() {
        try {
            // Logo
            $muLogo = __DIR__ . '/assets/img/mumbai-university-removebg-preview.png';
            $nirfLogo = __DIR__ . '/assets/img/nirf-full-removebg-preview.png';
            
            if (file_exists($muLogo)) {
                $this->Image($muLogo, 15, 10, 30);
            }
            if (file_exists($nirfLogo)) {
                $this->Image($nirfLogo, 170, 10, 25);
            }
            
            // Set font
            $this->SetFont('helvetica', 'B', 16);
            
            // Title
            $this->Cell(0, 30, 'University of Mumbai - NIRF Portal', 0, false, 'C', 0, '', 0, false, 'M', 'M');
            
            // Line break
            $this->Ln(35);
        } catch (Exception $e) {
            error_log("Header Error: " . $e->getMessage());
        }
    }

    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        // Set font
        $this->SetFont('helvetica', 'I', 8);
        // Page number
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// Create new PDF document
$pdf = new OperationalGuidePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('MU NIRF Portal');
$pdf->SetAuthor('University of Mumbai');
$pdf->SetTitle('NIRF Portal - Operational Guide');

// Set default header data
$pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);

// Set margins
$pdf->SetMargins(15, 40, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Add first page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', 'B', 20);

// Title
$pdf->Cell(0, 10, 'Operational Guide - NIRF Data Submission', 0, 1, 'C');
$pdf->Ln(10);

// Function to convert PNG to JPG if it has transparency
function convertPNGtoJPG($pngPath) {
    if (!file_exists($pngPath)) {
        return false;
    }

    // Check if image is PNG and has alpha channel
    $imageInfo = getimagesize($pngPath);
    if ($imageInfo[2] !== IMAGETYPE_PNG) {
        return $pngPath; // Return original path if not PNG
    }

    // Create JPG version if needed
    $jpgPath = substr($pngPath, 0, -4) . '_converted.jpg';
    
    // If JPG version already exists, return its path
    if (file_exists($jpgPath)) {
        return $jpgPath;
    }

    // Convert PNG to JPG
    $image = imagecreatefrompng($pngPath);
    $bg = imagecreatetruecolor(imagesx($image), imagesy($image));
    imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
    imagealphablending($bg, true);
    imagecopy($bg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
    imagedestroy($image);
    
    // Save as JPG
    imagejpeg($bg, $jpgPath, 100);
    imagedestroy($bg);
    
    return $jpgPath;
}

// Function to safely add image if it exists
function addImageIfExists($pdf, $imagePath, $width = 180) {
    $fullPath = __DIR__ . '/' . $imagePath;
    if (file_exists($fullPath)) {
        try {
            // Try to use original image first
            $pdf->Image($fullPath, '', '', $width);
        } catch (Exception $e) {
            // If original fails, try converting to JPG
            $convertedPath = convertPNGtoJPG($fullPath);
            if ($convertedPath) {
                $pdf->Image($convertedPath, '', '', $width);
            } else {
                $pdf->SetFont('helvetica', 'I', 10);
                $pdf->MultiCell(0, 10, '[Image conversion failed - ' . basename($imagePath) . ']', 0, 'C');
                $pdf->SetFont('helvetica', '', 12);
            }
        }
    } else {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->MultiCell(0, 10, '[Screenshot placeholder - ' . basename($imagePath) . ']', 0, 'C');
        $pdf->SetFont('helvetica', '', 12);
    }
}

// Create screenshots directory if it doesn't exist
$screenshotsDir = __DIR__ . '/assets/img/screenshots';
if (!file_exists($screenshotsDir)) {
    mkdir($screenshotsDir, 0777, true);
}

// Content sections
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, '1. Login Process', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 12);
$pdf->MultiCell(0, 10, 'Access the NIRF portal using your registered email and password. The system supports multiple user types including Department Users, Administrators, and Committee Members.', 0, 'L');
addImageIfExists($pdf, 'assets/img/screenshots/login_screen.png');
$pdf->Ln(10);

$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, '2. OTP Verification', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 12);
$pdf->MultiCell(0, 10, 'After entering your credentials, you\'ll receive an OTP via email. Enter the 6-digit code to complete the login process.', 0, 'L');
addImageIfExists($pdf, 'assets/img/screenshots/otp_screen.png');
$pdf->Ln(10);

$pdf->SetFont('helvetica', 'B', 16);
$pdf->AddPage();
$pdf->Cell(0, 10, '3. Dashboard Navigation', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 12);
$pdf->MultiCell(0, 10, 'The dashboard provides quick access to all NIRF data submission sections. Use the sidebar menu to navigate between different forms and reports.', 0, 'L');
addImageIfExists($pdf, 'assets/img/screenshots/dashboard.png');
$pdf->Ln(10);

$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, '4. Data Entry Forms', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 12);
$pdf->MultiCell(0, 10, "Each section contains specific forms for NIRF data submission. Key features include:\n\n• Auto-save functionality\n• Document upload capability\n• Data validation\n• Progress tracking", 0, 'L');
addImageIfExists($pdf, 'assets/img/screenshots/data_entry.png');
$pdf->Ln(10);

$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, '5. Document Upload Guidelines', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 12);
$pdf->MultiCell(0, 10, "When uploading supporting documents:\n\n• Use PDF format for all uploads\n• Maximum file size: 5MB\n• Ensure documents are clearly readable\n• Include official letterhead where applicable", 0, 'L');
addImageIfExists($pdf, 'assets/img/screenshots/upload_docs.png');
$pdf->Ln(10);

$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, '6. Final Submission', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 12);
$pdf->MultiCell(0, 10, "Before final submission:\n\n• Review all entered data\n• Ensure all required documents are uploaded\n• Generate and verify the preview report\n• Get necessary approvals\n• Submit within the deadline", 0, 'L');
addImageIfExists($pdf, 'assets/img/screenshots/final_submit.png');

$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Contact Information', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 12);
$pdf->MultiCell(0, 10, "For technical support:\nEmail: techsupport.nirf@mu.ac.in\n\nFor NIRF related queries:\nEmail: nirf.support@mu.ac.in\n\nHelpdesk Hours:\nMonday to Friday: 9:00 AM to 5:00 PM", 0, 'L');

try {
    // Create directory if it doesn't exist
    $directory = __DIR__ . '/assets/files';
    if (!file_exists($directory)) {
        mkdir($directory, 0777, true);
    }

    // Generate PDF
    $pdfPath = $directory . '/Nirf_Sample_Data.pdf';
    $pdf->Output($pdfPath, 'F');

    if (file_exists($pdfPath)) {
        // Redirect back to login page
        header('Location: unified_login.php');
        exit;
    } else {
        throw new Exception("Failed to create PDF file");
    }
} catch (Exception $e) {
    die("Error generating PDF: " . $e->getMessage());
}