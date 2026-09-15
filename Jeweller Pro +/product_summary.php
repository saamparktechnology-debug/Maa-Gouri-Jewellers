<?php
session_start();
require_once 'config/database.php';
require_once 'includes/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Handle Delete Activity Log Request (POST, GET, AJAX)
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

if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'clear_all_logs') {
    $is_ajax = isset($_REQUEST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    $del = mysqli_query($conn, "TRUNCATE TABLE product_activity_logs");
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => (bool)$del, 'message' => $del ? 'All logs cleared' : mysqli_error($conn)]);
        exit;
    }
    if ($del) {
        $_SESSION['flash_success'] = "All activity records have been cleared.";
    }
    header("Location: product_summary.php");
    exit;
}

$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
$logo_file = 'assets/images/moti-removebg-preview.png';
foreach($logo_paths as $lp) { if(file_exists($lp)) { $logo_file = $lp; break; } }

// --- FILTER & QUERY LOGIC ---
$filter_type = isset($_GET['type']) ? mysqli_real_escape_string($conn, trim($_GET['type'])) : 'all';
$filter_cat  = isset($_GET['category']) ? mysqli_real_escape_string($conn, trim($_GET['category'])) : 'all';
$filter_time = isset($_GET['timeframe']) ? mysqli_real_escape_string($conn, trim($_GET['timeframe'])) : 'all';
$from_date   = isset($_GET['from_date']) ? mysqli_real_escape_string($conn, trim($_GET['from_date'])) : '';
$to_date     = isset($_GET['to_date']) ? mysqli_real_escape_string($conn, trim($_GET['to_date'])) : '';
$search      = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';

// Ensure table and is_nongst column exists
init_activity_log_table($conn);
@mysqli_query($conn, "UPDATE product_activity_logs SET is_nongst = 1 WHERE invoice_no LIKE 'CM-%' OR invoice_no IN (SELECT invoice_no FROM invoices WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)))");

$where_clauses = ["(is_nongst = 0 OR is_nongst IS NULL)"];

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

// --- KPI METRICS (GST & General Stock Only) ---
$total_added_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt, SUM(weight) as total_wt, SUM(quantity) as total_qty FROM product_activity_logs WHERE activity_type='added' AND (is_nongst = 0 OR is_nongst IS NULL)"));
$total_billed_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt, SUM(weight) as total_wt, SUM(total_amount) as total_val FROM product_activity_logs WHERE activity_type='billed' AND (is_nongst = 0 OR is_nongst IS NULL)"));
$total_edited_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM product_activity_logs WHERE activity_type='edited' AND (is_nongst = 0 OR is_nongst IS NULL)"));
$total_deleted_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM product_activity_logs WHERE activity_type='deleted' AND (is_nongst = 0 OR is_nongst IS NULL)"));

$current_stock_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total_items, SUM(CAST(weight AS DECIMAL(10,3))) as total_wt, SUM(quantity) as total_pcs FROM products"));

// Distinct categories for filter
$cats_res = mysqli_query($conn, "SELECT DISTINCT category FROM product_activity_logs WHERE (is_nongst = 0 OR is_nongst IS NULL) AND category IS NOT NULL AND category != '' ORDER BY category ASC");
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

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'min',
        's' => 'sec',
    );
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
    <title>Product Summary &amp; Stock Activity History - MAA GOURI JEWELLERS</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
        
        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Playfair Display', serif; }

        /* Modern Sidebar */
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #2b1b17 0%, #1a0f0d 100%);
            border-right: 1.5px solid #d68b16;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 100;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-brand {
            padding: 20px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(214, 139, 22, 0.25);
            background: rgba(0, 0, 0, 0.2);
        }
        .sidebar-brand img {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: 2px solid #d68b16;
            background: #fff;
            object-fit: contain;
            flex-shrink: 0;
        }
        .sidebar-brand-text h2 {
            font-size: 15px;
            font-weight: 700;
            color: #d68b16;
            margin: 0;
            line-height: 1.2;
            letter-spacing: 0.5px;
        }
        .sidebar-brand-text p {
            font-size: 10px;
            color: #d68b16;
            margin: 2px 0 0 0;
            letter-spacing: 0.5px;
        }
        .sidebar-nav {
            flex: 1;
            padding: 12px 8px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #d68b16 transparent;
        }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: #d68b16; border-radius: 4px; }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            color: #e5d5b8;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 3px;
            transition: all 0.2s ease;
            position: relative;
        }
        .sidebar-nav a i {
            width: 20px;
            text-align: center;
            font-size: 15px;
            color: #d68b16;
            transition: all 0.2s;
        }
        .sidebar-nav a:hover {
            background: rgba(214, 139, 22, 0.15);
            color: #fff;
            transform: translateX(4px);
        }
        .sidebar-nav a.active {
            background: linear-gradient(135deg, #d68b16 0%, #800020 100%);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(214, 139, 22, 0.3);
        }
        .sidebar-nav a.active i { color: #fff; }
        .sidebar-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(214, 139, 22, 0.3), transparent);
            margin: 10px 8px;
        }
        .sidebar-section-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: rgba(214, 139, 22, 0.6);
            letter-spacing: 1px;
            padding: 6px 14px 2px;
        }
        .sidebar-user {
            padding: 14px 16px;
            border-top: 1px solid rgba(214, 139, 22, 0.2);
            background: rgba(0, 0, 0, 0.25);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; }
        .sidebar-user-info i { font-size: 24px; color: #d68b16; }
        .sidebar-user-info .user-details p { margin: 0; font-size: 12px; font-weight: 600; color: #fff; line-height: 1.2; }
        .sidebar-user-info .user-details span { font-size: 10px; color: #d68b16; }
        .sidebar-logout { color: #f87171; text-decoration: none; font-size: 14px; padding: 6px 8px; border-radius: 6px; transition: all 0.2s; }
        .sidebar-logout:hover { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            background: #fdfaf5;
            transition: margin 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Pill Badge Styles */
        .act-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .act-badge-added { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .act-badge-billed { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .act-badge-edited { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .act-badge-deleted { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .act-badge-restocked { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }
        .act-badge-transferred { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

        .card-stat {
            background: #fff;
            border: 1px solid rgba(214,139,22,0.22);
            border-radius: 14px;
            padding: 16px 20px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.03);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card-stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(214,139,22,0.12);
        }

        /* Activity Table Header Styling */
        #activityTable thead tr {
            background-color: #fbf5eb !important;
            border-bottom: 2px solid #ecd8b1 !important;
        }
        #activityTable thead th {
            color: #451a03 !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }

        /* Print formatting */
        @media print {
            .sidebar, .filter-box, .no-print, .action-btn-group { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; background: #fff !important; }
            .card-stat { border: 1px solid #cbd5e1 !important; box-shadow: none !important; }
            .inv-table { width: 100% !important; border-collapse: collapse !important; }
            .inv-table th, .inv-table td { border: 1px solid #cbd5e1 !important; padding: 6px !important; }
        }
    </style>
</head>
<body class="bg-amber-50/20 text-stone-800 antialiased">

    <!-- ==================== SIDEBAR ==================== -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="<?php echo $logo_file; ?>" alt="Logo">
            <div class="sidebar-brand-text">
                <h2>MAA GOURI</h2>
                <p>JEWELLERS PRO+</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Main</div>
            <a href="index.php"><i class="fas fa-home"></i> DASHBOARD</a>
            <a href="billing.php"><i class="fas fa-file-invoice-dollar"></i> BILLING</a>
            <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
            <a href="customers.php"><i class="fas fa-users"></i> CUSTOMERS</a>
            <a href="transfer.php"><i class="fas fa-exchange-alt"></i> TRANSFER</a>

            <div class="sidebar-divider"></div>
            <div class="sidebar-section-label">Analytics</div>
            <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
            <a href="due_list.php"><i class="fas fa-hourglass-half"></i> DUE LIST</a>
            <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>

            <div class="sidebar-divider"></div>
            <div class="sidebar-section-label">Tools</div>
            <a href="product_summary.php" class="active">
                <i class="fas fa-boxes-packing"></i> PRODUCT SUMMARY
            </a>
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
                    <span><?php echo htmlspecialchars($_SESSION['user_mobile'] ?? 'Store Manager'); ?></span>
                </div>
            </div>
            <a href="logout.php" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <!-- ==================== MAIN CONTENT ==================== -->
    <main class="main-content p-4 md:p-8">
        
        <!-- Header Bar -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-6 border-b border-amber-200">
            <div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-600 to-amber-800 text-white flex items-center justify-center text-lg shadow-md">
                        <i class="fas fa-boxes-packing"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold text-amber-950">Product Summary &amp; History</h1>
                        <p class="text-xs md:text-sm text-amber-800">Complete live audit trail of added, billed, modified, and removed inventory items.</p>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2.5 action-btn-group no-print flex-wrap">
                <a href="stock.php" class="px-4 py-2 bg-gradient-to-r from-amber-700 to-yellow-600 text-white rounded-xl text-xs font-bold hover:shadow-lg transition flex items-center gap-2">
                    <i class="fas fa-plus-circle"></i> Add New Product
                </a>
                <a href="billing.php" class="px-4 py-2 bg-emerald-700 text-white rounded-xl text-xs font-bold hover:bg-emerald-800 transition flex items-center gap-2">
                    <i class="fas fa-cash-register"></i> New Bill / Sale
                </a>
                <button onclick="window.print()" class="px-3.5 py-2 bg-white border border-stone-300 text-stone-700 rounded-xl text-xs font-bold hover:bg-stone-50 transition flex items-center gap-1.5 shadow-sm">
                    <i class="fas fa-print"></i> Print Summary
                </button>
                <button onclick="exportTableToCSV('product_activity_log.csv')" class="px-3.5 py-2 bg-amber-100 border border-amber-300 text-amber-900 rounded-xl text-xs font-bold hover:bg-amber-200 transition flex items-center gap-1.5 shadow-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
            </div>
        </div>

        <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="mt-4 p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs font-bold flex items-center justify-between shadow-sm">
            <div class="flex items-center gap-2">
                <i class="fas fa-check-circle text-emerald-600 text-sm"></i>
                <span><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">&times;</button>
        </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="mt-4 p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-bold flex items-center justify-between shadow-sm">
            <div class="flex items-center gap-2">
                <i class="fas fa-exclamation-circle text-rose-600 text-sm"></i>
                <span><?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700">&times;</button>
        </div>
        <?php endif; ?>

        <!-- KPI Metrics Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mt-6">
            <!-- 1. Total Added -->
            <div class="card-stat border-l-4 border-emerald-500">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-emerald-800 uppercase tracking-wide">Products Added</span>
                    <span class="w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs"><i class="fas fa-plus"></i></span>
                </div>
                <div class="text-2xl font-black text-stone-900 mt-2"><?php echo number_format($total_added_res['cnt'] ?? 0); ?></div>
                <div class="text-[11px] text-stone-500 mt-1 font-semibold">
                    Gross Wt: <strong class="text-emerald-700"><?php echo number_format($total_added_res['total_wt'] ?? 0, 3); ?>g</strong>
                </div>
            </div>

            <!-- 2. Total Billed / Sold -->
            <div class="card-stat border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-blue-800 uppercase tracking-wide">Billed / Sold</span>
                    <span class="w-7 h-7 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs"><i class="fas fa-shopping-cart"></i></span>
                </div>
                <div class="text-2xl font-black text-stone-900 mt-2"><?php echo number_format($total_billed_res['cnt'] ?? 0); ?></div>
                <div class="text-[11px] text-stone-500 mt-1 font-semibold">
                    Sold Wt: <strong class="text-blue-700"><?php echo number_format($total_billed_res['total_wt'] ?? 0, 3); ?>g</strong>
                </div>
            </div>

            <!-- 3. Stock Edits & Updates -->
            <div class="card-stat border-l-4 border-amber-500">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-amber-800 uppercase tracking-wide">Stock Edits</span>
                    <span class="w-7 h-7 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-xs"><i class="fas fa-pencil-alt"></i></span>
                </div>
                <div class="text-2xl font-black text-stone-900 mt-2"><?php echo number_format($total_edited_res['cnt'] ?? 0); ?></div>
                <div class="text-[11px] text-stone-500 mt-1 font-semibold">Price/Weight Adjustments</div>
            </div>

            <!-- 4. Deleted / Removed -->
            <div class="card-stat border-l-4 border-rose-500">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-rose-800 uppercase tracking-wide">Removed Items</span>
                    <span class="w-7 h-7 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center text-xs"><i class="fas fa-trash-alt"></i></span>
                </div>
                <div class="text-2xl font-black text-stone-900 mt-2"><?php echo number_format($total_deleted_res['cnt'] ?? 0); ?></div>
                <div class="text-[11px] text-stone-500 mt-1 font-semibold">Scrapped or Deleted</div>
            </div>

            <!-- 5. Active In-Hand Inventory -->
            <div class="card-stat border-l-4 border-purple-500 bg-gradient-to-br from-white to-purple-50/40">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-purple-900 uppercase tracking-wide">Current In-Stock</span>
                    <span class="w-7 h-7 rounded-full bg-purple-100 text-purple-800 flex items-center justify-center text-xs"><i class="fas fa-gem"></i></span>
                </div>
                <div class="text-2xl font-black text-purple-950 mt-2"><?php echo number_format($current_stock_res['total_items'] ?? 0); ?> <span class="text-xs font-normal text-stone-500">items</span></div>
                <div class="text-[11px] text-stone-500 mt-1 font-semibold">
                    Net Weight: <strong class="text-purple-800"><?php echo number_format($current_stock_res['total_wt'] ?? 0, 3); ?>g</strong>
                </div>
            </div>
        </div>

        <!-- Filter & Search Controls -->
        <div class="filter-box bg-white p-4 md:p-5 rounded-2xl border border-amber-200 mt-6 shadow-sm no-print">
            <form method="GET" action="product_summary.php" id="filterForm" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
                
                <!-- Live Search -->
                <div class="lg:col-span-2">
                    <label class="block text-xs font-bold text-stone-700 mb-1">Live Search Product / HUID / Invoice</label>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-3 text-stone-400 text-xs"></i>
                        <input type="text" id="liveSearchInput" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Instant search by Name, HUID, Invoice #, Customer..." class="w-full pl-8 pr-8 py-2 text-xs rounded-xl border border-stone-300 focus:outline-none focus:border-amber-500 transition">
                        <button type="button" id="clearSearchBtn" onclick="clearLiveSearch()" class="absolute right-2.5 top-2.5 text-stone-400 hover:text-stone-600 text-xs hidden">
                            <i class="fas fa-times-circle"></i>
                        </button>
                    </div>
                </div>

                <!-- Activity Type Filter -->
                <div>
                    <label class="block text-xs font-bold text-stone-700 mb-1">Activity Type</label>
                    <select id="activitySelect" name="type" class="w-full px-3 py-2 text-xs rounded-xl border border-stone-300 focus:outline-none focus:border-amber-500" onchange="applyLiveFilter()">
                        <option value="all" <?php echo $filter_type==='all'?'selected':''; ?>>All Activities</option>
                        <option value="added" <?php echo $filter_type==='added'?'selected':''; ?>>➕ Added to Stock</option>
                        <option value="billed" <?php echo $filter_type==='billed'?'selected':''; ?>>🛒 Billed / Sold</option>
                        <option value="edited" <?php echo $filter_type==='edited'?'selected':''; ?>>✏️ Edited / Updated</option>
                        <option value="deleted" <?php echo $filter_type==='deleted'?'selected':''; ?>>🗑️ Removed / Deleted</option>
                        <option value="restocked" <?php echo $filter_type==='restocked'?'selected':''; ?>>🔄 Restocked</option>
                    </select>
                </div>

                <!-- Category Filter -->
                <div>
                    <label class="block text-xs font-bold text-stone-700 mb-1">Category</label>
                    <select id="categorySelect" name="category" class="w-full px-3 py-2 text-xs rounded-xl border border-stone-300 focus:outline-none focus:border-amber-500" onchange="applyLiveFilter()">
                        <option value="all" <?php echo $filter_cat==='all'?'selected':''; ?>>All Categories</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_cat===$cat?'selected':''; ?>><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Timeframe Filter -->
                <div>
                    <label class="block text-xs font-bold text-stone-700 mb-1">Timeframe</label>
                    <select name="timeframe" id="timeframeSelect" class="w-full px-3 py-2 text-xs rounded-xl border border-stone-300 focus:outline-none focus:border-amber-500" onchange="toggleCustomDate(this.value); applyLiveFilter();">
                        <option value="all" <?php echo $filter_time==='all'?'selected':''; ?>>All Time</option>
                        <option value="today" <?php echo $filter_time==='today'?'selected':''; ?>>Today</option>
                        <option value="yesterday" <?php echo $filter_time==='yesterday'?'selected':''; ?>>Yesterday</option>
                        <option value="7days" <?php echo $filter_time==='7days'?'selected':''; ?>>Last 7 Days</option>
                        <option value="this_month" <?php echo $filter_time==='this_month'?'selected':''; ?>>This Month</option>
                        <option value="custom" <?php echo $filter_time==='custom'?'selected':''; ?>>Custom Date Range</option>
                    </select>
                </div>

                <!-- Custom Dates (Hidden by default unless custom) -->
                <div id="customDateBox" class="<?php echo $filter_time==='custom'?'':'hidden'; ?> sm:col-span-2 flex items-center gap-2">
                    <div class="flex-1">
                        <label class="block text-[10px] font-bold text-stone-500 mb-0.5">From Date</label>
                        <input type="date" id="fromDateInput" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" onchange="applyLiveFilter()" class="w-full px-2 py-1.5 text-xs rounded-lg border border-stone-300">
                    </div>
                    <div class="flex-1">
                        <label class="block text-[10px] font-bold text-stone-500 mb-0.5">To Date</label>
                        <input type="date" id="toDateInput" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" onchange="applyLiveFilter()" class="w-full px-2 py-1.5 text-xs rounded-lg border border-stone-300">
                    </div>
                </div>

                <!-- Submit / Reset Buttons -->
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 py-2 bg-amber-800 text-white rounded-xl text-xs font-bold hover:bg-amber-900 transition flex items-center justify-center gap-1.5 shadow-sm">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <button type="button" onclick="resetAllFilters()" class="px-3 py-2 bg-stone-100 text-stone-600 rounded-xl text-xs font-bold hover:bg-stone-200 transition" title="Reset All Filters">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- Quick Filter Pill Tabs -->
        <div class="flex items-center gap-2 mt-5 overflow-x-auto pb-1 no-print">
            <button type="button" onclick="setQuickTab('all')" id="tab-all" class="quick-tab px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-amber-900 text-white shadow-sm flex items-center gap-1.5 whitespace-nowrap">
                <span>All Activities</span>
                <span class="px-1.5 py-0.2 rounded-full bg-white/20 text-[10px]" id="cnt-all">0</span>
            </button>
            <button type="button" onclick="setQuickTab('added')" id="tab-added" class="quick-tab px-3.5 py-1.5 rounded-xl text-xs font-semibold transition bg-white text-stone-700 border border-stone-200 hover:bg-amber-50 flex items-center gap-1.5 whitespace-nowrap">
                <span>➕ Added</span>
                <span class="px-1.5 py-0.2 rounded-full bg-emerald-100 text-emerald-800 text-[10px]" id="cnt-added">0</span>
            </button>
            <button type="button" onclick="setQuickTab('billed')" id="tab-billed" class="quick-tab px-3.5 py-1.5 rounded-xl text-xs font-semibold transition bg-white text-stone-700 border border-stone-200 hover:bg-amber-50 flex items-center gap-1.5 whitespace-nowrap">
                <span>🛒 Billed / Sold</span>
                <span class="px-1.5 py-0.2 rounded-full bg-blue-100 text-blue-800 text-[10px]" id="cnt-billed">0</span>
            </button>
            <button type="button" onclick="setQuickTab('edited')" id="tab-edited" class="quick-tab px-3.5 py-1.5 rounded-xl text-xs font-semibold transition bg-white text-stone-700 border border-stone-200 hover:bg-amber-50 flex items-center gap-1.5 whitespace-nowrap">
                <span>✏️ Edited</span>
                <span class="px-1.5 py-0.2 rounded-full bg-amber-100 text-amber-800 text-[10px]" id="cnt-edited">0</span>
            </button>
            <button type="button" onclick="setQuickTab('deleted')" id="tab-deleted" class="quick-tab px-3.5 py-1.5 rounded-xl text-xs font-semibold transition bg-white text-stone-700 border border-stone-200 hover:bg-amber-50 flex items-center gap-1.5 whitespace-nowrap">
                <span>🗑️ Removed</span>
                <span class="px-1.5 py-0.2 rounded-full bg-rose-100 text-rose-800 text-[10px]" id="cnt-deleted">0</span>
            </button>
            <button type="button" onclick="setQuickTab('restocked')" id="tab-restocked" class="quick-tab px-3.5 py-1.5 rounded-xl text-xs font-semibold transition bg-white text-stone-700 border border-stone-200 hover:bg-amber-50 flex items-center gap-1.5 whitespace-nowrap">
                <span>🔄 Restocked</span>
                <span class="px-1.5 py-0.2 rounded-full bg-purple-100 text-purple-800 text-[10px]" id="cnt-restocked">0</span>
            </button>
        </div>

        <!-- Activity Log Table -->
        <div class="bg-white rounded-2xl border border-amber-200 mt-4 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-stone-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <h3 class="font-bold text-stone-900 text-sm md:text-base">Detailed Activity History</h3>
                    <span id="recordCountBadge" class="px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-900 text-xs font-bold"><?php echo mysqli_num_rows($logs_res); ?> records</span>
                </div>
                <div class="flex items-center gap-2.5 no-print">
                    <button type="button" onclick="confirmClearAllLogs()" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-600 text-rose-700 hover:text-white border border-rose-200 hover:border-rose-600 rounded-lg text-xs font-bold transition flex items-center gap-1 shadow-sm" title="Clear all activity history">
                        <i class="fas fa-trash-alt text-[10px]"></i> Clear All Logs
                    </button>
                    <div class="text-xs text-stone-500 hidden md:block">
                        Live interactive filtering enabled
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse inv-table" id="activityTable">
                    <thead>
                        <tr class="text-xs uppercase tracking-wider font-bold" style="background-color: #fbf5eb; border-bottom: 2px solid #ecd8b1; color: #451a03;">
                            <th class="py-3 px-3.5 text-center" style="color: #451a03;">#</th>
                            <th class="py-3 px-3.5" style="color: #451a03;">Activity</th>
                            <th class="py-3 px-3.5" style="color: #451a03;">Date &amp; Time</th>
                            <th class="py-3 px-3.5" style="color: #451a03;">Product Name &amp; HUID</th>
                            <th class="py-3 px-3.5" style="color: #451a03;">Category</th>
                            <th class="py-3 px-3.5 text-right" style="color: #451a03;">Quantity / Weight</th>
                            <th class="py-3 px-3.5" style="color: #451a03;">Activity Remarks / Details</th>
                            <th class="py-3 px-3.5 text-center" style="color: #451a03;">Logged By</th>
                            <th class="py-3 px-3.5 text-center no-print" style="color: #451a03;">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 text-xs text-stone-700" id="activityTableBody">
                        <?php if (mysqli_num_rows($logs_res) == 0): ?>
                        <tr id="emptyDbRow">
                            <td colspan="9" class="py-12 text-center text-stone-400">
                                <i class="fas fa-boxes text-3xl mb-2 text-stone-300"></i>
                                <p class="font-medium text-sm">No product activity logs found.</p>
                            </td>
                        </tr>
                        <?php else: 
                            $idx = 1;
                            while($log = mysqli_fetch_assoc($logs_res)): 
                                $atype = $log['activity_type'];
                                $badge_class = 'act-badge-added';
                                $badge_icon = 'fa-plus';
                                $badge_label = 'Added';

                                if ($atype === 'billed') {
                                    $badge_class = 'act-badge-billed';
                                    $badge_icon = 'fa-shopping-bag';
                                    $badge_label = 'Billed';
                                } elseif ($atype === 'edited') {
                                    $badge_class = 'act-badge-edited';
                                    $badge_icon = 'fa-pencil-alt';
                                    $badge_label = 'Edited';
                                } elseif ($atype === 'deleted') {
                                    $badge_class = 'act-badge-deleted';
                                    $badge_icon = 'fa-trash-alt';
                                    $badge_label = 'Removed';
                                } elseif ($atype === 'restocked') {
                                    $badge_class = 'act-badge-restocked';
                                    $badge_icon = 'fa-box';
                                    $badge_label = 'Restocked';
                                }

                                $date_fmt = date('d-M-Y h:i A', strtotime($log['created_at']));
                                $rel_time = time_elapsed_string($log['created_at']);
                                $date_iso = date('Y-m-d', strtotime($log['created_at']));
                                
                                $search_blob = strtolower(trim(
                                    $log['product_name'] . ' ' . 
                                    $log['category'] . ' ' . 
                                    $log['serial_no'] . ' ' . 
                                    $log['huid_code'] . ' ' . 
                                    $log['customer_name'] . ' ' . 
                                    $log['performed_by'] . ' ' . 
                                    $log['details']
                                ));
                        ?>
                        <tr class="activity-row hover:bg-amber-50/40 transition" 
                            data-type="<?php echo $atype; ?>"
                            data-category="<?php echo htmlspecialchars($log['category'] ?: ''); ?>"
                            data-date="<?php echo $date_iso; ?>"
                            data-search="<?php echo htmlspecialchars($search_blob); ?>"
                            data-log-id="<?php echo intval($log['id']); ?>">
                            
                            <td class="row-index py-3 px-3.5 text-center text-stone-400 font-mono text-[11px]"><?php echo $idx++; ?></td>
                            
                            <!-- Activity Badge -->
                            <td class="py-3 px-3.5">
                                <span class="act-badge <?php echo $badge_class; ?>">
                                    <i class="fas <?php echo $badge_icon; ?> text-[10px]"></i> <?php echo $badge_label; ?>
                                </span>
                            </td>

                            <!-- Date & Time -->
                            <td class="py-3 px-3.5 whitespace-nowrap">
                                <div class="font-bold text-stone-900"><?php echo $date_fmt; ?></div>
                                <div class="text-[10px] text-stone-400 font-medium"><?php echo $rel_time; ?></div>
                            </td>

                            <!-- Product Name & HUID -->
                            <td class="py-3 px-3.5">
                                <div class="font-bold text-amber-950 flex items-center gap-1.5">
                                    <i class="fas fa-gem text-amber-700 text-[10px]"></i> <?php echo htmlspecialchars($log['product_name']); ?>
                                </div>
                                <?php if(!empty($log['huid_code']) || !empty($log['serial_no'])): ?>
                                <div class="text-[10px] text-stone-500 font-mono mt-0.5">
                                    <?php if(!empty($log['huid_code'])): ?>
                                        <span class="bg-amber-100 text-amber-900 px-1 py-0.2 rounded">HUID: <?php echo htmlspecialchars(preg_replace('/^HUID[:\s\-_]*/i', '', $log['huid_code'])); ?></span>
                                    <?php endif; ?>
                                    <?php if(!empty($log['serial_no'])): ?>
                                        <span>SN: <?php echo htmlspecialchars($log['serial_no']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- Category -->
                            <td class="py-3 px-3.5 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded-md bg-stone-100 text-stone-700 font-semibold text-[11px]">
                                    <?php echo htmlspecialchars($log['category'] ?: 'General'); ?>
                                </span>
                            </td>

                            <!-- Quantity / Weight -->
                            <td class="py-3 px-3.5 text-right whitespace-nowrap">
                                <?php if($log['weight'] > 0): ?>
                                    <div class="font-bold text-stone-900"><?php echo number_format($log['weight'], 3); ?> g</div>
                                    <?php if($log['quantity'] > 0 && $log['unit'] !== 'g'): ?>
                                        <div class="text-[10px] text-stone-500"><?php echo $log['quantity'] . ' ' . $log['unit']; ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="font-bold text-stone-900"><?php echo $log['quantity'] . ' ' . ($log['unit'] ?: 'pcs'); ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- Activity Remarks / Details (No Invoice / CashMemo No, No Prices) -->
                            <td class="py-3 px-3.5">
                                <?php 
                                    $raw_details = trim($log['details'] ?? '');
                                    // Strip invoice/cash memo numbers and price mentions
                                    $clean_details = preg_replace('/in Invoice\/CashMemo\s*#[A-Za-z0-9\-_]+/i', '', $raw_details);
                                    $clean_details = preg_replace('/,\s*Price:\s*₹[\d,.]+/i', '', $clean_details);
                                    $clean_details = preg_replace('/\(Price:\s*₹[\d,.]+\)/i', '', $clean_details);
                                    $clean_details = preg_replace('/Price:\s*₹[\d,.]+/i', '', $clean_details);
                                    $clean_details = trim($clean_details);

                                    if ($atype === 'billed') {
                                        if(!empty($log['customer_name'])) {
                                            $clean_details = 'Billed / Sold to customer <strong class="text-stone-800">' . htmlspecialchars($log['customer_name']) . '</strong>';
                                        } else {
                                            $clean_details = 'Billed / Sold to Customer';
                                        }
                                    } elseif (empty($clean_details)) {
                                        $clean_details = ucfirst($atype) . ' in inventory';
                                    }
                                ?>
                                <span class="text-stone-600 text-xs"><?php echo $clean_details; ?></span>
                            </td>

                            <!-- Logged By -->
                            <td class="py-3 px-3.5 text-center whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded bg-stone-100 text-stone-600 text-[10px] font-semibold">
                                    <i class="fas fa-user-shield text-[9px] mr-1 text-amber-700"></i><?php echo htmlspecialchars($log['performed_by']); ?>
                                </span>
                            </td>

                            <!-- Action: Delete -->
                            <td class="py-3 px-3.5 text-center whitespace-nowrap no-print">
                                <button type="button" onclick="deleteActivityLog(<?php echo intval($log['id']); ?>, this)" 
                                        class="px-2.5 py-1 bg-rose-50 hover:bg-rose-600 text-rose-700 hover:text-white border border-rose-200 hover:border-rose-600 rounded-lg text-[11px] font-bold transition inline-flex items-center gap-1 shadow-sm group"
                                        title="Delete this activity record">
                                    <i class="fas fa-trash-alt text-[10px] group-hover:scale-110 transition-transform"></i>
                                    <span>Delete</span>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php endif; ?>
                        <tr id="noResultsRow" class="hidden">
                            <td colspan="9" class="py-12 text-center text-stone-400">
                                <i class="fas fa-search text-3xl mb-2 text-stone-300"></i>
                                <p class="font-medium text-sm text-stone-600">No matching activities found for your search &amp; filter criteria.</p>
                                <button type="button" onclick="resetAllFilters()" class="mt-3 px-3 py-1.5 bg-amber-100 text-amber-900 rounded-lg text-xs font-bold hover:bg-amber-200 transition">
                                    Reset Filters
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <script>
        function toggleCustomDate(val) {
            const box = document.getElementById('customDateBox');
            if (box) {
                if (val === 'custom') box.classList.remove('hidden');
                else box.classList.add('hidden');
            }
        }

        // Live Search and Multi-Filter Logic
        const liveSearchInput = document.getElementById('liveSearchInput');
        const clearSearchBtn = document.getElementById('clearSearchBtn');
        const activitySelect = document.getElementById('activitySelect');
        const categorySelect = document.getElementById('categorySelect');
        const timeframeSelect = document.getElementById('timeframeSelect');
        const fromDateInput = document.getElementById('fromDateInput');
        const toDateInput = document.getElementById('toDateInput');
        const recordCountBadge = document.getElementById('recordCountBadge');
        const noResultsRow = document.getElementById('noResultsRow');

        // Setup real-time listeners
        if (liveSearchInput) {
            liveSearchInput.addEventListener('input', function() {
                if (this.value.trim().length > 0) {
                    clearSearchBtn.classList.remove('hidden');
                } else {
                    clearSearchBtn.classList.add('hidden');
                }
                applyLiveFilter();
            });
        }

        function clearLiveSearch() {
            if (liveSearchInput) {
                liveSearchInput.value = '';
                clearSearchBtn.classList.add('hidden');
                applyLiveFilter();
            }
        }

        function setQuickTab(type) {
            if (activitySelect) {
                activitySelect.value = type;
            }
            updateTabStyles(type);
            applyLiveFilter();
        }

        function updateTabStyles(activeType) {
            document.querySelectorAll('.quick-tab').forEach(btn => {
                btn.className = 'quick-tab px-3.5 py-1.5 rounded-xl text-xs font-semibold transition bg-white text-stone-700 border border-stone-200 hover:bg-amber-50 flex items-center gap-1.5 whitespace-nowrap';
            });
            const activeBtn = document.getElementById('tab-' + activeType);
            if (activeBtn) {
                activeBtn.className = 'quick-tab px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-amber-900 text-white shadow-sm flex items-center gap-1.5 whitespace-nowrap';
            }
        }

        function countTabTotals() {
            const rows = document.querySelectorAll('.activity-row');
            let counts = { all: rows.length, added: 0, billed: 0, edited: 0, deleted: 0, restocked: 0 };
            rows.forEach(r => {
                const t = r.getAttribute('data-type');
                if (counts[t] !== undefined) counts[t]++;
            });
            for (let k in counts) {
                const el = document.getElementById('cnt-' + k);
                if (el) el.textContent = counts[k];
            }
        }

        function applyLiveFilter() {
            const q = liveSearchInput ? liveSearchInput.value.toLowerCase().trim() : '';
            const type = activitySelect ? activitySelect.value : 'all';
            const cat = categorySelect ? categorySelect.value : 'all';
            const time = timeframeSelect ? timeframeSelect.value : 'all';
            const fromD = fromDateInput ? fromDateInput.value : '';
            const toD = toDateInput ? toDateInput.value : '';

            updateTabStyles(type);

            const now = new Date();
            const todayStr = now.toISOString().split('T')[0];
            
            const yDate = new Date();
            yDate.setDate(now.getDate() - 1);
            const yesterdayStr = yDate.toISOString().split('T')[0];

            const d7Date = new Date();
            d7Date.setDate(now.getDate() - 7);
            const d7Str = d7Date.toISOString().split('T')[0];

            const curMonth = now.getMonth();
            const curYear = now.getFullYear();

            const rows = document.querySelectorAll('.activity-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const rowType = row.getAttribute('data-type') || '';
                const rowCat = row.getAttribute('data-category') || '';
                const rowDate = row.getAttribute('data-date') || '';
                const rowSearch = row.getAttribute('data-search') || '';

                let match = true;

                // 1. Activity Type Filter
                if (type !== 'all' && rowType !== type) {
                    match = false;
                }

                // 2. Category Filter
                if (match && cat !== 'all' && rowCat !== cat) {
                    match = false;
                }

                // 3. Timeframe Filter
                if (match && time !== 'all') {
                    if (time === 'today' && rowDate !== todayStr) match = false;
                    else if (time === 'yesterday' && rowDate !== yesterdayStr) match = false;
                    else if (time === '7days' && rowDate < d7Str) match = false;
                    else if (time === 'this_month') {
                        const rDateObj = new Date(rowDate);
                        if (rDateObj.getMonth() !== curMonth || rDateObj.getFullYear() !== curYear) match = false;
                    } else if (time === 'custom' && fromD && toD) {
                        if (rowDate < fromD || rowDate > toD) match = false;
                    }
                }

                // 4. Search Query (multi-word match)
                if (match && q.length > 0) {
                    const terms = q.split(' ');
                    for (let term of terms) {
                        if (term && !rowSearch.includes(term)) {
                            match = false;
                            break;
                        }
                    }
                }

                if (match) {
                    row.style.display = '';
                    visibleCount++;
                    const indexCell = row.querySelector('.row-index');
                    if (indexCell) indexCell.textContent = visibleCount;
                } else {
                    row.style.display = 'none';
                }
            });

            if (recordCountBadge) {
                recordCountBadge.textContent = `${visibleCount} of ${rows.length} records`;
            }

            if (noResultsRow) {
                if (visibleCount === 0 && rows.length > 0) {
                    noResultsRow.classList.remove('hidden');
                } else {
                    noResultsRow.classList.add('hidden');
                }
            }
        }

        function resetAllFilters() {
            if (liveSearchInput) liveSearchInput.value = '';
            if (clearSearchBtn) clearSearchBtn.classList.add('hidden');
            if (activitySelect) activitySelect.value = 'all';
            if (categorySelect) categorySelect.value = 'all';
            if (timeframeSelect) timeframeSelect.value = 'all';
            if (fromDateInput) fromDateInput.value = '';
            if (toDateInput) toDateInput.value = '';
            toggleCustomDate('all');
            setQuickTab('all');
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            countTabTotals();
            applyLiveFilter();
        });

        // Export table to CSV
        function exportTableToCSV(filename) {
            const rows = document.querySelectorAll('#activityTable tr');
            let csv = [];
            for (let i = 0; i < rows.length; i++) {
                if (rows[i].style.display === 'none') continue; // Only export visible filtered rows
                const row = [], cols = rows[i].querySelectorAll('td, th');
                for (let j = 0; j < cols.length - 1; j++) { // Skip the last action column in CSV
                    let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""').trim();
                    row.push('"' + text + '"');
                }
                csv.push(row.join(','));
            }
            const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
            const downloadLink = document.createElement('a');
            downloadLink.download = filename;
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = 'none';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }

        // Delete individual activity log with AJAX
        async function deleteActivityLog(logId, btn) {
            if (!confirm('Are you sure you want to delete this activity log record?')) {
                return;
            }

            const row = btn ? btn.closest('tr') : document.querySelector(`tr[data-log-id="${logId}"]`);
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin text-[10px]"></i>';
                btn.style.opacity = '0.7';
            }
            if (row) {
                row.style.opacity = '0.5';
            }

            try {
                const response = await fetch('product_summary.php?action=delete_log&id=' + encodeURIComponent(logId) + '&ajax=1', {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                
                let data = null;
                try {
                    data = await response.json();
                } catch(e) {
                    window.location.href = 'product_summary.php?action=delete_log&id=' + encodeURIComponent(logId);
                    return;
                }

                if (data && data.success) {
                    if (row) {
                        row.style.transition = 'all 0.3s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            row.remove();
                            applyLiveFilter();
                            if (typeof countTabTotals === 'function') countTabTotals();
                        }, 300);
                    }
                    showToast('Activity record removed successfully', 'success');
                } else {
                    window.location.href = 'product_summary.php?action=delete_log&id=' + encodeURIComponent(logId);
                }
            } catch (err) {
                console.error(err);
                window.location.href = 'product_summary.php?action=delete_log&id=' + encodeURIComponent(logId);
            }
        }

        // Clear all activity logs
        function confirmClearAllLogs() {
            if (!confirm('⚠️ Are you sure you want to CLEAR ALL product activity logs?\nThis will permanently delete the entire audit history.')) {
                return;
            }
            window.location.href = 'product_summary.php?action=clear_all_logs';
        }

        // Toast notification helper
        function showToast(message, type = 'success') {
            let toast = document.getElementById('psToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'psToast';
                toast.className = 'fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-xl text-xs font-bold flex items-center gap-2 transition-all duration-300 transform translate-y-10 opacity-0';
                document.body.appendChild(toast);
            }
            toast.className = 'fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-xl text-xs font-bold flex items-center gap-2 transition-all duration-300 transform ' + 
                (type === 'success' ? 'bg-emerald-900 text-emerald-100 border border-emerald-600' : 'bg-rose-900 text-rose-100 border border-rose-600');
            toast.innerHTML = (type === 'success' ? '<i class="fas fa-check-circle text-emerald-300"></i> ' : '<i class="fas fa-exclamation-circle text-rose-300"></i> ') + message;
            
            requestAnimationFrame(() => {
                toast.classList.remove('translate-y-10', 'opacity-0');
            });

            setTimeout(() => {
                toast.classList.add('translate-y-10', 'opacity-0');
            }, 3000);
        }
    </script>
</body>
</html>

