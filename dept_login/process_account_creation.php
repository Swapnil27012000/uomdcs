<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and collect form data
    $email = $_POST['email'];
    $dept_name = $_POST['dept_name'];
    $hod_name = $_POST['hod_name'];
    $address = $_POST['address'];
    $password = $_POST['password'];  // Store the password (this should be hashed)
    $permission = $_POST['permission'];  // 'user' (fixed value)

    // CRITICAL: Use config.php for database connection - prevent multiple connections
    // Only require config if connection doesn't exist
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    
    // Verify connection is available
    if (!isset($conn) || !$conn) {
        die('Database connection failed');
    }

    // Check if email already exists in the department_master table
    $stmt = $conn->prepare("SELECT email FROM department_master WHERE EMAIL = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // Email already exists, return an error
        echo "<script>alert('Account already exists with this email.'); window.location.href = 'create_account.php';</script>";
    } else {
        // Insert the new user into the department_master table
        $stmt = $conn->prepare("INSERT INTO department_master (PERMISSION, EMAIL, DEPT_NAME, HOD_NAME, ADDRESS, PASS_WORD) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $permission, $email, $dept_name, $hod_name, $address, $password);

        if ($stmt->execute()) {
            // Mark the token as used and delete it from the user_invitations table
            $delete_stmt = $conn->prepare("UPDATE user_invitationss SET used = 1 WHERE token = ?");
            $delete_stmt->bind_param("s", $_GET['token']);
            $delete_stmt->execute();

            echo "<script>alert('Account successfully created for $email.'); window.location.href = 'dashboard.php';</script>";
        } else {
            echo "<script>alert('Failed to create the account.'); window.location.href = 'create_account.php';</script>";
        }
    }

    // Close statement
    $stmt->close();
    // DO NOT close persistent connections - they are reused
    // $conn->close(); // REMOVED - persistent connections stay open
}
?>
