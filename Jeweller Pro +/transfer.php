<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Auto-create stock_transfers table if missing on online/live database
$create_transfers_table_sql = "CREATE TABLE IF NOT EXISTS `stock_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_no` varchar(50) NOT NULL,
  `destination_shop` varchar(150) NOT NULL,
  `transfer_date` date NOT NULL,
  `entry_mode` varchar(20) DEFAULT 'stock',
  `product_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `weight` decimal(10,3) DEFAULT 0.000,
  `quantity` int(11) DEFAULT 1,
  `unit` varchar(20) DEFAULT 'pcs',
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `item_value` decimal(12,2) DEFAULT 0.00,
  `huid_code` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_no` (`transfer_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
mysqli_query($conn, $create_transfers_table_sql);

// Auth check
if (!isset($_SESSION['user_id']) && !isset($_COOKIE['remember_user'])) {
    header("Location: login.php");
    exit();
}

$success = '';
$error = '';

// Handle Create Transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_transfer') {
    $destination_shop = mysqli_real_escape_string($conn, trim($_POST['destination_shop'] ?? ''));
    $transfer_date    = mysqli_real_escape_string($conn, trim($_POST['transfer_date'] ?? date('Y-m-d')));
    $entry_mode       = mysqli_real_escape_string($conn, trim($_POST['entry_mode'] ?? 'stock'));
    $remarks          = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));

    if (empty($destination_shop)) {
        $error = " Please enter or select a Destination Shop / Recipient.";
    } else {
        // Auto-generate Transfer Reference No (TRF-YYYYMMDD-XXXX)
        $today_str = date('Ymd');
        $prefix = 'TRF-' . $today_str . '-';
        $q_seq = mysqli_query($conn, "SELECT transfer_no FROM stock_transfers WHERE transfer_no LIKE 'TRF-%'");
        $existing_nums = [];
        if ($q_seq) {
            while ($r_seq = mysqli_fetch_assoc($q_seq)) {
                $parts = explode('-', $r_seq['transfer_no']);
                $num = intval(end($parts));
                if ($num > 0) $existing_nums[] = $num;
            }
        }
        $next_num = 1;
        if (!empty($existing_nums)) {
            $next_num = max($existing_nums) + 1;
        }
        $transfer_no = $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);

        if ($entry_mode === 'stock') {
            $product_id      = intval($_POST['product_id'] ?? 0);
            $transfer_qty    = intval($_POST['transfer_qty'] ?? 1);
            $transfer_weight = floatval($_POST['transfer_weight'] ?? 0);
            $unit_price      = floatval($_POST['unit_price'] ?? 0);

            if ($product_id <= 0) {
                $error = " Please select a valid product from stock.";
            } else {
                $p_res = mysqli_query($conn, "SELECT * FROM products WHERE id = $product_id");
                if ($p_res && mysqli_num_rows($p_res) > 0) {
                    $prod = mysqli_fetch_assoc($p_res);
                    $curr_qty = intval($prod['quantity'] ?? 0);
                    $curr_weight = floatval($prod['weight'] ?? 0);

                    if ($transfer_qty > $curr_qty) {
                        $error = " Transfer quantity ($transfer_qty) exceeds available stock quantity ($curr_qty)!";
                    } else {
                        $raw_name = !empty($prod['name']) ? $prod['name'] : (!empty($prod['item_name']) ? $prod['item_name'] : ($prod['product_name'] ?? 'Jewellery Item'));
                        $item_name  = mysqli_real_escape_string($conn, $raw_name);
                        $category   = mysqli_real_escape_string($conn, $prod['category'] ?? '');
                        $huid_code  = mysqli_real_escape_string($conn, $prod['huid_code'] ?? $prod['huid'] ?? '');
                        $unit       = mysqli_real_escape_string($conn, $prod['unit'] ?? 'pcs');
                        $item_value = $transfer_qty * $unit_price;

                        // Insert transfer record
                        $ins_sql = "INSERT INTO stock_transfers (transfer_no, destination_shop, transfer_date, entry_mode, product_id, item_name, category, weight, quantity, unit, unit_price, item_value, huid_code, remarks) 
                                    VALUES ('$transfer_no', '$destination_shop', '$transfer_date', 'stock', $product_id, '$item_name', '$category', $transfer_weight, $transfer_qty, '$unit', $unit_price, $item_value, '$huid_code', '$remarks')";
                        
                        if (mysqli_query($conn, $ins_sql)) {
                            // Exact Stock Deduction: deduct quantity and weight
                            $new_qty = max(0, $curr_qty - $transfer_qty);
                            $new_weight = max(0.000, $curr_weight - $transfer_weight);
                            
                            $upd_stock = "UPDATE products SET quantity = $new_qty, weight = $new_weight";
                            // If status column exists, update status
                            $chk_stat = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'status'");
                            if ($chk_stat && mysqli_num_rows($chk_stat) > 0) {
                                if ($new_qty == 0) {
                                    $upd_stock .= ", status = 'Out of Stock'";
                                }
                            }
                            $upd_stock .= " WHERE id = $product_id";
                            mysqli_query($conn, $upd_stock);

                            // Sync with Accounts ledger if accounts table exists
                            $chk_acc = mysqli_query($conn, "SHOW TABLES LIKE 'accounts'");
                            if ($chk_acc && mysqli_num_rows($chk_acc) > 0) {
                                $acc_desc = mysqli_real_escape_string($conn, "Stock Transfer Out: $item_name ($transfer_qty $unit, $transfer_weight g) to $destination_shop [Ref: $transfer_no]");
                                @mysqli_query($conn, "INSERT INTO accounts (type, category, description, amount, date) VALUES ('Transfer Out', 'Stock Transfer', '$acc_desc', $item_value, '$transfer_date')");
                            }

                            $success = " Stock Transfer '$transfer_no' completed successfully! $transfer_qty $unit ($transfer_weight g) deducted from inventory.";
                        } else {
                            $error = " Database Error: " . mysqli_error($conn);
                        }
                    }
                } else {
                    $error = " Selected stock item not found!";
                }
            }
        } else {
            // Manual Entry Mode (for non-stocked items / tracking)
            $item_name       = mysqli_real_escape_string($conn, trim($_POST['manual_item_name'] ?? ''));
            $category        = mysqli_real_escape_string($conn, trim($_POST['manual_category'] ?? 'Gold'));
            $huid_code       = mysqli_real_escape_string($conn, trim($_POST['manual_huid_code'] ?? ''));
            $transfer_weight = floatval($_POST['manual_weight'] ?? 0);
            $transfer_qty    = intval($_POST['manual_quantity'] ?? 1);
            $unit            = mysqli_real_escape_string($conn, trim($_POST['manual_unit'] ?? 'pcs'));
            $unit_price      = floatval($_POST['manual_unit_price'] ?? 0);
            $item_value      = floatval($_POST['manual_item_value'] ?? ($transfer_qty * $unit_price));

            if (empty($item_name)) {
                $error = " Please enter the Item Name for manual transfer record.";
            } else {
                $ins_sql = "INSERT INTO stock_transfers (transfer_no, destination_shop, transfer_date, entry_mode, product_id, item_name, category, weight, quantity, unit, unit_price, item_value, huid_code, remarks) 
                            VALUES ('$transfer_no', '$destination_shop', '$transfer_date', 'manual', NULL, '$item_name', '$category', $transfer_weight, $transfer_qty, '$unit', $unit_price, $item_value, '$huid_code', '$remarks')";
                
                if (mysqli_query($conn, $ins_sql)) {
                    // Sync with Accounts ledger if accounts table exists
                    $chk_acc = mysqli_query($conn, "SHOW TABLES LIKE 'accounts'");
                    if ($chk_acc && mysqli_num_rows($chk_acc) > 0) {
                        $acc_desc = mysqli_real_escape_string($conn, "Manual Transfer Record: $item_name ($transfer_qty $unit, $transfer_weight g) to $destination_shop [Ref: $transfer_no]");
                        @mysqli_query($conn, "INSERT INTO accounts (type, category, description, amount, date) VALUES ('Transfer Out', 'Stock Transfer', '$acc_desc', $item_value, '$transfer_date')");
                    }

                    $success = " Manual Transfer Record '$transfer_no' created successfully!";
                } else {
                    $error = " Database Error: " . mysqli_error($conn);
                }
            }
        }
    }
}

// Handle Delete Transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_transfer') {
    $del_id = intval($_POST['transfer_id'] ?? 0);
    $q_del = mysqli_query($conn, "SELECT * FROM stock_transfers WHERE id = $del_id");
    if ($q_del && mysqli_num_rows($q_del) > 0) {
        $trf_row = mysqli_fetch_assoc($q_del);
        // Restore stock if it was a stock entry
        if ($trf_row['entry_mode'] === 'stock' && !empty($trf_row['product_id'])) {
            $pid = intval($trf_row['product_id']);
            $r_qty = intval($trf_row['quantity']);
            $r_wt  = floatval($trf_row['weight']);
            mysqli_query($conn, "UPDATE products SET quantity = quantity + $r_qty, weight = weight + $r_wt WHERE id = $pid");
        }
        mysqli_query($conn, "DELETE FROM stock_transfers WHERE id = $del_id");
        $success = " Transfer record deleted successfully and stock quantity/weight restored.";
    }
}

// Fetch Active Products for Stock Select Dropdown
$products_list = [];
$p_query = mysqli_query($conn, "SELECT * FROM products WHERE quantity > 0 ORDER BY id DESC");
if ($p_query) {
    while ($r = mysqli_fetch_assoc($p_query)) {
        $products_list[] = $r;
    }
}

// Filters for History Table
$search_term = mysqli_real_escape_string($conn, trim($_GET['search'] ?? ''));
$filter_shop = mysqli_real_escape_string($conn, trim($_GET['shop'] ?? ''));
$from_date   = mysqli_real_escape_string($conn, trim($_GET['from_date'] ?? ''));
$to_date     = mysqli_real_escape_string($conn, trim($_GET['to_date'] ?? ''));

$where_clauses = ["1=1"];
if (!empty($search_term)) {
    $where_clauses[] = "(transfer_no LIKE '%$search_term%' OR item_name LIKE '%$search_term%' OR destination_shop LIKE '%$search_term%' OR huid_code LIKE '%$search_term%')";
}
if (!empty($filter_shop)) {
    $where_clauses[] = "destination_shop = '$filter_shop'";
}
if (!empty($from_date)) {
    $where_clauses[] = "transfer_date >= '$from_date'";
}
if (!empty($to_date)) {
    $where_clauses[] = "transfer_date <= '$to_date'";
}
$where_sql = implode(' AND ', $where_clauses);

// Fetch Transfers History
$transfers = [];
$tot_count = 0;
$tot_weight = 0.000;
$tot_value = 0.00;

$t_res = mysqli_query($conn, "SELECT * FROM stock_transfers WHERE $where_sql ORDER BY id DESC");
if ($t_res) {
    while ($r = mysqli_fetch_assoc($t_res)) {
        $transfers[] = $r;
        $tot_count++;
        $tot_weight += floatval($r['weight']);
        $tot_value  += floatval($r['item_value']);
    }
}

// Fetch Distinct Shops for Filter Dropdown
$shops_list = [];
$s_res = mysqli_query($conn, "SELECT DISTINCT destination_shop FROM stock_transfers ORDER BY destination_shop ASC");
if ($s_res) {
    while ($sr = mysqli_fetch_assoc($s_res)) {
        if (!empty($sr['destination_shop'])) $shops_list[] = $sr['destination_shop'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gold &amp; Stock Transfers - MAA GOURI JEWELLERS</title>
    <link rel="icon" type="image/png" href="logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', sans-serif; }
        .font-serif-title { font-family: 'Playfair Display', serif; }
        .sidebar { width: 240px; background: linear-gradient(180deg, #011921 0%, #03373b 50%, #044e54 80%, #011921 100%); height: 100vh; position: fixed; left:0; top:0; z-index: 1000; display:flex; flex-direction:column; box-shadow:4px 0 24px rgba(0,0,0,0.25); overflow:hidden; }
        .sidebar-logo { padding: 22px 18px 16px; border-bottom: 1px solid rgba(255,255,255,0.18); display:flex; align-items:center; gap:12px; flex-shrink:0; }
        .sidebar-logo img { width: 44px; height: 44px; object-fit: contain; border-radius:50%; background:rgba(255,255,255,0.1); padding:3px; flex-shrink:0; }
        .sidebar-logo-text h2 { color: #fff; font-size: 13px; font-weight: 700; letter-spacing: 0.5px; line-height: 1.3; font-family: 'Poppins', serif; }
        .sidebar-logo-text p { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }
        .sidebar-nav { flex:1; padding: 10px 0; overflow-y: auto; overflow-x:hidden; }
        .sidebar-section-label { padding: 10px 20px 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; color: #f5c842; letter-spacing: 1.5px; position: sticky; top: 0; background: #011921; z-index: 10; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); font-size: 13px; font-weight: 500; transition: all 0.2s; text-decoration: none; border-left: 3px solid transparent; position: relative; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
        .sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
        .sidebar-nav a.active::after { content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 60%; background: #fff; border-radius: 4px 0 0 4px; }
        .sidebar-nav a i { font-size: 14px; width: 18px; text-align: center; flex-shrink: 0; opacity: 0.9; }
        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }
        .sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }
        .sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; border: 1px solid rgba(239,68,68,0.4); }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }
        .page-wrapper { margin-left: 240px; min-height: 100vh; background: #f8fafc; padding: 28px; }
        
        .gold-card { background: linear-gradient(135deg, #ffffff 0%, #fffbf0 100%); border: 1.5px solid #fef3c7; border-radius: 14px; box-shadow: 0 4px 16px rgba(217, 119, 6, 0.08); }
        .tab-btn { padding: 10px 20px; font-size: 13px; font-weight: 600; border-radius: 8px; transition: all 0.2s; cursor: pointer; }
        .tab-btn.active { background: linear-gradient(135deg, #7a4e0a, #d68b16); color: #fff; box-shadow: 0 4px 12px rgba(214,139,22,0.3); }
        .tab-btn:not(.active) { background: #f1f5f9; color: #64748b; }
        .tab-btn:not(.active):hover { background: #e2e8f0; color: #334155; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800">

    <!-- Sidebar Navigation -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <?php 
            $logo_paths_t = ['assets/images/moti-removebg-preview.png', 'images/moti-removebg-preview.png', 'moti-removebg-preview.png', 'radhey shyam logo.png'];
            $logo_found = false;
            foreach ($logo_paths_t as $lf) {
                if (file_exists($lf)) {
                    echo '<img src="'.$lf.'" alt="Maa Gouri Logo">';
                    $logo_found = true;
                    break;
                }
            }
            if (!$logo_found) echo '<i class="fas fa-gem" style="color:#fff;font-size:30px;"></i>';
            ?>
            <div class="sidebar-logo-text">
                <h2>MAA GOURI JEWELLERS</h2>
                <p>Premium Since 2026</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Main Menu</div>
            <a href="index.php"><i class="fas fa-home"></i> DASHBOARD</a>
            <a href="billing.php"><i class="fas fa-receipt"></i> BILLING</a>
            <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
            <a href="customers.php"><i class="fas fa-users"></i> CUSTOMERS</a>
            <a href="transfer.php" class="active"><i class="fas fa-exchange-alt"></i> TRANSFER</a>

            <div class="sidebar-divider"></div>
            <div class="sidebar-section-label">Analytics</div>
            <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
            <a href="due_list.php"><i class="fas fa-hourglass-half"></i> DUE LIST</a>
            <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>

            <div class="sidebar-divider"></div>
            <div class="sidebar-section-label">Tools</div>
            <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
            <a href="purchase.php"><i class="fas fa-book"></i> PURCHASE</a>
            <a href="contacts.php"><i class="fas fa-address-book"></i> CONTACTS</a>
            <a href="accounts.php"><i class="fas fa-calculator"></i> ACCOUNTS</a>
        </nav>

        <div class="sidebar-user">
            <div class="sidebar-user-info">
                <i class="fas fa-user-circle"></i>
                <div class="user-details">
                    <p><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></p>
                    <span><?php echo htmlspecialchars($_SESSION['user_mobile'] ?? 'Admin'); ?></span>
                </div>
            </div>
            <a href="logout.php" class="sidebar-logout">
                <i class="fas fa-sign-out-alt"></i> LOGOUT
            </a>
        </div>
    </aside>

    <!-- Main Page Content -->
    <div class="page-wrapper">
        
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold font-serif-title text-slate-900 flex items-center gap-2">
                    <i class="fas fa-exchange-alt text-amber-600"></i> Gold &amp; Stock Transfers
                </h1>
                <p class="text-xs text-slate-500 mt-1">Transfer gold items to other shops/branches with exact stock deduction and printable vouchers</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="stock.php" class="px-4 py-2 bg-white border border-slate-300 rounded-lg text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    <i class="fas fa-boxes mr-1 text-amber-600"></i> View Inventory
                </a>
                <a href="reports.php" class="px-4 py-2 bg-gradient-to-r from-amber-700 to-amber-600 text-white rounded-lg text-xs font-bold shadow-sm hover:from-amber-800 hover:to-amber-700">
                    <i class="fas fa-file-pdf mr-1"></i> Reports Center
                </a>
            </div>
        </div>

        <!-- Alert Notifications -->
        <?php if (!empty($success)): ?>
            <div class="mb-6 p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 text-xs font-semibold rounded-r-lg shadow-sm flex items-center justify-between">
                <div><?php echo $success; ?></div>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="mb-6 p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-800 text-xs font-semibold rounded-r-lg shadow-sm flex items-center justify-between">
                <div><?php echo $error; ?></div>
                <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-900">&times;</button>
            </div>
        <?php endif; ?>

        <!-- KPI Metrics Bar -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
            <div class="bg-white p-5 rounded-xl border border-amber-200 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xl font-bold">
                    <i class="fas fa-shipping-fast"></i>
                </div>
                <div>
                    <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Transfers</div>
                    <div class="text-xl font-bold text-slate-900 mt-0.5"><?php echo $tot_count; ?> Records</div>
                </div>
            </div>

            <div class="bg-white p-5 rounded-xl border border-amber-200 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xl font-bold">
                    <i class="fas fa-weight-hanging"></i>
                </div>
                <div>
                    <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Transferred Weight</div>
                    <div class="text-xl font-bold text-slate-900 mt-0.5"><?php echo number_format($tot_weight, 3); ?> Grams</div>
                </div>
            </div>

            <div class="bg-white p-5 rounded-xl border border-amber-200 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold">
                    <i class="fas fa-indian-rupee-sign"></i>
                </div>
                <div>
                    <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Transferred Value</div>
                    <div class="text-xl font-bold text-slate-900 mt-0.5">₹<?php echo number_format($tot_value, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Transfer Entry Form Card -->
        <div class="gold-card p-6 mb-8">
            <div class="flex items-center justify-between mb-6 pb-4 border-b border-amber-200">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 font-serif-title flex items-center gap-2">
                        <i class="fas fa-paper-plane text-amber-600"></i> New Gold / Item Transfer Form
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">Select stock item to automatically deduct quantity/weight, or choose manual entry</p>
                </div>
                <!-- Entry Mode Switcher Tabs -->
                <div class="flex gap-2">
                    <button type="button" id="tabStock" onclick="switchMode('stock')" class="tab-btn active">
                        <i class="fas fa-box-open mr-1"></i> Select From Stock
                    </button>
                    <button type="button" id="tabManual" onclick="switchMode('manual')" class="tab-btn">
                        <i class="fas fa-pen-to-square mr-1"></i> Manual Entry Record
                    </button>
                </div>
            </div>

            <form action="transfer.php" method="POST" id="transferForm">
                <input type="hidden" name="action" value="create_transfer">
                <input type="hidden" name="entry_mode" id="entry_mode" value="stock">

                <!-- Shared Row: Destination Shop & Transfer Date -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Destination Shop / Recipient <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="destination_shop" list="shop_suggestions" required placeholder="Enter shop name or recipient" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold focus:outline-none focus:border-amber-500">
                        <datalist id="shop_suggestions">
                            <?php foreach($shops_list as $sl): ?>
                            <option value="<?php echo htmlspecialchars($sl); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Transfer Date <span class="text-rose-500">*</span>
                        </label>
                        <input type="date" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold focus:outline-none focus:border-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Remarks / Transfer Note
                        </label>
                        <input type="text" name="remarks" placeholder="Optional notes (e.g., For hallmarking / Exchange)" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold focus:outline-none focus:border-amber-500">
                    </div>
                </div>

                <!-- MODE A: SELECT FROM STOCK FIELDS -->
                <div id="stockFields" class="bg-white p-5 rounded-xl border border-amber-200 mb-5">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-4">
                        <div class="md:col-span-2">
                            <div class="flex justify-between items-center mb-1.5">
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">
                                    Select Product From Inventory <span class="text-rose-500">*</span>
                                </label>
                                <span id="stock_match_badge" class="text-[10px] text-amber-700 font-bold bg-amber-100 px-2 py-0.5 rounded-full hidden"></span>
                            </div>

                            <!-- Search Input Box -->
                            <div class="relative mb-2">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-amber-600">
                                    <i class="fas fa-search text-xs"></i>
                                </div>
                                <input type="text" id="stock_search_input" oninput="filterStockDropdown(this.value)" placeholder="Type to search stock by Name, Category, HUID, or Serial..." class="w-full pl-9 pr-8 py-2 bg-amber-50/70 border border-amber-300 rounded-lg text-xs font-semibold text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500">
                                <button type="button" onclick="clearStockSearch()" id="clear_stock_search_btn" class="hidden absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600">
                                    <i class="fas fa-xmark text-xs"></i>
                                </button>
                            </div>

                            <select name="product_id" id="product_select" onchange="onStockProductChange()" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-bold text-slate-900 focus:outline-none focus:border-amber-500">
                                <option value="">-- Choose Product to Transfer --</option>
                                <?php foreach ($products_list as $p): ?>
                                    <?php 
                                    $raw_pname = !empty($p['name']) ? $p['name'] : (!empty($p['item_name']) ? $p['item_name'] : ($p['product_name'] ?? 'Item'));
                                    $pname = htmlspecialchars($raw_pname);
                                    $pcat  = htmlspecialchars($p['category'] ?? '');
                                    $pqty  = intval($p['quantity'] ?? 0);
                                    $pwt   = floatval($p['weight'] ?? 0);
                                    $pprice = floatval($p['price'] ?? $p['unit_price'] ?? 0);
                                    $phuid = htmlspecialchars($p['huid_code'] ?? $p['huid'] ?? '');
                                    ?>
                                    <option value="<?php echo $p['id']; ?>" 
                                            data-name="<?php echo $pname; ?>"
                                            data-category="<?php echo $pcat; ?>"
                                            data-qty="<?php echo $pqty; ?>"
                                            data-weight="<?php echo $pwt; ?>"
                                            data-price="<?php echo $pprice; ?>"
                                            data-huid="<?php echo $phuid; ?>">
                                        <?php echo $pname; ?> (Category: <?php echo $pcat; ?> | Stock Qty: <?php echo $pqty; ?> | Weight: <?php echo $pwt; ?>g)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Available Stock Info
                            </label>
                            <div class="px-3.5 py-2.5 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-900 font-semibold" id="stockInfoBox">
                                Select a product to view stock info
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Unit Price (₹)
                            </label>
                            <input type="number" step="0.01" name="unit_price" id="stock_unit_price" placeholder="0.00" oninput="calcStockTotal()" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-bold focus:outline-none focus:border-amber-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                        <div>
                            <label class="block text-xs font-bold text-amber-900 uppercase tracking-wider mb-1.5">
                                Transfer Quantity (Pcs) <span class="text-rose-500">*</span>
                            </label>
                            <input type="number" min="1" name="transfer_qty" id="stock_transfer_qty" value="1" oninput="calcStockTotal()" class="w-full px-3.5 py-2.5 bg-white border border-amber-400 rounded-lg text-xs font-bold focus:outline-none focus:border-amber-600">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-amber-900 uppercase tracking-wider mb-1.5">
                                Exact Transferred Weight (Grams) <span class="text-rose-500">*</span>
                            </label>
                            <input type="number" step="0.001" min="0" name="transfer_weight" id="stock_transfer_weight" value="0.000" class="w-full px-3.5 py-2.5 bg-white border border-amber-400 rounded-lg text-xs font-bold focus:outline-none focus:border-amber-600">
                            <span class="text-[10px] text-amber-700">This exact weight will be deducted from inventory</span>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Total Transfer Value (Calculated ₹)
                            </label>
                            <input type="text" id="stock_calc_total" readonly value="₹ 0.00" class="w-full px-3.5 py-2.5 bg-slate-100 border border-slate-300 rounded-lg text-xs font-bold text-slate-900">
                        </div>
                    </div>
                </div>

                <!-- MODE B: MANUAL ENTRY FIELDS -->
                <div id="manualFields" class="bg-white p-5 rounded-xl border border-slate-200 mb-5 hidden">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Item Name / Description <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" name="manual_item_name" id="manual_item_name" placeholder="e.g. Gold Necklace 22K" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold focus:outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Category
                            </label>
                            <select name="manual_category" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold focus:outline-none focus:border-amber-500">
                                <option value="Gold">Gold</option>
                                <option value="Silver">Silver</option>
                                <option value="Diamond">Diamond</option>
                                <option value="Gemstones">Gemstones</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                HUID / Hallmark Code
                            </label>
                            <input type="text" name="manual_huid_code" placeholder="e.g. HUID123456" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold focus:outline-none focus:border-amber-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Weight (Grams)
                            </label>
                            <input type="number" step="0.001" name="manual_weight" value="0.000" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold focus:outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Quantity (Pcs)
                            </label>
                            <input type="number" min="1" name="manual_quantity" value="1" oninput="calcManualTotal()" id="manual_qty" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold focus:outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Unit Price (₹)
                            </label>
                            <input type="number" step="0.01" name="manual_unit_price" id="manual_price" value="0.00" oninput="calcManualTotal()" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold focus:outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Total Transfer Value (₹)
                            </label>
                            <input type="number" step="0.01" name="manual_item_value" id="manual_val" value="0.00" class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-xs font-bold text-slate-900 focus:outline-none focus:border-amber-500">
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-3 bg-gradient-to-r from-amber-700 to-amber-600 text-white rounded-lg text-xs font-bold shadow-md hover:from-amber-800 hover:to-amber-700 flex items-center gap-2">
                        <i class="fas fa-check-circle"></i> Complete &amp; Save Stock Transfer
                    </button>
                </div>
            </form>
        </div>

        <!-- Transfer History Table Section -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            
            <!-- Table Filter Bar -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6 pb-4 border-b border-slate-100">
                <h3 class="text-base font-bold text-slate-900 font-serif-title flex items-center gap-2">
                    <i class="fas fa-history text-amber-600"></i> Transfer History &amp; Records
                </h3>

                <form action="transfer.php" method="GET" class="flex flex-wrap items-center gap-3">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Search Ref, Item, Shop..." class="px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-md text-xs focus:outline-none focus:border-amber-500">
                    
                    <select name="shop" class="px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-md text-xs focus:outline-none focus:border-amber-500">
                        <option value="">All Destination Shops</option>
                        <?php foreach ($shops_list as $sl): ?>
                            <option value="<?php echo htmlspecialchars($sl); ?>" <?php echo $filter_shop === $sl ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sl); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-md text-xs focus:outline-none focus:border-amber-500">
                    <span class="text-xs text-slate-400">to</span>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-md text-xs focus:outline-none focus:border-amber-500">

                    <button type="submit" class="px-3.5 py-1.5 bg-slate-800 text-white rounded-md text-xs font-bold hover:bg-slate-900">
                        <i class="fas fa-filter mr-1"></i> Filter
                    </button>
                    <?php if(!empty($search_term) || !empty($filter_shop) || !empty($from_date) || !empty($to_date)): ?>
                        <a href="transfer.php" class="px-3 py-1.5 bg-slate-200 text-slate-700 rounded-md text-xs font-semibold hover:bg-slate-300">
                            Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100 border-b border-slate-200 text-slate-700 font-bold uppercase tracking-wider">
                            <th class="p-3">Ref No</th>
                            <th class="p-3">Date</th>
                            <th class="p-3">Destination Shop</th>
                            <th class="p-3">Item Details</th>
                            <th class="p-3 text-right">Weight (g)</th>
                            <th class="p-3 text-center">Qty</th>
                            <th class="p-3 text-right">Total Value</th>
                            <th class="p-3 text-center">Mode</th>
                            <th class="p-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="9" class="p-8 text-center text-slate-400 font-medium">
                                    No stock transfer records found matching your filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $t): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="p-3 font-bold text-amber-700">
                                        <?php echo htmlspecialchars($t['transfer_no']); ?>
                                    </td>
                                    <td class="p-3 font-medium text-slate-600">
                                        <?php echo date('d-M-Y', strtotime($t['transfer_date'])); ?>
                                    </td>
                                    <td class="p-3 font-bold text-slate-900">
                                        <?php echo htmlspecialchars($t['destination_shop']); ?>
                                    </td>
                                    <td class="p-3">
                                        <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($t['item_name']); ?></div>
                                        <div class="text-[10px] text-slate-500">
                                            Category: <?php echo htmlspecialchars($t['category'] ?? 'N/A'); ?> 
                                            <?php if(!empty($t['huid_code'])): ?> | HUID: <?php echo htmlspecialchars($t['huid_code']); ?><?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="p-3 text-right font-bold text-slate-900">
                                        <?php echo number_format(floatval($t['weight']), 3); ?> g
                                    </td>
                                    <td class="p-3 text-center font-bold text-slate-800">
                                        <?php echo intval($t['quantity']); ?> <?php echo htmlspecialchars($t['unit']); ?>
                                    </td>
                                    <td class="p-3 text-right font-bold text-emerald-700">
                                        ₹<?php echo number_format(floatval($t['item_value']), 2); ?>
                                    </td>
                                    <td class="p-3 text-center">
                                        <?php if($t['entry_mode'] === 'stock'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Stock</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700">Manual</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="view_transfer_pdf.php?id=<?php echo $t['id']; ?>" target="_blank" class="px-2.5 py-1 bg-amber-600 text-white rounded text-[11px] font-bold hover:bg-amber-700 flex items-center gap-1">
                                                <i class="fas fa-file-pdf"></i> Voucher
                                            </a>
                                            <form action="transfer.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this transfer? (If from stock, quantity/weight will be restored)');">
                                                <input type="hidden" name="action" value="delete_transfer">
                                                <input type="hidden" name="transfer_id" value="<?php echo $t['id']; ?>">
                                                <button type="submit" class="px-2 py-1 bg-rose-50 text-rose-600 hover:bg-rose-100 rounded text-[11px]">
                                                    <i class="fas fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Mode Switch & Calculation JS -->
    <script>
        function switchMode(mode) {
            document.getElementById('entry_mode').value = mode;
            if (mode === 'stock') {
                document.getElementById('tabStock').classList.add('active');
                document.getElementById('tabManual').classList.remove('active');
                document.getElementById('stockFields').classList.remove('hidden');
                document.getElementById('manualFields').classList.add('hidden');
            } else {
                document.getElementById('tabManual').classList.add('active');
                document.getElementById('tabStock').classList.remove('active');
                document.getElementById('manualFields').classList.remove('hidden');
                document.getElementById('stockFields').classList.add('hidden');
            }
        }

        function onStockProductChange() {
            const sel = document.getElementById('product_select');
            const opt = sel.options[sel.selectedIndex];
            const infoBox = document.getElementById('stockInfoBox');

            if (opt && opt.value !== '') {
                const qty = parseInt(opt.getAttribute('data-qty') || '0');
                const wt = parseFloat(opt.getAttribute('data-weight') || '0');
                const price = parseFloat(opt.getAttribute('data-price') || '0');

                infoBox.innerHTML = `<strong>Available Stock:</strong> ${qty} Pcs | <strong>Weight:</strong> ${wt.toFixed(3)}g`;
                document.getElementById('stock_transfer_qty').value = 1;
                document.getElementById('stock_transfer_weight').value = wt > 0 ? wt.toFixed(3) : "0.000";
                document.getElementById('stock_unit_price').value = price > 0 ? price.toFixed(2) : "0.00";
                calcStockTotal();
            } else {
                infoBox.innerHTML = 'Select a product to view stock info';
                document.getElementById('stock_calc_total').value = '₹ 0.00';
            }
        }

        function calcStockTotal() {
            const qty = parseInt(document.getElementById('stock_transfer_qty').value || '1');
            const price = parseFloat(document.getElementById('stock_unit_price').value || '0');
            const tot = qty * price;
            document.getElementById('stock_calc_total').value = '₹ ' + tot.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function calcManualTotal() {
            const qty = parseInt(document.getElementById('manual_qty').value || '1');
            const price = parseFloat(document.getElementById('manual_price').value || '0');
            const tot = qty * price;
            document.getElementById('manual_val').value = tot.toFixed(2);
        }

        function filterStockDropdown(query) {
            const sel = document.getElementById('product_select');
            if (!sel) return;
            const options = sel.options;
            const clearBtn = document.getElementById('clear_stock_search_btn');
            const badge = document.getElementById('stock_match_badge');
            
            const q = query.trim().toLowerCase();
            let matchCount = 0;
            let firstMatchIndex = -1;

            if (q.length > 0) {
                if (clearBtn) clearBtn.classList.remove('hidden');
            } else {
                if (clearBtn) clearBtn.classList.add('hidden');
                if (badge) badge.classList.add('hidden');
            }

            for (let i = 1; i < options.length; i++) {
                const opt = options[i];
                const name = (opt.getAttribute('data-name') || '').toLowerCase();
                const cat = (opt.getAttribute('data-category') || '').toLowerCase();
                const huid = (opt.getAttribute('data-huid') || '').toLowerCase();
                const text = opt.text.toLowerCase();

                const isMatch = (q === '') || text.includes(q) || name.includes(q) || cat.includes(q) || huid.includes(q);

                if (isMatch) {
                    opt.hidden = false;
                    opt.style.display = '';
                    matchCount++;
                    if (firstMatchIndex === -1) firstMatchIndex = i;
                } else {
                    opt.hidden = true;
                    opt.style.display = 'none';
                }
            }

            if (q.length > 0) {
                if (badge) {
                    badge.textContent = `${matchCount} product(s) found`;
                    badge.classList.remove('hidden');
                }

                // If only 1 product matches, auto select it
                if (matchCount === 1 && firstMatchIndex !== -1) {
                    sel.selectedIndex = firstMatchIndex;
                    onStockProductChange();
                } else if (sel.selectedIndex > 0 && options[sel.selectedIndex].hidden) {
                    sel.selectedIndex = 0;
                    onStockProductChange();
                }
            }
        }

        function clearStockSearch() {
            const input = document.getElementById('stock_search_input');
            if (input) {
                input.value = '';
                filterStockDropdown('');
                input.focus();
            }
        }
    </script>
</body>
</html>
