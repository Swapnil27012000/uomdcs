<?php
session_start();
header('Content-Type: application/json');
require __DIR__ . '/vendor/autoload.php';
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

function sendOTP($email, $otp) {
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
        $mail->Subject = 'Your New OTP for MU NIRF Portal Login';
        $mail->Body    = "Your new OTP is: <b>$otp</b>";

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
$_SESSION['login_otp'] = $otp;
$_SESSION['otp_expiry'] = time() + 300; // 5 minutes

if (sendOTP($email, $otp)) {
    echo json_encode(["success" => true, "message" => "A new OTP has been sent to your email."]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to resend OTP. Try again later."]);
}
