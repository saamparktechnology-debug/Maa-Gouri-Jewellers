<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/company_config.php';
require_once __DIR__ . '/../config/mail_config.php';
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

// Ensure due_update_history exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS due_update_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    previous_balance DECIMAL(10,2) NOT NULL,
    new_balance DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_mode VARCHAR(50) DEFAULT 'Cash',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// AJAX: Receive Due Payment for Non-GST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'receive_due_payment') {
    header('Content-Type: application/json');
    $id          = intval($_POST['id'] ?? 0);
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $mode        = mysqli_real_escape_string($conn, trim($_POST['payment_mode'] ?? 'Cash'));

    if ($id <= 0 || $amount_paid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment amount or invoice ID.']);
        exit();
    }

    $current = mysqli_fetch_assoc(mysqli_query($conn, "SELECT invoice_no, balance_amount, total_amount, paid_amount, gst_type, gst_amount FROM invoices WHERE id = $id LIMIT 1"));
    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
        exit();
    }

    $invoice_no_val   = $current['invoice_no'] ?? '';
    $previous_balance = floatval($current['balance_amount'] ?? 0);
    $current_paid     = floatval($current['paid_amount'] ?? 0);
    
    $new_balance  = max(0, $previous_balance - $amount_paid);
    $new_paid     = $current_paid + $amount_paid;
    $payment_date = date('Y-m-d');
    $payment_status = ($new_balance <= 0) ? 'paid' : 'part';

    $next_due_date = trim($_POST['next_due_date'] ?? '');
    $due_date_sql  = "";
    if ($new_balance <= 0) {
        $due_date_sql = ", due_date = NULL";
    } elseif (!empty($next_due_date)) {
        $safe_next_due = mysqli_real_escape_string($conn, $next_due_date);
        $due_date_sql  = ", due_date = '$safe_next_due'";
    }

    // Insert history
    mysqli_query($conn, "INSERT INTO due_update_history (invoice_id, previous_balance, new_balance, amount_paid, payment_date, payment_mode) VALUES ($id, $previous_balance, $new_balance, $amount_paid, '$payment_date', '$mode')");
    $history_id = mysqli_insert_id($conn);

    // Update invoice
    $upd = mysqli_query($conn, "UPDATE invoices SET balance_amount = $new_balance, paid_amount = $new_paid, payment_status = '$payment_status'$due_date_sql WHERE id = $id");

    echo json_encode([
        'success'          => (bool)$upd,
        'message'          => 'Payment of ₹' . number_format($amount_paid, 2) . ' received (' . $mode . ') successfully!',
        'id'               => $id,
        'invoice_no'       => $invoice_no_val,
        'previous_balance' => $previous_balance,
        'new_balance'      => $new_balance,
        'next_due_date'    => $next_due_date,
        'history_id'       => $history_id,
        'is_fully_paid'    => ($new_balance <= 0)
    ]);
    exit();
}

// AJAX: Send Reminder via Email
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ajax_send_reminder') {
    header('Content-Type: application/json');
    $customer_email  = trim($_POST['customer_email']  ?? '');
    $customer_name   = trim($_POST['customer_name']   ?? 'Customer');
    $customer_mobile = trim($_POST['customer_mobile'] ?? '');
    $invoice_no      = trim($_POST['invoice_no']      ?? '');
    $balance_amount  = floatval($_POST['balance_amount'] ?? 0);

    if (empty($customer_email) && !empty($customer_mobile)) {
        $safe_mobile = mysqli_real_escape_string($conn, $customer_mobile);
        $cust_res = mysqli_query($conn, "SELECT email FROM customers WHERE mobile = '$safe_mobile' LIMIT 1");
        if ($cust_res && mysqli_num_rows($cust_res) > 0) {
            $customer_email = trim(mysqli_fetch_assoc($cust_res)['email'] ?? '');
        }
    }

    if (empty($customer_email)) {
        echo json_encode(['success' => false, 'message' => 'Customer email not found. Please provide an email address.']);
        exit();
    }
    if ($balance_amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'No outstanding amount to remind.']);
        exit();
    }

    $subject = 'Payment Reminder: Cash Memo ' . $invoice_no . ' - ' . ($company_details['name'] ?? 'MAA GOURI JEWELLERS');
    $body    = '<div style="font-family:sans-serif;line-height:1.6;color:#333;padding:20px;background:#f9f9f9;border-radius:10px;">'
             . '<h2 style="color:#7a4e0a;margin-top:0;">' . htmlspecialchars($company_details['name'] ?? 'MAA GOURI JEWELLERS') . '</h2>'
             . '<p>Dear <strong>' . htmlspecialchars($customer_name) . '</strong>,</p>'
             . '<p>This is a gentle reminder regarding the outstanding balance on your cash memo <strong>' . htmlspecialchars($invoice_no) . '</strong>.</p>'
             . '<div style="background:#fff;padding:15px;border-left:4px solid #d97706;margin:15px 0;border-radius:6px;">'
             . '<span style="color:#78716c;font-size:12px;text-transform:uppercase;font-weight:bold;">Pending Due Amount:</span><br>'
             . '<strong style="font-size:24px;color:#dc2626;">₹' . number_format($balance_amount, 2) . '</strong>'
             . '</div>'
             . '<p>Kindly clear the pending payment at your earliest convenience.</p>'
             . '<p style="color:#78716c;font-size:12px;margin-top:20px;">Thank you for your business!<br>' . htmlspecialchars($company_details['name'] ?? 'MAA GOURI JEWELLERS') . '</p>'
             . '</div>';

    $res = sendSMTPMail($customer_email, $subject, $body);
    if (!empty($res['success'])) {
        echo json_encode(['success' => true, 'message' => 'Reminder email sent successfully to ' . $customer_email]);
    } else {
        echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Failed to send email.']);
    }
    exit();
}

// AJAX: Get Due Payment History
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ajax_history') {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    $res = mysqli_query($conn, "SELECT id as history_id, amount_paid, previous_balance, new_balance, payment_date, payment_mode FROM due_update_history WHERE invoice_id = $id ORDER BY payment_date DESC, id DESC");
    $hist = [];
    if ($res) {
        while($row = mysqli_fetch_assoc($res)) $hist[] = $row;
    }
    echo json_encode(['success' => true, 'history' => $hist]);
    exit();
}

// Main View Filter & Queries
$base_where = "WHERE (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0))";
$pending_count = intval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM invoices $base_where AND balance_amount > 0"))['cnt'] ?? 0);
$all_memos_count = intval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM invoices $base_where"))['cnt'] ?? 0);

$search_query = mysqli_real_escape_string($conn, trim($_GET['search'] ?? ''));
// If there are pending dues, default to 'pending'; otherwise default to 'all' so memos are visible
$default_status = ($pending_count > 0) ? 'pending' : 'all';
$filter_status = trim($_GET['status'] ?? $default_status);

$where_clauses = [
    "(gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0))"
];

$today = date('Y-m-d');
$week_end = date('Y-m-d', strtotime('+7 days'));

if ($filter_status === 'pending') {
    $where_clauses[] = "balance_amount > 0";
} elseif ($filter_status === 'overdue') {
    $where_clauses[] = "balance_amount > 0 AND due_date IS NOT NULL AND due_date != '0000-00-00' AND due_date < '$today'";
} elseif ($filter_status === 'due_today') {
    $where_clauses[] = "balance_amount > 0 AND due_date = '$today'";
} elseif ($filter_status === 'due_week') {
    $where_clauses[] = "balance_amount > 0 AND due_date BETWEEN '$today' AND '$week_end'";
} elseif ($filter_status === 'paid') {
    $where_clauses[] = "balance_amount <= 0";
}
// 'all' doesn't add balance filter

if ($search_query !== '') {
    $where_clauses[] = "(customer_name LIKE '%$search_query%' OR customer_mobile LIKE '%$search_query%' OR invoice_no LIKE '%$search_query%')";
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// Summary Metrics
$total_due_sum = floatval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(balance_amount), 0) as total FROM invoices $base_where AND balance_amount > 0"))['total'] ?? 0);
$total_due_customers = intval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM invoices $base_where AND balance_amount > 0"))['cnt'] ?? 0);

$overdue_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt, COALESCE(SUM(balance_amount),0) as amt FROM invoices $base_where AND balance_amount > 0 AND due_date IS NOT NULL AND due_date != '0000-00-00' AND due_date < '$today'"));
$overdue_count = intval($overdue_res['cnt'] ?? 0);
$overdue_sum = floatval($overdue_res['amt'] ?? 0);

// Today's recovered dues
$today_recovered = floatval(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(h.amount_paid), 0) as total 
    FROM due_update_history h 
    JOIN invoices i ON h.invoice_id = i.id 
    WHERE (i.gst_type NOT LIKE 'gst%' AND (i.gst_amount IS NULL OR i.gst_amount = 0)) 
      AND DATE(h.payment_date) = '$today'
"))['total'] ?? 0);

// Invoices Query
$rows = [];
$res = mysqli_query($conn, "
    SELECT id, invoice_no, customer_name, customer_mobile, customer_address, total_amount, paid_amount, balance_amount, due_date, created_at, payment_status 
    FROM invoices 
    $where_sql 
    ORDER BY CASE WHEN balance_amount > 0 THEN 0 ELSE 1 END, created_at DESC
");
if ($res) {
    while($r = mysqli_fetch_assoc($res)) $rows[] = $r;
}
$total_due_count = count($rows);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Geomorphology Due Management - MAA GOURI JEWELLERS</title>
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
            <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="reports.php"><i class="fas fa-chart-bar"></i> Sales Reports</a>
            <a href="customers.php"><i class="fas fa-users"></i> Customers</a>
            <a href="product_summary.php"><i class="fas fa-boxes"></i> Product Summary</a>
            <a href="accounts.php"><i class="fas fa-wallet"></i> Accounts Ledger</a>
            <a href="due_list.php" class="active"><i class="fas fa-file-invoice-dollar"></i> Due Management</a>
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
                    <h1 class="text-base sm:text-lg font-bold text-white tracking-wide">GEOMORPHOLOGY DUE MANAGEMENT</h1>
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
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-[#7a4e0a]">Non-GST Due Management</h2>
                    <p class="text-xs sm:text-sm text-stone-600 mt-1">Track outstanding balances on private cash memos, send WhatsApp/Email reminders, and record collections.</p>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <a href="reports.php" class="px-4 py-2 bg-stone-100 hover:bg-stone-200 text-stone-800 font-bold rounded-xl text-xs border border-stone-300 transition inline-flex items-center gap-1.5">
                        <i class="fas fa-file-invoice"></i> Sales Reports
                    </a>
                    <a href="accounts.php" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-xl text-xs shadow transition inline-flex items-center gap-1.5">
                        <i class="fas fa-wallet"></i> Accounts Ledger
                    </a>
                </div>
            </div>

            <!-- Metric Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-gold p-5 border-l-4 border-rose-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Total Outstanding Due</span>
                    <div class="text-2xl font-extrabold text-rose-600 mt-1">₹<?php echo number_format($total_due_sum, 2); ?></div>
                    <div class="text-xs text-stone-400 mt-1 font-semibold"><?php echo $total_due_customers; ?> total pending memos</div>
                </div>

                <div class="card-gold p-5 border-l-4 border-amber-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Overdue Balance</span>
                    <div class="text-2xl font-extrabold text-[#7a4e0a] mt-1">₹<?php echo number_format($overdue_sum, 2); ?></div>
                    <div class="text-xs text-rose-600 font-bold mt-1"><?php echo $overdue_count; ?> memos past due date</div>
                </div>

                <div class="card-gold p-5 border-l-4 border-emerald-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Today's Recovered Dues</span>
                    <div class="text-2xl font-extrabold text-emerald-700 mt-1">₹<?php echo number_format($today_recovered, 2); ?></div>
                    <div class="text-xs text-emerald-600 font-semibold mt-1">Collected today from past dues</div>
                </div>

                <div class="card-gold p-5 border-l-4 border-blue-600">
                    <span class="text-xs font-bold text-stone-500 uppercase">Active Due Memos</span>
                    <div class="text-2xl font-extrabold text-blue-900 mt-1"><?php echo $total_due_count; ?></div>
                    <div class="text-xs text-stone-400 font-semibold mt-1">Filtered in current view</div>
                </div>
            </div>

            <!-- Filters & Search Bar -->
            <div class="card-gold p-4 mb-6">
                <form method="GET" action="due_list.php" class="flex flex-col md:flex-row items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-2 w-full md:w-auto overflow-x-auto pb-1 md:pb-0">
                        <a href="due_list.php?status=all<?php echo $search_query ? '&search='.urlencode($search_query) : ''; ?>" 
                           class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?php echo $filter_status === 'all' ? 'bg-[#7a4e0a] text-white shadow' : 'bg-stone-100 text-stone-700 hover:bg-stone-200'; ?>">
                            All Memos (<?php echo $all_memos_count; ?>)
                        </a>
                        <a href="due_list.php?status=pending<?php echo $search_query ? '&search='.urlencode($search_query) : ''; ?>" 
                           class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?php echo $filter_status === 'pending' ? 'bg-amber-600 text-white shadow' : 'bg-amber-50 text-amber-800 hover:bg-amber-100'; ?>">
                            Pending Dues (<?php echo $pending_count; ?>)
                        </a>
                        <a href="due_list.php?status=overdue<?php echo $search_query ? '&search='.urlencode($search_query) : ''; ?>" 
                           class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?php echo $filter_status === 'overdue' ? 'bg-rose-600 text-white shadow' : 'bg-rose-50 text-rose-700 hover:bg-rose-100'; ?>">
                            Overdue (<?php echo $overdue_count; ?>)
                        </a>
                        <a href="due_list.php?status=paid<?php echo $search_query ? '&search='.urlencode($search_query) : ''; ?>" 
                           class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?php echo $filter_status === 'paid' ? 'bg-emerald-700 text-white shadow' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100'; ?>">
                            Fully Paid (<?php echo $all_memos_count - $pending_count; ?>)
                        </a>
                    </div>


                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        <div class="relative flex-1 md:w-64">
                            <i class="fas fa-search absolute left-3 top-2.5 text-stone-400"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" 
                                   placeholder="Search customer, mobile, memo #..." 
                                   class="w-full pl-9 pr-3 py-2 bg-stone-50 border border-stone-300 rounded-lg text-stone-800 focus:outline-none focus:border-amber-500">
                        </div>
                        <button type="submit" class="btn-gold-action">
                            Search
                        </button>
                        <?php if(!empty($search_query) || $filter_status !== 'all'): ?>
                        <a href="due_list.php" class="px-3 py-2 bg-stone-100 hover:bg-stone-200 text-stone-600 rounded-lg font-bold">
                            Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Outstanding Dues Table -->
            <div class="card-gold overflow-hidden">
                <div class="p-4 border-b border-stone-200 bg-amber-50/50 flex items-center justify-between">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-[#7a4e0a]">Outstanding Dues (<?php echo $total_due_count; ?>)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-[#f7eed7] border-b border-stone-200 text-stone-700 uppercase font-bold text-[11px]">
                                <th class="py-3 px-3">Memo No</th>
                                <th class="py-3 px-3">Customer</th>
                                <th class="py-3 px-3 text-right">Total Amount</th>
                                <th class="py-3 px-3 text-right">Paid Amount</th>
                                <th class="py-3 px-3 text-right font-black text-rose-600">Due Balance</th>
                                <th class="py-3 px-3 text-center">Due Date</th>
                                <th class="py-3 px-3 text-center">Remind</th>
                                <th class="py-3 px-3 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200" id="dueTableBody">
                            <?php if(empty($rows)): ?>
                            <tr><td colspan="8" class="text-center py-10 text-stone-400">No outstanding Non-GST dues found matching your criteria.</td></tr>
                            <?php else: foreach($rows as $r): 
                                $is_overdue = (!empty($r['due_date']) && $r['due_date'] !== '0000-00-00' && $r['due_date'] < $today);
                                $is_due_today = (!empty($r['due_date']) && $r['due_date'] === $today);
                                $clean_mobile = preg_replace('/[^0-9]/', '', $r['customer_mobile']);
                                if (strlen($clean_mobile) === 10) $clean_mobile = '91' . $clean_mobile;
                                $wa_msg = "নমস্কার " . ($r['customer_name'] ?: 'Customer') . ",\nমা গৌরী জুয়েলার্স থেকে জানানো হচ্ছে যে আপনার ক্যাশ মেমো নং: " . $r['invoice_no'] . " এর বাকি বকেয়া টাকা ₹" . number_format($r['balance_amount'], 2) . " অতি শীঘ্রই পরিশোধ করার জন্য অনুরোধ করা হচ্ছে।\nধন্যবাদ - মা গৌরী জুয়েলার্স";
                                $wa_url = "https://api.whatsapp.com/send?phone=" . $clean_mobile . "&text=" . urlencode($wa_msg);
                            ?>
                            <tr class="hover:bg-amber-50/50 transition <?php echo $is_overdue ? 'bg-rose-50/25' : ''; ?>" id="row-<?php echo $r['id']; ?>">
                                <td class="py-3 px-3 font-bold text-[#7a4e0a]">
                                    <?php echo htmlspecialchars($r['invoice_no']); ?>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="font-bold text-stone-900"><?php echo htmlspecialchars($r['customer_name'] ?: 'Cash Customer'); ?></div>
                                    <div class="text-[10px] text-stone-500 flex items-center gap-1">
                                        <i class="fas fa-phone text-[9px]"></i> <?php echo htmlspecialchars($r['customer_mobile'] ?: 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-right font-semibold text-stone-700">₹<?php echo number_format($r['total_amount'], 2); ?></td>
                                <td class="py-3 px-3 text-right text-emerald-700 font-bold">₹<?php echo number_format($r['paid_amount'], 2); ?></td>
                                <td class="py-3 px-3 text-right font-extrabold text-rose-600 text-sm">₹<?php echo number_format($r['balance_amount'], 2); ?></td>
                                <td class="py-3 px-3 text-center">
                                    <?php if(!empty($r['due_date']) && $r['due_date'] !== '0000-00-00'): ?>
                                        <?php if($is_overdue): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-100 text-rose-800 inline-flex items-center gap-1">
                                            <i class="fas fa-exclamation-triangle"></i> <?php echo date('d/m/Y', strtotime($r['due_date'])); ?>
                                        </span>
                                        <?php elseif($is_due_today): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-200 text-amber-900">
                                            Due Today
                                        </span>
                                        <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-stone-100 text-stone-700">
                                            <?php echo date('d/m/Y', strtotime($r['due_date'])); ?>
                                        </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-stone-400 text-[10px]">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <?php if(!empty($clean_mobile)): ?>
                                        <a href="<?php echo $wa_url; ?>" target="_blank" title="Send WhatsApp Reminder" class="p-1.5 bg-emerald-100 hover:bg-emerald-200 text-emerald-800 rounded-lg font-bold transition">
                                            <i class="fab fa-whatsapp text-sm"></i>
                                        </a>
                                        <?php endif; ?>
                                        <button type="button" onclick="sendEmailReminder(<?php echo $r['id']; ?>, '<?php echo addslashes($r['invoice_no']); ?>', '<?php echo addslashes($r['customer_name']); ?>', '<?php echo addslashes($r['customer_mobile']); ?>', <?php echo $r['balance_amount']; ?>)" title="Send Email Reminder" class="p-1.5 bg-amber-100 hover:bg-amber-200 text-amber-900 rounded-lg font-bold transition">
                                            <i class="fas fa-envelope text-xs"></i>
                                        </button>
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <button onclick="openPayModal(<?php echo $r['id']; ?>, '<?php echo addslashes($r['invoice_no']); ?>', '<?php echo addslashes($r['customer_name']); ?>', <?php echo $r['balance_amount']; ?>)" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs transition flex items-center gap-1 shadow-sm">
                                            <i class="fas fa-hand-holding-usd"></i> Receive
                                        </button>
                                        <button onclick="openHistoryModal(<?php echo $r['id']; ?>, '<?php echo addslashes($r['invoice_no']); ?>')" title="Payment Timeline" class="px-2.5 py-1.5 bg-stone-100 hover:bg-stone-200 text-stone-700 font-bold rounded-lg text-xs border border-stone-300 transition">
                                            <i class="fas fa-history"></i>
                                        </button>
                                        <a href="/view_pdf.php?invoice_no=<?php echo urlencode($r['invoice_no']); ?>" target="_blank" title="View Cash Memo" class="px-2.5 py-1.5 bg-[#011921] hover:bg-[#03373b] text-[#ffd700] font-bold rounded-lg text-xs transition">
                                            <i class="fas fa-print"></i>
                                        </a>
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

    <!-- Receive Payment Modal -->
    <div id="payModal" class="fixed inset-0 bg-black/60 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl border border-stone-200">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-stone-200">
                <h3 class="font-bold text-base text-stone-900 flex items-center gap-2">
                    <i class="fas fa-hand-holding-usd text-emerald-600"></i> Receive Due Payment
                </h3>
                <button onclick="closePayModal()" class="text-stone-400 hover:text-stone-600 text-lg">&times;</button>
            </div>
            
            <form id="payForm" onsubmit="submitPayment(event)" class="space-y-4 text-xs">
                <input type="hidden" id="payInvoiceId">
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Customer / Memo No</label>
                    <div id="payCustomerInfo" class="font-bold text-stone-900 text-sm bg-stone-50 p-2.5 rounded-lg border border-stone-200"></div>
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Payment Amount (₹) *</label>
                    <input type="number" step="0.01" min="1" id="payAmount" required class="w-full px-3 py-2 text-sm font-bold text-emerald-800 bg-white border border-stone-300 rounded-lg focus:outline-none focus:border-emerald-500">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Payment Mode</label>
                    <select id="payMode" class="w-full px-3 py-2 bg-white border border-stone-300 rounded-lg font-semibold">
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI / Online</option>
                        <option value="Bank / Online">Bank Transfer / NEFT</option>
                        <option value="Cheque">Cheque</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Next Due Date (If balance remains)</label>
                    <input type="date" id="payNextDueDate" class="w-full px-3 py-2 bg-white border border-stone-300 rounded-lg">
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="closePayModal()" class="flex-1 py-2.5 bg-stone-100 hover:bg-stone-200 font-bold rounded-xl text-stone-700">Cancel</button>
                    <button type="submit" id="paySubmitBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow-md">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- History Modal -->
    <div id="histModal" class="fixed inset-0 bg-black/60 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl p-6 w-full max-w-lg shadow-2xl border border-stone-200 max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-stone-200 flex-shrink-0">
                <h3 class="font-bold text-base text-stone-900 flex items-center gap-2">
                    <i class="fas fa-history text-amber-600"></i> Payment History &mdash; <span id="histInvoiceNo" class="text-amber-800"></span>
                </h3>
                <button onclick="closeHistoryModal()" class="text-stone-400 hover:text-stone-600 text-lg">&times;</button>
            </div>
            
            <div id="histContent" class="overflow-y-auto flex-1 text-xs">
                <!-- Content injected via AJAX -->
            </div>
            
            <div class="mt-4 pt-3 border-t border-stone-200 text-right flex-shrink-0">
                <button type="button" onclick="closeHistoryModal()" class="px-4 py-2 bg-stone-100 hover:bg-stone-200 text-stone-700 font-bold rounded-xl">Close</button>
            </div>
        </div>
    </div>

    <script>
        function openPayModal(id, invNo, custName, bal) {
            document.getElementById('payInvoiceId').value = id;
            document.getElementById('payCustomerInfo').innerHTML = `<span class="text-amber-800">${invNo}</span> &bull; ${custName} <span class="text-rose-600 ml-2">(Due: ₹${parseFloat(bal).toFixed(2)})</span>`;
            document.getElementById('payAmount').value = parseFloat(bal).toFixed(2);
            document.getElementById('payAmount').max = parseFloat(bal);
            document.getElementById('payModal').classList.remove('hidden');
        }
        function closePayModal() {
            document.getElementById('payModal').classList.add('hidden');
        }

        async function submitPayment(e) {
            e.preventDefault();
            const id = document.getElementById('payInvoiceId').value;
            const amt = document.getElementById('payAmount').value;
            const mode = document.getElementById('payMode').value;
            const nextDue = document.getElementById('payNextDueDate').value;
            const btn = document.getElementById('paySubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const fd = new FormData();
            fd.append('action', 'receive_due_payment');
            fd.append('id', id);
            fd.append('amount_paid', amt);
            fd.append('payment_mode', mode);
            fd.append('next_due_date', nextDue);

            try {
                const res = await fetch('due_list.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    btn.disabled = false;
                    btn.innerHTML = 'Confirm Payment';
                }
            } catch (err) {
                alert('Network error while processing payment.');
                btn.disabled = false;
                btn.innerHTML = 'Confirm Payment';
            }
        }

        async function sendEmailReminder(id, invNo, custName, mobile, bal) {
            if (!confirm(`Send email reminder to ${custName} for outstanding due ₹${parseFloat(bal).toFixed(2)}?`)) return;

            const fd = new FormData();
            fd.append('action', 'ajax_send_reminder');
            fd.append('invoice_no', invNo);
            fd.append('customer_name', custName);
            fd.append('customer_mobile', mobile);
            fd.append('balance_amount', bal);

            try {
                const res = await fetch('due_list.php', { method: 'POST', body: fd });
                const data = await res.json();
                alert(data.message);
            } catch (err) {
                alert('Failed to send email reminder.');
            }
        }

        async function openHistoryModal(id, invNo) {
            document.getElementById('histInvoiceNo').textContent = invNo;
            document.getElementById('histModal').classList.remove('hidden');
            document.getElementById('histContent').innerHTML = '<div class="text-center py-6 text-stone-400"><i class="fas fa-spinner fa-spin mr-1"></i> Loading history...</div>';

            const fd = new FormData();
            fd.append('action', 'ajax_history');
            fd.append('id', id);

            try {
                const res = await fetch('due_list.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success && data.history.length > 0) {
                    let html = '<table class="w-full text-xs text-left"><thead><tr class="border-b border-stone-200 text-stone-500 font-bold"><th class="py-2">Date</th><th class="py-2">Mode</th><th class="py-2 text-right">Amount Paid</th><th class="py-2 text-right">Remaining Due</th><th class="py-2 text-center">Receipt</th></tr></thead><tbody class="divide-y divide-stone-100">';
                    data.history.forEach(h => {
                        html += `<tr>
                            <td class="py-2.5 text-stone-700 font-semibold">${h.payment_date}</td>
                            <td class="py-2.5 font-bold text-stone-600">${h.payment_mode || 'Cash'}</td>
                            <td class="py-2.5 text-right font-bold text-emerald-700">₹${parseFloat(h.amount_paid).toFixed(2)}</td>
                            <td class="py-2.5 text-right font-bold text-rose-600">₹${parseFloat(h.new_balance).toFixed(2)}</td>
                            <td class="py-2.5 text-center">
                                <a href="../view_pdf.php?invoice_no=${encodeURIComponent(invNo)}&receipt=1&history_id=${h.history_id}" target="_blank" class="text-amber-700 hover:underline font-bold px-2 py-1 bg-amber-50 rounded">
                                    <i class="fas fa-print"></i>
                                </a>
                            </td>
                        </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('histContent').innerHTML = html;
                } else {
                    document.getElementById('histContent').innerHTML = '<div class="text-center py-8 text-stone-400">No partial payment records found for this cash memo.</div>';
                }
            } catch (err) {
                document.getElementById('histContent').innerHTML = '<div class="text-center py-6 text-rose-500">Failed to load payment history.</div>';
            }
        }

        function closeHistoryModal() {
            document.getElementById('histModal').classList.add('hidden');
        }
    </script>
</body>
</html>
