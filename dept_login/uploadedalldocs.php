<?php
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php';
}
// CRITICAL: Use unified_header.php instead of old header.php for consistent connection management
require "unified_header.php";
error_reporting(0);

$dept = $_SESSION['dept_id'];

$date = date_default_timezone_set('Asia/Kolkata');
$timestamp = date("Y-m-d H:i:s");
$timestamp1 = date("Y_m_d_H_i_s");


// Fetch uploaded docs for this dept
// $docs = [];
// $sql = "SELECT 
//     a.DEPT_ID,
//     a.DEPT_NAME,
//     a.HOD_NAME,
//     a.ADDRESS,
//     a.EMAIL,
//     b.A_YEAR,
//     b.particulars,
//     b.srno,
//     b.file_path
// FROM
//     department_master a
//         JOIN
//     nep_documents b ON a.DEPT_ID = b.dept_id
// WHERE
//     a.DEPT_ID = $dept AND b.A_YEAR = '$A_YEAR'";
// $result = mysqli_query($conn, $sql);
// while ($row = mysqli_fetch_assoc($result)) {
//     $docs[$row['srno']] = $row['file_path'];
// }
?>

<!-- <!DOCTYPE html>
<html lang="en"> -->

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Form</title>

    <link rel="shortcut icon" href="">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
        }

        .header {
            background-color: #2980b9;
            color: white;
            padding: 15px 20px;
            font-size: 18px;
            font-weight: bold;
        }

        .container {
            max-width: 1200px;
            margin: 20px auto;
            background-color: white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .div {
            padding: 20px;
            background-color: white;
        }

        .print-btn {
            text-align: center;
            padding: 15px;
        }

        .print-btn button {
            background-color: #27ae60;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }

        .app-id {
            background-color: #3498db;
            color: white;
            text-align: center;
            padding: 10px;
            font-weight: bold;
            font-size: 16px;
        }

        .section {
            padding: 20px;
        }

        .section-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 15px;
            border-bottom: 2px solid #ccc;
            padding-bottom: 5px;
        }

        .personal-details {
            display: flex;
            gap: 20px;
        }

        .details-left {
            flex: 1;
        }

        .photo {
            width: 120px;
            height: 150px;
            border: 2px solid #ccc;
            background-color: #e8f4fd;
        }

        .photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .detail-row {
            display: flex;
            margin-bottom: 8px;
            align-items: center;
        }

        .detail-label {
            width: 200px;
            font-weight: normal;
            text-align: right;
            padding-right: 10px;
        }

        .detail-value {
            flex: 1;
            font-weight: bold;
        }

        .cet-details {
            display: flex;
            gap: 40px;
            margin-bottom: 20px;
        }

        .cet-left,
        .cet-right {
            flex: 1;
        }

        .preferences-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .preferences-table th {
            background-color: #3498db;
            color: white;
            padding: 10px 8px;
            text-align: center;
            font-size: 14px;
            border: 1px solid #2980b9;
        }

        .preferences-table td {
            padding: 8px;
            text-align: center;
            border: 1px solid #ddd;
            font-size: 13px;
            vertical-align: middle;
        }

        .preferences-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .college-name {
            text-align: left !important;
            font-size: 12px;
        }

        .declaration {
            margin-top: 20px;
            padding: 15px;
            background-color: #e8f5e8;
            border: 2px solid #27ae60;
            border-radius: 5px;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
        }

        .declaration-text {
            background-color: #d4edda;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            text-align: justify;
            font-size: 13px;
            line-height: 1.4;
        }

        .footer-info {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #f8f9fa;
            font-size: 12px;
        }

        .footer-copyright {
            text-align: center;
            padding: 10px;
            background-color: #f8f9fa;
            font-size: 12px;
            border-top: 1px solid #ddd;
        }

        .status-unaided {
            background-color: #e74c3c;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
        }

        .status-government {
            background-color: #f39c12;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
        }

        .status-aided {
            background-color: #27ae60;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
        }

        #examtable {
            border-collapse: collapse;
            /* Optional: for a cleaner look */
            width: 100%;
            /* Optional: make the table full width */
        }

        #examtable th,
        #examtable td {
            padding: 20px 20px 20px 20px;
            border: 1px solid #ccc;
            /* Optional: adds a border to the cells */
        }

        #examtable th {
            background-color: #f2f2f2;
            /* Optional: header background color */
        }
    </style>
</head>

<body>
    <div class="div">
        <!-- <div class="header">
            Department Form
        </div> -->

        <!-- <div class="print-btn">
            <button>🖨️ pdf Department Form</button>
            <button>🖨️ excel Department Form</button>
        </div> -->

        <div class="app-id">
            Academic Year : <strong><?php echo $A_YEAR; ?></strong>
            &nbsp;&nbsp; | &nbsp;&nbsp;
            Department ID : <strong><?php echo $dept; ?></strong>
        </div>

        <div class="section">
            <div class="section-title">Research Details 
                &nbsp;&nbsp;
                <a href="Research_Details_Support_Doc.php" target="_blank">
                    <i class="fa-solid fa-up-right-from-square"></i>
                </a>
            </div>
            <div class="personal-details">
                <table class="table table-bordered m-20px" id="examtable_1" style="width:100%;">
                    <tbody>
                        <?php
                        $datapoint_query_6 = "SELECT a.id, a.A_YEAR, a.dept_id, 
                                                a.particulars, b.dp_id, a.srno,b.dq_no, b.dq_name, a.file_path
                                                FROM nep_documents a
                                                JOIN datapoint_question b ON a.srno = b.dq_no
                                                WHERE a.DEPT_ID = $dept and a.A_YEAR = '$A_YEAR' and a.particulars = '6' and b.dp_id='6'";
                        $datapoint_result_6 = mysqli_query($conn, $datapoint_query_6);
                        while ($info_6 = mysqli_fetch_array($datapoint_result_6, MYSQLI_ASSOC)) {
                            $srno = $info_6['srno'];
                            $dq_name = $info_6['dq_name'];
                            $file_path = $info_6['file_path'];
                        ?>
                            <tr>
                                <td class="text-center"><?php echo $srno; ?></td>
                                <td>
                                    <label type="label"><?php echo $dq_name; ?></label>
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <a href="<?php echo $file_path; ?>" target="_blank">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>

            </div>
        </div>

        <div class="section">
            <div class="section-title">NEPInitiatives
                &nbsp;&nbsp;
                <a href="nep_support_doc.php" target="_blank">
                    <i class="fa-solid fa-up-right-from-square"></i>
                </a>
            </div>

            <div class="personal-details">
                <table class="table table-bordered m-20px" id="examtable_1" style="width:100%;">
                    <tbody>
                        <?php
                        $datapoint_query_1 = "SELECT a.id, a.A_YEAR, a.dept_id, 
                                                a.particulars, b.dp_id, a.srno,b.dq_no, b.dq_name, a.file_path
                                                FROM nep_documents a
                                                JOIN datapoint_question b ON a.srno = b.dq_no
                                                WHERE a.DEPT_ID = $dept and a.A_YEAR = '$A_YEAR' and a.particulars = '1' and b.dp_id='1'";
                        $datapoint_result_1 = mysqli_query($conn, $datapoint_query_1);
                        while ($info_1 = mysqli_fetch_array($datapoint_result_1, MYSQLI_ASSOC)) {
                            $srno = $info_1['srno'];
                            $dq_name = $info_1['dq_name'];
                            $file_path = $info_1['file_path'];
                        ?>
                            <tr>
                                <td class="text-center"><?php echo $srno; ?></td>
                                <td>
                                    <label type="label"><?php echo $dq_name; ?></label>
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <a href="<?php echo $file_path; ?>" target="_blank">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Student Support
                &nbsp;&nbsp;
                <a href="Student_Support_Doc.php" target="_blank">
                    <i class="fa-solid fa-up-right-from-square"></i>
                </a>
            </div>
            <div class="personal-details">
                <table class="table table-bordered m-20px" id="examtable_2" style="width:100%;">
                    <tbody>
                        <?php
                        $datapoint_query_2 = "SELECT a.id, a.A_YEAR, a.dept_id, 
                                                a.particulars, b.dp_id, a.srno,b.dq_no, b.dq_name, a.file_path
                                                FROM nep_documents a
                                                JOIN datapoint_question b ON a.srno = b.dq_no
                                                WHERE a.DEPT_ID = $dept and a.A_YEAR = '$A_YEAR' and a.particulars = '2' and b.dp_id='2'";
                        $datapoint_result_2 = mysqli_query($conn, $datapoint_query_2);
                        while ($info_2 = mysqli_fetch_array($datapoint_result_2, MYSQLI_ASSOC)) {
                            $srno = $info_2['srno'];
                            $dq_name = $info_2['dq_name'];
                            $file_path = $info_2['file_path'];
                        ?>
                            <tr>
                                <td class="text-center"><?php echo $srno; ?></td>
                                <td>
                                    <label type="label"><?php echo $dq_name; ?></label>
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <a href="<?php echo $file_path; ?>" target="_blank">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Departmental Governance
                &nbsp;&nbsp;
                <a href="Departmental_Governance_Supporting_Doc.php" target="_blank">
                    <i class="fa-solid fa-up-right-from-square"></i>
                </a>
            </div>
            <div class="personal-details">
                <table class="table table-bordered m-20px" id="examtable_3" style="width:100%;">
                    <tbody>
                        <?php
                        $datapoint_query_3 = "SELECT a.id, a.A_YEAR, a.dept_id, 
                                                a.particulars, b.dp_id, a.srno,b.dq_no, b.dq_name, a.file_path
                                                FROM nep_documents a
                                                JOIN datapoint_question b ON a.srno = b.dq_no
                                                WHERE a.DEPT_ID = $dept and a.A_YEAR = '$A_YEAR' and a.particulars = '3' and b.dp_id='3'";
                        $datapoint_result_3 = mysqli_query($conn, $datapoint_query_3);
                        while ($info_3 = mysqli_fetch_array($datapoint_result_3, MYSQLI_ASSOC)) {
                            $srno = $info_3['srno'];
                            $dq_name = $info_3['dq_name'];
                            $file_path = $info_3['file_path'];
                        ?>
                            <tr>
                                <td class="text-center"><?php echo $srno; ?></td>
                                <td>
                                    <label type="label"><?php echo $dq_name; ?></label>
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <a href="<?php echo $file_path; ?>" target="_blank">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Conferences Workshops
                &nbsp;&nbsp;
                <a href="ConferencesWorkshops_Support_Doc.php" target="_blank">
                    <i class="fa-solid fa-up-right-from-square"></i>
                </a>
            </div>
            <div class="personal-details">
                <table class="table table-bordered m-20px" id="examtable_4" style="width:100%;">
                    <tbody>
                        <?php
                        $datapoint_query_4 = "SELECT a.id, a.A_YEAR, a.dept_id, 
                                                a.particulars, b.dp_id, a.srno,b.dq_no, b.dq_name, a.file_path
                                                FROM nep_documents a
                                                JOIN datapoint_question b ON a.srno = b.dq_no
                                                WHERE a.DEPT_ID = $dept and a.A_YEAR = '$A_YEAR' and a.particulars = '4' and b.dp_id='4'";
                        $datapoint_result_4 = mysqli_query($conn, $datapoint_query_4);
                        while ($info_4 = mysqli_fetch_array($datapoint_result_4, MYSQLI_ASSOC)) {
                            $srno = $info_4['srno'];
                            $dq_name = $info_4['dq_name'];
                            $file_path = $info_4['file_path'];
                        ?>
                            <tr>
                                <td class="text-center"><?php echo $srno; ?></td>
                                <td>
                                    <label type="label"><?php echo $dq_name; ?></label>
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <a href="<?php echo $file_path; ?>" target="_blank">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Collaborations
                &nbsp;&nbsp;
                <a href="Collaborations_Support_Doc.php" target="_blank">
                    <i class="fa-solid fa-up-right-from-square"></i>
                </a>
            </div>
            <div class="personal-details">
                <table class="table table-bordered m-20px" id="examtable_5" style="width:100%;">
                    <tbody>
                        <?php
                        $datapoint_query_5 = "SELECT a.id, a.A_YEAR, a.dept_id, 
                                                a.particulars, b.dp_id, a.srno,b.dq_no, b.dq_name, a.file_path
                                                FROM nep_documents a
                                                JOIN datapoint_question b ON a.srno = b.dq_no
                                                WHERE a.DEPT_ID = $dept and a.A_YEAR = '$A_YEAR' and a.particulars = '5' and b.dp_id='5'";
                        $datapoint_result_5 = mysqli_query($conn, $datapoint_query_5);
                        while ($info_5 = mysqli_fetch_array($datapoint_result_5, MYSQLI_ASSOC)) {
                            $srno = $info_5['srno'];
                            $dq_name = $info_5['dq_name'];
                            $file_path = $info_5['file_path'];
                        ?>
                            <tr>
                                <td class="text-center"><?php echo $srno; ?></td>
                                <td>
                                    <label type="label"><?php echo $dq_name; ?></label>
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <a href="<?php echo $file_path; ?>" target="_blank">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- <div class="section">
            <div class="declaration">Declaration</div>
            <div class="declaration-text">
                I have read all the rules of admission and on understanding these Rules, I have filled this Option Form for Admission to First Year of L.L.B.-3Yrs for the Academic Year 2025-26. The information given by me in this application is true to the best of my knowledge & belief. If at any later stage, it is found that I have furnished wrong information and/or submitted false certificate(s), I am aware that my admission stands cancelled and fees paid by me will be forfeited. Further I will be subject to legal and/or penal action as per the provisions of the law.
            </div>
        </div>

        <div class="footer-info">
            <div>
                <strong>Last Modified On :</strong> 24/08/2025 11:57:10 AM
            </div>
            <div>
                <strong>Last Modified By :</strong> L325153431, 106.76.70.188/Chrome/Windows 10/N
            </div>
        </div>-->

        <div class="footer-copyright">
            <strong>© This is the official website of University of Mumbai Centralized DCS Ranking PORTAL, Maharashtra, Mumbai. <br>All Rights Reserved.</strong>
        </div>
    </div>
</body>

<!-- </html> -->

<?php
// CRITICAL: Use unified_footer.php instead of old footer.php for consistent connection management
require "unified_footer.php";
?>
</tbody>
</table>
</div>
</div>
</div>