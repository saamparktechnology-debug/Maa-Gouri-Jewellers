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

// Handle Add Income
$msg_income = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_income'])) {
    $income_date    = mysqli_real_escape_string($conn, $_POST['income_date']);
    $source         = mysqli_real_escape_string($conn, $_POST['source']);
    $category       = mysqli_real_escape_string($conn, $_POST['category']);
    $amount         = floatval($_POST['amount']);
    $description    = mysqli_real_escape_string($conn, $_POST['description']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $invoice_no     = mysqli_real_escape_string($conn, $_POST['invoice_no'] ?? '');
    $created_by     = intval($_SESSION['user_id']);
    
    if ($amount > 0) {
        if (mysqli_query($conn, "INSERT INTO income (income_date, source, category, amount, description, payment_method, invoice_no, created_by) VALUES ('$income_date', '$source', '$category', $amount, '$description', '$payment_method', '$invoice_no', $created_by)")) {
            $msg_income = "Income entry of ₹" . number_format($amount, 2) . " added successfully!";
        }
    }
}

// Handle Add Expense
$msg_expense = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_expense'])) {
    $expense_date   = mysqli_real_escape_string($conn, $_POST['expense_date']);
    $category       = mysqli_real_escape_string($conn, $_POST['category']);
    $amount         = floatval($_POST['amount']);
    $description    = mysqli_real_escape_string($conn, $_POST['description']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $bill_no        = mysqli_real_escape_string($conn, $_POST['bill_no'] ?? '');
    $vendor_name    = mysqli_real_escape_string($conn, $_POST['vendor_name'] ?? '');
    $created_by     = intval($_SESSION['user_id']);
    
    if ($amount > 0) {
        if (mysqli_query($conn, "INSERT INTO expenses (expense_date, category, amount, description, payment_method, bill_no, vendor_name, created_by) VALUES ('$expense_date', '$category', $amount, '$description', '$payment_method', '$bill_no', '$vendor_name', $created_by)")) {
            $msg_expense = "Expense entry of ₹" . number_format($amount, 2) . " recorded successfully!";
        }
    }
}

// Handle Delete Income / Expense
if (isset($_GET['delete_income'])) {
    $id = intval($_GET['delete_income']);
    mysqli_query($conn, "DELETE FROM income WHERE id = $id");
    header("Location: income_expenses.php");
    exit();
}
if (isset($_GET['delete_expense'])) {
    $id = intval($_GET['delete_expense']);
    mysqli_query($conn, "DELETE FROM expenses WHERE id = $id");
    header("Location: income_expenses.php");
    exit();
}

$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '2026-04-01';
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');


$sales_data = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(total_amount),0) AS total_sales, COUNT(*) AS sales_count 
    FROM invoices 
    WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)) 
      AND DATE(created_at) BETWEEN '$date_from' AND '$date_to'
"));
$total_sales = floatval($sales_data['total_sales']);
$total_sales_count = intval($sales_data['sales_count']);

$income_result = mysqli_query($conn, "SELECT * FROM income WHERE income_date BETWEEN '$date_from' AND '$date_to' ORDER BY income_date DESC");
$total_income = 0; $income_records = [];
if($income_result) {
    while($row = mysqli_fetch_assoc($income_result)) { $total_income += $row['amount']; $income_records[] = $row; }
}

$expense_result = mysqli_query($conn, "SELECT * FROM expenses WHERE expense_date BETWEEN '$date_from' AND '$date_to' ORDER BY expense_date DESC");
$total_expense = 0; $expense_records = [];
if($expense_result) {
    while($row = mysqli_fetch_assoc($expense_result)) { $total_expense += $row['amount']; $expense_records[] = $row; }
}

$net_profit = $total_sales + $total_income - $total_expense;

// Chart data
$expense_by_cat = []; 
foreach($expense_records as $r) { 
    $cat = !empty($r['category']) ? $r['category'] : 'General';
    $expense_by_cat[$cat] = ($expense_by_cat[$cat] ?? 0) + $r['amount']; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Geomorphology Income &amp; Expenses - MAA GOURI JEWELLERS</title>
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
            <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="reports.php"><i class="fas fa-chart-bar"></i> Sales Reports</a>
            <a href="accounts.php"><i class="fas fa-wallet"></i> Accounts Ledger</a>
            <a href="due_list.php"><i class="fas fa-file-invoice-dollar"></i> Due Management</a>
            <a href="income_expenses.php" class="active"><i class="fas fa-exchange-alt"></i> Income &amp; Expenses</a>

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
                    <h1 class="text-base sm:text-lg font-bold text-white tracking-wide">GEOMORPHOLOGY INCOME &amp; EXPENSES</h1>
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

            <?php if(!empty($msg_income) || !empty($msg_expense)): ?>
            <div class="mb-4 p-3 bg-emerald-100 border border-emerald-300 text-emerald-800 rounded-xl text-xs font-bold flex items-center justify-between">
                <span><i class="fas fa-check-circle mr-1"></i> <?php echo htmlspecialchars($msg_income ?: $msg_expense); ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-700">&times;</button>
            </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-[#7a4e0a]">Income &amp; Expenses Balance</h2>
                    <p class="text-xs sm:text-sm text-stone-600 mt-1">Compare private cash memo sales against operational shop expenses.</p>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <button onclick="document.getElementById('incomeModal').classList.remove('hidden')" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs shadow transition inline-flex items-center gap-1.5">
                        <i class="fas fa-plus-circle"></i> Add Income
                    </button>
                    <button onclick="document.getElementById('expenseModal').classList.remove('hidden')" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl text-xs shadow transition inline-flex items-center gap-1.5">
                        <i class="fas fa-minus-circle"></i> Add Expense
                    </button>
                </div>
            </div>

            <!-- Date Filter -->
            <div class="card-gold p-4 mb-6">
                <form method="GET" action="income_expenses.php" class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <span class="font-bold text-stone-600">Date Range:</span>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg text-stone-800 font-semibold">
                        <span class="text-stone-400 font-bold">to</span>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg text-stone-800 font-semibold">
                        <button type="submit" class="btn-gold-action">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>
                    <div class="text-xs text-stone-500 font-bold">
                        Showing data from <span class="text-[#7a4e0a]"><?php echo date('d M Y', strtotime($date_from)); ?></span> to <span class="text-[#7a4e0a]"><?php echo date('d M Y', strtotime($date_to)); ?></span>
                    </div>
                </form>
            </div>

            <!-- Metrics -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-gold p-5 border-l-4 border-amber-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Non-GST Sales Revenue</span>
                    <div class="text-2xl font-extrabold text-[#7a4e0a] mt-1">₹<?php echo number_format($total_sales, 2); ?></div>
                    <div class="text-xs text-stone-500 mt-1 font-semibold"><?php echo $total_sales_count; ?> cash memos in range</div>
                </div>

                <div class="card-gold p-5 border-l-4 border-teal-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Other Incomes</span>
                    <div class="text-2xl font-extrabold text-teal-700 mt-1">₹<?php echo number_format($total_income, 2); ?></div>
                    <div class="text-xs text-stone-500 mt-1 font-semibold"><?php echo count($income_records); ?> miscellaneous income entries</div>
                </div>

                <div class="card-gold p-5 border-l-4 border-rose-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Total Expenses</span>
                    <div class="text-2xl font-extrabold text-rose-600 mt-1">₹<?php echo number_format($total_expense, 2); ?></div>
                    <div class="text-xs text-stone-500 mt-1 font-semibold"><?php echo count($expense_records); ?> expense records</div>
                </div>

                <div class="card-gold p-5 border-l-4 border-emerald-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Calculated Net Margin</span>
                    <div class="text-2xl font-extrabold <?php echo $net_profit >= 0 ? 'text-emerald-700' : 'text-rose-600'; ?> mt-1">
                        ₹<?php echo number_format($net_profit, 2); ?>
                    </div>
                    <div class="text-xs text-stone-500 mt-1 font-semibold">Sales + Income - Expenses</div>
                </div>
            </div>

            <!-- Ledger Tables Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                
                <!-- Expenses Ledger -->
                <div class="card-gold overflow-hidden flex flex-col">
                    <div class="p-4 border-b border-stone-200 bg-rose-50/60 flex items-center justify-between">
                        <h3 class="font-bold text-xs uppercase tracking-wider text-rose-900 flex items-center gap-2">
                            <i class="fas fa-minus-circle text-rose-600"></i> Expenses List (₹<?php echo number_format($total_expense, 2); ?>)
                        </h3>
                        <button onclick="document.getElementById('expenseModal').classList.remove('hidden')" class="text-xs text-rose-700 font-bold hover:underline">
                            + Add New
                        </button>
                    </div>
                    <div class="overflow-x-auto flex-1 max-h-96">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="bg-stone-100 border-b border-stone-200 text-stone-700 uppercase font-bold text-[10px]">
                                    <th class="py-2.5 px-3">Date</th>
                                    <th class="py-2.5 px-3">Category</th>
                                    <th class="py-2.5 px-3">Description</th>
                                    <th class="py-2.5 px-3 text-right">Amount</th>
                                    <th class="py-2.5 px-3 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                <?php if(empty($expense_records)): ?>
                                <tr><td colspan="5" class="text-center py-8 text-stone-400">No expense entries found in selected date range.</td></tr>
                                <?php else: foreach($expense_records as $e): ?>
                                <tr class="hover:bg-rose-50/20 transition">
                                    <td class="py-2.5 px-3 text-stone-600 font-medium"><?php echo date('d/m/Y', strtotime($e['expense_date'])); ?></td>
                                    <td class="py-2.5 px-3 font-bold text-rose-800"><?php echo htmlspecialchars($e['category'] ?: 'Expense'); ?></td>
                                    <td class="py-2.5 px-3 text-stone-600"><?php echo htmlspecialchars($e['description'] ?: ($e['vendor_name'] ?? '-')); ?></td>
                                    <td class="py-2.5 px-3 text-right font-extrabold text-rose-600">₹<?php echo number_format($e['amount'], 2); ?></td>
                                    <td class="py-2.5 px-3 text-center">
                                        <a href="income_expenses.php?delete_expense=<?php echo $e['id']; ?>" onclick="return confirm('Delete this expense?')" class="text-stone-400 hover:text-rose-600">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Income Ledger -->
                <div class="card-gold overflow-hidden flex flex-col">
                    <div class="p-4 border-b border-stone-200 bg-emerald-50/60 flex items-center justify-between">
                        <h3 class="font-bold text-xs uppercase tracking-wider text-emerald-900 flex items-center gap-2">
                            <i class="fas fa-plus-circle text-emerald-600"></i> Miscellaneous Incomes (₹<?php echo number_format($total_income, 2); ?>)
                        </h3>
                        <button onclick="document.getElementById('incomeModal').classList.remove('hidden')" class="text-xs text-emerald-700 font-bold hover:underline">
                            + Add New
                        </button>
                    </div>
                    <div class="overflow-x-auto flex-1 max-h-96">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="bg-stone-100 border-b border-stone-200 text-stone-700 uppercase font-bold text-[10px]">
                                    <th class="py-2.5 px-3">Date</th>
                                    <th class="py-2.5 px-3">Category</th>
                                    <th class="py-2.5 px-3">Description / Source</th>
                                    <th class="py-2.5 px-3 text-right">Amount</th>
                                    <th class="py-2.5 px-3 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                <?php if(empty($income_records)): ?>
                                <tr><td colspan="5" class="text-center py-8 text-stone-400">No other income entries found in selected date range.</td></tr>
                                <?php else: foreach($income_records as $inc): ?>
                                <tr class="hover:bg-emerald-50/20 transition">
                                    <td class="py-2.5 px-3 text-stone-600 font-medium"><?php echo date('d/m/Y', strtotime($inc['income_date'])); ?></td>
                                    <td class="py-2.5 px-3 font-bold text-emerald-800"><?php echo htmlspecialchars($inc['category'] ?: ($inc['source'] ?? 'Income')); ?></td>
                                    <td class="py-2.5 px-3 text-stone-600"><?php echo htmlspecialchars($inc['description'] ?: '-'); ?></td>
                                    <td class="py-2.5 px-3 text-right font-extrabold text-emerald-700">₹<?php echo number_format($inc['amount'], 2); ?></td>
                                    <td class="py-2.5 px-3 text-center">
                                        <a href="income_expenses.php?delete_income=<?php echo $inc['id']; ?>" onclick="return confirm('Delete this income entry?')" class="text-stone-400 hover:text-rose-600">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </main>
    </div>

    <!-- Add Expense Modal -->
    <div id="expenseModal" class="fixed inset-0 bg-black/60 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl border border-stone-200 text-xs">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-stone-200">
                <h3 class="font-bold text-base text-rose-700 flex items-center gap-2">
                    <i class="fas fa-minus-circle"></i> Add Expense Voucher
                </h3>
                <button onclick="document.getElementById('expenseModal').classList.add('hidden')" class="text-stone-400 hover:text-stone-600 text-lg">&times;</button>
            </div>
            
            <form method="POST" action="income_expenses.php" class="space-y-3.5">
                <input type="hidden" name="add_expense" value="1">
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Expense Date *</label>
                    <input type="date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Category *</label>
                    <input type="text" name="category" required placeholder="e.g. Shop Rent, Electricity, Tea/Snacks, Staff Salary" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Amount (₹) *</label>
                    <input type="number" step="0.01" min="1" name="amount" required placeholder="0.00" class="w-full px-3 py-2 text-sm font-bold text-rose-700 bg-stone-50 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Payment Method</label>
                    <select name="payment_method" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg font-semibold">
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI / Online</option>
                        <option value="Bank">Bank Transfer</option>
                        <option value="Cheque">Cheque</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Vendor / Description</label>
                    <input type="text" name="description" placeholder="Vendor name or purpose description" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg">
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('expenseModal').classList.add('hidden')" class="flex-1 py-2.5 bg-stone-100 hover:bg-stone-200 font-bold rounded-xl text-stone-700">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl shadow-md">Save Expense</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Income Modal -->
    <div id="incomeModal" class="fixed inset-0 bg-black/60 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl border border-stone-200 text-xs">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-stone-200">
                <h3 class="font-bold text-base text-emerald-700 flex items-center gap-2">
                    <i class="fas fa-plus-circle"></i> Add Miscellaneous Income
                </h3>
                <button onclick="document.getElementById('incomeModal').classList.add('hidden')" class="text-stone-400 hover:text-stone-600 text-lg">&times;</button>
            </div>
            
            <form method="POST" action="income_expenses.php" class="space-y-3.5">
                <input type="hidden" name="add_income" value="1">
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Income Date *</label>
                    <input type="date" name="income_date" required value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Source / Category *</label>
                    <input type="text" name="category" required placeholder="e.g. Making Charges, Repairing, Polish, Scrap" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg">
                    <input type="hidden" name="source" value="Other">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Amount (₹) *</label>
                    <input type="number" step="0.01" min="1" name="amount" required placeholder="0.00" class="w-full px-3 py-2 text-sm font-bold text-emerald-700 bg-stone-50 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Payment Method</label>
                    <select name="payment_method" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg font-semibold">
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI / Online</option>
                        <option value="Bank">Bank Transfer</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Description / Notes</label>
                    <input type="text" name="description" placeholder="Optional notes" class="w-full px-3 py-2 bg-stone-50 border border-stone-300 rounded-lg">
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('incomeModal').classList.add('hidden')" class="flex-1 py-2.5 bg-stone-100 hover:bg-stone-200 font-bold rounded-xl text-stone-700">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow-md">Save Income</button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>
