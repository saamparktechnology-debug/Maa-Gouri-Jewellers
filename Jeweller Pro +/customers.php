<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// AJAX: Get customer order history (GST Invoices Only)
if(isset($_GET['action']) && $_GET['action'] === 'get_orders') {
    header('Content-Type: application/json');
    $mobile = mysqli_real_escape_string($conn, trim($_GET['mobile'] ?? ''));
    if(empty($mobile)) { echo json_encode(['success'=>false]); exit(); }

    $inv_res = mysqli_query($conn, "SELECT invoice_no, total_amount, paid_amount, balance_amount, payment_status, payment_method, gst_type, created_at FROM invoices WHERE customer_mobile='$mobile' AND (gst_type LIKE 'gst%' OR gst_amount > 0) ORDER BY created_at DESC");
    $orders = [];
    if($inv_res) {
        while($inv = mysqli_fetch_assoc($inv_res)) {
            $inv_no = mysqli_real_escape_string($conn, $inv['invoice_no']);
            $items_res = mysqli_query($conn, "SELECT ii.quantity, ii.price, ii.total, ii.hsn_code,
                COALESCE(ii.product_name, p.name, '') as pname,
                COALESCE(p.item_name, '') as item_name,
                COALESCE(p.category, '') as category
                FROM invoice_items ii
                LEFT JOIN products p ON ii.product_id = p.id
                WHERE ii.invoice_id = (SELECT id FROM invoices WHERE invoice_no='$inv_no' LIMIT 1)");
            $items = [];
            if($items_res) {
                while($it = mysqli_fetch_assoc($items_res)) {
                    $cat  = $it['category'] ?: '';
                    $iname = $it['item_name'] ?: '';
                    $pname = $it['pname'] ?: '';
                    $desc = trim(($cat ? $cat . '  ' : '') . ($iname ?: $pname));
                    if(empty($desc)) $desc = 'Item';
                    $items[] = [
                        'desc'  => $desc,
                        'qty'   => $it['quantity'],
                        'rate'  => $it['price'],
                        'total' => $it['total'],
                        'hsn'   => (!empty($it['hsn_code']) && $it['hsn_code'] !== '0') ? $it['hsn_code'] : '7113'
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

// AJAX: Bulk Import Customers from Excel/JSON
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'import_customers') {
    header('Content-Type: application/json');
    @ini_set('max_execution_time', '300');
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);
    if(!$data || !isset($data['rows']) || !is_array($data['rows'])) {
        echo json_encode(['success' => false, 'message' => 'No valid customer rows received.']);
        exit();
    }

    $update_existing = !empty($data['update_existing']);
    $rows = $data['rows'];
    $added = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    $chk_gst = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'gst_number'");
    if($chk_gst && mysqli_num_rows($chk_gst) == 0) {
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN gst_number VARCHAR(20) DEFAULT '' AFTER address");
    }

    // Collect mobiles to fetch existing records in one bulk query
    $mobiles_to_check = [];
    foreach($rows as $r) {
        $m = preg_replace('/[^0-9]/', '', trim((string)($r['mobile'] ?? '')));
        if(strlen($m) === 12 && substr($m, 0, 2) === '91') $m = substr($m, 2);
        if(strlen($m) === 11 && substr($m, 0, 1) === '0')  $m = substr($m, 1);
        if(strlen($m) >= 10) {
            $mobiles_to_check[] = "'" . mysqli_real_escape_string($conn, $m) . "'";
        }
    }

    $existing_map = [];
    if(!empty($mobiles_to_check)) {
        $unique_mobiles = array_unique($mobiles_to_check);
        $chk_res = mysqli_query($conn, "SELECT id, mobile FROM customers WHERE mobile IN (" . implode(',', $unique_mobiles) . ")");
        if($chk_res) {
            while($crow = mysqli_fetch_assoc($chk_res)) {
                $existing_map[$crow['mobile']] = intval($crow['id']);
            }
        }
    }

    mysqli_begin_transaction($conn);
    try {
        foreach($rows as $idx => $r) {
            $name    = trim($r['name'] ?? '');
            $mobile  = preg_replace('/[^0-9]/', '', trim((string)($r['mobile'] ?? '')));
            if(strlen($mobile) === 12 && substr($mobile, 0, 2) === '91') {
                $mobile = substr($mobile, 2);
            }
            if(strlen($mobile) === 11 && substr($mobile, 0, 1) === '0') {
                $mobile = substr($mobile, 1);
            }
            $email   = trim($r['email'] ?? '');
            $address = trim($r['address'] ?? '');
            $gst     = strtoupper(trim($r['gst_number'] ?? ($r['gst'] ?? '')));

            if(empty($name) || empty($mobile) || strlen($mobile) < 10) {
                $skipped++;
                continue;
            }

            $e_name    = mysqli_real_escape_string($conn, $name);
            $e_mobile  = mysqli_real_escape_string($conn, $mobile);
            $e_email   = mysqli_real_escape_string($conn, $email);
            $e_address = mysqli_real_escape_string($conn, $address);
            $e_gst     = mysqli_real_escape_string($conn, $gst);

            if(isset($existing_map[$mobile])) {
                if($update_existing) {
                    $exist_id = $existing_map[$mobile];
                    $update_fields = ["name = '$e_name'"];
                    if(!empty($email))   $update_fields[] = "email = '$e_email'";
                    if(!empty($address)) $update_fields[] = "address = '$e_address'";
                    if(!empty($gst))     $update_fields[] = "gst_number = '$e_gst'";
                    $u_sql = "UPDATE customers SET " . implode(', ', $update_fields) . " WHERE id = $exist_id";
                    if(mysqli_query($conn, $u_sql)) {
                        $updated++;
                    } else {
                        $errors[] = "Error updating $name ($mobile): " . mysqli_error($conn);
                    }
                } else {
                    $skipped++;
                }
            } else {
                $ins_sql = "INSERT INTO customers (name, mobile, email, address, gst_number, created_at)
                            VALUES ('$e_name', '$e_mobile', '$e_email', '$e_address', '$e_gst', NOW())";
                if(mysqli_query($conn, $ins_sql)) {
                    $added++;
                    $existing_map[$mobile] = mysqli_insert_id($conn);
                } else {
                    $errors[] = "Error inserting $name ($mobile): " . mysqli_error($conn);
                }
            }
        }
        mysqli_commit($conn);
    } catch(Exception $ex) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'added' => $added,
        'updated' => $updated,
        'skipped' => $skipped,
        'total' => count($rows),
        'errors' => array_slice($errors, 0, 10)
    ]);
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
        echo "<script>alert(' Customer with this mobile number already exists!'); window.location.href='customers.php';<\/script>";
        exit();
    }

    $gst = mysqli_real_escape_string($conn, strtoupper(trim($_POST['gst'] ?? '')));

    $chk_gst = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'gst_number'");
    if(mysqli_num_rows($chk_gst) == 0) {
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN gst_number VARCHAR(20) DEFAULT '' AFTER address");
    }

    if(mysqli_query($conn, "INSERT INTO customers (name, mobile, email, address, gst_number, created_at) VALUES ('$name', '$mobile', '$email', '$address', '$gst', NOW())")) {
        echo "<script>alert(' Customer added successfully!'); window.location.href='customers.php';<\/script>";
        exit();
    } else {
        $error_msg = "Error adding customer: " . mysqli_error($conn);
    }
}

// Handle Update Customer
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_customer'])) {
    $id      = mysqli_real_escape_string($conn, $_POST['customer_id']);
    $name    = mysqli_real_escape_string($conn, $_POST['name']);
    $mobile  = mysqli_real_escape_string($conn, $_POST['mobile']);
    $email   = mysqli_real_escape_string($conn, $_POST['email']);
    $address = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
    $gst     = mysqli_real_escape_string($conn, strtoupper(trim($_POST['gst'] ?? '')));

    if(mysqli_query($conn, "UPDATE customers SET name='$name', mobile='$mobile', email='$email', address='$address', gst_number='$gst' WHERE id=$id")) {
        echo "<script>alert(' Customer updated successfully!'); window.location.href='customers.php';</script>";
        exit();
    } else {
        $error_msg = "Error updating customer: " . mysqli_error($conn);
    }
}

// Handle Delete Customer
if(isset($_GET['delete_id'])) {
    $delete_id = mysqli_real_escape_string($conn, $_GET['delete_id']);
    $result = mysqli_query($conn, "SELECT mobile FROM customers WHERE id = $delete_id");
    if($result && mysqli_num_rows($result) > 0) {
        $customer = mysqli_fetch_assoc($result);
        mysqli_query($conn, "DELETE FROM invoices WHERE customer_mobile = '{$customer['mobile']}'");
    }
    mysqli_query($conn, "DELETE FROM customers WHERE id = $delete_id");
    echo "<script>alert(' Customer deleted successfully!'); window.location.href='customers.php';</script>";
    exit();
}

$filter      = isset($_GET['filter'])      ? $_GET['filter']      : 'all';
$search      = isset($_GET['search'])      ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : '';

$query = "SELECT c.* FROM customers c WHERE 1=1
    AND NOT (
        EXISTS (SELECT 1 FROM invoices i_ng WHERE i_ng.customer_mobile = c.mobile AND (i_ng.gst_type NOT LIKE 'gst%' AND (i_ng.gst_amount IS NULL OR i_ng.gst_amount = 0)))
        AND NOT EXISTS (SELECT 1 FROM invoices i_g WHERE i_g.customer_mobile = c.mobile AND (i_g.gst_type LIKE 'gst%' OR i_g.gst_amount > 0))
    )";
if($filter == 'last_month')    $query .= " AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
elseif($filter == 'this_month') $query .= " AND MONTH(c.created_at)=MONTH(CURRENT_DATE()) AND YEAR(c.created_at)=YEAR(CURRENT_DATE())";
elseif($filter == 'this_year')  $query .= " AND YEAR(c.created_at)=YEAR(CURRENT_DATE())";
if(!empty($search))      $query .= " AND (c.name LIKE '%$search%' OR c.mobile LIKE '%$search%' OR c.email LIKE '%$search%' OR c.id LIKE '%$search%')";
if(!empty($date_filter)) $query .= " AND DATE(c.created_at)='$date_filter'";
$query .= " ORDER BY c.created_at DESC";

$customers_result = mysqli_query($conn, $query);
$total_customers  = $customers_result ? mysqli_num_rows($customers_result) : 0;
$customers = [];
if($customers_result) {
    while($row = mysqli_fetch_assoc($customers_result)) {
        $mob = $row['mobile'];
        $ocr = mysqli_query($conn, "SELECT COUNT(*) as c, COALESCE(SUM(total_amount), 0) as t, MAX(created_at) as l FROM invoices WHERE customer_mobile='$mob'");
        $stats = $ocr ? mysqli_fetch_assoc($ocr) : null;
        $row['order_count'] = intval($stats['c'] ?? 0);
        $row['total_amt'] = floatval($stats['t'] ?? 0);
        $row['last_order'] = $stats['l'] ?? null;
        $customers[] = $row;
    }
}

$error_message = isset($error_msg) ? $error_msg : '';
$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png','radhey shyam logo.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Customers - MAA GOURI JEWELLERS</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <script src="assets/js/xlsx.full.min.js"></script>
    <script>if(typeof XLSX === 'undefined'){document.write('<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"><\/script>');}</script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Poppins', sans-serif; font-weight: 700; }

        /* ========== SIDEBAR ========== */
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
            width: 44px; height: 44px; object-fit: contain;
            border-radius: 50%; background: rgba(255,255,255,0.1); padding: 3px; flex-shrink: 0;
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
            color: rgba(255,255,255,0.45);
            font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
            position: sticky;
            top: 0;
            background: #011921; color: #f5c842;
            z-index: 10;
        }

        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 20px;
            color: rgba(255,255,255,0.85);
            text-decoration: none; font-size: 13px; font-weight: 500;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
            letter-spacing: 0.3px; position: relative;
        }
        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.13); color: #fff;
            border-left-color: rgba(255,255,255,0.8); padding-left: 26px;
        }
        .sidebar-nav a.active {
            background: rgba(255,255,255,0.22); color: #fff;
            border-left-color: #fff; font-weight: 700;
        }
        .sidebar-nav a.active::after {
            content: ''; position: absolute; right: 0; top: 50%;
            transform: translateY(-50%); width: 4px; height: 60%;
            background: #fff; border-radius: 4px 0 0 4px;
        }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; opacity: 0.9; }

        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }

        .sidebar-user {
            padding: 14px 16px 18px;
            border-top: 1px solid rgba(255,255,255,0.18);
            background: rgba(0,0,0,0.12); flex-shrink: 0;
        }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }

        .sidebar-logout {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 9px 14px;
            background: rgba(239,68,68,0.75); color: #fff;
            border-radius: 8px; font-size: 12px; font-weight: 600;
            text-decoration: none; transition: background 0.2s;
            border: 1px solid rgba(239,68,68,0.4);
        }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }

        /* Sidebar overlay (mobile) */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px);
        }
        .sidebar-overlay.active { display: block; }

        /* ========== LAYOUT ========== */
        .page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; }

        /* ========== NAVBAR ========== */
        nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important; }

        /* ========== BURGER ========== */
        .burger-menu { width: 28px; height: 20px; position: relative; cursor: pointer; }
        .burger-menu span {
            display: block; position: absolute;
            height: 3px; width: 100%; background: #fff; border-radius: 3px; transition: all 0.3s ease;
        }
        .burger-menu span:nth-child(1) { top: 0; }
        .burger-menu span:nth-child(2) { top: 9px; }
        .burger-menu span:nth-child(3) { top: 18px; }
        .burger-menu.active span:nth-child(1) { top: 9px; transform: rotate(135deg); }
        .burger-menu.active span:nth-child(2) { opacity: 0; left: -20px; }
        .burger-menu.active span:nth-child(3) { top: 9px; transform: rotate(-135deg); }

        /* ========== MOBILE ========== */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .page-wrapper { margin-left: 0 !important; }
            .mobile-burger { display: block !important; }
            nav.nav-gold { margin-left: 0 !important; }
        }
        @media (min-width: 769px) { .mobile-burger { display: none !important; } }

        /* ========== PAGE STYLES ========== */
        body { background: #F5F5F5; margin: 0; padding: 0; }

        .page-heading {
            background: linear-gradient(135deg, #fdf6e3, #f5ead0);
            border-bottom: 2px solid rgba(181,115,14,0.2);
            padding: 20px 28px;
        }
        .page-heading h1 { color: #800020; font-size: 1.6rem; }
        .page-heading p  { color: #7a4e0a; font-size: 13px; margin-top: 2px; }

        .jewel-card {
            background: #fff;
            border: 1px solid rgba(181,115,14,0.2);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(181,115,14,0.08);
        }

        .jewel-input {
            background: #fdf6e3;
            border: 1px solid rgba(181,115,14,0.3);
            color: #4a3000;
            border-radius: 10px;
            padding: 8px 12px;
            transition: all 0.3s ease;
            width: 100%;
            font-size: 13px;
        }
        .jewel-input:focus {
            border-color: #d68b16;
            box-shadow: 0 0 0 3px rgba(214,139,22,0.15);
            outline: none;
        }
        .jewel-input::placeholder { color: rgba(122,78,10,0.4); }

        /* Table */
        .jewel-table { width: 100%; border-collapse: collapse; }
        .jewel-table th {
            background: linear-gradient(135deg, #7a4e0a, #d68b16);
            color: #fff; font-weight: 600;
            padding: 12px 10px; font-size: 12px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .jewel-table td {
            border-bottom: 1px solid rgba(181,115,14,0.1);
            padding: 10px 10px; color: #3a2800; font-size: 13px;
        }
        .jewel-table tbody tr:hover { background: #fdf6e3; }

        /* Buttons */
        .btn-jewel {
            background: linear-gradient(135deg, #800020, #d68b16);
            border: none; border-radius: 50px;
            padding: 9px 22px; font-weight: 700; color: #fff;
            transition: all 0.3s ease; display: inline-flex; align-items: center;
            gap: 6px; text-decoration: none; font-size: 13px; cursor: pointer;
        }
        .btn-jewel:hover { transform: scale(1.04); box-shadow: 0 8px 24px rgba(214,139,22,0.35); color: #fff; }

        .btn-excel {
            background: linear-gradient(135deg, #16a34a, #15803d);
            border: none; border-radius: 50px;
            padding: 9px 20px; font-weight: 700; color: #fff;
            transition: all 0.3s ease; display: inline-flex; align-items: center;
            gap: 6px; text-decoration: none; font-size: 13px; cursor: pointer;
            box-shadow: 0 4px 14px rgba(22, 163, 74, 0.3);
        }
        .btn-excel:hover { transform: scale(1.04); box-shadow: 0 8px 24px rgba(22, 163, 74, 0.45); color: #fff; }

        .btn-import {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            border: none; border-radius: 50px;
            padding: 9px 20px; font-weight: 700; color: #fff;
            transition: all 0.3s ease; display: inline-flex; align-items: center;
            gap: 6px; text-decoration: none; font-size: 13px; cursor: pointer;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.3);
        }
        .btn-import:hover { transform: scale(1.04); box-shadow: 0 8px 24px rgba(2, 132, 199, 0.45); color: #fff; }

        .btn-filter {
            padding: 7px 16px; border-radius: 8px; font-size: 12px; font-weight: 600;
            cursor: pointer; text-decoration: none; transition: all 0.2s;
            border: 1.5px solid rgba(181,115,14,0.4); color: #7a4e0a; background: #fdf6e3;
        }
        .btn-filter:hover, .btn-filter.active {
            background: linear-gradient(135deg, #800020, #d68b16);
            color: #fff; border-color: transparent;
        }

        .btn-edit {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff; border: none; border-radius: 8px;
            padding: 5px 12px; font-size: 11px; cursor: pointer; transition: all 0.2s;
        }
        .btn-edit:hover { transform: scale(1.04); box-shadow: 0 4px 12px rgba(59,130,246,0.4); }

        .btn-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff; border: none; border-radius: 8px;
            padding: 5px 12px; font-size: 11px; cursor: pointer; transition: all 0.2s;
            text-decoration: none; display: inline-block;
        }
        .btn-delete:hover { transform: scale(1.04); box-shadow: 0 4px 12px rgba(239,68,68,0.4); color: #fff; }

        /* Modal */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 2000; display: none;
            align-items: center; justify-content: center; padding: 16px;
        }
        .modal-overlay.flex { display: flex; }
        .modal-content {
            background: linear-gradient(145deg, #fdf6e3, #fff);
            border: 1px solid rgba(181,115,14,0.35);
            border-radius: 20px; padding: 28px;
            width: 100%; max-width: 460px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal-content h3 { color: #800020; margin-bottom: 18px; }
        .modal-content label { color: #7a4e0a; display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; }

        /* Footer */
        .footer-jewel {
            background: linear-gradient(0deg, #f5e6c8, #fdf6e3);
            border-top: 2px solid #d68b16;
            padding: 20px; margin-top: 40px; text-align: center;
        }

        @media (max-width: 640px) {
            .filter-wrap { flex-direction: column; }
            .table-wrap { overflow-x: auto; }
            .jewel-table { min-width: 640px; }
        }
    </style>
    <link rel="stylesheet" href="mobile.css">
</head>
<body>

<script>
    function createJewelSparkles() {
        const colors = ['#d68b16','#b5730e','#800020','#c9a96e','#f5c842'];
        document.querySelectorAll('.jewel-sparkle').forEach(s => s.remove());
        for(let i = 0; i < 50; i++) {
            const s = document.createElement('div');
            s.className = 'jewel-sparkle';
            s.style.left = Math.random() * 100 + '%';
            s.style.animationDelay = Math.random() * 8 + 's';
            s.style.animationDuration = (4 + Math.random() * 6) + 's';
            const sz = (Math.random() * 7 + 2) + 'px';
            s.style.width = sz; s.style.height = sz;
            s.style.background = `radial-gradient(circle, ${colors[Math.floor(Math.random()*colors.length)]}, transparent)`;
            document.body.appendChild(s);
        }
    }

    const texts = ["MAA GOURI JEWELLERS"];
    let textIndex = 0, charIndex = 0, isDeleting = false, typingSpeed = 100;

    function typeEffect() {
        const el = document.getElementById('typingText');
        if(!el) return;
        const cur = texts[textIndex];
        if(isDeleting) { el.innerHTML = cur.substring(0, charIndex - 1); charIndex--; typingSpeed = 50; }
        else { el.innerHTML = cur.substring(0, charIndex + 1); charIndex++; typingSpeed = 100; }
        if(!isDeleting && charIndex === cur.length) { isDeleting = true; typingSpeed = 2000; }
        else if(isDeleting && charIndex === 0) { isDeleting = false; textIndex = 0; typingSpeed = 500; }
        setTimeout(typeEffect, typingSpeed);
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('mainSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const burger  = document.getElementById('burgerMenu');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        burger.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    function closeSidebar() {
        document.getElementById('mainSidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        document.getElementById('burgerMenu').classList.remove('active');
        document.body.style.overflow = '';
    }

    window.addEventListener('load', function() {
        const isReload = performance.getEntriesByType("navigation")[0]?.type === "reload";
        const hasVisited = sessionStorage.getItem('visited');

        if (!hasVisited || isReload) {
            sessionStorage.setItem('visited', 'true');
            createJewelSparkles();
            setTimeout(typeEffect, 600);
            setTimeout(function() {
                const ov = document.getElementById('loadingOverlay');
                if(ov) { ov.style.opacity = '0'; ov.style.visibility = 'hidden'; setTimeout(()=>ov.style.display='none', 500); }
            }, 2000);
        } else {
            const ov = document.getElementById('loadingOverlay');
            if(ov) { ov.style.display = 'none'; }
            // Animate the content wrapper, NOT body (body transform breaks position:fixed sidebar)
            const pw = document.querySelector('.page-wrapper');
            if(pw) { pw.style.animation = 'slideInFromRightGlobal 0.3s ease-out forwards'; }
        }
    });
</script>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;overflow:hidden;transition:opacity 0.6s ease,visibility 0.6s ease;background:radial-gradient(ellipse at 50% 60%, #1a0a00 0%, #0d0500 100%);">

    <!-- Scanlines texture -->
    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>

    <!-- Corner ornaments -->
    <!-- <div style="position:absolute;top:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite;"></div>
    <div style="position:absolute;top:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 1s;"></div>
    <div style="position:absolute;bottom:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 2s;"></div>
    <div style="position:absolute;bottom:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 3s;"></div> -->

    <!-- Stars / sparkles container -->
    <div id="loaderStars" style="position:absolute;inset:0;pointer-events:none;z-index:2;"></div>

    <!-- Expanding rings container -->
    <div id="loaderRings" style="position:absolute;inset:0;pointer-events:none;z-index:2;display:flex;align-items:center;justify-content:center;"></div>

    <!-- Center content -->
    <div style="position:relative;z-index:10;text-align:center;">

        <!-- Gem with halos -->
                <div style="position:relative;width:120px;height:120px;margin:0 auto 24px;display:flex;align-items:center;justify-content:center;">
            
            
            <div style="width:120px;height:120px;background:transparent;animation:gemGlowPulse 1.5s ease-in-out infinite;">
                <img src="assets/images/moti-removebg-preview.png" alt="MAA GOURI JEWELLERS Logo" style="width:100%;height:100%;object-fit:contain;display:block;">
            </div>
        </div>

        <!-- Dots -->
        <div style="display:flex;gap:9px;justify-content:center;">
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite;"></div>
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite 0.2s;"></div>
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite 0.4s;"></div>
        </div>
    </div>

    <style>
        @keyframes ornFloat { 0%,100%{transform:rotate(0deg) scale(1);opacity:0.15} 50%{transform:rotate(20deg) scale(1.15);opacity:0.28} }
        @keyframes haloPulse { 0%,100%{opacity:0.3;transform:scale(1)} 50%{opacity:1;transform:scale(1.1)} }
        @keyframes gemGlowPulse { 0%,100%{filter:drop-shadow(0 0 8px #d68b16)} 50%{filter:drop-shadow(0 0 22px #ff9900)} }
        @keyframes titleGold { from{color:#d68b16} to{color:#f5c842} }
        @keyframes barSlide { 0%{transform:translateX(-100%)} 100%{transform:translateX(480%)} }
        @keyframes dotBounce { 0%,100%{opacity:0.3;transform:scale(0.7)} 50%{opacity:1;transform:scale(1.2)} }
        @keyframes starFade { 0%{opacity:0;transform:scale(0)} 50%{opacity:1} 100%{opacity:0;transform:scale(1)} }
        @keyframes ringExpand { 0%{opacity:0.7;transform:scale(0.2)} 100%{opacity:0;transform:scale(2)} }
    </style>

</div>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ========== SIDEBAR ========== -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) { echo '<img src="'.$path.'" alt="Logo">'; $logo_found = true; break; }
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
        <a href="index.php"><i class="fas fa-home"></i> DASHBOARD</a>
        <a href="billing.php"><i class="fas fa-receipt"></i> BILLING</a>
        <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
        <a href="customers.php" class="active"><i class="fas fa-users"></i> CUSTOMERS</a>
        <a href="transfer.php"><i class="fas fa-exchange-alt"></i> TRANSFER</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
        <a href="due_list.php"><i class="fas fa-hourglass-half"></i> DUE LIST</a>
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="product_summary.php"><i class="fas fa-boxes-packing"></i> PRODUCT SUMMARY</a>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="purchase.php"><i class="fas fa-book"></i> PURCHASE</a>
        <a href="contacts.php"><i class="fas fa-address-book"></i> CONTACTS</a>
        <a href="accounts.php"><i class="fas fa-calculator"></i> ACCOUNTS</a>
    </nav>

    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <p><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <span><?php echo htmlspecialchars($_SESSION['user_mobile'] ?? 'Admin'); ?></span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i> LOGOUT</a>
    </div>
</div>
<!-- ========== END SIDEBAR ========== -->

<!-- ========== NAVBAR ========== -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">
            <div class="ml-auto flex items-center gap-4">
                <span class="text-sm font-medium text-white hidden sm:inline">

                    <i class="fas fa-user mr-1"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <div class="mobile-burger" style="display:none;">
                    <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- ========== PAGE WRAPPER ========== -->
<div class="page-wrapper">

    <div class="page-heading">
        <h1 class="gold-font"><i class="fas fa-users mr-2"></i> Customer Management</h1>
        <p>View and manage all your jewellery customers</p>
    </div>

    <div class="container mx-auto px-4 sm:px-6 py-6">

        <?php if(!empty($error_message)): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm" style="background:#FEF2F2;border:1px solid #EF4444;color:#991B1B;">
                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="jewel-card p-4 mb-5">
            <div class="filter-wrap flex flex-col md:flex-row justify-between items-center gap-4">
                <!-- Filter buttons -->
                <div class="flex flex-wrap gap-2">
                    <a href="?filter=all"        class="btn-filter <?php echo $filter=='all'        ? 'active' : ''; ?>"> All Customers</a>
                    <a href="?filter=this_month" class="btn-filter <?php echo $filter=='this_month' ? 'active' : ''; ?>"> This Month</a>
                    <a href="?filter=this_year"  class="btn-filter <?php echo $filter=='this_year'  ? 'active' : ''; ?>"> This Year</a>
                </div>

                <!-- Search -->
                <form method="GET" class="flex flex-wrap gap-2">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="date" name="date_filter" value="<?php echo htmlspecialchars($date_filter); ?>" class="jewel-input" style="width:auto;">
                    <input type="text" name="search" placeholder="Search name / mobile / ID..." value="<?php echo htmlspecialchars($search); ?>" class="jewel-input" style="min-width:180px;flex:1;">
                    <button type="submit" class="btn-jewel" style="padding:8px 18px;font-size:12px;border-radius:10px;">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if(!empty($search) || !empty($date_filter) || $filter != 'all'): ?>
                        <a href="customers.php" class="btn-jewel" style="padding:8px 18px;font-size:12px;border-radius:10px;background:linear-gradient(135deg,#6b7280,#4b5563);">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-between items-center flex-wrap gap-3 mb-4">
            <div class="flex items-center gap-2 flex-wrap">
                <button onclick="exportCustomersToExcel()" class="btn-excel" title="Download all customers as Excel spreadsheet">
                    <i class="fas fa-file-excel mr-1"></i> Export Excel (.xlsx)
                </button>
                <button onclick="openImportModal()" class="btn-import" title="Import customers from Excel or CSV file">
                    <i class="fas fa-file-import mr-1"></i> Import Excel / CSV
                </button>
            </div>
            <div>
                <button onclick="openAddModal()" class="btn-jewel">
                    <i class="fas fa-user-plus"></i> Add Customer
                </button>
            </div>
        </div>

        <!-- Customers Table -->
        <div class="jewel-card p-5">
            <h2 class="gold-font text-xl font-bold mb-4" style="color:#800020;">
                <i class="fas fa-user-friends mr-2" style="color:#d68b16;"></i>
                Royal Customers
                <span class="text-sm font-normal ml-2" style="color:#7a4e0a;">(Total: <?php echo $total_customers; ?>)</span>
            </h2>

            <div class="table-wrap overflow-x-auto rounded-xl" style="border:1px solid rgba(181,115,14,0.15);">
                <table class="jewel-table">
                    <thead>
                        <tr>
                            <th class="text-left">ID</th>
                            <th class="text-left">Customer Name</th>
                            <th class="text-left">Mobile</th>
                            <th class="text-left hidden sm:table-cell">Email</th>
                            <th class="text-left">Registered</th>
                            <th class="text-center">Purchases</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($total_customers > 0): foreach($customers as $customer):
                            $mob = $customer['mobile'];
                            $order_count = intval($customer['order_count'] ?? 0);
                            $total_amt = floatval($customer['total_amt'] ?? 0);
                            $last_order = $customer['last_order'] ?? null;
                        ?>
                        <tr>
                            <td class="text-sm font-semibold" style="color:#d68b16;">#<?php echo $customer['id']; ?></td>
                            <td>
                                <div class="font-semibold" style="color:#800020;cursor:pointer;" onclick="openOrderHistory('<?php echo htmlspecialchars($mob, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($customer['name'], ENT_QUOTES); ?>')">
                                     <span style="text-decoration:underline dotted;text-underline-offset:3px;"><?php echo htmlspecialchars($customer['name']); ?></span>
                                    <i class="fas fa-receipt ml-1" style="font-size:10px;color:#d68b16;" title="View Orders"></i>
                                </div>
                                <?php if(!empty($customer['gst_number'])): ?>
                                    <div class="text-xs mt-0.5" style="color:#6d28d9;"> GST: <?php echo htmlspecialchars($customer['gst_number']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm" style="color:#374151;"> <?php echo htmlspecialchars($mob); ?></td>
                            <td class="text-sm hidden sm:table-cell" style="color:#6b7280;"><?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?></td>
                            <td class="text-sm" style="color:#374151;"> <?php echo date('d M Y', strtotime($customer['created_at'])); ?></td>
                            <td class="text-center">
                                <div class="font-bold text-sm" style="color:#800020;"><?php echo $order_count; ?> orders</div>
                                <div class="text-xs" style="color:#059669;">₹<?php echo number_format($total_amt, 2); ?></div>
                                <?php if($last_order): ?>
                                    <div class="text-xs" style="color:#9ca3af;">Last: <?php echo date('d M Y', strtotime($last_order)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="flex gap-1 justify-center flex-wrap">
                                    <button onclick='openEditModal(<?php echo htmlspecialchars(json_encode($customer), ENT_QUOTES, "UTF-8"); ?>)' class="btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="?delete_id=<?php echo $customer['id']; ?>" onclick="return confirm(' Delete this customer?')" class="btn-delete">
                                        <i class="fas fa-trash"></i> Del
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-10" style="color:#7a4e0a;">
                                <i class="fas fa-users text-3xl mb-3 block" style="color:#d68b16;opacity:0.4;"></i>
                                No customers found.
                                <?php echo !empty($search) ? 'Try different search criteria.' : 'Add customers from the billing page.'; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer-jewel">
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Design & Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology & Research Private Limited</a>
        </p>
    </footer>
</div><!-- end .page-wrapper -->

<!-- ========== ADD CUSTOMER MODAL ========== -->
<div id="addModal" class="modal-overlay">
    <div class="modal-content">
        <h3 class="text-xl font-bold gold-font"><i class="fas fa-user-plus mr-2" style="color:#d68b16;"></i> Add New Customer</h3>
        <form method="POST">
            <div class="mb-3">
                <label> Full Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" required placeholder="Customer name..." class="jewel-input">
            </div>
            <div class="mb-3">
                <label> Mobile Number <span style="color:#ef4444;">*</span></label>
                <input type="tel" name="mobile" required placeholder="10-digit mobile..." class="jewel-input">
            </div>
            <div class="mb-3">
                <label> Email Address</label>
                <input type="email" name="email" placeholder="email@example.com" class="jewel-input">
            </div>
            <div class="mb-3">
                <label> Address</label>
                <textarea name="address" rows="2" placeholder="Customer address..." class="jewel-input"></textarea>
            </div>
            <div class="mb-4">
                <label> GST Number <span style="color:#9ca3af;font-weight:400;">(Optional)</span></label>
                <input type="text" name="gst" placeholder="e.g. 22AAAAA0000A1Z5" maxlength="15" class="jewel-input" oninput="this.value=this.value.toUpperCase()">
                <p class="text-xs mt-1" style="color:#9ca3af;">15-character GST Identification Number</p>
            </div>
            <div class="flex gap-3">
                <button type="submit" name="add_customer" class="btn-jewel flex-1 justify-center">
                    <i class="fas fa-user-plus"></i> Add Customer
                </button>
                <button type="button" onclick="closeAddModal()" class="flex-1 py-2 rounded-lg text-sm font-semibold" style="background:#e5e7eb;color:#374151;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========== EDIT CUSTOMER MODAL ========== -->
<div id="editModal" class="modal-overlay">
    <div class="modal-content">
        <h3 class="text-xl font-bold gold-font"><i class="fas fa-edit mr-2" style="color:#d68b16;"></i> Edit Customer</h3>
        <form method="POST">
            <input type="hidden" name="customer_id" id="editCustomerId">
            <div class="mb-3">
                <label> Full Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" id="editName" required class="jewel-input">
            </div>
            <div class="mb-3">
                <label> Mobile Number <span style="color:#ef4444;">*</span></label>
                <input type="tel" name="mobile" id="editMobile" required class="jewel-input">
            </div>
            <div class="mb-3">
                <label> Email Address</label>
                <input type="email" name="email" id="editEmail" class="jewel-input">
            </div>
            <div class="mb-3">
                <label> Address</label>
                <textarea name="address" id="editAddress" rows="2" class="jewel-input"></textarea>
            </div>
            <div class="mb-4">
                <label> GST Number <span style="color:#9ca3af;font-weight:400;">(Optional)</span></label>
                <input type="text" name="gst" id="editGst" placeholder="e.g. 22AAAAA0000A1Z5" maxlength="15" class="jewel-input" oninput="this.value=this.value.toUpperCase()">
            </div>
            <div class="flex gap-3">
                <button type="submit" name="update_customer" class="btn-jewel flex-1 justify-center">
                    <i class="fas fa-save"></i> Update Customer
                </button>
                <button type="button" onclick="closeEditModal()" class="flex-1 py-2 rounded-lg text-sm font-semibold" style="background:#e5e7eb;color:#374151;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<style>
@media (max-width: 768px) { nav.nav-gold { margin-left: 0 !important; } }
</style>

<script>
    /* ---------- Sidebar ---------- */
    function toggleSidebar() {
        const sidebar = document.getElementById('mainSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const burger  = document.getElementById('burgerMenu');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        burger.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    function closeSidebar() {
        document.getElementById('mainSidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        const b = document.getElementById('burgerMenu');
        if(b) b.classList.remove('active');
        document.body.style.overflow = '';
    }

    /* ---------- Add Modal ---------- */
    function openAddModal() {
        document.getElementById('addModal').classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
    function closeAddModal() {
        document.getElementById('addModal').classList.remove('flex');
        document.body.style.overflow = '';
    }

    /* ---------- Edit Modal ---------- */
    function openEditModal(c) {
        document.getElementById('editCustomerId').value = c.id;
        document.getElementById('editName').value        = c.name;
        document.getElementById('editMobile').value      = c.mobile;
        document.getElementById('editEmail').value       = c.email   || '';
        document.getElementById('editAddress').value     = c.address || '';
        document.getElementById('editGst').value         = c.gst_number || '';
        document.getElementById('editModal').classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
    function closeEditModal() {
        document.getElementById('editModal').classList.remove('flex');
        document.body.style.overflow = '';
    }

    /* ---------- Import Modal ---------- */
    function openImportModal() {
        document.getElementById('importModal').classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
    function closeImportModal() {
        document.getElementById('importModal').classList.remove('flex');
        document.body.style.overflow = '';
    }

    /* Outside-click & ESC close */
    ['addModal','editModal','importModal'].forEach(function(id) {
        const el = document.getElementById(id);
        if(el) {
            el.addEventListener('click', function(e) {
                if(e.target === this) {
                    this.classList.remove('flex');
                    document.body.style.overflow = '';
                }
            });
        }
    });
    document.addEventListener('keydown', function(e) {
        if(e.key === 'Escape') { closeAddModal(); closeEditModal(); closeImportModal(); closeOrderHistory(); }
    });
</script>

<!-- ========== IMPORT CUSTOMERS MODAL ========== -->
<div id="importModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 620px;">
        <div class="flex justify-between items-center mb-4 pb-2 border-b border-amber-200">
            <h3 class="text-xl font-bold gold-font mb-0" style="color:#800020;">
                <i class="fas fa-file-excel mr-2" style="color:#16a34a;"></i> Import Customers from Excel
            </h3>
            <button type="button" onclick="closeImportModal()" style="color:#7a4e0a;font-size:22px;background:none;border:none;cursor:pointer;line-height:1;">&times;</button>
        </div>

        <!-- Step 1: Download Template -->
        <div class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200/80 flex items-center justify-between flex-wrap gap-2">
            <div>
                <div class="text-xs font-bold text-amber-900">Need the correct column format?</div>
                <div class="text-[11px] text-stone-600">Download our sample spreadsheet pre-filled with required headers.</div>
            </div>
            <button type="button" onclick="downloadCustomerTemplate()" class="px-3 py-1.5 bg-gradient-to-r from-emerald-600 to-green-700 hover:from-emerald-700 hover:to-green-800 text-white rounded-lg text-xs font-bold shadow-sm transition inline-flex items-center gap-1.5 cursor-pointer">
                <i class="fas fa-download"></i> Download Template (.xlsx)
            </button>
        </div>

        <!-- Step 2: Upload File -->
        <div class="mb-4">
            <label class="block text-xs font-bold text-amber-900 mb-1.5">Select Excel or CSV File (.xlsx, .xls, .csv)</label>
            <div class="border-2 border-dashed border-amber-300 rounded-xl p-4 text-center bg-white hover:bg-amber-50/50 transition cursor-pointer" onclick="document.getElementById('excelFileInput').click()">
                <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" class="hidden" onchange="handleExcelFileSelect(this)">
                <i class="fas fa-cloud-upload-alt text-2xl text-amber-600 mb-1.5 block"></i>
                <span class="text-xs font-semibold text-stone-700 block" id="uploadFileNameLabel">Click to browse file or drag and drop here</span>
                <span class="text-[10px] text-stone-400 block mt-0.5">Supports Microsoft Excel (.xlsx / .xls) and CSV files</span>
            </div>
        </div>

        <!-- Step 3: Options -->
        <div class="mb-4 flex items-center gap-2">
            <input type="checkbox" id="importUpdateExisting" class="w-4 h-4 text-amber-600 rounded border-amber-300 focus:ring-amber-500" checked>
            <label for="importUpdateExisting" class="text-xs text-stone-700 font-medium cursor-pointer mb-0">
                Update existing customer details if mobile number is already registered
            </label>
        </div>

        <!-- Step 4: Preview Card -->
        <div id="importPreviewCard" style="display:none;" class="mb-4">
            <div class="flex justify-between items-center mb-1.5">
                <span class="text-xs font-bold text-emerald-800" id="importPreviewStats">0 rows found</span>
                <span class="text-[10px] text-stone-400">Preview (up to 10 rows)</span>
            </div>
            <div class="max-h-40 overflow-y-auto border border-amber-200 rounded-lg bg-stone-50 p-1">
                <table class="w-full text-left text-[11px]">
                    <thead>
                        <tr class="bg-amber-100/70 text-stone-700 font-semibold border-b border-amber-200">
                            <th class="p-1">#</th>
                            <th class="p-1">Name</th>
                            <th class="p-1">Mobile</th>
                            <th class="p-1">GSTIN</th>
                            <th class="p-1">Address</th>
                        </tr>
                    </thead>
                    <tbody id="importPreviewTableBody"></tbody>
                </table>
            </div>
        </div>

        <!-- Step 5: Progress Bar (shown during import) -->
        <div id="importProgressBarContainer" style="display:none;" class="mb-4 p-3 bg-amber-50 rounded-xl border border-amber-300">
            <div class="flex justify-between items-center text-xs font-bold text-amber-950 mb-1.5">
                <span id="importProgressText"><i class="fas fa-spinner fa-spin text-emerald-600 mr-1.5"></i> Importing customers...</span>
                <span id="importProgressPercent" class="font-mono text-emerald-700">0%</span>
            </div>
            <div class="w-full bg-stone-200 h-2.5 rounded-full overflow-hidden">
                <div id="importProgressBar" class="bg-gradient-to-r from-emerald-500 to-green-600 h-full transition-all duration-150" style="width: 0%;"></div>
            </div>
            <div class="text-[10.5px] text-stone-500 mt-1.5 flex justify-between">
                <span id="importProgressCounts">Processing...</span>
                <span class="text-amber-700 font-semibold">Please keep this window open</span>
            </div>
        </div>

        <!-- Step 5: Modal Actions -->
        <div class="flex gap-3 pt-2 border-t border-amber-200">
            <button type="button" onclick="closeImportModal()" class="flex-1 py-2 rounded-lg text-xs font-semibold bg-stone-200 hover:bg-stone-300 text-stone-700 transition">
                Cancel
            </button>
            <button type="button" id="btnConfirmImport" onclick="executeCustomerImport()" disabled class="flex-1 py-2 rounded-lg text-xs font-bold text-white bg-gradient-to-r from-emerald-600 to-green-700 hover:from-emerald-700 hover:to-green-800 disabled:opacity-50 disabled:cursor-not-allowed shadow transition cursor-pointer">
                <i class="fas fa-check mr-1"></i> Confirm Import
            </button>
        </div>
    </div>
</div>

<!-- ========== ORDER HISTORY MODAL ========== -->
<div id="orderHistoryModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);" onclick="if(event.target===this)closeOrderHistory()">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(96vw,780px);max-height:88vh;background:#fff;border-radius:20px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,0.3);">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#7a4e0a,#d68b16);padding:18px 22px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div>
                <div style="color:#fff;font-size:16px;font-weight:700;font-family:'Poppins',serif;" id="ohCustomerName">Customer Orders</div>
                <div style="color:rgba(255,255,255,0.75);font-size:11px;margin-top:2px;" id="ohCustomerMobile"></div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <div id="ohSummaryBadge" style="background:rgba(255,255,255,0.2);color:#fff;font-size:11px;font-weight:700;padding:4px 14px;border-radius:20px;"></div>
                <button id="ohDownloadBtn" onclick="downloadStatementPDF()" style="display:none;background:rgba(255,255,255,0.22);border:1.5px solid rgba(255,255,255,0.5);color:#fff;font-size:11px;font-weight:700;padding:5px 13px;border-radius:20px;cursor:pointer;gap:5px;align-items:center;">
                    <i class="fas fa-file-pdf mr-1"></i> PDF
                </button>
                <button id="ohDownloadExcelBtn" onclick="downloadStatementExcel()" style="display:none;background:rgba(22,163,74,0.9);border:1.5px solid rgba(255,255,255,0.5);color:#fff;font-size:11px;font-weight:700;padding:5px 13px;border-radius:20px;cursor:pointer;gap:5px;align-items:center;">
                    <i class="fas fa-file-excel mr-1"></i> Excel
                </button>
                <button onclick="closeOrderHistory()" style="background:rgba(255,255,255,0.2);border:none;color:#fff;width:30px;height:30px;border-radius:50%;font-size:16px;cursor:pointer;line-height:1;">&times;</button>
            </div>
        </div>
        <!-- Summary Strip -->
        <div style="display:flex;gap:0;flex-shrink:0;border-bottom:1px solid rgba(181,115,14,0.15);">
            <div style="flex:1;padding:12px 8px;text-align:center;border-right:1px solid rgba(181,115,14,0.15);">
                <div style="font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.8px;">Total Orders</div>
                <div id="ohTotalOrders" style="font-size:18px;font-weight:800;color:#800020;margin-top:2px;">-</div>
            </div>
            <div style="flex:1;padding:12px 8px;text-align:center;border-right:1px solid rgba(181,115,14,0.15);">
                <div style="font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.8px;">Total Spent</div>
                <div id="ohTotalSpent" style="font-size:18px;font-weight:800;color:#059669;margin-top:2px;">-</div>
            </div>
            <div style="flex:1;padding:12px 8px;text-align:center;border-right:1px solid rgba(181,115,14,0.15);">
                <div style="font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.8px;">Paid</div>
                <div id="ohTotalPaid" style="font-size:18px;font-weight:800;color:#2563eb;margin-top:2px;">-</div>
            </div>
            <div style="flex:1;padding:12px 8px;text-align:center;">
                <div style="font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.8px;">Balance Due</div>
                <div id="ohTotalBalance" style="font-size:18px;font-weight:800;color:#dc2626;margin-top:2px;">-</div>
            </div>
        </div>
        <!-- Orders List -->
        <div style="overflow-y:auto;flex:1;padding:16px 18px;">
            <div id="ohLoading" style="text-align:center;padding:40px;color:#d68b16;font-size:13px;">
                <i class="fas fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;"></i>Loading orders...
            </div>
            <div id="ohOrdersList" style="display:none;"></div>
            <div id="ohEmpty" style="display:none;text-align:center;padding:40px;color:#9ca3af;">
                <i class="fas fa-receipt" style="font-size:36px;display:block;margin-bottom:10px;opacity:0.3;"></i>No orders found.
            </div>
        </div>
    </div>
</div>

<style>
.oh-order-card{border:1px solid rgba(181,115,14,0.2);border-radius:12px;margin-bottom:12px;overflow:hidden;transition:box-shadow 0.2s;}
.oh-order-card:hover{box-shadow:0 4px 16px rgba(181,115,14,0.15);}
.oh-order-head{background:linear-gradient(135deg,#fdf6e3,#f5ead0);padding:10px 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;}
.oh-badge{font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;}
.oh-badge.paid{background:#dcfce7;color:#166534;}
.oh-badge.part{background:#fef3c7;color:#92400e;}
.oh-badge.unpaid{background:#fee2e2;color:#991b1b;}
.oh-item-row{display:flex;justify-content:space-between;align-items:center;padding:7px 14px;border-bottom:1px solid rgba(181,115,14,0.08);font-size:12px;}
.oh-item-row:last-child{border-bottom:none;}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
/* ---------- Global Customer Export Data ---------- */
const customersExportData = <?php echo json_encode($customers); ?>;

/* ---------- Export Customers to Excel (.xlsx) ---------- */
function exportCustomersToExcel() {
    if (!customersExportData || customersExportData.length === 0) {
        alert('No customers found to export!');
        return;
    }
    const today = '<?php echo date("d M Y"); ?>';
    const todayFile = '<?php echo date("Y-m-d"); ?>';
    const totalOrders = customersExportData.reduce((s, c) => s + parseInt(c.order_count || 0), 0);
    const totalRevenue = customersExportData.reduce((s, c) => s + parseFloat(c.total_amt || 0), 0);

    const wb = XLSX.utils.book_new();

    // Sheet 1 - All GST Customers
    const aoa = [];
    aoa.push(['MAA GOURI JEWELLERS - Royal Customers Directory (GST Section)']);
    aoa.push(['Report Generated: ' + today + ' | Jeweller Pro+ Enterprise']);
    aoa.push([]);
    aoa.push(['Total Customers', customersExportData.length, '', 'Total Invoices', totalOrders, '', 'Total Purchases Value', '₹' + totalRevenue.toLocaleString('en-IN', {minimumFractionDigits: 2})]);
    aoa.push([]);
    aoa.push(['#', 'Customer ID', 'Customer Name', 'Mobile Number', 'GSTIN / GST No', 'Email Address', 'Address', 'Total Orders', 'Total Purchases (₹)', 'Registration Date', 'Last Order Date']);

    customersExportData.forEach((c, idx) => {
        const regDate = c.created_at ? new Date(c.created_at).toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'}) : '-';
        const lastDate = c.last_order ? new Date(c.last_order).toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'}) : '-';
        aoa.push([
            idx + 1,
            '#' + c.id,
            c.name || '',
            c.mobile || '',
            c.gst_number || '',
            c.email || '',
            c.address || '',
            parseInt(c.order_count || 0),
            parseFloat(c.total_amt || 0),
            regDate,
            lastDate
        ]);
    });

    aoa.push([]);
    aoa.push(['', 'TOTAL', '', '', '', '', '', totalOrders, totalRevenue, '', '']);

    const ws = XLSX.utils.aoa_to_sheet(aoa);
    ws['!cols'] = [
        {wch: 6},   // #
        {wch: 14},  // ID
        {wch: 26},  // Name
        {wch: 16},  // Mobile
        {wch: 20},  // GSTIN
        {wch: 26},  // Email
        {wch: 34},  // Address
        {wch: 14},  // Orders
        {wch: 22},  // Purchases
        {wch: 16},  // Reg Date
        {wch: 16}   // Last Order
    ];
    ws['!merges'] = [
        {s: {r:0, c:0}, e: {r:0, c:10}},
        {s: {r:1, c:0}, e: {r:1, c:10}}
    ];

    XLSX.utils.book_append_sheet(wb, ws, 'GST Customers');
    XLSX.writeFile(wb, `MAA_GOURI_Customers_GST_${todayFile}.xlsx`);
}

/* ---------- Download Sample Excel Template ---------- */
function downloadCustomerTemplate() {
    const wb = XLSX.utils.book_new();
    const aoa = [
        ['Customer Name', 'Mobile Number', 'Email Address', 'Address', 'GST Number'],
        ['Rajesh Verma', '9876543210', 'rajesh@example.com', 'Station Road, Kolkata', '19AAAAA0000A1Z5'],
        ['Sunita Roy', '9830112233', 'sunita@example.com', 'Bowbazar Market, Kolkata', '']
    ];
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    ws['!cols'] = [{wch: 22}, {wch: 16}, {wch: 24}, {wch: 28}, {wch: 18}];
    XLSX.utils.book_append_sheet(wb, ws, 'Customers');
    XLSX.writeFile(wb, 'Customer_Import_Template_GST.xlsx');
}

/* ---------- Parse Excel/CSV File Client-Side ---------- */
let parsedImportRows = [];

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function handleExcelFileSelect(input) {
    const file = input.files[0];
    if (!file) return;

    document.getElementById('uploadFileNameLabel').textContent = file.name;

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const rawRows = XLSX.utils.sheet_to_json(firstSheet, {defval: ''});

            if (!rawRows || rawRows.length === 0) {
                alert('No data rows found in the uploaded spreadsheet.');
                return;
            }

            parsedImportRows = [];
            rawRows.forEach((r) => {
                let name = '', mobile = '', email = '', address = '', gst = '';
                for (let k in r) {
                    const lk = k.trim().toLowerCase();
                    const val = String(r[k]).trim();
                    if (lk.includes('name')) name = val;
                    else if (lk.includes('mobile') || lk.includes('phone') || lk.includes('contact')) mobile = val;
                    else if (lk.includes('email') || lk.includes('mail')) email = val;
                    else if (lk.includes('address') || lk.includes('city') || lk.includes('village')) address = val;
                    else if (lk.includes('gst') || lk.includes('gstin')) gst = val;
                }
                const cleanMob = mobile.replace(/[^0-9]/g, '');
                if (name && cleanMob.length >= 10) {
                    parsedImportRows.push({
                        name: name,
                        mobile: cleanMob.length === 12 && cleanMob.startsWith('91') ? cleanMob.substring(2) : cleanMob,
                        email: email,
                        address: address,
                        gst_number: gst.toUpperCase()
                    });
                }
            });

            const previewCard = document.getElementById('importPreviewCard');
            const previewStats = document.getElementById('importPreviewStats');
            const previewTableBody = document.getElementById('importPreviewTableBody');
            const confirmBtn = document.getElementById('btnConfirmImport');

            if (parsedImportRows.length === 0) {
                alert('No valid rows found. Please make sure your spreadsheet has "Customer Name" and 10-digit "Mobile Number" columns.');
                previewCard.style.display = 'none';
                confirmBtn.disabled = true;
                return;
            }

            previewStats.textContent = `${parsedImportRows.length} valid customers found`;
            previewTableBody.innerHTML = '';
            parsedImportRows.slice(0, 10).forEach((row, i) => {
                previewTableBody.innerHTML += `
                    <tr class="border-b border-amber-100 text-xs">
                        <td class="p-1 font-mono text-stone-500">${i+1}</td>
                        <td class="p-1 font-bold text-gray-800">${escapeHtml(row.name)}</td>
                        <td class="p-1 font-mono text-stone-700">${escapeHtml(row.mobile)}</td>
                        <td class="p-1 text-purple-700">${escapeHtml(row.gst_number || '-')}</td>
                        <td class="p-1 text-stone-600 truncate max-w-xs">${escapeHtml(row.address || '-')}</td>
                    </tr>
                `;
            });
            if (parsedImportRows.length > 10) {
                previewTableBody.innerHTML += `<tr><td colspan="5" class="p-1 text-center text-xs text-stone-400 italic">...and ${parsedImportRows.length - 10} more rows</td></tr>`;
            }

            previewCard.style.display = 'block';
            confirmBtn.disabled = false;
        } catch (err) {
            console.error(err);
            alert('Failed to read Excel file: ' + err.message);
        }
    };
    reader.readAsArrayBuffer(file);
}

/* ---------- Execute Customer Import via AJAX in Batches ---------- */
async function executeCustomerImport() {
    if (!parsedImportRows || parsedImportRows.length === 0) {
        alert('Please select and parse an Excel file first.');
        return;
    }
    const updateExisting = document.getElementById('importUpdateExisting').checked;
    const confirmBtn = document.getElementById('btnConfirmImport');
    const cancelBtn = confirmBtn.previousElementSibling;
    const progressContainer = document.getElementById('importProgressBarContainer');
    const progressBar = document.getElementById('importProgressBar');
    const progressPercent = document.getElementById('importProgressPercent');
    const progressText = document.getElementById('importProgressText');
    const progressCounts = document.getElementById('importProgressCounts');

    confirmBtn.disabled = true;
    if (cancelBtn) cancelBtn.style.display = 'none';
    if (progressContainer) progressContainer.style.display = 'block';

    const totalRows = parsedImportRows.length;
    const BATCH_SIZE = 100;
    const totalBatches = Math.ceil(totalRows / BATCH_SIZE);

    let totalAdded = 0;
    let totalUpdated = 0;
    let totalSkipped = 0;

    try {
        for (let b = 0; b < totalBatches; b++) {
            const start = b * BATCH_SIZE;
            const end = Math.min(start + BATCH_SIZE, totalRows);
            const batchRows = parsedImportRows.slice(start, end);

            const pct = Math.round((start / totalRows) * 100);
            if (progressBar) progressBar.style.width = pct + '%';
            if (progressPercent) progressPercent.textContent = pct + '%';
            if (progressText) progressText.innerHTML = `<i class="fas fa-spinner fa-spin text-emerald-600 mr-1.5"></i> Importing batch ${b + 1} of ${totalBatches}...`;
            if (progressCounts) progressCounts.textContent = `Processed ${start} of ${totalRows} (${totalAdded} added, ${totalUpdated} updated)`;
            confirmBtn.innerHTML = `<i class="fas fa-spinner fa-spin mr-1"></i> Importing (${pct}%)...`;

            const resp = await fetch('customers.php?action=import_customers', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'import_customers',
                    rows: batchRows,
                    update_existing: updateExisting
                })
            });

            const respText = await resp.text();
            let data;
            try {
                data = JSON.parse(respText);
            } catch (err) {
                throw new Error(`Server returned invalid response on batch ${b + 1} (HTTP ${resp.status}): ${respText.substring(0, 150) || 'Empty response'}`);
            }

            if (!data.success) {
                throw new Error(data.message || `Batch ${b + 1} failed.`);
            }

            totalAdded += (data.added || 0);
            totalUpdated += (data.updated || 0);
            totalSkipped += (data.skipped || 0);
        }

        if (progressBar) progressBar.style.width = '100%';
        if (progressPercent) progressPercent.textContent = '100%';
        if (progressText) progressText.innerHTML = `<i class="fas fa-check-circle text-emerald-600 mr-1.5"></i> Import Complete!`;
        if (progressCounts) progressCounts.textContent = `Completed ${totalRows} customers (${totalAdded} added, ${totalUpdated} updated, ${totalSkipped} skipped)`;
        confirmBtn.innerHTML = '<i class="fas fa-check mr-1"></i> Done';

        alert(`🎉 Customer Import Successful!\n\nTotal Processed: ${totalRows}\nAdded: ${totalAdded} new customers\nUpdated: ${totalUpdated} existing customers\nSkipped: ${totalSkipped}`);
        window.location.reload();
    } catch (err) {
        alert('Import error: ' + err.message);
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check mr-1"></i> Retry Import';
        if (cancelBtn) cancelBtn.style.display = '';
    }
}

/* ---------- Order History Handling ---------- */
function openOrderHistory(mobile, name) {
    document.getElementById('orderHistoryModal').style.display = 'block';
    document.getElementById('ohCustomerName').textContent = ' ' + name;
    document.getElementById('ohCustomerMobile').textContent = ' ' + mobile;
    document.getElementById('ohLoading').style.display = 'block';
    document.getElementById('ohOrdersList').style.display = 'none';
    document.getElementById('ohEmpty').style.display = 'none';
    document.getElementById('ohTotalOrders').textContent = '-';
    document.getElementById('ohTotalSpent').textContent = '-';
    document.getElementById('ohTotalPaid').textContent = '-';
    document.getElementById('ohTotalBalance').textContent = '-';
    document.getElementById('ohSummaryBadge').textContent = '';
    document.body.style.overflow = 'hidden';

    fetch('customers.php?action=get_orders&mobile=' + encodeURIComponent(mobile))
        .then(r => r.json())
        .then(data => {
            document.getElementById('ohLoading').style.display = 'none';
            if(!data.success || data.orders.length === 0) {
                document.getElementById('ohEmpty').style.display = 'block';
                return;
            }
            // Summary
            let totalSpent = 0, totalPaid = 0, totalBal = 0;
            data.orders.forEach(o => {
                totalSpent += parseFloat(o.total_amount || 0);
                totalPaid  += parseFloat(o.paid_amount  || 0);
                totalBal   += parseFloat(o.balance_amount || 0);
            });
            document.getElementById('ohTotalOrders').textContent = data.orders.length;
            document.getElementById('ohTotalSpent').textContent  = '₹' + totalSpent.toLocaleString('en-IN', {minimumFractionDigits:2});
            document.getElementById('ohTotalPaid').textContent   = '₹' + totalPaid.toLocaleString('en-IN',  {minimumFractionDigits:2});
            document.getElementById('ohTotalBalance').textContent = totalBal > 0 ? '₹' + totalBal.toLocaleString('en-IN', {minimumFractionDigits:2}) : '₹0.00';
            document.getElementById('ohSummaryBadge').textContent = data.orders.length + ' order' + (data.orders.length > 1 ? 's' : '');

            // Store for PDF & Excel
            window._ohData = { orders: data.orders, totalSpent, totalPaid, totalBal };
            window._ohCustomer = { name, mobile };
            document.getElementById('ohDownloadBtn').style.display = 'inline-flex';
            document.getElementById('ohDownloadExcelBtn').style.display = 'inline-flex';

            // Render cards
            let html = '';
            data.orders.forEach(o => {
                const statusClass = o.payment_status === 'paid' ? 'paid' : (o.payment_status === 'part' ? 'part' : 'unpaid');
                const statusLabel = o.payment_status === 'paid' ? ' Paid' : (o.payment_status === 'part' ? ' Part Paid' : ' Unpaid');
                const date = new Date(o.created_at).toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'});
                html += `<div class="oh-order-card">
                    <div class="oh-order-head">
                        <div>
                            <span style="font-weight:700;color:#7a4e0a;font-size:13px;">${o.invoice_no}</span>
                            <span style="color:#9ca3af;font-size:11px;margin-left:8px;"> ${date}</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span class="oh-badge ${statusClass}">${statusLabel}</span>
                            <a href="view_pdf.php?invoice_no=${encodeURIComponent(o.invoice_no)}" target="_blank"
                               style="background:linear-gradient(135deg,#800020,#d68b16);color:#fff;font-size:10px;font-weight:700;padding:3px 12px;border-radius:20px;text-decoration:none;">
                                Print
                            </a>
                        </div>
                    </div>`;

                // Items
                if(o.items && o.items.length > 0) {
                    o.items.forEach(it => {
                        html += `<div class="oh-item-row">
                            <div>
                                <span style="font-weight:600;color:#374151;">${it.desc}</span>
                                ${it.hsn ? '<span style="color:#9ca3af;font-size:10px;margin-left:6px;">HSN: '+it.hsn+'</span>' : ''}
                            </div>
                            <div style="text-align:right;">
                                <span style="color:#6b7280;font-size:11px;">${it.qty} gm &nbsp;@₹${parseFloat(it.rate).toLocaleString('en-IN')}</span>
                                <span style="font-weight:700;color:#059669;margin-left:10px;">₹${parseFloat(it.total).toLocaleString('en-IN',{minimumFractionDigits:2})}</span>
                            </div>
                        </div>`;
                    });
                }

                // Footer
                html += `<div style="background:#f9f5eb;padding:8px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px;font-size:12px;">
                    <span style="color:#6b7280;"> ${o.payment_method || 'Cash'}</span>
                    <div style="display:flex;gap:16px;">
                        <span style="color:#059669;font-weight:700;">Total: ₹${parseFloat(o.total_amount).toLocaleString('en-IN',{minimumFractionDigits:2})}</span>
                        ${parseFloat(o.balance_amount) > 0 ? '<span style="color:#dc2626;font-weight:700;">Due: ₹'+parseFloat(o.balance_amount).toLocaleString('en-IN',{minimumFractionDigits:2})+'</span>' : ''}
                    </div>
                </div>`;
                html += '</div>';
            });

            document.getElementById('ohOrdersList').innerHTML = html;
            document.getElementById('ohOrdersList').style.display = 'block';
        })
        .catch(() => {
            document.getElementById('ohLoading').innerHTML = '<i class="fas fa-exclamation-circle" style="color:#dc2626;font-size:24px;display:block;margin-bottom:10px;"></i>Failed to load orders.';
        });
}

function closeOrderHistory() {
    document.getElementById('orderHistoryModal').style.display = 'none';
    document.getElementById('ohDownloadBtn').style.display = 'none';
    document.getElementById('ohDownloadExcelBtn').style.display = 'none';
    window._ohData = null;
    window._ohCustomer = null;
    document.body.style.overflow = '';
}

/* ---------- Customer Orders Statement Excel Export ---------- */
function downloadStatementExcel() {
    const d = window._ohData;
    const c = window._ohCustomer;
    if (!d || !d.orders || !d.orders.length) {
        alert('No orders found to export!');
        return;
    }
    const name = (c?.name || 'Customer').trim();
    const mobile = (c?.mobile || '').trim();
    const today = '<?php echo date("d M Y"); ?>';
    const todayFile = '<?php echo date("Y-m-d"); ?>';
    const wb = XLSX.utils.book_new();

    const aoa = [];
    aoa.push(['MAA GOURI JEWELLERS - Customer Order Statement']);
    aoa.push([`Customer: ${name} | Mobile: ${mobile} | Statement Date: ${today}`]);
    aoa.push([]);

    aoa.push(['Total Orders', d.orders.length, '', 'Total Billed', '₹' + d.totalSpent.toLocaleString('en-IN', {minimumFractionDigits:2}), '', 'Total Paid', '₹' + d.totalPaid.toLocaleString('en-IN', {minimumFractionDigits:2}), '', 'Balance Due', '₹' + d.totalBal.toLocaleString('en-IN', {minimumFractionDigits:2})]);
    aoa.push([]);
    aoa.push(['#', 'Invoice No', 'Date', 'Items / Products', 'Total Qty', 'Total Amount (₹)', 'Paid Amount (₹)', 'Balance Due (₹)', 'Payment Status', 'Payment Method']);

    d.orders.forEach((o, i) => {
        const itemNames = (o.items||[]).map(it => it.desc).join(', ') || '-';
        const totalQty = (o.items||[]).reduce((s, it) => s + parseFloat(it.qty||0), 0);
        const dateStr = o.created_at ? new Date(o.created_at).toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'}) : '-';
        aoa.push([
            i + 1,
            o.invoice_no,
            dateStr,
            itemNames,
            totalQty,
            parseFloat(o.total_amount||0),
            parseFloat(o.paid_amount||0),
            parseFloat(o.balance_amount||0),
            o.payment_status === 'paid' ? 'Full Paid' : (o.payment_status === 'part' ? 'Part Payment' : 'Unpaid'),
            o.payment_method || 'Cash'
        ]);
    });

    aoa.push([]);
    aoa.push(['', 'TOTAL', '', '', '', d.totalSpent, d.totalPaid, d.totalBal, '', '']);

    const ws = XLSX.utils.aoa_to_sheet(aoa);
    ws['!cols'] = [{wch:6},{wch:18},{wch:14},{wch:36},{wch:12},{wch:16},{wch:16},{wch:16},{wch:16},{wch:16}];
    ws['!merges'] = [{s:{r:0,c:0},e:{r:0,c:9}},{s:{r:1,c:0},e:{r:1,c:9}}];
    XLSX.utils.book_append_sheet(wb, ws, 'Orders Statement');

    const safeName = name.replace(/[^a-zA-Z0-9]/g, '_');
    XLSX.writeFile(wb, `Statement_${safeName}_${todayFile}.xlsx`);
}

// PDF Statement Download
function downloadStatementPDF() {
    const d = window._ohData;
    if(!d) return;
    const name   = document.getElementById('ohCustomerName').textContent.replace(' ','').trim();
    const mobile = document.getElementById('ohCustomerMobile').textContent.replace(' ','').trim();

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit:'mm', format:'a4', orientation:'portrait' });
    const W = 210, margin = 14;
    let y = 0;

    // Header bar
    doc.setFillColor(122, 78, 10);
    doc.rect(0, 0, W, 28, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(16); doc.setFont('helvetica','bold');
    doc.text('MAA GOURI JEWELLERS', W/2, 11, {align:'center'});
    doc.setFontSize(8); doc.setFont('helvetica','normal');
    doc.text('Krishnapriya, Midnapore (W), West Bengal - 721440  |  +91-9091518567', W/2, 17, {align:'center'});
    doc.setFontSize(10); doc.setFont('helvetica','bold');
    doc.text('CUSTOMER ACCOUNT STATEMENT', W/2, 24, {align:'center'});
    y = 34;

    // Customer info box
    doc.setFillColor(253, 246, 227);
    doc.setDrawColor(181, 115, 14);
    doc.roundedRect(margin, y, W - margin*2, 22, 3, 3, 'FD');
    doc.setTextColor(122, 78, 10); doc.setFontSize(10); doc.setFont('helvetica','bold');
    doc.text('Customer: ' + name, margin+4, y+8);
    doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(80,80,80);
    doc.text('Mobile: ' + mobile, margin+4, y+15);
    doc.text('Statement Date: ' + new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}), W - margin - 4, y+8, {align:'right'});
    doc.text('Total Orders: ' + d.orders.length, W - margin - 4, y+15, {align:'right'});
    y += 28;

    // Summary strip
    const cols4 = (W - margin*2) / 4;
    const summaryItems = [
        { label:'Total Spent',   val:'Rs.' + d.totalSpent.toLocaleString('en-IN',{minimumFractionDigits:2}), color:[5,150,105] },
        { label:'Total Paid',    val:'Rs.' + d.totalPaid.toLocaleString('en-IN',{minimumFractionDigits:2}),  color:[37,99,235] },
        { label:'Balance Due',   val:'Rs.' + d.totalBal.toLocaleString('en-IN',{minimumFractionDigits:2}),   color:[220,38,38] },
        { label:'Orders',        val:d.orders.length.toString(),                                              color:[128,0,32]  },
    ];
    summaryItems.forEach((s, i) => {
        const sx = margin + i * cols4;
        doc.setFillColor(248, 243, 230);
        doc.setDrawColor(214, 139, 22);
        doc.rect(sx, y, cols4 - 1, 16, 'FD');
        doc.setTextColor(...s.color); doc.setFontSize(10); doc.setFont('helvetica','bold');
        doc.text(s.val, sx + cols4/2 - 0.5, y+7, {align:'center'});
        doc.setTextColor(120,120,120); doc.setFontSize(7); doc.setFont('helvetica','normal');
        doc.text(s.label, sx + cols4/2 - 0.5, y+13, {align:'center'});
    });
    y += 22;

    // Orders table
    const colW = [28, 22, 62, 22, 28, 24];
    const headers = ['Invoice No', 'Date', 'Items', 'Qty(gm)', 'Amount', 'Status'];
    const tblW = W - margin*2;

    doc.setFillColor(122, 78, 10);
    doc.rect(margin, y, tblW, 7, 'F');
    doc.setTextColor(255,255,255); doc.setFontSize(7.5); doc.setFont('helvetica','bold');
    let hx = margin;
    headers.forEach((h, i) => {
        doc.text(h, hx + colW[i]/2, y+4.8, {align:'center'});
        hx += colW[i];
    });
    y += 7;

    doc.setFont('helvetica','normal'); doc.setFontSize(7.5);
    let rowIdx = 0;
    d.orders.forEach(o => {
        const date = new Date(o.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
        const itemNames = (o.items||[]).map(it => it.desc).join(', ') || '-';
        const totalQty  = (o.items||[]).reduce((s, it) => s + parseFloat(it.qty||0), 0);
        const amt       = 'Rs.' + parseFloat(o.total_amount).toLocaleString('en-IN',{minimumFractionDigits:2});
        const status    = o.payment_status === 'paid' ? 'Paid' : (o.payment_status === 'part' ? 'Part Paid' : 'Unpaid');
        const statusColor = o.payment_status === 'paid' ? [5,150,105] : (o.payment_status === 'part' ? [146,64,14] : [220,38,38]);

        const maxItemW = colW[2] - 3;
        const itemLines = doc.splitTextToSize(itemNames, maxItemW);
        const rowH = Math.max(8, itemLines.length * 4 + 3);

        if(y + rowH > 272) {
            doc.addPage();
            y = 14;
            doc.setFillColor(122, 78, 10);
            doc.rect(margin, y, tblW, 7, 'F');
            doc.setTextColor(255,255,255); doc.setFontSize(7.5); doc.setFont('helvetica','bold');
            hx = margin;
            headers.forEach((h, i) => { doc.text(h, hx + colW[i]/2, y+4.8, {align:'center'}); hx += colW[i]; });
            y += 7;
            doc.setFont('helvetica','normal'); doc.setFontSize(7.5);
        }

        doc.setFillColor(rowIdx % 2 === 0 ? 255 : 252, rowIdx % 2 === 0 ? 255 : 248, rowIdx % 2 === 0 ? 255 : 235);
        doc.setDrawColor(220, 200, 160);
        doc.rect(margin, y, tblW, rowH, 'FD');

        doc.setTextColor(60,60,60);
        const cy = y + rowH/2 + 1.2;
        let cx = margin;
        doc.setFont('helvetica','bold'); doc.setTextColor(122,78,10);
        doc.text(o.invoice_no, cx + colW[0]/2, cy, {align:'center'}); cx += colW[0];
        doc.setFont('helvetica','normal'); doc.setTextColor(80,80,80);
        doc.text(date, cx + colW[1]/2, cy, {align:'center'}); cx += colW[1];
        doc.setTextColor(50,50,50);
        const lineStartY = y + 3.5;
        itemLines.forEach((ln, li) => { doc.text(ln, cx + 1, lineStartY + li*4); });
        cx += colW[2];
        doc.setTextColor(80,80,80);
        doc.text(totalQty > 0 ? totalQty.toFixed(3) : '-', cx + colW[3]/2, cy, {align:'center'}); cx += colW[3];
        doc.setFont('helvetica','bold'); doc.setTextColor(5,100,60);
        doc.text(amt, cx + colW[4]/2, cy, {align:'center'}); cx += colW[4];
        doc.setTextColor(...statusColor);
        doc.text(status, cx + colW[5]/2, cy, {align:'center'});

        y += rowH;
        rowIdx++;
    });

    y += 6;
    if(y > 265) { doc.addPage(); y = 20; }
    doc.setDrawColor(181,115,14); doc.line(margin, y, W-margin, y);
    y += 5;
    doc.setFontSize(7); doc.setTextColor(140,140,140); doc.setFont('helvetica','italic');
    doc.text('This is a computer-generated statement from MAA GOURI JEWELLERS. For queries contact +91-9091518567.', W/2, y, {align:'center'});

    const totalPages = doc.internal.getNumberOfPages();
    for(let p = 1; p <= totalPages; p++) {
        doc.setPage(p);
        doc.setFontSize(7); doc.setTextColor(160,160,160); doc.setFont('helvetica','normal');
        doc.text('Page ' + p + ' of ' + totalPages, W - margin, 287, {align:'right'});
    }

    const safeName = name.replace(/[^a-zA-Z0-9]/g,'_');
    doc.save('Statement_' + safeName + '_' + new Date().toISOString().slice(0,10) + '.pdf');
}
</script>
</body>
</html>







