<?php
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php';
}

$department = "";
$department = $_POST['d_id'];

$check = "";
if ($department != "") {
    $check = "SELECT * FROM colleges WHERE department_id = '$department' and status='active' ORDER BY collname ASC";
} elseif ($department == "") {
    $check = "SELECT * FROM colleges WHERE status='active' ORDER BY collname ASC";
}
?>
<select style="font-size:medium" name="college_name" id="college_name" class="form-control" required>
    <option value="">Select Department/ Institution/ School/ Centre/Sub-campus/ Model College</option>
    <?php
    // $query11 = "SELECT * FROM college_name ORDER BY name_mar ASC";
    $result11 = mysqli_query($conn, $check);
    while ($info = mysqli_fetch_array($result11, MYSQLI_ASSOC)) {
        $department_id = $info['department_id'];
        $collname = $info['collname'];
        $collno = $info['collno'];
    ?>
        <option value="<?php echo $collno; ?>"><?php echo $collname; ?></option>

    <?php } 
    ?>
</select>

