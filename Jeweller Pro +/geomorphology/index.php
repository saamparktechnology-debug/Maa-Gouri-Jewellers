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

// 1. Daily sales (last 7 days)
$daily_sales = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $sales_query = mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)) AND DATE(created_at) = '$date'");
    $sales = mysqli_fetch_assoc($sales_query);
    $daily_sales[] = [
        'date' => date('d M', strtotime($date)),
        'total' => floatval($sales['total'] ?? 0)
    ];
}

// 2. Monthly sales (last 6 months)
$monthly_sales = [];
for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-$i months"));
    $month_end   = date('Y-m-t', strtotime("-$i months"));
    $month_query = mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)) AND DATE(created_at) BETWEEN '$month_start' AND '$month_end'");
    $m_sales = mysqli_fetch_assoc($month_query);
    $monthly_sales[] = [
        'month' => date('M Y', strtotime($month_start)),
        'total' => floatval($m_sales['total'] ?? 0)
    ];
}

// 3. Top Summary Totals
$today = date('Y-m-d');
$today_sales_data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total, COUNT(*) as cnt FROM invoices WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)) AND DATE(created_at) = '$today'"));
$today_sales = floatval($today_sales_data['total'] ?? 0);
$today_count = intval($today_sales_data['cnt'] ?? 0);

$all_sales_data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total, COUNT(*) as cnt, COALESCE(SUM(balance_amount), 0) as total_due FROM invoices WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0))"));
$all_sales = floatval($all_sales_data['total'] ?? 0);
$all_count = intval($all_sales_data['cnt'] ?? 0);
$all_due   = floatval($all_sales_data['total_due'] ?? 0);

$today_due_recovered = floatval(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(h.amount_paid), 0) as total 
    FROM due_update_history h 
    JOIN invoices i ON h.invoice_id = i.id 
    WHERE (i.gst_type NOT LIKE 'gst%' AND (i.gst_amount IS NULL OR i.gst_amount = 0)) 
      AND DATE(h.payment_date) = '$today'
"))['total'] ?? 0);

// 4. Payment Modes Total
$modes_data = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT 
        COALESCE(SUM(cash_paid), 0) as cash_tot,
        COALESCE(SUM(upi_paid), 0) as upi_tot,
        COALESCE(SUM(cheque_paid), 0) as cheque_tot,
        COALESCE(SUM(old_gold_value), 0) as gold_tot
    FROM invoices 
    WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0))
"));
$cash_total   = floatval($modes_data['cash_tot'] ?? 0);
$upi_total    = floatval($modes_data['upi_tot'] ?? 0);
$cheque_total = floatval($modes_data['cheque_tot'] ?? 0);
$gold_total   = floatval($modes_data['gold_tot'] ?? 0);

// 5. Recent 8 Invoices
$recent_invoices = mysqli_query($conn, "
    SELECT id, invoice_no, customer_name, customer_mobile, total_amount, paid_amount, balance_amount, payment_status, created_at 
    FROM invoices 
    WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)) 
    ORDER BY created_at DESC 
    LIMIT 8
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Geomorphology Dashboard - MAA GOURI JEWELLERS</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="shortcut icon" type="image/png" href="../logo.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            <a href="/geomorphology" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="/geomorphology/reports"><i class="fas fa-chart-bar"></i> Sales Reports</a>
            <a href="/geomorphology/accounts"><i class="fas fa-wallet"></i> Accounts Ledger</a>
            <a href="/geomorphology/due_list"><i class="fas fa-file-invoice-dollar"></i> Due Management</a>
            <a href="/geomorphology/income_expenses"><i class="fas fa-exchange-alt"></i> Income &amp; Expenses</a>

            <div class="sidebar-section-label mt-4">Security</div>
            <a href="/geomorphology?geo_action=lock" class="text-rose-300 hover:text-rose-100"><i class="fas fa-lock text-rose-400"></i> Lock Section</a>
            <button type="button" onclick="openChangePassModal()" class="w-full text-left flex items-center gap-3 px-5 py-2.5 text-xs text-amber-300 hover:text-white transition">
                <i class="fas fa-key text-amber-400"></i> Change Password
            </button>

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
                    <h1 class="text-base sm:text-lg font-bold text-white tracking-wide">GEOMORPHOLOGY DASHBOARD</h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-stone-950 shadow-sm">
                        PROTECTED MODE
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" onclick="openChangePassModal()" title="Change section password" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 text-white border border-white/20 transition hidden sm:inline-flex items-center gap-1">
                    <i class="fas fa-key text-amber-300"></i> Change Pass
                </button>
                <a href="/geomorphology?geo_action=lock" title="Lock section" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-rose-600/80 hover:bg-rose-600 text-white transition inline-flex items-center gap-1">
                    <i class="fas fa-lock"></i> <span class="hidden sm:inline">Lock</span>
                </a>
                <a href="/geomorphology?geo_action=exit_to_main&target=/index.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 text-white border border-white/20 transition hidden sm:inline-flex">
                    <i class="fas fa-home mr-1"></i> Main App
                </a>
            </div>
        </nav>

        <!-- Main Body -->
        <main class="p-4 sm:p-6 lg:p-8 flex-1">

            <!-- Welcome Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-[#7a4e0a]">Geomorphology Overview</h2>
                    <p class="text-xs sm:text-sm text-stone-600 mt-1">Private Cash Memo &amp; Non-GST Sales Financial Management.</p>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <a href="/geomorphology?geo_action=exit_to_main&target=/billing" class="btn-gold-action">
                        <i class="fas fa-cash-register"></i> New Bill
                    </a>
                    <a href="/geomorphology/reports" class="px-4 py-2 bg-stone-100 hover:bg-stone-200 text-stone-800 font-bold rounded-xl text-xs border border-stone-300 transition inline-flex items-center gap-1.5">
                        <i class="fas fa-chart-line"></i> Sales Reports
                    </a>
                </div>
            </div>

            <!-- Metric Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-gold p-5 border-l-4 border-amber-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Today's Cash Memos</span>
                    <div class="text-2xl font-extrabold text-[#7a4e0a] mt-1">₹<?php echo number_format($today_sales, 2); ?></div>
                    <div class="text-xs text-stone-500 mt-1 font-semibold"><?php echo $today_count; ?> memos created today</div>
                </div>

                <div class="card-gold p-5 border-l-4 border-blue-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Total Non-GST Sales</span>
                    <div class="text-2xl font-extrabold text-blue-900 mt-1">₹<?php echo number_format($all_sales, 2); ?></div>
                    <div class="text-xs text-stone-500 mt-1 font-semibold"><?php echo $all_count; ?> total cash memos</div>
                </div>

                <div class="card-gold p-5 border-l-4 border-rose-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Total Pending Dues</span>
                    <div class="text-2xl font-extrabold text-rose-600 mt-1">₹<?php echo number_format($all_due, 2); ?></div>
                    <div class="text-xs text-rose-600 mt-1 font-semibold">Outstanding balance</div>
                </div>

                <div class="card-gold p-5 border-l-4 border-emerald-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Today's Due Recoveries</span>
                    <div class="text-2xl font-extrabold text-emerald-700 mt-1">₹<?php echo number_format($today_due_recovered, 2); ?></div>
                    <div class="text-xs text-emerald-600 mt-1 font-semibold">Cleared today</div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                
                <!-- 7-Day Trend -->
                <div class="card-gold p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-bold text-sm text-[#7a4e0a] uppercase tracking-wider flex items-center gap-2">
                            <i class="fas fa-chart-bar text-amber-600"></i> Last 7 Days (₹)
                        </h3>
                    </div>
                    <div class="h-64">
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>

                <!-- 6-Month Trend -->
                <div class="card-gold p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-bold text-sm text-[#7a4e0a] uppercase tracking-wider flex items-center gap-2">
                            <i class="fas fa-chart-line text-blue-600"></i> 6-Month Trend (₹)
                        </h3>
                    </div>
                    <div class="h-64">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>

                <!-- Payment Modes Distribution -->
                <div class="card-gold p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-bold text-sm text-[#7a4e0a] uppercase tracking-wider flex items-center gap-2">
                            <i class="fas fa-pie-chart text-emerald-600"></i> Payment Channels
                        </h3>
                    </div>
                    <div class="h-64">
                        <canvas id="modeChart"></canvas>
                    </div>
                </div>

            </div>


            <!-- Recent Invoices Table -->
            <div class="card-gold overflow-hidden mb-6">
                <div class="p-4 border-b border-stone-200 bg-amber-50/50 flex items-center justify-between">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-[#7a4e0a]">Recent Non-GST Cash Memos</h3>
                    <a href="/geomorphology/reports" class="text-xs font-bold text-[#7a4e0a] hover:underline flex items-center gap-1">
                        View All Reports <i class="fas fa-arrow-right text-[10px]"></i>
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-[#f7eed7] border-b border-stone-200 text-stone-700 uppercase font-bold text-[11px]">
                                <th class="py-3 px-3">Memo No</th>
                                <th class="py-3 px-3">Customer Details</th>
                                <th class="py-3 px-3 text-right">Total Amount</th>
                                <th class="py-3 px-3 text-right">Paid Amount</th>
                                <th class="py-3 px-3 text-right">Balance Due</th>
                                <th class="py-3 px-3 text-center">Status</th>
                                <th class="py-3 px-3 text-center">Receipt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            <?php if(mysqli_num_rows($recent_invoices) == 0): ?>
                            <tr><td colspan="7" class="text-center py-8 text-stone-400">No Non-GST invoices recorded yet.</td></tr>
                            <?php else: while($r = mysqli_fetch_assoc($recent_invoices)): ?>
                            <tr class="hover:bg-amber-50/50 transition">
                                <td class="py-3 px-3 font-bold text-[#7a4e0a]">
                                    <?php echo htmlspecialchars($r['invoice_no']); ?>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="font-bold text-stone-900"><?php echo htmlspecialchars($r['customer_name'] ?: 'Cash Customer'); ?></div>
                                    <div class="text-[10px] text-stone-500">Ph: <?php echo htmlspecialchars($r['customer_mobile'] ?: 'N/A'); ?></div>
                                </td>
                                <td class="py-3 px-3 text-right font-extrabold text-stone-900">₹<?php echo number_format($r['total_amount'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-bold text-emerald-700">₹<?php echo number_format($r['paid_amount'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-bold <?php echo $r['balance_amount'] > 0 ? 'text-rose-600' : 'text-stone-400'; ?>">
                                    ₹<?php echo number_format($r['balance_amount'], 2); ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <?php if($r['payment_status'] === 'paid'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-100 text-emerald-800">Paid</span>
                                    <?php elseif($r['payment_status'] === 'part'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-800">Part Due</span>
                                    <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-100 text-rose-800">Unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <a href="/view_pdf.php?invoice_no=<?php echo urlencode($r['invoice_no']); ?>" target="_blank" class="px-2.5 py-1 bg-[#011921] hover:bg-[#03373b] text-[#ffd700] font-bold rounded-lg text-xs transition inline-flex items-center gap-1">
                                        <i class="fas fa-print text-[10px]"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div id="changePassModal" class="fixed inset-0 bg-black/60 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl border border-stone-200 text-xs">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-stone-200">
                <h3 class="font-bold text-base text-stone-900 flex items-center gap-2">
                    <i class="fas fa-key text-amber-600"></i> Change Section Password
                </h3>
                <button onclick="closeChangePassModal()" class="text-stone-400 hover:text-stone-600 text-lg">&times;</button>
            </div>
            
            <div id="cpAlert" class="hidden p-3 rounded-xl border mb-3 font-semibold"></div>

            <form id="changePassForm" onsubmit="handleChangePassword(event)" class="space-y-3.5">
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Current Password *</label>
                    <input type="password" id="cpCurrentPass" required class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">New Password *</label>
                    <input type="password" id="cpNewPass" required minlength="4" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Confirm New Password *</label>
                    <input type="password" id="cpConfirmPass" required minlength="4" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg">
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="closeChangePassModal()" class="flex-1 py-2.5 bg-stone-100 hover:bg-stone-200 font-bold rounded-xl text-stone-700">Cancel</button>
                    <button type="submit" id="cpSubmitBtn" class="flex-1 py-2.5 btn-gold-action justify-center shadow-md">Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const dailyLabels = <?php echo json_encode(array_column($daily_sales, 'date')); ?>;
        const dailyData   = <?php echo json_encode(array_column($daily_sales, 'total')); ?>;

        new Chart(document.getElementById('dailyChart'), {
            type: 'bar',
            data: {
                labels: dailyLabels,
                datasets: [{
                    label: 'Sales (₹)',
                    data: dailyData,
                    backgroundColor: 'rgba(214, 139, 22, 0.75)',
                    borderColor: '#7a4e0a',
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });

        const monthlyLabels = <?php echo json_encode(array_column($monthly_sales, 'month')); ?>;
        const monthlyData   = <?php echo json_encode(array_column($monthly_sales, 'total')); ?>;

        new Chart(document.getElementById('monthlyChart'), {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Revenue (₹)',
                    data: monthlyData,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#1e40af',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });


        const modeLabels = ['Cash', 'UPI / Online', 'Cheque', 'Old Gold'];
        const modeData   = [<?php echo "$cash_total, $upi_total, $cheque_total, $gold_total"; ?>];

        new Chart(document.getElementById('modeChart'), {
            type: 'doughnut',
            data: {
                labels: modeLabels,
                datasets: [{
                    data: modeData,
                    backgroundColor: ['#10b981', '#3b82f6', '#6366f1', '#a855f7'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } }
                }
            }
        });

        function openChangePassModal() {
            document.getElementById('changePassModal').classList.remove('hidden');
        }
        function closeChangePassModal() {
            document.getElementById('changePassModal').classList.add('hidden');
        }

        async function handleChangePassword(e) {
            e.preventDefault();
            const curr = document.getElementById('cpCurrentPass').value;
            const newP = document.getElementById('cpNewPass').value;
            const conf = document.getElementById('cpConfirmPass').value;
            const alertEl = document.getElementById('cpAlert');
            const btn = document.getElementById('cpSubmitBtn');

            if (newP !== conf) {
                alertEl.className = 'p-3 rounded-xl border mb-3 font-semibold bg-rose-100 border-rose-300 text-rose-800';
                alertEl.textContent = 'New password and confirm password do not match.';
                alertEl.classList.remove('hidden');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

            const fd = new FormData();
            fd.append('geo_ajax_action', 'change_password');
            fd.append('current_password', curr);
            fd.append('new_password', newP);
            fd.append('confirm_password', conf);

            try {
                const res = await fetch('/geomorphology', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    alertEl.className = 'p-3 rounded-xl border mb-3 font-semibold bg-emerald-100 border-emerald-300 text-emerald-800';
                    alertEl.textContent = data.message;
                    alertEl.classList.remove('hidden');
                    setTimeout(() => { closeChangePassModal(); location.reload(); }, 1000);
                } else {
                    alertEl.className = 'p-3 rounded-xl border mb-3 font-semibold bg-rose-100 border-rose-300 text-rose-800';
                    alertEl.textContent = data.message;
                    alertEl.classList.remove('hidden');
                    btn.disabled = false;
                    btn.innerHTML = 'Update Password';
                }
            } catch (err) {
                alertEl.className = 'p-3 rounded-xl border mb-3 font-semibold bg-rose-100 border-rose-300 text-rose-800';
                alertEl.textContent = 'Error communicating with server.';
                alertEl.classList.remove('hidden');
                btn.disabled = false;
                btn.innerHTML = 'Update Password';
            }
        }
    </script>
</body>
</html>
