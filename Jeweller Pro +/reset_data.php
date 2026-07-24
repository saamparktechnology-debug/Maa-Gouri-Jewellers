<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

$is_logged_in = isset($_SESSION['user_id']);
if (!$is_logged_in) {
    header('Location: login.php');
    exit;
}

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset']) && $_POST['confirm_reset'] === 'RESET') {
    // Disable foreign key checks temporarily
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");

    $tables = [
        'invoice_items',
        'invoices',
        'products',
        'customers',
        'purchase_entries',
        'stock_metal',
        'sanchari_payments',
        'sanchari_redemptions',
        'sanchari_customers',
        'income',
        'expenses',
        'contacts',
        'due_list',
        'accounts',
    ];

    $errors = [];
    foreach ($tables as $table) {
        // Check if table exists before truncating
        $check = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
        if ($check && mysqli_num_rows($check) > 0) {
            if (!mysqli_query($conn, "TRUNCATE TABLE `$table`")) {
                $errors[] = "$table: " . mysqli_error($conn);
            }
        }
    }

    // Re-seed stock_metal rows
    foreach (['Gold', 'Silver', 'Diamond', 'Platinum'] as $m) {
        mysqli_query($conn, "INSERT IGNORE INTO stock_metal (material_type, qty_available) VALUES ('$m', 0)");
    }

    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");

    if (empty($errors)) {
        $success = true;
        $message = 'All business data has been reset to zero. User accounts are preserved.';
    } else {
        $message = 'Reset completed with some errors: ' . implode(', ', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset All Data | MAA GOURI JEWELLERS</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3 { font-family: 'Poppins', serif; }

        .sidebar { position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg, #011921 0%, #03373b 50%, #044e54 80%, #011921 100%);z-index:1000;display:flex;flex-direction:column;box-shadow:4px 0 24px rgba(0,0,0,0.25);overflow:hidden; }
        .sidebar-nav { flex:1;padding:10px 0;overflow-y:auto;overflow-x:hidden; }
        .sidebar-logo { padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,0.18);display:flex;align-items:center;gap:12px;flex-shrink:0; }
        .sidebar-logo img { width:44px;height:44px;object-fit:contain;border-radius:50%;background:rgba(255,255,255,0.1);padding:3px; }
        .sidebar-logo-text h2 { color:#fff;font-size:13px;font-weight:700;font-family:'Poppins',serif; }
        .sidebar-logo-text p { color:rgba(255,255,255,0.65);font-size:10px; }
        .sidebar-nav a { display:flex;align-items:center;gap:12px;padding:11px 20px;color:rgba(255,255,255,0.85);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;border-left:3px solid transparent; }
        .sidebar-nav a:hover { background:rgba(255,255,255,0.13);color:#fff;border-left-color:rgba(255,255,255,0.8); }
        .sidebar-nav a.active { background:rgba(255,255,255,0.22);color:#fff;border-left-color:#fff;font-weight:700; }
        .sidebar-nav a i { width:18px;text-align:center;font-size:14px; }
        .sidebar-section-label { padding:10px 20px 4px;color:rgba(255,255,255,0.45);font-size:9px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase; }
        .sidebar-divider { height:1px;background:rgba(255,255,255,0.12);margin:6px 16px; }
        .sidebar-user { padding:14px 16px 18px;border-top:1px solid rgba(255,255,255,0.18);background:rgba(0,0,0,0.12);flex-shrink:0; }
        .sidebar-user-info { display:flex;align-items:center;gap:10px;margin-bottom:12px; }
        .sidebar-user-info i { color:rgba(255,255,255,0.9);font-size:26px; }
        .sidebar-user-info .user-details p { color:#fff;font-size:12px;font-weight:600; }
        .sidebar-user-info .user-details span { color:rgba(255,255,255,0.55);font-size:10px; }
        .sidebar-logout { display:flex;align-items:center;justify-content:center;gap:8px;padding:9px 14px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;transition:background .2s;background:rgba(239,68,68,0.75);color:#fff;border:1px solid rgba(239,68,68,0.4); }
        .sidebar-logout:hover { background:#ef4444; }
        .page-wrapper { margin-left:240px;min-height:100vh;background:linear-gradient(135deg,#f8f1e4,#fdf6e3); }
        nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important; }

        .danger-card {
            background:#fff;
            border-radius:24px;
            border:2px solid #fca5a5;
            box-shadow:0 20px 60px rgba(239,68,68,0.15);
            padding:40px;
            max-width:640px;
            margin:0 auto;
        }

        .warning-banner {
            background:linear-gradient(135deg,#fef2f2,#fee2e2);
            border:1.5px solid #fca5a5;
            border-radius:16px;
            padding:20px;
            margin-bottom:28px;
        }

        .table-list {
            background:#fafaf9;
            border:1px solid rgba(214,139,22,0.15);
            border-radius:12px;
            padding:16px 20px;
            margin-bottom:24px;
        }

        .table-list li {
            display:flex;
            align-items:center;
            gap:8px;
            padding:5px 0;
            font-size:13px;
            color:#374151;
            border-bottom:1px solid rgba(0,0,0,0.04);
        }
        .table-list li:last-child { border-bottom:none; }

        .confirm-input {
            width:100%;
            padding:14px 18px;
            border-radius:14px;
            border:2px solid rgba(239,68,68,0.3);
            background:#fff9f9;
            color:#374151;
            font-size:15px;
            font-weight:600;
            text-align:center;
            letter-spacing:3px;
            outline:none;
            transition:border-color .2s, box-shadow .2s;
            margin-bottom:20px;
        }
        .confirm-input:focus {
            border-color:#ef4444;
            box-shadow:0 0 0 3px rgba(239,68,68,0.12);
        }

        .btn-reset {
            width:100%;
            background:linear-gradient(135deg,#7f1d1d,#ef4444);
            color:#fff;
            border:none;
            border-radius:50px;
            padding:16px 0;
            font-size:16px;
            font-weight:700;
            cursor:pointer;
            transition:all .3s;
            letter-spacing:.5px;
        }
        .btn-reset:hover {
            transform:scale(1.02);
            box-shadow:0 12px 35px rgba(239,68,68,0.4);
        }
        .btn-reset:disabled {
            background:#d1d5db;
            cursor:not-allowed;
            transform:none;
            box-shadow:none;
        }

        .success-card {
            background:linear-gradient(135deg,#d1fae5,#a7f3d0);
            border:2px solid #6ee7b7;
            border-radius:20px;
            padding:32px;
            text-align:center;
            max-width:500px;
            margin:0 auto;
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f8f1e4;">

<!-- Sidebar -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
        $found = false;
        foreach($logo_paths as $p){ if(file_exists($p)){ echo '<img src="'.$p.'" alt="Logo">'; $found=true; break; } }
        if(!$found) echo '<i class="fas fa-gem" style="color:#fff;font-size:30px;"></i>';
        ?>
        <div class="sidebar-logo-text"><h2>MAA GOURI JEWELLERS</h2><p>Premium Since 2026</p></div>
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
        <a href="purchase.php"><i class="fas fa-shopping-cart"></i> PURCHASE</a>
        <a href="contacts.php"><i class="fas fa-address-book"></i> CONTACTS</a>
        <a href="accounts.php"><i class="fas fa-calculator"></i> ACCOUNTS</a>
        <div class="sidebar-divider"></div>
        <a href="reset_data.php" class="active" style="color:#fca5a5;"><i class="fas fa-trash-alt"></i> RESET DATA</a>
    </nav>
    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <p><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></p>
                <span><?php echo htmlspecialchars($_SESSION['user_mobile'] ?? ''); ?></span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i> LOGOUT</a>
    </div>
</div>

<!-- Top Nav -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 py-3 flex justify-between items-center">
        <h1 style="color:#fff;font-family:'Poppins',serif;font-size:18px;font-weight:700;">
            <i class="fas fa-trash-alt mr-2"></i>Reset All Data
        </h1>
        <a href="index.php" style="color:#fff;font-size:13px;font-weight:600;text-decoration:none;">
            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
        </a>
    </div>
</nav>

<div class="page-wrapper">
    <div class="container mx-auto px-4 py-12">

        <?php if ($success): ?>
        <!-- SUCCESS -->
        <div class="success-card">
            <div style="font-size:64px;margin-bottom:16px;">✅</div>
            <h2 style="color:#065f46;font-size:22px;font-weight:700;margin-bottom:8px;">Data Reset Complete</h2>
            <p style="color:#047857;font-size:14px;margin-bottom:24px;"><?php echo htmlspecialchars($message); ?></p>
            <p style="color:#065f46;font-size:13px;margin-bottom:24px;">All counters now show ₹0.00 · All records cleared · User login preserved.</p>
            <a href="index.php" style="display:inline-block;background:linear-gradient(135deg,#065f46,#059669);color:#fff;padding:14px 36px;border-radius:50px;font-weight:700;text-decoration:none;font-size:14px;">
                <i class="fas fa-home mr-2"></i>Go to Dashboard
            </a>
        </div>

        <?php elseif (!empty($message)): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;border-radius:12px;padding:16px;max-width:640px;margin:0 auto 24px;font-size:14px;">
            <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
        </div>

        <?php else: ?>
        <!-- RESET FORM -->
        <div class="danger-card">
            <div style="text-align:center;margin-bottom:28px;">
                <div style="font-size:56px;margin-bottom:12px;">⚠️</div>
                <h2 style="color:#7f1d1d;font-size:24px;font-weight:700;margin-bottom:6px;">Reset All Business Data</h2>
                <p style="color:#6b7280;font-size:14px;">This will permanently erase all records listed below and cannot be undone.</p>
            </div>

            <div class="warning-banner">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                    <i class="fas fa-exclamation-triangle" style="color:#dc2626;font-size:16px;"></i>
                    <strong style="color:#7f1d1d;font-size:13px;">THE FOLLOWING DATA WILL BE PERMANENTLY DELETED:</strong>
                </div>
                <ul class="table-list">
                    <li><i class="fas fa-receipt" style="color:#d68b16;width:16px;"></i> All Invoices &amp; Invoice Items</li>
                    <li><i class="fas fa-boxes" style="color:#d68b16;width:16px;"></i> All Stock / Products</li>
                    <li><i class="fas fa-users" style="color:#d68b16;width:16px;"></i> All Customers</li>
                    <li><i class="fas fa-shopping-cart" style="color:#d68b16;width:16px;"></i> All Purchase Entries</li>
                    <li><i class="fas fa-chart-line" style="color:#d68b16;width:16px;"></i> All Income &amp; Expense Records</li>
                    <li><i class="fas fa-book" style="color:#d68b16;width:16px;"></i> All Sanchay Book (Payments, Redemptions, Customers)</li>
                    <li><i class="fas fa-layer-group" style="color:#d68b16;width:16px;"></i> Metal Stock Quantities (reset to 0)</li>
                </ul>
                <div style="background:#fff3cd;border-radius:8px;padding:10px 14px;font-size:12px;color:#78350f;margin-top:6px;">
                    <i class="fas fa-shield-alt mr-1"></i> <strong>User accounts &amp; login are NOT deleted.</strong> You will still be able to log in after reset.
                </div>
            </div>

            <form method="POST" onsubmit="return validateReset()">
                <div style="margin-bottom:16px;">
                    <label style="display:block;color:#374151;font-size:13px;font-weight:600;margin-bottom:8px;text-align:center;">
                        Type <strong style="color:#dc2626;letter-spacing:2px;">RESET</strong> in the box below to confirm:
                    </label>
                    <input
                        type="text"
                        name="confirm_reset"
                        id="confirmInput"
                        class="confirm-input"
                        placeholder="Type RESET here"
                        autocomplete="off"
                        oninput="toggleBtn()"
                    >
                </div>

                <button type="submit" class="btn-reset" id="resetBtn" disabled>
                    <i class="fas fa-trash-alt mr-2"></i>
                    RESET ALL DATA TO ZERO
                </button>

                <div style="text-align:center;margin-top:16px;">
                    <a href="index.php" style="color:#6b7280;font-size:13px;text-decoration:none;">
                        <i class="fas fa-times mr-1"></i> Cancel — Go Back to Dashboard
                    </a>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
function toggleBtn() {
    const val = document.getElementById('confirmInput').value.trim();
    document.getElementById('resetBtn').disabled = (val !== 'RESET');
}

function validateReset() {
    const val = document.getElementById('confirmInput').value.trim();
    if (val !== 'RESET') {
        alert('Please type RESET exactly to confirm.');
        return false;
    }
    return confirm('⚠️ FINAL WARNING: This will delete ALL business data permanently. This CANNOT be undone.\n\nAre you absolutely sure?');
}
</script>

</body>
</html>





