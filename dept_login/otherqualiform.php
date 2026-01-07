<?php
session_start();
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php';
}
// require "header.php";


if (!isset($_SESSION['dept_id'])) {
    die("Session expired. Please login again.");
}

$dept = $_SESSION['dept_id'];

$date = date_default_timezone_set('Asia/Kolkata');
$timestamp = date("Y-m-d H:i:s");
$timestamp1 = date("Y_m_d_H_i_s");


$particulars = "";
if (isset($_POST['particulars'])) {
    $particulars = ($_POST['particulars']);
}


// echo "<script>alert('$particulars ');</script>";
// die;

// Fetch particulars id information
// $query = "SELECT * FROM datapoint_values WHERE dp_value = '$particulars'";
// $result = mysqli_query($conn, $query);
// $info = mysqli_fetch_array($result, MYSQLI_ASSOC);
// $particulars_id = $info['dp_id'];

//

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $srno = intval($_POST['srno']);
    // $particulars_id = mysqli_real_escape_string($conn, $_POST['particulars_id']);
    
    $query = "SELECT * FROM datapoint_values WHERE dp_value = '$particulars'";
    $result = mysqli_query($conn, $query);
    $info = mysqli_fetch_array($result, MYSQLI_ASSOC);
    $particulars_id = $info['dp_id'];



    // $A_YEAR = $_POST['A_YEAR'];
    $A_YEAR = mysqli_real_escape_string($conn, $_POST['A_YEAR']);

    // File field name (doc1, doc2, etc.)
    $fileKey = "doc" . $srno;

    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] != 0) {
        die("No file uploaded or upload error.");
    }

    $file = $_FILES[$fileKey];
    $fileName = $file['name'];
    $fileTmp = $file['tmp_name'];
    $fileSize = $file['size'];

    // Validation: size < 5MB
    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($fileSize > $maxSize) {
        die("File size exceeds 5MB limit.");
    }

    // Validation: only PDF
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        die("Only PDF files are allowed.");
    }

    // Destination folder: documents/{dept}/{particulars}/
    // org eg.. uploads/101/NEPInitiatives/101_NEPInitiatives_doc1.pdf
    $uploadDir = "uploads/" . $A_YEAR . "/" . $dept . "/" . $particulars . "/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Final file path
    // eg.. 101_NEPInitiatives_doc1.pdf
    $newFileName = $dept . "_" . $particulars . "_doc" . $srno . "_" . $timestamp1 . ".pdf";
    $filePath = $uploadDir . $newFileName;

    // Move uploaded file
    if (!move_uploaded_file($fileTmp, $filePath)) {
        die("Error saving uploaded file.");
    }

    // Save to database
    // Example table: nep_documents(dept_id, particulars, srno, file_path, uploaded_on)
    $filePathDB = mysqli_real_escape_string($conn, $filePath);
    $sql = "INSERT INTO nep_documents (A_YEAR, dept_id, particulars, srno, file_path, uploaded_on)
            VALUES ('$A_YEAR', '$dept', '$particulars_id', '$srno', '$filePathDB', '$timestamp')
            ON DUPLICATE KEY UPDATE 
            A_YEAR='$A_YEAR',
            file_path='$filePathDB', 
            uploaded_on='$timestamp'";

    if (mysqli_query($conn, $sql)) {
        echo "Saved Successfully";
    } else {
        echo "Database Error: " . mysqli_error($conn);
    }
}
?>
