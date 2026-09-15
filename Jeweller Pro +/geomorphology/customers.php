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

// AJAX: Get customer non-GST order history
if(isset($_GET['action']) && $_GET['action'] === 'get_orders') {
    header('Content-Type: application/json');
    $mobile = mysqli_real_escape_string($conn, trim($_GET['mobile'] ?? ''));
    if(empty($mobile)) { echo json_encode(['success'=>false, 'orders'=>[]]); exit(); }

    $inv_res = mysqli_query($conn, "
        SELECT invoice_no, total_amount, paid_amount, balance_amount, payment_status, payment_method, gst_type, created_at 
        FROM invoices 
        WHERE customer_mobile='$mobile' AND (gst_type NOT LIKE 'gst%' AND (gst_amount IS NULL OR gst_amount = 0)) 
        ORDER BY created_at DESC
    ");
    $orders = [];
    if($inv_res) {
        while($inv = mysqli_fetch_assoc($inv_res)) {
            $inv_no = mysqli_real_escape_string($conn, $inv['invoice_no']);
            $items_res = mysqli_query($conn, "
                SELECT ii.quantity, ii.pcs, ii.unit, ii.gross_wt, ii.net_wt, ii.price, ii.total, ii.hsn_code,
                    COALESCE(ii.product_name, p.name, '') as pname,
                    COALESCE(p.item_name, '') as item_name,
                    COALESCE(p.category, '') as category
                FROM invoice_items ii
                LEFT JOIN products p ON ii.product_id = p.id
                WHERE ii.invoice_id = (SELECT id FROM invoices WHERE invoice_no='$inv_no' LIMIT 1)
            ");
            $items = [];
            if($items_res) {
                while($it = mysqli_fetch_assoc($items_res)) {
                    $cat  = $it['category'] ?: '';
                    $iname = $it['item_name'] ?: '';
                    $pname = $it['pname'] ?: '';
                    $desc = trim(($cat ? $cat . ' - ' : '') . ($iname ?: $pname));
                    if(empty($desc)) $desc = 'Jewellery Item';
                    $wt = floatval($it['gross_wt'] > 0 ? $it['gross_wt'] : ($it['net_wt'] > 0 ? $it['net_wt'] : 0));
                    $items[] = [
                        'desc'   => $desc,
                        'qty'    => floatval($it['quantity'] > 0 ? $it['quantity'] : ($it['pcs'] ?: 1)),
                        'unit'   => $it['unit'] ?: 'pcs',
                        'weight' => $wt > 0 ? number_format($wt, 3) . 'g' : '',
                        'rate'   => floatval($it['price']),
                        'total'  => floatval($it['total'])
                    ];
                }
            }
            $inv['items'] = $items;
            $orders[] = $inv;
        }
    }
    echo json_encode(['success'=>true, 'orders'=>$orders]);
    exit();
}

// Handle Add Customer
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_customer'])) {
    $name    = mysqli_real_escape_string($conn, trim($_POST['name']));
    $mobile  = mysqli_real_escape_string($conn, trim($_POST['mobile']));
    $email   = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $address = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));

    $chk = mysqli_query($conn, "SELECT id FROM customers WHERE mobile = '$mobile'");
    if(mysqli_num_rows($chk) > 0) {
        $_SESSION['flash_error'] = "Customer with mobile $mobile already exists!";
        header("Location: customers.php");
        exit();
    }

    if(mysqli_query($conn, "INSERT INTO customers (name, mobile, email, address, created_at) VALUES ('$name', '$mobile', '$email', '$address', NOW())")) {
        $_SESSION['flash_success'] = "Customer '$name' added successfully.";
    } else {
        $_SESSION['flash_error'] = "Error adding customer: " . mysqli_error($conn);
    }
    header("Location: customers.php");
    exit();
}

// Handle Update Customer
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_customer'])) {
    $id      = intval($_POST['customer_id'] ?? 0);
    $name    = mysqli_real_escape_string($conn, trim($_POST['name']));
    $mobile  = mysqli_real_escape_string($conn, trim($_POST['mobile']));
    $email   = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $address = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));

    if($id > 0) {
        if(mysqli_query($conn, "UPDATE customers SET name='$name', mobile='$mobile', email='$email', address='$address' WHERE id=$id")) {
            $_SESSION['flash_success'] = "Customer '$name' updated successfully.";
        } else {
            $_SESSION['flash_error'] = "Error updating customer: " . mysqli_error($conn);
        }
    }
    header("Location: customers.php");
    exit();
}

// Search & filter
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

$where_clauses = ["1=1"];
if(!empty($search)) {
    $where_clauses[] = "(c.name LIKE '%$search%' OR c.mobile LIKE '%$search%' OR c.address LIKE '%$search%')";
}
if($filter == 'last_month') {
    $where_clauses[] = "c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif($filter == 'this_month') {
    $where_clauses[] = "MONTH(c.created_at)=MONTH(CURRENT_DATE()) AND YEAR(c.created_at)=YEAR(CURRENT_DATE())";
}

$where_sql = implode(' AND ', $where_clauses);

// Query all customers who have Non-GST invoices
$query = "
    SELECT 
        c.id, c.name, c.mobile, c.email, c.address, c.created_at,
        COUNT(i.id) as memo_count,
        COALESCE(SUM(i.total_amount), 0) as total_purchases,
        COALESCE(SUM(i.balance_amount), 0) as total_due,
        MAX(i.created_at) as last_memo_date
    FROM customers c
    INNER JOIN invoices i ON c.mobile = i.customer_mobile AND (i.gst_type NOT LIKE 'gst%' AND (i.gst_amount IS NULL OR i.gst_amount = 0))
    WHERE $where_sql
    GROUP BY c.id, c.name, c.mobile, c.email, c.address, c.created_at
    ORDER BY last_memo_date DESC, c.id DESC
";

$customers_result = mysqli_query($conn, $query);
$customers = [];
if($customers_result) {
    while($row = mysqli_fetch_assoc($customers_result)) {
        $customers[] = $row;
    }
}

$total_customers = count($customers);
$total_purchases_all = array_sum(array_column($customers, 'total_purchases'));
$total_due_all = array_sum(array_column($customers, 'total_due'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers (Non-GST) - GEOMORPHOLOGY</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3 { font-family: 'Poppins', sans-serif; font-weight: 700; }

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
            <a href="customers.php" class="active"><i class="fas fa-users"></i> Customers</a>
            <a href="product_summary.php"><i class="fas fa-boxes"></i> Product Summary</a>
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
                    <h1 class="text-base sm:text-lg font-bold text-white tracking-wide">NON-GST CUSTOMERS</h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-stone-950 shadow-sm">
                        PROTECTED MODE
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="/geomorphology?geo_action=exit_to_main&target=/customers.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 text-white border border-white/20 transition hidden sm:inline-flex">
                    <i class="fas fa-users mr-1"></i> Main GST Customers
                </a>
            </div>
        </nav>

        <!-- Main Body -->
        <main class="p-4 sm:p-6 lg:p-8 flex-1">

            <!-- Flash messages -->
            <?php if(isset($_SESSION['flash_success'])): ?>
                <div class="p-3 mb-4 rounded-xl bg-emerald-100 border border-emerald-300 text-emerald-800 text-xs font-semibold flex items-center gap-2">
                    <i class="fas fa-check-circle text-emerald-600"></i> <?php echo $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
                </div>
            <?php endif; ?>
            <?php if(isset($_SESSION['flash_error'])): ?>
                <div class="p-3 mb-4 rounded-xl bg-rose-100 border border-rose-300 text-rose-800 text-xs font-semibold flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-rose-600"></i> <?php echo $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?>
                </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-[#7a4e0a]">Non-GST Customers Management</h2>
                    <p class="text-xs sm:text-sm text-stone-600 mt-1">Directory of customers with Cash Memo records, purchase history and dues.</p>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <button onclick="openAddCustomerModal()" class="px-4 py-2 bg-gradient-to-r from-[#d68b16] to-[#b5730e] hover:from-[#b5730e] hover:to-[#7a4e0a] text-white font-bold rounded-xl text-xs shadow-md transition inline-flex items-center gap-1.5 cursor-pointer">
                        <i class="fas fa-user-plus"></i> Add New Customer
                    </button>
                    <a href="index.php?geo_action=exit_to_main&target=../billing.php" class="px-4 py-2 bg-stone-100 hover:bg-stone-200 text-stone-800 font-bold rounded-xl text-xs border border-stone-300 transition inline-flex items-center gap-1.5">
                        <i class="fas fa-cash-register"></i> New Memo
                    </a>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="kpi-card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-xl bg-amber-100 text-amber-800">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total Non-GST Customers</div>
                        <div class="text-xl font-extrabold text-[#7a4e0a] mt-0.5"><?php echo number_format($total_customers); ?></div>
                        <div class="text-[10px] text-gray-500 mt-0.5">With Cash Memo transactions</div>
                    </div>
                </div>

                <div class="kpi-card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-xl bg-emerald-100 text-emerald-800">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total Purchases</div>
                        <div class="text-xl font-extrabold text-emerald-800 mt-0.5">₹<?php echo number_format($total_purchases_all, 2); ?></div>
                        <div class="text-[10px] text-gray-500 mt-0.5">Non-GST sales volume</div>
                    </div>
                </div>

                <div class="kpi-card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-xl bg-rose-100 text-rose-800">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Outstanding Dues</div>
                        <div class="text-xl font-extrabold text-rose-800 mt-0.5">₹<?php echo number_format($total_due_all, 2); ?></div>
                        <div class="text-[10px] text-gray-500 mt-0.5">Non-GST pending balances</div>
                    </div>
                </div>
            </div>

            <!-- Search & Filter Bar -->
            <div class="kpi-card p-4 mb-6">
                <form method="GET" class="flex flex-col sm:flex-row items-center gap-3">
                    <div class="flex-1 w-full">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search customer name, mobile number, or address..." class="w-full bg-stone-50 border border-amber-300 rounded-lg px-3 py-2 text-xs text-gray-800 focus:outline-none focus:ring-1 focus:ring-amber-500">
                    </div>
                    <div class="w-full sm:w-48">
                        <select name="filter" class="w-full bg-stone-50 border border-amber-300 rounded-lg px-3 py-2 text-xs font-medium text-gray-800 focus:outline-none focus:ring-1 focus:ring-amber-500" onchange="this.form.submit()">
                            <option value="all" <?php if($filter==='all') echo 'selected'; ?>>All Customers</option>
                            <option value="this_month" <?php if($filter==='this_month') echo 'selected'; ?>>Added This Month</option>
                            <option value="last_month" <?php if($filter==='last_month') echo 'selected'; ?>>Last 30 Days</option>
                        </select>
                    </div>
                    <div class="flex gap-2 w-full sm:w-auto">
                        <button type="submit" class="flex-1 sm:flex-none py-2 px-4 bg-[#7a4e0a] hover:bg-[#5c3a07] text-white font-bold rounded-lg text-xs transition shadow-sm cursor-pointer">
                            <i class="fas fa-search mr-1"></i> Search
                        </button>
                        <a href="customers.php" class="py-2 px-3 bg-stone-200 hover:bg-stone-300 text-stone-700 font-bold rounded-lg text-xs transition text-center">
                            Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Customers Table -->
            <div class="kpi-card overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-amber-200/60 bg-amber-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-address-book text-[#7a4e0a]"></i>
                        <h3 class="text-sm font-bold text-gray-800">Non-GST Customer Directory</h3>
                    </div>
                    <span class="text-xs font-bold text-amber-900 bg-amber-200/70 px-3 py-1 rounded-full">
                        <?php echo count($customers); ?> Customers Found
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-[#011921] text-[#ffd700] uppercase text-[10px] tracking-wider">
                                <th class="py-3 px-4">#</th>
                                <th class="py-3 px-4">Customer Name</th>
                                <th class="py-3 px-4">Mobile</th>
                                <th class="py-3 px-4">Address</th>
                                <th class="py-3 px-4 text-center">Cash Memos</th>
                                <th class="py-3 px-4 text-right">Total Purchases</th>
                                <th class="py-3 px-4 text-right">Due Balance</th>
                                <th class="py-3 px-4">Last Memo Date</th>
                                <th class="py-3 px-4 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-amber-100">
                            <?php 
                            if(empty($customers)): 
                            ?>
                            <tr>
                                <td colspan="9" class="py-8 text-center text-stone-500 italic">
                                    No non-GST customer records found matching your search.
                                </td>
                            </tr>
                            <?php 
                            else:
                                foreach($customers as $idx => $c):
                            ?>
                            <tr class="hover:bg-amber-50/40 transition">
                                <td class="py-3 px-4 text-stone-400 font-mono"><?php echo $idx + 1; ?></td>
                                <td class="py-3 px-4 font-bold text-gray-900">
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </td>
                                <td class="py-3 px-4 font-mono text-gray-700">
                                    <a href="tel:<?php echo htmlspecialchars($c['mobile']); ?>" class="text-amber-800 hover:underline">
                                        <?php echo htmlspecialchars($c['mobile']); ?>
                                    </a>
                                </td>
                                <td class="py-3 px-4 text-gray-600 max-w-xs truncate">
                                    <?php echo htmlspecialchars($c['address'] ?: '-'); ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="inline-block px-2.5 py-0.5 bg-amber-100 text-amber-900 font-bold rounded-full text-[11px]">
                                        <?php echo intval($c['memo_count']); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right font-mono font-bold text-emerald-800">
                                    ₹<?php echo number_format(floatval($c['total_purchases']), 2); ?>
                                </td>
                                <td class="py-3 px-4 text-right font-mono font-bold <?php echo floatval($c['total_due']) > 0 ? 'text-rose-700' : 'text-gray-400'; ?>">
                                    ₹<?php echo number_format(floatval($c['total_due']), 2); ?>
                                </td>
                                <td class="py-3 px-4 text-gray-500 whitespace-nowrap">
                                    <?php echo $c['last_memo_date'] ? date('d M Y', strtotime($c['last_memo_date'])) : '-'; ?>
                                </td>
                                <td class="py-3 px-4 text-center whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5">
                                        <button onclick="viewCustomerOrders('<?php echo htmlspecialchars($c['mobile']); ?>', '<?php echo addslashes($c['name']); ?>')" class="px-2.5 py-1 bg-amber-100 hover:bg-amber-200 text-amber-900 font-bold rounded-lg text-xs transition cursor-pointer" title="View Cash Memo History">
                                            <i class="fas fa-receipt mr-1"></i> Memos
                                        </button>
                                        <button onclick="openEditCustomerModal(<?php echo htmlspecialchars(json_encode($c)); ?>)" class="px-2 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-lg text-xs transition cursor-pointer" title="Edit Customer">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                endforeach; 
                            endif; 
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- Modal: Add Customer -->
    <div id="addCustomerModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl border border-amber-200 shadow-2xl w-full max-w-md overflow-hidden text-xs">
            <div class="px-5 py-4 bg-gradient-to-r from-[#011921] to-[#044e54] text-white flex justify-between items-center">
                <h3 class="text-sm font-bold flex items-center gap-2">
                    <i class="fas fa-user-plus text-amber-400"></i> Add New Customer
                </h3>
                <button onclick="closeModal('addCustomerModal')" class="text-white hover:text-gray-300 text-xl font-bold">&times;</button>
            </div>
            <form method="POST" class="p-5 space-y-3">
                <input type="hidden" name="add_customer" value="1">
                <div>
                    <label class="block font-bold text-gray-700 mb-1">Customer Name *</label>
                    <input type="text" name="name" required class="w-full bg-stone-50 border border-amber-300 rounded-lg p-2 text-xs font-semibold">
                </div>
                <div>
                    <label class="block font-bold text-gray-700 mb-1">Mobile Number *</label>
                    <input type="tel" name="mobile" required class="w-full bg-stone-50 border border-amber-300 rounded-lg p-2 text-xs font-mono font-semibold">
                </div>
                <div>
                    <label class="block font-bold text-gray-700 mb-1">Email Address</label>
                    <input type="email" name="email" class="w-full bg-stone-50 border border-amber-300 rounded-lg p-2 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-gray-700 mb-1">Address / Village</label>
                    <textarea name="address" rows="2" class="w-full bg-stone-50 border border-amber-300 rounded-lg p-2 text-xs"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
                    <button type="button" onclick="closeModal('addCustomerModal')" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg font-semibold text-gray-700">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-[#7a4e0a] hover:bg-[#5c3a07] text-white rounded-lg font-bold shadow">Save Customer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Customer -->
    <div id="editCustomerModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl border border-amber-200 shadow-2xl w-full max-w-md overflow-hidden text-xs">
            <div class="px-5 py-4 bg-gradient-to-r from-[#011921] to-[#044e54] text-white flex justify-between items-center">
                <h3 class="text-sm font-bold flex items-center gap-2">
                    <i class="fas fa-user-edit text-amber-400"></i> Edit Customer
                </h3>
                <button onclick="closeModal('editCustomerModal')" class="text-white hover:text-gray-300 text-xl font-bold">&times;</button>
            </div>
            <form method="POST" class="p-5 space-y-3">
                <input type="hidden" name="update_customer" value="1">
                <input type="hidden" name="customer_id" id="editCustomerId">
                <div>
                    <label class="block font-bold text-gray-700 mb-1">Customer Name *</label>
                    <input type="text" name="name" id="editCustomerName" required class="w-full bg-stone-50 border border-amber-300 rounded-lg p-2 text-xs font-semibold">
                </div>
                <div>
                    <label class="block font-bold text-gray-700 mb-1">Mobile Number *</label>
                    <input type="tel" name="mobile" id="editCustomerMobile" required class="w-full bg-stone-50 border border-amber-300 rounded-lg p-2 text-xs font-mono font-semibold">
                </div>
                <div>
                    <label class="block font-bold text-gray-700 mb-1">Email Address</label>
                    <input type="email" name="email" id="editCustomerEmail" class="w-full bg-stone-50 border border-amber-300 rounded-lg p-2 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-gray-700 mb-1">Address / Village</label>
                    <textarea name="address" id="editCustomerAddress" rows="2" class="w-full bg-stone-50 border border-amber-300 rounded-lg p-2 text-xs"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
                    <button type="button" onclick="closeModal('editCustomerModal')" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg font-semibold text-gray-700">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-[#7a4e0a] hover:bg-[#5c3a07] text-white rounded-lg font-bold shadow">Update Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Customer Non-GST Order History -->
    <div id="ordersModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl border border-amber-200 shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden text-xs">
            <div class="px-5 py-4 bg-gradient-to-r from-[#011921] to-[#044e54] text-white flex justify-between items-center">
                <div>
                    <h3 class="text-sm font-bold flex items-center gap-2">
                        <i class="fas fa-file-invoice text-amber-400"></i> Cash Memo History
                    </h3>
                    <p class="text-[10px] text-amber-200 mt-0.5" id="modalCustomerTitle">Customer Records</p>
                </div>
                <button onclick="closeModal('ordersModal')" class="text-white hover:text-gray-300 text-xl font-bold">&times;</button>
            </div>
            
            <div class="p-5 overflow-y-auto flex-1 space-y-3" id="ordersContainer">
                <div class="text-center py-6 text-stone-400">
                    <i class="fas fa-spinner fa-spin text-lg mr-2"></i> Loading cash memos...
                </div>
            </div>

            <div class="px-5 py-3 bg-stone-50 border-t border-stone-200 flex justify-end">
                <button onclick="closeModal('ordersModal')" class="px-4 py-2 bg-stone-300 hover:bg-stone-400 rounded-lg font-semibold text-stone-800">Close</button>
            </div>
        </div>
    </div>

    <script>
        function openAddCustomerModal() {
            document.getElementById('addCustomerModal').classList.remove('hidden');
            document.getElementById('addCustomerModal').classList.add('flex');
        }

        function openEditCustomerModal(cust) {
            document.getElementById('editCustomerId').value = cust.id;
            document.getElementById('editCustomerName').value = cust.name;
            document.getElementById('editCustomerMobile').value = cust.mobile;
            document.getElementById('editCustomerEmail').value = cust.email || '';
            document.getElementById('editCustomerAddress').value = cust.address || '';
            document.getElementById('editCustomerModal').classList.remove('hidden');
            document.getElementById('editCustomerModal').classList.add('flex');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('flex');
            document.getElementById(modalId).classList.add('hidden');
        }

        function viewCustomerOrders(mobile, name) {
            document.getElementById('modalCustomerTitle').textContent = name + ' (' + mobile + ')';
            const container = document.getElementById('ordersContainer');
            container.innerHTML = '<div class="text-center py-6 text-stone-400"><i class="fas fa-spinner fa-spin text-lg mr-2"></i> Loading cash memos...</div>';
            
            document.getElementById('ordersModal').classList.remove('hidden');
            document.getElementById('ordersModal').classList.add('flex');

            fetch('customers.php?action=get_orders&mobile=' + encodeURIComponent(mobile))
                .then(res => res.json())
                .then(data => {
                    if(!data.success || !data.orders || data.orders.length === 0) {
                        container.innerHTML = '<p class="text-center py-6 text-stone-500 italic">No non-GST cash memos found for this customer.</p>';
                        return;
                    }
                    
                    let html = '';
                    data.orders.forEach(o => {
                        const isPaid = o.payment_status === 'paid';
                        const isPart = o.payment_status === 'part';
                        const badgeColor = isPaid ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : (isPart ? 'bg-amber-100 text-amber-800 border-amber-300' : 'bg-rose-100 text-rose-800 border-rose-300');
                        
                        let itemsHtml = '';
                        if(o.items && o.items.length > 0) {
                            o.items.forEach(it => {
                                itemsHtml += `
                                    <div class="flex justify-between items-center py-1 text-[11px] border-b border-stone-100 last:border-0">
                                        <span class="font-medium text-stone-800">${it.desc} ${it.weight ? `<strong class="text-amber-900">(${it.weight})</strong>` : ''}</span>
                                        <span class="font-mono text-stone-600">${it.qty} ${it.unit} &times; ₹${it.rate.toLocaleString('en-IN')} = <strong class="text-stone-900">₹${it.total.toLocaleString('en-IN')}</strong></span>
                                    </div>
                                `;
                            });
                        }

                        html += `
                            <div class="bg-stone-50 rounded-xl border border-stone-200 p-3.5 space-y-2">
                                <div class="flex justify-between items-center flex-wrap gap-2">
                                    <div class="flex items-center gap-2">
                                        <a href="../view_pdf.php?invoice_no=${encodeURIComponent(o.invoice_no)}" target="_blank" class="font-mono font-bold text-amber-800 hover:underline">
                                            <i class="fas fa-file-invoice mr-1"></i>${o.invoice_no}
                                        </a>
                                        <span class="text-[10px] text-stone-400">${new Date(o.created_at).toLocaleDateString('en-IN', {day:'numeric',month:'short',year:'numeric'})}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold border uppercase ${badgeColor}">${o.payment_status}</span>
                                        <a href="../view_pdf.php?invoice_no=${encodeURIComponent(o.invoice_no)}" target="_blank" class="px-2 py-1 bg-stone-200 hover:bg-stone-300 rounded text-[10px] font-bold text-stone-700">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </div>
                                </div>
                                <div class="bg-white rounded-lg p-2 border border-stone-200/80">
                                    ${itemsHtml || '<div class="text-stone-400 italic">No line items</div>'}
                                </div>
                                <div class="flex justify-between text-[11px] font-mono pt-1">
                                    <span>Total: <strong class="text-stone-900">₹${parseFloat(o.total_amount).toLocaleString('en-IN', {minimumFractionDigits:2})}</strong></span>
                                    <span>Paid: <strong class="text-emerald-700">₹${parseFloat(o.paid_amount).toLocaleString('en-IN', {minimumFractionDigits:2})}</strong></span>
                                    <span>Due: <strong class="${parseFloat(o.balance_amount) > 0 ? 'text-rose-700' : 'text-stone-500'}">₹${parseFloat(o.balance_amount).toLocaleString('en-IN', {minimumFractionDigits:2})}</strong></span>
                                </div>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                })
                .catch(err => {
                    container.innerHTML = '<p class="text-center py-6 text-rose-500">Error loading customer memos.</p>';
                });
        }
    </script>
</body>
</html>
