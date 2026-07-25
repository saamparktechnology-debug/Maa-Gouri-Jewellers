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
        'email'         => 'admin@gourijewellers.com',
        'gstin'         => '',
        'logo_path'     => 'assets/images/moti-removebg-preview.png',
    ];
}

$is_logged_in = isset($_SESSION['user_id']);

$logo_paths = [
    $COMPANY['logo_path'] ?? '',
    'assets/images/moti-removebg-preview.png',
    'logo.png',
];

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$material    = isset($_GET['material']) ? trim($_GET['material']) : '';
$date_from   = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to     = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$where = [];
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where[] = "(purchase_no LIKE '%$s%' OR invoice_no LIKE '%$s%' OR supplier_name LIKE '%$s%')";
}
if ($material !== '' && in_array($material, ['Gold','Silver','Diamond','Platinum'])) {
    $m = $conn->real_escape_string($material);
    $where[] = "material_type = '$m'";
}
if ($date_from !== '') {
    $df = $conn->real_escape_string($date_from);
    $where[] = "purchase_date >= '$df'";
}
if ($date_to !== '') {
    $dt = $conn->real_escape_string($date_to);
    $where[] = "purchase_date <= '$dt'";
}
$where_sql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

// â”€â”€ Handle delete â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$delete_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $del_id = intval($_POST['delete_id']);
    // roll back stock before deleting
    $items_res = $conn->query("SELECT material_type, qty FROM purchase_entries WHERE id = $del_id");
    if ($items_res) {
        while ($row = $items_res->fetch_assoc()) {
            $conn->query("UPDATE stock_metal SET qty_available = qty_available - " . floatval($row['qty']) . " WHERE material_type = '" . $conn->real_escape_string($row['material_type']) . "'");
        }
    }
    $conn->query("DELETE FROM purchase_entries WHERE id = $del_id");
    $delete_msg = 'Purchase entry deleted successfully.';
}

// â”€â”€ Fetch list â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$list_sql = "SELECT id, purchase_no, purchase_date, invoice_no, invoice_date, supplier_name,
                    material_type, qty, unit, total_amount, payment_mode
             FROM purchase_entries
             $where_sql
             ORDER BY purchase_date DESC, id DESC";
$result = $conn->query($list_sql);

// â”€â”€ Totals for filtered set â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$total_amount_sum = 0;
$total_count = 0;
$rows = [];
if ($result) {
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
        $total_amount_sum += floatval($r['total_amount']);
        $total_count++;
    }
}

function fmt_inr($n) {
    return number_format((float)$n, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>Purchase History - <?php echo htmlspecialchars($COMPANY['name']); ?></title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="shortcut icon" type="image/png" href="assets/images/moti-removebg-preview.png">
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
* { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
h1,h2,h3,.gold-font { font-family: 'Poppins', serif; }

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

/* GENERAL CARDS & TABLES */
.jewel-card { background: linear-gradient(145deg, #fdf6e3, #f5ead0); border-radius: 16px; border: 1px solid rgba(181,115,14,0.2); box-shadow: 0 4px 20px rgba(181,115,14,0.08); }
.jewel-input { background: #fff; border: 1.5px solid rgba(181,115,14,0.3); color: #3a1f00; font-size: 13px; transition: border-color 0.2s, box-shadow 0.2s; }
.jewel-input:focus { outline: none; border-color: #d68b16; box-shadow: 0 0 0 3px rgba(214,139,22,0.15); }
.btn-gold { background: linear-gradient(135deg, #d68b16, #b5730e); border: none; color: #fff; font-weight: 700; cursor: pointer; transition: all 0.2s ease; }
.btn-gold:hover { background: linear-gradient(135deg, #e8a020, #c8830e); box-shadow: 0 4px 16px rgba(214,139,22,0.35); transform: translateY(-1px); }

.stat-box { background: linear-gradient(145deg, #ffffff, #fdf6e3); border: 1.5px solid rgba(214,139,22,0.25); border-radius: 16px; padding: 16px 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
.stat-label { font-size: 11px; color: #7a4e0a; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; }
.stat-value { font-size: 22px; font-weight: 700; color: #800020; font-family: 'Poppins', serif; margin-top: 2px; }

table.hist-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
table.hist-table thead tr { background: linear-gradient(135deg, #7a4e0a, #d68b16); }
table.hist-table thead th { color: #fff; padding: 10px 12px; text-align: left; font-weight: 600; white-space: nowrap; font-size: 11px; text-transform: uppercase; }
table.hist-table thead th:first-child { border-top-left-radius: 10px; }
table.hist-table thead th:last-child { border-top-right-radius: 10px; }
table.hist-table tbody td { padding: 11px 12px; border-bottom: 1px solid rgba(181,115,14,0.12); color: #334155; white-space: nowrap; }
table.hist-table tbody tr:hover { background: rgba(214,139,22,0.06); }
.mat-pill { padding: 3px 10px; border-radius: 50px; font-size: 11px; font-weight: 700; display: inline-block; }
.mat-pill.Gold { background: #fef3c7; color: #7a4e0a; border: 1px solid #fde68a; }
.mat-pill.Silver { background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; }
.mat-pill.Diamond { background: #ede9fe; color: #4c1d95; border: 1px solid #ddd6fe; }
.mat-pill.Platinum { background: #f0fdf4; color: #14532d; border: 1px solid #bbf7d0; }
.action-btn { padding: 5px 10px; border-radius: 8px; font-size: 11px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; cursor: pointer; }
.action-view { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
.action-delete { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.alert-success { background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; border-radius: 12px; padding: 14px 18px; font-size: 13px; font-weight: 600; }
.empty-state { text-align: center; padding: 50px 20px; color: #94a3b8; }
</style>
</head>
<body style="background:#F5F5F5;margin:0;padding:0;">

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) {
                echo '<img src="'.$path.'" alt="Logo">';
                $logo_found = true; break;
            }
        }
        if(!$logo_found) echo '<i class="fas fa-gem" style="color:#fff;font-size:30px;flex-shrink:0;"></i>';
        ?>
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
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 flex justify-between items-center">
        <div class="flex items-center gap-3">
            <?php
            foreach($logo_paths as $path) {
                if(file_exists($path)) {
                    echo '<img src="'.$path.'" style="width:32px;height:32px;object-fit:cover;border-radius:50%;border:1px solid #d68b16;display:inline-block;vertical-align:middle;">';
                    break;
                }
            }
            ?>
            <h1 style="color:#fff;font-family:'Poppins',serif;font-size:18px;font-weight:700;" class="flex items-center gap-2">
                <i class="fas fa-history" style="color:#ffd700;"></i> Purchase History
            </h1>
        </div>
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
<div class="container mx-auto px-4 sm:px-6 py-6" style="max-width:1200px;">

<?php if($delete_msg): ?>
<div class="alert-success mb-6"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($delete_msg); ?></div>
<?php endif; ?>

<!-- Stats Overview -->
<div class="grid grid-cols-1 gap-4 md:grid-cols-3 mb-6">
    <div class="stat-box">
        <div class="stat-label">Total Purchase Entries</div>
        <div class="stat-value"><?php echo $total_count; ?></div>
    </div>
    <div class="stat-box">
        <div class="stat-label">Total Amount (Filtered)</div>
        <div class="stat-value">â‚¹ <?php echo fmt_inr($total_amount_sum); ?></div>
    </div>
    <div class="stat-box flex flex-col justify-center">
        <div class="stat-label mb-1">Quick Action</div>
        <a href="purchase.php" class="btn-gold text-center py-2 px-4 rounded-xl text-xs font-bold flex items-center justify-center gap-2">
            <i class="fas fa-plus-circle"></i> Create New Purchase Entry
        </a>
    </div>
</div>

<!-- Filters Card -->
<div class="jewel-card p-5 mb-6">
    <h3 class="text-sm font-bold mb-4" style="color:#7a4e0a;">
        <i class="fas fa-filter mr-1" style="color:#d68b16;"></i> Filter Purchase History
    </h3>
    <form method="GET" class="grid grid-cols-1 gap-4 md:grid-cols-5 items-end">
        <div class="md:col-span-2">
            <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Search (Purchase No / Invoice / Supplier)</label>
            <input type="text" name="search" class="jewel-input w-full rounded-xl px-3 py-2 text-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. PUR-2026 or Supplier Name">
        </div>
        <div>
            <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Material</label>
            <select name="material" class="jewel-input w-full rounded-xl px-3 py-2 text-sm">
                <option value="">All Materials</option>
                <?php foreach(['Gold','Silver','Diamond','Platinum'] as $m): ?>
                <option value="<?php echo $m; ?>" <?php echo $material===$m?'selected':''; ?>><?php echo $m; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">From Date</label>
            <input type="date" name="date_from" class="jewel-input w-full rounded-xl px-3 py-2 text-sm" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        <div>
            <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">To Date</label>
            <input type="date" name="date_to" class="jewel-input w-full rounded-xl px-3 py-2 text-sm" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        <div class="md:col-span-5 flex gap-3 justify-end mt-2">
            <a href="purchase_history.php" class="px-4 py-2 rounded-xl text-xs font-semibold bg-white border border-amber-300 text-amber-900 flex items-center gap-1">
                <i class="fas fa-times"></i> Clear Filters
            </a>
            <button type="submit" class="btn-gold px-6 py-2 rounded-xl text-xs font-bold flex items-center gap-2">
                <i class="fas fa-search"></i> Apply Filters
            </button>
        </div>
    </form>
</div>

<!-- Table Card -->
<div class="jewel-card p-5">
    <?php if (count($rows) === 0): ?>
        <div class="empty-state">
            <i class="fas fa-inbox" style="font-size:40px;margin-bottom:12px;display:block;color:#d68b16;"></i>
            No purchase entries found<?php echo ($search || $material || $date_from || $date_to) ? ' for the selected filters.' : '.'; ?>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="hist-table">
            <thead>
                <tr>
                    <th>Purchase No</th>
                    <th>Purchase Date</th>
                    <th>Invoice No</th>
                    <th>Supplier</th>
                    <th>Material</th>
                    <th>Qty</th>
                    <th>Payment Mode</th>
                    <th>Total Amount</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td style="font-weight:700;color:#7a4e0a;"><?php echo htmlspecialchars($row['purchase_no']); ?></td>
                    <td><?php echo htmlspecialchars(date('d-M-Y', strtotime($row['purchase_date']))); ?></td>
                    <td><?php echo htmlspecialchars($row['invoice_no']); ?></td>
                    <td><strong><?php echo htmlspecialchars($row['supplier_name']); ?></strong></td>
                    <td><span class="mat-pill <?php echo htmlspecialchars($row['material_type']); ?>"><?php echo htmlspecialchars($row['material_type']); ?></span></td>
                    <td style="font-weight:600;"><?php echo rtrim(rtrim(number_format((float)$row['qty'],4),'0'),'.'); ?> <?php echo htmlspecialchars($row['unit']); ?></td>
                    <td><?php echo htmlspecialchars($row['payment_mode']); ?></td>
                    <td style="font-weight:700;color:#800020;">â‚¹ <?php echo fmt_inr($row['total_amount']); ?></td>
                    <td style="text-align:center;">
                        <div class="flex gap-2 justify-center">
                            <a href="purchase_view.php?id=<?php echo intval($row['id']); ?>" class="action-btn action-view" title="View Details">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <form method="POST" onsubmit="return confirm('Delete this purchase entry? Stock quantities will be rolled back.');" style="display:inline;">
                                <input type="hidden" name="delete_id" value="<?php echo intval($row['id']); ?>">
                                <button type="submit" class="action-btn action-delete"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /container -->

<footer style="background:linear-gradient(0deg,#f5e6c8,#fdf6e3);border-top:2px solid #d68b16;padding:20px;margin-top:40px;text-align:center;">
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

