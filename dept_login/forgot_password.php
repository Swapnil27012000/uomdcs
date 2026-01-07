<?php
session_start();
error_reporting(0);

require __DIR__ . '/vendor/autoload.php';
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php';
}

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

if (isset($_POST['submit'])) {
    $email = trim($_POST['email']);

    // Check if email exists
    $stmt = $conn->prepare("SELECT * FROM department_master WHERE EMAIL = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        // Generate secure token and expiry
        $token = bin2hex(random_bytes(32));
        $exp_time = date("Y-m-d H:i:s", strtotime('+1 hour'));

        // Save token to database
        // $update_stmt = $conn->prepare("UPDATE department_master SET RESET_TOKEN = ?, RESET_EXP = ? WHERE EMAIL = ?");
        $update_stmt = $conn->prepare("UPDATE department_master SET RESET_PASSWORD = ?, RESET_TOKEN_EXPIRE = ? WHERE EMAIL = ?");
        $update_stmt->bind_param("sss", $token, $exp_time, $email);
        $update_stmt->execute();

        // Reset link
        $reset_link = $port_link . "/" . "ResetPassword.php?token=" . urlencode($token);

        try {
            // SMTP Config
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'];
            $mail->Password   = $_ENV['SMTP_PASS'];
            $mail->SMTPSecure = 'tls';
            $mail->Port       = $_ENV['SMTP_PORT'];

            // Sender & Recipient
            $mail->setFrom($_ENV['SMTP_FROM'], $_ENV['SMTP_FROM_NAME']);
            $mail->addAddress($email);

            // Content
            $mail->isHTML(true);
            $mail->Subject = "Password Reset Request";
            $mail->Body    = "
                <p>Dear User,</p>
                <p>We received a request to reset your password for the <b>UoM Centralized DCS Ranking Portal</b>.</p>
                <p>Please click the link below to reset your password (valid for 1 hour):</p>
                <p><a href='$reset_link'>$reset_link</a></p>
                <p>If you did not request this, you can safely ignore this email.</p>
                <br>
                <p>Regards,<br>University of Mumbai</p>
            ";

            $mail->send();
            echo "<script>alert('Password reset link has been sent to your email.'); window.location='index.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('Email could not be sent. Mailer Error: {$mail->ErrorInfo}');</script>";
        }
    } else {
        echo "<script>alert('Email not found.');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" type="text/css" href="./assets/css/style.css">
    <link rel="icon" href="assets/img/mumbai-university-removebg-preview.png" type="image/png">
</head>
<body>
<nav class="navbar">
    <div class="items">
        <img src="assets/img/mumbai-university-removebg-preview.png" alt="image" height="100px">
        <h1>University Of Mumbai</h1>
        <img src="assets/img/nirf-full-removebg-preview.png" alt="image" height="90px">
    </div>
</nav>

<div class="main">
    <div class="container">
        <form method="POST" action="" class="login-email">
            <p class="login-text" style="font-size: 2rem; font-weight: 800;">Forgot Password</p>
            <div class="input-group">
                <input type="email" name="email" placeholder="Enter your registered email" required>
            </div>
            <div class="input-group">
                <button type="submit" name="submit" class="btn">Send Reset Link</button>
            </div>
            <div class="input-group">                     
                <a href="index.php" class="nodal-officer" style="text-align: center; text-decoration: none;">Back to Login</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
