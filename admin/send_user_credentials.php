<?php
// admin/send_user_credentials.php - Send Credentials to Experts, AAQA, Chairman, etc.
require('session.php');

header('Content-Type: application/json');
error_reporting(0); // prevent warnings from breaking JSON

// Load composer autoload (PHPMailer + vlucas/phpdotenv)
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Use fixed unified login link for all credential emails
$login_url = 'https://uomdcs.univofmumbai.in/unified_login.php';

// Validate userId
if (empty($_POST['userId'])) {
    echo json_encode(['status' => 'error', 'message' => 'No User ID provided']);
    exit;
}
$userId = intval($_POST['userId']);
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$permission = isset($_POST['permission']) ? trim($_POST['permission']) : '';

// Fetch user details securely from boss table
$query = "SELECT Id, EMAIL, PASS_WORD, PERMISSION FROM boss WHERE Id = ? LIMIT 1";

if (!$stmt = $conn->prepare($query)) {
    echo json_encode(['status' => 'error', 'message' => 'Database prepare error: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}
$user = $result->fetch_assoc();
$user_email = $user['EMAIL'];
$password = $user['PASS_WORD'];
$user_permission = $user['PERMISSION'];

// Determine role name and portal name based on permission
// All users will use the unified login page
$role_name = '';
$portal_name = '';

switch (strtolower($user_permission)) {
    case 'expert_comty_login':
        $role_name = 'Expert Committee Member';
        $portal_name = 'UoM Expert Committee Portal';
        break;
    case 'aaqa_login':
        $role_name = 'AAQA Member';
        $portal_name = 'UoM AAQA Portal';
        break;
    case 'chairman_login':
        $role_name = 'Chairman';
        $portal_name = 'UoM Chairman Review Portal';
        break;
    case 'verification_committee':
        $role_name = 'Verification Committee Member';
        $portal_name = 'UoM Verification Committee Portal';
        break;
    default:
        $role_name = 'User';
        $portal_name = 'UoM Centralized DCS Ranking Portal';
        break;
}

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
    $mail->addAddress($user_email, $role_name);

    // Email content
    $mail->isHTML(true);
    $mail->Subject = "Login Credentials for " . $portal_name;
    $mail->Body = "
    <div style='font-family: \"Times New Roman\", Times, serif; font-size:14px; color:#000;'>
        <p>Respected Sir/Madam,</p>
        <p>The login details for <b>" . htmlspecialchars($portal_name) . "</b> are as follows:</p>
        <p>
        <b>Role:</b> " . htmlspecialchars($role_name) . " <br>
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

    // Update SEND_CRED flag in boss table (add column if it doesn't exist)
    // First check if column exists
    $check_col = $conn->query("SHOW COLUMNS FROM boss LIKE 'SEND_CRED'");
    if (!$check_col || $check_col->num_rows == 0) {
        // Add column if it doesn't exist
        $conn->query("ALTER TABLE boss ADD COLUMN SEND_CRED TINYINT(1) DEFAULT 0");
    }
    if ($check_col) {
        mysqli_free_result($check_col);
    }

    // Update SEND_CRED flag
    $updateQuery = "UPDATE boss SET SEND_CRED = 1 WHERE Id = ?";
    if ($updateStmt = $conn->prepare($updateQuery)) {
        $updateStmt->bind_param("i", $userId);
        $updateStmt->execute();
        $updateStmt->close();
    }

    echo json_encode(['status' => 'success', 'message' => "Credentials sent successfully to " . htmlspecialchars($user_email)]);
    exit;
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => "Mailer Error: " . $e->getMessage()]);
    exit;
}
$stmt->close();
?>
