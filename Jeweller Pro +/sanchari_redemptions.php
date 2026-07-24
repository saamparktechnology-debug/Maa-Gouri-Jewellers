<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';
if(!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

// -- Generate Redemption ID ---------------------------------------------
function genRedemptionId($conn) {
    $res = mysqli_query($conn, "SELECT redemption_id FROM sanchari_redemptions ORDER BY id DESC LIMIT 1");
    $row = mysqli_fetch_assoc($res);
    $num = 1;
    if($row && $row['redemption_id']) {
        $num = (int)substr($row['redemption_id'], 3) + 1;
    }
    return 'RED' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// -- Handle Form Submit -------------------------------------------------
$success = false;
$saved_id = '';
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_redemption'])) {
    $redemption_id   = genRedemptionId($conn);
    $customer_id     = mysqli_real_escape_string($conn, trim($_POST['customer_id']));
    $book_id         = mysqli_real_escape_string($conn, trim($_POST['book_id']));
    $purchase_date   = mysqli_real_escape_string($conn, trim($_POST['purchase_date']));
    $item_name       = mysqli_real_escape_string($conn, trim($_POST['item_name']));
    $gross_weight    = (float)$_POST['gross_weight'];
    $net_weight      = (float)$_POST['net_weight'];
    $making_charge   = (float)$_POST['making_charge'];
    $gst             = (float)$_POST['gst'];
    $jewellery_amount= (float)$_POST['jewellery_amount'];
    $adjusted_amount = (float)$_POST['adjusted_amount'];
    $balance_amount  = (float)$_POST['balance_amount'];

    $stmt = mysqli_prepare($conn, "INSERT INTO sanchari_redemptions (redemption_id, customer_id, book_id, purchase_date, item_name, gross_weight, net_weight, making_charge, gst, jewellery_amount, adjusted_amount, balance_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'sssssddddddd', $redemption_id, $customer_id, $book_id, $purchase_date, $item_name, $gross_weight, $net_weight, $making_charge, $gst, $jewellery_amount, $adjusted_amount, $balance_amount);
    mysqli_stmt_execute($stmt);

    // Update customer status to Completed
    mysqli_query($conn, "UPDATE sanchari_customers SET status='Completed' WHERE customer_id='$customer_id'");

    $success = true;
    $saved_id = $redemption_id;
}

// -- Handle PDF Receipt -------------------------------------------------
if(isset($_GET['pdf']) && isset($_GET['rid'])) {
    $rid = mysqli_real_escape_string($conn, $_GET['rid']);
    $r   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT sr.*, sc.customer_name, sc.mobile, sc.address, sc.monthly_amount, sc.scheme_duration, sc.joining_date FROM sanchari_redemptions sr LEFT JOIN sanchari_customers sc ON sr.customer_id=sc.customer_id WHERE sr.redemption_id='$rid'"));
    if(!$r) { echo "Record not found"; exit; }
    // Get payment summary
    $pay = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) total_paid, COALESCE(SUM(weight),0) total_weight, COUNT(*) installments FROM sanchari_payments WHERE customer_id='".$r['customer_id']."'"));
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Redemption Receipt - <?= htmlspecialchars($rid) ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #222; padding: 30px; }
  .header { text-align: center; border-bottom: 3px solid #c7522a; padding-bottom: 14px; margin-bottom: 18px; }
  .header h1 { color: #c7522a; font-size: 22px; letter-spacing: 1px; }
  .header p { color: #666; font-size: 11px; margin-top: 4px; }
  .badge { display: inline-block; background: #c7522a; color: #fff; padding: 3px 12px; border-radius: 20px; font-size: 11px; margin-top: 6px; }
  .section { margin-bottom: 16px; }
  .section-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #c7522a; border-bottom: 1px solid #f0ddd5; padding-bottom: 4px; margin-bottom: 8px; font-weight: bold; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; }
  .field { margin-bottom: 4px; }
  .field .lbl { color: #888; font-size: 10px; text-transform: uppercase; }
  .field .val { font-weight: bold; font-size: 12px; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; }
  th { background: #c7522a; color: #fff; padding: 7px 10px; text-align: left; font-size: 11px; }
  td { padding: 7px 10px; border-bottom: 1px solid #f0e8e0; }
  tr:nth-child(even) td { background: #fdf6f0; }
  .total-row td { background: #fdf0e8; font-weight: bold; }
  .highlight { background: #fff8f4; border: 2px solid #c7522a; border-radius: 8px; padding: 12px 16px; margin-top: 16px; }
  .highlight .big { font-size: 20px; font-weight: bold; color: #c7522a; }
  .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #aaa; border-top: 1px solid #eee; padding-top: 12px; }
  .sign-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px; }
  .sign-box { text-align: center; border-top: 1px solid #ccc; padding-top: 6px; font-size: 10px; color: #888; }
  @media print { body { padding: 15px; } }
</style>
</head>
<body>
<div class="header">
  <h1>?? MAA GOURI JEWELLERS</h1>
  <p>Sanchari Scheme � Redemption Receipt</p>
  <span class="badge"><?= htmlspecialchars($r['redemption_id']) ?></span>
</div>

<div class="section">
  <div class="section-title">Customer Details</div>
  <div class="grid2">
    <div class="field"><div class="lbl">Customer Name</div><div class="val"><?= htmlspecialchars($r['customer_name']) ?></div></div>
    <div class="field"><div class="lbl">Customer ID</div><div class="val"><?= htmlspecialchars($r['customer_id']) ?></div></div>
    <div class="field"><div class="lbl">Book ID</div><div class="val"><?= htmlspecialchars($r['book_id']) ?></div></div>
    <div class="field"><div class="lbl">Mobile</div><div class="val"><?= htmlspecialchars($r['mobile']) ?></div></div>
    <div class="field"><div class="lbl">Joining Date</div><div class="val"><?= htmlspecialchars($r['joining_date']) ?></div></div>
    <div class="field"><div class="lbl">Scheme</div><div class="val">?<?= number_format($r['monthly_amount'],2) ?>/mo � <?= htmlspecialchars($r['scheme_duration']) ?></div></div>
  </div>
</div>

<div class="section">
  <div class="section-title">Scheme Summary</div>
  <table>
    <thead><tr><th>Total Installments</th><th>Total Amount Paid</th><th>Gold Accumulated</th></tr></thead>
    <tbody>
      <tr><td><?= $pay['installments'] ?></td><td>?<?= number_format($pay['total_paid'],2) ?></td><td><?= number_format($pay['total_weight'],3) ?>g</td></tr>
    </tbody>
  </table>
</div>

<div class="section">
  <div class="section-title">Jewellery Purchase Details</div>
  <div class="grid2" style="margin-bottom:10px">
    <div class="field"><div class="lbl">Item Name</div><div class="val"><?= htmlspecialchars($r['item_name']) ?></div></div>
    <div class="field"><div class="lbl">Purchase Date</div><div class="val"><?= htmlspecialchars($r['purchase_date']) ?></div></div>
  </div>
  <table>
    <thead><tr><th>Gross Weight</th><th>Net Weight</th><th>Making Charge</th><th>GST</th><th>Jewellery Amount</th></tr></thead>
    <tbody>
      <tr>
        <td><?= number_format($r['gross_weight'],3) ?>g</td>
        <td><?= number_format($r['net_weight'],3) ?>g</td>
        <td>?<?= number_format($r['making_charge'],2) ?></td>
        <td>?<?= number_format($r['gst'],2) ?></td>
        <td><strong>?<?= number_format($r['jewellery_amount'],2) ?></strong></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="highlight">
  <div class="grid2">
    <div>
      <div style="font-size:11px;color:#888;text-transform:uppercase">Jewellery Amount</div>
      <div class="big">?<?= number_format($r['jewellery_amount'],2) ?></div>
    </div>
    <div>
      <div style="font-size:11px;color:#888;text-transform:uppercase">Scheme Adjusted</div>
      <div class="big" style="color:#27ae60">- ?<?= number_format($r['adjusted_amount'],2) ?></div>
    </div>
  </div>
  <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e0c4b0">
    <div style="font-size:11px;color:#888;text-transform:uppercase">Balance to Pay / Refund</div>
    <div style="font-size:24px;font-weight:bold;color:<?= $r['balance_amount'] >= 0 ? '#c7522a' : '#27ae60' ?>">
      <?= $r['balance_amount'] >= 0 ? '?'.number_format($r['balance_amount'],2).' (Customer Pays)' : '?'.number_format(abs($r['balance_amount']),2).' (Refund to Customer)' ?>
    </div>
  </div>
</div>

<div class="sign-row">
  <div class="sign-box">Customer Signature</div>
  <div class="sign-box">Authorized Signatory<br>MAA GOURI JEWELLERS</div>
</div>

<div class="footer">
  Generated on <?= date('d M Y H:i') ?> | MAA GOURI JEWELLERS � Sanchari Scheme
</div>

<script>window.onload=()=>window.print();</script>
</body>
</html>
<?php
    exit;
}

// -- Load customers for dropdown ----------------------------------------
$customers = mysqli_query($conn, "SELECT sc.*, COALESCE(SUM(sp.amount),0) total_paid, COALESCE(SUM(sp.weight),0) total_weight, COUNT(sp.id) installments FROM sanchari_customers sc LEFT JOIN sanchari_payments sp ON sc.customer_id=sp.customer_id GROUP BY sc.id ORDER BY sc.customer_name ASC");
$cust_list = [];
while($r = mysqli_fetch_assoc($customers)) $cust_list[] = $r;

// -- Past redemptions ---------------------------------------------------
$past = mysqli_query($conn, "SELECT sr.*, sc.customer_name, sc.mobile FROM sanchari_redemptions sr LEFT JOIN sanchari_customers sc ON sr.customer_id=sc.customer_id ORDER BY sr.id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sanchari Redemption | MAA GOURI JEWELLERS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"/>
  <style>
    :root { --gold:#c7522a; --gold-light:#f8ede6; --gold-mid:#e8a87c; }
    body { background:#f5f0eb; font-family:'Segoe UI',sans-serif; }
    .top-bar { background:#fff; border-bottom:3px solid var(--gold); padding:12px 24px; display:flex; align-items:center; justify-content:space-between; }
    .top-bar h4 { color:var(--gold); margin:0; font-weight:700; }
    .card { border:0; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
    .section-title { color:var(--gold); font-weight:700; font-size:.85rem; text-transform:uppercase; letter-spacing:.5px; border-bottom:2px solid var(--gold-light); padding-bottom:6px; margin-bottom:16px; }
    .customer-card { background:var(--gold-light); border-radius:10px; padding:14px 18px; margin-bottom:16px; display:none; }
    .customer-card.show { display:block; }
    .stat-pill { background:#fff; border-radius:8px; padding:10px 16px; text-align:center; }
    .stat-pill .val { font-size:1.3rem; font-weight:700; color:var(--gold); }
    .stat-pill .lbl { font-size:.72rem; color:#888; text-transform:uppercase; }
    .summary-box { background:linear-gradient(135deg,#c7522a,#e8a87c); color:#fff; border-radius:12px; padding:20px 24px; }
    .summary-box .big { font-size:2rem; font-weight:800; }
    .tbl th { background:var(--gold-light); color:#555; font-size:.78rem; text-transform:uppercase; }
    .tbl td { vertical-align:middle; font-size:.88rem; }
    .badge-active { background:#d4edda; color:#155724; }
    .badge-completed { background:#cce5ff; color:#004085; }
  </style>
</head>
<body>

<div class="top-bar">
  <div class="d-flex align-items-center gap-3">
    <i class="bi bi-gem fs-4" style="color:var(--gold)"></i>
    <h4>Sanchari Redemption</h4>
  </div>
  <div class="d-flex gap-2">
    <a href="sanchari_dashboard.php" class="btn btn-sm btn-outline-warning"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-house"></i></a>
  </div>
</div>

<div class="container-fluid py-4 px-4" style="max-width:1100px">

  <?php if($success): ?>
  <div class="alert alert-success d-flex align-items-center gap-3 shadow-sm">
    <i class="bi bi-check-circle-fill fs-4"></i>
    <div>
      <strong>Redemption saved!</strong> ID: <strong><?= $saved_id ?></strong>
      <a href="?pdf=1&rid=<?= urlencode($saved_id) ?>" target="_blank" class="btn btn-sm btn-danger ms-3"><i class="bi bi-file-pdf me-1"></i>Print Receipt</a>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- LEFT: Form -->
    <div class="col-lg-7">
      <div class="card">
        <div class="card-body p-4">
          <div class="section-title"><i class="bi bi-award me-2"></i>New Redemption Entry</div>

          <!-- Customer Select -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Select Customer</label>
            <select id="custSelect" class="form-select">
              <option value="">� Select Customer �</option>
              <?php foreach($cust_list as $c): ?>
              <option value="<?= htmlspecialchars($c['customer_id']) ?>"
                data-book="<?= htmlspecialchars($c['book_id']) ?>"
                data-name="<?= htmlspecialchars($c['customer_name']) ?>"
                data-mobile="<?= htmlspecialchars($c['mobile']) ?>"
                data-monthly="<?= $c['monthly_amount'] ?>"
                data-scheme="<?= htmlspecialchars($c['scheme_duration']) ?>"
                data-joined="<?= htmlspecialchars($c['joining_date']) ?>"
                data-paid="<?= $c['total_paid'] ?>"
                data-weight="<?= $c['total_weight'] ?>"
                data-installments="<?= $c['installments'] ?>"
                data-status="<?= htmlspecialchars($c['status']) ?>">
                <?= htmlspecialchars($c['customer_id']) ?> � <?= htmlspecialchars($c['customer_name']) ?> (<?= htmlspecialchars($c['book_id']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Customer Info Card -->
          <div class="customer-card" id="custCard">
            <div class="row g-2 mb-2">
              <div class="col-6"><small class="text-muted">Name</small><div class="fw-bold" id="ci_name"></div></div>
              <div class="col-6"><small class="text-muted">Mobile</small><div id="ci_mobile"></div></div>
              <div class="col-6"><small class="text-muted">Book ID</small><div id="ci_book"></div></div>
              <div class="col-6"><small class="text-muted">Status</small><div><span id="ci_status" class="badge"></span></div></div>
            </div>
            <div class="row g-2">
              <div class="col-4">
                <div class="stat-pill">
                  <div class="val" id="ci_installments">0</div>
                  <div class="lbl">Installments</div>
                </div>
              </div>
              <div class="col-4">
                <div class="stat-pill">
                  <div class="val" id="ci_paid">?0</div>
                  <div class="lbl">Total Paid</div>
                </div>
              </div>
              <div class="col-4">
                <div class="stat-pill">
                  <div class="val" id="ci_weight">0g</div>
                  <div class="lbl">Gold Weight</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Redemption Form -->
          <form method="post" id="redemptionForm">
            <input type="hidden" name="save_redemption" value="1">
            <input type="hidden" name="customer_id" id="f_customer_id">
            <input type="hidden" name="book_id" id="f_book_id">

            <div class="section-title mt-3"><i class="bi bi-gem me-2"></i>Jewellery Details</div>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Item Name <span class="text-danger">*</span></label>
                <input type="text" name="item_name" class="form-control" placeholder="e.g. Gold Necklace 22KT" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Purchase Date</label>
                <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Gross Weight (g)</label>
                <input type="number" step="0.001" name="gross_weight" id="f_gross" class="form-control" value="0" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Net Weight (g)</label>
                <input type="number" step="0.001" name="net_weight" id="f_net" class="form-control" value="0" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Making Charge (?)</label>
                <input type="number" step="0.01" name="making_charge" id="f_making" class="form-control" value="0">
              </div>
              <div class="col-md-4">
                <label class="form-label">GST (?)</label>
                <input type="number" step="0.01" name="gst" id="f_gst" class="form-control" value="0">
              </div>
              <div class="col-md-4">
                <label class="form-label">Jewellery Amount (?)</label>
                <input type="number" step="0.01" name="jewellery_amount" id="f_jewellery" class="form-control" value="0">
              </div>
            </div>

            <div class="section-title mt-4"><i class="bi bi-calculator me-2"></i>Settlement</div>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Scheme Amount (Auto)</label>
                <input type="number" step="0.01" name="adjusted_amount" id="f_adjusted" class="form-control" value="0" readonly style="background:#f8ede6">
              </div>
              <div class="col-md-4">
                <label class="form-label">Balance (?)</label>
                <input type="number" step="0.01" name="balance_amount" id="f_balance" class="form-control" value="0" readonly style="background:#f8ede6">
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div id="balanceNote" class="text-muted small"></div>
              </div>
            </div>

            <!-- Summary Box -->
            <div class="summary-box mt-4" id="summaryBox" style="display:none!important">
              <div class="row">
                <div class="col-6">
                  <div style="font-size:.75rem;opacity:.8">JEWELLERY AMOUNT</div>
                  <div class="big" id="s_jewellery">?0</div>
                </div>
                <div class="col-6">
                  <div style="font-size:.75rem;opacity:.8">SCHEME ADJUSTED</div>
                  <div class="big" id="s_adjusted">- ?0</div>
                </div>
              </div>
              <div class="mt-3 pt-3" style="border-top:1px dashed rgba(255,255,255,.4)">
                <div style="font-size:.75rem;opacity:.8">BALANCE</div>
                <div style="font-size:1.6rem;font-weight:800" id="s_balance">?0</div>
              </div>
            </div>

            <div class="mt-4 d-flex gap-2">
              <button type="submit" class="btn btn-warning text-white px-4" id="submitBtn" disabled>
                <i class="bi bi-save me-2"></i>Save Redemption
              </button>
              <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">Reset</button>
            </div>
          </form>

        </div>
      </div>
    </div>

    <!-- RIGHT: Past Redemptions -->
    <div class="col-lg-5">
      <div class="card">
        <div class="card-body p-4">
          <div class="section-title"><i class="bi bi-clock-history me-2"></i>Past Redemptions</div>
          <div class="table-responsive">
            <table class="table tbl table-hover mb-0">
              <thead><tr><th>ID</th><th>Customer</th><th>Item</th><th>Amount</th><th>Balance</th><th>PDF</th></tr></thead>
              <tbody>
              <?php while($r=mysqli_fetch_assoc($past)): ?>
              <tr>
                <td><span class="badge bg-light text-dark"><?= htmlspecialchars($r['redemption_id']) ?></span></td>
                <td>
                  <div class="fw-semibold"><?= htmlspecialchars($r['customer_name']) ?></div>
                  <small class="text-muted"><?= htmlspecialchars($r['customer_id']) ?></small>
                </td>
                <td><small><?= htmlspecialchars($r['item_name']) ?></small></td>
                <td>?<?= number_format($r['jewellery_amount'],0) ?></td>
                <td class="<?= $r['balance_amount'] > 0 ? 'text-danger' : 'text-success' ?>">
                  <?= $r['balance_amount'] >= 0 ? '?'.number_format($r['balance_amount'],0) : '-?'.number_format(abs($r['balance_amount']),0) ?>
                </td>
                <td>
                  <a href="?pdf=1&rid=<?= urlencode($r['redemption_id']) ?>" target="_blank" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-file-pdf"></i>
                  </a>
                </td>
              </tr>
              <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sel = document.getElementById('custSelect');
const custCard = document.getElementById('custCard');
let currentPaid = 0;

sel.addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  if(!opt.value) { custCard.classList.remove('show'); document.getElementById('submitBtn').disabled=true; return; }

  // Fill hidden fields
  document.getElementById('f_customer_id').value = opt.value;
  document.getElementById('f_book_id').value = opt.dataset.book;

  // Fill info card
  document.getElementById('ci_name').textContent = opt.dataset.name;
  document.getElementById('ci_mobile').textContent = opt.dataset.mobile;
  document.getElementById('ci_book').textContent = opt.dataset.book;
  const statusEl = document.getElementById('ci_status');
  statusEl.textContent = opt.dataset.status;
  statusEl.className = 'badge badge-' + opt.dataset.status.toLowerCase();
  document.getElementById('ci_installments').textContent = opt.dataset.installments;
  document.getElementById('ci_paid').textContent = '?' + parseFloat(opt.dataset.paid).toLocaleString('en-IN', {maximumFractionDigits:0});
  document.getElementById('ci_weight').textContent = parseFloat(opt.dataset.weight).toFixed(3) + 'g';

  currentPaid = parseFloat(opt.dataset.paid) || 0;
  document.getElementById('f_adjusted').value = currentPaid.toFixed(2);

  custCard.classList.add('show');
  document.getElementById('submitBtn').disabled = false;
  calcBalance();
});

function calcBalance() {
  const jewellery = parseFloat(document.getElementById('f_jewellery').value) || 0;
  const adjusted  = parseFloat(document.getElementById('f_adjusted').value) || 0;
  const balance   = jewellery - adjusted;
  document.getElementById('f_balance').value = balance.toFixed(2);

  const note = document.getElementById('balanceNote');
  const sBox = document.getElementById('summaryBox');

  if(jewellery > 0) {
    sBox.style.display = 'block';
    document.getElementById('s_jewellery').textContent = '?' + jewellery.toLocaleString('en-IN', {minimumFractionDigits:2});
    document.getElementById('s_adjusted').textContent  = '- ?' + adjusted.toLocaleString('en-IN', {minimumFractionDigits:2});
    document.getElementById('s_balance').textContent   = (balance >= 0 ? '?' : '-?') + Math.abs(balance).toLocaleString('en-IN', {minimumFractionDigits:2});
    note.textContent = balance >= 0 ? '? Customer pays extra' : '? Refund to customer';
    note.className = balance >= 0 ? 'text-danger small' : 'text-success small';
  } else {
    sBox.style.display = 'none';
  }
}

// Auto-calc jewellery amount from making + gst (optional helper)
['f_gross','f_net','f_making','f_gst','f_jewellery'].forEach(id => {
  document.getElementById(id).addEventListener('input', calcBalance);
});

function resetForm() {
  custCard.classList.remove('show');
  sel.value = '';
  currentPaid = 0;
  document.getElementById('submitBtn').disabled = true;
  document.getElementById('summaryBox').style.display = 'none';
}
</script>
</body>
</html>



