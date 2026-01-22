<?php
/**
 * API: Send Credentials to Verification Committee User
 * Uses same logic as admin/sendmail.php
 */
header('Content-Type: application/json');
error_reporting(0); // prevent warnings from breaking JSON

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Simple session check for admin - don't use session.php as it has die() statements that break JSON responses
if (!isset($_SESSION['admin_username']) && !isset($_SESSION['user_permission'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please login again.']);
    exit;
}

// Check admin permission
$has_admin_permission = false;
if (isset($_SESSION['admin_username'])) {
    $has_admin_permission = true;
}
if (isset($_SESSION['user_permission'])) {
    $permission = strtolower(trim($_SESSION['user_permission']));
    $has_admin_permission = (
        $permission === 'admin' || 
        $permission === 'administrator' || 
        $permission === 'adm' ||
        $permission === 'boss' ||
        stripos($permission, 'admin') !== false
    );
}
if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
    $has_admin_permission = true;
}

if (!$has_admin_permission) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Admin permission required.']);
    exit;
}

require('../../config.php');

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$email = isset($_POST['email']) ? trim($_POST['email']) : '';

if (empty($_POST['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No User ID provided']);
    exit;
}
$user_id = intval($_POST['user_id']);

// Fetch user details securely (same pattern as sendmail.php)
$query = "SELECT EMAIL, PASS_WORD, ROLE, NAME FROM verification_committee_users WHERE id = ? LIMIT 1";

if (!$stmt = $conn->prepare($query)) {
    echo json_encode(['status' => 'error', 'message' => 'Database prepare error: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}
$user = $result->fetch_assoc();
$user_email = $user['EMAIL'];
$password = $user['PASS_WORD'];
$role = $user['ROLE'];
$name = $user['NAME'] ?? 'Verification Committee Member';

// Load PHPMailer
require __DIR__ . '/../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

// Use fixed unified login link for all credential emails
$login_url = 'https://uomdcs.univofmumbai.in/unified_login.php';

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
    $mail->addAddress($user_email, $name);

    // Email content
    $mail->isHTML(true);
    $mail->Subject = "Login Credentials for UoM Verification Committee Portal";
    $mail->Body = "
    <div style='font-family: \"Times New Roman\", Times, serif; font-size:14px; color:#000;'>
        <p>Respected " . htmlspecialchars($name) . ",</p>
        <p>The login details for <b>UoM Verification Committee Portal</b> are as follows:</p>
        <p>
        <b>Role:</b> " . htmlspecialchars($role) . "<br>
        <b>Email:</b> " . htmlspecialchars($user_email) . "
        </p>
        <p>Kindly use the latest version of Google Chrome or Mozilla Firefox for the whole process.</p>
        <p>1. Visit <a href='" . htmlspecialchars($login_url) . "'>" . htmlspecialchars($login_url) . "</a> for login.</p>
        <p>2. <b>Use the following credentials:</b><br>
        Email: " . htmlspecialchars($user_email) . " <br>
        Password: " . htmlspecialchars($password) . "</p>
        <hr>
        <p style='color:#555;font-size:12px;'>[Do not reply to this email. This is an auto-generated email.]</p>
    </div>";

    $mail->send();

    // Update SEND_CRED flag (same pattern as sendmail.php)
    $updateQuery = "UPDATE verification_committee_users SET SEND_CRED = 1 WHERE id = ?";
    if ($updateStmt = $conn->prepare($updateQuery)) {
        $updateStmt->bind_param("i", $user_id);
        $updateStmt->execute();
    }

    echo json_encode(['status' => 'success', 'message' => "Credentials sent successfully to " . htmlspecialchars($user_email)]);
    exit;
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => "Mailer Error: " . $e->getMessage()]);
    exit;
}
