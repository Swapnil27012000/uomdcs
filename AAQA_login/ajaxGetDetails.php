<?php
/**
 * AAQA - AJAX Get Details Handler
 * Based on admin/ajaxGetDetails.php with category support
 */

require('session.php');
require_once(__DIR__ . '/../config.php');

ini_set('display_errors', 0);
error_reporting(0);

// Generate random password
function generatePassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

// ---------- Handle Update / Insert ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_btn'])) {
    // Clear all output buffers before JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Set headers
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    
    // Validate CSRF token
    if (file_exists(__DIR__ . '/../dept_login/csrf.php')) {
        require_once __DIR__ . '/../dept_login/csrf.php';
        if (function_exists('validate_csrf')) {
            $csrf_token = $_POST['csrf_token'] ?? '';
            if (empty($csrf_token) || !validate_csrf($csrf_token)) {
                echo json_encode(['status' => 'error', 'msg' => 'Security token validation failed. Please refresh the page and try again.']);
                exit;
            }
        }
    }
    
    // Validate and sanitize input
    $deptCollNo = trim($_POST['dept_collno'] ?? '');
    $deptName = trim($_POST['dept_name'] ?? '');
    $deptEmail = trim($_POST['dept_email'] ?? '');
    $hodEmail = trim($_POST['hod_email'] ?? '');
    $hodName = trim($_POST['hod_name'] ?? '');
    $hodAddress = trim($_POST['hod_address'] ?? '');
    $category = trim($_POST['category'] ?? '');
    
    // Input validation
    if (empty($deptCollNo) || empty($deptName) || empty($deptEmail)) {
        echo json_encode(['status' => 'error', 'msg' => '⚠️ Department Code, Name, and Email are required.']);
        exit;
    }
    
    // Validate email format
    if (!filter_var($deptEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'msg' => '⚠️ Invalid department email format.']);
        exit;
    }
    
    if (!empty($hodEmail) && !filter_var($hodEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'msg' => '⚠️ Invalid HOD email format.']);
        exit;
    }
    
    // Build response in variable
    $response = ['status' => 'error', 'msg' => 'Unknown error'];
    
    try {
        // Check if department exists
        $checkQuery = "SELECT DEPT_ID FROM department_master WHERE DEPT_COLL_NO = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkQuery);
        if (!$checkStmt) {
            throw new Exception('Database prepare error');
        }
        $checkStmt->bind_param("s", $deptCollNo);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult && $checkResult->num_rows > 0) {
            // Update existing department
            $deptRow = $checkResult->fetch_assoc();
            $deptId = (int)$deptRow['DEPT_ID'];
            mysqli_free_result($checkResult);
            $checkStmt->close();
            
            // Get academic year for category update
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            $academic_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
            
            // Update department_master
            $updateQuery = "UPDATE department_master t1
                            JOIN colleges t2 ON t1.DEPT_COLL_NO = t2.collno
                            JOIN departmentnames t3 ON t2.collno = t3.collno
                            SET 
                                t1.EMAIL = ?,
                                t1.HOD_EMAIL = ?,
                                t1.SEND_CRED = 0,
                                t1.DEPT_NAME = ?,
                                t1.HOD_NAME = ?,
                                t1.ADDRESS = ?,
                                t2.collname = ?,
                                t3.collname = ?
                            WHERE t1.DEPT_COLL_NO = ?";
            $stmt = $conn->prepare($updateQuery);
            if (!$stmt) {
                throw new Exception('Database prepare error');
            }
            $stmt->bind_param("ssssssss", $deptEmail, $hodEmail, $deptName, $hodName, $hodAddress, $deptName, $deptName, $deptCollNo);
            
            if ($stmt->execute()) {
                // Update category in department_profiles if provided
                if (!empty($category)) {
                    // Check if profile exists
                    $profileCheckQuery = "SELECT id FROM department_profiles 
                                         WHERE (dept_id = ? OR dept_id = ?) AND A_YEAR = ? LIMIT 1";
                    $profileCheckStmt = $conn->prepare($profileCheckQuery);
                    if ($profileCheckStmt) {
                        $deptIdStr = (string)$deptId;
                        $profileCheckStmt->bind_param("sss", $deptIdStr, $deptCollNo, $academic_year);
                        $profileCheckStmt->execute();
                        $profileCheckResult = $profileCheckStmt->get_result();
                        
                        if ($profileCheckResult && $profileCheckResult->num_rows > 0) {
                            // Update existing profile
                            $profileUpdateQuery = "UPDATE department_profiles SET category = ? 
                                                  WHERE (dept_id = ? OR dept_id = ?) AND A_YEAR = ?";
                            $profileUpdateStmt = $conn->prepare($profileUpdateQuery);
                            if ($profileUpdateStmt) {
                                $deptIdStr = (string)$deptId;
                                $profileUpdateStmt->bind_param("ssss", $category, $deptIdStr, $deptCollNo, $academic_year);
                                $profileUpdateStmt->execute();
                                $profileUpdateStmt->close();
                            }
                        } else {
                            // Insert new profile
                            $profileInsertQuery = "INSERT INTO department_profiles (dept_id, A_YEAR, category) VALUES (?, ?, ?)";
                            $profileInsertStmt = $conn->prepare($profileInsertQuery);
                            if ($profileInsertStmt) {
                                $profileInsertStmt->bind_param("sss", $deptCollNo, $academic_year, $category);
                                $profileInsertStmt->execute();
                                $profileInsertStmt->close();
                            }
                        }
                        
                        if ($profileCheckResult) {
                            mysqli_free_result($profileCheckResult);
                        }
                        $profileCheckStmt->close();
                    }
                }
                
                $stmt->close();
                $response = ['status' => 'success', 'msg' => '✅ Department details updated successfully!', 'action' => 'update'];
            } else {
                $stmt->close();
                throw new Exception('Error updating record');
            }
        } else {
            // Insert new department
            if ($checkResult) {
                mysqli_free_result($checkResult);
            }
            $checkStmt->close();
            
            $plainPassword = generatePassword(10);
            $insertQuery = "INSERT INTO department_master 
                (DEPT_COLL_NO, DEPT_NAME, EMAIL, HOD_EMAIL, HOD_NAME, ADDRESS, PASS_WORD, SEND_CRED) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)";
            $stmt = $conn->prepare($insertQuery);
            if (!$stmt) {
                throw new Exception('Database prepare error');
            }
            $stmt->bind_param("sssssss", $deptCollNo, $deptName, $deptEmail, $hodEmail, $hodName, $hodAddress, $plainPassword);
            
            if ($stmt->execute()) {
                // Get the inserted DEPT_ID
                $newDeptId = (int)$stmt->insert_id;
                $stmt->close();
                
                // Insert category in department_profiles if provided
                if (!empty($category)) {
                    $current_year = (int)date('Y');
                    $current_month = (int)date('n');
                    $academic_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
                    
                    $profileInsertQuery = "INSERT INTO department_profiles (dept_id, A_YEAR, category) VALUES (?, ?, ?)";
                    $profileInsertStmt = $conn->prepare($profileInsertQuery);
                    if ($profileInsertStmt) {
                        $profileInsertStmt->bind_param("sss", $deptCollNo, $academic_year, $category);
                        $profileInsertStmt->execute();
                        $profileInsertStmt->close();
                    }
                }
                
                $response = [
                    'status' => 'success',
                    'msg' => '✅ New department added successfully!',
                    'action' => 'insert',
                    'generated_password' => $plainPassword
                ];
            } else {
                $stmt->close();
                throw new Exception('Error inserting record');
            }
        }
    } catch (Exception $e) {
        error_log("AAQA ajaxGetDetails update error: " . $e->getMessage());
        $response = ['status' => 'error', 'msg' => '❌ Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
    }
    
    // Clear buffers and output JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Display Form ----------
$department_collno = $_POST['collno'] ?? '';

if (!empty($department_collno)) {
    // Get academic year
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    $academic_year = ($current_month >= 6) ? ($current_year - 1) . '-' . $current_year : ($current_year - 1) . '-' . $current_year;
    
    // Simple query like admin version
    $query = "SELECT a.DEPT_ID, a.DEPT_COLL_NO, b.collno, a.DEPT_NAME, a.EMAIL, a.HOD_EMAIL, a.HOD_NAME, a.ADDRESS, b.collname 
              FROM department_master a 
              JOIN colleges b ON b.collno = a.DEPT_COLL_NO 
              WHERE a.DEPT_COLL_NO = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo '<div class="alert alert-danger">Database error. Please try again.</div>';
        exit;
    }
    $stmt->bind_param("s", $department_collno);
    if (!$stmt->execute()) {
        $stmt->close();
        echo '<div class="alert alert-danger">Query error. Please try again.</div>';
        exit;
    }
    $result = $stmt->get_result();
    
    // --- CSS for Responsive Modern Form ---
    ?>
    <style>
        .form-wrapper {
            max-width: 600px;
            margin: 30px auto;
            padding: 20px 25px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
        }
        .form-wrapper h3, .form-wrapper h4 {
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #333;
        }
        input[type=text],
        input[type=email],
        textarea,
        select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }
        textarea {
            resize: vertical;
        }
        .btn-submit {
            width: 100%;
            background-color: #007bff;
            color: #fff;
            border: none;
            padding: 10px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-submit:hover {
            background-color: #0056b3;
        }
        @media (min-width: 600px) {
            .form-row {
                display: flex;
                gap: 15px;
            }
            .form-row .form-group {
                flex: 1;
            }
        }
    </style>
    <?php

    if ($result && $result->num_rows > 0) {
        $info = $result->fetch_assoc();
        mysqli_free_result($result);
        $stmt->close();
        
        // Get category separately
        $category = null;
        if (!empty($info['DEPT_ID'])) {
            $deptId = (int)$info['DEPT_ID'];
            $deptCollNo = $info['DEPT_COLL_NO'] ?? '';
            
            $categoryQuery = "SELECT category FROM department_profiles 
                             WHERE (dept_id = ? OR dept_id = ?) AND A_YEAR = ? LIMIT 1";
            $categoryStmt = $conn->prepare($categoryQuery);
            if ($categoryStmt) {
                $deptIdStr = (string)$deptId;
                $categoryStmt->bind_param("sss", $deptIdStr, $deptCollNo, $academic_year);
                $categoryStmt->execute();
                $categoryResult = $categoryStmt->get_result();
                if ($categoryResult && $categoryResult->num_rows > 0) {
                    $categoryRow = $categoryResult->fetch_assoc();
                    $category = $categoryRow['category'] ?? null;
                }
                if ($categoryResult) {
                    mysqli_free_result($categoryResult);
                }
                $categoryStmt->close();
            }
        }
        
        // Get categories list
        $categories_query = "SELECT department_id, department_name FROM departments ORDER BY department_id ASC";
        $categories_stmt = $conn->prepare($categories_query);
        $categories = [];
        if ($categories_stmt) {
            $categories_stmt->execute();
            $categories_result = $categories_stmt->get_result();
            if ($categories_result) {
                while ($cat_row = $categories_result->fetch_assoc()) {
                    $categories[] = $cat_row;
                }
                mysqli_free_result($categories_result);
            }
            $categories_stmt->close();
        }
        ?>
        <div class="form-wrapper">
            <h4><i class="fas fa-edit me-2"></i>Update Department Details</h4>
            <div id="updateMsg"></div>
            <form id="updateForm">
                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                
                <div class="form-group">
                    <label>Department Code:</label>
                    <input type="text" value="<?php echo htmlspecialchars($info['collno'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    <input type="hidden" name="dept_collno" value="<?php echo htmlspecialchars($info['collno'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-group">
                    <label>Department Name:</label>
                    <input type="text" name="dept_name" value="<?php echo htmlspecialchars($info['DEPT_NAME'] ?? $info['collname'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-list-alt me-2"></i>Category</label>
                    <select name="category" class="form-control">
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): 
                            $selected = (($category ?? '') == $cat['department_name']) ? 'selected' : '';
                        ?>
                            <option value="<?php echo htmlspecialchars($cat['department_name'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($cat['department_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Department Email:</label>
                        <input type="email" name="dept_email" value="<?php echo htmlspecialchars($info['EMAIL'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group">
                        <label>HOD Email:</label>
                        <input type="email" name="hod_email" value="<?php echo htmlspecialchars($info['HOD_EMAIL'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>HOD Name:</label>
                    <input type="text" name="hod_name" value="<?php echo htmlspecialchars($info['HOD_NAME'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-group">
                    <label>Department Address:</label>
                    <textarea name="hod_address" rows="3"><?php echo htmlspecialchars($info['ADDRESS'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <button type="submit" name="update_btn" class="btn-submit">Save Details</button>
            </form>
        </div>
        <?php
    } else {
        // No record found - close statement
        if ($result) {
            mysqli_free_result($result);
        }
        $stmt->close();
        
        // Get college name
        $collegeQuery = "SELECT collname FROM colleges WHERE collno = ? LIMIT 1";
        $collegeStmt = $conn->prepare($collegeQuery);
        $collegeName = '';
        if ($collegeStmt) {
            $collegeStmt->bind_param("s", $department_collno);
            $collegeStmt->execute();
            $collegeResult = $collegeStmt->get_result();
            if ($collegeResult && $collegeResult->num_rows > 0) {
                $collegeInfo = $collegeResult->fetch_assoc();
                $collegeName = $collegeInfo['collname'] ?? '';
            }
            if ($collegeResult) {
                mysqli_free_result($collegeResult);
            }
            $collegeStmt->close();
        }
        
        // Get categories list
        $categories_query = "SELECT department_id, department_name FROM departments ORDER BY department_id ASC";
        $categories_stmt = $conn->prepare($categories_query);
        $categories = [];
        if ($categories_stmt) {
            $categories_stmt->execute();
            $categories_result = $categories_stmt->get_result();
            if ($categories_result) {
                while ($cat_row = $categories_result->fetch_assoc()) {
                    $categories[] = $cat_row;
                }
                mysqli_free_result($categories_result);
            }
            $categories_stmt->close();
        }
        ?>
        <p>No record found. You can add a department Details.</p>
        <div class="form-wrapper">
            <h4><i class="fas fa-plus-circle me-2"></i>Add New Department</h4>
            <div id="updateMsg"></div>
            <form id="updateForm">
                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                
                <div class="form-group">
                    <label>Department Code:</label>
                    <input type="text" value="<?php echo htmlspecialchars($department_collno, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    <input type="hidden" name="dept_collno" value="<?php echo htmlspecialchars($department_collno, ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-group">
                    <label>Department Name:</label>
                    <input type="text" name="dept_name" value="<?php echo htmlspecialchars($collegeName, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-list-alt me-2"></i>Category</label>
                    <select name="category" class="form-control" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['department_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($cat['department_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Department Email:</label>
                        <input type="email" name="dept_email" placeholder="Enter Department Email" required>
                    </div>
                    <div class="form-group">
                        <label>HOD Email:</label>
                        <input type="email" name="hod_email" placeholder="Enter HOD Email">
                    </div>
                </div>

                <div class="form-group">
                    <label>HOD Name:</label>
                    <input type="text" name="hod_name" placeholder="Enter HOD Name">
                </div>

                <div class="form-group">
                    <label>Department Address:</label>
                    <textarea name="hod_address" rows="3" placeholder="Enter Department Address"></textarea>
                </div>

                <button type="submit" name="update_btn" class="btn-submit">Add Department</button>
            </form>
        </div>
        <?php
    }
}
?>
