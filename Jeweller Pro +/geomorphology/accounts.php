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

// Month filter (Smart auto-selection)
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
    $month = $_GET['month'];
} else {
    $cur_m = date('Y-m');
    $chk_cur = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM invoices WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)) AND DATE(created_at) BETWEEN '$cur_m-01' AND '$cur_m-31'"))['c'] ?? 0;
    if ($chk_cur == 0) {
        $latest_m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m') as lm FROM invoices WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)) ORDER BY created_at DESC LIMIT 1"))['lm'] ?? date('Y-m');
        $month = $latest_m ?: date('Y-m');
    } else {
        $month = $cur_m;
    }
}

$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));


$prevMonth = date('Y-m', strtotime('-1 month', strtotime($monthStart)));
$nextMonth = date('Y-m', strtotime('+1 month', strtotime($monthStart)));

$monthStartEsc = mysqli_real_escape_string($conn, $monthStart);
$monthEndEsc   = mysqli_real_escape_string($conn, $monthEnd);

// 1. Fetch all paid/part Non-GST invoices for the month
$sql = "
    SELECT
        DATE(created_at)               AS day,
        id,
        invoice_no,
        customer_name,
        payment_method,
        payment_status,
        COALESCE(cash_paid, 0)         AS cash_paid,
        COALESCE(upi_paid, 0)          AS upi_paid,
        COALESCE(account_paid, 0)      AS account_paid,
        COALESCE(cheque_paid, 0)       AS cheque_paid,
        COALESCE(old_gold_value, 0)    AS old_gold_value,
        paid_amount,
        total_amount,
        balance_amount
    FROM invoices
    WHERE DATE(created_at) BETWEEN '$monthStartEsc' AND '$monthEndEsc'
      AND (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0))
      AND payment_status != 'unpaid'
    ORDER BY created_at DESC
";
$res = mysqli_query($conn, $sql);

$days = [];
$day_invoices = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $d = $row['day'];
        if (!isset($days[$d])) {
            $days[$d] = ['cash' => 0, 'upi' => 0, 'cheque' => 0, 'oldgold' => 0, 'total' => 0, 'bills' => 0, 'due_collections' => 0];
            $day_invoices[$d] = [];
        }
        $cash    = floatval($row['cash_paid']);
        $upi     = floatval($row['upi_paid']) + floatval($row['account_paid']);
        $cheque  = floatval($row['cheque_paid']);
        $oldgold = floatval($row['old_gold_value']);

        // Fallback if split columns not populated
        $method = strtolower(trim($row['payment_method'] ?? ''));
        if ($cash == 0 && $upi == 0 && $cheque == 0 && $oldgold == 0) {
            if (strpos($method, 'cash') !== false) {
                $cash = floatval($row['paid_amount']);
            } elseif (strpos($method, 'upi') !== false || strpos($method, 'neft') !== false || strpos($method, 'account') !== false) {
                $upi = floatval($row['paid_amount']);
            } else {
                $cash = floatval($row['paid_amount']);
            }
        }

        $days[$d]['cash']    += $cash;
        $days[$d]['upi']     += $upi;
        $days[$d]['cheque']  += $cheque;
        $days[$d]['oldgold'] += $oldgold;
        $days[$d]['total']   += floatval($row['paid_amount']);
        $days[$d]['bills']   += 1;

        $day_invoices[$d][] = [
            'type' => 'invoice',
            'invoice_no' => $row['invoice_no'],
            'customer_name' => $row['customer_name'] ?: 'Cash Customer',
            'amount' => floatval($row['paid_amount']),
            'mode' => $row['payment_method'] ?: 'Cash'
        ];
    }
}

// 2. Fetch Due Payments Cleared in Month (from due_update_history for Non-GST invoices)
$dueSql = "
    SELECT 
        DATE(h.payment_date)           AS day,
        COALESCE(h.amount_paid, 0)     AS amount_paid,
        COALESCE(h.payment_mode, 'Cash') AS payment_mode,
        i.invoice_no,
        i.customer_name
    FROM due_update_history h
    JOIN invoices i ON h.invoice_id = i.id
    WHERE DATE(h.payment_date) BETWEEN '$monthStartEsc' AND '$monthEndEsc'
      AND (i.gst_type NOT LIKE 'gst%' AND (i.gst_amount IS NULL OR i.gst_amount = 0))
      AND h.amount_paid > 0
    ORDER BY h.payment_date DESC
";
$dueRes = mysqli_query($conn, $dueSql);
if ($dueRes) {
    while ($drow = mysqli_fetch_assoc($dueRes)) {
        $d = $drow['day'];
        if (!isset($days[$d])) {
            $days[$d] = ['cash' => 0, 'upi' => 0, 'cheque' => 0, 'oldgold' => 0, 'total' => 0, 'bills' => 0, 'due_collections' => 0];
            $day_invoices[$d] = [];
        }
        $amt  = floatval($drow['amount_paid']);
        $mode = strtolower(trim($drow['payment_mode']));

        if (strpos($mode, 'upi') !== false || strpos($mode, 'neft') !== false || strpos($mode, 'online') !== false || strpos($mode, 'account') !== false) {
            $days[$d]['upi'] += $amt;
        } else {
            $days[$d]['cash'] += $amt;
        }
        $days[$d]['total'] += $amt;
        $days[$d]['due_collections'] += $amt;

        $day_invoices[$d][] = [
            'type' => 'due_recovery',
            'invoice_no' => $drow['invoice_no'],
            'customer_name' => $drow['customer_name'] ?: 'Customer',
            'amount' => $amt,
            'mode' => $drow['payment_mode'] . ' (Due Recovery)'
        ];
    }
}

krsort($days);

$totCash = 0; $totUpi = 0; $totCheque = 0; $totOldGold = 0; $totAll = 0; $totBills = 0; $totDueCol = 0;
foreach ($days as $d => $data) {
    $totCash    += $data['cash'];
    $totUpi     += $data['upi'];
    $totCheque  += $data['cheque'];
    $totOldGold += $data['oldgold'];
    $totAll     += $data['total'];
    $totBills   += $data['bills'];
    $totDueCol  += $data['due_collections'];
}
$totDueCols = $totDueCol;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Geomorphology Accounts Ledger - MAA GOURI JEWELLERS</title>
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
        @media print {
            .sidebar, nav.nav-gold, .no-print { display: none !important; }
            .page-wrapper { margin-left: 0 !important; }
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
            <a href="product_summary.php"><i class="fas fa-boxes"></i> Product Summary</a>
            <a href="accounts.php" class="active"><i class="fas fa-wallet"></i> Accounts Ledger</a>
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
        <nav class="nav-gold text-white px-4 py-3 sticky top-0 z-40 flex items-center justify-between shadow-lg no-print">
            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('sidebar').classList.toggle('open')" class="lg:hidden text-white text-xl p-1">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="flex items-center gap-2">
                    <h1 class="text-base sm:text-lg font-bold text-white tracking-wide">GEOMORPHOLOGY ACCOUNTS LEDGER</h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-stone-950 shadow-sm">
                        PROTECTED MODE
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="index.php?geo_action=lock" title="Lock section" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-rose-600/80 hover:bg-rose-600 text-white transition inline-flex items-center gap-1">
                    <i class="fas fa-lock"></i> <span class="hidden sm:inline">Lock</span>
                </a>
                <a href="index.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 text-white border border-white/20 transition">
                    <i class="fas fa-tachometer-alt mr-1"></i> Dashboard
                </a>
                <a href="index.php?geo_action=exit_to_main&target=../index.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 text-white border border-white/20 transition hidden sm:inline-flex">
                    <i class="fas fa-home mr-1"></i> Main App
                </a>
            </div>
        </nav>

        <!-- Main Body -->
        <main class="p-4 sm:p-6 lg:p-8 flex-1">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-[#7a4e0a]">Non-GST Accounts Ledger</h2>
                    <p class="text-xs sm:text-sm text-stone-600 mt-1">Daily multi-channel collections breakdown for <?php echo $monthLabel; ?>.</p>
                </div>

                <div class="flex items-center gap-2 flex-wrap no-print">
                    <button onclick="window.print()" class="px-4 py-2 bg-stone-100 hover:bg-stone-200 text-stone-800 font-bold rounded-xl text-xs border border-stone-300 transition inline-flex items-center gap-1.5">
                        <i class="fas fa-print"></i> Print Daybook
                    </button>
                    <a href="due_list.php" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-xl text-xs shadow transition inline-flex items-center gap-1.5">
                        <i class="fas fa-file-invoice-dollar"></i> Due Collections
                    </a>
                </div>
            </div>

            <!-- Month Navigator -->
            <div class="card-gold p-4 mb-6 no-print">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-2">
                        <a href="accounts.php?month=<?php echo $prevMonth; ?>" class="px-3 py-2 bg-stone-100 hover:bg-stone-200 text-stone-800 font-bold rounded-lg transition inline-flex items-center gap-1">
                            <i class="fas fa-chevron-left"></i> <span><?php echo date('M Y', strtotime($prevMonth.'-01')); ?></span>
                        </a>
                        <span class="px-4 py-2 bg-amber-50 text-amber-900 border border-amber-300 rounded-lg font-extrabold text-sm">
                            <i class="fas fa-calendar-alt text-amber-600 mr-1.5"></i> <?php echo $monthLabel; ?>
                        </span>
                        <a href="accounts.php?month=<?php echo $nextMonth; ?>" class="px-3 py-2 bg-stone-100 hover:bg-stone-200 text-stone-800 font-bold rounded-lg transition inline-flex items-center gap-1">
                            <span><?php echo date('M Y', strtotime($nextMonth.'-01')); ?></span> <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>

                    <form method="GET" action="accounts.php" class="flex items-center gap-2">
                        <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" class="px-3 py-1.5 bg-stone-50 border border-stone-300 rounded-lg text-stone-800 font-bold">
                        <button type="submit" class="btn-gold-action">
                            Go
                        </button>
                        <a href="accounts.php" class="px-3 py-2 bg-stone-100 hover:bg-stone-200 text-stone-600 rounded-lg font-bold">
                            Current Month
                        </a>
                    </form>
                </div>
            </div>

            <!-- Financial Metrics Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <div class="card-gold p-4 border-l-4 border-amber-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Total Collected</span>
                    <div class="text-xl font-extrabold text-[#7a4e0a] mt-1">₹<?php echo number_format($totAll, 2); ?></div>
                    <div class="text-[10px] text-stone-400 mt-0.5"><?php echo $totBills; ?> cash memos</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-emerald-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Cash Collected</span>
                    <div class="text-xl font-extrabold text-emerald-700 mt-1">₹<?php echo number_format($totCash, 2); ?></div>
                    <div class="text-[10px] text-emerald-600 font-bold mt-0.5"><?php echo $totAll > 0 ? round(($totCash/$totAll)*100).'%' : '0%'; ?> of total</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-blue-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">UPI / Online / Bank</span>
                    <div class="text-xl font-extrabold text-blue-700 mt-1">₹<?php echo number_format($totUpi, 2); ?></div>
                    <div class="text-[10px] text-blue-600 font-bold mt-0.5"><?php echo $totAll > 0 ? round(($totUpi/$totAll)*100).'%' : '0%'; ?> of total</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-indigo-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Cheque Received</span>
                    <div class="text-xl font-extrabold text-indigo-700 mt-1">₹<?php echo number_format($totCheque, 2); ?></div>
                    <div class="text-[10px] text-stone-400 mt-0.5">Bank Clearance</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-purple-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Old Gold Adjusted</span>
                    <div class="text-xl font-extrabold text-purple-700 mt-1">₹<?php echo number_format($totOldGold, 2); ?></div>
                    <div class="text-[10px] text-stone-400 mt-0.5">Value Exchanged</div>
                </div>

                <div class="card-gold p-4 border-l-4 border-teal-600">
                    <span class="text-[11px] font-bold text-stone-500 uppercase">Due Recoveries</span>
                    <div class="text-xl font-extrabold text-teal-700 mt-1">₹<?php echo number_format($totDueCols, 2); ?></div>
                    <div class="text-[10px] text-teal-600 font-bold mt-0.5">Past Memos Cleared</div>
                </div>
            </div>

            <!-- Daily Ledger Table -->
            <div class="card-gold overflow-hidden">
                <div class="p-4 border-b border-stone-200 bg-amber-50/50 flex items-center justify-between">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-[#7a4e0a]">Daily Daybook Ledger &mdash; <?php echo $monthLabel; ?></h3>
                    <span class="text-xs text-stone-500">Click any day to inspect individual entries</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-[#f7eed7] border-b border-stone-200 text-stone-700 uppercase font-bold text-[11px]">
                                <th class="py-3 px-3">Date</th>
                                <th class="py-3 px-3 text-center">Cash Memos</th>
                                <th class="py-3 px-3 text-right">Cash (₹)</th>
                                <th class="py-3 px-3 text-right">UPI / Online (₹)</th>
                                <th class="py-3 px-3 text-right">Cheque (₹)</th>
                                <th class="py-3 px-3 text-right">Old Gold (₹)</th>
                                <th class="py-3 px-3 text-right">Due Recovered (₹)</th>
                                <th class="py-3 px-3 text-right font-black text-amber-900 bg-amber-100/50">Total Inflow (₹)</th>
                                <th class="py-3 px-3 text-center no-print">Drilldown</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            <?php if(empty($days)): ?>
                            <tr><td colspan="9" class="text-center py-10 text-stone-400">No Non-GST payment activity recorded in <?php echo $monthLabel; ?>.</td></tr>
                            <?php else: foreach($days as $dateStr => $d): 
                                $dayKey = md5($dateStr);
                                $inflow = $d['total'];
                            ?>
                            <tr class="hover:bg-amber-50/50 transition">
                                <td class="py-3 px-3 font-bold text-stone-900">
                                    <div class="flex items-center gap-1.5">
                                        <i class="far fa-calendar text-amber-600"></i>
                                        <span><?php echo date('d M Y, D', strtotime($dateStr)); ?></span>
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-stone-100 text-stone-700">
                                        <?php echo $d['bills']; ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-right font-semibold text-emerald-700">₹<?php echo number_format($d['cash'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-semibold text-blue-700">₹<?php echo number_format($d['upi'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-semibold text-indigo-700">₹<?php echo number_format($d['cheque'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-semibold text-purple-700">₹<?php echo number_format($d['oldgold'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-semibold text-teal-700">₹<?php echo number_format($d['due_collections'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-extrabold text-[#7a4e0a] text-sm bg-amber-50/50">₹<?php echo number_format($inflow, 2); ?></td>
                                <td class="py-3 px-3 text-center no-print">
                                    <button onclick="toggleDayDrilldown('<?php echo $dayKey; ?>')" class="px-2.5 py-1 bg-stone-100 hover:bg-stone-200 text-stone-700 font-bold rounded-lg text-[10px] transition inline-flex items-center gap-1">
                                        <i class="fas fa-list text-[9px]"></i> View
                                    </button>
                                </td>
                            </tr>
                            <!-- Drilldown row -->
                            <tr id="drilldown-<?php echo $dayKey; ?>" class="hidden bg-stone-50/90 no-print">
                                <td colspan="9" class="p-3 border-t border-b border-stone-200">
                                    <div class="p-3 bg-white rounded-xl border border-stone-200 text-xs">
                                        <div class="font-bold text-[#7a4e0a] mb-2 uppercase text-[10px]">
                                            Transactions on <?php echo date('d F Y', strtotime($dateStr)); ?>
                                        </div>
                                        <?php if(empty($day_invoices[$dateStr])): ?>
                                            <p class="text-stone-400">No individual memos recorded.</p>
                                        <?php else: ?>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                                                <?php foreach($day_invoices[$dateStr] as $tx): ?>
                                                <div class="p-2.5 bg-stone-50 rounded-lg border border-stone-200 flex items-center justify-between">
                                                    <div>
                                                        <div class="font-bold text-stone-800"><?php echo htmlspecialchars($tx['invoice_no']); ?></div>
                                                        <div class="text-[10px] text-stone-500"><?php echo htmlspecialchars($tx['customer_name']); ?> &bull; <span class="font-semibold text-amber-800"><?php echo htmlspecialchars($tx['mode']); ?></span></div>
                                                    </div>
                                                    <div class="font-extrabold text-emerald-700 text-sm">
                                                        ₹<?php echo number_format($tx['amount'], 2); ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- Total Footer Row -->
                            <tr class="bg-amber-100/60 font-extrabold text-stone-900 border-t-2 border-stone-300">
                                <td class="py-3 px-3 uppercase text-[11px] text-[#7a4e0a]">Monthly Grand Total</td>
                                <td class="py-3 px-3 text-center"><?php echo $totBills; ?> Memos</td>
                                <td class="py-3 px-3 text-right text-emerald-800">₹<?php echo number_format($totCash, 2); ?></td>
                                <td class="py-3 px-3 text-right text-blue-800">₹<?php echo number_format($totUpi, 2); ?></td>
                                <td class="py-3 px-3 text-right text-indigo-800">₹<?php echo number_format($totCheque, 2); ?></td>
                                <td class="py-3 px-3 text-right text-purple-800">₹<?php echo number_format($totOldGold, 2); ?></td>
                                <td class="py-3 px-3 text-right text-teal-800">₹<?php echo number_format($totDueCols, 2); ?></td>
                                <td class="py-3 px-3 text-right text-base text-[#7a4e0a] font-black bg-amber-200/60">₹<?php echo number_format($totAll, 2); ?></td>
                                <td class="py-3 px-3 no-print"></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script>
        function toggleDayDrilldown(dayKey) {
            const row = document.getElementById('drilldown-' + dayKey);
            if (row) {
                row.classList.toggle('hidden');
            }
        }
    </script>
</body>
</html>
