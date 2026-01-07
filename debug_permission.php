<?php
// debug_permission.php - Check user permission in database
session_start();
require('config.php');

// Get email from POST or GET
$email = $_POST['email'] ?? $_GET['email'] ?? '';

if (empty($email)) {
    die("Please provide email parameter: ?email=your@email.com");
}

echo "<h2>Permission Debug for: " . htmlspecialchars($email) . "</h2>";

// Check boss table
$stmt = $conn->prepare("SELECT EMAIL, PERMISSION FROM boss WHERE BINARY EMAIL = ?");
if ($stmt) {
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        echo "<h3>Found in 'boss' table:</h3>";
        echo "<pre>";
        echo "EMAIL: " . htmlspecialchars($row['EMAIL']) . "\n";
        echo "PERMISSION (raw): [" . htmlspecialchars($row['PERMISSION']) . "]\n";
        echo "PERMISSION (length): " . strlen($row['PERMISSION']) . "\n";
        echo "PERMISSION (lowercase): [" . strtolower(trim($row['PERMISSION'])) . "]\n";
        echo "PERMISSION (hex): " . bin2hex($row['PERMISSION']) . "\n";
        echo "</pre>";
        
        $permission_lower = strtolower(trim($row['PERMISSION']));
        $permission_check = $row['PERMISSION'] . '|' . $permission_lower;
        $has_aaqa = stripos($permission_check, 'aaqa') !== false;
        
        echo "<h3>Permission Check Results:</h3>";
        echo "<pre>";
        echo "Contains 'aaqa' (case-insensitive): " . ($has_aaqa ? 'YES' : 'NO') . "\n";
        echo "Exact match 'aaqa': " . ($permission_lower === 'aaqa' ? 'YES' : 'NO') . "\n";
        echo "Exact match 'aaqa_login': " . ($permission_lower === 'aaqa_login' ? 'YES' : 'NO') . "\n";
        echo "</pre>";
    } else {
        echo "<p>Not found in 'boss' table.</p>";
    }
    $stmt->close();
}

// Check department_master table
$stmt = $conn->prepare("SELECT EMAIL, PERMISSION FROM department_master WHERE BINARY EMAIL = ?");
if ($stmt) {
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        echo "<h3>Found in 'department_master' table:</h3>";
        echo "<pre>";
        echo "EMAIL: " . htmlspecialchars($row['EMAIL']) . "\n";
        echo "PERMISSION (raw): [" . htmlspecialchars($row['PERMISSION'] ?? 'NULL') . "]\n";
        echo "</pre>";
    } else {
        echo "<p>Not found in 'department_master' table.</p>";
    }
    $stmt->close();
}

$conn->close();
?>

