<?php
// EmployerDetails - Employer details form
require('session.php');

// ============================================================================
// AJAX HANDLERS - BEFORE ANY HTML OUTPUT
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // ✅ Ensure userInfo is loaded from session (already loaded by session.php at line 3, but make sure it's available)
    if (!isset($userInfo) && isset($_SESSION['userInfo'])) {
        $userInfo = $_SESSION['userInfo'];
    }
    
    // ✅ Allow skip_form_complete to be handled by skip_form_component.php (AFTER session setup)
    if ($_POST['action'] === 'skip_form_complete') {
        require_once(__DIR__ . '/skip_form_component.php');
        // skip_form_component.php will handle and exit
    }
    
    // Clear buffers again after session start
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // CRITICAL #4: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $dept = $userInfo['DEPT_ID'] ?? ($_SESSION['dept_id'] ?? 0);
        if (!$dept) { throw new Exception('Department not found in session.'); }

        $action = $_POST['action'];
        if ($action === 'add' || $action === 'update') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $First_Name = trim($_POST['First_Name'] ?? '');
            $Last_Name = trim($_POST['Last_Name'] ?? '');
            $Designation = trim($_POST['Designation'] ?? '');
            $Type_of_Industry = trim($_POST['Type_of_Industry'] ?? '');
            $Company = trim($_POST['Company'] ?? '');
            $Location = trim($_POST['Location'] ?? '');
            $Email_ID = trim($_POST['Email_ID'] ?? '');
            $Phone_Number = preg_replace('/\D+/', '', $_POST['Phone_Number'] ?? '');
            // Convert phone to integer for database (bigint column)
            $Phone_Number_int = !empty($Phone_Number) ? (int)$Phone_Number : 0;
            $type = trim($_POST['type'] ?? '');

            // Basic server validations
            if ($First_Name === '' || $Last_Name === '' || $Designation === '' || $Type_of_Industry === '' || $Company === '' || $Location === '' || $Email_ID === '' || $Phone_Number === '' || $type === '') {
                throw new Exception('Please fill all required fields.');
            }
            if (!filter_var($Email_ID, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address.');
            }
            if (strlen($Phone_Number) !== 10) {
                throw new Exception('Mobile number must be exactly 10 digits.');
            }

            // Academic year compute (June rollover)
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            if ($current_month >= 7) {
    $A_YEAR = $current_year . '-' . ($current_year + 1);
} else {
    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
}

            if ($action === 'add') {
                $q = "INSERT INTO employers_details (A_YEAR, DEPT_ID, FIRST_NAME, LAST_NAME, DESIGNATION, TYPE_OF_INDUSTRY, COMPANY, LOCATION, EMAIL_ID, PHONE, TYPE_INDIAN_FOREIGN)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $q);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . mysqli_error($conn));
                }
                // Type string: A_YEAR(s), DEPT_ID(i), then 8 strings, PHONE(i bigint), TYPE_INDIAN_FOREIGN(s) = 'sisssssssis'
                mysqli_stmt_bind_param($stmt, 'sisssssssis', $A_YEAR, $dept, $First_Name, $Last_Name, $Designation, $Type_of_Industry, $Company, $Location, $Email_ID, $Phone_Number_int, $type);
                if (!mysqli_stmt_execute($stmt)) {
                    $error = mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt);
                    throw new Exception('Failed to add record: ' . $error);
                }
                mysqli_stmt_close($stmt);  // CRITICAL: Close statement
                $response = ['success' => true, 'message' => 'Employer added successfully'];
            } else {
                $q = "UPDATE employers_details SET FIRST_NAME=?, LAST_NAME=?, DESIGNATION=?, TYPE_OF_INDUSTRY=?, COMPANY=?, LOCATION=?, EMAIL_ID=?, PHONE=?, TYPE_INDIAN_FOREIGN=? WHERE ID=? AND DEPT_ID=?";
                $stmt = mysqli_prepare($conn, $q);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . mysqli_error($conn));
                }
                // Type string: 7 strings (First_Name, Last_Name, Designation, Type_of_Industry, Company, Location, Email_ID) + PHONE(i bigint) + 1 string (TYPE_INDIAN_FOREIGN) + 2 integers (id, dept) = 'sssssssisii'
                mysqli_stmt_bind_param($stmt, 'sssssssisii', $First_Name, $Last_Name, $Designation, $Type_of_Industry, $Company, $Location, $Email_ID, $Phone_Number_int, $type, $id, $dept);
                if (!mysqli_stmt_execute($stmt)) {
                    $error = mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt);
                    throw new Exception('Failed to update record: ' . $error);
                }
                mysqli_stmt_close($stmt);  // CRITICAL: Close statement
                $response = ['success' => true, 'message' => 'Employer updated successfully'];
            }
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $stmt = mysqli_prepare($conn, 'DELETE FROM employers_details WHERE ID = ? AND DEPT_ID = ?');
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, 'ii', $id, $dept);
            if (!mysqli_stmt_execute($stmt)) {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception('Failed to delete record: ' . $error);
            }
            mysqli_stmt_close($stmt);  // CRITICAL: Close statement
            $response = ['success' => true, 'message' => 'Deleted'];
        } else {
            throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        // CRITICAL #2: Build error response in variable
        $response = ['success' => false, 'message' => $e->getMessage()];
    } catch (Error $e) {
        // CRITICAL #2: Build error response in variable for fatal errors
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #2: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Inline fetch for editing
// CRITICAL: Follow all checklist items to prevent crashes and ensure proper JSON responses
if (isset($_GET['fetch']) && $_GET['fetch'] == '1') {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Suppress errors
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // CRITICAL #4: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $dept = $userInfo['DEPT_ID'] ?? ($_SESSION['dept_id'] ?? 0);
        if (!$dept) {
            $response = ['success' => false, 'message' => 'Department not found'];
        } else {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) {
                $response = ['success' => false, 'message' => 'Invalid record ID'];
            } else {
        $stmt = mysqli_prepare($conn, 'SELECT * FROM employers_details WHERE ID = ? AND DEPT_ID = ?');
        if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
                } else {
        mysqli_stmt_bind_param($stmt, 'ii', $id, $dept);
        if (!mysqli_stmt_execute($stmt)) {
                        $error = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
                        $response = ['success' => false, 'message' => 'Query execution failed: ' . $error];
                    } else {
        $res = mysqli_stmt_get_result($stmt);
        if ($res && ($row = mysqli_fetch_assoc($res))) {
                            mysqli_free_result($res);  // CRITICAL: Free result
                            mysqli_stmt_close($stmt);  // CRITICAL: Close statement
                            $response = ['success' => true, 'record' => $row];
        } else {
                            if ($res) {
                                mysqli_free_result($res);  // CRITICAL: Free result even if empty
                            }
                            mysqli_stmt_close($stmt);
                            $response = ['success' => false, 'message' => 'Record not found'];
        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    } catch (Error $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #2: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

require('unified_header.php');
$dept = $userInfo['DEPT_ID'] ?? ($_SESSION['dept_id'] ?? 0);

// Get department details for display from department_master table
$dept_query = "SELECT DEPT_COLL_NO, DEPT_NAME FROM department_master WHERE DEPT_ID = '$dept'";
$dept_result = mysqli_query($conn, $dept_query);
$dept_info = mysqli_fetch_assoc($dept_result);
$dept_code = $dept_info['DEPT_COLL_NO'];
$dept_name = $dept_info['DEPT_NAME'];
?>
<div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
    <div id="soft-message" class="soft-message" style="display:none; position: fixed; top: 20px; right: 20px; padding: 12px 16px; border-radius: 8px; color: #fff; z-index: 9999;"></div>
    <div class="main-content-area">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-handshake me-3"></i>Employer Details
                    </h1>
                    <p class="page-subtitle">Manage employer information and contact details</p>
                </div>
                <a href="export_page_pdf.php?page=EmployerDetails" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
            </div>
        </div>

        <?php 
        // Display Skip Form Button for departments with NO employer data
        require_once(__DIR__ . '/skip_form_component.php');
        $check_existing_query = "SELECT ID FROM employers_details WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
        $check_existing_stmt = mysqli_prepare($conn, $check_existing_query);
        $has_existing_data = false;
        if ($check_existing_stmt) {
            mysqli_stmt_bind_param($check_existing_stmt, "is", $dept, $A_YEAR);
            mysqli_stmt_execute($check_existing_stmt);
            $check_existing_result = mysqli_stmt_get_result($check_existing_stmt);
            if ($check_existing_result && mysqli_num_rows($check_existing_result) > 0) {
                $has_existing_data = true;
            }
            if ($check_existing_result) {
                mysqli_free_result($check_existing_result);
            }
            mysqli_stmt_close($check_existing_stmt);
        }
        displaySkipFormButton('employer', 'Employer Details', $A_YEAR, $has_existing_data);
        ?>

        <div class="card">
            <div class="card-body">
                <form class="modern-form" id="employerForm" method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="id" id="record_id" value="">
                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-user me-2"></i>Personal Information
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>First Name *</b></label>
                                    <input type="text" name="First_Name" class="form-control" placeholder="Enter First Name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Last Name *</b></label>
                                    <input type="text" name="Last_Name" class="form-control" placeholder="Enter Last Name" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Designation *</b></label>
                                    <input type="text" name="Designation" class="form-control" placeholder="Enter Designation" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Type of Industry *</b></label>
                                    <input type="text" name="Type_of_Industry" class="form-control" placeholder="Enter Industry" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Company Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-building me-2"></i>Company Information
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Company *</b></label>
                                    <input type="text" name="Company" class="form-control" placeholder="Enter Company Name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Location *</b></label>
                                    <input type="text" name="Location" class="form-control" placeholder="Enter Location" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-envelope me-2"></i>Contact Information
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Email ID *</b></label>
                                    <input type="email" name="Email_ID" class="form-control" placeholder="Enter Email ID" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Phone Number *</b></label>
                                    <input type="tel" name="Phone_Number" id="Phone_Number" class="form-control" placeholder="10-digit Mobile Number" minlength="10" maxlength="10" pattern="\d{10}" inputmode="numeric" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label"><b>Type (Indian/Foreign) *</b></label>
                                    <select name="type" class="form-control" required>
                                        <option value="">Select Type</option>
                                        <option value="Indian">Indian</option>
                                        <option value="Foreign">Foreign</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="text-center mt-4">
                        <div class="d-flex flex-wrap justify-content-center gap-3">
                            <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                                <i class="fas fa-paper-plane me-2"></i><span id="submitText">Submit Data</span>
                            </button>
                        </div>
                    </div>
        </form>
        </div>
    </div>

<!-- Show Entered Data -->
<div class="card">
    <div class="card-body">
        <h3 class="fs-4 mb-3 text-center" id="msg"><b>You Have Entered the Following Data</b></h3>
        <div class="table-responsive">
            <table class="table table-hover modern-table">
                <thead class="table-header">
                    <tr>
                        <th scope="col">Academic Year</th>
                        <th scope="col">First Name</th>
                        <th scope="col">Last Name</th>
                        <th scope="col">Designation</th>
                        <th scope="col">Type of Industry</th>
                        <th scope="col">Company</th>
                        <th scope="col">Location</th>
                        <th scope="col">Email ID</th>
                        <th scope="col">Phone Number</th>
                        <th scope="col">Type</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // CRITICAL SECURITY FIX: Use prepared statement to prevent SQL injection
                    $dept_int = (int)$dept;
                    $Record_stmt = mysqli_prepare($conn, "SELECT * FROM employers_details WHERE DEPT_ID = ?");
                    if ($Record_stmt) {
                        mysqli_stmt_bind_param($Record_stmt, "i", $dept_int);
                        mysqli_stmt_execute($Record_stmt);
                        $Record = mysqli_stmt_get_result($Record_stmt);
                    } else {
                        $Record = false;
                    }
                    if ($Record) {
                        while ($row = mysqli_fetch_array($Record)) {
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['A_YEAR'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['FIRST_NAME'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['LAST_NAME'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['DESIGNATION'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['TYPE_OF_INDUSTRY'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['COMPANY'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['LOCATION'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['EMAIL_ID'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['PHONE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['TYPE_INDIAN_FOREIGN'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-warning" onclick="editEmployer(<?php echo (int)$row['ID']; ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteEmployer(<?php echo (int)$row['ID']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php
                        } // End while loop
                        // CRITICAL: Free result and close statement
                        if ($Record) {
                            mysqli_free_result($Record);
                        }
                        if (isset($Record_stmt)) {
                            mysqli_stmt_close($Record_stmt);
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
<?php
require "unified_footer.php";
?>
<script>
// Soft message helper
function showSmoothMessage(message, type='success'){
    const el = document.getElementById('soft-message');
    if(!el) return;
    const colors = { success: '#16a34a', error: '#dc2626', info: '#2563eb' };
    el.style.background = colors[type] || colors.info;
    el.textContent = message;
    el.style.display = 'block';
    setTimeout(()=>{ el.style.display='none'; }, 2500);
}
// Inline edit
function editEmployer(id){
    fetch('?fetch=1&id='+id)
        .then(r=>r.json())
        .then(data=>{
            if(!data.success){ showSmoothMessage(data.message||'Failed to load','error'); return; }
            const rec = data.record;
            document.getElementById('record_id').value = rec.ID;
            document.querySelector('input[name="action"]').value = 'update';
            setVal('First_Name', rec.FIRST_NAME);
            setVal('Last_Name', rec.LAST_NAME);
            setVal('Designation', rec.DESIGNATION);
            setVal('Type_of_Industry', rec.TYPE_OF_INDUSTRY);
            setVal('Company', rec.COMPANY);
            setVal('Location', rec.LOCATION);
            setVal('Email_ID', rec.EMAIL_ID);
            setVal('Phone_Number', rec.PHONE);
            setSelect('type', rec.TYPE_INDIAN_FOREIGN);
            document.getElementById('submitText').textContent = 'Update';
            window.scrollTo({top:0, behavior:'smooth'});
        })
        .catch(e=>showSmoothMessage('Error: '+e.message,'error'));
}
function setVal(name, val){ const el=document.querySelector(`[name="${name}"]`); if(el) el.value=val||''; }
function setSelect(name, val){ const el=document.querySelector(`[name="${name}"]`); if(!el) return; el.value=val||''; }

// AJAX submit
document.getElementById('employerForm').addEventListener('submit', function(e){
    e.preventDefault();
    const phone = document.getElementById('Phone_Number').value.replace(/\D+/g,'');
    if(phone.length!==10){ alert('Mobile number must be exactly 10 digits.'); return; }
    const btn = document.getElementById('submitBtn');
    const txt = document.getElementById('submitText');
    const orig = txt.textContent;
    btn.disabled = true; txt.textContent = 'Processing...';
    const fd = new FormData(this);
    fetch('', { method:'POST', body: fd })
      .then(async (resp)=>{ const c=resp.clone(); try{ return await resp.json(); }catch(e){ const t=await c.text(); throw new Error(t||'Non-JSON response'); } })
      .then(data=>{
        if(data.success){
          showSmoothMessage(data.message||'Saved','success');
          setTimeout(()=>{ window.location.reload(); }, 800);
        }else{ showSmoothMessage(data.message||'Failed','error'); }
      })
      .catch(err=>{ showSmoothMessage(err.message||'Request failed','error'); })
      .finally(()=>{ btn.disabled=false; txt.textContent=orig; });
});

// Delete via AJAX
function deleteEmployer(id){
    if(!confirm('Delete this record?')) return;
    const fd = new FormData();
    fd.append('action','delete'); fd.append('id', id);
    fetch('', { method:'POST', body: fd })
      .then(r=>r.json())
      .then(data=>{ if(data.success){ showSmoothMessage('Deleted','success'); setTimeout(()=>window.location.reload(),700); } else { showSmoothMessage(data.message||'Delete failed','error'); } })
      .catch(e=>showSmoothMessage('Delete error: '+e.message,'error'));
}
</script>