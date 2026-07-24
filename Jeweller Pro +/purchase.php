<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

$is_logged_in = isset($_SESSION['user_id']);
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// ── DB: create tables if not exist ────────────────────────────────────────────
$conn->query("
CREATE TABLE IF NOT EXISTS purchase_entries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    purchase_no     VARCHAR(50)  NOT NULL UNIQUE,
    purchase_date   DATE         NOT NULL,
    invoice_no      VARCHAR(100) NOT NULL,
    invoice_date    DATE         NOT NULL,
    ref_no          VARCHAR(100),
    ref_date        DATE,
    payment_mode    VARCHAR(50)  DEFAULT 'NEFT/RTGS',

    -- Supplier
    supplier_name   VARCHAR(200) NOT NULL,
    supplier_addr   VARCHAR(500),
    supplier_gstin  VARCHAR(20),
    supplier_pan    VARCHAR(20),
    supplier_state  VARCHAR(100),
    supplier_state_code VARCHAR(5),
    supplier_mobile VARCHAR(20),
    supplier_email  VARCHAR(100),

    -- Buyer (auto-filled)
    buyer_name      VARCHAR(200) DEFAULT 'MOTI JEWELLERS',
    buyer_addr      VARCHAR(500),
    buyer_gstin     VARCHAR(20),
    buyer_pan       VARCHAR(20),

    -- Item
    material_type   ENUM('Gold','Silver','Diamond','Platinum') NOT NULL,
    description     VARCHAR(300),
    huid_code       VARCHAR(100),
    hsn_sac         VARCHAR(20),
    qty             DECIMAL(12,4) NOT NULL,
    unit            VARCHAR(10)  DEFAULT 'gm',
    rate_per_unit   DECIMAL(12,4) NOT NULL,

    -- Tax
    tax_type        ENUM('CGST_SGST','IGST') DEFAULT 'CGST_SGST',
    cgst_pct        DECIMAL(5,2) DEFAULT 1.50,
    sgst_pct        DECIMAL(5,2) DEFAULT 1.50,
    igst_pct        DECIMAL(5,2) DEFAULT 3.00,

    -- Amounts
    subtotal        DECIMAL(14,2),
    cgst_amt        DECIMAL(14,2) DEFAULT 0,
    sgst_amt        DECIMAL(14,2) DEFAULT 0,
    igst_amt        DECIMAL(14,2) DEFAULT 0,
    gst_total       DECIMAL(14,2),
    total_amount    DECIMAL(14,2),
    amount_words    VARCHAR(500),

    -- Meta
    notes           TEXT,
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("
CREATE TABLE IF NOT EXISTS stock_metal (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    material_type ENUM('Gold','Silver','Diamond','Platinum') NOT NULL,
    unit          VARCHAR(10) DEFAULT 'gm',
    qty_available DECIMAL(14,4) DEFAULT 0,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_material (material_type)
)");

// seed stock rows
foreach(['Gold','Silver','Diamond','Platinum'] as $m) {
    $conn->query("INSERT IGNORE INTO stock_metal (material_type,qty_available) VALUES ('$m',0)");
}

// Ensure HUID column exists for existing database tables
$result = $conn->query("SHOW COLUMNS FROM purchase_entries LIKE 'huid_code'");
if ($result && $result->num_rows === 0) {
    $conn->query("ALTER TABLE purchase_entries ADD COLUMN huid_code VARCHAR(100) NULL AFTER description");
}

// ── Auto purchase_no ──────────────────────────────────────────────────────────
$today   = date('Y-m-d');
$yy      = date('y');
$mm      = date('m');
$res     = $conn->query("SELECT COUNT(*) AS cnt FROM purchase_entries WHERE YEAR(purchase_date)=YEAR(CURDATE()) AND MONTH(purchase_date)=MONTH(CURDATE())");
$cnt     = $res ? ($res->fetch_assoc()['cnt'] + 1) : 1;
$auto_no = "PUR{$yy}{$mm}" . str_pad($cnt, 4, '0', STR_PAD_LEFT);

// ── POST handler ──────────────────────────────────────────────────────────────
$success_msg = $error_msg = '';
$last_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_purchase'])) {
    $pno   = $conn->real_escape_string(trim($_POST['purchase_no']));
    $pdate = $conn->real_escape_string($_POST['purchase_date']);
    $ino   = $conn->real_escape_string(trim($_POST['invoice_no']));
    $idate = $conn->real_escape_string($_POST['invoice_date']);
    $rno   = $conn->real_escape_string(trim($_POST['ref_no'] ?? ''));
    $rdate = !empty($_POST['ref_date']) ? "'".$conn->real_escape_string($_POST['ref_date'])."'" : 'NULL';
    $pmode = $conn->real_escape_string($_POST['payment_mode']);

    $sname  = $conn->real_escape_string(trim($_POST['supplier_name']));
    $saddr  = $conn->real_escape_string(trim($_POST['supplier_addr'] ?? ''));
    $sgstin = $conn->real_escape_string(trim($_POST['supplier_gstin'] ?? ''));
    $span   = $conn->real_escape_string(trim($_POST['supplier_pan'] ?? ''));
    $sstate = $conn->real_escape_string(trim($_POST['supplier_state'] ?? ''));
    $scode  = $conn->real_escape_string(trim($_POST['supplier_state_code'] ?? ''));
    $smob   = $conn->real_escape_string(trim($_POST['supplier_mobile'] ?? ''));
    $semail = $conn->real_escape_string(trim($_POST['supplier_email'] ?? ''));

    $bname  = $conn->real_escape_string(trim($_POST['buyer_name'] ?? 'MOTI JEWELLERS'));
    $baddr  = $conn->real_escape_string(trim($_POST['buyer_addr'] ?? ''));
    $bgstin = $conn->real_escape_string(trim($_POST['buyer_gstin'] ?? ''));
    $bpan   = $conn->real_escape_string(trim($_POST['buyer_pan'] ?? ''));

    $tax_type = $conn->real_escape_string($_POST['tax_type']);
    $cgst_p   = floatval($_POST['cgst_pct'] ?? 1.5);
    $sgst_p   = floatval($_POST['sgst_pct'] ?? 1.5);
    $igst_p   = floatval($_POST['igst_pct'] ?? 3.0);
    $notes    = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    $uid      = intval($_SESSION['user_id'] ?? 0);

    // Allow multiple items per purchase_no by dropping unique key if exists
    @$conn->query("ALTER TABLE purchase_entries DROP INDEX purchase_no");

    $items_raw = json_decode($_POST['purchase_items'] ?? '[]', true);
    if (empty($items_raw) || !is_array($items_raw)) {
        $items_raw = [[
            'material_type' => $_POST['material_type'] ?? 'Gold',
            'description'   => trim($_POST['description'] ?? ''),
            'huid_code'     => trim($_POST['huid_code'] ?? ''),
            'hsn_sac'       => trim($_POST['hsn_sac'] ?? ''),
            'unit'          => $_POST['unit'] ?? 'gm',
            'qty'           => floatval($_POST['qty'] ?? 0),
            'rate_per_unit' => floatval($_POST['rate_per_unit'] ?? 0)
        ]];
    }

    // Overall subtotal across all items
    $total_subtotal = 0;
    foreach ($items_raw as $it) {
        $total_subtotal += round(floatval($it['qty']) * floatval($it['rate_per_unit']), 2);
    }

    $overall_cgst = $overall_sgst = $overall_igst = 0;
    if ($tax_type === 'CGST_SGST') {
        $overall_cgst = round($total_subtotal * $cgst_p / 100, 2);
        $overall_sgst = round($total_subtotal * $sgst_p / 100, 2);
    } else {
        $overall_igst = round($total_subtotal * $igst_p / 100, 2);
    }
    $overall_gst_total = $overall_cgst + $overall_sgst + $overall_igst;
    $overall_grand_total = round($total_subtotal + $overall_gst_total, 2);
    $words = $conn->real_escape_string(numberToWords($overall_grand_total));

    $saved_count = 0;
    foreach ($items_raw as $it) {
        $mat   = $conn->real_escape_string($it['material_type']);
        $desc  = $conn->real_escape_string(trim($it['description']));
        $huid  = $conn->real_escape_string(trim($it['huid_code'] ?? ''));
        $hsn   = $conn->real_escape_string(trim($it['hsn_sac'] ?? ''));
        $qty   = floatval($it['qty']);
        $unit  = $conn->real_escape_string($it['unit'] ?? 'gm');
        $rate  = floatval($it['rate_per_unit']);
        $subtotal = round($qty * $rate, 2);

        $cgst_a = $sgst_a = $igst_a = 0;
        if ($tax_type === 'CGST_SGST') {
            $cgst_a = round($subtotal * $cgst_p / 100, 2);
            $sgst_a = round($subtotal * $sgst_p / 100, 2);
        } else {
            $igst_a = round($subtotal * $igst_p / 100, 2);
        }
        $gst_total = $cgst_a + $sgst_a + $igst_a;
        $total_amount = round($subtotal + $gst_total, 2);

        $sql = "INSERT INTO purchase_entries
            (purchase_no,purchase_date,invoice_no,invoice_date,ref_no,ref_date,payment_mode,
             supplier_name,supplier_addr,supplier_gstin,supplier_pan,supplier_state,supplier_state_code,supplier_mobile,supplier_email,
             buyer_name,buyer_addr,buyer_gstin,buyer_pan,
             material_type,description,huid_code,hsn_sac,qty,unit,rate_per_unit,
             tax_type,cgst_pct,sgst_pct,igst_pct,
             subtotal,cgst_amt,sgst_amt,igst_amt,gst_total,total_amount,amount_words,
             notes,created_by)
            VALUES
            ('$pno','$pdate','$ino','$idate','$rno',$rdate,'$pmode',
             '$sname','$saddr','$sgstin','$span','$sstate','$scode','$smob','$semail',
             '$bname','$baddr','$bgstin','$bpan',
             '$mat','$desc','$huid','$hsn',$qty,'$unit',$rate,
             '$tax_type',$cgst_p,$sgst_p,$igst_p,
             $subtotal,$cgst_a,$sgst_a,$igst_a,$gst_total,$total_amount,'$words',
             '$notes',$uid)";

        if ($conn->query($sql)) {
            $saved_count++;
            $last_id = $conn->insert_id;
            // update stock
            $conn->query("UPDATE stock_metal SET qty_available = qty_available + $qty WHERE material_type = '$mat'");
        }
    }

    if ($saved_count > 0) {
        $success_msg = "Purchase saved successfully! $saved_count item(s) added under Purchase No: $pno";
    } else {
        $error_msg = "Error: " . $conn->error;
    }
}

// ── Helper: number to words ───────────────────────────────────────────────────
function numberToWords($num) {
    $num = round($num, 2);
    $parts = explode('.', (string)$num);
    $rupees = intval($parts[0]);
    $paise  = isset($parts[1]) ? intval(str_pad($parts[1], 2, '0')) : 0;

    $ones  = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
               'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
               'Seventeen','Eighteen','Nineteen'];
    $tens  = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];

    function toWords($n, $ones, $tens) {
        if ($n === 0) return '';
        if ($n < 20) return $ones[$n] . ' ';
        if ($n < 100) return $tens[intval($n/10)] . ' ' . ($n%10 ? $ones[$n%10].' ' : '');
        return $ones[intval($n/100)] . ' Hundred ' . toWords($n%100, $ones, $tens);
    }

    $w = '';
    if ($rupees >= 10000000) { $w .= toWords(intval($rupees/10000000), $ones, $tens) . 'Crore '; $rupees %= 10000000; }
    if ($rupees >= 100000)   { $w .= toWords(intval($rupees/100000),   $ones, $tens) . 'Lakh ';  $rupees %= 100000; }
    if ($rupees >= 1000)     { $w .= toWords(intval($rupees/1000),     $ones, $tens) . 'Thousand '; $rupees %= 1000; }
    $w .= toWords($rupees, $ones, $tens);
    $result = trim($w) . ' Rupees';
    if ($paise > 0) $result .= ' and ' . trim(toWords($paise, $ones, $tens)) . ' Paise';
    return $result . ' Only';
}

// ── HSN defaults ──────────────────────────────────────────────────────────────
$hsn_defaults = ['Gold'=>'71081200','Silver'=>'71069100','Diamond'=>'71023100','Platinum'=>'71101100'];
$unit_defaults = ['Gold'=>'gm','Silver'=>'gm','Diamond'=>'ct','Platinum'=>'gm'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="author" content="MANU GUPTA">
<title>Purchase Entry | MOTI JEWELLERS</title>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
*{font-family:'Poppins',sans-serif;box-sizing:border-box;}
h1,h2,h3,.gold-font{font-family:'Poppins',sans-serif;font-weight:700;}

/* ── Sidebar (same as all pages) ── */
.sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg, #011921 0%, #03373b 50%, #044e54 80%, #011921 100%);z-index:1000;display:flex;flex-direction:column;box-shadow:4px 0 24px rgba(0,0,0,0.25);transition:transform .35s cubic-bezier(.4,0,.2,1);overflow:hidden;}
.sidebar-nav::-webkit-scrollbar{width:4px;}.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.2);border-radius:4px;}
.sidebar-logo{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,0.18);display:flex;align-items:center;gap:12px;flex-shrink:0;}
.sidebar-logo img{width:44px;height:44px;object-fit:contain;border-radius:50%;background:rgba(255,255,255,0.1);padding:3px;flex-shrink:0;}
.sidebar-logo-text h2{color:#fff;font-size:13px;font-weight:700;font-family:'Poppins',serif;letter-spacing:.5px;}
.sidebar-logo-text p{color:rgba(255,255,255,0.65);font-size:10px;margin-top:1px;}
.sidebar-nav{flex:1;padding:10px 0;overflow-y:auto;overflow-x:hidden;}
.sidebar-section-label{padding:10px 20px 4px;color:rgba(255,255,255,0.45);font-size:9px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;position:sticky;top:0;background: #011921; color: #f5c842;z-index:10;}
.sidebar-nav a{display:flex;align-items:center;gap:12px;padding:11px 20px;color:rgba(255,255,255,0.85);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;border-left:3px solid transparent;position:relative;}
.sidebar-nav a:hover{background:rgba(255,255,255,0.13);color:#fff;border-left-color:rgba(255,255,255,0.8);padding-left:26px;}
.sidebar-nav a.active{background:rgba(255,255,255,0.22);color:#fff;border-left-color:#fff;font-weight:700;}
.sidebar-nav a i{width:18px;text-align:center;font-size:14px;flex-shrink:0;}
.sidebar-divider{height:1px;background:rgba(255,255,255,0.12);margin:6px 16px;}
.sidebar-user{padding:14px 16px 18px;border-top:1px solid rgba(255,255,255,0.18);background:rgba(0,0,0,0.12);flex-shrink:0;}
.sidebar-user-info{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.sidebar-user-info i{color:rgba(255,255,255,0.9);font-size:26px;}
.sidebar-user-info .user-details p{color:#fff;font-size:12px;font-weight:600;}
.sidebar-user-info .user-details span{color:rgba(255,255,255,0.55);font-size:10px;}
.sidebar-logout,.sidebar-login-btn{display:flex;align-items:center;justify-content:center;gap:8px;padding:9px 14px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;transition:background .2s;border:1px solid rgba(255,255,255,0.3);}
.sidebar-logout{background:rgba(239,68,68,0.75);color:#fff;border-color:rgba(239,68,68,0.4);}
.sidebar-logout:hover{background:#ef4444;}
.sidebar-login-btn{background:rgba(255,255,255,0.2);color:#fff;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block;}
.page-wrapper{margin-left:240px;min-height:100vh;}
nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important;}
nav.nav-gold span{color:#fff!important;}
.burger-menu{width:28px;height:20px;position:relative;cursor:pointer;}
.burger-menu span{display:block;position:absolute;height:3px;width:100%;background:#fff;border-radius:3px;transition:all .3s;}
.burger-menu span:nth-child(1){top:0}
.burger-menu span:nth-child(2){top:9px}
.burger-menu span:nth-child(3){top:18px}
@media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.page-wrapper{margin-left:0!important}.mobile-burger{display:block!important}}
@media(min-width:769px){.mobile-burger{display:none!important}}

/* ── Form styles ── */
.form-card{background:#fff;border-radius:20px;box-shadow:0 8px 40px rgba(0,0,0,0.08);border:1px solid rgba(214,139,22,0.12);padding:28px;}
.section-heading{font-family:'Poppins',serif;color:#800020;font-size:15px;font-weight:700;border-bottom:2px solid rgba(214,139,22,0.25);padding-bottom:8px;margin-bottom:18px;letter-spacing:.3px;}
.field-label{display:block;color:#7a4e0a;font-size:12px;font-weight:600;margin-bottom:5px;letter-spacing:.3px;}
.field-label .req{color:#dc2626;}
.form-input{width:100%;padding:11px 14px;border-radius:12px;border:1.5px solid rgba(148,163,184,0.3);background:#fbfaf8;color:#334155;font-size:13px;outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:#d68b16;box-shadow:0 0 0 3px rgba(214,139,22,0.12);}
.form-input[readonly]{background:#f0ede8;color:#7a6a55;}
.form-select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23d68b16' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;}
.tax-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:10px;font-size:12px;font-weight:600;cursor:pointer;border:2px solid transparent;transition:all .2s;}
.tax-badge.active-cgst{background:#fef3c7;border-color:#d68b16;color:#7a4e0a;}
.tax-badge.active-igst{background:#e0f2fe;border-color:#0284c7;color:#0c4a6e;}
.tax-badge.inactive{background:#f8f8f8;border-color:#e2e8f0;color:#94a3b8;}
.amount-box{background:linear-gradient(135deg,#fdf6e3,#fff9ed);border:1.5px solid rgba(214,139,22,0.2);border-radius:14px;padding:18px 20px;}
.amount-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;}
.amount-row.total{border-top:2px solid #d68b16;margin-top:8px;padding-top:12px;font-size:16px;font-weight:700;color:#800020;}
.btn-save{background:linear-gradient(135deg,#800020,#d68b16);color:#fff;border:none;border-radius:50px;padding:14px 40px;font-size:15px;font-weight:700;cursor:pointer;transition:all .3s;letter-spacing:.5px;}
.btn-save:hover{transform:scale(1.04);box-shadow:0 10px 30px rgba(214,139,22,0.35);}
.btn-pdf{background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;border-radius:50px;padding:14px 36px;font-size:15px;font-weight:700;cursor:pointer;transition:all .3s;}
.btn-pdf:hover{transform:scale(1.04);box-shadow:0 10px 25px rgba(59,130,246,0.3);}
.alert-success{background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;border-radius:12px;padding:14px 18px;font-size:13px;font-weight:600;}
.alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;border-radius:12px;padding:14px 18px;font-size:13px;font-weight:600;}
.mat-btn{padding:10px 18px;border-radius:50px;font-size:13px;font-weight:600;cursor:pointer;border:2px solid transparent;transition:all .2s;}
.mat-btn.sel-Gold{background:#fef3c7;border-color:#d68b16;color:#7a4e0a;}
.mat-btn.sel-Silver{background:#f1f5f9;border-color:#64748b;color:#1e293b;}
.mat-btn.sel-Diamond{background:#ede9fe;border-color:#7c3aed;color:#4c1d95;}
.mat-btn.sel-Platinum{background:#f0fdf4;border-color:#16a34a;color:#14532d;}
.mat-btn.inactive-mat{background:#f8f8f8;border-color:#e2e8f0;color:#94a3b8;}

/* ── Print/PDF hidden ── */
@media print{.sidebar,.nav-gold,.no-print{display:none!important}.page-wrapper{margin-left:0!important}}
</style>
</head>
<body style="background:#F5F5F5;margin:0;padding:0;">

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_paths=['logo.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
        $found=false;
        foreach($logo_paths as $p){if(file_exists($p)){echo '<img src="'.$p.'" alt="Logo">';$found=true;break;}}
        if(!$found) echo '<i class="fas fa-gem" style="color:#fff;font-size:30px;"></i>';
        ?>
        <div class="sidebar-logo-text"><h2>MOTI JEWELLERS</h2><p>Premium Since 2026</p></div>
    </div>
    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>
        <a href="index.php"><i class="fas fa-home"></i> HOME</a>
        <a href="billing.php"><i class="fas fa-receipt"></i> BILLING</a>
        <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
        <a href="customers.php"><i class="fas fa-users"></i> CUSTOMERS</a>
        <a href="sanchari_dashboard.php">
            <i class="fas fa-piggy-bank"></i> SANCHAY SCHEME
        </a>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
        <a href="due_list.php"><i class="fas fa-hourglass-half"></i> DUE LIST</a>
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="purchase.php" class="active"><i class="fas fa-shopping-cart"></i> PURCHASE</a>
        <a href="contacts.php"><i class="fas fa-address-book"></i> CONTACTS</a>
        <a href="accounts.php"><i class="fas fa-calculator"></i> ACCOUNTS</a>
    </nav>
    <div class="sidebar-user">
        <?php if($is_logged_in): ?>
        <div class="sidebar-user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <p><?=htmlspecialchars($_SESSION['user_name'])?></p>
                <span><?=htmlspecialchars($_SESSION['user_mobile']??'Admin')?></span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i> LOGOUT</a>
        <?php else: ?>
        <a href="login.php" class="sidebar-login-btn"><i class="fas fa-sign-in-alt"></i> LOGIN</a>
        <?php endif; ?>
    </div>
</div>

<!-- TOPNAV -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 py-3 flex justify-between items-center">
        <h1 style="color:#fff;font-family:'Poppins',serif;font-size:18px;font-weight:700;">
            <i class="fas fa-shopping-cart mr-2"></i>Purchase Entry
        </h1>
        <div class="flex items-center gap-4">
            <?php if($is_logged_in): ?>
            <span class="text-sm font-medium" style="color:#fff;"><i class="fas fa-user mr-1"></i><?=htmlspecialchars($_SESSION['user_name'])?></span>
            <?php endif; ?>
            <div class="mobile-burger" style="display:none;">
                <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="page-wrapper">
<div class="container mx-auto px-4 py-8" style="max-width:960px;">

<?php if($success_msg): ?>
<div class="alert-success mb-6 no-print"><i class="fas fa-check-circle mr-2"></i><?=$success_msg?>
    <button onclick="triggerPDF()" class="ml-4 underline text-green-800 font-bold"><i class="fas fa-file-pdf mr-1"></i>Download PDF</button>
</div>
<?php endif; ?>
<?php if($error_msg): ?>
<div class="alert-error mb-6 no-print"><i class="fas fa-exclamation-circle mr-2"></i><?=$error_msg?></div>
<?php endif; ?>

<form method="POST" id="purchaseForm" onsubmit="return preparePurchaseSubmit()">
<input type="hidden" name="save_purchase" value="1">

<!-- ── PURCHASE META ─────────────────────────────────────────────────────── -->
<div class="form-card mb-6">
    <div class="section-heading"><i class="fas fa-file-invoice mr-2"></i>Purchase / Invoice Details</div>
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div>
            <label class="field-label">Purchase No <span class="req">*</span></label>
            <input name="purchase_no" id="purchase_no" class="form-input" value="<?=$auto_no?>" required>
        </div>
        <div>
            <label class="field-label">Purchase Date <span class="req">*</span></label>
            <input type="date" name="purchase_date" class="form-input" value="<?=date('Y-m-d')?>" required>
        </div>
        <div>
            <label class="field-label">Invoice No <span class="req">*</span></label>
            <input name="invoice_no" class="form-input" placeholder="LTC26-27/9" required>
        </div>
        <div>
            <label class="field-label">Invoice Date <span class="req">*</span></label>
            <input type="date" name="invoice_date" class="form-input" value="<?=date('Y-m-d')?>" required>
        </div>
        <div>
            <label class="field-label">Reference No</label>
            <input name="ref_no" class="form-input" placeholder="Ref. No.">
        </div>
        <div>
            <label class="field-label">Reference Date</label>
            <input type="date" name="ref_date" class="form-input">
        </div>
        <div class="col-span-2">
            <label class="field-label">Payment Mode</label>
            <select name="payment_mode" class="form-input form-select">
                <option value="NEFT/RTGS">NEFT / RTGS</option>
                <option value="Cash">Cash</option>
                <option value="Cheque">Cheque</option>
                <option value="UPI">UPI</option>
                <option value="Bank Transfer">Bank Transfer</option>
            </select>
        </div>
    </div>
</div>

<!-- ── SUPPLIER ──────────────────────────────────────────────────────────── -->
<div class="form-card mb-6">
    <div class="section-heading"><i class="fas fa-store mr-2"></i>Supplier (Seller) Details</div>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="field-label">Supplier Name <span class="req">*</span></label>
            <input name="supplier_name" class="form-input" placeholder="e.g. Laxmi Tonch Centre" required>
        </div>
        <div>
            <label class="field-label">Mobile</label>
            <input name="supplier_mobile" class="form-input" placeholder="8178973839">
        </div>
        <div class="md:col-span-2">
            <label class="field-label">Address</label>
            <input name="supplier_addr" class="form-input" placeholder="Vill+P.O., District, State, PIN">
        </div>
        <div>
            <label class="field-label">GSTIN</label>
            <input name="supplier_gstin" class="form-input" placeholder="19IPSPM8896B1ZT" maxlength="15" style="text-transform:uppercase;">
        </div>
        <div>
            <label class="field-label">PAN</label>
            <input name="supplier_pan" class="form-input" placeholder="IPSPM8896B" maxlength="10" style="text-transform:uppercase;">
        </div>
        <div>
            <label class="field-label">State</label>
            <input name="supplier_state" class="form-input" placeholder="West Bengal">
        </div>
        <div>
            <label class="field-label">State Code</label>
            <input name="supplier_state_code" class="form-input" placeholder="19" maxlength="3">
        </div>
        <div>
            <label class="field-label">Email</label>
            <input type="email" name="supplier_email" class="form-input" placeholder="supplier@email.com">
        </div>
    </div>
</div>

<!-- ── BUYER ─────────────────────────────────────────────────────────────── -->
<div class="form-card mb-6">
    <div class="section-heading"><i class="fas fa-building mr-2"></i>Buyer Details (Our Shop)</div>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="field-label">Buyer Name</label>
            <input name="buyer_name" class="form-input" value=" MOTI JEWELLERS">
        </div>
        <div>
            <label class="field-label">GSTIN</label>
            <input name="buyer_gstin" class="form-input" placeholder="Our GSTIN" style="text-transform:uppercase;">
        </div>
        <div class="md:col-span-2">
            <label class="field-label">Address</label>
            <input name="buyer_addr" class="form-input" placeholder="Hamirpur, Debra, Kharagpur, Paschim Medinipur">
        </div>
        <div>
            <label class="field-label">PAN</label>
            <input name="buyer_pan" class="form-input" placeholder="Our PAN" style="text-transform:uppercase;">
        </div>
    </div>
</div>

<!-- ── MATERIAL ───────────────────────────────────────────────────────────── -->
<div class="form-card mb-6">
    <div class="section-heading"><i class="fas fa-gem mr-2"></i>Material & Item Details</div>

    <!-- Material type selector -->
    <div class="mb-5">
        <label class="field-label mb-3">Material Type <span class="req">*</span></label>
        <div class="flex flex-wrap gap-3" id="matBtns">
            <?php foreach(['Gold','Silver','Diamond','Platinum'] as $m): ?>
            <button type="button" class="mat-btn inactive-mat" onclick="selectMat('<?=$m?>')" id="matBtn_<?=$m?>">
                <?php
                $icons=['Gold'=>'⭐','Silver'=>'🥈','Diamond'=>'💎','Platinum'=>'⚪'];
                echo $icons[$m].' '.$m;
                ?>
            </button>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="material_type" id="material_type" value="Gold">
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="md:col-span-2">
            <label class="field-label">Description of Goods <span class="req">*</span></label>
            <input name="description" id="desc_field" class="form-input" placeholder="e.g. Pour Gold / Silver Bar / Diamond">
        </div>
        <div>
            <label class="field-label">HUID Code <span style="color:#9ca3af;font-weight:400;">(Optional)</span></label>
            <input type="text" name="huid_code" id="manualhuid" placeholder="e.g. F108D" class="form-input">
        </div>
        <div>
            <label class="field-label">HSN / SAC Code</label>
            <input name="hsn_sac" id="hsn_field" class="form-input" placeholder="71081200">
        </div>
        <div>
            <label class="field-label">Unit</label>
            <select name="unit" id="unit_field" class="form-input form-select">
                <option value="gm">gm (Gram)</option>
                <option value="kg">kg (Kilogram)</option>
                <option value="ct">ct (Carat)</option>
                <option value="pcs">pcs (Pieces)</option>
            </select>
        </div>
        <div>
            <label class="field-label">Quantity <span class="req">*</span></label>
            <input type="number" name="qty" id="qty" class="form-input" placeholder="e.g. 30" step="0.0001" min="0">
        </div>
        <div>
            <label class="field-label">Rate per Unit (₹) <span class="req">*</span></label>
            <input type="number" name="rate_per_unit" id="rate" class="form-input" placeholder="e.g. 15762.14" step="0.01" min="0">
        </div>
        <div class="md:col-span-2">
            <label class="field-label">GST Rate for this Item</label>
            <select id="item_gst_pct" class="form-input form-select">
                <option value="3" selected>3% GST (Standard Gold / Jewellery Rate)</option>
                <option value="0">0% (Non-GST / Exempt)</option>
                <option value="1.5">1.5% GST</option>
                <option value="5">5% GST</option>
                <option value="12">12% GST</option>
                <option value="18">18% GST (Diamond / Making Rate)</option>
                <option value="28">28% GST</option>
            </select>
        </div>
    </div>

    <!-- Add Item Button -->
    <div class="mt-4">
        <button type="button" onclick="addPurchaseItem()" class="w-full py-3 rounded-xl text-sm font-bold flex items-center justify-center gap-2 shadow-md" style="background:linear-gradient(135deg, #ffd700 0%, #b5730e 100%);color:#022c22;border:none;cursor:pointer;">
            <i class="fas fa-plus-circle"></i> Add Item to Purchase List
        </button>
    </div>

    <!-- Purchase Items Table -->
    <div class="mt-6 overflow-x-auto">
        <div id="purchaseNotifMsg" class="mb-3 p-2.5 rounded-lg text-xs font-bold bg-green-100 text-green-800 border border-green-300 hidden"></div>
        <label class="field-label mb-2 font-bold" style="color:#7a4e0a;"><i class="fas fa-list-ul mr-1"></i> Purchase Items List</label>
        <table class="w-full text-left border-collapse rounded-xl overflow-hidden shadow-sm" style="border:1px solid rgba(214,139,22,0.25);">
            <thead>
                <tr style="background:rgba(214,139,22,0.15);color:#7a4e0a;font-size:12px;">
                    <th class="px-3 py-2 text-center" style="width:35px;">#</th>
                    <th class="px-3 py-2">Material & Description</th>
                    <th class="px-3 py-2 text-center">Qty & Unit</th>
                    <th class="px-3 py-2 text-right">Rate / Unit</th>
                    <th class="px-3 py-2 text-right">Subtotal</th>
                    <th class="px-3 py-2 text-right">GST Rate & Amt</th>
                    <th class="px-3 py-2 text-right">Net Total</th>
                    <th class="px-3 py-2 text-center" style="width:50px;">Action</th>
                </tr>
            </thead>
            <tbody id="purchaseItemsList">
                <tr id="emptyPurchaseRow">
                    <td colspan="8" class="text-center py-4 text-gray-400 text-xs">
                        No items added to purchase list yet — fill details above and click "Add Item to Purchase List"
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Confirm All Items Section -->
    <div class="mt-4 flex flex-col sm:flex-row gap-3 items-center justify-between p-3.5 rounded-xl" style="background:rgba(214,139,22,0.08);border:1px solid rgba(214,139,22,0.25);">
        <div>
            <span class="text-xs font-bold text-amber-900"><i class="fas fa-check-double mr-1"></i> Confirm Items List</span>
            <p class="text-xs text-amber-700">Click to confirm all added products and review final totals below.</p>
        </div>
        <button type="button" onclick="confirmAllPurchaseItems()" class="py-2.5 px-5 rounded-xl text-xs font-bold shadow-md flex items-center gap-2" style="background:#059669;color:#fff;border:none;cursor:pointer;">
            <i class="fas fa-check-circle"></i> Confirm All Items
        </button>
    </div>

    <!-- Confirmed Summary Box -->
    <div id="confirmSummaryBox" class="mt-3 p-4 rounded-xl hidden" style="background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;">
        <div class="flex justify-between items-center mb-2">
            <strong class="text-sm"><i class="fas fa-check-circle mr-1"></i> All Items Confirmed!</strong>
            <span class="text-xs font-bold px-2 py-0.5 rounded bg-green-200 text-green-900" id="confirmCountBadge">0 Items</span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
            <div>Items Count: <strong id="confirmItemCount">0</strong></div>
            <div>Subtotal: <strong id="confirmSubtotal">₹0.00</strong></div>
            <div>Total GST: <strong id="confirmTotalGst">₹0.00</strong></div>
            <div>Grand Total: <strong id="confirmGrandTotal" class="text-sm text-green-900 font-extrabold">₹0.00</strong></div>
        </div>
    </div>

    <input type="hidden" name="purchase_items" id="hiddenPurchaseItems" value="[]">
</div>

<!-- ── TAX ───────────────────────────────────────────────────────────────── -->
<div class="form-card mb-6">
    <div class="section-heading"><i class="fas fa-percent mr-2"></i>GST / Tax Details</div>

    <div class="flex gap-3 mb-5 flex-wrap">
        <div class="tax-badge active-cgst" id="badge_cgst" onclick="setTax('CGST_SGST')">
            <i class="fas fa-check-circle"></i> CGST + SGST <small>(Intra-State)</small>
        </div>
        <div class="tax-badge inactive" id="badge_igst" onclick="setTax('IGST')">
            <i class="fas fa-circle"></i> IGST <small>(Inter-State)</small>
        </div>
    </div>
    <input type="hidden" name="tax_type" id="tax_type" value="CGST_SGST">

    <div id="cgst_row" class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div>
            <label class="field-label">CGST %</label>
            <input type="number" name="cgst_pct" id="cgst_pct" class="form-input" value="1.50" step="0.01" oninput="calcAmounts()">
        </div>
        <div>
            <label class="field-label">SGST %</label>
            <input type="number" name="sgst_pct" id="sgst_pct" class="form-input" value="1.50" step="0.01" oninput="calcAmounts()">
        </div>
        <div>
            <label class="field-label">CGST Amount</label>
            <input id="cgst_amt_display" class="form-input" readonly placeholder="0.00">
        </div>
        <div>
            <label class="field-label">SGST Amount</label>
            <input id="sgst_amt_display" class="form-input" readonly placeholder="0.00">
        </div>
    </div>
    <div id="igst_row" class="grid grid-cols-2 gap-4 md:grid-cols-4 hidden">
        <div>
            <label class="field-label">IGST %</label>
            <input type="number" name="igst_pct" id="igst_pct" class="form-input" value="3.00" step="0.01" oninput="calcAmounts()">
        </div>
        <div>
            <label class="field-label">IGST Amount</label>
            <input id="igst_amt_display" class="form-input" readonly placeholder="0.00">
        </div>
    </div>
</div>

<!-- ── AMOUNT SUMMARY ─────────────────────────────────────────────────────── -->
<div class="form-card mb-6">
    <div class="section-heading"><i class="fas fa-rupee-sign mr-2"></i>Amount Summary</div>
    <div class="amount-box">
        <div class="amount-row"><span style="color:#555;">Subtotal (Qty × Rate)</span><span id="disp_subtotal" style="font-weight:600;">₹ 0.00</span></div>
        <div class="amount-row" id="disp_cgst_row"><span style="color:#555;">CGST</span><span id="disp_cgst">₹ 0.00</span></div>
        <div class="amount-row" id="disp_sgst_row"><span style="color:#555;">SGST</span><span id="disp_sgst">₹ 0.00</span></div>
        <div class="amount-row hidden" id="disp_igst_row"><span style="color:#555;">IGST</span><span id="disp_igst">₹ 0.00</span></div>
        <div class="amount-row"><span style="color:#555;">GST Total</span><span id="disp_gst_total" style="font-weight:600;">₹ 0.00</span></div>
        <div class="amount-row total"><span>TOTAL AMOUNT</span><span id="disp_total">₹ 0.00</span></div>
        <div class="mt-3 pt-3 border-t" style="border-color:rgba(214,139,22,0.2);">
            <span style="color:#7a4e0a;font-size:12px;font-weight:600;">In Words: </span>
            <span id="disp_words" style="color:#334155;font-size:12px;font-style:italic;">Zero Rupees Only</span>
        </div>
    </div>
</div>

<!-- ── NOTES ─────────────────────────────────────────────────────────────── -->
<div class="form-card mb-8">
    <div class="section-heading"><i class="fas fa-sticky-note mr-2"></i>Notes / Remarks</div>
    <textarea name="notes" class="form-input" rows="3" placeholder="Any additional notes..."></textarea>
</div>

<!-- ── ACTIONS ───────────────────────────────────────────────────────────── -->
<div class="flex flex-wrap gap-4 justify-center mb-10 no-print">
    <button type="submit" class="btn-save"><i class="fas fa-save mr-2"></i>SAVE PURCHASE</button>
    <button type="button" class="btn-pdf" onclick="generatePDF()"><i class="fas fa-file-pdf mr-2"></i>DOWNLOAD PDF</button>
    <a href="purchase_history.php" style="background:linear-gradient(135deg,#374151,#6b7280);color:#fff;border:none;border-radius:50px;padding:14px 36px;font-size:15px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
        <i class="fas fa-history"></i>HISTORY
    </a>
</div>

</form>
</div><!-- /container -->
<footer style="background:linear-gradient(0deg,#f5e6c8,#fdf6e3);border-top:2px solid #d68b16;padding:20px;margin-top:40px;text-align:center;">
    <p class="text-xs" style="color:#7a4e0a;">
        &copy; 2026 MOTI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
        Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology</a>
    </p>
</footer>
</div><!-- /page-wrapper -->

<script>
// ── Sidebar ───────────────────────────────────────────────────────────────────
function toggleSidebar(){
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
function closeSidebar(){
    document.getElementById('mainSidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
}

// ── Multi-Item Purchase Cart Global State ──────────────────────────────────────
var purchaseItems = [];

// ── Material selector ─────────────────────────────────────────────────────────
const hsnMap  = {Gold:'71081200',Silver:'71069100',Diamond:'71023100',Platinum:'71101100'};
const unitMap = {Gold:'gm',Silver:'gm',Diamond:'ct',Platinum:'gm'};
const descMap = {Gold:'Pour Gold',Silver:'Silver Bar',Diamond:'Diamond (Natural)',Platinum:'Platinum Bar'};

function selectMat(mat) {
    document.getElementById('material_type').value = mat;
    document.getElementById('hsn_field').value   = "";
    document.getElementById('desc_field').value  = descMap[mat];
    const us = document.getElementById('unit_field');
    for(let i=0;i<us.options.length;i++){if(us.options[i].value===unitMap[mat]){us.selectedIndex=i;break;}}
    document.querySelectorAll('.mat-btn').forEach(b=>b.className='mat-btn inactive-mat');
    document.getElementById('matBtn_'+mat).className='mat-btn sel-'+mat;
    calcAmounts();
}
// default Gold selected
selectMat('Gold');

// ── Tax toggle ────────────────────────────────────────────────────────────────
function setTax(type) {
    document.getElementById('tax_type').value = type;
    if(type === 'CGST_SGST'){
        document.getElementById('badge_cgst').className='tax-badge active-cgst';
        document.getElementById('badge_igst').className='tax-badge inactive';
        document.getElementById('cgst_row').classList.remove('hidden');
        document.getElementById('igst_row').classList.add('hidden');
        document.getElementById('disp_cgst_row').classList.remove('hidden');
        document.getElementById('disp_sgst_row').classList.remove('hidden');
        document.getElementById('disp_igst_row').classList.add('hidden');
    } else {
        document.getElementById('badge_igst').className='tax-badge active-igst';
        document.getElementById('badge_cgst').className='tax-badge inactive';
        document.getElementById('igst_row').classList.remove('hidden');
        document.getElementById('cgst_row').classList.add('hidden');
        document.getElementById('disp_igst_row').classList.remove('hidden');
        document.getElementById('disp_cgst_row').classList.add('hidden');
        document.getElementById('disp_sgst_row').classList.add('hidden');
    }
    calcAmounts();
}

function htmlEsc(str) {
    if(!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function addPurchaseItem() {
    try {
        const matEl  = document.getElementById('material_type');
        const descEl = document.getElementById('desc_field');
        const huidEl = document.getElementById('manualhuid');
        const hsnEl  = document.getElementById('hsn_field');
        const unitEl = document.getElementById('unit_field');
        const qtyEl  = document.getElementById('qty');
        const rateEl = document.getElementById('rate');
        const gstEl  = document.getElementById('item_gst_pct');

        const mat  = matEl ? matEl.value : 'Gold';
        const desc = descEl ? descEl.value.trim() : '';
        const huid = huidEl ? huidEl.value.trim() : '';
        const hsn  = hsnEl ? hsnEl.value.trim() : '';
        const unit = unitEl ? unitEl.value : 'gm';
        const qty  = parseFloat(qtyEl ? qtyEl.value : 0) || 0;
        const rate = parseFloat(rateEl ? rateEl.value : 0) || 0;
        const gstPct = parseFloat(gstEl ? gstEl.value : 3) || 0;

        if (!desc) { alert('Please enter Description of Goods.'); if(descEl) descEl.focus(); return; }
        if (qty <= 0) { alert('Please enter a valid Quantity.'); if(qtyEl) qtyEl.focus(); return; }
        if (rate <= 0) { alert('Please enter Rate per Unit.'); if(rateEl) rateEl.focus(); return; }

        const baseSubtotal = Math.round(qty * rate * 100) / 100;
        const itemGstAmt   = Math.round(baseSubtotal * gstPct / 100 * 100) / 100;
        const itemTotal    = Math.round((baseSubtotal + itemGstAmt) * 100) / 100;

        purchaseItems.push({
            material_type: mat,
            description: desc,
            huid_code: huid,
            hsn_sac: hsn,
            unit: unit,
            qty: qty,
            rate_per_unit: rate,
            subtotal: baseSubtotal,
            gst_pct: gstPct,
            gst_amount: itemGstAmt,
            total_amount: itemTotal
        });

        // Reset item input fields for quick entry of next item
        if (descEl) descEl.value = '';
        if (huidEl) huidEl.value = '';
        if (qtyEl)  qtyEl.value = '';
        if (rateEl) rateEl.value = '';

        renderPurchaseItems();
        calcAmounts();

        const notif = document.getElementById('purchaseNotifMsg');
        if (notif) {
            notif.innerHTML = '✅ Added <strong>' + htmlEsc(desc) + '</strong> (' + qty + ' ' + htmlEsc(unit) + ' @ \u20B9' + fmt(rate) + ' | GST: ' + gstPct + '%) to purchase list!';
            notif.classList.remove('hidden');
            setTimeout(() => notif.classList.add('hidden'), 3500);
        }
    } catch(err) {
        console.error("Error in addPurchaseItem:", err);
        alert("Error adding item: " + err.message);
    }
}

function removePurchaseItem(index) {
    purchaseItems.splice(index, 1);
    renderPurchaseItems();
    calcAmounts();
}

function renderPurchaseItems() {
    const tbody = document.getElementById('purchaseItemsList');
    if (!tbody) return;

    if (purchaseItems.length === 0) {
        tbody.innerHTML = '<tr id="emptyPurchaseRow"><td colspan="8" class="text-center py-4 text-gray-400 text-xs">No items added to purchase list yet — fill details above and click "Add Item to Purchase List"</td></tr>';
        document.getElementById('hiddenPurchaseItems').value = '[]';
        return;
    }

    let html = '';
    purchaseItems.forEach((it, idx) => {
        const gstPctDisp = (it.gst_pct !== undefined) ? it.gst_pct : 3;
        const gstAmtDisp = (it.gst_amount !== undefined) ? it.gst_amount : Math.round(it.subtotal * gstPctDisp / 100 * 100)/100;
        const itemTotDisp = (it.total_amount !== undefined) ? it.total_amount : Math.round((it.subtotal + gstAmtDisp)*100)/100;

        html += '<tr class="border-b text-xs text-gray-700">' +
            '<td class="px-3 py-2 text-center font-medium">' + (idx + 1) + '</td>' +
            '<td class="px-3 py-2">' +
                '<strong style="color:#022c22;">' + htmlEsc(it.description) + '</strong> ' +
                '<span class="text-xs px-1.5 py-0.5 rounded ml-1" style="background:rgba(214,139,22,0.15);color:#7a4e0a;font-weight:600;">' + htmlEsc(it.material_type) + '</span>' +
                (it.huid_code ? '<div class="text-gray-500" style="font-size:10px;">HUID: ' + htmlEsc(it.huid_code) + '</div>' : '') +
                (it.hsn_sac ? '<div class="text-gray-400" style="font-size:10px;">HSN: ' + htmlEsc(it.hsn_sac) + '</div>' : '') +
            '</td>' +
            '<td class="px-3 py-2 text-center font-semibold">' + it.qty + ' ' + htmlEsc(it.unit) + '</td>' +
            '<td class="px-3 py-2 text-right">\u20B9' + fmt(it.rate_per_unit) + '</td>' +
            '<td class="px-3 py-2 text-right font-bold text-amber-900">\u20B9' + fmt(it.subtotal) + '</td>' +
            '<td class="px-3 py-2 text-right text-blue-700 font-semibold">' + gstPctDisp + '% (\u20B9' + fmt(gstAmtDisp) + ')</td>' +
            '<td class="px-3 py-2 text-right font-bold text-green-900">\u20B9' + fmt(itemTotDisp) + '</td>' +
            '<td class="px-3 py-2 text-center">' +
                '<button type="button" onclick="removePurchaseItem(' + idx + ')" class="text-red-600 hover:text-red-800 font-bold text-sm px-2 py-0.5 rounded hover:bg-red-50">\u2715</button>' +
            '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
    document.getElementById('hiddenPurchaseItems').value = JSON.stringify(purchaseItems);
}

function confirmAllPurchaseItems() {
    if (purchaseItems.length === 0) {
        alert('Please add at least one item to the purchase list first.');
        return;
    }
    renderPurchaseItems();
    calcAmounts();

    const sub = purchaseItems.reduce((sum, item) => sum + item.subtotal, 0);
    const gst = purchaseItems.reduce((sum, item) => sum + ((item.gst_amount !== undefined) ? item.gst_amount : Math.round(item.subtotal * (item.gst_pct || 3)/100 * 100)/100), 0);
    const grand = Math.round((sub + gst) * 100)/100;

    const countEl = document.getElementById('confirmItemCount');
    const badgeEl = document.getElementById('confirmCountBadge');
    const subEl   = document.getElementById('confirmSubtotal');
    const gstEl   = document.getElementById('confirmTotalGst');
    const grandEl = document.getElementById('confirmGrandTotal');

    if(countEl) countEl.textContent = purchaseItems.length;
    if(badgeEl) badgeEl.textContent = purchaseItems.length + ' Items Confirmed';
    if(subEl)   subEl.textContent   = '₹ ' + fmt(sub);
    if(gstEl)   gstEl.textContent   = '₹ ' + fmt(gst);
    if(grandEl) grandEl.textContent = '₹ ' + fmt(grand);

    const box = document.getElementById('confirmSummaryBox');
    if (box) {
        box.classList.remove('hidden');
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function preparePurchaseSubmit() {
    if (purchaseItems.length === 0) {
        const desc = document.getElementById('desc_field').value.trim();
        const qty  = parseFloat(document.getElementById('qty').value) || 0;
        const rate = parseFloat(document.getElementById('rate').value) || 0;
        if (desc && qty > 0 && rate > 0) {
            addPurchaseItem();
        } else {
            alert('Please add at least one item to the purchase list before saving.');
            return false;
        }
    }
    document.getElementById('hiddenPurchaseItems').value = JSON.stringify(purchaseItems);
    return true;
}

// ── Amount calculations ────────────────────────────────────────────────────────
function calcAmounts(){
    let sub = 0;
    let totalItemGst = 0;
    
    if (purchaseItems.length > 0) {
        sub = purchaseItems.reduce((sum, item) => sum + item.subtotal, 0);
        totalItemGst = purchaseItems.reduce((sum, item) => sum + ((item.gst_amount !== undefined) ? item.gst_amount : Math.round(item.subtotal * (item.gst_pct || 3)/100 * 100)/100), 0);
    } else {
        const qty   = parseFloat(document.getElementById('qty').value) || 0;
        const rate  = parseFloat(document.getElementById('rate').value) || 0;
        const gstP  = parseFloat(document.getElementById('item_gst_pct')?.value) || 3;
        sub = Math.round(qty * rate * 100) / 100;
        totalItemGst = Math.round(sub * gstP / 100 * 100) / 100;
    }

    const taxT = document.getElementById('tax_type').value;
    let cgst = 0, sgst = 0, igst = 0;
    if (taxT === 'CGST_SGST') {
        cgst = Math.round(totalItemGst / 2 * 100) / 100;
        sgst = Math.round(totalItemGst / 2 * 100) / 100;
    } else {
        igst = totalItemGst;
    }
    const gstTotal = cgst + sgst + igst;
    const total    = Math.round((sub + gstTotal) * 100) / 100;

    document.getElementById('disp_subtotal').textContent = '₹ ' + fmt(sub);
    document.getElementById('disp_cgst').textContent     = '₹ ' + fmt(cgst);
    document.getElementById('disp_sgst').textContent     = '₹ ' + fmt(sgst);
    document.getElementById('disp_igst').textContent     = '₹ ' + fmt(igst);
    document.getElementById('disp_gst_total').textContent= '₹ ' + fmt(gstTotal);
    document.getElementById('disp_total').textContent    = '₹ ' + fmt(total);
    document.getElementById('cgst_amt_display').value    = fmt(cgst);
    document.getElementById('sgst_amt_display').value    = fmt(sgst);
    document.getElementById('igst_amt_display').value    = fmt(igst);
    document.getElementById('disp_words').textContent    = numberToWords(total);
}

function fmt(n){ return n.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }

// ── Number to Words (JS) ──────────────────────────────────────────────────────
function numberToWords(num){
    if(num===0) return 'Zero Rupees Only';
    num=Math.round(num*100)/100;
    const parts=num.toFixed(2).split('.');
    let rupees=parseInt(parts[0]);
    const paise=parseInt(parts[1]);
    const ones=['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                'Seventeen','Eighteen','Nineteen'];
    const tens_=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    function tw(n){
        if(n===0)return'';if(n<20)return ones[n]+' ';
        return tens_[Math.floor(n/10)]+' '+(n%10?ones[n%10]+' ':'');
    }
    let w='';
    if(rupees>=10000000){w+=tw(Math.floor(rupees/10000000))+'Crore ';rupees%=10000000;}
    if(rupees>=100000) {w+=tw(Math.floor(rupees/100000))+'Lakh ';  rupees%=100000;}
    if(rupees>=1000)   {w+=tw(Math.floor(rupees/1000))+'Thousand ';rupees%=1000;}
    w+=tw(rupees);
    let res=w.trim()+' Rupees';
    if(paise>0) res+=' and '+tw(paise).trim()+' Paise';
    return res+' Only';
}

// ── PDF Generator ─────────────────────────────────────────────────────────────
function generatePDF(){
    const {jsPDF} = window.jspdf;
    const doc = new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    const pw=210, ph=297, ml=14, mr=14, cw=pw-ml-mr;

    // Colors
    const gold=[183,115,14], dark=[60,20,10], white=[255,255,255], lgray=[245,240,232];

    // ── Header band
    doc.setFillColor(...gold);
    doc.rect(0,0,pw,28,'F');
    doc.setTextColor(...white);
    doc.setFont('helvetica','bold');
    doc.setFontSize(18);
    doc.text('MOTI JEWELLERS',pw/2,11,{align:'center'});
    doc.setFontSize(8);
    doc.setFont('helvetica','normal');
    doc.text('Purchase Tax Invoice',pw/2,18,{align:'center'});
    doc.setFontSize(7);
    doc.text('Krishnapriya, Midnapore (W), West Bengal - 721440',pw/2,24,{align:'center'});

    let y=34;

    // ── Invoice meta box
    doc.setFillColor(...lgray);
    doc.roundedRect(ml,y,cw,22,3,3,'F');
    doc.setTextColor(...dark);
    doc.setFont('helvetica','bold');doc.setFontSize(8);
    const pno  = document.getElementById('purchase_no').value;
    const pdate= document.querySelector('[name=purchase_date]').value;
    const ino  = document.querySelector('[name=invoice_no]').value;
    const idate= document.querySelector('[name=invoice_date]').value;
    const pmode= document.querySelector('[name=payment_mode]').value;
    doc.text('Purchase No:',ml+4,y+7); doc.setFont('helvetica','normal'); doc.text(pno,ml+32,y+7);
    doc.setFont('helvetica','bold');
    doc.text('Invoice No:',ml+4,y+13); doc.setFont('helvetica','normal'); doc.text(ino,ml+32,y+13);
    doc.setFont('helvetica','bold');
    doc.text('Invoice Date:',ml+4,y+19); doc.setFont('helvetica','normal'); doc.text(idate,ml+32,y+19);
    doc.setFont('helvetica','bold');
    doc.text('Purchase Date:',pw/2+4,y+7); doc.setFont('helvetica','normal'); doc.text(pdate,pw/2+32,y+7);
    doc.setFont('helvetica','bold');
    doc.text('Payment Mode:',pw/2+4,y+13); doc.setFont('helvetica','normal'); doc.text(pmode,pw/2+32,y+13);

    y+=27;

    // ── Supplier & Buyer side-by-side
    const sname  = document.querySelector('[name=supplier_name]').value;
    const saddr  = document.querySelector('[name=supplier_addr]').value;
    const sgstin = document.querySelector('[name=supplier_gstin]').value;
    const span_  = document.querySelector('[name=supplier_pan]').value;
    const sstate = document.querySelector('[name=supplier_state]').value;
    const scode  = document.querySelector('[name=supplier_state_code]').value;
    const smob   = document.querySelector('[name=supplier_mobile]').value;
    const bname  = document.querySelector('[name=buyer_name]').value;
    const baddr  = document.querySelector('[name=buyer_addr]').value;
    const bgstin = document.querySelector('[name=buyer_gstin]').value;
    const bpan   = document.querySelector('[name=buyer_pan]').value;

    const colW = cw/2 - 2;
    doc.setFillColor(...gold);doc.rect(ml,y,colW,7,'F');
    doc.setTextColor(...white);doc.setFont('helvetica','bold');doc.setFontSize(8);
    doc.text('SELLER',ml+colW/2,y+5,{align:'center'});
    doc.setFillColor(...gold);doc.rect(ml+colW+4,y,colW,7,'F');
    doc.text('BUYER',ml+colW+4+colW/2,y+5,{align:'center'});

    doc.setFillColor(...lgray);
    const sboxH=38;
    doc.rect(ml,y+7,colW,sboxH,'F');
    doc.rect(ml+colW+4,y+7,colW,sboxH,'F');

    doc.setTextColor(...dark);doc.setFont('helvetica','bold');doc.setFontSize(8);
    const sx=ml+3, bx=ml+colW+7;
    let sy=y+13;
    doc.text(sname||'—',sx,sy); sy+=5;
    doc.setFont('helvetica','normal');doc.setFontSize(7);
    if(saddr){const ls=doc.splitTextToSize(saddr,colW-6);doc.text(ls,sx,sy);sy+=ls.length*4;}
    if(sgstin){doc.setFont('helvetica','bold');doc.text('GSTIN: ',sx,sy);doc.setFont('helvetica','normal');doc.text(sgstin,sx+14,sy);sy+=4.5;}
    if(span_){doc.setFont('helvetica','bold');doc.text('PAN: ',sx,sy);doc.setFont('helvetica','normal');doc.text(span_,sx+10,sy);sy+=4.5;}
    if(sstate){doc.text('State: '+sstate+(scode?' ('+scode+')':''),sx,sy);}
    if(smob){sy+=4.5;doc.text('Mob: '+smob,sx,sy);}

    let by=y+13;
    doc.setFont('helvetica','bold');doc.setFontSize(8);
    doc.text(bname||'MOTI JEWELLERS',bx,by);by+=5;
    doc.setFont('helvetica','normal');doc.setFontSize(7);
    if(baddr){const ls=doc.splitTextToSize(baddr,colW-6);doc.text(ls,bx,by);by+=ls.length*4;}
    if(bgstin){doc.setFont('helvetica','bold');doc.text('GSTIN: ',bx,by);doc.setFont('helvetica','normal');doc.text(bgstin,bx+14,by);by+=4.5;}
    if(bpan){doc.setFont('helvetica','bold');doc.text('PAN: ',bx,by);doc.setFont('helvetica','normal');doc.text(bpan,bx+10,by);}

    y += sboxH + 12;

    // ── Items table (Supports multiple items)
    const itemsToPrint = (purchaseItems.length > 0) ? purchaseItems : [{
        material_type: document.getElementById('material_type').value,
        description: document.getElementById('desc_field').value,
        huid_code: document.getElementById('manualhuid').value,
        hsn_sac: document.getElementById('hsn_field').value,
        unit: document.getElementById('unit_field').value,
        qty: parseFloat(document.getElementById('qty').value)||0,
        rate_per_unit: parseFloat(document.getElementById('rate').value)||0,
        subtotal: Math.round((parseFloat(document.getElementById('qty').value)||0)*(parseFloat(document.getElementById('rate').value)||0)*100)/100
    }];

    let sub = itemsToPrint.reduce((sum, it) => sum + it.subtotal, 0);
    const taxT = document.getElementById('tax_type').value;
    const cgstP= parseFloat(document.getElementById('cgst_pct').value)||0;
    const sgstP= parseFloat(document.getElementById('sgst_pct').value)||0;
    const igstP= parseFloat(document.getElementById('igst_pct').value)||0;
    let cgstA=0,sgstA=0,igstA=0;
    if(taxT==='CGST_SGST'){cgstA=Math.round(sub*cgstP/100*100)/100;sgstA=Math.round(sub*sgstP/100*100)/100;}
    else{igstA=Math.round(sub*igstP/100*100)/100;}
    const gstTot=cgstA+sgstA+igstA;
    const total=Math.round((sub+gstTot)*100)/100;

    // Table header
    const cols=[['Description',60],['HSN/SAC',22],['Qty',18],['Rate',28],['Per',12],['Amount',28]];
    doc.setFillColor(...dark);
    doc.rect(ml,y,cw,8,'F');
    doc.setTextColor(...white);doc.setFont('helvetica','bold');doc.setFontSize(7.5);
    let cx=ml;
    cols.forEach(([h,w])=>{doc.text(h,cx+w/2,y+5.5,{align:'center'});cx+=w;});

    // Table rows for all items
    y+=8;
    itemsToPrint.forEach((it) => {
        doc.setFillColor(255,255,255);doc.rect(ml,y,cw,12,'F');
        doc.setTextColor(...dark);doc.setFont('helvetica','normal');doc.setFontSize(8);
        cx=ml;
        const descText = it.description + ' (' + it.material_type + ')' + (it.huid_code ? ' HUID:' + it.huid_code : '');
        const vals=[descText, it.hsn_sac||'7108', it.qty.toFixed(4), fmt(it.rate_per_unit), it.unit, fmt(it.subtotal)];
        cols.forEach(([,w],i)=>{
            doc.text(vals[i],i===0?cx+2:cx+w/2,y+7.5,{align:i===0?'left':'center'});
            cx+=w;
        });
        y+=12;
    });

    // Tax rows
    y+=14;
    const taxRows=taxT==='CGST_SGST'
        ?[['Output CGST @'+cgstP+'%','','','','',fmt(cgstA)],['Output SGST @'+sgstP+'%','','','','',fmt(sgstA)]]
        :[['Output IGST @'+igstP+'%','','','','',fmt(igstA)]];
    doc.setFontSize(7.5);
    taxRows.forEach(row=>{
        doc.setFillColor(...lgray);doc.rect(ml,y,cw,6,'F');
        cx=ml;
        cols.forEach(([,w],i)=>{
            doc.text(row[i],i===0?cx+2:cx+w/2,y+4.5,{align:i===0?'left':'center'});
            cx+=w;
        });
        y+=6;
    });

    // Subtotal/Total rows
    y+=2;
    const summaryRows=[
        ['SUBTOTAL','₹ '+fmt(sub)],
        ['GST TOTAL','₹ '+fmt(gstTot)],
        ['TOTAL AMOUNT','₹ '+fmt(total)],
    ];
    summaryRows.forEach(([label,val],i)=>{
        if(i===2){doc.setFillColor(...gold);doc.rect(ml,y,cw,9,'F');doc.setTextColor(...white);}
        else{doc.setFillColor(...lgray);doc.rect(ml,y,cw,7,'F');doc.setTextColor(...dark);}
        doc.setFont('helvetica','bold');doc.setFontSize(i===2?9:8);
        doc.text(label,ml+4,y+(i===2?6:5));
        doc.text(val,ml+cw-4,y+(i===2?6:5),{align:'right'});
        y+=i===2?9:7;
    });

    // Amount in words
    y+=4;
    doc.setFillColor(253,246,227);doc.rect(ml,y,cw,9,'F');
    doc.setTextColor(...dark);doc.setFont('helvetica','bold');doc.setFontSize(7.5);
    doc.text('Amount in Words: ',ml+3,y+6);
    doc.setFont('helvetica','italic');
    doc.text(numberToWords(total),ml+38,y+6);
    y+=13;

    // Declaration
    doc.setFont('helvetica','normal');doc.setFontSize(7);
    doc.setTextColor(100,80,60);
    const decl='We declare that this invoice shows the actual price of goods and all particulars are true and correct.';
    doc.text(decl,ml,y);
    y+=10;

    // Signature
    doc.setFillColor(...lgray);doc.rect(pw-ml-55,y,55,20,'F');
    doc.setTextColor(...dark);doc.setFont('helvetica','bold');doc.setFontSize(8);
    doc.text('For MOTI JEWELLERS',pw-ml-52,y+7);
    doc.setFont('helvetica','normal');doc.setFontSize(7);
    doc.text('Authorised Signatory',pw-ml-48,y+17);

    // Footer
    doc.setFillColor(...gold);doc.rect(0,ph-12,pw,12,'F');
    doc.setTextColor(...white);doc.setFont('helvetica','bold');doc.setFontSize(7.5);
    doc.text('( This is a Computer Generated Purchase Invoice )',pw/2,ph-5,{align:'center'});

    // Download
    const fname=`Purchase_${document.getElementById('purchase_no').value}_${pdate}.pdf`;
    doc.save(fname);
}

function triggerPDF(){ generatePDF(); }
</script>
</body>
</html>





