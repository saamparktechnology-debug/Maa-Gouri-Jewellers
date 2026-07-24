<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';
if(!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $customer_id = mysqli_real_escape_string($conn, trim($_POST['customer_id']));
    $status = mysqli_real_escape_string($conn, trim($_POST['status']));
    if ($customer_id !== '' && in_array($status, ['Active', 'Completed', 'Closed'], true)) {
        mysqli_query($conn, "UPDATE sanchari_customers SET status='" . $status . "' WHERE customer_id='" . $customer_id . "'");
    }
}

$customers = mysqli_query($conn, "SELECT * FROM sanchari_customers ORDER BY id DESC");
$payments = mysqli_query($conn, "SELECT * FROM sanchari_payments ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><title>Reports | MAA GOURI JEWELLERS</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" /></head><body class="bg-light"><div class="container py-4"><h3 class="mb-4" style="color:#c7522a;">Sanchari Reports</h3><div class="row g-3 mb-4"><div class="col-md-6"><div class="card shadow-sm border-0"><div class="card-body"><h5>Customer Report</h5><table class="table table-sm align-middle"><thead><tr><th>Customer ID</th><th>Name</th><th>Book ID</th><th>Status</th><th>Action</th></tr></thead><tbody><?php while($r=mysqli_fetch_assoc($customers)){ echo '<tr><td>'.$r['customer_id'].'</td><td>'.$r['customer_name'].'</td><td>'.$r['book_id'].'</td><td><span class="badge text-bg-light">'.$r['status'].'</span></td><td><form method="post" class="d-flex gap-2"><input type="hidden" name="update_status" value="1"><input type="hidden" name="customer_id" value="'.$r['customer_id'].'"><select name="status" class="form-select form-select-sm" style="min-width:120px;"><option value="Active" '.($r['status']=='Active'?'selected':'').'>Active</option><option value="Completed" '.($r['status']=='Completed'?'selected':'').'>Completed</option><option value="Closed" '.($r['status']=='Closed'?'selected':'').'>Closed</option></select><button class="btn btn-sm btn-warning text-white" type="submit">Save</button></form></td></tr>'; } ?></tbody></table></div></div></div><div class="col-md-6"><div class="card shadow-sm border-0"><div class="card-body"><h5>Installment Report</h5><table class="table table-sm"><thead><tr><th>Payment ID</th><th>Customer</th><th>Amount</th><th>Weight</th></tr></thead><tbody><?php while($r=mysqli_fetch_assoc($payments)){ echo '<tr><td>'.$r['payment_id'].'</td><td>'.$r['customer_name'].'</td><td>'.number_format($r['amount'],2).'</td><td>'.number_format($r['weight'],3).'g</td></tr>'; } ?></tbody></table></div></div></div></div></div></body></html>


