<?php
// admin/sendmail.php - Send Mail Handler
require('session.php');

header('Content-Type: application/json');
error_reporting(0); // prevent warnings from breaking JSON

// Load composer autoload (PHPMailer + vlucas/phpdotenv)
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Validate deptId
if (empty($_POST['deptId'])) {
    echo json_encode(['status' => 'error', 'message' => 'No Department ID provided']);
    exit;
}
$deptId = intval($_POST['deptId']);

// Fetch department details securely
$query = "SELECT a.DEPT_ID, a.DEPT_COLL_NO, b.collno, a.DEPT_NAME, a.EMAIL, a.PASS_WORD, b.collname
          FROM department_master a
          JOIN colleges b ON b.collno = a.DEPT_COLL_NO
          WHERE a.DEPT_ID = ? LIMIT 1";

if (!$stmt = $conn->prepare($query)) {
    echo json_encode(['status' => 'error', 'message' => 'Database prepare error: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $deptId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Department not found']);
    exit;
}
$dept = $result->fetch_assoc();
$email        = $dept['EMAIL'];
$password     = $dept['PASS_WORD'];
$deptCollNo   = $dept['DEPT_COLL_NO'];
$deptCollName = $dept['collname'];
$deptName     = $dept['DEPT_NAME'];

// ---------------------- PHPMailer ----------------------
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    // SMTP config
    $mail->isSMTP();
    $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['SMTP_USER'] ?? '';
    $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);

    // Sender & Recipient
    $mail->setFrom($_ENV['SMTP_FROM'] ?? 'no-reply@example.com', $_ENV['SMTP_FROM_NAME'] ?? 'UoM Portal');
    $mail->addAddress($email, $deptName);

    // Email content
    $mail->isHTML(true);
    $mail->Subject = "Login Credentials for UoM Centralized DCS Ranking Portal process";
    $mail->Body = "
    <div style='font-family: \"Times New Roman\", Times, serif; font-size:14px; color:#000;'>
        <p>Respected Sir/Madam,</p>
        <p>The login details for <b>UoM Centralized DCS Ranking Portal</b> are as follows:</p>
        <p>
        <b>Department Code:</b> {$deptCollNo} <br>
        <b>Department Name:</b> {$deptCollName}
        </p>
        <p>Kindly use the latest version of Google Chrome or Mozilla Firefox for the whole process.</p>
        <p>1. Visit <a href='{$port_link}/index.php'>{$port_link}/index.php</a> for login.</p>
        <p>2. <b>Use the following credentials:</b><br>
        Email: {$email} <br>
        Password: {$password}</p>
        <hr>
        <p style='color:#555;font-size:12px;'>[Do not reply to this email. This is an auto-generated email.]</p>
    </div>";

    $mail->send();

    // Update SEND_CRED flag
    $updateQuery = "UPDATE department_master SET SEND_CRED = 1 WHERE DEPT_ID = ?";
    if ($updateStmt = $conn->prepare($updateQuery)) {
        $updateStmt->bind_param("i", $deptId);
        $updateStmt->execute();
    }

    echo json_encode(['status' => 'success', 'message' => "Credentials sent successfully to $email"]);
    exit;
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => "Mailer Error: " . $e->getMessage()]);
    exit;
}
