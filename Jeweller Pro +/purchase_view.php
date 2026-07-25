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
<link rel="shortcut icon" type="image/png" href="assets/images/moti-removebg-preview.png">
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
* { font-family: 'Poppins', sans-serif; box-sizing: border-box; }

/* SIDEBAR */
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
.page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; background: #F5F5F5; }
nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important; }
.burger-menu { width: 28px; height: 20px; position: relative; cursor: pointer; }
.burger-menu span { display: block; position: absolute; height: 3px; width: 100%; background: #fff; border-radius: 3px; transition: all 0.3s ease; }
.burger-menu span:nth-child(1) { top: 0; }
.burger-menu span:nth-child(2) { top: 9px; }
.burger-menu span:nth-child(3) { top: 18px; }
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .page-wrapper { margin-left: 0 !important; }
    nav.nav-gold { margin-left: 0 !important; }
    .mobile-burger { display: block !important; }
}
@media (min-width: 769px) { .mobile-burger { display: none !important; } }

.jewel-card { background: linear-gradient(145deg, #fdf6e3, #f5ead0); border-radius: 16px; border: 1px solid rgba(181,115,14,0.2); box-shadow: 0 4px 20px rgba(181,115,14,0.08); }
.btn-gold { background: linear-gradient(135deg, #d68b16, #b5730e); border: none; color: #fff; font-weight: 700; cursor: pointer; transition: all 0.2s ease; }
.btn-gold:hover { background: linear-gradient(135deg, #e8a020, #c8830e); box-shadow: 0 4px 16px rgba(214,139,22,0.35); transform: translateY(-1px); }

@media print {
    .no-print, .sidebar, .sidebar-overlay, nav.nav-gold, footer { display: none !important; }
    .page-wrapper { margin-left: 0 !important; background: #fff !important; }
    .jewel-card { box-shadow: none !important; border: 1px solid #ccc !important; background: #fff !important; }
}
</style>
</head>
<body style="background:#F5F5F5;margin:0;padding:0;">

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <img src="assets/images/moti-removebg-preview.png" alt="Logo" onerror="this.src='logo.png'">
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
<nav class="nav-gold shadow-lg sticky top-0 z-50 no-print" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 flex justify-between items-center">
        <h1 style="color:#fff;font-family:'Poppins',serif;font-size:18px;font-weight:700;" class="flex items-center gap-2">
            <i class="fas fa-file-invoice" style="color:#ffd700;"></i> Purchase Entry Details
        </h1>
        <div class="flex items-center gap-4">
            <?php if($is_logged_in): ?>
            <span class="text-sm font-medium hidden sm:inline-block" style="color:#fff;">
                <i class="fas fa-user-circle mr-1" style="color:#ffd700;"></i><?php echo htmlspecialchars($_SESSION['user_name']); ?>
            </span>
            <?php endif; ?>
            <div class="mobile-burger" style="display:none;">
                <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- MAIN CONTENT WRAPPER -->
<div class="page-wrapper">
<div class="container mx-auto px-4 sm:px-6 py-6" style="max-width:1000px;">

<div class="jewel-card p-6 sm:p-8">
    <div class="flex justify-between items-center pb-6 border-b border-amber-200 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-amber-900"><?php echo htmlspecialchars($COMPANY['name']); ?></h1>
            <p class="text-xs text-amber-800"><?php echo htmlspecialchars($COMPANY['address_line1']); ?>, <?php echo htmlspecialchars($COMPANY['address_line2']); ?></p>
        </div>
        <div class="text-right">
            <span class="text-xs font-bold uppercase tracking-wider text-amber-900 bg-amber-200 px-3 py-1 rounded-full border border-amber-300">Purchase Entry</span>
            <div class="text-sm font-bold text-gray-800 mt-2"># <?php echo htmlspecialchars($purchase['purchase_no']); ?></div>
            <div class="text-xs text-gray-500">Date: <?php echo date('d-M-Y', strtotime($purchase['purchase_date'])); ?></div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-8 text-xs">
        <div class="bg-amber-50 p-4 rounded-xl border border-amber-200">
            <h3 class="font-bold text-amber-900 mb-2 uppercase tracking-wide">Supplier (Seller)</h3>
            <p class="font-bold text-gray-800 text-sm"><?php echo htmlspecialchars($purchase['supplier_name']); ?></p>
            <p class="text-gray-600"><?php echo htmlspecialchars($purchase['supplier_addr'] ?? '—'); ?></p>
            <p class="text-gray-600 mt-1">Mob: <?php echo htmlspecialchars($purchase['supplier_mobile'] ?? '—'); ?></p>
            <p class="text-gray-600">GSTIN: <?php echo htmlspecialchars($purchase['supplier_gstin'] ?? '—'); ?></p>
        </div>
        <div class="bg-white p-4 rounded-xl border border-amber-200">
            <h3 class="font-bold text-amber-900 mb-2 uppercase tracking-wide">Invoice Details</h3>
            <p class="mb-1">Invoice No: <strong><?php echo htmlspecialchars($purchase['invoice_no']); ?></strong></p>
            <p class="mb-1">Invoice Date: <?php echo date('d-M-Y', strtotime($purchase['invoice_date'])); ?></p>
            <p>Payment Mode: <strong><?php echo htmlspecialchars($purchase['payment_mode']); ?></strong></p>
        </div>
    </div>

    <table class="w-full text-left border-collapse text-xs mb-8 rounded-xl overflow-hidden shadow-sm">
        <thead>
            <tr style="background:linear-gradient(135deg, #7a4e0a, #d68b16);" class="text-white">
                <th class="p-3">Material & Description</th>
                <th class="p-3 text-center">HSN</th>
                <th class="p-3 text-center">Qty</th>
                <th class="p-3 text-right">Rate / Unit</th>
                <th class="p-3 text-right">Tax (GST)</th>
                <th class="p-3 text-right">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr class="border-b bg-white">
                <td class="p-3">
                    <strong class="text-gray-900"><?php echo htmlspecialchars($purchase['description']); ?></strong>
                    <span class="ml-2 text-xs bg-amber-100 text-amber-900 px-2 py-0.5 rounded font-bold border border-amber-300"><?php echo htmlspecialchars($purchase['material_type']); ?></span>
                    <?php if(!empty($purchase['huid_code'])): ?>
                    <div class="text-amber-800 text-xs mt-1">HUID: <strong><?php echo htmlspecialchars($purchase['huid_code']); ?></strong></div>
                    <?php endif; ?>
                </td>
                <td class="p-3 text-center text-gray-600"><?php echo htmlspecialchars($purchase['hsn_sac'] ?? '—'); ?></td>
                <td class="p-3 text-center font-bold text-gray-800"><?php echo rtrim(rtrim(number_format((float)$purchase['qty'], 4), '0'), '.'); ?> <?php echo htmlspecialchars($purchase['unit']); ?></td>
                <td class="p-3 text-right">₹ <?php echo fmt($purchase['rate_per_unit']); ?></td>
                <td class="p-3 text-right">₹ <?php echo fmt($purchase['gst_total']); ?></td>
                <td class="p-3 text-right font-bold text-amber-900 text-sm">₹ <?php echo fmt($purchase['total_amount']); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="flex justify-between items-center pt-4 border-t border-amber-200 no-print">
        <a href="purchase_history.php" class="px-4 py-2 bg-white border border-amber-300 text-amber-900 rounded-xl font-semibold text-xs text-decoration-none">← Back to History</a>
        <button onclick="window.print()" class="btn-gold px-5 py-2 rounded-xl font-bold text-xs"><i class="fas fa-print mr-1"></i> Print Statement</button>
    </div>
</div>

</div><!-- /container -->

<footer style="background:linear-gradient(0deg,#f5e6c8,#fdf6e3);border-top:2px solid #d68b16;padding:20px;margin-top:40px;text-align:center;" class="no-print">
    <p class="text-xs" style="color:#7a4e0a;">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($COMPANY['name']); ?> &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
        Design & Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology & Research Private Limited</a>
    </p>
</footer>
</div><!-- /page-wrapper -->

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


