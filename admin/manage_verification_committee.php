<?php
/**
 * Admin Panel: Manage Verification Committee
 * - Show only verification_committee permission emails from boss table
 * - Add/Edit/Delete verification committee users
 * - Assign items to verify with organized UI
 * - Send credentials functionality
 */

// Error handling first
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require('session.php');
} catch (Exception $e) {
    error_log("Error loading session: " . $e->getMessage());
    die("Error loading session. Please contact administrator.");
}

try {
    require_once(__DIR__ . '/../config.php');
} catch (Exception $e) {
    error_log("Error loading config: " . $e->getMessage());
    die("Error loading configuration. Please contact administrator.");
}

// Check database connection
if (!isset($conn) || !$conn) {
    error_log("Database connection not available in manage_verification_committee.php");
    die("Database connection error. Please contact administrator.");
}

// Get only boss users with verification_committee permission
$boss_users = [];
try {
    $boss_query = "SELECT Id, EMAIL, PERMISSION, PASS_WORD FROM boss WHERE LOWER(PERMISSION) LIKE '%verification%' OR LOWER(PERMISSION) = 'verification_committee' ORDER BY EMAIL";
    $boss_result = @$conn->query($boss_query);
    if ($boss_result) {
        while ($row = $boss_result->fetch_assoc()) {
            $boss_users[] = $row;
        }
        mysqli_free_result($boss_result);
    }
} catch (Exception $e) {
    error_log("Error fetching boss users: " . $e->getMessage());
    $boss_users = [];
}

// Get all verification committee users with their assignments (using actual table structure)
$verification_users = [];
try {
    $table_check = @$conn->query("SHOW TABLES LIKE 'verification_committee_users'");
    if ($table_check && $table_check->num_rows > 0) {
        mysqli_free_result($table_check);
        
        // Check if verification_committee_assignments table exists
        $assignments_table_check = @$conn->query("SHOW TABLES LIKE 'verification_committee_assignments'");
        $has_assignments_table = ($assignments_table_check && $assignments_table_check->num_rows > 0);
        if ($assignments_table_check) {
            mysqli_free_result($assignments_table_check);
        }
        
        if ($has_assignments_table) {
            $verification_query = "SELECT 
                vcu.id,
                vcu.EMAIL,
                vcu.ROLE,
                vcu.NAME,
                vcu.is_active,
                IFNULL(vcu.SEND_CRED, 0) AS SEND_CRED,
                GROUP_CONCAT(CONCAT(vca.section_number, '-', vca.item_number) ORDER BY vca.section_number, vca.item_number SEPARATOR ', ') as assigned_items
            FROM verification_committee_users vcu
            LEFT JOIN verification_committee_assignments vca ON vcu.id = vca.verification_user_id
            GROUP BY vcu.id
            ORDER BY vcu.ROLE, vcu.EMAIL";
        } else {
            // If assignments table doesn't exist, just get users
            $verification_query = "SELECT 
                id,
                EMAIL,
                ROLE,
                NAME,
                is_active,
                IFNULL(SEND_CRED, 0) AS SEND_CRED,
                '' as assigned_items
            FROM verification_committee_users
            ORDER BY ROLE, EMAIL";
        }
        
        $verification_result = @$conn->query($verification_query);
        if ($verification_result) {
            while ($row = $verification_result->fetch_assoc()) {
                $verification_users[] = $row;
            }
            mysqli_free_result($verification_result);
        } else {
            error_log("Error in verification_query: " . mysqli_error($conn));
        }
    } else {
        if ($table_check) {
            mysqli_free_result($table_check);
        }
    }
} catch (Exception $e) {
    error_log("Error fetching verification users: " . $e->getMessage());
    $verification_users = [];
}

// Available roles
$available_roles = ['HRDC', 'AAQA', 'RAPC', 'KRC', 'AAMS', 'IQAC', 'DSD', 'GAD', 'Finance', 'AEM', 'CTPC', 'ICT', 'DLLE', 'Sports', 'Board of Examinations', 'Results Section', 'CDU', 'DIIL', 'cemas'];

// Sections and items structure - Based on UDRF Document
$sections = [
    0 => [
        'name' => 'A. Brief Details of the Department / Institution/ School/ Centre/ Sub-campus/ Model or Conducted/ Constituent College of University',
        'items' => [
            1 => 'Sanctioned Teaching Faculty and No. of Currently Working Regular Teachers (Professor, Associate Professor and Assistant Professor)',
            2 => 'No of Ad hoc/ Contract Teachers',
            3 => 'Strength of Non-teaching Employee (Class I/ II/ III/ IV)'
        ]
    ],
    1 => [
        'name' => 'I. Faculty Output, Research and Professional Activities Details',
        'items' => [
            1 => 'The average percentage of full-time teachers with Ph.D. (Max. 10 Marks)',
            2 => 'Full-time teachers who received awards, recognition, and fellowships at the State, National, and International levels (Max. 10 Marks)',
            3 => 'Percentage of teachers awarded the international fellowship for advanced studies/ research (Max. 05 Marks)',
            4 => 'Number of Ph.D\'s awarded at the Department during the last year (Max. 20 Marks)',
            5 => 'Total Grants for research projects sponsored by non-government sources (Max. 10 marks)',
            6 => 'Total Grants for research projects sponsored by the government sources (Max. 10 Marks)',
            7 => 'Revenue generated from Consultancy in the last year (Max. 10 Marks)',
            8 => 'Revenue generated from corporate training program organised by the department (Max. 05 Marks)',
            9 => 'Department with UGC-SAP, CAS, DST-FIST, DBT, ICSSR, and other similar recognitions (Max. 5+10 Marks)',
            10 => 'Number of start-ups incubated in the department during the last year (Max. 10 Marks)',
            11 => 'Number of IPR (Patents / Copyright/ Trademarks/ Designs etc.) Filed /published/ awarded and Transfer of Technology (ToT) (Max. 30 Marks)',
            12 => 'Total Number of research papers published in the Journals notified under Scopus, Web of Sciences (Max. 20 Marks)',
            13 => 'Cumulative IMPACT Factor per teacher based on JCR (Journal Citations Report) /Thomson Reuters Database (Max. 50 Marks)',
            14 => 'Bibliometrics of the publications in the last year based on cumulative citations (Max. 45 Marks)',
            15 => 'h-index of the Department (cumulative h-index of all full-time teachers) (Max. 20 Marks)',
            16 => 'UGC Listed non-Scopus /Web of Sciences research papers/ ISSN Journals + Special Issue Articles (Max marks 10)',
            17 => 'Total Number of books and chapters in edited volumes published by Reputed Publisher and development of MOOCs (Max. 20 Marks)',
            20 => 'Number of Teachers invited as speakers/resource persons/ Session Chair (10 marks)',
            21 => 'Number of Teachers who presented at Conferences/ Seminars/ Workshops (5 marks)'
        ]
    ],
    2 => [
        'name' => 'II. NEP Initiatives, Teaching, Learning, and Assessment Process',
        'items' => [
            1 => 'NEP Initiatives and Professional Activities adopted by the Department (Max. marks 30)',
            2 => 'Teaching-learning pedagogical approaches adopted by the department (Max. 20 Marks)',
            3 => 'Student-centric assessments (Formative, Interim, and Summative) adopted by the department (Max 20 Marks)',
            4 => 'Adoption of MOOC courses like SWAYAM, NPTEL, SWAYAM plus, Coursera, etc in the Curriculum (Max. 10 Marks)',
            5 => 'Creation of E-Content Development/ Digital Educational Materials for Teaching-Learning (Max.15 Marks)',
            6 => 'Timely Declaration of Results (Max. 05 Marks)'
        ]
    ],
    3 => [
        'name' => 'III. Departmental Governance and Practices',
        'items' => [
            1 => 'No of Inclusive Practices and Support Initiatives, as per UGC Norms (Max. 10 Marks)',
            2 => 'Green/Ecofriendly/ Sustainability Practices and Conducive Management steps implemented at the Department (Max. 10 Marks)',
            3 => 'Percentage of teachers involved in University and Government Administrative authorities/bodies/ Committees (Max. 10 Marks)',
            4 => 'Number of awards and recognitions received for extension activities from Government /recognized bodies (Max. 10 Marks)',
            5 => 'Budgetary Allocation of the Department and Expenditure (Max 05 Marks)',
            6 => 'Alumni contribution/ Funding Support during the previous year (Max. Marks 10)',
            7 => 'CSR and Philanthropic Funding support to the Department during the previous year (Max. Marks 10)',
            8 => 'Efforts taken for the Strengthening/ Augmentation of Departmental Infrastructural, Computational/ IT/Digital, Library, and Laboratory Facilities (Max. 10 Marks)',
            9 => 'Perception from Industry/Employers and Academia (PEER) during the last year (Max. 10 Marks)',
            10 => 'Students\' Feedback about Teachers and Department (Max. 10 Marks)',
            11 => 'Best Practice/ Unique Activity of the Department (Max. 5 marks)',
            12 => 'Details of various initiatives taken at the department level to ensure synchronization (Max. 10 marks)'
        ]
    ],
    4 => [
        'name' => 'IV. Student Support, Achievements and Progression',
        'items' => [
            1 => 'Enrolment Ratio (Max. 10 Marks)',
            2 => 'Admission Percentage in various programs run by the Department (Max. 10 marks)',
            3 => 'Number of JRFs, SRFs, Post Doctoral Fellows, Research Associates, and other research fellows (Max. 10 Marks)',
            4 => 'ESCS Diversity of Students as per Govt of Maharashtra Reservation Policy (Max. 10 Marks)',
            5 => 'Women Diversity of Students (Max. 5 Marks)',
            6 => 'Regional Diversity of Students (Max. 5 Marks)',
            7 => 'Various Support Initiatives for Enrichment of Campus Life and Academic Growth of Students (Max. 10 marks)',
            8 => 'Average percentage of Internship/ OJT of students in the last year (Max. 10 Marks)',
            9 => 'Graduation Outcome in various programs run by the Department (Max. 5 marks)',
            10 => 'Average percentage of Placement and Self- Employment of outgoing students (Max. 10 Marks)',
            11 => 'The average percentage of students qualifying in the state/national/ international level examinations (Max. 10 Marks)',
            12 => 'Average percentage of Students going for Higher Studies in Foreign Universities/ IIT/ IIM/ Eminent Institutions (Max. 10 Marks)',
            13 => 'Students Research Activity: Research Publications/Award at State Level Avishkar /Anveshan Award / National Conference Presentation Award etc (Max. 15 Marks)',
            14 => 'Number of awards/medals for outstanding performance in sports activities at State/ National/International level (Max. 10 Marks)',
            15 => 'Number of awards/medals for outstanding performance in cultural activities at State/ National/International level (Max. 10 Marks)'
        ]
    ],
    5 => [
        'name' => 'V. Conferences, Workshops, and Collaborations',
        'items' => [
            1 => 'Number of Industry-Academia Innovative practices/ Workshop conducted during the last year (5 Marks)',
            2 => 'Number of Workshops/ STTP/ Refresher or Orientation Programme Organized (5 Marks)',
            3 => 'Number of National Conferences/Seminars/Workshops organized (5 Marks)',
            4 => 'Number of International Conferences/ Seminars/ Workshops organized (10 Marks)',
            5 => 'Number of Teachers invited as speakers/resource persons/ Session Chair (10 marks)',
            6 => 'Number of Teachers who presented at Conferences/ Seminars/ Workshops (5 marks)',
            7 => 'Number of Industry collaborations for Programs and their output (10 Marks)',
            8 => 'Number of National Academic collaborations for Programs and their output (5 Marks)',
            9 => 'Number of Government/Semi-Government Collaboration Projects Programs (5 Marks)',
            10 => 'Number of International Academic collaborations for Programs and their output (10 Marks)',
            11 => 'No. of Outreach/ Social Activity Collaborations and their output (5 Marks)'
        ]
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Verification Committee - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f6f7fb;
        }
        .form-container {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 20px;
            margin-top: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .card {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: none;
            margin-bottom: 2rem;
        }
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        .badge-role {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
        }
        .btn-send {
            background-color: #198754;
            color: #fff;
        }
        .btn-resend {
            background-color: #ffc107;
            color: #000;
        }
        .alert {
            display: none;
            margin-top: 15px;
        }
        .section-assignment-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 1rem;
            overflow: hidden;
        }
        .section-assignment-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            font-weight: bold;
            cursor: pointer;
        }
        .section-assignment-body {
            padding: 1rem;
            background: #f8f9fa;
            max-height: 300px;
            overflow-y: auto;
        }
        .item-checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .item-checkbox {
            padding: 0.5rem;
            background: white;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        .download-btn {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        .download-btn:hover {
            background-color: #0056b3;
        }
        #verificationTable th,
        #verificationTable td {
            border: 1px solid #dee2e6 !important;
            padding: 12px;
            vertical-align: middle;
        }
        #verificationTable {
            border-collapse: collapse;
            margin: 20px 0;
        }
        .btn-group .btn {
            margin: 0;
            border-radius: 0;
        }
        .btn-group .btn:first-child {
            border-top-left-radius: 0.375rem;
            border-bottom-left-radius: 0.375rem;
        }
        .btn-group .btn:last-child {
            border-top-right-radius: 0.375rem;
            border-bottom-right-radius: 0.375rem;
        }
        .btn-group {
            display: flex;
            gap: 0;
        }
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="d-flex" id="wrapper">
        <?php include('sidebar.php'); ?>
        
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-transparent py-4 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
                    <h2 class="fs-2 m-0">Manage Verification Committee</h2>
                </div>
                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle second-text fw-bold" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['admin_username']); ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>
            
            <div class="div">
                <div class="container">
                    <?php
                    // CRITICAL: Display success message from GET parameter (POST-redirect-GET pattern)
                    if (isset($_GET['success']) && $_GET['success'] == '1' && isset($_GET['message'])) {
                        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
                        echo htmlspecialchars(urldecode($_GET['message']), ENT_QUOTES, 'UTF-8');
                        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                        echo '</div>';
                        // Remove success parameter from URL to prevent showing message on refresh
                        echo '<script>if (window.history.replaceState) { window.history.replaceState({}, document.title, window.location.pathname); }</script>';
                    }
                    ?>
                    <div id="responseMessage" class="alert"></div>
                    
                    <!-- Add New Verification Committee User -->
                    <div class="form-container">
                        <h4 class="mb-4"><i class="fas fa-user-plus me-2"></i>Add Verification Committee User</h4>
                        <form id="addVerificationUserForm">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="boss_user" class="form-label">Select User (Verification Committee Permission) <span class="text-danger">*</span></label>
                                    <select class="form-select" id="boss_user" name="email" required>
                                        <option value="">-- Select User --</option>
                                        <?php foreach ($boss_users as $user): ?>
                                            <option value="<?php echo htmlspecialchars($user['EMAIL']); ?>" data-password="<?php echo htmlspecialchars($user['PASS_WORD']); ?>">
                                                <?php echo htmlspecialchars($user['EMAIL']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Only users with verification_committee permission are shown</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="name" class="form-label">Name (Optional)</label>
                                    <input type="text" class="form-control" id="name" name="name" placeholder="Full Name">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="role_name" class="form-label">Role Name <span class="text-danger">*</span></label>
                                    <select class="form-select" id="role_name" name="role_name" required>
                                        <option value="">-- Select Role --</option>
                                        <?php foreach ($available_roles as $role): ?>
                                            <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($role); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label for="is_active" class="form-label">Status</label>
                                    <select class="form-select" id="is_active" name="is_active">
                                        <option value="1" selected>Active</option>
                                        <option value="0">Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Add User
                            </button>
                        </form>
                    </div>
                    
                    <!-- Existing Verification Committee Users -->
                    <div class="form-container" style="margin-top: 30px;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0"><i class="fas fa-list me-2"></i>Existing Verification Committee Users</h4>
                            <button id="downloadExcel" class="download-btn">
                                <i class="fas fa-file-excel"></i> Download as Excel
                            </button>
                        </div>
                        <div>
                            <table class="table table-bordered" id="verificationTable" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th style="width: 5%;">ID</th>
                                        <th style="width: 20%;">Email</th>
                                        <th style="width: 15%;">Name</th>
                                        <th style="width: 10%;">Role</th>
                                        <th style="width: 8%;">Status</th>
                                        <th style="width: 20%;">Assigned Items</th>
                                        <th style="width: 12%;">Send Credentials</th>
                                        <th style="width: 10%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($verification_users as $user): ?>
                                            <tr>
                                                <td class="text-center"><?php echo $user['id']; ?></td>
                                                <td><?php echo htmlspecialchars($user['EMAIL']); ?></td>
                                                <td><?php echo htmlspecialchars($user['NAME'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($user['ROLE']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($user['is_active']): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($user['assigned_items'] ?? 'No items assigned'); ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $sendCred = (int)($user['SEND_CRED'] ?? 0);
                                                    $btnClass = $sendCred === 1 ? 'btn-resend' : 'btn-send';
                                                    $btnText = $sendCred === 1 ? 'Re-send Credentials' : 'Send Credentials';
                                                    ?>
                                                    <button type="button" class="btn <?php echo $btnClass; ?> send-cred-btn" data-id="<?php echo $user['id']; ?>" data-email="<?php echo htmlspecialchars($user['EMAIL'], ENT_QUOTES); ?>">
                                                        <?php echo $btnText; ?>
                                                    </button>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-sm btn-warning" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['EMAIL'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['ROLE'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['NAME'] ?? '', ENT_QUOTES); ?>', <?php echo $user['is_active']; ?>)" title="Edit User">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <button class="btn btn-sm btn-primary" onclick="manageAssignments(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['EMAIL'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['ROLE'], ENT_QUOTES); ?>')" title="Assign Items">
                                                            <i class="fas fa-tasks"></i> Assign
                                                        </button>
                                                        <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['EMAIL'], ENT_QUOTES); ?>')" title="Delete User">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Verification Committee User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editUserForm">
                    <div class="modal-body">
                        <input type="hidden" id="edit_user_id" name="user_id">
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="edit_name" name="name">
                        </div>
                        <div class="mb-3">
                            <label for="edit_role_name" class="form-label">Role Name <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_role_name" name="role_name" required>
                                <?php foreach ($available_roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_is_active" class="form-label">Status</label>
                            <select class="form-select" id="edit_is_active" name="is_active">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Manage Assignments Modal -->
    <div class="modal fade" id="assignmentsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-tasks me-2"></i>Manage Item Assignments</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <input type="hidden" id="assign_user_id" value="">
                    <div class="mb-3">
                        <p><strong>User:</strong> <span id="assign_user_email"></span></p>
                        <p><strong>Role:</strong> <span id="assign_user_role"></span></p>
                    </div>
                    <hr>
                    <h6 class="mb-3">Select Items to Assign (Checkboxes):</h6>
                    <div id="assignmentsContainer">
                        <?php foreach ($sections as $section_num => $section_data): ?>
                            <div class="section-assignment-card">
                                <div class="section-assignment-header" onclick="toggleSection(<?php echo $section_num; ?>)">
                                    <i class="fas fa-chevron-down me-2" id="icon_<?php echo $section_num; ?>"></i>
                                    <?php echo htmlspecialchars($section_data['name']); ?>
                                </div>
                                <div class="section-assignment-body" id="section_<?php echo $section_num; ?>">
                                    <div class="item-checkbox-group">
                                        <?php foreach ($section_data['items'] as $item_num => $item_name): ?>
                                            <div class="item-checkbox">
                                                <div class="form-check">
                                                    <input class="form-check-input assignment-checkbox" 
                                                           type="checkbox" 
                                                           value="<?php echo $section_num . '_' . $item_num; ?>"
                                                           data-section="<?php echo $section_num; ?>"
                                                           data-item="<?php echo $item_num; ?>"
                                                           id="assign_<?php echo $section_num; ?>_<?php echo $item_num; ?>">
                                                    <label class="form-check-label" for="assign_<?php echo $section_num; ?>_<?php echo $item_num; ?>">
                                                        <strong>Item <?php echo $item_num; ?>:</strong> <?php echo htmlspecialchars($item_name); ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveAssignments()">
                        <i class="fas fa-save me-2"></i>Save Assignments
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable - fix column count issue
            // Destroy existing instance if any
            if ($.fn.DataTable.isDataTable('#verificationTable')) {
                $('#verificationTable').DataTable().destroy();
            }
            
            var table = $('#verificationTable').DataTable({
                responsive: true,
                pageLength: 10,
                lengthMenu: [5, 10, 25, 50, 100, 500, 1000],
                columnDefs: [
                    { orderable: false, targets: [6, 7] } // Disable sorting on Send Credentials and Actions columns
                ],
                order: [[0, 'asc']], // Sort by ID by default
                language: {
                    emptyTable: "No verification committee users found. Add one above."
                }
            });

            // Download Excel
            $('#downloadExcel').on('click', function() {
                var wb = XLSX.utils.book_new();
                var ws = XLSX.utils.table_to_sheet(document.getElementById('verificationTable'));
                XLSX.utils.book_append_sheet(wb, ws, "Verification Committee");
                XLSX.writeFile(wb, "Verification_Committee_List.xlsx");
            });

            // Send Credentials - Same logic as AllDepartmentDetails.php
            $('#verificationTable tbody').on('click', '.send-cred-btn', function() {
                var button = $(this);
                var userId = button.data('id');
                var email = button.data('email');
                var responseBox = $('#responseMessage');

                if (!userId) {
                    responseBox.removeClass().addClass("alert alert-danger").text("Invalid User ID").fadeIn();
                    return;
                }

                button.text('Sending...').prop('disabled', true);

                $.ajax({
                    url: 'api/verification_committee_send_credentials.php',
                    type: 'POST',
                    data: { user_id: userId, email: email },
                    dataType: 'json',
                    success: function(data) {
                        if (data && data.status === "success") {
                            responseBox.removeClass().addClass("alert alert-success").text(data.message).fadeIn();
                            button.removeClass('btn-send').addClass('btn-resend').text('Re-send Credentials');
                        } else {
                            var msg = (data && data.message) ? data.message : 'Something went wrong.';
                            responseBox.removeClass().addClass("alert alert-danger").text(msg).fadeIn();
                            button.text('Send Credentials');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error(xhr.responseText);
                        responseBox.removeClass().addClass("alert alert-danger").text("AJAX error: " + error).fadeIn();
                        button.text('Send Credentials');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                        setTimeout(function() { responseBox.fadeOut(); }, 5000);
                    }
                });
            });

            // Add User
            let isSubmitting = false; // Prevent duplicate submissions
            $('#addVerificationUserForm').on('submit', function(e) {
                e.preventDefault();
                
                // CRITICAL: Prevent duplicate submissions
                if (isSubmitting) {
                    return;
                }
                
                const formData = {
                    email: $('#boss_user').val(),
                    name: $('#name').val(),
                    role_name: $('#role_name').val(),
                    is_active: $('#is_active').val() || 1
                };
                
                // Validate required fields
                if (!formData.email || !formData.role_name) {
                    $('#responseMessage').removeClass().addClass("alert alert-danger").text('Please fill in all required fields.').fadeIn();
                    return;
                }
                
                isSubmitting = true;
                const submitButton = $(this).find('button[type="submit"]');
                const originalText = submitButton.html();
                submitButton.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Adding...');
                
                $.ajax({
                    url: 'api/verification_committee_add.php',
                    method: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        if (response && response.success) {
                            $('#responseMessage').removeClass().addClass("alert alert-success").text(response.message).fadeIn();
                            $('#addVerificationUserForm')[0].reset();
                            // CRITICAL: Redirect to prevent duplicate submissions on refresh (POST-redirect-GET pattern)
                            setTimeout(function() { 
                                window.location.href = 'manage_verification_committee.php?success=1&message=' + encodeURIComponent(response.message);
                            }, 1000);
                        } else {
                            var errorMsg = (response && response.message) ? response.message : 'Unknown error occurred';
                            $('#responseMessage').removeClass().addClass("alert alert-danger").text('Error: ' + errorMsg).fadeIn();
                            isSubmitting = false;
                            submitButton.prop('disabled', false).html(originalText);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', xhr.responseText);
                        var errorMsg = 'Error adding user. Please try again.';
                        try {
                            var jsonResponse = JSON.parse(xhr.responseText);
                            if (jsonResponse && jsonResponse.message) {
                                errorMsg = jsonResponse.message;
                            }
                        } catch (e) {
                            // Use default error message
                        }
                        $('#responseMessage').removeClass().addClass("alert alert-danger").text(errorMsg).fadeIn();
                        isSubmitting = false;
                        submitButton.prop('disabled', false).html(originalText);
                    }
                });
            });
        });
        
        // Edit User
        function editUser(id, email, role, name, isActive) {
            $('#edit_user_id').val(id);
            $('#edit_email').val(email);
            $('#edit_name').val(name || '');
            $('#edit_role_name').val(role);
            $('#edit_is_active').val(isActive);
            $('#editUserModal').modal('show');
        }
        
        let isEditing = false; // Prevent duplicate edit submissions
        $('#editUserForm').on('submit', function(e) {
            e.preventDefault();
            
            // CRITICAL: Prevent duplicate submissions
            if (isEditing) {
                return;
            }
            
            const formData = {
                user_id: $('#edit_user_id').val(),
                name: $('#edit_name').val(),
                role_name: $('#edit_role_name').val(),
                is_active: $('#edit_is_active').val()
            };
            
            isEditing = true;
            const submitButton = $(this).find('button[type="submit"]');
            const originalText = submitButton.html();
            submitButton.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Updating...');
            
            $.ajax({
                url: 'api/verification_committee_edit.php',
                method: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#responseMessage').removeClass().addClass("alert alert-success").text(response.message).fadeIn();
                        $('#editUserModal').modal('hide');
                        // CRITICAL: Redirect to prevent duplicate submissions on refresh (POST-redirect-GET pattern)
                        setTimeout(function() { 
                            window.location.href = 'manage_verification_committee.php?success=1&message=' + encodeURIComponent(response.message);
                        }, 1000);
                    } else {
                        $('#responseMessage').removeClass().addClass("alert alert-danger").text('Error: ' + response.message).fadeIn();
                        isEditing = false;
                        submitButton.prop('disabled', false).html(originalText);
                    }
                },
                error: function() {
                    $('#responseMessage').removeClass().addClass("alert alert-danger").text('Error updating user. Please try again.').fadeIn();
                    isEditing = false;
                    submitButton.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // Manage Assignments
        function manageAssignments(id, email, role) {
            $('#assign_user_id').val(id);
            $('#assign_user_email').text(email);
            $('#assign_user_role').text(role);
            
            // Load existing assignments
            $.ajax({
                url: 'api/verification_committee_get_assignments.php',
                method: 'GET',
                data: { user_id: id },
                dataType: 'json',
                success: function(response) {
                    // Uncheck all first
                    $('.assignment-checkbox').prop('checked', false);
                    
                    // Check existing assignments
                    if (response.success && response.assignments) {
                        response.assignments.forEach(function(assignment) {
                            const checkbox = $(`.assignment-checkbox[data-section="${assignment.section_number}"][data-item="${assignment.item_number}"]`);
                            checkbox.prop('checked', true);
                        });
                    }
                }
            });
            
            $('#assignmentsModal').modal('show');
        }
        
        function toggleSection(sectionNum) {
            const body = $('#section_' + sectionNum);
            const icon = $('#icon_' + sectionNum);
            body.slideToggle();
            icon.toggleClass('fa-chevron-down fa-chevron-up');
        }
        
        let isSavingAssignments = false; // Prevent duplicate assignment saves
        function saveAssignments() {
            // CRITICAL: Prevent duplicate submissions
            if (isSavingAssignments) {
                return;
            }
            
            const userId = $('#assign_user_id').val();
            if (!userId) {
                $('#responseMessage').removeClass().addClass("alert alert-danger").text('Error: Missing user ID').fadeIn();
                return;
            }
            
            const assignments = [];
            $('.assignment-checkbox:checked').each(function() {
                const section = parseInt($(this).data('section'));
                const item = parseInt($(this).data('item'));
                if (!isNaN(section) && !isNaN(item)) {
                    assignments.push({
                        section_number: section,
                        item_number: item
                    });
                }
            });
            
            isSavingAssignments = true;
            const saveButton = $('#assignmentsModal').find('button[onclick="saveAssignments()"]');
            const originalText = saveButton.html();
            saveButton.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Saving...');
            
            $.ajax({
                url: 'api/verification_committee_save_assignments.php',
                method: 'POST',
                data: {
                    user_id: userId,
                    assignments: JSON.stringify(assignments)
                },
                dataType: 'json',
                success: function(response) {
                    if (response && response.success) {
                        $('#responseMessage').removeClass().addClass("alert alert-success").text(response.message || 'Assignments saved successfully').fadeIn();
                        $('#assignmentsModal').modal('hide');
                        // CRITICAL: Redirect to prevent duplicate submissions on refresh (POST-redirect-GET pattern)
                        setTimeout(function() { 
                            window.location.href = 'manage_verification_committee.php?success=1&message=' + encodeURIComponent(response.message || 'Assignments saved successfully');
                        }, 1000);
                    } else {
                        var errorMsg = (response && response.message) ? response.message : 'Unknown error occurred';
                        $('#responseMessage').removeClass().addClass("alert alert-danger").text('Error: ' + errorMsg).fadeIn();
                        isSavingAssignments = false;
                        saveButton.prop('disabled', false).html(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr.responseText);
                    var errorMsg = 'Error saving assignments. Please try again.';
                    try {
                        var jsonResponse = JSON.parse(xhr.responseText);
                        if (jsonResponse && jsonResponse.message) {
                            errorMsg = jsonResponse.message;
                        }
                    } catch (e) {
                        // Use default error message
                    }
                    $('#responseMessage').removeClass().addClass("alert alert-danger").text('Error: ' + errorMsg).fadeIn();
                    isSavingAssignments = false;
                    saveButton.prop('disabled', false).html(originalText);
                }
            });
        }
        
        // Delete User
        let isDeleting = false; // Prevent duplicate delete submissions
        function deleteUser(id, email) {
            // CRITICAL: Prevent duplicate submissions
            if (isDeleting) {
                return;
            }
            
            if (confirm('Are you sure you want to delete this verification committee user?\n\nEmail: ' + email + '\n\nThis will also delete all their assignments.')) {
                isDeleting = true;
                $.ajax({
                    url: 'api/verification_committee_delete.php',
                    method: 'POST',
                    data: { user_id: id },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#responseMessage').removeClass().addClass("alert alert-success").text(response.message).fadeIn();
                            // CRITICAL: Redirect to prevent duplicate submissions on refresh (POST-redirect-GET pattern)
                            setTimeout(function() { 
                                window.location.href = 'manage_verification_committee.php?success=1&message=' + encodeURIComponent(response.message);
                            }, 1000);
                        } else {
                            $('#responseMessage').removeClass().addClass("alert alert-danger").text('Error: ' + response.message).fadeIn();
                            isDeleting = false;
                        }
                    },
                    error: function() {
                        $('#responseMessage').removeClass().addClass("alert alert-danger").text('Error deleting user. Please try again.').fadeIn();
                        isDeleting = false;
                    }
                });
            }
        }

        // Sidebar toggle
        var el = document.getElementById("wrapper");
        var toggleButton = document.getElementById("menu-toggle");
        toggleButton.onclick = function() {
            el.classList.toggle("toggled");
        };
    </script>
    <?php include '../footer_main.php';?>
</body>
</html>
