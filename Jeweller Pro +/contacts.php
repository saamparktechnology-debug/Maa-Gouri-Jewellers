<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$is_logged_in = true;

// ── Handle Add/Edit/Delete ───────────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $name    = trim($_POST['name'] ?? '');
    $mobile  = trim($_POST['mobile'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($_POST['action'] === 'save' && $name !== '' && $mobile !== '') {
        $n = mysqli_real_escape_string($conn, $name);
        $m = mysqli_real_escape_string($conn, $mobile);
        $e = mysqli_real_escape_string($conn, $email);
        $a = mysqli_real_escape_string($conn, $address);
        mysqli_query($conn, "INSERT INTO customers (name, mobile, address, email)
                              VALUES ('$n', '$m', '$a', '$e')
                              ON DUPLICATE KEY UPDATE name='$n', address='$a', email='$e'");
        $msg = 'saved';
    }

    if ($_POST['action'] === 'delete' && !empty($_POST['mobile'])) {
        $m = mysqli_real_escape_string($conn, $_POST['mobile']);
        mysqli_query($conn, "DELETE FROM customers WHERE mobile = '$m'");
        $msg = 'deleted';
    }

    header('Location: contacts.php?msg=' . $msg);
    exit;
}
$msg = $_GET['msg'] ?? '';

// ── Search ───────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $sql = "SELECT name, mobile, email, address FROM customers
            WHERE name LIKE '%$s%' OR mobile LIKE '%$s%' OR email LIKE '%$s%'
            ORDER BY name ASC";
} else {
    $sql = "SELECT name, mobile, email, address FROM customers ORDER BY name ASC";
}
$res = mysqli_query($conn, $sql);
if (!$res) die("Query Error: " . mysqli_error($conn));

$customers = [];
while ($row = mysqli_fetch_assoc($res)) {
    $customers[] = $row;
}
$total = count($customers);

$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>Contacts — MAA GOURI JEWELLERS</title>
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

.card-gold {
    background: #fff;
    border: 1px solid rgba(214,139,22,0.25);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(122,78,10,0.06);
}

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1050;
}

.modal-overlay.show { display: flex; }

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
        <a href="contacts.php" class="active"><i class="fas fa-address-book"></i> CONTACTS</a>
        <a href="accounts.php"><i class="fas fa-calculator"></i> ACCOUNTS</a>
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
                    <p class="text-xs text-amber-700 font-medium">Customer Contacts Directory</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="openModal()" class="px-4 py-2 text-xs font-bold text-white rounded-xl shadow-md flex items-center gap-2" style="background:linear-gradient(135deg, #7a4e0a 0%, #d68b16 100%);">
                <i class="fas fa-plus"></i> Add New Contact
            </button>
        </div>
    </header>

    <!-- Content Area -->
    <div class="p-4 sm:p-6 max-w-7xl mx-auto space-y-6">

        <!-- Page Heading Bar -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white p-5 rounded-2xl border border-amber-200/70 shadow-sm">
            <div>
                <h1 class="text-2xl font-bold text-amber-950 flex items-center gap-2">
                    <i class="fas fa-address-book text-amber-600"></i> Customer Contacts Directory
                </h1>
                <p class="text-xs text-gray-500 mt-1">Manage customer names, mobile numbers, email addresses &amp; billing locations</p>
            </div>

            <div class="flex items-center gap-2 font-bold text-amber-900 bg-amber-100/70 border border-amber-300 px-4 py-2 rounded-xl text-xs">
                <i class="fas fa-users text-amber-700"></i> Total Contacts: <?= $total ?>
            </div>
        </div>

        <?php if ($msg === 'saved'): ?>
        <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-300 text-emerald-800 text-xs font-bold flex items-center gap-2">
            <i class="fas fa-check-circle text-emerald-600 text-base"></i> Contact saved successfully.
        </div>
        <?php elseif ($msg === 'deleted'): ?>
        <div class="p-4 rounded-xl bg-amber-50 border border-amber-300 text-amber-800 text-xs font-bold flex items-center gap-2">
            <i class="fas fa-trash text-amber-600 text-base"></i> Contact deleted.
        </div>
        <?php endif; ?>

        <!-- Search Bar -->
        <div class="bg-white p-4 rounded-2xl border border-amber-200/70 shadow-sm">
            <form method="GET" class="flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-3.5 top-3 text-gray-400 text-sm"></i>
                    <input type="text" name="search" placeholder="Search by customer name, mobile or email address..." value="<?= htmlspecialchars($search) ?>" class="w-full pl-10 pr-4 py-2.5 text-xs rounded-xl border border-amber-300 bg-amber-50/50 text-gray-800 outline-none focus:ring-2 focus:ring-amber-500">
                </div>
                <button type="submit" class="px-5 py-2.5 text-xs font-bold text-white rounded-xl shadow-md flex items-center justify-center gap-2" style="background:linear-gradient(135deg, #7a4e0a 0%, #d68b16 100%);">
                    <i class="fas fa-search"></i> Search Contacts
                </button>
                <?php if ($search !== ''): ?>
                <a href="contacts.php" class="px-4 py-2.5 text-xs font-bold text-gray-600 bg-gray-100 rounded-xl hover:bg-gray-200 transition flex items-center justify-center">
                    Clear Search
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Contacts Table -->
        <div class="card-gold overflow-hidden">
            <div class="px-6 py-4 flex items-center justify-between border-b border-amber-200/60" style="background:linear-gradient(135deg, #7a4e0a 0%, #d68b16 100%);">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fas fa-list"></i> Customer Contact Directory
                </h3>
                <span class="text-xs font-bold px-3 py-1 rounded-full bg-white/20 text-white"><?= $total ?> Contacts Found</span>
            </div>

            <?php if (empty($customers)): ?>
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-address-book text-4xl mb-3 block text-amber-200"></i>
                <div class="text-sm font-semibold text-gray-600">No contacts found</div>
                <div class="text-xs text-gray-400 mt-1"><?= $search !== '' ? 'Try a different search term' : 'Click "Add New Contact" to create your first entry' ?></div>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-amber-100/50 text-amber-900 text-xs uppercase font-bold border-b border-amber-200/70">
                            <th class="px-5 py-3">Customer Name</th>
                            <th class="px-5 py-3">Mobile Number</th>
                            <th class="px-5 py-3">Email Address</th>
                            <th class="px-5 py-3">Billing Address</th>
                            <th class="px-5 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100">
                    <?php foreach ($customers as $c):
                        $initial = strtoupper(substr(trim($c['name']) ?: '?', 0, 1));
                    ?>
                    <tr class="hover:bg-amber-50/50 transition">
                        <td class="px-5 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full font-bold text-white text-xs flex items-center justify-center shadow-sm" style="background:linear-gradient(135deg, #7a4e0a 0%, #d68b16 100%);">
                                    <?= htmlspecialchars($initial) ?>
                                </div>
                                <span class="text-xs font-bold text-gray-900"><?= htmlspecialchars($c['name']) ?></span>
                            </div>
                        </td>
                        <td class="px-5 py-3.5">
                            <a href="tel:<?= htmlspecialchars($c['mobile']) ?>" class="text-xs font-bold text-amber-900 hover:text-amber-700 flex items-center gap-1.5">
                                <i class="fas fa-phone-alt text-amber-600"></i> <?= htmlspecialchars($c['mobile']) ?>
                            </a>
                        </td>
                        <td class="px-5 py-3.5 text-xs text-gray-600">
                            <?= $c['email'] ? '<a href="mailto:'.htmlspecialchars($c['email']).'" class="text-blue-600 hover:underline flex items-center gap-1.5"><i class="fas fa-envelope text-blue-500"></i> '.htmlspecialchars($c['email']).'</a>' : '<span class="text-gray-300">—</span>' ?>
                        </td>
                        <td class="px-5 py-3.5 text-xs text-gray-600 max-w-xs">
                            <?= $c['address'] ? htmlspecialchars($c['address']) : '<span class="text-gray-300">—</span>' ?>
                        </td>
                        <td class="px-5 py-3.5 text-center whitespace-nowrap">
                            <button onclick='editModal(<?= json_encode($c) ?>)' class="px-3 py-1 text-xs font-bold text-blue-900 bg-blue-100 rounded-lg hover:bg-blue-200 transition mr-1">
                                <i class="fas fa-edit mr-1"></i> Edit
                            </button>
                            <button onclick="deleteContact('<?= htmlspecialchars(addslashes($c['mobile'])) ?>','<?= htmlspecialchars(addslashes($c['name'])) ?>')" class="px-3 py-1 text-xs font-bold text-rose-900 bg-rose-100 rounded-lg hover:bg-rose-200 transition">
                                <i class="fas fa-trash-alt mr-1"></i> Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
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

<!-- Add / Edit Contact Modal -->
<div class="modal-overlay" id="modalOverlay">
    <div class="bg-white rounded-2xl max-w-md w-full mx-4 shadow-2xl overflow-hidden border border-amber-200">
        <div class="px-6 py-4 flex items-center justify-between border-b border-amber-200" style="background:linear-gradient(135deg, #7a4e0a 0%, #d68b16 100%);">
            <h3 class="text-lg font-bold text-white flex items-center gap-2" id="modalTitle">
                <i class="fas fa-user-plus"></i> Add Contact
            </h3>
            <button onclick="closeModal()" class="text-white hover:text-amber-200 text-lg font-bold">✕</button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="original_mobile" id="originalMobile">
            <div>
                <label class="block text-xs font-bold text-amber-900 mb-1">Customer Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="fName" required placeholder="Enter customer full name" class="w-full px-3.5 py-2 text-xs rounded-xl border border-amber-300 bg-amber-50/50 text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <div>
                <label class="block text-xs font-bold text-amber-900 mb-1">Mobile Number <span class="text-red-500">*</span></label>
                <input type="tel" name="mobile" id="fMobile" required placeholder="10-digit mobile number" class="w-full px-3.5 py-2 text-xs rounded-xl border border-amber-300 bg-amber-50/50 text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <div>
                <label class="block text-xs font-bold text-amber-900 mb-1">Email Address</label>
                <input type="email" name="email" id="fEmail" placeholder="customer@example.com" class="w-full px-3.5 py-2 text-xs rounded-xl border border-amber-300 bg-amber-50/50 text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <div>
                <label class="block text-xs font-bold text-amber-900 mb-1">Billing Address</label>
                <textarea name="address" id="fAddress" rows="3" placeholder="Enter customer address..." class="w-full px-3.5 py-2 text-xs rounded-xl border border-amber-300 bg-amber-50/50 text-gray-900 outline-none focus:ring-2 focus:ring-amber-500"></textarea>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="flex-1 py-2.5 text-xs font-bold text-gray-600 bg-gray-100 rounded-xl hover:bg-gray-200 transition">
                    Cancel
                </button>
                <button type="submit" class="flex-1 py-2.5 text-xs font-bold text-white rounded-xl shadow-md" style="background:linear-gradient(135deg, #7a4e0a 0%, #d68b16 100%);">
                    💾 Save Contact
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden Delete Form -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="mobile" id="deleteMobile">
</form>

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
function openModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus mr-1"></i> Add Contact';
    document.getElementById('fName').value = '';
    document.getElementById('fMobile').value = '';
    document.getElementById('fEmail').value = '';
    document.getElementById('fAddress').value = '';
    document.getElementById('modalOverlay').classList.add('show');
}
function editModal(c) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-edit mr-1"></i> Edit Contact';
    document.getElementById('fName').value = c.name || '';
    document.getElementById('fMobile').value = c.mobile || '';
    document.getElementById('fEmail').value = c.email || '';
    document.getElementById('fAddress').value = c.address || '';
    document.getElementById('modalOverlay').classList.add('show');
}
function closeModal() {
    document.getElementById('modalOverlay').classList.remove('show');
}
function deleteContact(mobile, name) {
    if(confirm('Are you sure you want to delete contact "' + name + '"?')) {
        document.getElementById('deleteMobile').value = mobile;
        document.getElementById('deleteForm').submit();
    }
}
document.getElementById('modalOverlay').addEventListener('click', e => {
    if(e.target.id === 'modalOverlay') closeModal();
});
</script>
</body>
</html>


