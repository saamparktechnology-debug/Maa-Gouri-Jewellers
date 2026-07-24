<?php
$host     = getenv('DB_HOST') ?: '127.0.0.1';
$user     = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$database = getenv('DB_NAME') ?: 'gouri';

// Turn off mysqli exception throwing temporarily during connection attempt
mysqli_report(MYSQLI_REPORT_OFF);

$conn = false;
$errors = [];

// Standard Linux / aaPanel socket locations
$possible_sockets = [
    '/tmp/mysql.sock',
    '/var/run/mysqld/mysqld.sock',
    '/var/lib/mysql/mysql.sock'
];

$targets = [
    ['host' => '127.0.0.1', 'port' => 3306, 'socket' => null],
    ['host' => 'localhost', 'port' => 3306, 'socket' => null],
];

foreach ($possible_sockets as $sock) {
    if (file_exists($sock)) {
        array_unshift($targets, ['host' => 'localhost', 'port' => 3306, 'socket' => $sock]);
    }
}

// Passwords to attempt if primary fails
$passwords_to_try = array_unique(array_filter([$password, '', 'RootPaas123', 'root', '123456'], function($val) { return $val !== null; }));

foreach ($targets as $t) {
    foreach ($passwords_to_try as $p) {
        $c = @mysqli_connect($t['host'], $user, $p, $database, $t['port'], $t['socket']);
        if ($c) {
            $conn = $c;
            break 2;
        } else {
            $err = mysqli_connect_error();
            if ($err) $errors[] = $t['host'] . ($t['socket'] ? " ({$t['socket']})" : "") . ": " . $err;
        }
    }
}

// If DB doesn't exist yet, try creating it
if (!$conn) {
    foreach ($targets as $t) {
        foreach ($passwords_to_try as $p) {
            $c = @mysqli_connect($t['host'], $user, $p, '', $t['port'], $t['socket']);
            if ($c) {
                @mysqli_query($c, "CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                @mysqli_close($c);
                $conn = @mysqli_connect($t['host'], $user, $p, $database, $t['port'], $t['socket']);
                if ($conn) break 2;
            }
        }
    }
}

// Restore default mysqli report mode
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!$conn) {
    $err_details = !empty($errors) ? implode(' | ', array_unique($errors)) : 'MySQL service unreachable';
    die("<div style='font-family:sans-serif;padding:30px;background:#fff5f5;border:1px solid #feb2b2;color:#9b2c2c;margin:40px auto;max-width:640px;border-radius:8px;'>"
        . "<h3 style='margin-top:0;'>⚠️ Database Connection Failed</h3>"
        . "<p><strong>Diagnostic details:</strong><br><code style='background:#edf2f7;padding:4px 8px;border-radius:4px;font-size:12px;'>" . htmlspecialchars($err_details) . "</code></p>"
        . "</div>");
}

// Set timezone
date_default_timezone_set('Asia/Kolkata');

// Create tables if not exist
$create_users = "CREATE TABLE IF NOT EXISTS users (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100) NOT NULL,
mobile VARCHAR(15) UNIQUE NOT NULL,
email VARCHAR(100),
password VARCHAR(255) NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_users);

$create_products = "CREATE TABLE IF NOT EXISTS products (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(200) NOT NULL,
category VARCHAR(50),
price DECIMAL(10,2) NOT NULL,
quantity INT DEFAULT 0,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_products);

// Ensure required columns exist on products table
$products_cols = [
    'serial_no' => "VARCHAR(50) NULL",
    'weight'    => "VARCHAR(20) NULL",
    'item_name' => "VARCHAR(255) DEFAULT ''",
    'huid_code' => "VARCHAR(100) NULL",
    'hsn_code'  => "VARCHAR(50) DEFAULT '0'",
];

foreach ($products_cols as $col_name => $col_definition) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE '$col_name'");
    if ($chk && mysqli_num_rows($chk) == 0) {
        $unique = ($col_name === 'serial_no') ? " UNIQUE" : "";
        mysqli_query($conn, "ALTER TABLE products ADD COLUMN $col_name $col_definition$unique");
    }
}

$create_customers = "CREATE TABLE IF NOT EXISTS customers (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100) NOT NULL,
mobile VARCHAR(15) UNIQUE NOT NULL,
email VARCHAR(100),
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_customers);

// Ensure required columns exist on customers table
$customers_cols = [
    'address'    => "TEXT NULL",
    'email'      => "VARCHAR(255) NULL",
    'gst_number' => "VARCHAR(20) DEFAULT ''",
];

foreach ($customers_cols as $col_name => $col_definition) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE '$col_name'");
    if ($chk && mysqli_num_rows($chk) == 0) {
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN $col_name $col_definition");
    }
}

$create_sanchari_customers = "CREATE TABLE IF NOT EXISTS sanchari_customers (
id INT AUTO_INCREMENT PRIMARY KEY,
customer_id VARCHAR(20) UNIQUE NOT NULL,
book_id VARCHAR(20) UNIQUE NOT NULL,
customer_name VARCHAR(100) NOT NULL,
mobile VARCHAR(15) NOT NULL,
email VARCHAR(100),
address TEXT,
joining_date DATE NOT NULL,
monthly_amount DECIMAL(10,2) NOT NULL,
scheme_duration VARCHAR(20) NOT NULL,
status VARCHAR(20) NOT NULL DEFAULT 'Active',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_sanchari_customers);

$create_sanchari_payments = "CREATE TABLE IF NOT EXISTS sanchari_payments (
id INT AUTO_INCREMENT PRIMARY KEY,
payment_id VARCHAR(20) UNIQUE NOT NULL,
customer_id VARCHAR(20) NOT NULL,
book_id VARCHAR(20) NOT NULL,
customer_name VARCHAR(100) NOT NULL,
installment_no INT NOT NULL,
payment_date DATE NOT NULL,
amount DECIMAL(10,2) NOT NULL,
gold_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
weight DECIMAL(10,3) NOT NULL DEFAULT 0,
payment_mode VARCHAR(30) NOT NULL,
remarks TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_sanchari_payments);

$create_sanchari_redemptions = "CREATE TABLE IF NOT EXISTS sanchari_redemptions (
id INT AUTO_INCREMENT PRIMARY KEY,
redemption_id VARCHAR(20) UNIQUE NOT NULL,
customer_id VARCHAR(20) NOT NULL,
book_id VARCHAR(20) NOT NULL,
purchase_date DATE NOT NULL,
item_name VARCHAR(150) NOT NULL,
gross_weight DECIMAL(10,3) DEFAULT 0,
net_weight DECIMAL(10,3) DEFAULT 0,
making_charge DECIMAL(10,2) DEFAULT 0,
gst DECIMAL(10,2) DEFAULT 0,
jewellery_amount DECIMAL(10,2) DEFAULT 0,
adjusted_amount DECIMAL(10,2) DEFAULT 0,
balance_amount DECIMAL(10,2) DEFAULT 0,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_sanchari_redemptions);

$create_invoices = "CREATE TABLE IF NOT EXISTS invoices (
id INT AUTO_INCREMENT PRIMARY KEY,
invoice_no VARCHAR(50) UNIQUE NOT NULL,
customer_name VARCHAR(100) NOT NULL,
customer_mobile VARCHAR(15) NOT NULL,
gst_type ENUM('gst_3', 'gst_18', 'non_gst') DEFAULT 'non_gst',
subtotal DECIMAL(10,2) DEFAULT 0,
gst_amount DECIMAL(10,2) DEFAULT 0,
total_amount DECIMAL(10,2) DEFAULT 0,
created_by INT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_invoices);

// Ensure all required columns exist on invoices table
$columns_to_ensure = [
    'customer_id'      => "INT NULL",
    'customer_address' => "TEXT NULL",
    'customer_gstin'   => "VARCHAR(50) NULL",
    'discount'         => "DECIMAL(10,2) DEFAULT 0",
    'payment_status'   => "VARCHAR(20) DEFAULT 'pending'",
    'payment_method'   => "VARCHAR(20) DEFAULT 'Cash'",
    'paid_amount'      => "DECIMAL(10,2) DEFAULT 0",
    'balance_amount'   => "DECIMAL(10,2) DEFAULT 0",
    'huid_code'        => "VARCHAR(100) NULL",
    'cash_paid'        => "DECIMAL(10,2) DEFAULT 0",
    'upi_paid'         => "DECIMAL(10,2) DEFAULT 0",
    'account_paid'     => "DECIMAL(10,2) DEFAULT 0",
    'due_date'         => "DATE NULL",
    'reminder_sent'    => "TINYINT(1) DEFAULT 0",
    'pdf_file'         => "LONGBLOB NULL",
    'pdf_file_name'    => "VARCHAR(255) NULL",
];

foreach ($columns_to_ensure as $col_name => $col_definition) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE '$col_name'");
    if ($chk && mysqli_num_rows($chk) == 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN $col_name $col_definition");
    }
}

$create_invoice_items = "CREATE TABLE IF NOT EXISTS invoice_items (
id INT AUTO_INCREMENT PRIMARY KEY,
invoice_id INT,
product_id INT,
quantity INT,
price DECIMAL(10,2),
total DECIMAL(10,2)
)";
mysqli_query($conn, $create_invoice_items);

// Ensure required columns exist on invoice_items table
$invoice_items_cols = [
    'product_name'  => "VARCHAR(200) NULL",
    'serial_no'     => "VARCHAR(100) NULL",
    'hsn_code'      => "VARCHAR(50) NULL",
    'making_charge' => "DECIMAL(10,2) DEFAULT 0",
    'hallmark'      => "DECIMAL(10,2) DEFAULT 0",
    'discount'      => "DECIMAL(10,2) DEFAULT 0",
    'huid_code'     => "VARCHAR(100) NULL",
];

foreach ($invoice_items_cols as $col_name => $col_definition) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE '$col_name'");
    if ($chk && mysqli_num_rows($chk) == 0) {
        mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN $col_name $col_definition");
    }
}

// Modify quantity column in invoice_items if it is not DECIMAL
$chk_qty = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'quantity'");
if ($chk_qty && mysqli_num_rows($chk_qty) > 0) {
    $row = mysqli_fetch_assoc($chk_qty);
    if (stripos($row['Type'] ?? '', 'decimal') === false) {
        mysqli_query($conn, "ALTER TABLE invoice_items MODIFY COLUMN quantity DECIMAL(10,3) NULL");
    }
}

// Ensure default required admin accounts exist (only insert if missing, do not overwrite existing passwords)
$admin_pass_hash = password_hash('123456', PASSWORD_DEFAULT);
$required_admins = [
    ['admin@gourijewellers.com', '9647291299', 'Gouri Admin'],
    ['subhapatra169@gmail.com', '9635985848', 'Subha Patra Admin'],
    ['saamparktechnology@gmail.com', '8617536679', 'Saampark Admin'],
    ['hiisupriya@gmail.com', '9876543210', 'Supriya Admin']
];

foreach ($required_admins as $adm) {
    $adm_email = $adm[0];
    $adm_mob   = $adm[1];
    $adm_name  = $adm[2];
    
    $chk_adm = mysqli_query($conn, "SELECT id FROM users WHERE email = '$adm_email' OR mobile = '$adm_mob'");
    if (!$chk_adm || mysqli_num_rows($chk_adm) == 0) {
        mysqli_query($conn, "INSERT INTO users (name, mobile, email, password) VALUES ('$adm_name', '$adm_mob', '$adm_email', '$admin_pass_hash')");
    }
}

// Create purchase_entries table if not exists
$create_purchase_entries = "CREATE TABLE IF NOT EXISTS purchase_entries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    purchase_no     VARCHAR(50)  NOT NULL UNIQUE,
    purchase_date   DATE         NOT NULL,
    invoice_no      VARCHAR(100) NOT NULL,
    invoice_date    DATE         NOT NULL,
    ref_no          VARCHAR(100),
    ref_date        DATE,
    payment_mode    VARCHAR(50)  DEFAULT 'NEFT/RTGS',
    supplier_name   VARCHAR(200) NOT NULL,
    supplier_addr   VARCHAR(500),
    supplier_gstin  VARCHAR(20),
    supplier_pan    VARCHAR(20),
    supplier_state  VARCHAR(100),
    supplier_state_code VARCHAR(5),
    supplier_mobile VARCHAR(20),
    supplier_email  VARCHAR(100),
    buyer_name      VARCHAR(200) DEFAULT 'MAA GOURI JEWELLERS',
    buyer_addr      VARCHAR(500),
    buyer_gstin     VARCHAR(20),
    buyer_pan       VARCHAR(20),
    material_type   ENUM('Gold','Silver','Diamond','Platinum') NOT NULL,
    description     VARCHAR(300),
    huid_code       VARCHAR(100),
    hsn_sac         VARCHAR(20),
    qty             DECIMAL(12,4) NOT NULL,
    unit            VARCHAR(10)  DEFAULT 'gm',
    rate_per_unit   DECIMAL(12,4) NOT NULL,
    tax_type        ENUM('CGST_SGST','IGST') DEFAULT 'CGST_SGST',
    cgst_pct        DECIMAL(5,2) DEFAULT 1.50,
    sgst_pct        DECIMAL(5,2) DEFAULT 1.50,
    igst_pct        DECIMAL(5,2) DEFAULT 3.00,
    subtotal        DECIMAL(14,2),
    cgst_amt        DECIMAL(14,2) DEFAULT 0,
    sgst_amt        DECIMAL(14,2) DEFAULT 0,
    igst_amt        DECIMAL(14,2) DEFAULT 0,
    gst_total       DECIMAL(14,2),
    total_amount    DECIMAL(14,2),
    amount_words    VARCHAR(500),
    notes           TEXT,
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_purchase_entries);

// Ensure HUID column exists for purchase_entries
$chk_huid = mysqli_query($conn, "SHOW COLUMNS FROM purchase_entries LIKE 'huid_code'");
if ($chk_huid && mysqli_num_rows($chk_huid) == 0) {
    mysqli_query($conn, "ALTER TABLE purchase_entries ADD COLUMN huid_code VARCHAR(100) NULL AFTER description");
}

// Create stock_metal table if not exists
$create_stock_metal = "CREATE TABLE IF NOT EXISTS stock_metal (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    material_type ENUM('Gold','Silver','Diamond','Platinum') NOT NULL,
    unit          VARCHAR(10) DEFAULT 'gm',
    qty_available DECIMAL(14,4) DEFAULT 0,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_material (material_type)
)";
mysqli_query($conn, $create_stock_metal);

// Seed stock metal rows if empty
foreach (['Gold', 'Silver', 'Diamond', 'Platinum'] as $m) {
    mysqli_query($conn, "INSERT IGNORE INTO stock_metal (material_type, qty_available) VALUES ('$m', 0)");
}

// Set session user if needed
if(isset($_SESSION['user_id'])) {
    $check = mysqli_query($conn, "SELECT id FROM users WHERE id = '{$_SESSION['user_id']}'");
    if(mysqli_num_rows($check) == 0) {
        $admin = mysqli_query($conn, "SELECT id FROM users WHERE mobile = '9647291299'");
        $admin_row = mysqli_fetch_assoc($admin);
        $_SESSION['user_id'] = $admin_row['id'];
        $_SESSION['user_name'] = 'Gouri Admin';
        $_SESSION['user_mobile'] = '9647291299';
    }
}
