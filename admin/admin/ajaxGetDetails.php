<?php
// admin/ajaxGetDetails.php - AJAX Get Details Handler
require('session.php');

ini_set('display_errors', 0);
error_reporting(0);

// Generate random password
function generatePassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

// ---------- Handle Update / Insert ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_btn'])) {
    $deptCollNo = $_POST['dept_collno'] ?? '';
    $deptName   = $_POST['dept_name'] ?? '';
    $deptEmail  = $_POST['dept_email'] ?? '';
    $hodEmail   = $_POST['hod_email'] ?? '';
    $hodName    = $_POST['hod_name'] ?? '';
    $hodAddress = $_POST['hod_address'] ?? '';

    header('Content-Type: application/json; charset=utf-8');

    if (!empty($deptCollNo) && !empty($deptName) && !empty($deptEmail)) {
        $checkQuery = "SELECT DEPT_ID FROM department_master WHERE DEPT_COLL_NO = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("s", $deptCollNo);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult && $checkResult->num_rows > 0) {
            // Update existing
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
            $stmt->bind_param("ssssssss", $deptEmail, $hodEmail, $deptName, $hodName, $hodAddress, $deptName, $deptName, $deptCollNo);

            if ($stmt->execute()) {
                echo json_encode(["status" => "success", "msg" => "✅ Department details updated successfully!", "action" => "update"]);
            } else {
                echo json_encode(["status" => "error", "msg" => "❌ Error updating record."]);
            }
            $stmt->close();
        } else {
            // Insert new
            $plainPassword = generatePassword(10);
            $insertQuery = "INSERT INTO department_master 
                (DEPT_COLL_NO, DEPT_NAME, EMAIL,HOD_EMAIL, HOD_NAME, ADDRESS, PASS_WORD, SEND_CRED) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)";
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param("sssssss", $deptCollNo, $deptName, $deptEmail, $hodEmail, $hodName, $hodAddress, $plainPassword);

            if ($stmt->execute()) {
                echo json_encode([
                    "status" => "success",
                    "msg" => "✅ New department added successfully!",
                    "action" => "insert",
                    "generated_password" => $plainPassword
                ]);
            } else {
                echo json_encode(["status" => "error", "msg" => "❌ Error inserting record."]);
            }
            $stmt->close();
        }
        $checkStmt->close();
    } else {
        echo json_encode(["status" => "error", "msg" => "⚠️ All fields are required."]);
    }
    exit;
}

// ---------- Display Form ----------
$department_collno = $_POST['collno'] ?? '';

if (!empty($department_collno)) {
    $query = "SELECT a.DEPT_ID, a.DEPT_COLL_NO, b.collno, a.DEPT_NAME, a.EMAIL, a.HOD_EMAIL, a.HOD_NAME, a.ADDRESS, b.collname 
              FROM department_master a 
              JOIN colleges b ON b.collno = a.DEPT_COLL_NO 
              WHERE a.DEPT_COLL_NO = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $department_collno);
    $stmt->execute();
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
        .form-wrapper h3 {
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
        textarea {
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
        ?>
        <div class="form-wrapper">
            <h3>Update Department Details</h3>
            <div id="updateMsg"></div>
            <form id="updateForm">
                <div class="form-group">
                    <label>Department Code:</label>
                    <input type="text" value="<?php echo htmlspecialchars($info['collno']); ?>" disabled>
                    <input type="hidden" name="dept_collno" value="<?php echo htmlspecialchars($info['collno']); ?>">
                </div>

                <div class="form-group">
                    <label>Department Name:</label>
                    <input type="text" name="dept_name" value="<?php echo htmlspecialchars($info['DEPT_NAME'] ?: $info['collname']); ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Department Email:</label>
                        <input type="email" name="dept_email" value="<?php echo htmlspecialchars($info['EMAIL']); ?>">
                    </div>
                    <div class="form-group">
                        <label>HOD Email:</label>
                        <input type="email" name="hod_email" value="<?php echo htmlspecialchars($info['HOD_EMAIL']); ?>">
                    </div>
                </div>

                <div class="form-group">
                        <label>HOD Name:</label>
                        <input type="text" name="hod_name" value="<?php echo htmlspecialchars($info['HOD_NAME']); ?>">
                    </div>

                <div class="form-group">
                    <label>Department Address:</label>
                    <textarea name="hod_address" rows="3"><?php echo htmlspecialchars($info['ADDRESS']); ?></textarea>
                </div>

                <button type="submit" name="update_btn" class="btn-submit">Save Details</button>
            </form>
        </div>
        <?php
    } else {
        $collegeQuery = "SELECT collname FROM colleges WHERE collno = ?";
        $collegeStmt = $conn->prepare($collegeQuery);
        $collegeStmt->bind_param("s", $department_collno);
        $collegeStmt->execute();
        $collegeResult = $collegeStmt->get_result();
        $collegeInfo = $collegeResult->fetch_assoc();
        $collegeName = $collegeInfo['collname'] ?? '';
        $collegeStmt->close();
        ?>
        <p>No record found. You can add a department Details.</p>
        <div class="form-wrapper">
            <h3>Add New Department</h3>
            <div id="updateMsg"></div>
            <form id="updateForm">
                <div class="form-group">
                    <label>Department Code:</label>
                    <input type="text" value="<?php echo htmlspecialchars($department_collno); ?>" disabled>
                    <input type="hidden" name="dept_collno" value="<?php echo htmlspecialchars($department_collno); ?>">
                </div>

                <div class="form-group">
                    <label>Department Name:</label>
                    <input type="text" name="dept_name" value="<?php echo htmlspecialchars($collegeName); ?>" readonly>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Department Email:</label>
                        <input type="email" name="dept_email" placeholder="Enter Department Email">
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
    $stmt->close();
}
$conn->close();
?>
