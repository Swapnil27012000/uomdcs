<?php
session_start();
header('Content-Type: application/json');
require __DIR__ . '/vendor/autoload.php';
include 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

function sendOTP($email, $otp)
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'];
        $mail->Password   = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom($_ENV['SMTP_USER'], 'MU NIRF Portal');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Resend OTP for MU NIRF Portal Login';
        $mail->Body    = "
        <p>Dear User,</p>
        <p>Your new One-Time Password (OTP) for login is: <b>$otp</b></p>
        <p>This OTP is valid for the next <b>5 minutes</b>.</p>
        <p>If you didn’t request this, please ignore this email.</p>
        <br>
        <p>Best regards,<br>MU NIRF Portal Team</p>
    ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Ensure email is available
if (!isset($_SESSION['pending_email'])) {
    echo json_encode(["success" => false, "message" => "Session expired. Please login again."]);
    exit;
}

$email = $_SESSION['pending_email'];

// Generate new OTP
$otp = rand(100000, 999999);
$expiry = date('Y-m-d H:i:s', time() + 300); // 5 minutes from now

// Check which table the user belongs to
$table = $_SESSION['pending_table'] ?? '';
if (empty($table)) {
    echo json_encode(["success" => false, "message" => "Session expired. Please login again."]);
    exit;
}

// Update OTP in the appropriate table
if ($table === 'department_master') {
    $stmt = $conn->prepare("UPDATE department_master SET otp = ?, otp_expiry = ? WHERE EMAIL = ?");
} else {
    $stmt = $conn->prepare("UPDATE boss SET otp = ?, otp_expiry = ? WHERE EMAIL = ?");
}

if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Database error. Please try again."]);
    exit;
}

$stmt->bind_param("sss", $otp, $expiry, $email);
$updateSuccess = $stmt->execute();
$stmt->close();

if ($updateSuccess && sendOTP($email, $otp)) {
    echo json_encode(["success" => true, "message" => "A new OTP has been sent to your email."]);
} else {
    // If either database update or email sending fails, try to rollback the OTP
    if ($updateSuccess) {
        if ($table === 'department_master') {
            $stmt = $conn->prepare("UPDATE department_master SET otp = NULL, otp_expiry = NULL WHERE EMAIL = ?");
        } else {
            $stmt = $conn->prepare("UPDATE boss SET otp = NULL, otp_expiry = NULL WHERE EMAIL = ?");
        }
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(["success" => false, "message" => "Failed to resend OTP. Please try again later."]);
}
