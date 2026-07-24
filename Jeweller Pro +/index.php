<?php
// Trigger Vercel rebuild with correct author email
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

$is_logged_in = isset($_SESSION['user_id']);
if(!$is_logged_in) {
    header("Location: login.php");
    exit();
}
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';
$logo_paths = ['assets/images/moti-removebg-preview.png', 'images/moti-removebg-preview.png', 'moti-removebg-preview.png', 'radhey shyam logo.png'];

$daily_sales = [];
$top_products = [];
if($is_logged_in) {
    for($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $sales_query = mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE DATE(created_at) = '$date'");
        $sales = mysqli_fetch_assoc($sales_query);
        $daily_sales[] = [
            'date' => date('d M', strtotime($date)),
            'total' => $sales['total'] ?? 0
        ];
    }

    $top_products_result = mysqli_query($conn, "SELECT p.name, SUM(ii.quantity) as sold FROM invoice_items ii JOIN products p ON ii.product_id = p.id GROUP BY ii.product_id ORDER BY sold DESC LIMIT 5");
    while($row = mysqli_fetch_assoc($top_products_result)) {
        $top_products[] = $row;
    }

    $monthly_sales = [];
    $daily_invoice_counts = [];
    for($m = 5; $m >= 0; $m--) {
        $month = date('Y-m', strtotime("-$m months"));
        $month_label = date('M', strtotime($month . '-01'));
        $sales_query = mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month'");
        $sales_row = mysqli_fetch_assoc($sales_query);
        $monthly_sales[] = ['month' => $month_label, 'total' => $sales_row['total'] ?? 0];
    }

    for($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $customer_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM customers WHERE DATE(created_at) = '$date'");
        $customer_row = mysqli_fetch_assoc($customer_query);
        $customer_growth[] = ['date' => date('d M', strtotime($date)), 'total' => $customer_row['total'] ?? 0];

        $invoice_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices WHERE DATE(created_at) = '$date'");
        $invoice_count_row = mysqli_fetch_assoc($invoice_count_query);
        $daily_invoice_counts[] = ['date' => date('d M', strtotime($date)), 'total' => $invoice_count_row['total'] ?? 0];
    }

    $invoice_pending_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices WHERE balance_amount > 0"));
    $invoice_completed_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices WHERE COALESCE(balance_amount, 0) = 0"));
    $invoice_pending_count = $invoice_pending_count_row['total'] ?? 0;
    $invoice_completed_count = $invoice_completed_count_row['total'] ?? 0;

    $category_sales = [];
    $category_sales_result = mysqli_query($conn, "SELECT COALESCE(p.category, 'Other') as category, COALESCE(SUM(ii.total), 0) as revenue FROM invoice_items ii JOIN products p ON ii.product_id = p.id GROUP BY p.category ORDER BY revenue DESC LIMIT 6");
    while($row = mysqli_fetch_assoc($category_sales_result)) {
        $category_sales[] = $row;
    }

    $category_stock = [];
    $category_stock_result = mysqli_query($conn, "SELECT COALESCE(category, 'Other') as category, COALESCE(SUM(quantity), 0) as total_qty FROM products GROUP BY category ORDER BY total_qty DESC LIMIT 6");
    while($row = mysqli_fetch_assoc($category_stock_result)) {
        $category_stock[] = $row;
    }

    $low_stock_items = [];
    $low_stock_result = mysqli_query($conn, "SELECT name, quantity FROM products WHERE quantity <= 5 ORDER BY quantity ASC, name ASC LIMIT 5");
    while($row = mysqli_fetch_assoc($low_stock_result)) {
        $low_stock_items[] = $row;
    }

    $pending_invoices = [];
    $pending_invoices_result = mysqli_query($conn, "SELECT invoice_no, customer_name, balance_amount FROM invoices WHERE balance_amount > 0 ORDER BY created_at DESC LIMIT 5");
    while($row = mysqli_fetch_assoc($pending_invoices_result)) {
        $pending_invoices[] = $row;
    }

    $current_month = date('Y-m');
    $monthly_income_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM income WHERE DATE_FORMAT(income_date, '%Y-%m') = '$current_month'"));
    $monthly_expense_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$current_month'"));
    $stock_items_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM products"));
    $stock_quantity_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(quantity), 0) as total FROM products"));
    $stock_value_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(price * quantity), 0) as total FROM products"));
    $customers_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM customers"));
    $total_income_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM income"));
    $total_expense_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM expenses"));
    $total_due_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(balance_amount), 0) as total FROM invoices WHERE balance_amount > 0"));

    $monthly_income = $monthly_income_row['total'] ?? 0;
    $monthly_expense = $monthly_expense_row['total'] ?? 0;
    $stock_items_count = $stock_items_count_row['total'] ?? 0;
    $stock_quantity = $stock_quantity_row['total'] ?? 0;
    $stock_value = $stock_value_row['total'] ?? 0;
    $customers_count = $customers_count_row['total'] ?? 0;
    $total_income = $total_income_row['total'] ?? 0;
    $total_expense = $total_expense_row['total'] ?? 0;
    $total_due = $total_due_row['total'] ?? 0;

    // Total sales from invoices (all-time)
    $total_sales_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices"));
    $total_sales = $total_sales_row['total'] ?? 0;

    // Today's sales (Includes new billing invoices + due payments cleared today)
    $today_due_cleared = 0;
    $due_chk = mysqli_query($conn, "SHOW TABLES LIKE 'due_update_history'");
    if($due_chk && mysqli_num_rows($due_chk) > 0) {
        $due_cleared_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount_paid), 0) as total FROM due_update_history WHERE DATE(payment_date) = CURDATE()"));
        $today_due_cleared = floatval($due_cleared_row['total'] ?? 0);
    }
    $today_inv_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE DATE(created_at) = CURDATE()"));
    $today_sales_amt = floatval($today_inv_row['total'] ?? 0) + $today_due_cleared;

    // Today's invoice count
    $today_invoice_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices WHERE DATE(created_at) = CURDATE()"));
    $today_invoice_count = $today_invoice_count_row['total'] ?? 0;

    // Total purchases from purchase_entries
    $total_purchases_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM purchase_entries"));
    $total_purchases = $total_purchases_row['total'] ?? 0;

    // Today's purchases
    $today_purchases_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM purchase_entries WHERE DATE(purchase_date) = CURDATE()"));
    $today_purchases = $today_purchases_row['total'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="author" content="MANU GUPTA">
    <meta name="description" content="Login for MAA GOURI JEWELLERS">
    <title>MAA GOURI JEWELLERS - Premium Jewellery Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Poppins', sans-serif; font-weight: 700; }

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
        .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
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
            object-fit: contain;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            padding: 3px;
            flex-shrink: 0;
        }

        .sidebar-logo-text h2 {
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.3;
            font-family: 'Poppins', serif;
            letter-spacing: 0.5px;
        }

        .sidebar-logo-text p {
            color: rgba(255,255,255,0.65);
            font-size: 10px;
            margin-top: 1px;
        }

        .sidebar-nav {
            flex: 1;
            padding: 10px 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-section-label {
            padding: 10px 20px 4px;
            color: rgba(255,255,255,0.45);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            background: #011921; color: #f5c842;
            z-index: 10;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 20px;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
            letter-spacing: 0.3px;
            position: relative;
        }

        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.13);
            color: #fff;
            border-left-color: rgba(255,255,255,0.8);
            padding-left: 26px;
        }

        .sidebar-nav a.active {
            background: rgba(255,255,255,0.22);
            color: #fff;
            border-left-color: #fff;
            font-weight: 700;
        }

        .sidebar-nav a.active::after {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: #fff;
            border-radius: 4px 0 0 4px;
        }

        .sidebar-nav a i {
            width: 18px;
            text-align: center;
            font-size: 14px;
            flex-shrink: 0;
            opacity: 0.9;
        }

        .sidebar-divider {
            height: 1px;
            background: rgba(255,255,255,0.12);
            margin: 6px 16px;
        }

        .sidebar-user {
            padding: 14px 16px 18px;
            border-top: 1px solid rgba(255,255,255,0.18);
            background: rgba(0,0,0,0.12);
            flex-shrink: 0;
        }

        .sidebar-user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .sidebar-user-info i {
            color: rgba(255,255,255,0.9);
            font-size: 26px;
            flex-shrink: 0;
        }

        .sidebar-user-info .user-details p {
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
        }

        .sidebar-user-info .user-details span {
            color: rgba(255,255,255,0.55);
            font-size: 10px;
        }

        .sidebar-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 14px;
            background: rgba(239,68,68,0.75);
            color: #fff;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
            letter-spacing: 0.5px;
            border: 1px solid rgba(239,68,68,0.4);
        }

        .sidebar-logout:hover {
            background: #ef4444;
            color: #fff;
        }

        .sidebar-login-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 14px;
            background: rgba(255,255,255,0.2);
            color: #fff;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .sidebar-login-btn:hover { background: rgba(255,255,255,0.3); }

        /* Sidebar overlay (mobile) */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active { display: block; }

        /* ========== MAIN LAYOUT ========== */
        .page-wrapper {
            margin-left: 0;
            min-height: 100vh;
            transition: margin-left 0.35s ease;
        }

        /* ========== TOP NAVBAR ========== */
        nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important;
        }

        nav.nav-gold h1,
        nav.nav-gold p,
        nav.nav-gold span { color: #ffffff !important; }

        /* ========== BURGER MENU ========== */
        .burger-menu {
            width: 28px;
            height: 20px;
            position: relative;
            cursor: pointer;
        }

        .burger-menu span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: #ffffff;
            border-radius: 3px;
            transition: all 0.3s ease;
        }

        .burger-menu span:nth-child(1) { top: 0px; }
        .burger-menu span:nth-child(2) { top: 9px; }
        .burger-menu span:nth-child(3) { top: 18px; }

        .burger-menu.active span:nth-child(1) { top: 9px; transform: rotate(135deg); }
        .burger-menu.active span:nth-child(2) { opacity: 0; left: -20px; }
        .burger-menu.active span:nth-child(3) { top: 9px; transform: rotate(-135deg); }

        /* ========== MOBILE RESPONSIVE ========== */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .page-wrapper { margin-left: 0 !important; }
            .mobile-burger { display: block !important; }
        }

        @media (min-width: 769px) {
            .mobile-burger { display: none !important; }
        }

        /* ========== SPARKLES ========== */
        .jewel-sparkle {
            position: fixed;
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            animation: sparkleFloat linear infinite;
        }

        @keyframes sparkleFloat {
            0% { transform: translateY(100vh) scale(0); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 0.5; }
            100% { transform: translateY(-10vh) scale(1); opacity: 0; }
        }

        /* ========== FLOATING LOGO ========== */
        .floating-logo {
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
            width: 100% !important;
            text-align: center !important;
            margin: 0 auto 20px auto !important;
            animation: logoFloat 3s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }

        .floating-logo img {
            width: 220px !important;
            height: 220px !important;
            max-width: 85vw !important;
            max-height: 220px !important;
            object-fit: contain;
            display: block;
            margin: 0 auto !important;
            filter: drop-shadow(0 12px 32px rgba(214,139,22,0.45));
        }

        /* ========== HERO ========== */
        .hero-with-logo { text-align: center; }
        .dashboard-section { background: transparent; }
        .dashboard-chart, .top-products-card {
            background: rgba(255, 255, 255, 0.45) !important;
            backdrop-filter: blur(16px) !important;
            -webkit-backdrop-filter: blur(16px) !important;
            border: 1px solid rgba(255, 255, 255, 0.4) !important;
            border-radius: 20px;
            box-shadow: 0 12px 32px rgba(122, 78, 10, 0.04) !important;
        }
        .dashboard-chart { padding: 24px; }
        .top-products-card { padding: 24px; }
        .dashboard-title { font-size: 1.1rem; font-weight: 700; color: #7a4e0a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.75rem; }
        .top-products-card .top-product-item { padding: 14px 0; border-bottom: 1px solid rgba(181,115,14,0.12); }
        .top-products-card .top-product-item:last-child { border-bottom: none; }

        .typing-text {
            background: linear-gradient(135deg, #800020, #c9a96e, #d68b16);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-family: 'Poppins', serif;
        }

        .cursor {
            display: inline-block;
            width: 3px;
            height: 1em;
            background: #d68b16;
            margin-left: 4px;
            vertical-align: middle;
            animation: blink 0.8s infinite;
        }

        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

        /* ========== STAT CARDS ========== */
        .stat-gem {
            background: rgba(255, 255, 255, 0.45) !important;
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
            border: 1px solid rgba(255, 255, 255, 0.4) !important;
            border-radius: 16px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 8px 24px rgba(122, 78, 10, 0.04) !important;
        }

        .stat-gem:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.65) !important;
            border-color: rgba(214, 139, 22, 0.4) !important;
            box-shadow: 0 12px 30px rgba(181,115,14,0.12) !important;
        }

        .stat-gem i {
            font-size: 32px;
            background: linear-gradient(135deg, #800020, #d68b16);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-gem h3 { font-size: 1.8rem; font-weight: 700; color: #800020; }
        .stat-gem p { color: #7a4e0a; font-size: 11px; }

        /* ========== GEM CARDS ========== */
        .gem-card {
            background: rgba(255, 255, 255, 0.45) !important;
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
            border: 1px solid rgba(255, 255, 255, 0.4) !important;
            border-radius: 20px;
            transition: all 0.4s ease;
            cursor: pointer;
            box-shadow: 0 8px 24px rgba(122, 78, 10, 0.04) !important;
        }

        .gem-card:hover {
            transform: translateY(-6px);
            background: rgba(255, 255, 255, 0.65) !important;
            box-shadow: 0 16px 35px rgba(181,115,14,0.15) !important;
            border-color: rgba(214, 139, 22, 0.4) !important;
        }

        .gem-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #800020, #d68b16, #7a4e0a);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: gemGlow 2.5s ease-in-out infinite;
        }

        @keyframes gemGlow {
            0%,100% { box-shadow: 0 0 10px rgba(214,139,22,0.4), 0 0 20px rgba(128,0,32,0.2); }
            50% { box-shadow: 0 0 25px rgba(214,139,22,0.7), 0 0 40px rgba(128,0,32,0.3); transform: scale(1.05); }
        }

        .gem-icon i { font-size: 28px; color: #fff; }
        .gem-card h3 { color: #800020; }
        .gem-card p { color: #6b5a3e; }

        /* ========== GLASS WIDGET ON COLORFUL STRIP ========== */
        .dark-glass-card {
            backdrop-filter: blur(16px) !important;
            -webkit-backdrop-filter: blur(16px) !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .dark-glass-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.6), transparent);
        }
        .dark-glass-card:hover {
            transform: translateY(-4px) scale(1.03);
            filter: brightness(1.15);
        }
        .glass-gold {
            background: linear-gradient(135deg, rgba(181, 115, 14, 0.75), rgba(122, 78, 10, 0.85)) !important;
            border: 1px solid #f5c842 !important;
            box-shadow: 0 8px 25px rgba(214, 139, 22, 0.4) !important;
        }
        .glass-emerald {
            background: linear-gradient(135deg, rgba(5, 150, 105, 0.75), rgba(4, 120, 87, 0.85)) !important;
            border: 1px solid #34d399 !important;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
        }
        .glass-rose {
            background: linear-gradient(135deg, rgba(225, 29, 72, 0.75), rgba(159, 18, 57, 0.85)) !important;
            border: 1px solid #fb7185 !important;
            box-shadow: 0 8px 25px rgba(244, 63, 94, 0.4) !important;
        }
        .glass-purple {
            background: linear-gradient(135deg, rgba(147, 51, 234, 0.75), rgba(107, 33, 168, 0.85)) !important;
            border: 1px solid #c084fc !important;
            box-shadow: 0 8px 25px rgba(168, 85, 247, 0.4) !important;
        }
        .glass-orange {
            background: linear-gradient(135deg, rgba(234, 88, 12, 0.75), rgba(154, 52, 18, 0.85)) !important;
            border: 1px solid #fdba74 !important;
            box-shadow: 0 8px 25px rgba(249, 115, 22, 0.4) !important;
        }
        .glass-teal {
            background: linear-gradient(135deg, rgba(13, 148, 136, 0.75), rgba(15, 118, 110, 0.85)) !important;
            border: 1px solid #2dd4bf !important;
            box-shadow: 0 8px 25px rgba(20, 184, 166, 0.4) !important;
        }

        /* ========== BUTTONS ========== */
        .btn-jewel {
            background: linear-gradient(135deg, #800020, #d68b16);
            border: none;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 700;
            color: #fff;
            transition: all 0.3s ease;
            display: inline-block;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-jewel:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 30px rgba(214,139,22,0.4);
            color: #fff;
        }

        /* ========== FOOTER ========== */
        .footer-jewel {
            background: linear-gradient(0deg, #f5e6c8, #fdf6e3);
            border-top: 2px solid #d68b16;
        }

        .footer-jewel h4 { color: #800020; }
        .footer-jewel a { color: #6b5a3e; }
        .footer-jewel a:hover { color: #800020; }

        /* ========== LOADER ========== */
        .loader-necklace { text-align: center; }
        .necklace-chain { display: flex; justify-content: center; gap: 12px; margin-bottom: 24px; }

        .chain-link {
            width: 18px;
            height: 28px;
            border: 3px solid #d68b16;
            border-radius: 50%;
            animation: chainSwing 0.8s ease-in-out infinite alternate;
        }

        .chain-link:nth-child(1){animation-delay:0s}
        .chain-link:nth-child(2){animation-delay:0.1s}
        .chain-link:nth-child(3){animation-delay:0.2s}
        .chain-link:nth-child(4){animation-delay:0.3s}
        .chain-link:nth-child(5){animation-delay:0.4s}

        @keyframes chainSwing {
            0%{transform:rotate(0deg) translateY(0)}
            100%{transform:rotate(15deg) translateY(-5px)}
        }

        .pendant {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, #800020, #d68b16);
            clip-path: polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%);
            margin: 0 auto 20px;
            animation: pendantGlow 1s ease-in-out infinite alternate;
        }

        @keyframes pendantGlow {
            from { box-shadow: 0 0 10px #d68b16; transform: scale(1); }
            to { box-shadow: 0 0 30px #d68b16; transform: scale(1.1); }
        }

        /* ========== THEME TOGGLE ========== */
        .theme-toggle {
            width: 52px;
            height: 26px;
            background: rgba(255,255,255,0.2);
            border-radius: 999px;
            position: relative;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 6px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .theme-toggle .toggle-icon { font-size: 11px; color: rgba(255,255,255,0.8); z-index: 1; }
        .theme-toggle .toggle-ball {
            position: absolute;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: transform 0.3s ease;
        }

        body.dark-theme .theme-toggle .toggle-ball { transform: translateX(26px); }

        /* ========== FOOTER LOGO ========== */
        .footer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .footer-logo img { width: 50px; height: 50px; object-fit: contain; }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: repeat(2,1fr) !important; gap: 12px !important; }
            .hero-buttons { flex-direction: column; align-items: center; gap: 12px; }
            .hero-buttons a { width: 80%; text-align: center; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr !important; }
            .floating-logo img { width: 110px !important; height: 110px !important; }
        }

        @media print { body * { visibility: visible; } }
    </style>
</head>
<body class="<?php echo $theme == 'light' ? 'light-theme' : 'dark-theme'; ?>" style="background: radial-gradient(circle at 10% 10%, #fffbf5 0%, #faf5e6 50%, #f6eccf 100%); margin:0; padding:0; min-height:100vh;">

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

    const texts = ["MAA GOURI JEWELLERS", "PREMIUM JEWELLERY COLLECTION", "FINE GOLD & DIAMOND JEWELLERY"];
    let textIndex = 0, charIndex = 0, isDeleting = false, typingSpeed = 100;

    function typeEffect() {
        const el = document.getElementById('typingText');
        if(!el) return;
        const cur = texts[textIndex];
        if(isDeleting) { el.textContent = cur.substring(0, charIndex - 1); charIndex--; typingSpeed = 40; }
        else { el.textContent = cur.substring(0, charIndex + 1); charIndex++; typingSpeed = 90; }
        if(!isDeleting && charIndex === cur.length) { isDeleting = true; typingSpeed = 2500; }
        else if(isDeleting && charIndex === 0) { isDeleting = false; textIndex = (textIndex + 1) % texts.length; typingSpeed = 400; }
        setTimeout(typeEffect, typingSpeed);
    }

    function toggleTheme() {
        const body = document.body;
        const isLight = body.classList.contains('light-theme');
        body.classList.toggle('light-theme', !isLight);
        body.classList.toggle('dark-theme', isLight);
        document.cookie = "theme=" + (isLight ? 'dark' : 'light') + "; path=/; max-age=" + (365*24*60*60);
        createJewelSparkles();
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

        createJewelSparkles();
        setTimeout(typeEffect, 300);

        if (!hasVisited || isReload) {
            sessionStorage.setItem('visited', 'true');
            setTimeout(function() {
                const ov = document.getElementById('loadingOverlay');
                if(ov) { ov.style.opacity = '0'; ov.style.visibility = 'hidden'; setTimeout(()=>ov.style.display='none', 500); }
            }, 1800);
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
<!-- Loading Overlay -->
<div id="loadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;overflow:hidden;transition:opacity 0.6s ease,visibility 0.6s ease;background:radial-gradient(ellipse at 50% 60%, #1a0a00 0%, #0d0500 100%);">

    <!-- Scanlines texture -->
    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>

   
    <!-- Stars / sparkles container -->
    <div id="loaderStars" style="position:absolute;inset:0;pointer-events:none;z-index:2;"></div>

    <!-- Expanding rings container -->
    <div id="loaderRings" style="position:absolute;inset:0;pointer-events:none;z-index:2;display:flex;align-items:center;justify-content:center;"></div>

    <!-- Center content -->
    <div style="position:relative;z-index:10;text-align:center;">

        <!-- Logo with pulsing circular halos -->
        <div style="position:relative;width:120px;height:120px;margin:0 auto 24px;display:flex;align-items:center;justify-content:center;">
            
            
            <div style="width:120px;height:120px;background:transparent;display:flex;align-items:center;justify-content:center;animation:gemGlowPulse 1.5s ease-in-out infinite;">
                <img src="assets/images/moti-removebg-preview.png" alt="MAA GOURI JEWELLERS Logo" style="width:100%;height:100%;object-fit:contain;display:block;">
            </div>
        </div>

        <!-- Title -->
        <div style="color:#d68b16;font-size:22px;letter-spacing:6px;font-family:'Poppins',serif;margin-bottom:6px;animation:titleGold 2s ease infinite alternate;">MAA GOURI JEWELLERS</div>
        <p style="color:rgba(201,169,110,0.7);font-size:10px;letter-spacing:4px;text-transform:uppercase;margin-bottom:24px;">Crafting Timeless Elegance</p>

        <!-- Progress bar -->
        <div style="width:200px;height:3px;background:rgba(255,255,255,0.08);border-radius:3px;margin:0 auto 16px;overflow:hidden;">
            <div style="height:100%;width:35%;background:linear-gradient(90deg,#7a4e0a,#d68b16,#f5c842);border-radius:3px;animation:barSlide 1.8s ease-in-out infinite;"></div>
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

<?php if($is_logged_in): ?>
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
                echo '<img src="'.$path.'" alt="MAA GOURI JEWELLERS Logo" style="height:38px;width:auto;max-width:44px;object-fit:contain;display:inline-block;margin-right:8px;">';
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

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>

        <a href="index.php" class="active">
            <i class="fas fa-home"></i> HOME
        </a>
        <a href="billing.php">
            <i class="fas fa-receipt"></i> BILLING
        </a>
        <a href="stock.php">
            <i class="fas fa-boxes"></i> STOCK
        </a>
        <a href="customers.php">
            <i class="fas fa-users"></i> CUSTOMERS
        </a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>

        <a href="reports.php">
            <i class="fas fa-chart-bar"></i> REPORTS
        </a>
        <a href="due_list.php">
            <i class="fas fa-hourglass-half"></i> DUE LIST
        </a>
        <a href="income_expenses.php">
            <i class="fas fa-chart-line"></i> INCOME & EXP
        </a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>

        <a href="whatsapp_automation.php">
            <i class="fab fa-whatsapp"></i> WHATSAPP
        </a>
        <a href="purchase.php">
            <i class="fas fa-book"></i> PURCHASE
        </a>
        <a href="contacts.php">
            <i class="fas fa-address-book"></i> CONTACTS
        </a>
        <a href="accounts.php">
            <i class="fas fa-calculator"></i> ACCOUNTS
        </a>
    </nav>

    <!-- User Info + Logout -->
    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <p><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <span><?php echo htmlspecialchars($_SESSION['user_mobile'] ?? 'Admin'); ?></span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout">
            <i class="fas fa-sign-out-alt"></i> LOGOUT
        </a>
    </div>
</div>
<!-- ========== END SIDEBAR ========== -->
<?php endif; ?>

<!-- ========== TOP NAVBAR ========== -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="<?php echo $is_logged_in ? 'margin-left:240px;' : ''; ?>">
    <div class="container mx-auto px-4 sm:px-6 py-2.5 sm:py-3">
        <div class="flex justify-between items-center">

            <!-- Left: Logo + Shop Title (Always visible) -->
            <div class="flex items-center space-x-3">
                <?php
                $logo_found = false;
                foreach($logo_paths as $path) {
                    if(file_exists($path)) {
                        echo '<img src="'.$path.'" alt="MAA GOURI JEWELLERS Logo" style="height:40px;width:auto;max-width:44px;object-fit:contain;display:inline-block;">';
                        $logo_found = true; break;
                    }
                }
                if(!$logo_found) echo '<i class="fas fa-gem" style="color:#ffd700;font-size:24px;"></i>';
                ?>
                <div>
                    <h1 class="text-sm sm:text-lg font-bold tracking-wide" style="color:#fff;font-family:\'Poppins\',serif;line-height:1.2;margin:0;">MAA GOURI JEWELLERS</h1>
                    <p class="text-[10px] hidden sm:block" style="color:rgba(255,255,255,0.75);margin:0;letter-spacing:0.5px;">Premium Jewellery Management</p>
                </div>
            </div>

            <!-- Right: User info + Hamburger Menu (on mobile) -->
            <div class="ml-auto flex items-center gap-3 sm:gap-4">
                <?php if($is_logged_in): ?>
                <span class="text-xs sm:text-sm font-medium text-white hidden md:flex items-center gap-2">
                    <i class="fas fa-user-circle" style="color:#ffd700;"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <a href="logout.php" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-red-600/80 hover:bg-red-600 text-white transition-all border border-red-400/40 hidden sm:inline-flex items-center">
                    <i class="fas fa-sign-out-alt mr-1"></i> Logout
                </a>

                <!-- Mobile Hamburger Menu Button (Always on right on mobile) -->
                <div class="mobile-burger" style="display:none;">
                    <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>
                <?php else: ?>
                <a href="login.php" class="btn-jewel" style="padding: 6px 18px; font-size: 12px;">Login</a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</nav>

<!-- ========== PAGE WRAPPER ========== -->
<div class="page-wrapper" style="<?php echo $is_logged_in ? 'margin-left:240px;' : ''; ?>">

<?php if(!$is_logged_in): ?>
<!-- Hero Section -->
<section class="hero-with-logo py-8 sm:py-10 md:py-12 relative" style="background:linear-gradient(135deg, #fdf6e3 0%, #f5ead0 50%, #fdf6e3 100%);">
    <div class="container mx-auto px-4 sm:px-6 text-center">
        <div class="floating-logo mb-6">
            <?php
            $logo_found = false;
            foreach($logo_paths as $path) {
                if(file_exists($path)) {
                    echo '<img src="'.$path.'" alt="MAA GOURI JEWELLERS Logo" style="width:220px;height:220px;max-width:85vw;max-height:220px;object-fit:contain;display:block;margin:0 auto;filter:drop-shadow(0 12px 32px rgba(214,139,22,0.45));">';
                    $logo_found = true; break;
                }
            }
            if(!$logo_found) echo '<i class="fas fa-gem" style="font-size:110px;color:#d68b16;filter:drop-shadow(0 12px 32px rgba(214,139,22,0.45));"></i>';
            ?>
        </div>

        <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-bold mt-4 mb-4" style="min-height:1.2em;">
            <span id="typingText" class="typing-text">MAA GOURI JEWELLERS</span><span class="cursor"></span>
        </h1>

        <p class="text-base sm:text-lg md:text-xl mb-8 max-w-2xl mx-auto" style="color:#7a4e0a;">
            Complete Billing, Stock &amp; Customer Management Solution for Premium Jewellery Businesses
        </p>

        <div class="hero-buttons flex flex-col sm:flex-row justify-center space-y-3 sm:space-y-0 sm:space-x-6">
            <a href="billing.php" class="btn-jewel"><i class="fas fa-receipt mr-2"></i> START BILLING</a>
            <a href="stock.php" class="btn-jewel" style="background:linear-gradient(135deg,#7a4e0a,#d68b16);"><i class="fas fa-boxes mr-2"></i> VIEW STOCK</a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if($is_logged_in): ?>
<section class="py-4" style="background: transparent;">
    <div class="container mx-auto px-4 sm:px-6">
        <h2 class="text-xl font-bold text-center mb-3" style="color:#7a4e0a;"><i class="fas fa-chart-line mr-2" style="color:#d68b16;"></i> Quick Business Dashboard</h2>
        
        <!-- ======== LIVE AMOUNTS SUMMARY STRIP (Top 6 Financial Metrics) ======== -->
        <div class="py-3 px-3 rounded-2xl mb-4 overflow-hidden" style="background:linear-gradient(135deg, #011921 0%, #03373b 50%, #044e54 100%); border: 2.5px solid #ffd700; box-shadow: 0 0 15px rgba(255, 215, 0, 0.5); box-shadow: 0 10px 30px rgba(2,44,34,0.3);">
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <!-- Total Sales -->
                <div class="dark-glass-card glass-gold text-center p-2.5 rounded-xl">
                    <div class="text-lg mb-0.5">💰</div>
                    <div class="text-base font-bold" style="color:#f5c842;">₹<?php echo number_format($total_sales, 0); ?></div>
                    <div class="text-[10px] font-semibold mt-0.5" style="color:rgba(255,255,255,0.85);">TOTAL SALES</div>
                </div>
                <!-- Today's Sales -->
                <div class="dark-glass-card glass-emerald text-center p-2.5 rounded-xl">
                    <div class="text-lg mb-0.5">📊</div>
                    <div class="text-base font-bold" style="color:#86efac;">₹<?php echo number_format($today_sales_amt, 0); ?></div>
                    <div class="text-[10px] font-semibold mt-0.5" style="color:rgba(255,255,255,0.85);">TODAY'S SALES</div>
                    <div class="text-[9px]" style="color:rgba(255,255,255,0.6);"><?php echo $today_invoice_count; ?> invoice<?php echo $today_invoice_count != 1 ? 's' : ''; ?></div>
                </div>
                <!-- Total Purchases -->
                <div class="dark-glass-card glass-rose text-center p-2.5 rounded-xl">
                    <div class="text-lg mb-0.5">🛒</div>
                    <div class="text-base font-bold" style="color:#fda4af;">₹<?php echo number_format($total_purchases, 0); ?></div>
                    <div class="text-[10px] font-semibold mt-0.5" style="color:rgba(255,255,255,0.85);">TOTAL PURCHASES</div>
                </div>
                <!-- Today's Purchases -->
                <div class="dark-glass-card glass-purple text-center p-2.5 rounded-xl">
                    <div class="text-lg mb-0.5">🏷️</div>
                    <div class="text-base font-bold" style="color:#c4b5fd;">₹<?php echo number_format($today_purchases, 0); ?></div>
                    <div class="text-[10px] font-semibold mt-0.5" style="color:rgba(255,255,255,0.85);">TODAY'S PURCHASES</div>
                </div>
                <!-- Outstanding Due -->
                <div class="dark-glass-card glass-orange text-center p-2.5 rounded-xl">
                    <div class="text-lg mb-0.5">⏳</div>
                    <div class="text-base font-bold" style="color:#fb923c;">₹<?php echo number_format($total_due, 0); ?></div>
                    <div class="text-[10px] font-semibold mt-0.5" style="color:rgba(255,255,255,0.85);">OUTSTANDING DUE</div>
                </div>
                <!-- Net Profit -->
                <?php $net_profit = $total_sales - $total_purchases - $total_expense; ?>
                <div class="dark-glass-card glass-teal text-center p-2.5 rounded-xl">
                    <div class="text-lg mb-0.5"><?php echo $net_profit >= 0 ? '📈' : '📉'; ?></div>
                    <div class="text-base font-bold" style="color:<?php echo $net_profit >= 0 ? '#86efac' : '#fca5a5'; ?>;">₹<?php echo number_format(abs($net_profit), 0); ?></div>
                    <div class="text-[10px] font-semibold mt-0.5" style="color:rgba(255,255,255,0.85);"><?php echo $net_profit >= 0 ? 'NET PROFIT' : 'NET LOSS'; ?></div>
                </div>
            </div>
        </div>

        <!-- ======== COMPACT INVENTORY & OPERATIONAL METRICS ======== -->
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
            <a href="stock.php" class="stat-gem p-3 block cursor-pointer text-center">
                <div class="text-xl mb-1" style="color:#b87318;"><i class="fas fa-boxes"></i></div>
                <h3 class="text-lg font-bold text-gray-800"><?php echo number_format($stock_items_count); ?></h3>
                <p class="uppercase text-[10px] tracking-wider mt-0.5" style="color:#7a4e0a; font-weight:600;">Stock Items</p>
            </a>
            <a href="stock.php" class="stat-gem p-3 block cursor-pointer text-center">
                <div class="text-xl mb-1" style="color:#d68b16;"><i class="fas fa-weight-hanging"></i></div>
                <h3 class="text-lg font-bold text-gray-800"><?php echo number_format($stock_quantity); ?></h3>
                <p class="uppercase text-[10px] tracking-wider mt-0.5" style="color:#7a4e0a; font-weight:600;">Stock Qty</p>
            </a>
            <a href="stock.php" class="stat-gem p-3 block cursor-pointer text-center">
                <div class="text-xl mb-1" style="color:#a16207;"><i class="fas fa-coins"></i></div>
                <h3 class="text-lg font-bold text-gray-800">₹<?php echo number_format($stock_value, 0); ?></h3>
                <p class="uppercase text-[10px] tracking-wider mt-0.5" style="color:#7a4e0a; font-weight:600;">Stock Value</p>
            </a>
            <a href="customers.php" class="stat-gem p-3 block cursor-pointer text-center">
                <div class="text-xl mb-1" style="color:#047857;"><i class="fas fa-users"></i></div>
                <h3 class="text-lg font-bold text-gray-800"><?php echo number_format($customers_count); ?></h3>
                <p class="uppercase text-[10px] tracking-wider mt-0.5" style="color:#065f46; font-weight:600;">Customers</p>
            </a>
            <a href="income_expenses.php" class="stat-gem p-3 block cursor-pointer text-center">
                <div class="text-xl mb-1" style="color:#15803d;"><i class="fas fa-arrow-up-right-from-square"></i></div>
                <h3 class="text-lg font-bold text-gray-800">₹<?php echo number_format($total_income, 0); ?></h3>
                <p class="uppercase text-[10px] tracking-wider mt-0.5" style="color:#166534; font-weight:600;">Total Income</p>
            </a>
            <a href="income_expenses.php" class="stat-gem p-3 block cursor-pointer text-center">
                <div class="text-xl mb-1" style="color:#b91c1c;"><i class="fas fa-wallet"></i></div>
                <h3 class="text-lg font-bold text-gray-800">₹<?php echo number_format($total_expense, 0); ?></h3>
                <p class="uppercase text-[10px] tracking-wider mt-0.5" style="color:#9f1239; font-weight:600;">Expenses</p>
            </a>
            <a href="due_list.php" class="stat-gem p-3 block cursor-pointer text-center">
                <div class="text-xl mb-1" style="color:#c2410c;"><i class="fas fa-calendar-check"></i></div>
                <h3 class="text-lg font-bold text-gray-800">₹<?php echo number_format($total_due, 0); ?></h3>
                <p class="uppercase text-[10px] tracking-wider mt-0.5" style="color:#9a3412; font-weight:600;">Due Balance</p>
            </a>
            <a href="due_list.php" class="stat-gem p-3 block cursor-pointer text-center">
                <div class="text-xl mb-1" style="color:#0ea5e9;"><i class="fas fa-bell"></i></div>
                <h3 class="text-lg font-bold text-gray-800"><?php echo number_format($invoice_pending_count); ?></h3>
                <p class="uppercase text-[10px] tracking-wider mt-0.5" style="color:#0369a1; font-weight:600;">Pending Invoices</p>
            </a>
            <a href="income_expenses.php" class="stat-gem p-3 block cursor-pointer text-center col-span-2 sm:col-span-1">
                <div class="text-xl mb-1" style="color:#0891b2;"><i class="fas fa-calendar-alt"></i></div>
                <h3 class="text-lg font-bold text-gray-800">₹<?php echo number_format($monthly_income, 0); ?></h3>
                <p class="uppercase text-[10px] tracking-wider mt-0.5" style="color:#0e7490; font-weight:600;">Monthly Income</p>
            </a>
        </div>
    </div>
</section>

<section class="dashboard-section py-8 sm:py-10 md:py-12">
    <div class="container mx-auto px-4 sm:px-6">
        <div class="grid gap-6 lg:grid-cols-3 mb-8">
            <div class="dashboard-chart lg:col-span-2">
                <div class="dashboard-title"><i class="fas fa-chart-line"></i> Sales Last 7 Days</div>
                <div style="min-height:280px;">
                    <canvas id="salesChart" height="250"></canvas>
                </div>
            </div>
            <div class="top-products-card">
                <div class="dashboard-title"><i class="fas fa-star"></i> Top Products</div>
                <div class="top-products-list">
                    <?php if(empty($top_products)): ?>
                        <p class="text-sm text-gray-500">No product sales available yet.</p>
                    <?php else: ?>
                        <?php foreach($top_products as $product): ?>
                            <div class="top-product-item flex justify-between items-center">
                                <span class="font-semibold text-sm"><?php echo htmlspecialchars($product['name']); ?></span>
                                <span class="text-sm text-gray-600"><?php echo number_format($product['sold']); ?> sold</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="dashboard-chart">
            <div class="dashboard-title"><i class="fas fa-chart-pie"></i> Monthly Income vs Expenses</div>
            <div style="min-height:280px; max-width:520px; margin:0 auto;">
                <canvas id="incomeExpenseChart" height="250"></canvas>
            </div>
            <div class="mt-4 flex flex-wrap justify-center gap-3 text-sm text-gray-600">
                <span class="px-3 py-2 rounded-full bg-green-50 text-green-700">Income: ₹<?php echo number_format($monthly_income, 2); ?></span>
                <span class="px-3 py-2 rounded-full bg-red-50 text-red-700">Expenses: ₹<?php echo number_format($monthly_expense, 2); ?></span>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 mt-8">
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-chart-bar"></i> Monthly Sales Trend</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="monthlySalesChart" height="250"></canvas>
                </div>
            </div>
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-user-plus"></i> Customer Growth</div>
                <div style="min-height:280px;">
                    <canvas id="customerTrendChart" height="250"></canvas>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 mt-8">
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-file-invoice"></i> Invoice Completion Rate</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="invoiceCompletionChart" height="250"></canvas>
                </div>
            </div>
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-chart-pie"></i> Sales by Category</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="categorySalesChart" height="250"></canvas>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 mt-8">
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-file-invoice"></i> Invoice Count Last 7 Days</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="invoiceCountChart" height="250"></canvas>
                </div>
            </div>
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-boxes"></i> Stock Quantity by Category</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="categoryStockChart" height="250"></canvas>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 mt-8">
            <div class="dashboard-chart p-4 sm:p-5">
                <div class="dashboard-title"><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</div>
                <?php if(empty($low_stock_items)): ?>
                    <p class="text-sm text-gray-500">No low-stock products currently.</p>
                <?php else: ?>
                    <ul class="space-y-2 mt-2">
                        <?php foreach($low_stock_items as $item): ?>
                            <li class="flex justify-between items-center border border-white/40 bg-white/20 backdrop-blur-md rounded-xl p-2 sm:p-3">
                                <span class="font-medium text-sm text-gray-700"><?php echo htmlspecialchars($item['name']); ?></span>
                                <span class="text-xs text-red-600 font-semibold bg-red-50/50 px-2.5 py-1 rounded-full border border-red-200"><?php echo number_format($item['quantity']); ?> pcs</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="dashboard-chart p-4 sm:p-5">
                <div class="dashboard-title"><i class="fas fa-file-invoice-dollar"></i> Pending Invoices</div>
                <?php if(empty($pending_invoices)): ?>
                    <p class="text-sm text-gray-500">No pending invoices at the moment.</p>
                <?php else: ?>
                    <ul class="space-y-2 mt-2">
                        <?php foreach($pending_invoices as $invoice): ?>
                            <li class="border border-white/40 bg-white/20 backdrop-blur-md rounded-xl p-2 sm:p-3">
                                <div class="flex justify-between items-start gap-3">
                                    <div>
                                        <p class="font-semibold text-sm text-gray-800"><?php echo htmlspecialchars($invoice['invoice_no']); ?></p>
                                        <p class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($invoice['customer_name']); ?></p>
                                    </div>
                                    <span class="text-xs text-orange-700 font-semibold bg-orange-50/50 px-2.5 py-1 rounded-full border border-orange-200">₹<?php echo number_format($invoice['balance_amount'], 2); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Stats Section -->
<div class="container mx-auto px-4 sm:px-6 py-8">
    <div class="stats-grid grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-5">
        <?php
        $products_count = $conn->query("SELECT COUNT(*) as total FROM products")->fetch_assoc();
        $stock_value    = $conn->query("SELECT SUM(price * quantity) as total FROM products")->fetch_assoc();
        $today_due_cleared_stat = 0;
        $due_chk_stat = $conn->query("SHOW TABLES LIKE 'due_update_history'");
        if($due_chk_stat && $due_chk_stat->num_rows > 0) {
            $due_cleared_row_stat = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) as total FROM due_update_history WHERE DATE(payment_date) = CURDATE()")->fetch_assoc();
            $today_due_cleared_stat = floatval($due_cleared_row_stat['total'] ?? 0);
        }
        $today_inv_row_stat = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE DATE(created_at) = CURDATE()")->fetch_assoc();
        $today_sales = ['total' => floatval($today_inv_row_stat['total'] ?? 0) + $today_due_cleared_stat];
        $customers_count = $conn->query("SELECT COUNT(*) as total FROM customers")->fetch_assoc();
        ?>
        <div class="stat-gem p-5">
            <i class="fas fa-gem mb-3 block"></i>
            <h3><?php echo $products_count['total'] ?? 0; ?></h3>
            <p class="uppercase tracking-wider mt-1">Total Products</p>
        </div>
        <div class="stat-gem p-5">
            <i class="fas fa-rupee-sign mb-3 block"></i>
            <h3>₹<?php echo number_format($stock_value['total'] ?? 0, 0); ?></h3>
            <p class="uppercase tracking-wider mt-1">Stock Value</p>
        </div>
        <div class="stat-gem p-5">
            <i class="fas fa-chart-line mb-3 block"></i>
            <h3>₹<?php echo number_format($today_sales['total'] ?? 0, 0); ?></h3>
            <p class="uppercase tracking-wider mt-1">Today's Sales</p>
        </div>
        <div class="stat-gem p-5">
            <i class="fas fa-users mb-3 block"></i>
            <h3><?php echo $customers_count['total'] ?? 0; ?></h3>
            <p class="uppercase tracking-wider mt-1">Happy Customers</p>
        </div>
    </div>
</div>

<!-- Features Section -->
<section class="py-12 md:py-20" style="background:#fdf6e3;">
    <div class="container mx-auto px-4 sm:px-6">
        <h2 class="text-3xl sm:text-4xl font-bold text-center mb-12" style="color:#800020;">
            ✦ EXCLUSIVE FEATURES ✦
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="gem-card p-8 text-center">
                <div class="gem-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <h3 class="text-xl font-bold mb-3">GST Billing System</h3>
                <p>Create GST and Non-GST invoices with automatic tax calculation</p>
            </div>
            <div class="gem-card p-8 text-center">
                <div class="gem-icon"><i class="fas fa-chart-line"></i></div>
                <h3 class="text-xl font-bold mb-3">Live Stock Tracking</h3>
                <p>Real-time inventory management with low stock alerts</p>
            </div>
            <div class="gem-card p-8 text-center">
                <div class="gem-icon"><i class="fas fa-calculator"></i></div>
                <h3 class="text-xl font-bold mb-3">EMI Calculator</h3>
                <p>Calculate EMI options for your customers easily</p>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="footer-jewel py-10 mt-4">
    <div class="container mx-auto px-4 sm:px-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
            <div>
                <div class="footer-logo">
                    <?php
                    $logo_found = false;
                    foreach($logo_paths as $path) {
                        if(file_exists($path)) { echo '<img src="'.$path.'" alt="Logo">'; $logo_found=true; break; }
                    }
                    if(!$logo_found) echo '<i class="fas fa-gem" style="color:#800020;font-size:28px;"></i>';
                    ?>
                    <h3 class="text-lg font-bold" style="color:#800020;">MAA GOURI JEWELLERS</h3>
                </div>
                <p class="text-sm" style="color:#6b5a3e;">Premium jewellery management system for royal businesses.</p>
            </div>
            <div>
                <h4 class="font-bold mb-4">QUICK LINKS</h4>
                <ul class="space-y-2 text-sm" style="color:#6b5a3e;">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="billing.php">Billing</a></li>
                    <li><a href="stock.php">Stock</a></li>
                    <li><a href="reports.php">Reports</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-bold mb-4">CONTACT</h4>
                <ul class="space-y-2 text-sm" style="color:#6b5a3e;">
                    <li><i class="fas fa-phone mr-2" style="color:#d68b16;"></i> +91 <?php echo htmlspecialchars($COMPANY['mobile'] ?? '8617536679'); ?></li>
                    <li><i class="fas fa-envelope mr-2" style="color:#d68b16;"></i> <?php echo htmlspecialchars($COMPANY['email'] ?? 'Subhapatra169@gmail.com'); ?></li>
                    <li><i class="fas fa-map-marker-alt mr-2" style="color:#d68b16;"></i> <?php echo htmlspecialchars(($COMPANY['address_line1'] ?? 'Temathani, Sabang') . ', ' . ($COMPANY['address_line2'] ?? 'Paschim Medinipur')); ?></li>
                    <li><i class="fas fa-globe mr-2" style="color:#d68b16;"></i> <?php echo htmlspecialchars($COMPANY['state'] ?? 'West Bengal'); ?></li>
                </ul>
            </div>
            <div>
                <h4 class="font-bold mb-4">HOURS</h4>
                <p class="text-sm" style="color:#6b5a3e;">Monday - Sunday: 10AM - 8PM</p>
                <p class="text-sm mt-1" style="color:#6b5a3e;">Thursday: Royal Holiday</p>
            </div>
        </div>
        <div class="mt-8 pt-6 text-center" style="border-top:1px solid rgba(181,115,14,0.25);">
            <p class="text-xs" style="color:#7a4e0a;">
                &copy; <?php echo date('Y'); ?> MAA GOURI JEWELLERS &nbsp;|&nbsp; Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology</a>
            </p>
        </div>
    </div>
</footer>

</div><!-- end .page-wrapper -->

<?php if($is_logged_in): ?>
<script>
    const salesLabels = <?php echo json_encode(array_column($daily_sales, 'date')); ?>;
    const salesValues = <?php echo json_encode(array_column($daily_sales, 'total')); ?>;
    const salesCtx = document.getElementById('salesChart');
    if(salesCtx) {
        const ctx = salesCtx.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 250);
        gradient.addColorStop(0, 'rgba(214, 139, 22, 0.45)');
        gradient.addColorStop(1, 'rgba(214, 139, 22, 0.01)');
        
        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: salesLabels,
                datasets: [{
                    label: 'Sales',
                    data: salesValues,
                    backgroundColor: gradient,
                    borderColor: '#b5730e',
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#d68b16',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    fill: true,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(181, 115, 14, 0.08)' },
                        ticks: {
                            callback: function(value) { return '₹' + value.toLocaleString(); },
                            color: '#7a4e0a',
                            font: { size: 10 }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#7a4e0a', font: { size: 10 } }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

        const incomeExpenseCtx = document.getElementById('incomeExpenseChart');
        if(incomeExpenseCtx) {
            new Chart(incomeExpenseCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Income', 'Expenses'],
                    datasets: [{
                        data: [<?php echo json_encode($monthly_income); ?>, <?php echo json_encode($monthly_expense); ?>],
                        backgroundColor: ['#0d9488', '#be185d'],
                        borderColor: 'rgba(255, 255, 255, 0.6)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 10, padding: 12, font: { size: 11 }, color: '#7a4e0a' }
                        }
                    }
                }
            });
        }

        const monthlySalesCtx = document.getElementById('monthlySalesChart');
        if(monthlySalesCtx) {
            const ctx = monthlySalesCtx.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 250);
            gradient.addColorStop(0, '#d946ef');
            gradient.addColorStop(1, '#f472b6');

            new Chart(monthlySalesCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_sales, 'month')); ?>,
                    datasets: [{
                        label: 'Sales',
                        data: <?php echo json_encode(array_column($monthly_sales, 'total')); ?>,
                        backgroundColor: gradient,
                        borderColor: '#c084fc',
                        borderWidth: 1.5,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            grid: { color: 'rgba(181, 115, 14, 0.08)' },
                            ticks: { callback: function(value) { return '₹' + value.toLocaleString(); }, color: '#7a4e0a', font: { size: 10 } } 
                        },
                        x: { grid: { display: false }, ticks: { color: '#7a4e0a', font: { size: 10 } } }
                    }
                }
            });
        }

        const customerTrendCtx = document.getElementById('customerTrendChart');
        if(customerTrendCtx) {
            const ctx = customerTrendCtx.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 250);
            gradient.addColorStop(0, 'rgba(13, 148, 136, 0.4)');
            gradient.addColorStop(1, 'rgba(13, 148, 136, 0.01)');

            new Chart(customerTrendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($customer_growth, 'date')); ?>,
                    datasets: [{
                        label: 'New Customers',
                        data: <?php echo json_encode(array_column($customer_growth, 'total')); ?>,
                        backgroundColor: gradient,
                        borderColor: '#0d9488',
                        borderWidth: 3,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#14b8a6',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        tension: 0.35,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            grid: { color: 'rgba(181, 115, 14, 0.08)' },
                            ticks: { precision: 0, color: '#7a4e0a', font: { size: 10 } } 
                        },
                        x: { grid: { display: false }, ticks: { color: '#7a4e0a', font: { size: 10 } } }
                    }
                }
            });
        }

        const invoiceCompletionCtx = document.getElementById('invoiceCompletionChart');
        if(invoiceCompletionCtx) {
            new Chart(invoiceCompletionCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Completed', 'Pending'],
                    datasets: [{
                        data: [<?php echo json_encode($invoice_completed_count); ?>, <?php echo json_encode($invoice_pending_count); ?>],
                        backgroundColor: ['#0f766e', '#e11d48'],
                        borderColor: 'rgba(255, 255, 255, 0.6)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 11 }, color: '#7a4e0a' } }
                    }
                }
            });
        }

        const categorySalesCtx = document.getElementById('categorySalesChart');
        if(categorySalesCtx) {
            new Chart(categorySalesCtx, {
                type: 'polarArea',
                data: {
                    labels: <?php echo json_encode(array_column($category_sales, 'category')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_map(function($row){ return (float)$row['revenue']; }, $category_sales)); ?>,
                        backgroundColor: ['#0f766e', '#f472b6', '#d946ef', '#14b8a6', '#f43f5e', '#a21caf'],
                        borderColor: 'rgba(255, 255, 255, 0.6)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            grid: { color: 'rgba(181, 115, 14, 0.08)' },
                            ticks: { display: false }
                        }
                    },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 11 }, color: '#7a4e0a' } }
                    }
                }
            });
        }

        const invoiceCountCtx = document.getElementById('invoiceCountChart');
        if(invoiceCountCtx) {
            const ctx = invoiceCountCtx.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 250);
            gradient.addColorStop(0, 'rgba(217, 70, 239, 0.4)');
            gradient.addColorStop(1, 'rgba(217, 70, 239, 0.01)');

            new Chart(invoiceCountCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($daily_invoice_counts, 'date')); ?>,
                    datasets: [{
                        label: 'Invoices',
                        data: <?php echo json_encode(array_column($daily_invoice_counts, 'total')); ?>,
                        backgroundColor: gradient,
                        borderColor: '#d946ef',
                        borderWidth: 3,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#d946ef',
                        pointRadius: 5,
                        tension: 0.35,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            grid: { color: 'rgba(181, 115, 14, 0.08)' },
                            ticks: { precision: 0, color: '#7a4e0a', font: { size: 10 } } 
                        },
                        x: { grid: { display: false }, ticks: { color: '#7a4e0a', font: { size: 10 } } }
                    }
                }
            });
        }

        const categoryStockCtx = document.getElementById('categoryStockChart');
        if(categoryStockCtx) {
            const ctx = categoryStockCtx.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 250);
            gradient.addColorStop(0, 'rgba(13, 148, 136, 0.4)');
            gradient.addColorStop(1, 'rgba(13, 148, 136, 0.01)');

            new Chart(categoryStockCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($category_stock, 'category')); ?>,
                    datasets: [{
                        label: 'Stock Quantity',
                        data: <?php echo json_encode(array_map(function($row){ return (float)$row['total_qty']; }, $category_stock)); ?>,
                        backgroundColor: gradient,
                        borderColor: '#0d9488',
                        borderWidth: 3,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#0d9488',
                        pointRadius: 5,
                        tension: 0.35,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            grid: { color: 'rgba(181, 115, 14, 0.08)' },
                            ticks: { precision: 0, color: '#7a4e0a', font: { size: 10 } } 
                        },
                        x: { grid: { display: false }, ticks: { color: '#7a4e0a', font: { size: 10 } } }
                    }
                }
            });
        }
    </script>
    <?php endif; ?>
</html>








