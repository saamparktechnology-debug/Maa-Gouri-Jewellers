<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$is_logged_in = true;

// ── Ensure required columns & due_update_history table exist ────────────────
$cols = ['cash_paid', 'upi_paid', 'account_paid', 'cheque_paid', 'old_gold_value'];
foreach ($cols as $c) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE '$c'");
    if ($chk && mysqli_num_rows($chk) == 0) {
        @mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN $c DECIMAL(10,2) DEFAULT 0");
    }
}

$chkHistoryTable = mysqli_query($conn, "SHOW TABLES LIKE 'due_update_history'");
if ($chkHistoryTable && mysqli_num_rows($chkHistoryTable) > 0) {
    $chkModeCol = mysqli_query($conn, "SHOW COLUMNS FROM due_update_history LIKE 'payment_mode'");
    if ($chkModeCol && mysqli_num_rows($chkModeCol) == 0) {
        @mysqli_query($conn, "ALTER TABLE due_update_history ADD COLUMN payment_mode VARCHAR(50) DEFAULT 'Cash'");
    }
}

// ── Month filter ─────────────────────────────────────────────────────────
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

$monthStartEsc = mysqli_real_escape_string($conn, $monthStart);
$monthEndEsc   = mysqli_real_escape_string($conn, $monthEnd);

// ── 1. Fetch all paid/part invoices for the month ────────────────────────────
$sql = "
    SELECT
        DATE(created_at)               AS day,
        invoice_no,
        payment_method,
        payment_status,
        COALESCE(cash_paid, 0)         AS cash_paid,
        COALESCE(upi_paid, 0)          AS upi_paid,
        COALESCE(account_paid, 0)      AS account_paid,
        COALESCE(cheque_paid, 0)       AS cheque_paid,
        COALESCE(old_gold_value, 0)    AS old_gold_value,
        paid_amount,
        total_amount
    FROM invoices
    WHERE DATE(created_at) BETWEEN '$monthStartEsc' AND '$monthEndEsc'
      AND payment_status != 'unpaid'
    ORDER BY created_at DESC
";
$res = mysqli_query($conn, $sql);
if (!$res) die("Query Error: " . mysqli_error($conn));

$days = [];
while ($row = mysqli_fetch_assoc($res)) {
    $d = $row['day'];
    if (!isset($days[$d])) {
        $days[$d] = ['cash' => 0, 'upi' => 0, 'cheque' => 0, 'oldgold' => 0, 'total' => 0, 'bills' => 0, 'due_collections' => 0];
    }
    $cash    = floatval($row['cash_paid']);
    $upi     = floatval($row['upi_paid']) + floatval($row['account_paid']);
    $cheque  = floatval($row['cheque_paid']);
    $oldgold = floatval($row['old_gold_value']);

    // Fallback if split columns not populated
    $method = strtolower(trim($row['payment_method']));
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
}

// ── 2. Fetch Due Payments Cleared Today / In Month (from due_update_history) ─
if ($chkHistoryTable && mysqli_num_rows($chkHistoryTable) > 0) {
    $dueSql = "
        SELECT 
            DATE(h.payment_date)           AS day,
            COALESCE(h.amount_paid, 0)     AS amount_paid,
            COALESCE(h.payment_mode, 'Cash') AS payment_mode,
            i.invoice_no
        FROM due_update_history h
        LEFT JOIN invoices i ON h.invoice_id = i.id
        WHERE DATE(h.payment_date) BETWEEN '$monthStartEsc' AND '$monthEndEsc'
          AND h.amount_paid > 0
        ORDER BY h.payment_date DESC
    ";
    $dueRes = mysqli_query($conn, $dueSql);
    if ($dueRes) {
        while ($drow = mysqli_fetch_assoc($dueRes)) {
            $d = $drow['day'];
            if (!isset($days[$d])) {
                $days[$d] = ['cash' => 0, 'upi' => 0, 'cheque' => 0, 'oldgold' => 0, 'total' => 0, 'bills' => 0, 'due_collections' => 0];
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
        }
    }
}

ksort($days);
$days = array_reverse($days, true);

// ── Month totals ──────────────────────────────────────────────────────────
$monthCash     = array_sum(array_column($days, 'cash'));
$monthUpi      = array_sum(array_column($days, 'upi'));
$monthCheque   = array_sum(array_column($days, 'cheque'));
$monthOldGold  = array_sum(array_column($days, 'oldgold'));
$monthTotal    = array_sum(array_column($days, 'total'));
$monthBills    = array_sum(array_column($days, 'bills'));
$monthDueRec   = array_sum(array_column($days, 'due_collections'));

// ── Today highlight ───────────────────────────────────────────────────────
$today = date('Y-m-d');
$todayCash      = $days[$today]['cash']      ?? 0;
$todayUpi       = $days[$today]['upi']       ?? 0;
$todayTotal     = $days[$today]['total']     ?? 0;
$todayBills     = $days[$today]['bills']     ?? 0;
$todayDueRec    = $days[$today]['due_collections'] ?? 0;

function fmt($v) {
    return '₹' . number_format($v, 2, '.', ',');
}
function pct($part, $total) {
    return $total > 0 ? round(($part / $total) * 100) : 0;
}

$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>Accounts — MAA GOURI JEWELLERS</title>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

*, *::before, *::after { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
h1, h2, h3, .gold-font { font-family: 'Poppins', sans-serif; font-weight: 700; }

/* ========== SIDEBAR ========== */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 240px;
    height: 100vh;
    background: linear-gradient(180deg, #011921 0%, #03373b 50%, #044e54 80%, #011921 100%);
    z-index: 1000;
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 24px rgba(0,0,0,0.25);
    transition: transform 0.35s cubic-bezier(.4,0,.2,1);
    overflow: hidden;
}

.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

.sidebar-logo {
    padding: 22px 18px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.18);
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.sidebar-logo img {
    width: 44px;
    height: 44px;
    object-fit: cover;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    padding: 2px;
    border: 1.5px solid #ffd700;
    flex-shrink: 0;
}

.sidebar-logo-text h2 {
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: 0.5px;
}

.sidebar-logo-text p {
    color: rgba(255,255,255,0.65);
    font-size: 10px;
    margin-top: 1px;
}

.sidebar-nav { flex: 1; padding: 10px 0; overflow-y: auto; overflow-x: hidden; }
.sidebar-section-label { padding: 10px 20px 4px; color: #f5c842; font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; position: sticky; top: 0; background: #011921; z-index: 10; }
.sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent; position: relative; }
.sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
.sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
.sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; opacity: 0.9; }
.sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }
.sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
.sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
.sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }
.sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; border: 1px solid rgba(239,68,68,0.4); }
.sidebar-logout:hover { background: #ef4444; color: #fff; }

.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
.sidebar-overlay.active { display: block; }

/* ========== MAIN LAYOUT ========== */
.page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; background: #fdfbf7; }
nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important; margin-left: 0; }

.card-gold {
    background: #fff;
    border: 1px solid rgba(214,139,22,0.25);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(122,78,10,0.06);
}

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .page-wrapper { margin-left: 0 !important; }
}
</style>
</head>
<body class="bg-amber-50/20 text-gray-800 antialiased">

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ========== SIDEBAR ========== -->
<div class="sidebar" id="mainSidebar">
    <!-- Logo -->
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) {
                echo '<img src="'.$path.'" alt="MAA GOURI JEWELLERS Logo" style="width:38px;height:38px;object-fit:cover;border-radius:50%;border:1.5px solid #ffd700;display:inline-block;margin-right:8px;">';
                $logo_found = true; break;
            }
        }
        if(!$logo_found) echo '<i class="fas fa-gem" style="color:#fff;font-size:30px;flex-shrink:0;"></i>';
        ?>
        <div class="sidebar-logo-text">
            <h2>MAA GOURI JEWELLERS</h2>
            <p>Premium Since 2026</p>
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
        <a href="due_list.php"><i class="fas fa-hourglass-half"></i> DUE LIST</a>
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="purchase.php"><i class="fas fa-book"></i> PURCHASE</a>
        <a href="contacts.php"><i class="fas fa-address-book"></i> CONTACTS</a>
        <a href="accounts.php" class="active"><i class="fas fa-calculator"></i> ACCOUNTS</a>
    </nav>

    <!-- User Info + Logout -->
    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <svg width="28" height="28" viewBox="0 0 496 512" aria-hidden="true" focusable="false" style="flex-shrink:0;color:inherit;">
                <path fill="currentColor" d="M248 8C111 8 0 119 0 256s111 248 248 248 248-111 248-248S385 8 248 8zm0 96a72 72 0 1 1 0 144 72 72 0 0 1 0-144zm0 344c-59.6 0-112.9-32.7-139.7-80.4 7.1-44 88.4-68.5 139.7-68.5 51.3 0 132.6 24.5 139.7 68.5C360.9 415.3 307.6 448 248 448z"></path>
            </svg>
            <div class="user-details">
                <p><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></p>
                <span><?php echo htmlspecialchars($_SESSION['user_mobile'] ?? 'Admin'); ?></span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout">
            <i class="fas fa-sign-out-alt"></i> LOGOUT
        </a>
    </div>
</div>

<!-- Main Wrapper -->
<div class="page-wrapper">
    <!-- Top Navbar -->
    <header class="bg-white border-b border-amber-200/60 px-6 py-3.5 sticky top-0 z-30 flex items-center justify-between no-print shadow-sm">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="md:hidden text-amber-900 p-2 text-xl focus:outline-none">
                <i class="fas fa-bars"></i>
            </button>
            <div class="flex items-center gap-2">
                <img src="assets/images/moti-removebg-preview.png" alt="Logo" class="w-8 h-8 object-contain" onerror="this.src='moti-removebg-preview.png'">
                <div>
                    <h1 class="font-bold text-lg text-amber-950 leading-none">MAA GOURI JEWELLERS</h1>
                    <p class="text-xs text-amber-700 font-medium">Daily Accounts &amp; Cash Collection</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="billing.php" class="hidden sm:inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-bold text-amber-900 bg-amber-100 border border-amber-300 hover:bg-amber-200 transition">
                <i class="fas fa-plus"></i> New Bill
            </a>
        </div>
    </header>

    <!-- Content Area -->
    <div class="p-4 sm:p-6 max-w-7xl mx-auto space-y-6">

        <!-- Page Heading Bar -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white p-5 rounded-2xl border border-amber-200/70 shadow-sm">
            <div>
                <h1 class="text-2xl font-bold text-amber-950 flex items-center gap-2">
                    <i class="fas fa-book text-amber-600"></i> Cash &amp; Accounts Ledger
                </h1>
                <p class="text-xs text-gray-500 mt-1">Cash, UPI &amp; Due Payment Collection History — <strong class="text-amber-800"><?= htmlspecialchars($monthLabel) ?></strong></p>
            </div>

            <div class="flex items-center gap-3 flex-wrap no-print">
                <form method="GET" class="flex gap-2 items-center">
                    <label class="text-xs font-bold text-amber-900">📅 Month:</label>
                    <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" onchange="this.form.submit()" class="px-3 py-1.5 text-xs rounded-xl border border-amber-300 bg-amber-50 text-amber-900 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500">
                </form>
                <button onclick="window.print()" class="px-4 py-2 text-xs font-bold text-white rounded-xl shadow-md flex items-center gap-2" style="background:linear-gradient(135deg, #7a4e0a 0%, #d68b16 100%);">
                    <i class="fas fa-print"></i> Print Statement
                </button>
            </div>
        </div>

        <!-- Today Summary Cards (if viewing current month) -->
        <?php if ($month === date('Y-m')): ?>
        <div>
            <div class="text-xs font-bold uppercase tracking-wider text-amber-800 mb-3 flex items-center gap-2">
                <i class="fas fa-bolt text-amber-500"></i> Today's Live Collections — <?= date('d M Y') ?>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="card-gold p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-xl font-bold text-amber-900" style="background:linear-gradient(135deg, #ffd700 0%, #b5730e 100%);">
                        💵
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-500 uppercase">Today Cash Collection</div>
                        <div class="text-xl font-extrabold text-amber-950"><?= fmt($todayCash) ?></div>
                        <div class="text-xs text-gray-400 mt-0.5"><?= $todayBills ?> new bill(s) <?= $todayDueRec > 0 ? '+ Due Payments' : '' ?></div>
                    </div>
                </div>

                <div class="card-gold p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-xl font-bold text-white" style="background:linear-gradient(135deg, #800020 0%, #c0002e 100%);">
                        📲
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-500 uppercase">Today Digital / UPI</div>
                        <div class="text-xl font-extrabold text-rose-900"><?= fmt($todayUpi) ?></div>
                        <div class="text-xs text-gray-400 mt-0.5"><?= pct($todayUpi, $todayTotal) ?>% of today</div>
                    </div>
                </div>

                <div class="card-gold p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-xl font-bold text-green-950" style="background:linear-gradient(135deg, #a7f3d0 0%, #059669 100%);">
                        💰
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-500 uppercase">Today Net Total Received</div>
                        <div class="text-xl font-extrabold text-emerald-800"><?= fmt($todayTotal) ?></div>
                        <?php if($todayDueRec > 0): ?>
                        <div class="text-xs text-emerald-600 font-semibold mt-0.5">Includes <?= fmt($todayDueRec) ?> Due Cleared</div>
                        <?php else: ?>
                        <div class="text-xs text-gray-400 mt-0.5">All modes combined</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Month Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
            <div class="card-gold p-5">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">Total Collection</div>
                <div class="text-2xl font-bold text-amber-950 mt-1"><?= fmt($monthTotal) ?></div>
                <div class="text-xs text-gray-500 mt-1"><?= $monthBills ?> billing invoices</div>
            </div>

            <div class="card-gold p-5">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">💵 Cash Received</div>
                <div class="text-2xl font-bold text-amber-800 mt-1"><?= fmt($monthCash) ?></div>
                <div class="mt-2">
                    <div class="h-1.5 w-full bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-amber-500 rounded-full" style="width:<?= pct($monthCash, $monthTotal) ?>%"></div>
                    </div>
                    <span class="text-xs text-gray-400 mt-1 block"><?= pct($monthCash, $monthTotal) ?>% of total</span>
                </div>
            </div>

            <div class="card-gold p-5">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">📲 UPI / Bank</div>
                <div class="text-2xl font-bold text-rose-900 mt-1"><?= fmt($monthUpi) ?></div>
                <div class="mt-2">
                    <div class="h-1.5 w-full bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-rose-600 rounded-full" style="width:<?= pct($monthUpi, $monthTotal) ?>%"></div>
                    </div>
                    <span class="text-xs text-gray-400 mt-1 block"><?= pct($monthUpi, $monthTotal) ?>% of total</span>
                </div>
            </div>

            <div class="card-gold p-5">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">💳 Due Payment Received</div>
                <div class="text-2xl font-bold text-emerald-800 mt-1"><?= fmt($monthDueRec) ?></div>
                <div class="text-xs text-emerald-600 font-semibold mt-1">Cleared from past dues</div>
            </div>
        </div>

        <!-- Daily Collection Table -->
        <div class="card-gold overflow-hidden">
            <div class="px-6 py-4 flex items-center justify-between border-b border-amber-200/60" style="background:linear-gradient(135deg, #7a4e0a 0%, #d68b16 100%);">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fas fa-list-alt"></i> Daily Collection Ledger — <?= htmlspecialchars($monthLabel) ?>
                </h3>
                <span class="text-xs font-bold px-3 py-1 rounded-full bg-white/20 text-white"><?= count($days) ?> Active Days</span>
            </div>

            <?php if (empty($days)): ?>
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-receipt text-4xl mb-3 block text-amber-200"></i>
                <div class="text-sm font-semibold text-gray-600">No collection records found</div>
                <div class="text-xs text-gray-400 mt-1">No collections recorded for <?= htmlspecialchars($monthLabel) ?></div>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-amber-100/50 text-amber-900 text-xs uppercase font-bold border-b border-amber-200/70">
                            <th class="px-5 py-3">Date</th>
                            <th class="px-5 py-3 text-center">New Bills</th>
                            <th class="px-5 py-3 text-right">💵 Cash</th>
                            <th class="px-5 py-3 text-right">📲 UPI / Digital</th>
                            <th class="px-5 py-3 text-right">💳 Due Cleared</th>
                            <th class="px-5 py-3 text-right">Total Collection</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100">
                    <?php foreach ($days as $d => $v):
                        $isToday = ($d === $today);
                        $cashPct = pct($v['cash'], $v['total']);
                        $upiPct  = pct($v['upi'],  $v['total']);
                        $dayLabel = date('D, d M Y', strtotime($d));
                    ?>
                    <tr class="hover:bg-amber-50/50 transition <?= $isToday ? 'bg-amber-100/30' : '' ?>">
                        <td class="px-5 py-3.5">
                            <div class="flex items-center gap-2">
                                <?php if($isToday): ?>
                                <span class="px-2 py-0.5 text-xs font-extrabold rounded-full bg-amber-800 text-white shadow-sm">TODAY</span>
                                <?php endif; ?>
                                <span class="text-xs font-bold text-gray-800"><?= $dayLabel ?></span>
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-900 border border-amber-200"><?= $v['bills'] ?></span>
                        </td>
                        <td class="px-5 py-3.5 text-right font-bold text-amber-800 text-xs">
                            <?= $v['cash'] > 0 ? fmt($v['cash']) : '<span class="text-gray-300 font-normal">—</span>' ?>
                        </td>
                        <td class="px-5 py-3.5 text-right font-bold text-rose-900 text-xs">
                            <?= $v['upi'] > 0 ? fmt($v['upi']) : '<span class="text-gray-300 font-normal">—</span>' ?>
                        </td>
                        <td class="px-5 py-3.5 text-right font-bold text-emerald-700 text-xs">
                            <?= ($v['due_collections'] ?? 0) > 0 ? ('+' . fmt($v['due_collections'])) : '<span class="text-gray-300 font-normal">—</span>' ?>
                        </td>
                        <td class="px-5 py-3.5 text-right font-extrabold text-gray-900 text-sm">
                            <?= fmt($v['total']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-amber-100/70 border-t-2 border-amber-300 text-amber-950 font-bold">
                            <td class="px-5 py-3.5 text-xs uppercase">📊 Month Total</td>
                            <td class="px-5 py-3.5 text-center text-xs"><?= $monthBills ?></td>
                            <td class="px-5 py-3.5 text-right text-xs text-amber-900"><?= fmt($monthCash) ?></td>
                            <td class="px-5 py-3.5 text-right text-xs text-rose-900"><?= fmt($monthUpi) ?></td>
                            <td class="px-5 py-3.5 text-right text-xs text-emerald-800"><?= fmt($monthDueRec) ?></td>
                            <td class="px-5 py-3.5 text-right text-sm font-black text-amber-950"><?= fmt($monthTotal) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <footer style="background:linear-gradient(0deg,#f5e6c8,#fdf6e3);border-top:2px solid #d68b16;padding:20px;margin-top:40px;text-align:center;">
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology</a>
        </p>
    </footer>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
}
function closeSidebar() {
    document.getElementById('mainSidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
}
</script>
</body>
</html>


