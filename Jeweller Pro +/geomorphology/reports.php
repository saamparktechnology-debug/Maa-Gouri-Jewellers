<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/company_config.php';
require_once __DIR__ . '/auth_guard.php';

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

// AJAX endpoint to get invoice items details
if (isset($_GET['action']) && $_GET['action'] === 'get_invoice_items') {
    header('Content-Type: application/json');
    $invoice_no = mysqli_real_escape_string($conn, trim($_GET['invoice_no'] ?? ''));
    $items = [];
    if (!empty($invoice_no)) {
        $chk = mysqli_query($conn, "SELECT id FROM invoices WHERE invoice_no = '$invoice_no' AND (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0))");
        if ($chk && mysqli_num_rows($chk) > 0) {
            $invoice = mysqli_fetch_assoc($chk);
            $invoice_id = intval($invoice['id']);
            
            $res = mysqli_query($conn, "
                SELECT 
                    ii.*,
                    p.category,
                    p.weight AS p_weight
                FROM invoice_items ii 
                LEFT JOIN products p ON ii.product_id = p.id 
                WHERE ii.invoice_id = $invoice_id
            ");
            
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $unit = !empty($row['unit']) ? $row['unit'] : ((stripos($row['product_name'] ?? '', 'pair') !== false) ? 'pair' : 'pcs');
                    $gross_wt = !empty($row['gross_wt']) ? floatval($row['gross_wt']) : ($unit === 'g' ? floatval($row['quantity'] ?? 0) : 0);
                    $net_wt = !empty($row['net_wt']) ? floatval($row['net_wt']) : $gross_wt;
                    $pcs = !empty($row['pcs']) ? intval($row['pcs']) : ($unit === 'pair' ? 2 : 1);
                    if ($pcs <= 0) $pcs = 1;
                    $items[] = [
                        'id' => intval($row['id']),
                        'product_id' => $row['product_id'] ? intval($row['product_id']) : null,
                        'product_name' => $row['product_name'] ?? '',
                        'serial_no' => $row['serial_no'] ?? '',
                        'huid_code' => $row['huid_code'] ?? '',
                        'hsn_code' => (!empty($row['hsn_code']) && $row['hsn_code'] !== '0') ? $row['hsn_code'] : '7113',
                        'price' => floatval($row['price'] ?? 0),
                        'quantity' => floatval($row['quantity'] ?? 0),
                        'pcs' => $pcs,
                        'gross_wt' => $gross_wt,
                        'net_wt' => $net_wt,
                        'category' => $row['category'] ?? '',
                        'weight' => $gross_wt > 0 ? $gross_wt : (!empty($row['p_weight']) ? floatval($row['p_weight']) : 0),
                        'unit' => $unit
                    ];
                }
            }
        }
    }
    echo json_encode($items);
    exit();
}

require_once __DIR__ . '/../includes/activity_logger.php';

// Handle Delete Invoice
$delete_msg = '';
$delete_error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action_delete_invoice'])) {
    $invoice_no = mysqli_real_escape_string($conn, trim($_POST['invoice_no'] ?? ''));
    $delete_mode = $_POST['delete_mode'] ?? 'restore_stock';
    if (!empty($invoice_no)) {
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        $chk = mysqli_query($conn, "SELECT id FROM invoices WHERE invoice_no = '$invoice_no'");
        if ($chk && mysqli_num_rows($chk) > 0) {
            $inv_row = mysqli_fetch_assoc($chk);
            $invoice_id = intval($inv_row['id']);

            if ($delete_mode === 'restore_stock') {
                if (isset($_POST['items_restore'])) {
                    $restore_items = json_decode($_POST['items_restore'], true);
                    if (is_array($restore_items)) {
                        foreach ($restore_items as $item) {
                            $product_id = intval($item['product_id'] ?? 0);
                            $restore_qty = floatval($item['restore_qty'] ?? 0);
                            $restore_weight = floatval($item['restore_weight'] ?? 0);
                            $hsn_code = mysqli_real_escape_string($conn, $item['hsn_code'] ?? '7113');
                            $product_name = mysqli_real_escape_string($conn, $item['product_name'] ?? '');
                            $serial_no = mysqli_real_escape_string($conn, $item['serial_no'] ?? '');
                            $huid_code = mysqli_real_escape_string($conn, $item['huid_code'] ?? '');
                            $price = floatval($item['price'] ?? 0);
                            $unit = mysqli_real_escape_string($conn, $item['unit'] ?? 'pcs');
                            $category = mysqli_real_escape_string($conn, $item['category'] ?? '');
                            
                            if ($restore_qty > 0 || $restore_weight > 0) {
                                $product_exists = false;
                                if ($product_id > 0) {
                                    $p_chk = mysqli_query($conn, "SELECT id FROM products WHERE id = $product_id");
                                    if ($p_chk && mysqli_num_rows($p_chk) > 0) {
                                        $product_exists = true;
                                    }
                                }
                                
                                if ($product_exists) {
                                    $wt_sql = $restore_weight > 0 ? ", weight = weight + $restore_weight" : "";
                                    mysqli_query($conn, "UPDATE products SET quantity = quantity + $restore_qty, status = 'In Stock', hsn_code = '$hsn_code' $wt_sql WHERE id = $product_id");
                                } else {
                                    if (empty($category)) {
                                        if (stripos($product_name, '18k') !== false) {
                                            $category = 'Gold 18K';
                                        } else if (stripos($product_name, '12k') !== false) {
                                            $category = 'Gold 12K';
                                        } else if (stripos($product_name, '9k') !== false) {
                                            $category = 'Gold 9K';
                                        } else if (stripos($product_name, 'gold') !== false) {
                                            $category = 'Gold 22K';
                                        } else if (stripos($product_name, 'silver') !== false) {
                                            $category = 'Silver';
                                        } else {
                                            $category = 'Others';
                                        }
                                    }
                                    mysqli_query($conn, "INSERT INTO products (serial_no, name, item_name, category, weight, price, quantity, huid_code, hsn_code, status, created_at) VALUES ('$serial_no', '$product_name', '$product_name', '$category', '$restore_weight', $price, $restore_qty, '$huid_code', '$hsn_code', 'In Stock', NOW())");
                                }

                                if (function_exists('log_product_activity')) {
                                    $wt_detail = $restore_weight > 0 ? " ({$restore_weight}g)" : "";
                                    log_product_activity($conn, [
                                        'activity_type' => 'restocked',
                                        'product_id' => $product_id,
                                        'product_name' => $product_name,
                                        'category' => $category,
                                        'serial_no' => $serial_no,
                                        'huid_code' => $huid_code,
                                        'quantity' => $restore_qty,
                                        'unit' => $unit ?: 'pcs',
                                        'weight' => $restore_weight,
                                        'rate' => $price,
                                        'details' => "Restored +{$restore_qty} {$unit}{$wt_detail} back to stock from deleted Cash Memo #$invoice_no",
                                        'performed_by' => $_SESSION['user_name'] ?? 'Admin'
                                    ]);
                                }
                            }
                        }
                    }
                } else {
                    $items_res = mysqli_query($conn, "SELECT * FROM invoice_items WHERE invoice_id = $invoice_id");
                    if ($items_res) {
                        while ($it = mysqli_fetch_assoc($items_res)) {
                            $pid = intval($it['product_id'] ?? 0);
                            $qty = floatval($it['quantity'] ?? 0);
                            if ($qty > 0) {
                                $product_exists = false;
                                if ($pid > 0) {
                                    $p_chk = mysqli_query($conn, "SELECT id FROM products WHERE id = $pid");
                                    if ($p_chk && mysqli_num_rows($p_chk) > 0) {
                                        $product_exists = true;
                                    }
                                }
                                if ($product_exists) {
                                    mysqli_query($conn, "UPDATE products SET quantity = quantity + $qty WHERE id = $pid");
                                } else {
                                    $name = mysqli_real_escape_string($conn, $it['product_name']);
                                    $serial = mysqli_real_escape_string($conn, $it['serial_no'] ?? '');
                                    $huid = mysqli_real_escape_string($conn, $it['huid_code'] ?? '');
                                    $hsn = mysqli_real_escape_string($conn, $it['hsn_code'] ?? '7113');
                                    $price = floatval($it['price'] ?? 0);
                                    $cat = (stripos($name, '18k') !== false) ? 'Gold 18K' : ((stripos($name, '12k') !== false) ? 'Gold 12K' : ((stripos($name, '9k') !== false) ? 'Gold 9K' : ((stripos($name, 'gold') !== false) ? 'Gold 22K' : ((stripos($name, 'silver') !== false) ? 'Silver' : 'Others'))));
                                    mysqli_query($conn, "INSERT INTO products (serial_no, name, item_name, category, weight, price, quantity, huid_code, hsn_code, created_at) VALUES ('$serial', '$name', '$name', '$cat', '', $price, $qty, '$huid', '$hsn', NOW())");
                                }
                            }
                        }
                    }
                }
            }

            mysqli_query($conn, "DELETE FROM invoice_items WHERE invoice_id = $invoice_id");
            mysqli_query($conn, "DELETE FROM due_update_history WHERE invoice_id = $invoice_id");
            mysqli_query($conn, "DELETE FROM invoices WHERE id = $invoice_id");
            $delete_msg = "Cash memo #$invoice_no has been successfully deleted." . ($delete_mode === 'restore_stock' ? " Items have been moved back to stock." : " (Stock was not modified).");
        } else {
            $delete_error = "Error: Cash memo #$invoice_no not found.";
        }
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
    }
}

// Date Filter & Presets
$date_preset = trim($_GET['date_preset'] ?? 'all');
$today = date('Y-m-d');

if ($date_preset === 'today') {
    $date_from = $today;
    $date_to   = $today;
} elseif ($date_preset === 'yesterday') {
    $date_from = date('Y-m-d', strtotime('-1 day'));
    $date_to   = date('Y-m-d', strtotime('-1 day'));
} elseif ($date_preset === 'this_week') {
    $date_from = date('Y-m-d', strtotime('monday this week'));
    $date_to   = $today;
} elseif ($date_preset === 'this_month') {
    $date_from = date('Y-m-01');
    $date_to   = $today;
} elseif ($date_preset === 'last_month') {
    $date_from = date('Y-m-01', strtotime('first day of last month'));
    $date_to   = date('Y-m-t', strtotime('last day of last month'));
} elseif ($date_preset === 'this_fy') {
    $cur_month = intval(date('m'));
    $cur_year = intval(date('Y'));
    $fy_start_year = ($cur_month >= 4) ? $cur_year : ($cur_year - 1);
    $date_from = $fy_start_year . '-04-01';
    $date_to   = $today;
} elseif ($date_preset === 'custom') {
    $date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : '2020-01-01';
    $date_to   = !empty($_GET['date_to'])   ? $_GET['date_to']   : $today;
} else {
    // Default 'all' - All Time
    $date_preset = 'all';
    $date_from = '2020-01-01';
    $date_to   = $today;
}


$search_query   = mysqli_real_escape_string($conn, trim($_GET['search'] ?? ''));
$status_filter  = mysqli_real_escape_string($conn, trim($_GET['payment_status'] ?? ''));
$min_amount     = floatval($_GET['min_amount'] ?? 0);
$max_amount     = floatval($_GET['max_amount'] ?? 0);

$where_clauses = [
    "(gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0))",
    "DATE(created_at) BETWEEN '$date_from' AND '$date_to'"
];

if (!empty($search_query)) {
    $where_clauses[] = "(invoice_no LIKE '%$search_query%' OR customer_name LIKE '%$search_query%' OR customer_mobile LIKE '%$search_query%')";
}
if (!empty($status_filter) && $status_filter !== 'all') {
    $where_clauses[] = "payment_status = '$status_filter'";
}
if ($min_amount > 0) {
    $where_clauses[] = "total_amount >= $min_amount";
}
if ($max_amount > 0) {
    $where_clauses[] = "total_amount <= $max_amount";
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=geomorphology_sales_reports_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Cash Memo No', 'Customer Name', 'Mobile', 'Subtotal', 'Cash Paid', 'UPI Paid', 'Old Gold Value', 'Total Amount', 'Paid Amount', 'Due Balance', 'Status', 'Date']);
    
    $export_res = mysqli_query($conn, "SELECT * FROM invoices $where_sql ORDER BY created_at DESC");
    if ($export_res) {
        $idx = 1;
        while ($row = mysqli_fetch_assoc($export_res)) {
            fputcsv($out, [
                $idx++,
                $row['invoice_no'],
                $row['customer_name'],
                $row['customer_mobile'],
                $row['subtotal'],
                $row['cash_paid'] ?? 0,
                $row['upi_paid'] ?? 0,
                $row['old_gold_value'] ?? 0,
                $row['total_amount'],
                $row['paid_amount'],
                $row['balance_amount'],
                $row['payment_status'],
                $row['created_at']
            ]);
        }
    }
    fclose($out);
    exit();
}

// Summary Metrics
$stat_res = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT 
        COUNT(*) as cnt, 
        COALESCE(SUM(total_amount), 0) as total_amt, 
        COALESCE(SUM(paid_amount), 0) as total_paid, 
        COALESCE(SUM(balance_amount), 0) as total_due,
        COALESCE(SUM(cash_paid), 0) as total_cash,
        COALESCE(SUM(upi_paid), 0) as total_upi,
        COALESCE(SUM(old_gold_value), 0) as total_oldgold
    FROM invoices $where_sql
"));

$total_count      = intval($stat_res['cnt'] ?? 0);
$total_amount_sum = floatval($stat_res['total_amt'] ?? 0);
$total_paid_sum   = floatval($stat_res['total_paid'] ?? 0);
$total_due_sum    = floatval($stat_res['total_due'] ?? 0);
$total_cash_sum   = floatval($stat_res['total_cash'] ?? 0);
$total_upi_sum    = floatval($stat_res['total_upi'] ?? 0);
$total_gold_sum   = floatval($stat_res['total_oldgold'] ?? 0);

// Invoices Query
$bills_rows = [];
$inv_res = mysqli_query($conn, "
    SELECT id, invoice_no, customer_name, customer_mobile, customer_address, subtotal, total_amount, paid_amount, balance_amount, payment_status, payment_method, cash_paid, upi_paid, old_gold_value, created_at 
    FROM invoices 
    $where_sql 
    ORDER BY created_at DESC 
    LIMIT 300
");
if ($inv_res) {
    while($r = mysqli_fetch_assoc($inv_res)) $bills_rows[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Geomorphology Sales Reports - MAA GOURI JEWELLERS</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="shortcut icon" type="image/png" href="../logo.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../mobile.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Poppins', sans-serif; font-weight: 700; }

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
        .sidebar-logo img {
            width: 44px; height: 44px; object-fit: contain; border-radius: 50%;
            background: rgba(255,255,255,0.1); padding: 3px; flex-shrink: 0;
        }
        .sidebar-logo-text h2 {
            color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3;
            letter-spacing: 0.5px;
        }
        .sidebar-logo-text p {
            color: #ffd700; font-size: 10px; margin-top: 1px; font-weight: 600;
        }
        .sidebar-nav { flex: 1; padding: 10px 0; overflow-y: auto; overflow-x: hidden; }
        .sidebar-section-label {
            padding: 10px 20px 4px; color: #ffd700; font-size: 9px; font-weight: 700;
            letter-spacing: 1.5px; text-transform: uppercase; position: sticky; top: 0;
            background: #011921; z-index: 10;
        }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px; padding: 11px 20px;
            color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px;
            font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent;
        }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
        .sidebar-nav a.active { background: rgba(255,255,225,0.22); color: #ffd700; border-left-color: #ffd700; font-weight: 700; }
        .sidebar-nav a.active::after { content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 60%; background: #ffd700; border-radius: 4px 0 0 4px; }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; opacity: 0.9; }

        .page-wrapper { margin-left: 240px; min-height: 100vh; background: #fffbf4; transition: margin-left 0.35s ease; }
        nav.nav-gold {
            background: linear-gradient(135deg, #011921, #03373b) !important;
            border-bottom: 2.5px solid #ffd700;
            box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important;
        }
        .card-gold {
            background: #fff; border: 1px solid rgba(214,139,22,0.25); border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05); transition: transform 0.2s, box-shadow 0.2s;
        }
        .card-gold:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(214,139,22,0.15); }
        .btn-gold-action {
            background: linear-gradient(135deg, #7a4e0a, #d68b16); color: #fff;
            padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700;
            transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-gold-action:hover { filter: brightness(1.1); color: #fff; }

        @media(max-width: 1024px) {
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
            <a href="/geomorphology"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="/geomorphology/reports" class="active"><i class="fas fa-chart-bar"></i> Sales Reports</a>
            <a href="/geomorphology/accounts"><i class="fas fa-wallet"></i> Accounts Ledger</a>
            <a href="/geomorphology/due_list"><i class="fas fa-file-invoice-dollar"></i> Due Management</a>
            <a href="/geomorphology/income_expenses"><i class="fas fa-exchange-alt"></i> Income &amp; Expenses</a>

            <div class="sidebar-section-label mt-4">Security</div>
            <a href="/geomorphology?geo_action=lock" class="text-rose-300 hover:text-rose-100"><i class="fas fa-lock text-rose-400"></i> Lock Section</a>

            <div class="sidebar-section-label mt-4">Switch System</div>
            <a href="/geomorphology?geo_action=exit_to_main&target=/billing"><i class="fas fa-cash-register" style="color:#10b981;"></i> New Bill (Billing)</a>
            <a href="/geomorphology?geo_action=exit_to_main&target=/index.php"><i class="fas fa-arrow-left" style="color:#60a5fa;"></i> Back to Main GST App</a>
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
                    <h1 class="text-base sm:text-lg font-bold text-white tracking-wide">GEOMORPHOLOGY SALES REPORTS</h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-stone-950 shadow-sm">
                        PROTECTED MODE
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="/geomorphology?geo_action=lock" title="Lock section" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-rose-600/80 hover:bg-rose-600 text-white transition inline-flex items-center gap-1">
                    <i class="fas fa-lock"></i> <span class="hidden sm:inline">Lock</span>
                </a>
                <a href="/geomorphology" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 text-white border border-white/20 transition">
                    <i class="fas fa-tachometer-alt mr-1"></i> Dashboard
                </a>
                <a href="/geomorphology?geo_action=exit_to_main&target=/index.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 text-white border border-white/20 transition hidden sm:inline-flex">
                    <i class="fas fa-home mr-1"></i> Main App
                </a>
            </div>
        </nav>

        <!-- Main Body -->
        <main class="p-4 sm:p-6 lg:p-8 flex-1">

            <?php if(!empty($delete_msg)): ?>
            <div class="mb-4 p-3 bg-emerald-100 border border-emerald-300 text-emerald-800 rounded-xl text-xs font-bold flex items-center justify-between">
                <span><i class="fas fa-check-circle mr-1"></i> <?php echo htmlspecialchars($delete_msg); ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-700">&times;</button>
            </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-[#7a4e0a]">Non-GST Sales &amp; Memos</h2>
                    <p class="text-xs sm:text-sm text-stone-600 mt-1">Detailed sales analysis and line-item breakdown for private cash memos.</p>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <?php 
                        $csv_query = $_GET;
                        $csv_query['export'] = 'csv';
                        $csv_url = '/geomorphology/reports?' . http_build_query($csv_query);
                    ?>
                    <a href="<?php echo $csv_url; ?>" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white font-bold rounded-xl text-xs shadow transition inline-flex items-center gap-1.5">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                </div>
            </div>

            <!-- Preset Filters Bar -->
            <div class="card-gold p-4 mb-6">
                <!-- Quick Date Presets -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-3 mb-3 border-b border-stone-200 text-xs">
                    <span class="font-bold text-stone-400 mr-1 uppercase text-[10px]">Presets:</span>
                    <?php
                        $presets = [
                            'today' => 'Today',
                            'yesterday' => 'Yesterday',
                            'this_week' => 'This Week',
                            'this_month' => 'This Month',
                            'last_month' => 'Last Month',
                            'this_fy' => 'This FY',
                            'all' => 'All Time'
                        ];
                        foreach ($presets as $k => $label):
                            $active = ($date_preset === $k);
                    ?>
                    <a href="/geomorphology/reports?date_preset=<?php echo $k; ?>" 
                       class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?php echo $active ? 'bg-[#7a4e0a] text-white shadow' : 'bg-stone-100 text-stone-700 hover:bg-stone-200'; ?>">
                        <?php echo $label; ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Custom Filter Inputs -->
                <form method="GET" action="/geomorphology/reports" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 text-xs">
                    <input type="hidden" name="date_preset" value="custom">
                    <div>
                        <label class="block font-bold text-stone-600 mb-1">Date From</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg text-stone-800">
                    </div>
                    <div>
                        <label class="block font-bold text-stone-600 mb-1">Date To</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg text-stone-800">
                    </div>
                    <div>
                        <label class="block font-bold text-stone-600 mb-1">Search Customer / Memo</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Name, mobile, memo #..." class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg text-stone-800">
                    </div>
                    <div>
                        <label class="block font-bold text-stone-600 mb-1">Payment Status</label>
                        <select name="payment_status" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg font-semibold">
                            <option value="all">All Statuses</option>
                            <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Fully Paid</option>
                            <option value="part" <?php echo $status_filter === 'part' ? 'selected' : ''; ?>>Partially Due</option>
                            <option value="unpaid" <?php echo $status_filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="btn-gold-action w-full justify-center py-2">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="/geomorphology/reports" class="px-3 py-2 bg-stone-100 hover:bg-stone-200 text-stone-600 rounded-lg font-bold">
                            Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Financial Metrics Ribbon -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <div class="card-gold p-4 border-l-4 border-amber-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Cash Memos</span>
                    <div class="text-xl font-extrabold text-[#7a4e0a] mt-1"><?php echo $total_count; ?></div>
                    <div class="text-[10px] text-stone-400 mt-0.5">Total Count</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-blue-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Total Sales Value</span>
                    <div class="text-xl font-extrabold text-blue-900 mt-1">₹<?php echo number_format($total_amount_sum, 2); ?></div>
                    <div class="text-[10px] text-stone-400 mt-0.5">Non-GST Gross</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-emerald-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Cash Collected</span>
                    <div class="text-xl font-extrabold text-emerald-700 mt-1">₹<?php echo number_format($total_cash_sum, 2); ?></div>
                    <div class="text-[10px] text-stone-400 mt-0.5">Physical Cash</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-teal-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">UPI / Online</span>
                    <div class="text-xl font-extrabold text-teal-700 mt-1">₹<?php echo number_format($total_upi_sum, 2); ?></div>
                    <div class="text-[10px] text-stone-400 mt-0.5">Digital Payments</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-purple-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Old Gold Value</span>
                    <div class="text-xl font-extrabold text-purple-700 mt-1">₹<?php echo number_format($total_gold_sum, 2); ?></div>
                    <div class="text-[10px] text-stone-400 mt-0.5">Exchange Adjusted</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-rose-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Pending Due</span>
                    <div class="text-xl font-extrabold text-rose-600 mt-1">₹<?php echo number_format($total_due_sum, 2); ?></div>
                    <div class="text-[10px] text-stone-400 mt-0.5">Outstanding Balance</div>
                </div>
            </div>

            <!-- Invoices Table -->
            <div class="card-gold overflow-hidden">
                <div class="p-4 border-b border-stone-200 bg-amber-50/50 flex items-center justify-between">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-[#7a4e0a]">Cash Memo Records (<?php echo $total_count; ?>)</h3>
                    <span class="text-xs text-stone-500">Showing up to 300 latest records</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-[#f7eed7] border-b border-stone-200 text-stone-700 uppercase font-bold text-[11px]">
                                <th class="py-3 px-3 w-8">#</th>
                                <th class="py-3 px-3">Memo No</th>
                                <th class="py-3 px-3">Date</th>
                                <th class="py-3 px-3">Customer Details</th>
                                <th class="py-3 px-3 text-right">Total Amount</th>
                                <th class="py-3 px-3 text-right">Paid Amount</th>
                                <th class="py-3 px-3 text-right">Due Balance</th>
                                <th class="py-3 px-3 text-center">Status</th>
                                <th class="py-3 px-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            <?php if(empty($bills_rows)): ?>
                            <tr><td colspan="9" class="text-center py-10 text-stone-400">No Non-GST invoices found matching your criteria.</td></tr>
                            <?php else: foreach($bills_rows as $idx => $b): ?>
                            <tr class="hover:bg-amber-50/50 transition" id="row-memo-<?php echo $b['id']; ?>">
                                <td class="py-3 px-3 text-stone-400 font-semibold"><?php echo $idx + 1; ?></td>
                                <td class="py-3 px-3">
                                    <div class="font-bold text-[#7a4e0a]"><?php echo htmlspecialchars($b['invoice_no']); ?></div>
                                    <button onclick="toggleItems('<?php echo addslashes($b['invoice_no']); ?>', <?php echo $b['id']; ?>)" class="text-[10px] text-amber-700 hover:underline font-bold mt-0.5 inline-flex items-center gap-1">
                                        <i class="fas fa-chevron-down text-[8px]" id="chevron-<?php echo $b['id']; ?>"></i> Items Breakdown
                                    </button>
                                </td>
                                <td class="py-3 px-3 text-stone-600 font-medium"><?php echo date('d/m/Y', strtotime($b['created_at'])); ?></td>
                                <td class="py-3 px-3">
                                    <div class="font-bold text-stone-900"><?php echo htmlspecialchars($b['customer_name'] ?: 'Cash Customer'); ?></div>
                                    <div class="text-[10px] text-stone-500">Ph: <?php echo htmlspecialchars($b['customer_mobile'] ?: 'N/A'); ?></div>
                                </td>
                                <td class="py-3 px-3 text-right font-extrabold text-stone-900">₹<?php echo number_format($b['total_amount'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-bold text-emerald-700">₹<?php echo number_format($b['paid_amount'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-bold <?php echo $b['balance_amount'] > 0 ? 'text-rose-600 font-extrabold' : 'text-stone-400'; ?>">
                                    ₹<?php echo number_format($b['balance_amount'], 2); ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <?php if($b['payment_status'] === 'paid'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-100 text-emerald-800">Paid</span>
                                    <?php elseif($b['payment_status'] === 'part'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-800">Part Due</span>
                                    <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-100 text-rose-800">Unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="/view_pdf.php?invoice_no=<?php echo urlencode($b['invoice_no']); ?>" target="_blank" title="Print Cash Memo" class="px-2.5 py-1.5 bg-[#011921] hover:bg-[#03373b] text-[#ffd700] font-bold rounded-lg text-xs transition inline-flex items-center gap-1">
                                            <i class="fas fa-print text-[10px]"></i> View
                                        </a>
                                        <button type="button" onclick="openDeleteInvoiceModal('<?php echo addslashes($b['invoice_no']); ?>')" title="Delete Memo" class="px-2 py-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 font-bold rounded-lg text-xs transition cursor-pointer">
                                            <i class="fas fa-trash-alt text-[10px]"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <!-- Line Items Accordion Row -->
                            <tr id="items-row-<?php echo $b['id']; ?>" class="hidden bg-stone-50/80">
                                <td colspan="9" class="p-3 border-t border-b border-stone-200">
                                    <div id="items-container-<?php echo $b['id']; ?>" class="p-3 bg-white rounded-xl border border-stone-200 text-xs">
                                        <div class="text-center py-2 text-stone-400"><i class="fas fa-spinner fa-spin mr-1"></i> Loading items...</div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- Custom Delete Invoice Modal -->
    <div id="deleteInvoiceModal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl border border-amber-200 shadow-2xl w-full max-w-md overflow-hidden flex flex-col transform transition-transform duration-300 text-xs">
            <!-- Modal Header -->
            <div class="px-5 py-4 bg-gradient-to-r from-red-800 to-red-600 flex justify-between items-center text-white">
                <h3 class="text-sm font-bold flex items-center gap-2">
                    <i class="fas fa-trash-alt"></i> Delete Cash Memo #<span id="deleteInvoiceNoText"></span>
                </h3>
                <button type="button" onclick="closeDeleteModal()" class="text-white hover:text-gray-200 text-xl font-bold cursor-pointer">&times;</button>
            </div>
            
            <!-- Modal Body -->
            <div class="p-5 space-y-4 max-h-[65vh] overflow-y-auto">
                <p class="text-xs text-gray-600">
                    Choose how you want to handle the products in this cash memo when deleting it:
                </p>
                
                <div class="flex gap-3">
                    <button type="button" onclick="selectDeleteMode('permanent')" id="btnDeletePerm" class="flex-1 py-3 px-3.5 rounded-xl border border-red-300 text-red-700 bg-red-50 hover:bg-red-100 font-semibold text-xs transition duration-200 text-center cursor-pointer">
                        <i class="fas fa-trash-alt block text-sm mb-1 text-red-600"></i>
                        <strong>Delete Permanently</strong><br>
                        <span class="text-[9px] font-normal text-red-500">(Stock is NOT updated)</span>
                    </button>
                    <button type="button" onclick="selectDeleteMode('restore_stock')" id="btnDeleteRestore" class="flex-1 py-3 px-3.5 rounded-xl border border-green-300 text-green-700 bg-green-50 hover:bg-green-100 font-semibold text-xs transition duration-200 text-center cursor-pointer">
                        <i class="fas fa-boxes block text-sm mb-1 text-green-600"></i>
                        <strong>Move to Stock</strong><br>
                        <span class="text-[9px] font-normal text-green-500">(Add items to inventory)</span>
                    </button>
                </div>
                
                <!-- Items Form (Visible only when 'restore_stock' is selected) -->
                <div id="restoreStockForm" class="hidden space-y-3 pt-3 border-t border-dashed border-gray-200">
                    <h4 class="text-xs font-bold text-yellow-800 uppercase tracking-wider">Quantities &amp; Weight to move back:</h4>
                    <div class="space-y-2" id="restoreItemsList">
                        <!-- Dynamic Items list populated by JS -->
                    </div>
                </div>
            </div>
            
            <!-- Modal Footer -->
            <div class="px-5 py-3 bg-gray-50 flex justify-end gap-2.5 border-t border-gray-100">
                <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs font-semibold rounded-lg transition cursor-pointer">
                    Cancel
                </button>
                <button type="button" id="btnConfirmDeleteSubmit" onclick="submitDeleteInvoiceAction()" disabled class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-lg transition cursor-pointer opacity-50">
                    Confirm Delete
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentDeleteInvoiceNo = '';
        let currentDeleteMode = null;
        let invoiceItemsCache = [];

        async function toggleItems(invoiceNo, invoiceId) {
            const row = document.getElementById('items-row-' + invoiceId);
            const chevron = document.getElementById('chevron-' + invoiceId);
            const container = document.getElementById('items-container-' + invoiceId);

            if (!row || !chevron || !container) return;

            if (!row.classList.contains('hidden')) {
                row.classList.add('hidden');
                chevron.classList.remove('rotate-180');
                return;
            }

            row.classList.remove('hidden');
            chevron.classList.add('rotate-180');

            if (container.dataset.loaded === 'true') {
                return;
            }

            try {
                container.innerHTML = '<div class="text-center py-2 text-stone-400"><i class="fas fa-spinner fa-spin mr-1"></i> Loading details...</div>';
                const res = await fetch('reports.php?action=get_invoice_items&invoice_no=' + encodeURIComponent(invoiceNo));
                const data = await res.json();
                
                if (data && data.length > 0) {
                    let html = '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">';
                    data.forEach(it => {
                        const pcsTxt = it.pcs ? it.pcs + ' pcs' : '';
                        const wtTxt = it.gross_wt > 0 ? parseFloat(it.gross_wt).toFixed(3) + 'g' : '';
                        const spec = [pcsTxt, wtTxt].filter(Boolean).join(' | ');
                        html += `
                            <div class="bg-stone-50 border border-stone-200 rounded-lg p-2 flex justify-between items-center text-xs">
                                <div>
                                    <div class="font-bold text-stone-800">${it.product_name}</div>
                                    <div class="text-[10px] text-stone-500 font-mono">${spec ? spec : (it.quantity + ' ' + (it.unit||'pcs'))}</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-emerald-700">₹${parseFloat(it.price).toLocaleString('en-IN', {minimumFractionDigits:2})}</div>
                                    ${it.huid_code ? `<span class="text-[9px] bg-blue-50 text-blue-700 border border-blue-200 rounded px-1">${it.huid_code}</span>` : ''}
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                    container.dataset.loaded = 'true';
                } else {
                    container.innerHTML = '<div class="text-center py-2 text-stone-400">No individual line items found for this memo.</div>';
                }
            } catch (err) {
                container.innerHTML = '<div class="text-center py-2 text-rose-500">Failed to load item details.</div>';
            }
        }

        function openDeleteInvoiceModal(invoiceNo) {
            currentDeleteInvoiceNo = invoiceNo;
            currentDeleteMode = null;
            invoiceItemsCache = [];
            
            document.getElementById('deleteInvoiceNoText').innerText = invoiceNo;
            
            const btnSubmit = document.getElementById('btnConfirmDeleteSubmit');
            btnSubmit.disabled = true;
            btnSubmit.style.opacity = '0.5';
            
            const btnPerm = document.getElementById('btnDeletePerm');
            const btnRestore = document.getElementById('btnDeleteRestore');
            btnPerm.className = 'flex-1 py-3 px-3.5 rounded-xl border border-red-300 text-red-700 bg-red-50 hover:bg-red-100 font-semibold text-xs transition duration-200 text-center cursor-pointer';
            btnRestore.className = 'flex-1 py-3 px-3.5 rounded-xl border border-green-300 text-green-700 bg-green-50 hover:bg-green-100 font-semibold text-xs transition duration-200 text-center cursor-pointer';
            
            document.getElementById('restoreStockForm').classList.add('hidden');
            const itemsList = document.getElementById('restoreItemsList');
            itemsList.innerHTML = '<div class="text-center py-2 text-stone-400"><i class="fas fa-spinner fa-spin mr-1"></i> Loading items...</div>';
            
            const modal = document.getElementById('deleteInvoiceModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            fetch('reports.php?action=get_invoice_items&invoice_no=' + encodeURIComponent(invoiceNo))
                .then(res => res.json())
                .then(data => {
                    invoiceItemsCache = data;
                    itemsList.innerHTML = '';
                    
                    if (!data || data.length === 0) {
                        itemsList.innerHTML = '<p class="text-xs text-gray-500 text-center py-2">No individual product items found in this cash memo.</p>';
                        return;
                    }
                    
                    data.forEach((item, index) => {
                        const itemDiv = document.createElement('div');
                        itemDiv.className = 'p-3 bg-amber-50/80 rounded-xl border border-amber-200 flex flex-col gap-2';
                        
                        let soldLabel = '';
                        if (item.gross_wt > 0) {
                            soldLabel = `Sold: ${item.pcs || 1} pcs (${parseFloat(item.gross_wt).toFixed(3)}g)`;
                        } else {
                            soldLabel = `Sold: ${item.pcs || item.quantity} ${item.unit || 'pcs'}`;
                        }

                        const defaultQty = item.pcs || 1;
                        const defaultWeight = item.gross_wt || item.weight || item.net_wt || 0;

                        itemDiv.innerHTML = `
                            <div class="flex justify-between items-start">
                                <span class="text-xs font-bold text-stone-800">${item.product_name}</span>
                                <span class="text-[10px] text-amber-900 bg-amber-100/90 px-2 py-0.5 rounded font-mono font-bold">${soldLabel}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-1">
                                <div>
                                    <label class="block text-[10px] font-bold text-stone-700 mb-1">Qty to Restore (Pcs)</label>
                                    <input type="number" id="restoreQty_${index}" value="${defaultQty}" step="1" min="0" class="w-full bg-white border border-amber-300 rounded-lg px-2 py-1.5 text-xs font-bold text-stone-800">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-stone-700 mb-1">Weight to Restore (g)</label>
                                    <input type="number" id="restoreWeight_${index}" value="${defaultWeight}" step="0.001" min="0" class="w-full bg-white border border-amber-300 rounded-lg px-2 py-1.5 text-xs font-bold text-stone-800">
                                </div>
                            </div>
                        `;
                        itemsList.appendChild(itemDiv);
                    });
                })
                .catch(err => {
                    console.error('Error fetching invoice items:', err);
                    itemsList.innerHTML = '<p class="text-xs text-red-500 text-center py-2">Failed to load invoice items.</p>';
                });
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteInvoiceModal');
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }

        function selectDeleteMode(mode) {
            currentDeleteMode = mode;
            const btnSubmit = document.getElementById('btnConfirmDeleteSubmit');
            btnSubmit.disabled = false;
            btnSubmit.style.opacity = '1';
            
            const btnPerm = document.getElementById('btnDeletePerm');
            const btnRestore = document.getElementById('btnDeleteRestore');
            const form = document.getElementById('restoreStockForm');
            
            if (mode === 'permanent') {
                btnPerm.className = 'flex-1 py-3 px-3.5 rounded-xl border-2 border-red-600 text-red-700 bg-red-100 font-bold text-xs transition duration-200 text-center cursor-pointer shadow-sm';
                btnRestore.className = 'flex-1 py-3 px-3.5 rounded-xl border border-green-300 text-green-700 bg-green-50 hover:bg-green-100 font-semibold text-xs transition duration-200 text-center cursor-pointer';
                form.classList.add('hidden');
            } else {
                btnRestore.className = 'flex-1 py-3 px-3.5 rounded-xl border-2 border-green-600 text-green-700 bg-green-100 font-bold text-xs transition duration-200 text-center cursor-pointer shadow-sm';
                btnPerm.className = 'flex-1 py-3 px-3.5 rounded-xl border border-red-300 text-red-700 bg-red-50 hover:bg-red-100 font-semibold text-xs transition duration-200 text-center cursor-pointer';
                form.classList.remove('hidden');
            }
        }

        function submitDeleteInvoiceAction() {
            if (!currentDeleteMode) return;
            
            let confirmMsg = '';
            if (currentDeleteMode === 'permanent') {
                confirmMsg = "⚠️ FINAL WARNING: This action is permanent and CANNOT be undone.\nAre you sure you want to delete Cash Memo #" + currentDeleteInvoiceNo + " permanently (without restoring stock)?";
            } else {
                confirmMsg = "Are you sure you want to delete Cash Memo #" + currentDeleteInvoiceNo + " and move the specified quantities & weights back to stock?";
            }
            
            if (!confirm(confirmMsg)) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.pathname;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action_delete_invoice';
            actionInput.value = '1';
            form.appendChild(actionInput);
            
            const invoiceInput = document.createElement('input');
            invoiceInput.type = 'hidden';
            invoiceInput.name = 'invoice_no';
            invoiceInput.value = currentDeleteInvoiceNo;
            form.appendChild(invoiceInput);
            
            const modeInput = document.createElement('input');
            modeInput.type = 'hidden';
            modeInput.name = 'delete_mode';
            modeInput.value = currentDeleteMode;
            form.appendChild(modeInput);
            
            if (currentDeleteMode === 'restore_stock') {
                const restoreData = invoiceItemsCache.map((item, index) => {
                    const qtyInput = document.getElementById('restoreQty_' + index);
                    const weightInput = document.getElementById('restoreWeight_' + index);
                    
                    return {
                        product_id: item.product_id,
                        product_name: item.product_name,
                        serial_no: item.serial_no || '',
                        huid_code: item.huid_code || '',
                        price: item.price || 0,
                        unit: item.unit || 'pcs',
                        category: item.category || '',
                        restore_qty: parseFloat(qtyInput ? qtyInput.value : (item.pcs || 1)) || 0,
                        restore_weight: parseFloat(weightInput ? weightInput.value : (item.gross_wt || 0)) || 0,
                        hsn_code: (item.hsn_code && item.hsn_code !== '0') ? item.hsn_code : '7113'
                    };
                });
                
                const restoreInput = document.createElement('input');
                restoreInput.type = 'hidden';
                restoreInput.name = 'items_restore';
                restoreInput.value = JSON.stringify(restoreData);
                form.appendChild(restoreInput);
            }
            
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>
