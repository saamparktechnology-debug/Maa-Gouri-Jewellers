<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';
if(!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

function genId($prefix, $table, $field) {
  global $conn;
  $res = mysqli_query($conn, "SELECT $field FROM $table ORDER BY id DESC LIMIT 1");
  $row = mysqli_fetch_assoc($res);
  $num = 1;
  if($row && $row[$field]) {
    $num = (int)substr($row[$field], strlen($prefix)) + 1;
  }
  return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
}

if($_SERVER['REQUEST_METHOD']==='POST') {
  $customer_id = mysqli_real_escape_string($conn, trim($_POST['customer_id']));
  $book_id = mysqli_real_escape_string($conn, trim($_POST['book_id']));
  $customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name']));
  $mobile = mysqli_real_escape_string($conn, trim($_POST['mobile']));
  $email = mysqli_real_escape_string($conn, trim($_POST['email']));
  $address = mysqli_real_escape_string($conn, trim($_POST['address']));
  $joining_date = mysqli_real_escape_string($conn, trim($_POST['joining_date']));
  $monthly_amount = (float)$_POST['monthly_amount'];
  $scheme_duration = mysqli_real_escape_string($conn, trim($_POST['scheme_duration']));
  $status = mysqli_real_escape_string($conn, trim($_POST['status']));
  if($customer_name==='' || $mobile==='' || $monthly_amount<=0){ die('Please fill all mandatory fields.'); }
  $stmt = mysqli_prepare($conn, "INSERT INTO sanchari_customers (customer_id, book_id, customer_name, mobile, email, address, joining_date, monthly_amount, scheme_duration, status) VALUES (?,?,?,?,?,?,?,?,?,?)");
  mysqli_stmt_bind_param($stmt, 'ssssssssss', $customer_id, $book_id, $customer_name, $mobile, $email, $address, $joining_date, $monthly_amount, $scheme_duration, $status);
  mysqli_stmt_execute($stmt);
  header('Location: sbook.php?success=1');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register Customer | MAA GOURI JEWELLERS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="card shadow-sm border-0 mx-auto" style="max-width:900px;">
      <div class="card-body p-4">
        <h3 class="mb-3" style="color:#c7522a;">Customer Registration</h3>
        <form method="post">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Customer ID <span class="text-danger">*</span></label><input name="customer_id" class="form-control" placeholder="CUS0001" required></div>
            <div class="col-md-6"><label class="form-label">Book ID <span class="text-danger">*</span></label><input name="book_id" class="form-control" placeholder="SB0001" required></div>
            <div class="col-md-6"><label class="form-label">Customer Name <span class="text-danger">*</span></label><input name="customer_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Mobile Number <span class="text-danger">*</span></label><input name="mobile" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Joining Date</label><input type="date" name="joining_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
            <div class="col-md-6"><label class="form-label">Monthly Deposit Amount <span class="text-danger">*</span></label><input type="number" step="0.01" name="monthly_amount" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Scheme Duration</label><select name="scheme_duration" class="form-select"><option>11 Months</option><option selected>12 Months</option></select></div>
            <div class="col-md-12"><label class="form-label">Address</label><textarea name="address" rows="3" class="form-control" placeholder="India, West Bengal"></textarea></div>
            <div class="col-md-6"><label class="form-label">Status</label><select name="status" class="form-select"><option>Active</option><option>Completed</option><option>Closed</option></select></div>
          </div>
          <div class="mt-4 text-end"><button class="btn btn-warning text-white" type="submit">Save Customer</button></div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>



