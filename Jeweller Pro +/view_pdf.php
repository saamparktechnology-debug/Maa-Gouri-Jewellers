<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$invoice_no = mysqli_real_escape_string($conn, $_GET['invoice_no'] ?? '');
if(!$invoice_no && isset($_GET['id'])) {
    $id_param = intval($_GET['id']);
    $inv_by_id_res = mysqli_query($conn, "SELECT invoice_no FROM invoices WHERE id = $id_param LIMIT 1");
    if ($inv_by_id_res && $inv_by_id = mysqli_fetch_assoc($inv_by_id_res)) {
        $invoice_no = $inv_by_id['invoice_no'];
    }
}
if(!$invoice_no) { die("Invoice number missing."); }

// Fetch invoice
$inv_res = mysqli_query($conn, "SELECT * FROM invoices WHERE invoice_no = '$invoice_no'");
if(!$inv_res || mysqli_num_rows($inv_res) == 0) {
    die("<h3 style='font-family:sans-serif;padding:20px;'>Invoice not found: ".htmlspecialchars($invoice_no)."</h3><a href='reports.php'>← Back to Reports</a>");
}
$inv = mysqli_fetch_assoc($inv_res);

// Fetch invoice items
$col_gst_type_check = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'gst_type'")) > 0;
$select_gst_type = $col_gst_type_check ? "ii.gst_type," : "'non_gst' AS gst_type,";

$items_res = mysqli_query($conn, "
    SELECT ii.invoice_id, ii.product_id, ii.quantity, ii.price, ii.total,
           ii.making_charge, ii.hallmark, ii.discount, $select_gst_type
           'g' AS unit,
           COALESCE(ii.product_name, p.name) AS product_name,
           COALESCE(ii.serial_no, p.serial_no) AS serial_no,
           COALESCE(ii.huid_code, p.huid_code) AS huid_code,
           ii.hsn_code AS hsn_code
    FROM invoice_items ii
    LEFT JOIN products p ON ii.product_id = p.id
    WHERE ii.invoice_id = " . intval($inv['id'])
);
$items = [];
if($items_res) {
    while($row = mysqli_fetch_assoc($items_res)) $items[] = $row;
}

$gst_total = floatval($inv['gst_amount'] ?? 0);
$subtotal  = floatval($inv['subtotal'] ?? 0);
$raw_gst_type = strtolower(trim($inv['gst_type'] ?? 'non_gst'));
$is_gst    = ($gst_total > 0 || ($raw_gst_type !== 'non_gst' && strpos($raw_gst_type, 'gst') === 0));

$doc_title     = $is_gst ? 'TAX INVOICE' : 'CASH MEMO';
$no_label      = $is_gst ? 'Invoice No.' : 'Cash Memo No.';
$date_label    = $is_gst ? 'Invoice Date' : 'Date';
$bill_to_label = $is_gst ? 'Bill To' : 'Customer Details';
$doc_term_name = $is_gst ? 'invoice' : 'cash memo';

// Calculate overall effective GST rate (e.g. 18% or 3%)
$effective_gst_pct = ($subtotal > 0 && $gst_total > 0) ? round(($gst_total / $subtotal) * 100, 1) : ($is_gst ? 3 : 0);

$cgst_rate   = $is_gst ? round($effective_gst_pct / 2, 2) : 0;
$sgst_rate   = $is_gst ? round($effective_gst_pct / 2, 2) : 0;
$cgst_amount = $is_gst ? round($gst_total / 2, 2) : 0;
$sgst_amount = $is_gst ? round($gst_total / 2, 2) : 0;

$subtotal = floatval($inv['subtotal'] ?? 0);
$discount = floatval($inv['discount'] ?? 0);
$old_gold = floatval($inv['old_gold_amount'] ?? 0);
$raw_total= floatval($inv['total_amount']);

// Ensure net total deducts old_gold_amount if DB stored pre-deduction total
$calc_total = max(0, $subtotal + $gst_total - $old_gold);
$total = ($old_gold > 0 && abs($raw_total - $calc_total) > 1) ? $calc_total : $raw_total;

$paid     = floatval($inv['paid_amount'] ?? 0);
$balance  = floatval($inv['balance_amount'] ?? 0);
$due_date = ($inv['payment_status'] === 'paid' || $balance <= 0) ? '' : (!empty($inv['due_date']) ? date('d/m/Y', strtotime($inv['due_date'])) : date('d/m/Y', strtotime('+30 days', strtotime($inv['created_at']))));
$next_due_date_display = (!empty($inv['due_date']) && $inv['due_date'] !== '0000-00-00') ? date('d/m/Y', strtotime($inv['due_date'])) : '';
$date_fmt = date('d/m/Y', strtotime($inv['created_at']));

// Fetch customer previous balance if any
$prev_balance = 0;
$cust_mobile = mysqli_real_escape_string($conn, $inv['customer_mobile']);
$prev_res = mysqli_query($conn, "SELECT SUM(balance_amount) as prev_due FROM invoices WHERE customer_mobile = '$cust_mobile' AND invoice_no != '$invoice_no' AND balance_amount > 0");
if($prev_res && $pr = mysqli_fetch_assoc($prev_res)) {
    $prev_balance = floatval($pr['prev_due'] ?? 0);
}
$current_balance = $prev_balance + $balance;

// Total Quantity & Weight calculations
$total_gross_wt = 0;
$total_net_wt = 0;
foreach($items as $it) {
    $g = floatval($it['quantity']);
    $n = floatval($it['quantity']);
    $total_gross_wt += $g;
    $total_net_wt   += $n;
}

// Number to words
function num2words($n) {
    $n = (int)round($n);
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    if($n==0) return 'Zero';
    if($n<20) return $ones[$n];
    if($n<100) return $tens[(int)($n/10)].($n%10?' '.$ones[$n%10]:'');
    if($n<1000) return $ones[(int)($n/100)].' Hundred'.($n%100?' '.num2words($n%100):'');
    if($n<100000) return num2words((int)($n/1000)).' Thousand'.($n%1000?' '.num2words($n%1000):'');
    if($n<10000000) return num2words((int)($n/100000)).' Lakh'.($n%100000?' '.num2words($n%100000):'');
    return num2words((int)($n/10000000)).' Crore'.($n%10000000?' '.num2words($n%10000000):'');
}
$total_words = num2words($total) . ' Rupees Only';

$logo_file = 'assets/images/moti-removebg-preview.png';

// ── DUE PAYMENT RECEIPT MODE LOGIC ──
$is_receipt = (isset($_GET['receipt']) && $_GET['receipt'] == '1') || isset($_GET['history_id']);
$history_id = intval($_GET['history_id'] ?? 0);

$rec_paid_amount = 0;
$rec_prev_balance = 0;
$rec_new_balance = 0;
$rec_prev_paid = 0;
$rec_payment_date = date('d/m/Y', strtotime($inv['created_at']));
$rec_no = 'RCPT-' . ($history_id > 0 ? $history_id : rand(1000, 9999));

if ($is_receipt) {
    if ($history_id > 0) {
        $h_res = mysqli_query($conn, "SELECT * FROM due_update_history WHERE id = $history_id LIMIT 1");
        if ($h_res && $h_row = mysqli_fetch_assoc($h_res)) {
            $rec_paid_amount = floatval($h_row['amount_paid']);
            $rec_prev_balance = floatval($h_row['previous_balance']);
            $rec_new_balance = floatval($h_row['new_balance']);
            $rec_payment_date = date('d/m/Y', strtotime($h_row['payment_date']));
        }
    }
    if ($rec_paid_amount <= 0) {
        $rec_paid_amount = floatval($_GET['paid_now'] ?? $inv['paid_amount']);
        $rec_new_balance = floatval($inv['balance_amount']);
        $rec_prev_balance = $rec_new_balance + $rec_paid_amount;
    }
    $rec_prev_paid = max(0, floatval($inv['total_amount']) - $rec_prev_balance);
    $rec_words = num2words($rec_paid_amount) . ' Rupees Only';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $doc_title; ?> — <?php echo htmlspecialchars($invoice_no); ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Poppins:wght@400;500;600;700&display=swap');

/* Strict A4 Print Dimensions */
@page {
    size: A4 portrait;
    margin: 8mm 10mm 8mm 10mm;
}

* { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins', sans-serif; }
body { background:#cbd5e1; padding:20px 0; color:#1e293b; }

.print-actions { max-width:820px; margin:0 auto 16px; display:flex; justify-content:space-between; align-items:center; }
.btn-print { background:linear-gradient(135deg,#7a4e0a,#d68b16); color:#fff; padding:10px 24px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(214,139,22,0.3); }
.btn-back { background:#fff; color:#475569; padding:10px 18px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid #cbd5e1; }

.invoice-card {
    width:210mm; max-width:820px; min-height:285mm; margin:0 auto; background:#fffbf4; border:3px solid #d68b16; border-radius:14px; padding:28px 32px; box-shadow:0 12px 36px rgba(0,0,0,0.18); position:relative; overflow:hidden;
}

/* Full Page Coloured Logo Watermark (Rendered on top as a true watermark overlay) */
.full-page-coloured-watermark {
    position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); width:520px; height:520px; object-fit:contain; opacity:0.12; pointer-events:none; z-index:99; filter:none;
}

.invoice-content { position:relative; z-index:2; }

/* Top Header */
.top-header { display:flex; align-items:center; gap:18px; margin-bottom:16px; border-bottom:2.5px solid #d68b16; padding-bottom:14px; }
.very-left-logo { width:85px; height:85px; object-fit:contain; border:none; box-shadow:none; background:transparent; flex-shrink:0; }
.shop-branding-text { flex:1; }
.shop-title { font-family:'Poppins', sans-serif; font-size:24px; font-weight:700; color:#2b1b17; line-height:1.1; letter-spacing:0.5px; margin-bottom:4px; }
.shop-details-line { font-size:11.5px; color:#523e2b; line-height:1.5; }
.shop-details-line strong { color:#7a4e0a; }

.header-right { text-align:right; flex-shrink:0; }
.tax-invoice-tag { font-size:18px; font-weight:700; color:#7a4e0a; font-family:'Poppins', sans-serif; letter-spacing:1.5px; text-transform:uppercase; margin-bottom:4px; }
.payment-status-pill { display:inline-block; padding:4px 14px; border-radius:20px; font-size:10.5px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase; }
.pill-paid { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
.pill-part { background:#fef3c7; color:#b45309; border:1px solid #fcd34d; }
.pill-unpaid { background:#ffe4e6; color:#be123c; border:1px solid #fca5a5; }

/* Meta Info Grid (Invoice No, Invoice Date, Due Date) */
.meta-bar { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; background:rgba(247,238,215,0.72); border:1.5px solid #d68b16; border-radius:8px; padding:8px 16px; margin-bottom:16px; font-size:11.5px; }
.meta-item { display:flex; flex-direction:column; }
.meta-label { font-size:9.5px; color:#7a4e0a; text-transform:uppercase; font-weight:600; letter-spacing:0.5px; margin-bottom:1px; }
.meta-value { font-weight:700; color:#2b1b17; font-size:13px; }

/* Bill To Block */
.bill-to-card { background:rgba(255,255,255,0.75); border:1.5px solid #e5c98a; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:flex-start; box-shadow:0 2px 6px rgba(122,78,10,0.06); }
.bill-to-left { flex:1; }
.bill-to-title { font-size:10.5px; font-weight:700; color:#7a4e0a; text-transform:uppercase; letter-spacing:1px; margin-bottom:3px; border-bottom:1px solid #f3e5c8; padding-bottom:3px; display:inline-block; }
.customer-name-big { font-size:15px; font-weight:700; color:#1e293b; margin-top:2px; }
.customer-address-text { font-size:11.5px; color:#475569; margin-top:3px; line-height:1.4; }
.bill-to-right { text-align:right; font-size:11.5px; color:#475569; line-height:1.6; flex-shrink:0; }

/* Items Table */
.inv-table { width:100%; border-collapse:collapse; margin-bottom:0; background:rgba(255,255,255,0.75); border-radius:8px; overflow:hidden; border:1.5px solid #d68b16; }
.inv-table thead tr { background:linear-gradient(135deg, #7a4e0a, #d68b16); color:#fff; }
.inv-table th { padding:8px 10px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; text-align:left; border-right:1px solid rgba(255,255,255,0.15); }
.inv-table th.right { text-align:right; }
.inv-table th.center { text-align:center; }
.inv-table td { padding:8px 10px; font-size:11.5px; color:#334155; border-bottom:1px solid #f1e5cd; border-right:1px solid #f1e5cd; vertical-align:top; background:rgba(255,255,255,0.65); }
.inv-table td.right { text-align:right; }
.inv-table td.center { text-align:center; }
.inv-table tbody tr:nth-child(even) td { background:rgba(250,245,232,0.65); }
.item-desc { font-weight:600; color:#1e293b; }
.item-sub { font-size:9.5px; color:#64748b; margin-top:1px; }

/* Subtotal row */
.subtotal-row td { background:rgba(243,232,206,0.85) !important; font-weight:700; color:#2b1b17; border-top:2px solid #d68b16; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5); padding:8px 10px; }

/* Bottom Section */
.bottom-section { display:grid; grid-template-columns:1.15fr 1fr; gap:20px; margin-top:16px; }
.bottom-left { display:flex; flex-direction:column; gap:12px; }
.terms-box-title { font-size:11px; font-weight:700; color:#7a4e0a; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:3px; }
.terms-text-content { font-size:10px; color:#475569; line-height:1.55; background:rgba(255,255,255,0.65); padding:8px 12px; border-radius:6px; border:1px solid #f1e5cd; }

/* Signature Stamp Box (At Very Bottom) */
.stamp-space-box { margin-top:16px; display:flex; justify-content:flex-start; }
.signature-stamp-frame { width:165px; height:75px; border:1.5px dashed #d68b16; border-radius:8px; display:inline-flex; flex-direction:column; align-items:center; justify-content:center; background:rgba(248,243,230,0.5); position:relative; }
.stamp-label-text { font-size:9.5px; color:#7a4e0a; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-top:auto; padding-bottom:5px; text-align:center; }

/* Calculation Box (Right Side) */
.calc-card { background:rgba(255,255,255,0.80); border:1.5px solid #d68b16; border-radius:8px; padding:14px 18px; box-shadow:0 2px 6px rgba(122,78,10,0.06); display:flex; flex-direction:column; gap:6px; }
.calc-line { display:flex; justify-content:space-between; font-size:11.5px; color:#475569; }
.calc-line strong { color:#1e293b; }
.calc-total-box { background:linear-gradient(135deg, #7a4e0a, #d68b16); color:#fff; border-radius:6px; padding:8px 12px; display:flex; justify-content:space-between; align-items:center; margin:3px 0; }
.calc-total-label { font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
.calc-total-val { font-size:15px; font-weight:700; font-family:inherit; }

.amount-words-bar { background:rgba(243,232,206,0.96); border:1.5px solid #d68b16; border-radius:8px; padding:8px 14px; margin-top:14px; font-size:11px; color:#523e2b; }
.amount-words-bar strong { color:#2b1b17; font-weight:700; }

/* Strict A4 Printing Rules */
@media print {
    html, body {
        width: 210mm !important;
        height: 297mm !important;
        background: #fff !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .print-actions { display:none !important; }
    .invoice-card {
        width: 100% !important;
        max-width: 100% !important;
        min-height: 285mm !important;
        border: 2.5px solid #ffd700; box-shadow: 0 0 15px rgba(255, 215, 0, 0.5) !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 24px 28px !important;
        background: #fff !important;
        page-break-inside: avoid;
    }
    .full-page-coloured-watermark { opacity:0.12 !important; z-index: 99 !important; }
    .inv-table tbody tr:nth-child(even) { background:#fcf8ee !important; }
}
</style>
</head>
<body>

<div class="print-actions">
    <div style="display:flex;gap:10px;">
        <a href="billing.php" class="btn-back">← Back to Billing</a>
        <a href="reports.php" class="btn-back">📊 Reports</a>
    </div>
    <button onclick="window.print()" class="btn-print">🖨️ Print / Download PDF (A4)</button>
</div>

<div class="invoice-card">
    <!-- Full Page Coloured Logo Watermark -->
    <?php if(file_exists($logo_file)): ?>
    <img src="<?php echo $logo_file; ?>" class="full-page-coloured-watermark" alt="Coloured Watermark Logo">
    <?php endif; ?>

    <div class="invoice-content">
        <?php if($is_receipt): ?>
            <!-- ==================== 🧾 DUE PAYMENT RECEIPT TEMPLATE ==================== -->
            <div>
                <!-- Top Header -->
                <div class="top-header">
                    <img src="<?php echo $logo_file; ?>" alt="MAA GOURI JEWELLERS Logo" class="very-left-logo">
                    <div class="shop-branding-text">
                        <div class="shop-title"><?php echo $COMPANY['name']; ?></div>
                        <div class="shop-details-line">
                            <strong>GSTIN:</strong> <?php echo $COMPANY['gstin']; ?> &nbsp;|&nbsp; <strong>Ph No:</strong> +91-<?php echo $COMPANY['mobile']; ?><br>
                            <strong>Address:</strong> <?php echo $COMPANY['address_line1']; ?>, <?php echo $COMPANY['address_line2']; ?>, <?php echo $COMPANY['state']; ?> (Code: <?php echo $COMPANY['state_code']; ?>)
                        </div>
                    </div>
                    <div class="header-right">
                        <div class="tax-invoice-tag" style="color:#059669;font-size:17px;">PAYMENT RECEIPT</div>
                        <span class="payment-status-pill pill-paid">✓ PAYMENT RECEIVED</span>
                    </div>
                </div>

                <!-- Receipt Meta Bar -->
                <div class="meta-bar" style="grid-template-columns:<?php echo ($rec_new_balance > 0 && !empty($next_due_date_display)) ? '1fr 1fr 1.2fr 1.1fr' : '1fr 1fr 1.2fr'; ?>;">
                    <div class="meta-item">
                        <span class="meta-label">Receipt No.</span>
                        <span class="meta-value"><?php echo $rec_no; ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Payment Date</span>
                        <span class="meta-value"><?php echo $rec_payment_date; ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Ref. <?php echo $no_label; ?></span>
                        <span class="meta-value"><?php echo htmlspecialchars($invoice_no); ?> (<?php echo $date_fmt; ?>)</span>
                    </div>
                    <?php if($rec_new_balance > 0 && !empty($next_due_date_display)): ?>
                    <div class="meta-item">
                        <span class="meta-label" style="color:#b91c1c;">Next Due Date</span>
                        <span class="meta-value" style="color:#b91c1c;"><?php echo $next_due_date_display; ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Bill To Block -->
                <div class="bill-to-card">
                    <div class="bill-to-left">
                        <div class="bill-to-title">Received From</div>
                        <div class="customer-name-big"><?php echo htmlspecialchars($inv['customer_name']); ?></div>
                        <?php if(!empty($inv['customer_address'])): ?>
                        <div class="customer-address-text">📍 Address: <?php echo htmlspecialchars($inv['customer_address']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="bill-to-right">
                        📞 Ph No: <strong>+91-<?php echo htmlspecialchars($inv['customer_mobile'] ?? '—'); ?></strong><br>
                        <?php if(!empty($inv['customer_gstin'])): ?>
                        🏛️ GSTIN: <strong><?php echo htmlspecialchars($inv['customer_gstin']); ?></strong><br>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Particulars Breakdown Table -->
                <table class="inv-table" style="margin-bottom:16px;">
                    <thead>
                        <tr>
                            <th>Payment Particulars / Description</th>
                            <th class="right" style="width:160px;">Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Original <?php echo $is_gst ? 'Invoice' : 'Cash Memo'; ?> Total Amount (Ref: <strong><?php echo htmlspecialchars($invoice_no); ?></strong>)</td>
                            <td class="right">₹<?php echo number_format($inv['total_amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <td>Previous Total Payments Received</td>
                            <td class="right">₹<?php echo number_format($rec_prev_paid, 2); ?></td>
                        </tr>
                        <tr>
                            <td>Outstanding Due Balance Before This Payment</td>
                            <td class="right" style="color:#b91c1c;font-weight:700;">₹<?php echo number_format($rec_prev_balance, 2); ?></td>
                        </tr>
                        <tr style="background:rgba(209,250,229,0.7) !important;font-weight:bold;">
                            <td style="color:#065f46;font-size:13px;">✔ PAYMENT RECEIVED NOW (Date: <?php echo $rec_payment_date; ?>)</td>
                            <td class="right" style="color:#065f46;font-size:16px;font-weight:800;">₹<?php echo number_format($rec_paid_amount, 2); ?></td>
                        </tr>
                        <tr class="subtotal-row">
                            <td style="font-size:12.5px;color:<?php echo $rec_new_balance > 0 ? '#b91c1c' : '#065f46'; ?>;">
                                REMAINING OUTSTANDING BALANCE DUE
                                <?php if($rec_new_balance > 0 && !empty($next_due_date_display)): ?>
                                    <br><span style="font-size:10.5px;font-weight:normal;color:#b91c1c;">🗓️ Next Payment Due Date: <strong><?php echo $next_due_date_display; ?></strong></span>
                                <?php endif; ?>
                            </td>
                            <td class="right" style="font-size:16px;font-weight:800;color:<?php echo $rec_new_balance > 0 ? '#b91c1c' : '#065f46'; ?>;">₹<?php echo number_format($rec_new_balance, 2); ?></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Amount Received in Words -->
                <div class="amount-words-bar" style="background:#ecfdf5;border-color:#6ee7b7;color:#065f46;margin-bottom:16px;">
                    <strong>Payment Amount Received in Words:</strong> <em><?php echo $rec_words; ?></em>
                </div>

                <!-- Terms & Signature Stamp Box -->
                <div class="bottom-section" style="margin-top:16px;">
                    <div class="bottom-left">
                        <div>
                            <div class="terms-box-title">Terms &amp; Declaration</div>
                            <div class="terms-text-content">
                                1. Received payment with thanks towards due balance for <?php echo $is_gst ? 'Invoice' : 'Cash Memo'; ?> <strong><?php echo htmlspecialchars($invoice_no); ?></strong>.<br>
                                2. Subject to Paschim Medinipur Jurisdiction.<br>
                                3. This is a computer generated official payment receipt.
                            </div>
                        </div>
                    </div>
                    <div class="bottom-right" style="display:flex;justify-content:flex-end;align-items:flex-end;">
                        <div class="signature-stamp-frame">
                            <div class="stamp-label-text">Signature &amp; Stamp<br>Authorised Signatory</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- ==================== FULL INVOICE TEMPLATE ==================== -->
            <div>
                <!-- 1. Top Header: Very Left Logo + Little Right (Name, GST No, Ph No, Address) -->
                <div class="top-header">
                    <img src="<?php echo $logo_file; ?>" alt="MAA GOURI JEWELLERS Logo" class="very-left-logo">
                    <div class="shop-branding-text">
                        <div class="shop-title"><?php echo $COMPANY['name']; ?></div>
                        <div class="shop-details-line">
                            <strong>GSTIN:</strong> <?php echo $COMPANY['gstin']; ?> &nbsp;|&nbsp; <strong>Ph No:</strong> +91-<?php echo $COMPANY['mobile']; ?><br>
                            <strong>Address:</strong> <?php echo $COMPANY['address_line1']; ?>, <?php echo $COMPANY['address_line2']; ?>, <?php echo $COMPANY['state']; ?> (Code: <?php echo $COMPANY['state_code']; ?>)
                        </div>
                    </div>
                    <div class="header-right">
                        <div class="tax-invoice-tag"><?php echo $doc_title; ?></div>
                        <span class="payment-status-pill <?php echo 'pill-'.$inv['payment_status']; ?>">
                            <?php
                            $ps = $inv['payment_status'];
                            echo ($ps==='paid') ? '✓ PAID' : (($ps==='part') ? 'PART PAID' : (($ps==='unpaid') ? 'UNPAID' : strtoupper($ps)));
                            ?>
                        </span>
                    </div>
                </div>

                <!-- 2. Invoice Meta Bar: Invoice No, Invoice Date, Due Date -->
                <div class="meta-bar">
                    <div class="meta-item">
                        <span class="meta-label"><?php echo $no_label; ?></span>
                        <span class="meta-value"><?php echo htmlspecialchars($invoice_no); ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label"><?php echo $date_label; ?></span>
                        <span class="meta-value"><?php echo $date_fmt; ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label"><?php echo ($current_balance > 0) ? 'Next Due Date' : 'Due Date'; ?></span>
                        <span class="meta-value" style="<?php echo ($current_balance > 0) ? 'color:#b91c1c;' : ''; ?>"><?php echo !empty($next_due_date_display) ? $next_due_date_display : ($due_date ?: '—'); ?></span>
                    </div>
                </div>

                <!-- 3. Bill To Block: Name & Address on left, Ph No & GSTIN & Vertical Place of Supply on right -->
                <div class="bill-to-card">
                    <div class="bill-to-left">
                        <div class="bill-to-title"><?php echo $bill_to_label; ?></div>
                        <div class="customer-name-big"><?php echo htmlspecialchars($inv['customer_name']); ?></div>
                        <?php if(!empty($inv['customer_address'])): ?>
                        <div class="customer-address-text">📍 Address: <?php echo htmlspecialchars($inv['customer_address']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="bill-to-right">
                        📞 Ph No: <strong>+91-<?php echo htmlspecialchars($inv['customer_mobile'] ?? '—'); ?></strong><br>
                        <?php if(!empty($inv['customer_gstin'])): ?>
                        🏛️ GSTIN: <strong><?php echo htmlspecialchars($inv['customer_gstin']); ?></strong><br>
                        <?php endif; ?>
                        📍 <strong>Place of Supply:</strong><br>
                        <div style="margin-top:2px;font-size:11.5px;color:#334155;line-height:1.4;">
                            <?php echo $COMPANY['address_line1']; ?><br>
                            <?php echo $COMPANY['address_line2']; ?><br>
                            <?php echo $COMPANY['state']; ?> (Code: <?php echo $COMPANY['state_code']; ?>)
                        </div>
                    </div>
                </div>

                <!-- 4. Items Table with Item Weights in Grams (Gross Wt & Net Wt) -->
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th style="width:32px" class="center">No</th>
                            <th>Items / Product Name</th>
                            <th style="width:85px" class="right">Gross Wt / Qty</th>
                            <th style="width:85px" class="right">Net Wt / Qty</th>
                            <th style="width:95px" class="right">Rate (₹/g)</th>
                            <th style="width:75px" class="right">Tax</th>
                            <th style="width:110px" class="right">Total (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($items)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center;padding:16px;color:#94a3b8;">No items added to this invoice.</td>
                        </tr>
                        <?php else: foreach($items as $idx => $it):
                            $name     = htmlspecialchars($it['product_name'] ?? 'Item');
                            $serial   = trim($it['serial_no'] ?? '');
                            $huid     = trim($it['huid_code'] ?? '');
                            if(empty($huid) && !empty($inv['huid_code'])) {
                                $huid = trim($inv['huid_code']);
                            }
                            $unit     = trim($it['unit'] ?? 'g');
                            $gross_wt = floatval($it['quantity']);
                            $net_wt   = floatval($it['quantity']);
                            $rate     = floatval($it['price']);
                            $amt      = floatval($it['total']);
                            $it_gst_type = $it['gst_type'] ?? 'non_gst';
                            if ($it_gst_type === 'gst_18') {
                                $item_tax_rate = 18;
                                $tax_amt = round($amt * 0.18, 2);
                            } else if ($it_gst_type === 'gst_3') {
                                $item_tax_rate = 3;
                                $tax_amt = round($amt * 0.03, 2);
                            } else if ($is_gst && $effective_gst_pct > 0) {
                                $item_tax_rate = $effective_gst_pct;
                                $tax_amt = round($amt * ($effective_gst_pct / 100), 2);
                            } else {
                                $item_tax_rate = 0;
                                $tax_amt = 0;
                            }
                        ?>
                        <tr>
                            <td class="center"><?php echo $idx + 1; ?></td>
                            <td>
                                <div class="item-desc"><?php echo $name; ?></div>
                                <?php if(!empty($huid)): ?>
                                <div class="item-sub" style="color:#7a4e0a;font-weight:600;">HUID: <strong><?php echo htmlspecialchars($huid); ?></strong></div>
                                <?php endif; ?>
                                <?php if(!empty($serial) && $serial !== $huid): ?>
                                <div class="item-sub">Serial: <strong><?php echo htmlspecialchars($serial); ?></strong></div>
                                <?php endif; ?>
                            </td>
                            <td class="right"><strong><?php echo ($unit === 'Qty') ? number_format($gross_wt, 0) : number_format($gross_wt, 3); ?></strong> <?php echo $unit; ?></td>
                            <td class="right"><strong><?php echo ($unit === 'Qty') ? number_format($net_wt, 0) : number_format($net_wt, 3); ?></strong> <?php echo $unit; ?></td>
                            <td class="right">₹<?php echo number_format($rate, 2); ?></td>
                            <td class="right">
                                <?php if($is_gst && $tax_amt > 0): ?>
                                ₹<?php echo number_format($tax_amt, 2); ?><br><span style="font-size:9px;color:#64748b;">(<?php echo $item_tax_rate; ?>%)</span>
                                <?php else: ?>
                                <span style="color:#94a3b8;">0%</span>
                                <?php endif; ?>
                            </td>
                            <td class="right"><strong>₹<?php echo number_format($amt, 2); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- 5. Subtotal Row with Total Weights in Grams -->
                        <tr class="subtotal-row">
                            <td colspan="2" style="text-align:right;">SUBTOTAL WEIGHTS &amp; AMOUNT</td>
                            <td class="right"><?php echo number_format($total_gross_wt, 3); ?> g</td>
                            <td class="right"><?php echo number_format($total_net_wt, 3); ?> g</td>
                            <td></td>
                            <td class="right">₹<?php echo number_format($is_gst ? $gst_total : 0, 2); ?></td>
                            <td class="right">₹<?php echo number_format($subtotal, 2); ?></td>
                        </tr>
                    </tbody>
                </table>

                <!-- 6. Bottom Split Section -->
                <div class="bottom-section">
                    <!-- Left Side: Complete Terms & Conditions -->
                    <div class="bottom-left">
                        <div>
                            <div class="terms-box-title">Terms and Conditions</div>
                            <div class="terms-text-content">
                                1. Goods once sold will not be taken back or exchanged without original bill receipt.<br>
                                2. Guarantee / Warranty applies strictly as per manufacturer and BIS Hallmark standards.<br>
                                3. All disputes are subject to Paschim Medinipur Jurisdiction.<br>
                                4. We declare that this <?php echo $doc_term_name; ?> shows the actual price of the goods described and all particulars are true and correct.
                            </div>
                        </div>
                    </div>

                    <!-- Right Side: Taxable Amount, CGST 1.5%, SGST 1.5% (Actual GST Divided in Two), Total Amount, Received Amount, Previous Balance, Current Balance -->
                    <div class="bottom-right">
                        <div class="calc-card">
                            <div class="calc-line">
                                <span>Taxable Amount</span>
                                <span>₹<?php echo number_format($subtotal, 2); ?></span>
                            </div>

                            <?php if($is_gst): ?>
                            <div class="calc-line">
                                <span>CGST (<?php echo $cgst_rate; ?>%)</span>
                                <span>₹<?php echo number_format($cgst_amount, 2); ?></span>
                            </div>

                            <div class="calc-line">
                                <span>SGST (<?php echo $sgst_rate; ?>%)</span>
                                <span>₹<?php echo number_format($sgst_amount, 2); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if($discount > 0): ?>
                            <div class="calc-line" style="color:#b91c1c;">
                                <span>Discount</span>
                                <span>(-) ₹<?php echo number_format($discount, 2); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if($old_gold > 0): ?>
                            <div class="calc-line" style="color:#dc2626;font-weight:600;">
                                <span>Less: Old Gold Return / Exchange</span>
                                <span>(-) ₹<?php echo number_format($old_gold, 2); ?></span>
                            </div>
                            <?php endif; ?>

                            <div class="calc-total-box">
                                <span class="calc-total-label">Grand Total (Net Payable)</span>
                                <span class="calc-total-val">₹<?php echo number_format($total, 2); ?></span>
                            </div>

                            <div class="calc-line" style="margin-top:2px;">
                                <span>Received Amount</span>
                                <span style="color:#15803d;font-weight:700;">₹<?php echo number_format($paid, 2); ?></span>
                            </div>

                            <div class="calc-line">
                                <span>Previous Balance</span>
                                <span style="color:#64748b;font-weight:600;">₹<?php echo number_format($prev_balance, 2); ?></span>
                            </div>

                            <div class="calc-line" style="border-top:1px dashed #cbd5e1;padding-top:4px;margin-top:2px;">
                                <span>Current Balance (Total Due)</span>
                                <span style="color:<?php echo $current_balance > 0 ? '#b91c1c' : '#15803d'; ?>;font-weight:700;">₹<?php echo number_format($current_balance, 2); ?></span>
                            </div>

                            <?php if($current_balance > 0 && !empty($next_due_date_display)): ?>
                            <div class="calc-line" style="color:#b91c1c;font-size:11px;margin-top:2px;">
                                <span>🗓️ Next Due Date</span>
                                <strong style="color:#b91c1c;"><?php echo $next_due_date_display; ?></strong>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 7. Total Amount in Words -->
                <div class="amount-words-bar">
                    <strong>Total Amount in Words:</strong> <em><?php echo $total_words; ?></em>
                </div>

                <!-- 8. Signature & Stamp Space (Right below Amount in Words) -->
                <div class="stamp-space-box">
                    <div class="signature-stamp-frame">
                        <div class="stamp-label-text">Signature &amp; Stamp<br>Authorised Signatory</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    </div>
</div>

</body>
</html>

