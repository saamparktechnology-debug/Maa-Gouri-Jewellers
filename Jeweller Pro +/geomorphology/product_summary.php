<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/company_config.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../includes/activity_logger.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';
$logo_paths = ['../assets/images/moti-removebg-preview.png', '../images/moti-removebg-preview.png', '../moti-removebg-preview.png', '../logo.png'];
$active_logo = '../logo.png';
foreach ($logo_paths as $lp) {
    if (file_exists(__DIR__ . '/' . $lp)) {
        $active_logo = $lp;
        break;
    }
}

init_activity_log_table($conn);
@mysqli_query($conn, "UPDATE product_activity_logs SET is_nongst = 1 WHERE invoice_no LIKE 'CM-%' OR invoice_no IN (SELECT invoice_no FROM invoices WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)))");

// Handle Delete Activity Log Request
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'delete_log') {
    $log_id = intval($_REQUEST['log_id'] ?? ($_REQUEST['id'] ?? 0));
    $is_ajax = isset($_REQUEST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    
    if ($log_id > 0) {
        $del = mysqli_query($conn, "DELETE FROM product_activity_logs WHERE id = $log_id");
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => (bool)$del, 'message' => $del ? 'Record deleted successfully' : mysqli_error($conn)]);
            exit;
        }
        if ($del) {
            $_SESSION['flash_success'] = "Activity record deleted successfully.";
        } else {
            $_SESSION['flash_error'] = "Failed to delete activity record: " . mysqli_error($conn);
        }
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid Log ID']);
            exit;
        }
    }
    header("Location: product_summary.php");
    exit;
}

// --- FILTER & QUERY LOGIC (NON-GST) ---
$filter_type = isset($_GET['type']) ? mysqli_real_escape_string($conn, trim($_GET['type'])) : 'all';
$filter_cat  = isset($_GET['category']) ? mysqli_real_escape_string($conn, trim($_GET['category'])) : 'all';
$filter_time = isset($_GET['timeframe']) ? mysqli_real_escape_string($conn, trim($_GET['timeframe'])) : 'all';
$from_date   = isset($_GET['from_date']) ? mysqli_real_escape_string($conn, trim($_GET['from_date'])) : '';
$to_date     = isset($_GET['to_date']) ? mysqli_real_escape_string($conn, trim($_GET['to_date'])) : '';
$search      = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';

$where_clauses = ["(is_nongst = 1 OR invoice_no LIKE 'CM-%')"];

if ($filter_type !== 'all' && !empty($filter_type)) {
    $where_clauses[] = "activity_type = '$filter_type'";
}

if ($filter_cat !== 'all' && !empty($filter_cat)) {
    $where_clauses[] = "category = '$filter_cat'";
}

if ($filter_time === 'today') {
    $where_clauses[] = "DATE(created_at) = CURDATE()";
} elseif ($filter_time === 'yesterday') {
    $where_clauses[] = "DATE(created_at) = SUBDATE(CURDATE(), 1)";
} elseif ($filter_time === '7days') {
    $where_clauses[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($filter_time === 'this_month') {
    $where_clauses[] = "MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
} elseif ($filter_time === 'custom' && !empty($from_date) && !empty($to_date)) {
    $where_clauses[] = "DATE(created_at) BETWEEN '$from_date' AND '$to_date'";
}

if (!empty($search)) {
    $where_clauses[] = "(
        product_name LIKE '%$search%' OR 
        serial_no LIKE '%$search%' OR 
        huid_code LIKE '%$search%' OR 
        invoice_no LIKE '%$search%' OR 
        customer_name LIKE '%$search%' OR
        details LIKE '%$search%'
    )";
}

$where_sql = implode(' AND ', $where_clauses);

// --- KPI METRICS (NON-GST ONLY) ---
$total_billed_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt, SUM(weight) as total_wt, SUM(total_amount) as total_val FROM product_activity_logs WHERE (is_nongst = 1 OR invoice_no LIKE 'CM-%') AND activity_type='billed'"));
$total_memos_res  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT invoice_no) as memo_cnt FROM product_activity_logs WHERE (is_nongst = 1 OR invoice_no LIKE 'CM-%') AND invoice_no IS NOT NULL"));
$total_wt_billed  = floatval($total_billed_res['total_wt'] ?? 0);
$total_val_billed = floatval($total_billed_res['total_val'] ?? 0);
$total_cnt_billed = intval($total_billed_res['cnt'] ?? 0);
$total_memos_cnt  = intval($total_memos_res['memo_cnt'] ?? 0);

// Distinct categories
$cats_res = mysqli_query($conn, "SELECT DISTINCT category FROM product_activity_logs WHERE (is_nongst = 1 OR invoice_no LIKE 'CM-%') AND category IS NOT NULL AND category != '' ORDER BY category ASC");
$categories = [];
while($c = mysqli_fetch_assoc($cats_res)) { $categories[] = $c['category']; }

// Fetch Activity Logs
$logs_res = mysqli_query($conn, "SELECT * FROM product_activity_logs WHERE $where_sql ORDER BY created_at DESC, id DESC LIMIT 500");

// Helper for relative time
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = [
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'min',
        's' => 'sec',
    ];
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Summary (Non-GST) - GEOMORPHOLOGY</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3 { font-family: 'Poppins', sans-serif; font-weight: 700; }

        /* Sidebar Styles */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 240px; height: 100vh;
            background: linear-gradient(180deg, #011921 0%, #03373b 50%, #044e54 80%, #011921 100%);
            z-index: 1000;
            display: flex; flex-direction: column;
            box-shadow: 4px 0 24px rgba(0,0,0,0.25);
            transition: transform 0.35s cubic-bezier(.4,0,.2,1);
            overflow: hidden;
        }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        .sidebar-logo {
            padding: 22px 18px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.18);
            display: flex; align-items: center; gap: 12px; flex-shrink: 0;
        }
        .sidebar-logo img {
            width: 44px; height: 44px; object-fit: cover;
            border-radius: 50%; background: rgba(255,255,255,0.1); flex-shrink: 0;
        }
        .sidebar-logo-text h2 {
            color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3;
            font-family: 'Poppins', serif; letter-spacing: 0.5px;
        }
        .sidebar-logo-text p { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }

        .sidebar-nav {
            flex: 1;
            padding: 10px 0;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .sidebar-section-label {
            padding: 10px 20px 4px;
            color: #f5c842;
            font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
            position: sticky; top: 0; background: #011921; z-index: 10;
        }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 20px;
            color: rgba(255,255,255,0.85);
            text-decoration: none; font-size: 13px; font-weight: 500;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }
        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.08);
            color: #ffd700;
            border-left-color: #ffd700;
        }
        .sidebar-nav a.active {
            background: rgba(255,215,0,0.15);
            color: #ffd700;
            border-left-color: #ffd700;
            font-weight: 600;
        }
        .page-wrapper { margin-left: 240px; transition: margin-left 0.35s ease; }
        .nav-gold { background: linear-gradient(135deg, #011921 0%, #03373b 50%, #044e54 100%); border-bottom: 2px solid #b5730e; }

        .kpi-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid rgba(181,115,14,0.18);
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
            transition: all 0.25s ease;
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(181,115,14,0.12); }

        @media (max-width: 1023px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .page-wrapper { margin-left: 0; }
        }
    </style>
</head>
<body class="bg-[#fffbf4] text-gray-800 antialiased">

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <img src="<?php echo htmlspecialchars($active_logo); ?>" alt="Logo" onerror="this.src='../logo.png'">
            <div class="sidebar-logo-text">
                <h2>MAA GOURI JEWELLERS</h2>
                <p>GEOMORPHOLOGY PORTAL</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Geomorphology Menu</div>
            <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="reports.php"><i class="fas fa-chart-bar"></i> Sales Reports</a>
            <a href="customers.php"><i class="fas fa-users"></i> Customers</a>
            <a href="product_summary.php" class="active"><i class="fas fa-boxes"></i> Product Summary</a>
            <a href="accounts.php"><i class="fas fa-wallet"></i> Accounts Ledger</a>
            <a href="due_list.php"><i class="fas fa-file-invoice-dollar"></i> Due Management</a>
            <a href="income_expenses.php"><i class="fas fa-exchange-alt"></i> Income &amp; Expenses</a>

            <div class="sidebar-section-label mt-4">Security</div>
            <a href="index.php?geo_action=lock" class="text-rose-300 hover:text-rose-100"><i class="fas fa-lock text-rose-400"></i> Lock Section</a>

            <div class="sidebar-section-label mt-4">Switch System</div>
            <a href="index.php?geo_action=exit_to_main&target=../billing.php"><i class="fas fa-cash-register" style="color:#10b981;"></i> New Bill (Billing)</a>
            <a href="index.php?geo_action=exit_to_main&target=../index.php"><i class="fas fa-arrow-left" style="color:#60a5fa;"></i> Back to Main GST App</a>
        </nav>
    </aside>

    <!-- Main Wrapper -->
    <div class="page-wrapper flex flex-col min-h-screen">

        <!-- Top Navbar -->
        <nav class="nav-gold text-white px-4 py-3 sticky top-0 z-40 flex items-center justify-between shadow-lg">
            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('sidebar').classList.toggle('open')" class="lg:hidden text-white text-xl p-1">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="flex items-center gap-2">
                    <h1 class="text-base sm:text-lg font-bold text-white tracking-wide">NON-GST PRODUCT SUMMARY</h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-stone-950 shadow-sm">
                        PROTECTED MODE
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="/geomorphology?geo_action=exit_to_main&target=/product_summary.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 text-white border border-white/20 transition hidden sm:inline-flex">
                    <i class="fas fa-boxes mr-1"></i> Main GST Product Summary
                </a>
            </div>
        </nav>

        <!-- Main Body -->
        <main class="p-4 sm:p-6 lg:p-8 flex-1">

            <!-- Welcome Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-[#7a4e0a]">Non-GST Product Activity Summary</h2>
                    <p class="text-xs sm:text-sm text-stone-600 mt-1">Audit trail and record of all products billed or transacted under Cash Memos.</p>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <a href="index.php?geo_action=exit_to_main&target=../billing.php" class="px-4 py-2 bg-gradient-to-r from-[#d68b16] to-[#b5730e] hover:from-[#b5730e] hover:to-[#7a4e0a] text-white font-bold rounded-xl text-xs shadow-md transition inline-flex items-center gap-1.5">
                        <i class="fas fa-cash-register"></i> New Cash Memo
                    </a>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="kpi-card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-xl bg-amber-100 text-amber-800">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Non-GST Items Billed</div>
                        <div class="text-xl font-extrabold text-[#7a4e0a] mt-0.5"><?php echo number_format($total_cnt_billed); ?> <span class="text-xs font-medium text-gray-500">items</span></div>
                        <div class="text-[10px] text-gray-500 mt-0.5"><?php echo number_format($total_memos_cnt); ?> unique Cash Memos</div>
                    </div>
                </div>

                <div class="kpi-card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-xl bg-emerald-100 text-emerald-800">
                        <i class="fas fa-weight-hanging"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total Weight Billed</div>
                        <div class="text-xl font-extrabold text-emerald-800 mt-0.5"><?php echo number_format($total_wt_billed, 3); ?> <span class="text-xs font-medium text-gray-500">g</span></div>
                        <div class="text-[10px] text-gray-500 mt-0.5">Non-GST Gold/Jewellery Sold</div>
                    </div>
                </div>

                <div class="kpi-card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-xl bg-blue-100 text-blue-800">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total Non-GST Sales</div>
                        <div class="text-xl font-extrabold text-blue-900 mt-0.5">₹<?php echo number_format($total_val_billed, 2); ?></div>
                        <div class="text-[10px] text-gray-500 mt-0.5">Total Value Billed</div>
                    </div>
                </div>

                <div class="kpi-card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-xl bg-purple-100 text-purple-800">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Product Categories</div>
                        <div class="text-xl font-extrabold text-purple-900 mt-0.5"><?php echo count($categories); ?> <span class="text-xs font-medium text-gray-500">categories</span></div>
                        <div class="text-[10px] text-gray-500 mt-0.5">Active in Non-GST Memos</div>
                    </div>
                </div>
            </div>

            <!-- Filter Controls -->
            <div class="kpi-card p-5 mb-6">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-[#7a4e0a] mb-1">Timeframe</label>
                        <select name="timeframe" id="timeframeSelect" class="w-full bg-stone-50 border border-amber-300 rounded-lg px-3 py-2 text-xs font-medium text-gray-800 focus:outline-none focus:ring-1 focus:ring-amber-500" onchange="toggleCustomDates(this.value)">
                            <option value="all" <?php if($filter_time === 'all') echo 'selected'; ?>>All Time</option>
                            <option value="today" <?php if($filter_time === 'today') echo 'selected'; ?>>Today</option>
                            <option value="yesterday" <?php if($filter_time === 'yesterday') echo 'selected'; ?>>Yesterday</option>
                            <option value="7days" <?php if($filter_time === '7days') echo 'selected'; ?>>Last 7 Days</option>
                            <option value="this_month" <?php if($filter_time === 'this_month') echo 'selected'; ?>>This Month</option>
                            <option value="custom" <?php if($filter_time === 'custom') echo 'selected'; ?>>Custom Range</option>
                        </select>
                    </div>

                    <div id="customDatesDiv" class="<?php echo ($filter_time === 'custom') ? '' : 'hidden'; ?> grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-[11px] font-bold text-[#7a4e0a] mb-1">From</label>
                            <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="w-full bg-stone-50 border border-amber-300 rounded-lg px-2 py-1.5 text-xs text-gray-800">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-[#7a4e0a] mb-1">To</label>
                            <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="w-full bg-stone-50 border border-amber-300 rounded-lg px-2 py-1.5 text-xs text-gray-800">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-[#7a4e0a] mb-1">Category</label>
                        <select name="category" class="w-full bg-stone-50 border border-amber-300 rounded-lg px-3 py-2 text-xs font-medium text-gray-800 focus:outline-none focus:ring-1 focus:ring-amber-500">
                            <option value="all">All Categories</option>
                            <?php foreach($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php if($filter_cat === $cat) echo 'selected'; ?>><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-[#7a4e0a] mb-1">Search Keywords</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Product, Memo #, Customer..." class="w-full bg-stone-50 border border-amber-300 rounded-lg px-3 py-2 text-xs text-gray-800 focus:outline-none focus:ring-1 focus:ring-amber-500">
                    </div>

                    <div class="flex items-end gap-2">
                        <button type="submit" class="flex-1 py-2 px-4 bg-[#7a4e0a] hover:bg-[#5c3a07] text-white font-bold rounded-lg text-xs transition shadow-sm">
                            <i class="fas fa-filter mr-1"></i> Filter
                        </button>
                        <a href="product_summary.php" class="py-2 px-3 bg-stone-200 hover:bg-stone-300 text-stone-700 font-bold rounded-lg text-xs transition text-center">
                            Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Activity Logs Table -->
            <div class="kpi-card overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-amber-200/60 bg-amber-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-history text-[#7a4e0a]"></i>
                        <h3 class="text-sm font-bold text-gray-800">Non-GST Product Activity Trail (Last 500 Records)</h3>
                    </div>
                    <span class="text-xs font-bold text-amber-900 bg-amber-200/70 px-3 py-1 rounded-full">
                        Showing <?php echo mysqli_num_rows($logs_res); ?> Entries
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-[#011921] text-[#ffd700] uppercase text-[10px] tracking-wider">
                                <th class="py-3 px-4">#</th>
                                <th class="py-3 px-4">Date &amp; Time</th>
                                <th class="py-3 px-4">Action</th>
                                <th class="py-3 px-4">Product Name</th>
                                <th class="py-3 px-4">Category</th>
                                <th class="py-3 px-4 text-right">Qty / Wt</th>
                                <th class="py-3 px-4 text-right">Rate / Total</th>
                                <th class="py-3 px-4">Cash Memo #</th>
                                <th class="py-3 px-4">Customer</th>
                                <th class="py-3 px-4 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-amber-100">
                            <?php 
                            if(mysqli_num_rows($logs_res) == 0): 
                            ?>
                            <tr>
                                <td colspan="10" class="py-8 text-center text-stone-500 italic">
                                    No non-GST product activities found matching your filters.
                                </td>
                            </tr>
                            <?php 
                            else:
                                $idx = 1;
                                while($row = mysqli_fetch_assoc($logs_res)):
                                    $act_type = $row['activity_type'];
                                    $badge_class = 'bg-amber-100 text-amber-800 border-amber-300';
                                    if ($act_type === 'billed') $badge_class = 'bg-emerald-100 text-emerald-800 border-emerald-300';
                                    elseif ($act_type === 'deleted') $badge_class = 'bg-rose-100 text-rose-800 border-rose-300';
                            ?>
                            <tr class="hover:bg-amber-50/40 transition">
                                <td class="py-3 px-4 text-stone-400 font-mono"><?php echo $idx++; ?></td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <div class="font-semibold text-gray-800"><?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?></div>
                                    <div class="text-[10px] text-gray-400"><?php echo time_elapsed_string($row['created_at']); ?></div>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold border uppercase <?php echo $badge_class; ?>">
                                        <?php echo htmlspecialchars($act_type); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="font-bold text-gray-900"><?php echo htmlspecialchars($row['product_name']); ?></div>
                                    <?php if(!empty($row['serial_no']) || !empty($row['huid_code'])): ?>
                                    <div class="text-[10px] text-stone-500 font-mono">
                                        <?php if($row['serial_no']) echo "SN: " . htmlspecialchars($row['serial_no']) . " "; ?>
                                        <?php if($row['huid_code']) echo "HUID: " . htmlspecialchars($row['huid_code']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="text-stone-600 font-medium"><?php echo htmlspecialchars($row['category'] ?: '-'); ?></span>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap font-mono">
                                    <?php if(floatval($row['weight']) > 0): ?>
                                    <div class="font-bold text-[#7a4e0a]"><?php echo number_format($row['weight'], 3); ?> g</div>
                                    <?php endif; ?>
                                    <div class="text-[11px] text-gray-500"><?php echo floatval($row['quantity']); ?> <?php echo htmlspecialchars($row['unit']); ?></div>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap font-mono">
                                    <?php if(floatval($row['total_amount']) > 0): ?>
                                    <div class="font-bold text-emerald-800">₹<?php echo number_format($row['total_amount'], 2); ?></div>
                                    <?php endif; ?>
                                    <?php if(floatval($row['rate']) > 0): ?>
                                    <div class="text-[10px] text-gray-400">@ ₹<?php echo number_format($row['rate'], 2); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <?php if(!empty($row['invoice_no'])): ?>
                                    <a href="../view_pdf.php?invoice_no=<?php echo urlencode($row['invoice_no']); ?>" target="_blank" class="text-amber-800 font-bold hover:underline inline-flex items-center gap-1 font-mono">
                                        <i class="fas fa-file-invoice text-xs"></i> <?php echo htmlspecialchars($row['invoice_no']); ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($row['customer_name'] ?: '-'); ?></div>
                                    <?php if(!empty($row['customer_mobile'])): ?>
                                    <div class="text-[10px] text-gray-500 font-mono"><?php echo htmlspecialchars($row['customer_mobile']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <a href="product_summary.php?action=delete_log&id=<?php echo $row['id']; ?>" onclick="return confirm('Delete this activity record?');" class="text-rose-500 hover:text-rose-700 p-1" title="Delete Log Record">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            endif; 
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script>
        function toggleCustomDates(val) {
            const div = document.getElementById('customDatesDiv');
            if (val === 'custom') {
                div.classList.remove('hidden');
            } else {
                div.classList.add('hidden');
            }
        }
    </script>
</body>
</html>
