<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';
if(!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

// Handle payment update
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_payment'])) {
    $inv_id = (int)$_POST['inv_id'];
    $paid = (float)$_POST['paid_amount'];
    $mode = mysqli_real_escape_string($conn, trim($_POST['payment_mode']));
    $res = mysqli_query($conn, "SELECT amount FROM sanchari_payments WHERE id=$inv_id");
    $row = mysqli_fetch_assoc($res);
    $total = $row ? (float)$row['amount'] : 0;
    $balance = $total - $paid;
    $status = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'pending');
    mysqli_query($conn, "UPDATE sanchari_payments SET paid_amount=$paid, balance_amount=$balance, payment_status='$status', payment_mode='$mode' WHERE id=$inv_id");
    header('Location: sanchari_dashboard.php?tab=payments&msg=updated');
    exit;
}

// Handle status update
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_status'])) {
    $cid = mysqli_real_escape_string($conn, trim($_POST['customer_id']));
    $st  = mysqli_real_escape_string($conn, trim($_POST['status']));
    if(in_array($st, ['Active','Completed','Closed'])) {
        mysqli_query($conn, "UPDATE sanchari_customers SET status='$st' WHERE customer_id='$cid'");
    }
    header('Location: sanchari_dashboard.php?tab=customers&msg=updated');
    exit;
}

$tab = $_GET['tab'] ?? 'overview';

// -- Stats ------------------------------------------------------------------
$total_customers   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM sanchari_customers"))['c'] ?? 0;
$active_customers  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM sanchari_customers WHERE status='Active'"))['c'] ?? 0;
$total_collected   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) s FROM sanchari_payments"))['s'] ?? 0;
$total_weight      = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(weight),0) s FROM sanchari_payments"))['s'] ?? 0;
$pending_balance   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(balance_amount),0) s FROM sanchari_payments WHERE payment_status!='paid'"))['s'] ?? 0;
$this_month        = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) s FROM sanchari_payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())"))['s'] ?? 0;

// -- Data for tabs ---------------------------------------------------------
$customers_data = mysqli_query($conn,"SELECT sc.*, COALESCE(SUM(sp.amount),0) total_paid, COUNT(sp.id) installments FROM sanchari_customers sc LEFT JOIN sanchari_payments sp ON sc.customer_id=sp.customer_id GROUP BY sc.id ORDER BY sc.id DESC");
$payments_data  = mysqli_query($conn,"SELECT * FROM sanchari_payments ORDER BY id DESC LIMIT 200");

// PDF download for customer statement
if(isset($_GET['pdf']) && isset($_GET['cid'])) {
    $cid = mysqli_real_escape_string($conn, $_GET['cid']);
    $cust = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM sanchari_customers WHERE customer_id='$cid'"));
    $pays = mysqli_query($conn,"SELECT * FROM sanchari_payments WHERE customer_id='$cid' ORDER BY payment_date ASC");
    $rows = [];
    while($r = mysqli_fetch_assoc($pays)) $rows[] = $r;
    $total_p = array_sum(array_column($rows,'amount'));
    $total_w = array_sum(array_column($rows,'weight'));
    ob_start();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Statement</title>
<style>
  body{font-family:Arial,sans-serif;font-size:12px;margin:0;padding:20px;color:#222;}
  h2{color:#c7522a;margin:0 0 4px;}
  .info{margin-bottom:16px;border:1px solid #eee;padding:10px;border-radius:6px;}
  table{width:100%;border-collapse:collapse;margin-top:10px;}
  th{background:#c7522a;color:#fff;padding:7px 8px;text-align:left;}
  td{padding:6px 8px;border-bottom:1px solid #f0e8e0;}
  tr:nth-child(even) td{background:#fdf6f0;}
  .total{font-weight:bold;background:#fff8f4;}
  .footer{margin-top:20px;font-size:10px;color:#999;text-align:center;}
</style></head><body>
<h2>MAA GOURI JEWELLERS � Sanchari Statement</h2>
<div class="info">
  <strong><?= htmlspecialchars($cust['customer_name'] ?? '') ?></strong> &nbsp;|&nbsp;
  Book ID: <?= htmlspecialchars($cust['book_id'] ?? '') ?> &nbsp;|&nbsp;
  Mobile: <?= htmlspecialchars($cust['mobile'] ?? '') ?><br>
  Joining: <?= htmlspecialchars($cust['joining_date'] ?? '') ?> &nbsp;|&nbsp;
  Monthly: ?<?= number_format($cust['monthly_amount'] ?? 0, 2) ?> &nbsp;|&nbsp;
  Status: <?= htmlspecialchars($cust['status'] ?? '') ?>
</div>
<table>
  <thead><tr><th>#</th><th>Payment ID</th><th>Date</th><th>Installment</th><th>Amount (?)</th><th>Gold Rate</th><th>Weight (g)</th><th>Mode</th></tr></thead>
  <tbody>
  <?php foreach($rows as $i=>$r): ?>
  <tr><td><?=$i+1?></td><td><?=htmlspecialchars($r['payment_id'])?></td><td><?=htmlspecialchars($r['payment_date'])?></td><td><?=$r['installment_no']?></td><td><?=number_format($r['amount'],2)?></td><td><?=number_format($r['gold_rate'],2)?></td><td><?=number_format($r['weight'],3)?></td><td><?=htmlspecialchars($r['payment_mode'])?></td></tr>
  <?php endforeach; ?>
  <tr class="total"><td colspan="4">Total</td><td>?<?=number_format($total_p,2)?></td><td>�</td><td><?=number_format($total_w,3)?>g</td><td></td></tr>
  </tbody>
</table>
<div class="footer">Generated on <?=date('d M Y H:i')?> | MAA GOURI JEWELLERS</div>
</body></html>
<?php
    $html = ob_get_clean();
    header('Content-Type: text/html');
    echo $html;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sanchari Dashboard | MAA GOURI JEWELLERS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"/>
  <style>
    :root { --gold:#c7522a; --gold-light:#f8ede6; --gold-mid:#e8a87c; }
    body { background:#f5f0eb; font-family:'Segoe UI',sans-serif; }
    .top-bar { background:#fff; border-bottom:3px solid var(--gold); padding:12px 24px; display:flex; align-items:center; justify-content:space-between; }
    .top-bar h4 { color:var(--gold); margin:0; font-weight:700; letter-spacing:.5px; }
    .nav-tabs .nav-link { color:#666; font-weight:500; border:none; border-bottom:3px solid transparent; padding:10px 18px; }
    .nav-tabs .nav-link.active { color:var(--gold); border-bottom:3px solid var(--gold); background:transparent; font-weight:700; }
    .stat-card { background:#fff; border-radius:12px; padding:20px 24px; border-left:5px solid var(--gold); box-shadow:0 2px 8px rgba(0,0,0,.06); }
    .stat-card .icon { font-size:2rem; color:var(--gold-mid); }
    .stat-card .val { font-size:1.7rem; font-weight:700; color:#222; }
    .stat-card .lbl { font-size:.8rem; color:#888; text-transform:uppercase; letter-spacing:.5px; }
    .card { border:0; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
    .badge-active { background:#d4edda; color:#155724; }
    .badge-completed { background:#cce5ff; color:#004085; }
    .badge-closed { background:#f8d7da; color:#721c24; }
    .badge-paid { background:#d4edda; color:#155724; }
    .badge-partial { background:#fff3cd; color:#856404; }
    .badge-pending { background:#f8d7da; color:#721c24; }
    .tbl th { background:var(--gold-light); color:#555; font-size:.78rem; text-transform:uppercase; letter-spacing:.4px; }
    .tbl td { vertical-align:middle; font-size:.9rem; }
    .search-box { max-width:280px; }
    .msg-toast { position:fixed; top:70px; right:20px; z-index:9999; }
    @media print { .no-print { display:none!important; } }
  </style>
</head>
<body>

<!-- Top Bar -->
<div class="top-bar no-print">
  <div class="d-flex align-items-center gap-3">
    <i class="bi bi-gem fs-4" style="color:var(--gold)"></i>
    <h4>Sanchari Dashboard</h4>
  </div>
  <div class="d-flex gap-2">
    <a href="sanchari_register.php" class="btn btn-sm btn-outline-warning">+ Register Customer</a>
    <a href="sanchari_payment.php" class="btn btn-sm btn-warning text-white">+ Add Payment</a>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-house"></i></a>
  </div>
</div>

<!-- Toast -->
<?php if(isset($_GET['msg'])): ?>
<div class="msg-toast no-print">
  <div class="alert alert-success alert-dismissible shadow" role="alert">
    <i class="bi bi-check-circle me-2"></i> <?= $_GET['msg']==='updated' ? 'Updated successfully!' : 'Done!' ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php endif; ?>

<div class="container-fluid py-4 px-4">

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-4 no-print" id="mainTabs">
    <li class="nav-item"><a class="nav-link <?=$tab==='overview'?'active':''?>" href="?tab=overview"><i class="bi bi-speedometer2 me-1"></i>Overview</a></li>
    <li class="nav-item"><a class="nav-link <?=$tab==='customers'?'active':''?>" href="?tab=customers"><i class="bi bi-people me-1"></i>Customers</a></li>
    <li class="nav-item"><a class="nav-link <?=$tab==='payments'?'active':''?>" href="?tab=payments"><i class="bi bi-cash-stack me-1"></i>Payment History</a></li>
    <li class="nav-item"><a class="nav-link <?=$tab==='update'?'active':''?>" href="?tab=update"><i class="bi bi-pencil-square me-1"></i>Update Payment</a></li>
    <li class="nav-item"><a class="nav-link <?=$tab==='reports'?'active':''?>" href="?tab=reports"><i class="bi bi-bar-chart me-1"></i>Reports</a></li>
  </ul>

  <!-- --- OVERVIEW ------------------------------------------------------- -->
  <?php if($tab==='overview'): ?>
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
      <div class="stat-card">
        <div class="icon"><i class="bi bi-people"></i></div>
        <div class="val"><?=$total_customers?></div>
        <div class="lbl">Total Customers</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card">
        <div class="icon"><i class="bi bi-person-check"></i></div>
        <div class="val"><?=$active_customers?></div>
        <div class="lbl">Active</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card">
        <div class="icon"><i class="bi bi-cash"></i></div>
        <div class="val">?<?=number_format($total_collected,0)?></div>
        <div class="lbl">Total Collected</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card">
        <div class="icon"><i class="bi bi-calendar-month"></i></div>
        <div class="val">?<?=number_format($this_month,0)?></div>
        <div class="lbl">This Month</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card">
        <div class="icon"><i class="bi bi-currency-bitcoin"></i></div>
        <div class="val"><?=number_format($total_weight,3)?>g</div>
        <div class="lbl">Gold Accumulated</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card" style="border-left-color:#e74c3c">
        <div class="icon" style="color:#e74c3c"><i class="bi bi-exclamation-triangle"></i></div>
        <div class="val" style="color:#e74c3c">?<?=number_format($pending_balance,0)?></div>
        <div class="lbl">Pending Balance</div>
      </div>
    </div>
  </div>

  <!-- Recent Payments -->
  <div class="card">
    <div class="card-body">
      <h6 class="mb-3" style="color:var(--gold)"><i class="bi bi-clock-history me-2"></i>Recent Payments</h6>
      <div class="table-responsive">
        <table class="table tbl table-hover mb-0">
          <thead><tr><th>Payment ID</th><th>Customer</th><th>Date</th><th>Amount</th><th>Weight</th><th>Mode</th></tr></thead>
          <tbody>
          <?php
          $recent = mysqli_query($conn,"SELECT * FROM sanchari_payments ORDER BY id DESC LIMIT 10");
          while($r=mysqli_fetch_assoc($recent)):
          ?>
          <tr>
            <td><span class="badge bg-light text-dark"><?=htmlspecialchars($r['payment_id'])?></span></td>
            <td><?=htmlspecialchars($r['customer_name'])?></td>
            <td><?=htmlspecialchars($r['payment_date'])?></td>
            <td><strong>?<?=number_format($r['amount'],2)?></strong></td>
            <td><?=number_format($r['weight'],3)?>g</td>
            <td><span class="badge bg-light text-dark"><?=htmlspecialchars($r['payment_mode'])?></span></td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- --- CUSTOMERS ------------------------------------------------------ -->
  <?php elseif($tab==='customers'): ?>
  <div class="card">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 style="color:var(--gold)" class="mb-0"><i class="bi bi-people me-2"></i>Customer List</h6>
        <input type="text" id="custSearch" class="form-control form-control-sm search-box" placeholder="Search name / mobile�">
      </div>
      <div class="table-responsive">
        <table class="table tbl table-hover" id="custTable">
          <thead><tr><th>Customer ID</th><th>Book ID</th><th>Name</th><th>Mobile</th><th>Monthly</th><th>Joined</th><th>Installments</th><th>Total Paid</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php while($r=mysqli_fetch_assoc($customers_data)): ?>
          <tr>
            <td><?=htmlspecialchars($r['customer_id'])?></td>
            <td><?=htmlspecialchars($r['book_id'])?></td>
            <td><strong><?=htmlspecialchars($r['customer_name'])?></strong></td>
            <td><?=htmlspecialchars($r['mobile'])?></td>
            <td>?<?=number_format($r['monthly_amount'],2)?></td>
            <td><?=htmlspecialchars($r['joining_date'])?></td>
            <td><?=$r['installments']?></td>
            <td>?<?=number_format($r['total_paid'],2)?></td>
            <td><span class="badge badge-<?=strtolower($r['status'])?>"><?=$r['status']?></span></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <form method="post" class="d-flex gap-1">
                  <input type="hidden" name="update_status" value="1">
                  <input type="hidden" name="customer_id" value="<?=htmlspecialchars($r['customer_id'])?>">
                  <select name="status" class="form-select form-select-sm" style="min-width:100px">
                    <option <?=$r['status']==='Active'?'selected':''?>>Active</option>
                    <option <?=$r['status']==='Completed'?'selected':''?>>Completed</option>
                    <option <?=$r['status']==='Closed'?'selected':''?>>Closed</option>
                  </select>
                  <button class="btn btn-sm btn-warning text-white" title="Save">?</button>
                </form>
                <a href="?tab=customers&pdf=1&cid=<?=urlencode($r['customer_id'])?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Statement PDF"><i class="bi bi-file-pdf"></i></a>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- --- PAYMENT HISTORY ------------------------------------------------ -->
  <?php elseif($tab==='payments'): ?>
  <div class="card">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 style="color:var(--gold)" class="mb-0"><i class="bi bi-cash-stack me-2"></i>Payment History</h6>
        <input type="text" id="paySearch" class="form-control form-control-sm search-box" placeholder="Search customer / ID�">
      </div>
      <div class="table-responsive">
        <table class="table tbl table-hover" id="payTable">
          <thead><tr><th>Payment ID</th><th>Customer ID</th><th>Name</th><th>Installment</th><th>Date</th><th>Amount</th><th>Gold Rate</th><th>Weight</th><th>Mode</th><th>Remarks</th></tr></thead>
          <tbody>
          <?php while($r=mysqli_fetch_assoc($payments_data)): ?>
          <tr>
            <td><span class="badge bg-light text-dark"><?=htmlspecialchars($r['payment_id'])?></span></td>
            <td><?=htmlspecialchars($r['customer_id'])?></td>
            <td><?=htmlspecialchars($r['customer_name'])?></td>
            <td class="text-center"><?=$r['installment_no']?></td>
            <td><?=htmlspecialchars($r['payment_date'])?></td>
            <td><strong>?<?=number_format($r['amount'],2)?></strong></td>
            <td>?<?=number_format($r['gold_rate'],2)?></td>
            <td><?=number_format($r['weight'],3)?>g</td>
            <td><?=htmlspecialchars($r['payment_mode'])?></td>
            <td><small class="text-muted"><?=htmlspecialchars($r['remarks'])?></small></td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- --- UPDATE PAYMENT ------------------------------------------------ -->
  <?php elseif($tab==='update'): ?>
  <div class="card mx-auto" style="max-width:750px">
    <div class="card-body p-4">
      <h6 class="mb-4" style="color:var(--gold)"><i class="bi bi-pencil-square me-2"></i>Update Payment Record</h6>
      <?php
      $upd_payments = mysqli_query($conn,"SELECT sp.*, sc.monthly_amount FROM sanchari_payments sp LEFT JOIN sanchari_customers sc ON sp.customer_id=sc.customer_id ORDER BY sp.id DESC LIMIT 100");
      ?>
      <div class="table-responsive">
        <table class="table tbl table-hover">
          <thead><tr><th>Payment ID</th><th>Customer</th><th>Installment</th><th>Payment Date</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th>Edit</th></tr></thead>
          <tbody>
          <?php while($r=mysqli_fetch_assoc($upd_payments)): ?>
          <tr>
            <td><?=htmlspecialchars($r['payment_id'])?></td>
            <td><?=htmlspecialchars($r['customer_name'])?></td>
            <td class="text-center"><?=$r['installment_no']?></td>
            <td><strong><?=date('d M Y', strtotime($r['payment_date']))?></strong></td>
            <td>?<?=number_format($r['amount'],2)?></td>
            <td>?<?=number_format($r['paid_amount'] ?? $r['amount'],2)?></td>
            <td>?<?=number_format($r['balance_amount'] ?? 0,2)?></td>
            <td><span class="badge badge-<?=$r['payment_status']??'paid'?>"><?=$r['payment_status']??'paid'?></span></td>
            <td>
              <button class="btn btn-sm btn-outline-warning" onclick="openEdit(<?=$r['id']?>,<?=$r['amount']?>,<?=$r['paid_amount']??$r['amount']?>,'<?=htmlspecialchars($r['payment_mode']??'Cash')?>','<?=$r['payment_date']?>')">
                <i class="bi bi-pencil"></i>
              </button>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Edit Modal -->
  <div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
      <form method="post" class="modal-content">
        <input type="hidden" name="update_payment" value="1">
        <input type="hidden" name="inv_id" id="modal_id">
        <div class="modal-header" style="background:var(--gold-light)">
          <h6 class="modal-title" style="color:var(--gold)">Update Payment</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Payment Date</label>
            <input id="modal_date_show" class="form-control" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Total Amount</label>
            <input id="modal_total" class="form-control" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Paid Amount</label>
            <input type="number" step="0.01" name="paid_amount" id="modal_paid" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Payment Mode</label>
            <select name="payment_mode" id="modal_mode" class="form-select">
              <option>Cash</option><option>UPI</option><option>Bank</option><option>Card</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-warning text-white w-100">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- --- REPORTS -------------------------------------------------------- -->
  <?php elseif($tab==='reports'): ?>
  <?php
  $rpt_customers = mysqli_query($conn,"SELECT sc.customer_id, sc.customer_name, sc.book_id, sc.mobile, sc.monthly_amount, sc.scheme_duration, sc.joining_date, sc.status, COALESCE(SUM(sp.amount),0) total_paid, COALESCE(SUM(sp.weight),0) total_weight, COUNT(sp.id) installments FROM sanchari_customers sc LEFT JOIN sanchari_payments sp ON sc.customer_id=sp.customer_id GROUP BY sc.id ORDER BY sc.customer_name ASC");
  $all_rows = [];
  while($r = mysqli_fetch_assoc($rpt_customers)) $all_rows[] = $r;
  $grand_amount = array_sum(array_column($all_rows,'total_paid'));
  $grand_weight = array_sum(array_column($all_rows,'total_weight'));
  ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 style="color:var(--gold)" class="mb-0"><i class="bi bi-bar-chart me-2"></i>Summary Report</h6>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print"><i class="bi bi-printer me-1"></i>Print</button>
  </div>
  <!-- Summary Row -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="stat-card">
        <div class="val">?<?=number_format($grand_amount,2)?></div>
        <div class="lbl">Grand Total Collected</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card">
        <div class="val"><?=number_format($grand_weight,3)?>g</div>
        <div class="lbl">Total Gold Accumulated</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card">
        <div class="val"><?=count($all_rows)?></div>
        <div class="lbl">Total Members</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card">
        <div class="val"><?=count(array_filter($all_rows,fn($r)=>$r['status']==='Active'))?></div>
        <div class="lbl">Active Members</div>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table tbl table-hover">
          <thead><tr><th>Customer ID</th><th>Name</th><th>Book ID</th><th>Mobile</th><th>Monthly</th><th>Joined</th><th>Installments</th><th>Total Paid</th><th>Gold Wt.</th><th>Status</th><th>Statement</th></tr></thead>
          <tbody>
          <?php foreach($all_rows as $r): ?>
          <tr>
            <td><?=htmlspecialchars($r['customer_id'])?></td>
            <td><strong><?=htmlspecialchars($r['customer_name'])?></strong></td>
            <td><?=htmlspecialchars($r['book_id'])?></td>
            <td><?=htmlspecialchars($r['mobile'])?></td>
            <td>?<?=number_format($r['monthly_amount'],2)?></td>
            <td><?=htmlspecialchars($r['joining_date'])?></td>
            <td class="text-center"><?=$r['installments']?></td>
            <td><strong>?<?=number_format($r['total_paid'],2)?></strong></td>
            <td><?=number_format($r['total_weight'],3)?>g</td>
            <td><span class="badge badge-<?=strtolower($r['status'])?>"><?=$r['status']?></span></td>
            <td><a href="?tab=reports&pdf=1&cid=<?=urlencode($r['customer_id'])?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-pdf"></i> PDF</a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Search filter
function liveSearch(inputId, tableId) {
  const inp = document.getElementById(inputId);
  if(!inp) return;
  inp.addEventListener('keyup', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{
      tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}
liveSearch('custSearch','custTable');
liveSearch('paySearch','payTable');

// Edit modal
function openEdit(id, total, paid, mode, date) {
  document.getElementById('modal_id').value = id;
  document.getElementById('modal_total').value = '?' + parseFloat(total).toFixed(2);
  document.getElementById('modal_paid').value = parseFloat(paid).toFixed(2);
  // Format date nicely
  if(date) {
    const d = new Date(date);
    document.getElementById('modal_date_show').value = d.toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'});
  }
  const sel = document.getElementById('modal_mode');
  for(let o of sel.options) o.selected = o.value===mode;
  new bootstrap.Modal(document.getElementById('editModal')).show();
}

// Auto-dismiss toast
setTimeout(()=>{ document.querySelectorAll('.msg-toast .alert').forEach(a=>a.remove()); }, 3000);
</script>
</body>
</html>


