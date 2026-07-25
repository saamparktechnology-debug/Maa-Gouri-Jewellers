<?php
session_start();
require_once 'config/database.php';
if(file_exists('config/company_config.php')) {
    require_once 'config/company_config.php';
} else {
    $COMPANY = [
        'name'          => 'MAA GOURI JEWELLERS',
        'name_short'    => 'Gouri Jewellers',
        'address_line1' => 'Sabang',
        'address_line2' => 'Paschim Medinipur',
        'state'         => 'West Bengal',
        'state_code'    => '19',
        'mobile'        => '9647291299',
        'email'         => 'jewellersmaagouri@gmail.com',
        'gstin'         => '',
        'logo_path'     => 'assets/images/moti-removebg-preview.png',
    ];
}

$is_logged_in = isset($_SESSION['user_id']);
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    die("Invalid Purchase ID");
}

$res = $conn->query("SELECT * FROM purchase_entries WHERE id = $id LIMIT 1");
if (!$res || $res->num_rows === 0) {
    die("Purchase entry not found");
}
$purchase = $res->fetch_assoc();

function fmt($v) {
    return number_format((float)$v, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>Purchase View #<?php echo htmlspecialchars($purchase['purchase_no']); ?> | <?php echo htmlspecialchars($COMPANY['name']); ?></title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="shortcut icon" type="image/png" href="<?php echo htmlspecialchars($COMPANY['logo_path']); ?>">
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
* { font-family: 'Poppins', sans-serif; box-sizing: border-box; }

/* SIDEBAR & NAV (Screen Only) */
.sidebar {
    position: fixed; top: 0; left: 0; width: 240px; height: 100vh;
    background: linear-gradient(180deg, #011921 0%, #03373b 50%, #044e54 80%, #011921 100%);
    z-index: 1000; display: flex; flex-direction: column;
    box-shadow: 4px 0 24px rgba(0,0,0,0.25);
    transition: transform 0.35s cubic-bezier(.4,0,.2,1);
    overflow: hidden;
}
.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
.sidebar-logo {
    padding: 22px 18px 16px; border-bottom: 1px solid rgba(255,255,255,0.18);
    display: flex; align-items: center; gap: 12px; flex-shrink: 0;
}
.sidebar-logo img { width: 44px; height: 44px; object-fit: cover; border-radius: 50%; background: rgba(255,255,255,0.1); }
.sidebar-logo-text h2 { color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3; font-family: 'Poppins', serif; }
.sidebar-logo-text p { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }
.sidebar-nav { flex: 1; padding: 10px 0; overflow-y: auto; overflow-x: hidden; }
.sidebar-section-label { padding: 10px 20px 4px; color: #f5c842; font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; position: sticky; top: 0; background: #011921; z-index: 10; }
.sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent; position: relative; }
.sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
.sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
.sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
.sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }
.sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
.sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; }
.sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; }
.sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }
.sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; }
.sidebar-logout:hover { background: #ef4444; color: #fff; }
.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
.sidebar-overlay.active { display: block; }
.page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; background: #eaedf2; display: flex; flex-direction: column; align-items: center; padding: 30px 15px; }
nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important; width: 100%; position: fixed; top: 0; left: 0; z-index: 50;}
.burger-menu { width: 28px; height: 20px; position: relative; cursor: pointer; }
.burger-menu span { display: block; position: absolute; height: 3px; width: 100%; background: #fff; border-radius: 3px; transition: all 0.3s ease; }
.burger-menu span:nth-child(1) { top: 0; }
.burger-menu span:nth-child(2) { top: 9px; }
.burger-menu span:nth-child(3) { top: 18px; }

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .page-wrapper { margin-left: 0 !important; padding-top: 80px; }
    nav.nav-gold { padding-left: 15px; padding-right: 15px; }
    .mobile-burger { display: block !important; }
}
@media (min-width: 769px) { 
    .mobile-burger { display: none !important; } 
    nav.nav-gold { padding-left: 260px; }
    .page-wrapper { padding-top: 90px; }
}

.btn-gold { background: linear-gradient(135deg, #d68b16, #b5730e); border: none; color: #fff; font-weight: 700; cursor: pointer; transition: all 0.2s ease; }
.btn-gold:hover { background: linear-gradient(135deg, #e8a020, #c8830e); box-shadow: 0 4px 16px rgba(214,139,22,0.35); transform: translateY(-1px); }

/* A4 Print Layout Container */
.print-container {
    background: #fff;
    width: 100%;
    max-width: 210mm; /* A4 Width */
    min-height: 297mm; /* A4 Height */
    margin: 0 auto;
    padding: 15mm;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-radius: 4px;
    position: relative;
}
.invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; border-bottom: 2px solid #f0f0f0; padding-bottom: 20px; }
.invoice-logo { width: 80px; height: 80px; object-fit: contain; }
.company-details h1 { font-family: 'Playfair Display', serif; font-size: 26px; color: #011921; font-weight: 800; margin: 0 0 5px 0; }
.company-details p { font-size: 11px; color: #555; margin: 0; line-height: 1.5; }
.invoice-title-badge { background: #011921; color: #ffd700; padding: 6px 15px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; display: inline-block; margin-bottom: 10px; }
.invoice-meta { text-align: right; }
.invoice-meta h2 { font-size: 22px; color: #333; margin: 0 0 5px 0; font-weight: 700; }
.invoice-meta p { font-size: 11px; color: #666; margin: 0 0 3px 0; }

.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
.info-box { background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #eee; }
.info-box h3 { font-size: 11px; color: #999; text-transform: uppercase; font-weight: 700; margin: 0 0 8px 0; letter-spacing: 0.5px; }
.info-box p { font-size: 13px; color: #222; margin: 0 0 4px 0; }
.info-box p strong { font-weight: 600; color: #000; }

table.invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
table.invoice-table th { background: #011921; color: #fff; font-size: 11px; text-transform: uppercase; font-weight: 600; padding: 12px 10px; text-align: left; }
table.invoice-table th:last-child { border-top-right-radius: 6px; border-bottom-right-radius: 6px; }
table.invoice-table th:first-child { border-top-left-radius: 6px; border-bottom-left-radius: 6px; }
table.invoice-table td { padding: 15px 10px; font-size: 13px; color: #333; border-bottom: 1px solid #eee; }
table.invoice-table td.money { font-weight: 600; }

.totals-section { display: flex; justify-content: flex-end; margin-bottom: 40px; }
.totals-box { width: 300px; }
.total-line { display: flex; justify-content: space-between; padding: 8px 0; font-size: 13px; color: #555; }
.total-line.grand-total { font-size: 18px; font-weight: 800; color: #011921; border-top: 2px solid #011921; padding-top: 12px; margin-top: 5px; }

.footer-note { text-align: center; color: #888; font-size: 10px; padding-top: 20px; border-top: 1px solid #eee; position: absolute; bottom: 15px; left: 15px; right: 15px; }

/* PRINT STYLES */
@media print {
    @page { margin: 0; size: A4 portrait; }
    body, html { background: #fff !important; margin: 0 !important; padding: 0 !important; }
    .no-print, .sidebar, .sidebar-overlay, nav.nav-gold { display: none !important; }
    .page-wrapper { margin: 0 !important; padding: 0 !important; background: #fff !important; display: block; }
    .print-container { box-shadow: none !important; border-radius: 0 !important; width: 100% !important; max-width: none !important; margin: 0 !important; padding: 15mm 15mm !important; min-height: 0; }
    
    /* Ensure table borders and colors print correctly */
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar no-print" id="mainSidebar">
    <div class="sidebar-logo">
        <img src="<?php echo htmlspecialchars($COMPANY['logo_path']); ?>" alt="Logo" onerror="this.src='logo.png'">
        <div class="sidebar-logo-text">
            <h2><?php echo htmlspecialchars($COMPANY['name']); ?></h2>
            <p>Trusted Jewellery System</p>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>
        <a href="index.php"><i class="fas fa-home"></i> HOME</a>
        <a href="billing.php"><i class="fas fa-receipt"></i> BILLING</a>
        <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
        <a href="customers.php"><i class="fas fa-users"></i> CUSTOMERS</a>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME & EXP</a>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="purchase.php"><i class="fas fa-shopping-cart"></i> PURCHASE</a>
        <a href="purchase_history.php" class="active"><i class="fas fa-history"></i> PURCHASE HISTORY</a>
        <a href="contacts.php"><i class="fas fa-address-book"></i> CONTACTS</a>
        <a href="accounts.php"><i class="fas fa-calculator"></i> ACCOUNTS</a>
    </nav>
    <div class="sidebar-user">
        <?php if($is_logged_in): ?>
        <div class="sidebar-user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <p><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <span><?php echo htmlspecialchars($_SESSION['user_mobile'] ?? 'Admin'); ?></span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i> LOGOUT</a>
        <?php else: ?>
        <a href="login.php" class="sidebar-logout" style="background:rgba(255,255,255,0.2);"><i class="fas fa-sign-in-alt"></i> LOGIN</a>
        <?php endif; ?>
    </div>
</div>

<!-- TOP NAVBAR -->
<nav class="nav-gold no-print">
    <div class="container mx-auto px-4 sm:px-6 py-3 flex justify-between items-center h-full">
        <h1 style="color:#fff;font-family:'Poppins',serif;font-size:18px;font-weight:700;" class="flex items-center gap-2">
            <i class="fas fa-file-invoice" style="color:#ffd700;"></i> Purchase Statement
        </h1>
        <div class="flex items-center gap-4">
            <div class="mobile-burger">
                <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- MAIN CONTENT WRAPPER -->
<div class="page-wrapper">
    <div class="w-full flex justify-end max-w-4xl mb-4 no-print gap-3">
        <a href="purchase_history.php" class="px-5 py-2.5 bg-white text-gray-700 rounded-lg shadow font-semibold text-sm hover:bg-gray-50 transition border border-gray-200">
            &larr; Back
        </a>
        <button onclick="window.print()" class="btn-gold px-6 py-2.5 rounded-lg shadow-lg font-bold text-sm flex items-center gap-2">
            <i class="fas fa-print"></i> Print Statement
        </button>
    </div>

    <!-- A4 PRINT CONTAINER -->
    <div class="print-container">
        
        <!-- HEADER -->
        <div class="invoice-header">
            <div class="flex gap-4 items-center">
                <img src="<?php echo htmlspecialchars($COMPANY['logo_path']); ?>" alt="Logo" class="invoice-logo" onerror="this.src='logo.png'">
                <div class="company-details">
                    <h1><?php echo htmlspecialchars($COMPANY['name']); ?></h1>
                    <p><?php echo htmlspecialchars($COMPANY['address_line1']); ?>, <?php echo htmlspecialchars($COMPANY['address_line2']); ?></p>
                    <p><?php echo htmlspecialchars($COMPANY['state']); ?> - <?php echo htmlspecialchars($COMPANY['state_code']); ?></p>
                    <?php if(!empty($COMPANY['gstin'])): ?>
                    <p><strong>GSTIN:</strong> <?php echo htmlspecialchars($COMPANY['gstin']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="invoice-meta">
                <div class="invoice-title-badge">Purchase Entry</div>
                <h2># <?php echo htmlspecialchars($purchase['purchase_no']); ?></h2>
                <p><strong>Date:</strong> <?php echo date('d-M-Y', strtotime($purchase['purchase_date'])); ?></p>
            </div>
        </div>

        <!-- INFO GRID -->
        <div class="info-grid">
            <div class="info-box">
                <h3>Supplier (Seller) Details</h3>
                <p><strong><?php echo htmlspecialchars($purchase['supplier_name']); ?></strong></p>
                <?php if(!empty($purchase['supplier_addr'])): ?>
                <p><?php echo htmlspecialchars($purchase['supplier_addr']); ?></p>
                <?php endif; ?>
                <?php if(!empty($purchase['supplier_mobile'])): ?>
                <p>Mob: <?php echo htmlspecialchars($purchase['supplier_mobile']); ?></p>
                <?php endif; ?>
                <?php if(!empty($purchase['supplier_gstin'])): ?>
                <p>GSTIN: <?php echo htmlspecialchars($purchase['supplier_gstin']); ?></p>
                <?php endif; ?>
            </div>
            <div class="info-box">
                <h3>Supplier Invoice Details</h3>
                <p>Invoice No: <strong><?php echo htmlspecialchars($purchase['invoice_no']); ?></strong></p>
                <p>Invoice Date: <strong><?php echo date('d-M-Y', strtotime($purchase['invoice_date'])); ?></strong></p>
                <p>Payment Mode: <strong><?php echo htmlspecialchars($purchase['payment_mode']); ?></strong></p>
            </div>
        </div>

        <!-- ITEMS TABLE -->
        <table class="invoice-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:center;">HSN / SAC</th>
                    <th style="text-align:right;">Quantity</th>
                    <th style="text-align:right;">Rate / Unit</th>
                    <th style="text-align:right;">Amount (<?php echo "\xe2\x82\xb9"; ?>)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong style="display:block;margin-bottom:4px;"><?php echo htmlspecialchars($purchase['description']); ?></strong>
                        <span style="display:inline-block;background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;border:1px solid #fde68a;">
                            <?php echo htmlspecialchars($purchase['material_type']); ?>
                        </span>
                        <?php if(!empty($purchase['huid_code'])): ?>
                        <div style="font-size:11px;color:#666;margin-top:5px;">HUID: <strong><?php echo htmlspecialchars($purchase['huid_code']); ?></strong></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;color:#666;"><?php echo htmlspecialchars($purchase['hsn_sac'] ?? '-'); ?></td>
                    <td style="text-align:right;font-weight:600;color:#222;">
                        <?php echo rtrim(rtrim(number_format((float)$purchase['qty'], 4), '0'), '.'); ?> <?php echo htmlspecialchars($purchase['unit']); ?>
                    </td>
                    <td style="text-align:right;color:#444;">
                        <?php echo "\xe2\x82\xb9"; ?> <?php echo fmt($purchase['rate_per_unit']); ?>
                    </td>
                    <td class="money" style="text-align:right;">
                        <?php 
                        $subtotal = (float)$purchase['total_amount'] - (float)$purchase['gst_total'];
                        echo "\xe2\x82\xb9 " . fmt($subtotal); 
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- TOTALS -->
        <div class="totals-section">
            <div class="totals-box">
                <div class="total-line">
                    <span>Sub Total:</span>
                    <span><?php echo "\xe2\x82\xb9"; ?> <?php echo fmt($subtotal); ?></span>
                </div>
                <?php if((float)$purchase['gst_total'] > 0): ?>
                <div class="total-line">
                    <span>Tax (GST):</span>
                    <span><?php echo "\xe2\x82\xb9"; ?> <?php echo fmt($purchase['gst_total']); ?></span>
                </div>
                <?php endif; ?>
                <div class="total-line grand-total">
                    <span>Total Amount:</span>
                    <span><?php echo "\xe2\x82\xb9"; ?> <?php echo fmt($purchase['total_amount']); ?></span>
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer-note">
            This is a system generated purchase statement. &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($COMPANY['name']); ?>
            <br>
            Printed on: <?php echo date("d-M-Y h:i A"); ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar(){
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
function closeSidebar(){
    document.getElementById('mainSidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
}
</script>
</body>
</html>