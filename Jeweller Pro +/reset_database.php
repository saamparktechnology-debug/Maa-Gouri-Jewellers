<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

// Disable foreign key checks to allow truncating tables cleanly
mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");

$tables_to_truncate = [
    'users',
    'products',
    'customers',
    'invoices',
    'invoice_items',
    'due_update_history',
    'purchase_entries',
    'stock_metal',
    'sanchari_customers',
    'sanchari_payments',
    'sanchari_redemptions',
    'income',
    'expenses',
    'income_categories',
    'expense_categories',
    'advance_customers',
    'whatsapp_logs',
    'otp_logins'
];

foreach ($tables_to_truncate as $table) {
    @mysqli_query($conn, "TRUNCATE TABLE `$table`");
}

// Reset stock metal default rows
foreach (['Gold', 'Silver', 'Diamond', 'Platinum'] as $m) {
    @mysqli_query($conn, "INSERT IGNORE INTO stock_metal (material_type, qty_available) VALUES ('$m', 0)");
}

// Re-enable foreign key checks
mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");

// Ensure required admin accounts exist with exact password hash for 123456
$admin_pass_hash = password_hash('123456', PASSWORD_DEFAULT);
$required_admins = [
    ['subhapatra169@gmail.com', '9635985848', 'Subha Patra Admin'],
    ['admin@gourijewellers.com', '9635985849', 'Moti Admin'],
    ['saamparktechnology@gmail.com', '8617536679', 'Saampark Admin'],
    ['hiisupriya@gmail.com', '9876543210', 'Supriya Admin']
];

foreach ($required_admins as $adm) {
    $adm_email = $adm[0];
    $adm_mob   = $adm[1];
    $adm_name  = $adm[2];
    
    mysqli_query($conn, "INSERT INTO users (name, mobile, email, password) VALUES ('$adm_name', '$adm_mob', '$adm_email', '$admin_pass_hash') ON DUPLICATE KEY UPDATE password = '$admin_pass_hash', email = '$adm_email', name = '$adm_name'");
}

echo "<div style='font-family:sans-serif;padding:30px;max-width:600px;margin:50px auto;background:#f0fdf4;border:1px solid #86efac;border-radius:12px;color:#166534;'>";
echo "<h2>✅ Database Reset Complete!</h2>";
echo "<p>All stock, products, customers, invoices, due records, purchases, and sanchari entries have been completely cleared for a fresh start.</p>";
echo "<p>Admin logins created (Password: <code>123456</code>):</p>";
echo "<ul>";
echo "<li><strong>subhapatra169@gmail.com</strong> (Mobile: 9635985848)</li>";
echo "<li><strong>admin@gourijewellers.com</strong></li>";
echo "<li><strong>saamparktechnology@gmail.com</strong></li>";
echo "</ul>";
echo "<a href='index.php' style='display:inline-block;margin-top:15px;padding:10px 20px;background:#15803d;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;'>Return to Home</a>";
echo "</div>";
?>


