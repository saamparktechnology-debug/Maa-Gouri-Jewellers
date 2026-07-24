<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

$is_logged_in = isset($_SESSION['user_id']);
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="author" content="MANU GUPTA">
    <title>Sanchari | MAA GOURI JEWELLERS</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Poppins', serif; }

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

        .sidebar-logout,
        .sidebar-login-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .sidebar-logout {
            background: rgba(239,68,68,0.75);
            color: #fff;
            border-color: rgba(239,68,68,0.4);
        }

        .sidebar-logout:hover { background: #ef4444; }
        .sidebar-login-btn { background: rgba(255,255,255,0.2); color: #fff; }
        .sidebar-login-btn:hover { background: rgba(255,255,255,0.3); }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active { display: block; }

        .page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; }

        nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important;
            margin-left: 0;
        }

        nav.nav-gold h1,
        nav.nav-gold p,
        nav.nav-gold span { color: #ffffff !important; }

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

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .page-wrapper { margin-left: 0 !important; }
            .mobile-burger { display: block !important; }
        }

        @media (min-width: 769px) { .mobile-burger { display: none !important; } }

        .hero-with-logo { text-align: center; }
        .typing-text { background: linear-gradient(135deg, #800020, #c9a96e, #d68b16); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-family: 'Poppins', serif; }
        .cursor { display: inline-block; width: 3px; height: 1em; background: #d68b16; margin-left: 4px; vertical-align: middle; animation: blink 0.8s infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

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

        .form-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 25px 80px rgba(0,0,0,0.08);
            border: 1px solid rgba(214,139,22,0.12);
        }

        .form-input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid rgba(148,163,184,0.25);
            background: #fbfaf8;
            color: #334155;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-input:focus { border-color: #d68b16; box-shadow: 0 0 0 4px rgba(214,139,22,0.1); }
        .form-label { color: #7a4e0a; font-weight: 600; }
        .required { color: #dc2626; }

        body.light-theme { background:#F5F5F5; }
        body.dark-theme { background:#201d1b; color:#f8fafc; }



    
    </style>
</head>
<body class="<?php echo $theme == 'light' ? 'light-theme' : 'dark-theme'; ?>" style="background:#F5F5F5; margin:0; padding:0;">

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

<div id="loadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;overflow:hidden;transition:opacity 0.6s ease,visibility 0.6s ease;background:radial-gradient(ellipse at 50% 60%, #1a0a00 0%, #0d0500 100%);">
    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>
    <div style="position:absolute;top:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite;">✦</div>
    <div style="position:absolute;top:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 1s;">✦</div>
    <div style="position:absolute;bottom:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 2s;">✦</div>
    <div style="position:absolute;bottom:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 3s;">✦</div>
    <div style="position:relative;z-index:10;text-align:center;">
                <div style="position:relative;width:120px;height:120px;margin:0 auto 24px;display:flex;align-items:center;justify-content:center;">
            
            
            <div style="width:120px;height:120px;background:transparent;animation:gemGlowPulse 1.5s ease-in-out infinite;">
                <img src="assets/images/moti-removebg-preview.png" alt="MAA GOURI JEWELLERS Logo" style="width:100%;height:100%;object-fit:contain;display:block;">
            </div>
        </div>
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
    </style>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
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

    <div class="sidebar-user">
        <?php if($is_logged_in): ?>
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
        <?php else: ?>
        <a href="login.php" class="sidebar-login-btn">
            <i class="fas fa-sign-in-alt"></i> LOGIN
        </a>
        <?php endif; ?>
    </div>
</div>

<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">
            <div></div>
            <div class="ml-auto flex items-center gap-4">
                <?php if($is_logged_in): ?>
                <span class="text-sm font-medium text-white">
                    <i class="fas fa-user mr-1"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <?php endif; ?>
                <div class="mobile-burger" style="display:none;">
                    <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="page-wrapper">
    <section class="hero-with-logo py-12 sm:py-16 md:py-20 relative" style="background:linear-gradient(135deg, #fdf6e3 0%, #f5ead0 50%, #fdf6e3 100%);">
        <div class="container mx-auto px-4 sm:px-6 text-center">
            <div class="floating-logo mb-6">
                <?php
                $logo_found = false;
                foreach($logo_paths as $path) {
                    if(file_exists($path)) {
                        echo '<img src="'.$path.'" alt="MAA GOURI JEWELLERS Logo" style="height:38px;width:auto;max-width:44px;object-fit:contain;display:inline-block;margin-right:8px;">';
                        $logo_found = true; break;
                    }
                }
                if(!$logo_found) echo '<i class="fas fa-gem" style="font-size:80px;color:#d68b16;"></i>';
                ?>
            </div>

            <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-bold mt-4 mb-4" style="min-height:1.2em;">
                <span id="typingText" class="typing-text"></span><span class="cursor"></span>
            </h1>

            <p class="text-base sm:text-lg md:text-xl mb-8 max-w-2xl mx-auto" style="color:#7a4e0a;">
                Gold Advance Booking / Swarna Sanchay Management System with registration, payments, passbook, redemption and reports.
            </p>

            <div class="hero-buttons flex flex-col sm:flex-row justify-center space-y-3 sm:space-y-0 sm:space-x-6">
                <a href="sanchari_register.php" class="btn-jewel"><i class="fas fa-user-plus mr-2"></i> NEW CUSTOMER</a>
                <a href="sanchari_payment.php" class="btn-jewel" style="background:linear-gradient(135deg,#7a4e0a,#d68b16);"><i class="fas fa-coins mr-2"></i> PAYMENT ENTRY</a>
            </div>
        </div>
    </section>

    <div class="container mx-auto px-4 sm:px-6 py-10">
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="form-card p-8">
                <h3 class="text-2xl font-bold mb-3" style="color:#800020;">MAA GOURI JEWELLERS Gold Advance Scheme</h3>
                <p class="text-sm text-gray-600 mb-4">Complete modules for customer registration, payment entry, passbook viewing, redemption, reports and dashboard are now available from this page.</p>
                <ul class="list-unstyled text-sm text-gray-700 space-y-2">
                    <li><i class="fas fa-check text-success me-2"></i> Auto-generated Customer ID / Book ID / Payment ID</li>
                    <li><i class="fas fa-check text-success me-2"></i> Gold weight auto calculated from amount and rate</li>
                    <li><i class="fas fa-check text-success me-2"></i> Passbook, reports, redemption and dashboard modules</li>
                </ul>
            </div>

 <!-- ===================================================================== -->
   <div style="background:#e8f5e9; border-radius:18px; border:1px solid #c8e6c9; box-shadow:0 8px 25px rgba(0,0,0,0.08); padding:24px;">
    <h4 style="color:#c7522a; font-weight:700; border-bottom:2px solid rgba(199,82,42,0.2); padding-bottom:10px; margin-bottom:16px; font-size:16px;">
        <i class="fas fa-bolt" style="margin-right:8px;"></i>Quick Access
    </h4>

    <div style="display:flex; flex-direction:column; gap:10px;">
        <a href="sanchari_register.php" style="background:#fff; color:#333; border:1px solid #c8e6c9; font-weight:600; padding:12px 16px; border-radius:12px; text-decoration:none; display:flex; align-items:center; transition:all 0.3s ease;">
            <i class="fas fa-user-plus" style="width:25px; margin-right:8px;"></i> Customer Registration
        </a>
        <a href="sanchari_payment.php" style="background:#fff; color:#333; border:1px solid #c8e6c9; font-weight:600; padding:12px 16px; border-radius:12px; text-decoration:none; display:flex; align-items:center;">
            <i class="fas fa-coins" style="width:25px; margin-right:8px;"></i> Payment Entry
        </a>
        <a href="sanchari_dashboard.php" style="background:#fff; color:#333; border:1px solid #c8e6c9; font-weight:600; padding:12px 16px; border-radius:12px; text-decoration:none; display:flex; align-items:center;">
            <i class="fas fa-chart-line" style="width:25px; margin-right:8px;"></i> Dashboard
        </a>
        
        <a href="sanchari_redemption.php" style="background:#fff; color:#333; border:1px solid #c8e6c9; font-weight:600; padding:12px 16px; border-radius:12px; text-decoration:none; display:flex; align-items:center;">
            <i class="fas fa-gem" style="width:25px; margin-right:8px;"></i> Gold Redemption
        </a>
        <a href="sanchari_reports.php" style="background:#fff; color:#333; border:1px solid #c8e6c9; font-weight:600; padding:12px 16px; border-radius:12px; text-decoration:none; display:flex; align-items:center;">
            <i class="fas fa-file-alt" style="width:25px; margin-right:8px;"></i> Reports
        </a>
    </div>
</div>


            <div class="form-card p-6">
                <h4 class="mb-3" style="color:#c7522a;">Terms & Conditions</h4>
                <ol class="small text-gray-700 ps-3 mb-0">
                    <li>Deposit continuously for 11 months and receive scheme benefit in the 12th month.</li>
                    <li>Passbook loss requires customer verification.</li>
                    <li>Gold weight is calculated as per the gold rate on the payment date.</li>
                    <li>Missing installments may cancel scheme benefits.</li>
                </ol>
            </div>
        </div>
        <div class="form-card p-8 mt-6">
            <h3 class="text-2xl font-bold mb-4" style="color:#800020;">Current Status</h3>
            <p class="text-sm text-gray-600">The new Sanchari modules are ready under the project root. Use the buttons above to open the registration, payment, dashboard, passbook, redemption and reports pages.</p>
        </div>
    </div>

    <footer class="footer-jewel py-10 mt-4" style="background:linear-gradient(0deg, #f5e6c8, #fdf6e3); border-top: 2px solid #d68b16; margin-left:240px;">
        <div class="container mx-auto px-4 sm:px-6 text-center">
            <p class="text-xs" style="color:#7a4e0a;">
                &copy; <?php echo date('Y'); ?> MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
                Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology</a>
            </p>
        </div>
    </footer>
</div>

</body>
</html>





