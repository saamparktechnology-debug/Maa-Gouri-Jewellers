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
  if($row && $row[$field]) { $num = (int)substr($row[$field], strlen($prefix)) + 1; }
  return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
}

$customers = mysqli_query($conn, "SELECT customer_id, book_id, customer_name, monthly_amount FROM sanchari_customers ORDER BY id DESC");

if($_SERVER['REQUEST_METHOD']==='POST') {
  $payment_id = genId('PAY', 'sanchari_payments', 'payment_id');
  $customer_id = mysqli_real_escape_string($conn, trim($_POST['customer_id']));
  $book_id = mysqli_real_escape_string($conn, trim($_POST['book_id']));
  $customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name']));
  $installment_no = (int)$_POST['installment_no'];
  $payment_date = mysqli_real_escape_string($conn, trim($_POST['payment_date']));
  $amount = (float)$_POST['amount'];
  $gold_rate = (float)$_POST['gold_rate'];
  $weight = $gold_rate > 0 ? round($amount / $gold_rate, 3) : 0;
  $payment_mode = mysqli_real_escape_string($conn, trim($_POST['payment_mode']));
  $remarks = mysqli_real_escape_string($conn, trim($_POST['remarks']));
  $stmt = mysqli_prepare($conn, "INSERT INTO sanchari_payments (payment_id, customer_id, book_id, customer_name, installment_no, payment_date, amount, gold_rate, weight, payment_mode, remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  mysqli_stmt_bind_param($stmt, 'ssssissdsss', $payment_id, $customer_id, $book_id, $customer_name, $installment_no, $payment_date, $amount, $gold_rate, $weight, $payment_mode, $remarks);
  mysqli_stmt_execute($stmt);
  header('Location: sbook.php?payment=1');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="author" content="MANU GUPTA">
  <title>Payment Entry | MAA GOURI JEWELLERS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="card shadow-sm border-0 mx-auto" style="max-width:900px;">
      <div class="card-body p-4">
        <h3 class="mb-3" style="color:#c7522a;">Sanchay Payment Entry</h3>
        <form method="post">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Payment ID</label><input class="form-control" value="Auto Generated" disabled></div>
            <div class="col-md-6"><label class="form-label">Customer</label><select id="customerSelect" name="customer_id" class="form-select" required>
              <option value="">Select customer</option>
              <?php while($row=mysqli_fetch_assoc($customers)){ echo '<option value="'.$row['customer_id'].'" data-book="'.$row['book_id'].'" data-name="'.$row['customer_name'].'">'.$row['customer_id'].' - '.$row['customer_name'].'</option>'; } ?>
            </select></div>
            <div class="col-md-6"><label class="form-label">Book ID</label><input id="bookId" name="book_id" class="form-control" readonly></div>
            <div class="col-md-6"><label class="form-label">Customer Name</label><input id="customerName" name="customer_name" class="form-control" readonly></div>
            <div class="col-md-6"><label class="form-label">Installment Number</label><input type="number" name="installment_no" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
            <div class="col-md-6"><label class="form-label">Amount Paid</label><input type="number" step="0.01" name="amount" id="amount" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Gold Rate Per Gram</label><input type="number" step="0.01" name="gold_rate" id="goldRate" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Gold Weight (Auto)</label><input id="weightOut" class="form-control" readonly></div>
            <div class="col-md-6"><label class="form-label">Payment Mode</label><select name="payment_mode" class="form-select"><option>Cash</option><option>UPI</option><option>Bank</option><option>Card</option></select></div>
            <div class="col-md-12"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="3"></textarea></div>
          </div>
          <div class="mt-4 text-end"><button class="btn btn-warning text-white" type="submit">Save Payment</button></div>
        </form>
      </div>
    </div>
  </div>
  <script>
    const select = document.getElementById('customerSelect');
    const bookId = document.getElementById('bookId');
    const customerName = document.getElementById('customerName');
    const amount = document.getElementById('amount');
    const goldRate = document.getElementById('goldRate');
    const weightOut = document.getElementById('weightOut');
    function updateWeight(){ const w = goldRate.value > 0 ? (amount.value / goldRate.value) : 0; weightOut.value = w.toFixed(3); }
    select.addEventListener('change', function(){ const opt = this.options[this.selectedIndex]; bookId.value = opt.dataset.book || ''; customerName.value = opt.dataset.name || ''; });
    amount.addEventListener('input', updateWeight); goldRate.addEventListener('input', updateWeight);
  </script>
</body>
</html>



