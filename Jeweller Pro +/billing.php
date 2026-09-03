<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require_once 'config/database.php';
require_once 'config/company_config.php';
require_once 'config/mail_config.php';
require_once 'includes/activity_logger.php';


$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Server-side initial pre-fetch for metal rates so the user NEVER sees "Loading..." or dashes
$initial_metal_rates = null;
$metal_cache_file = sys_get_temp_dir() . '/radhe_shyam_metal_rates.json';
if(file_exists($metal_cache_file)) {
    $initial_metal_rates = json_decode(file_get_contents($metal_cache_file), true);
}
if(!$initial_metal_rates) {
    $initial_metal_rates = [
        'success' => true,
        'gold24' => 78500,
        'gold22' => 72000,
        'gold18' => 58875,
        'gold12' => 39250,
        'gold9' => 29437,
        'silver' => 920,
        'platinum' => 32000,
        'fallback' => true,
        'source' => 'Market Rate'
    ];
}

// AJAX: Search bills by mobile number
if(isset($_GET['action']) && $_GET['action'] === 'search_mobile') {
    header('Content-Type: application/json');
    $mobile = mysqli_real_escape_string($conn, trim($_GET['mobile'] ?? ''));
    if(empty($mobile)) {
        echo json_encode(['success' => false, 'message' => 'Mobile number required']);
        exit();
    }
    $q = "SELECT i.invoice_no, i.customer_name, i.customer_mobile, i.customer_address,
                 i.total_amount, i.gst_type, i.created_at
          FROM invoices i
          WHERE i.customer_mobile LIKE '%$mobile%'
          ORDER BY i.created_at DESC
          LIMIT 50";
    $res = mysqli_query($conn, $q);
    $bills = [];
    if($res) {
        while($row = mysqli_fetch_assoc($res)) {
            $bills[] = $row;
        }
    }
    echo json_encode(['success' => true, 'bills' => $bills, 'count' => count($bills)]);
    exit();
}

// AJAX: Get all customers with active advance credit balance
if(isset($_GET['action']) && $_GET['action'] === 'get_advance_customers') {
    header('Content-Type: application/json');
    $q = mysqli_query($conn, "
        SELECT id, receipt_no, customer_name, customer_mobile, customer_address, advance_amount, used_amount, available_amount, created_at 
        FROM customer_advances 
        WHERE available_amount > 0 AND status != 'cancelled' 
        ORDER BY created_at DESC
    ");
    $adv_list = [];
    if($q) {
        while($r = mysqli_fetch_assoc($q)) {
            $adv_list[] = $r;
        }
    }
    echo json_encode(['success' => true, 'customers' => $adv_list]);
    exit();
}

// AJAX: Check if customer has active advance credit by mobile or name
if(isset($_GET['action']) && $_GET['action'] === 'check_customer_advance') {
    header('Content-Type: application/json');
    $mobile = mysqli_real_escape_string($conn, trim($_GET['mobile'] ?? ''));
    $name   = mysqli_real_escape_string($conn, trim($_GET['name'] ?? ''));
    
    $where = [];
    if(!empty($mobile)) $where[] = "customer_mobile = '$mobile'";
    if(!empty($name))   $where[] = "customer_name = '$name' OR customer_name LIKE '%$name%'";
    
    if(empty($where)) {
        echo json_encode(['success' => true, 'has_advance' => false]);
        exit();
    }
    
    $w_sql = implode(' OR ', $where);
    $q = mysqli_query($conn, "
        SELECT id, receipt_no, customer_name, customer_mobile, customer_address, advance_amount, used_amount, available_amount 
        FROM customer_advances 
        WHERE ($w_sql) AND available_amount > 0 AND status != 'cancelled' 
        ORDER BY available_amount DESC, created_at DESC LIMIT 1
    ");
    if($q && $row = mysqli_fetch_assoc($q)) {
        echo json_encode(['success' => true, 'has_advance' => true, 'advance' => $row]);
    } else {
        echo json_encode(['success' => true, 'has_advance' => false]);
    }
    exit();
}


// AJAX: Save custom shop rates to server config file
if(isset($_GET['action']) && $_GET['action'] === 'save_shop_rates') {
    header('Content-Type: application/json');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if($data && is_array($data)) {
        $ratesFile = __DIR__ . '/config/shop_rates.json';
        $existing = file_exists($ratesFile) ? (json_decode(file_get_contents($ratesFile), true) ?: []) : [];
        $merged = array_merge($existing, $data);
        $merged['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($ratesFile, json_encode($merged, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'rates' => $merged]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid rate data']);
    }
    exit();
}

// Load saved shop rates from config/shop_rates.json for PHP pre-filling
$savedShopRatesFile = __DIR__ . '/config/shop_rates.json';
$savedShopRates = file_exists($savedShopRatesFile) ? (json_decode(file_get_contents($savedShopRatesFile), true) ?: []) : [];

// AJAX: Send part-payment reminder email
if(isset($_GET['action']) && $_GET['action'] === 'send_reminder') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    // Debug log helper for reminders
    function _sr_log($data) {
        $f = '/tmp/send_reminder_debug.log';
        $line = '['.date('Y-m-d H:i:s').'] ' . json_encode($data) . "\n";
        @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
    }
    _sr_log(['start', '_POST' => $_POST, 'REMOTE' => $_SERVER['REMOTE_ADDR'] ?? '']);
    $customer_email  = trim($_POST['customer_email'] ?? '');
    $customer_name   = trim($_POST['customer_name'] ?? 'Customer');
    $customer_mobile = trim($_POST['customer_mobile'] ?? '');
    $invoice_no      = trim($_POST['invoice_no'] ?? '');
    $balance_amount  = floatval($_POST['balance_amount'] ?? 0);

    if(empty($customer_email) && !empty($customer_mobile)) {
        $safe_mobile = mysqli_real_escape_string($conn, $customer_mobile);
        $cust_res = mysqli_query($conn, "SELECT email FROM customers WHERE mobile = '$safe_mobile' LIMIT 1");
        if($cust_res && mysqli_num_rows($cust_res) > 0) {
            $cust_row = mysqli_fetch_assoc($cust_res);
            $customer_email = trim($cust_row['email'] ?? '');
        }
    }

    if(empty($customer_email)) {
        $msg = 'Customer email is required for reminder. Enter an email address or save the email for this mobile number in the customer record.';
        _sr_log(['error','no_email','message'=>$msg]);
        echo json_encode(['success' => false, 'message' => $msg]);
        exit();
    }
    if($balance_amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'No unpaid amount to remind.']);
        exit();
    }

    $subject = 'Payment Reminder from MAA GOURI JEWELLERS';
    $invoice_text = $invoice_no ? 'Invoice No: ' . htmlspecialchars($invoice_no) . '<br>' : '';
    $message = '<p>Dear ' . htmlspecialchars($customer_name) . ',</p>' .
               '<p>This is a reminder that an amount of <strong>&#8377;' . number_format($balance_amount, 2) . '</strong> is still due.' .
               ($invoice_no ? ' Please refer to ' . htmlspecialchars($invoice_no) . '.' : '') . '</p>' .
               '<p>Please make the remaining payment at your earliest convenience.</p>' .
               '<p>Thank you,<br>MAA GOURI JEWELLERS</p>';
    $sendResult = sendSMTPMail($customer_email, $subject, $message);
    _sr_log(['after_sendSMTPMail','sendResult'=>$sendResult]);
    if(!empty($sendResult['success'])) {
        if(!empty($invoice_no)) {
            $safe_invoice_no = mysqli_real_escape_string($conn, $invoice_no);
            mysqli_query($conn, "UPDATE invoices SET reminder_sent = 1 WHERE invoice_no = '$safe_invoice_no'");
        }
        _sr_log(['success','invoice'=>$invoice_no,'email'=>$customer_email]);
        echo json_encode(['success' => true, 'message' => 'Reminder email sent successfully to ' . htmlspecialchars($customer_email) . '.']);
    } else {
        $error = trim($sendResult['message'] ?? 'Failed to send reminder email.');
        _sr_log(['failed','invoice'=>$invoice_no,'email'=>$customer_email,'error'=>$error]);
        echo json_encode(['success' => false, 'message' => 'Failed to send reminder email. ' . htmlspecialchars($error)]);
    }
    exit();
}

// Ensure reminder_sent exists on invoices for due-today filtering
$chk_reminder = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'reminder_sent'");
if($chk_reminder && mysqli_num_rows($chk_reminder) == 0) {
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN reminder_sent TINYINT(1) DEFAULT 0");
}

//  NEW: AJAX: Mark invoice as paid (partial or full custom amount) 
if(isset($_GET['action']) && $_GET['action'] === 'mark_paid') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid method']);
        exit();
    }
    // Debug log helper
    function _mp_log($data) {
        $f = '/tmp/mark_paid_debug.log';
        $line = '['.date('Y-m-d H:i:s').'] ' . json_encode($data) . "\n";
        @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
    }
    _mp_log(['start','_POST'=>$_POST,'_GET'=>$_GET,'REMOTE'=>$_SERVER['REMOTE_ADDR'] ?? '']);
    $invoice_no = mysqli_real_escape_string($conn, trim($_POST['invoice_no'] ?? ''));
    $amount = floatval($_POST['amount'] ?? 0);
    if(empty($invoice_no)) {
        echo json_encode(['success' => false, 'message' => 'Invoice number required']);
        exit();
    }
    $res = mysqli_query($conn, "SELECT total_amount, paid_amount FROM invoices WHERE invoice_no = '$invoice_no' LIMIT 1");
    if(!$res || mysqli_num_rows($res) === 0) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit();
    }
    $row = mysqli_fetch_assoc($res);
    $total = floatval($row['total_amount']);
    $alreadyPaid = floatval($row['paid_amount']);
    $currentBalance = $total - $alreadyPaid;
    // If amount not provided or <= 0, treat as full balance payment
    if($amount <= 0) {
        $amount = $currentBalance;
    }
    if($amount > $currentBalance + 0.01) {
        echo json_encode(['success' => false, 'message' => 'Amount exceeds balance due (₹' . number_format($currentBalance, 2) . ')']);
        exit();
    }
    $newPaid = $alreadyPaid + $amount;
    $newBalance = max($total - $newPaid, 0);
    $newStatus = ($newBalance <= 0.01) ? 'paid' : 'part';
    $dueDateSql = ($newStatus === 'paid') ? ", due_date=NULL" : "";
    $upd = mysqli_query($conn, "UPDATE invoices SET payment_status='$newStatus', paid_amount=$newPaid, balance_amount=$newBalance$dueDateSql WHERE invoice_no='$invoice_no'");
    if($upd) {
        // If caller requested anonymization (e.g., reports Mark Paid), clear customer name/mobile
        if(!empty($_POST['anonymize']) && $_POST['anonymize']) {
            mysqli_query($conn, "UPDATE invoices SET customer_name = '', customer_mobile = '' WHERE invoice_no = '$invoice_no'");
        }
        _mp_log(['updated','invoice'=>$invoice_no,'new_paid'=>$newPaid,'new_balance'=>$newBalance,'anonymize'=>$_POST['anonymize'] ?? null]);
        echo json_encode([
            'success' => true,
            'message' => $newStatus === 'paid' ? ('Invoice ' . $invoice_no . ' fully paid!') : ('Payment of ₹' . number_format($amount, 2) . ' recorded for ' . $invoice_no),
            'fully_paid' => $newStatus === 'paid',
            'new_paid' => $newPaid,
            'new_balance' => $newBalance
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit();
}

// Add PDF column to invoices table if not exists
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'pdf_file'");
if($check_column && mysqli_num_rows($check_column) == 0) {
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN pdf_file LONGBLOB");
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN pdf_file_name VARCHAR(255)");
}

// Add split payment columns if not exists
$chk_cash = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'cash_paid'");
if($chk_cash && mysqli_num_rows($chk_cash) == 0) {
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN cash_paid DECIMAL(10,2) DEFAULT 0");
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN upi_paid DECIMAL(10,2) DEFAULT 0");
}

// Add account_paid column (for NEFT) if not exists
$chk_due_date = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'due_date'");
if($chk_due_date && mysqli_num_rows($chk_due_date) == 0) {
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN due_date DATE NULL");
}

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle PDF Upload
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_pdf'])) {
    $invoice_no = mysqli_real_escape_string($conn, $_POST['invoice_no']);
    if(isset($_FILES['invoice_pdf']) && $_FILES['invoice_pdf']['error'] == 0) {
        $file_ext = mime_content_type($_FILES['invoice_pdf']['tmp_name']);
        if($file_ext === 'application/pdf') {
            $pdf_content = addslashes(file_get_contents($_FILES['invoice_pdf']['tmp_name']));
            $pdf_file_name = mysqli_real_escape_string($conn, $_FILES['invoice_pdf']['name']);
            $update_query = "UPDATE invoices SET pdf_file = '$pdf_content', pdf_file_name = '$pdf_file_name' WHERE invoice_no = '$invoice_no'";
            if(mysqli_query($conn, $update_query)) {
                $pdf_success = "&#10003; PDF uploaded successfully for Invoice: $invoice_no";
            } else {
                $pdf_error = "&#10007; Error uploading PDF: " . mysqli_error($conn);
            }
        } else {
            $pdf_error = "&#10007; Only PDF files are allowed!";
        }
    } else {
        $pdf_error = "&#10007; Please select a PDF file!";
    }
}

// Initialize last invoice variables
$success = '';
$error = '';
$last_invoice_no = '';
$last_customer_name = '';
$last_customer_mobile = '';
$last_customer_address = '';
$last_gst_type = '';
$last_making_charge = 0;
$last_making_charge_amount = 0;
$last_hallmark = 0;
$last_pola = 0;
$last_discount = 0;
$last_items = [];
$last_subtotal = 0;
$last_gst_amount = 0;
$last_cgst_amount = 0;
$last_sgst_amount = 0;
$last_cgst_rate = 0;
$last_sgst_rate = 0;
$last_round_off = 0;
$last_total = 0;
$last_total_quantity = 0;
$last_payment_status = 'paid';
$last_paid_amount = 0;
$last_balance_amount = 0;
$last_payment_method = 'Cash';
$last_cash_paid = 0;
$last_upi_paid = 0;
$last_is_split = 0;
$last_old_gold_amount = 0;

$logo_paths = ['assets/images/moti-removebg-preview.png', 'images/moti-removebg-preview.png', 'moti-removebg-preview.png', 'radhey shyam logo.png'];

// Check and auto-add missing columns to products table if missing
$chk4 = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'diamond_cent'");
if($chk4 && mysqli_num_rows($chk4) == 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN diamond_cent VARCHAR(50) DEFAULT ''");
}
$chk5 = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'metal_kt'");
if($chk5 && mysqli_num_rows($chk5) == 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN metal_kt VARCHAR(50) DEFAULT ''");
}
$chk6 = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'metal_weight'");
if($chk6 && mysqli_num_rows($chk6) == 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN metal_weight VARCHAR(50) DEFAULT ''");
}

// Fetch products from DB
$all_products = [];
$products_result = mysqli_query($conn, "SELECT id, name, item_name, serial_no, category, weight, price, quantity, unit, huid_code, diamond_cent, metal_kt, metal_weight FROM products ORDER BY category, item_name, name");
if(!$products_result) {
    $products_result = mysqli_query($conn, "SELECT id, name, item_name, serial_no, category, weight, price, quantity, unit, huid_code FROM products ORDER BY category, item_name, name");
}
if($products_result) {
    while($p = mysqli_fetch_assoc($products_result)) {
        if(empty($p['unit'])) {
            $p['unit'] = (stripos($p['name'], 'pair') !== false || stripos($p['item_name'], 'pair') !== false) ? 'pair' : 'pcs';
        }
        $all_products[] = $p;
    }
}

// Build item type options from ALL categories in the products table
$categories_res = mysqli_query($conn, "SELECT DISTINCT category FROM products WHERE category != '' ORDER BY category");
$all_db_categories = [];
if ($categories_res) {
    while ($cr = mysqli_fetch_assoc($categories_res)) {
        if (!empty($cr['category'])) $all_db_categories[] = $cr['category'];
    }
}
$categories = array_unique(array_merge(['Gold 24K', 'Gold 22K', 'Gold 18K', 'Gold 12K', 'Gold 9K', 'Silver', 'Stone', 'Diamond', 'Others'], $all_db_categories));
foreach ($categories as $cat) {
    if (!isset($itemTypeOptions[$cat])) $itemTypeOptions[$cat] = [];
    $safeCat = mysqli_real_escape_string($conn, $cat);
    $res = mysqli_query($conn, "SELECT DISTINCT item_name FROM products WHERE category = '$safeCat' AND item_name != '' AND item_name IS NOT NULL ORDER BY item_name");
    if($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            if (!empty($row['item_name']) && !in_array($row['item_name'], $itemTypeOptions[$cat])) {
                $itemTypeOptions[$cat][] = $row['item_name'];
            }
        }
    }
}

// Gold 24K, 22K, 18K, 12K, 9K items - as specified by shop owner
$goldItems = ['Coin','Bar','Guinea','Necklace','Chur','Bala','Chain','Tops','Single Loket','Double Loket','Churi','Jhuladul','Jhumka','Ladies Ring','Gold Choker','Gents Ring','Gents Breslet','Ladies Breslet','Tika','Takti','Mantasa','Pearl Choker','Bauti Chur','Soket Bauti','Breslet Noya','Stell Noya','Baby Ring','Bali','Pitaring','Baby Breslet','Pearl Sitahar','Nose Pin','Other'];
$itemTypeOptions['Gold 24K'] = array_unique(array_merge($itemTypeOptions['Gold 24K'], $goldItems));
$itemTypeOptions['Gold 22K'] = array_unique(array_merge($itemTypeOptions['Gold 22K'], $goldItems));
$itemTypeOptions['Gold 18K'] = array_unique(array_merge($itemTypeOptions['Gold 18K'], $goldItems));
$itemTypeOptions['Gold 12K'] = array_unique(array_merge($itemTypeOptions['Gold 12K'], $goldItems));
$itemTypeOptions['Gold 9K']  = array_unique(array_merge($itemTypeOptions['Gold 9K'],  $goldItems));

$itemTypeOptions['Silver']   = array_unique(array_merge($itemTypeOptions['Silver'],
 ['Thali','Bati','Glass','Spoon','Showpiece','B.B.C Silver','Mix Silver','Other']));
$itemTypeOptions['Stone']    = array_unique(array_merge($itemTypeOptions['Stone'],
 ['Natural Pearl','Gomed','Red Coral','Nila','Panna','Jerkon','Amethist','Cats Eye','Other']));
$itemTypeOptions['Diamond']  = array_unique(array_merge($itemTypeOptions['Diamond'],
  ['Ladies Ring','Gents Ring','Tops','Mangal Sutra','Nose Pin','Necklace','Other']));
$itemTypeOptions['Others']   = array_unique(array_merge($itemTypeOptions['Others'],
  ['Shankha','Pala','Mala','Moti Mala','Trasel','Branch Fram','Braslate Pala',
  'Parl Mala','Gala','Reparing','Stamp Charg','Other']));
//  NEW: Fetch due-today payments 
$today = date('Y-m-d');
$due_today_result = mysqli_query($conn, "
    SELECT invoice_no, customer_name, customer_mobile, customer_address,
           balance_amount, paid_amount, total_amount, due_date
    FROM invoices
    WHERE due_date = '$today'
      AND balance_amount > 0
      AND payment_status IN ('part', 'unpaid')
      AND (reminder_sent = 0 OR reminder_sent IS NULL)
    ORDER BY customer_name ASC
");
$due_today_bills = [];
if($due_today_result) {
    while($drow = mysqli_fetch_assoc($due_today_result)) {
        $due_today_bills[] = $drow;
    }
}

// Load available HUID codes from purchase history and exclude already used serials
$available_huids = [];
$huid_result = mysqli_query($conn, "SELECT DISTINCT TRIM(huid_code) AS huid_code FROM purchase_entries WHERE TRIM(huid_code) <> '' AND TRIM(huid_code) IS NOT NULL");
if($huid_result) {
    while($row = mysqli_fetch_assoc($huid_result)) {
        $code = trim($row['huid_code']);
        if($code !== '') $available_huids[$code] = $code;
    }
}
$invoice_items_table = mysqli_query($conn, "SHOW TABLES LIKE 'invoice_items'");
if($invoice_items_table && mysqli_num_rows($invoice_items_table) > 0) {
    $used_result = mysqli_query($conn, "SELECT DISTINCT TRIM(serial_no) AS used_huid FROM invoice_items WHERE TRIM(serial_no) <> '' AND serial_no IS NOT NULL");
    if($used_result) {
        while($row = mysqli_fetch_assoc($used_result)) {
            $used = trim($row['used_huid']);
            if($used !== '') unset($available_huids[$used]);
        }
    }
}
$available_huids = array_values($available_huids);

// Preload active advance credit customers
$active_advance_customers = [];
$adv_cust_q = mysqli_query($conn, "
    SELECT id, receipt_no, customer_name, customer_mobile, customer_address, advance_amount, used_amount, available_amount, created_at 
    FROM customer_advances 
    WHERE available_amount > 0 AND status != 'cancelled' 
    ORDER BY created_at DESC
");
if($adv_cust_q) {
    while($ar = mysqli_fetch_assoc($adv_cust_q)) {
        $active_advance_customers[] = $ar;
    }
}

// Handle billing submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['create_invoice']) || isset($_POST['items']) || isset($_POST['subtotal']))) {
    $customer_name    = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
    $customer_mobile  = mysqli_real_escape_string($conn, $_POST['customer_mobile'] ?? '');

    // Prevent rapid duplicate submissions (e.g. double-click)
    $sub_hash = md5($customer_mobile . '|' . ($_POST['items'] ?? '') . '|' . ($_POST['paid_amount'] ?? '') . '|' . ($_POST['advance_adjusted'] ?? ''));
    if (isset($_SESSION['last_sub_hash']) && $_SESSION['last_sub_hash'] === $sub_hash && (time() - ($_SESSION['last_sub_time'] ?? 0)) < 4) {
        if (!empty($_SESSION['last_redirect_url'])) {
            header("Location: " . $_SESSION['last_redirect_url']);
            exit();
        }
    }
    $_SESSION['last_sub_hash'] = $sub_hash;
    $_SESSION['last_sub_time'] = time();

    $customer_address = mysqli_real_escape_string($conn, $_POST['customer_address'] ?? '');
    $customer_email   = mysqli_real_escape_string($conn, $_POST['customer_email'] ?? '');
    $gst_amount       = floatval($_POST['gst_amount'] ?? 0);
    $raw_gst_type     = strtolower(trim($_POST['gst_type'] ?? 'non_gst'));
    if ($gst_amount > 0 && ($raw_gst_type === 'non_gst' || empty($raw_gst_type))) {
        $raw_gst_type = 'gst_3';
    }
    $gst_type         = mysqli_real_escape_string($conn, $raw_gst_type);
    $subtotal         = floatval($_POST['subtotal']);
    $making_charge    = floatval($_POST['making_charge'] ?? 0);
    $hallmark         = floatval($_POST['hallmark'] ?? 0);
    $pola             = floatval($_POST['pola'] ?? 0);
    $discount         = floatval($_POST['discount'] ?? 0);
    $payment_status   = mysqli_real_escape_string($conn, $_POST['payment_status'] ?? 'paid');
    $payment_method   = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'Cash');
    $paid_amount      = floatval($_POST['paid_amount'] ?? 0);
    $account_paid     = floatval($_POST['account_paid'] ?? 0);
    $cash_paid        = floatval($_POST['cash_paid'] ?? 0);
    $upi_paid         = floatval($_POST['upi_paid'] ?? 0);
    $is_split         = intval($_POST['is_split_payment'] ?? 0);
    $due_date = mysqli_real_escape_string($conn, $_POST['due_date'] ?? '');
    if(empty($due_date)) $due_date = 'NULL';
    else $due_date = "'" . $due_date . "'";

    $session_uid = (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) ? intval($_SESSION['user_id']) : 1;

    // --- CHECK FOR ADVANCE CREDIT BOOKING DEPOSIT (NO ITEMS PURCHASED) ---
    $is_adv_booking = intval($_POST['is_advance_credit_booking'] ?? 0);
    $adv_deposit_amount = floatval($_POST['advance_deposit_amount'] ?? 0);
    $raw_items_check = json_decode($_POST['items'] ?? '[]', true);
    $items_empty = (!is_array($raw_items_check) || count($raw_items_check) === 0);

    if ($is_adv_booking === 1 || ($items_empty && $adv_deposit_amount > 0) || ($items_empty && ($payment_status === 'unpaid' || $payment_status === 'advance') && $paid_amount > 0)) {
        $final_adv_amt = ($adv_deposit_amount > 0) ? $adv_deposit_amount : $paid_amount;
        if ($final_adv_amt > 0) {
            $today = date('Ymd');
            $prefix = 'ADV-' . $today . '-';
            $q_adv = mysqli_query($conn, "SELECT receipt_no FROM customer_advances WHERE receipt_no LIKE 'ADV-%'");
            $existing_adv_nums = [];
            if ($q_adv) {
                while ($adv_row = mysqli_fetch_assoc($q_adv)) {
                    $parts = explode('-', $adv_row['receipt_no']);
                    $num = intval(end($parts));
                    if ($num > 0) $existing_adv_nums[] = $num;
                }
            }
            $next_adv_num = empty($existing_adv_nums) ? 1 : max($existing_adv_nums) + 1;
            while (in_array($next_adv_num, $existing_adv_nums)) $next_adv_num++;
            $adv_receipt_no = $prefix . str_pad($next_adv_num, 4, '0', STR_PAD_LEFT);

            $adv_notes = mysqli_real_escape_string($conn, trim($_POST['booking_notes'] ?? ''));
            $delivery_date_val = ($due_date !== 'NULL') ? $due_date : "NULL";

            if ($is_split) {
                $payment_method = 'Cash+UPI';
            }

            $ins_adv = mysqli_query($conn, "
                INSERT INTO customer_advances (receipt_no, customer_name, customer_mobile, customer_address, advance_amount, used_amount, available_amount, payment_method, cash_paid, upi_paid, booking_note, delivery_date, status, created_by)
                VALUES ('$adv_receipt_no', '$customer_name', '$customer_mobile', '$customer_address', $final_adv_amt, 0.00, $final_adv_amt, '$payment_method', $cash_paid, $upi_paid, '$adv_notes', $delivery_date_val, 'active', $session_uid)
            ");

            if ($ins_adv) {
                mysqli_query($conn, "INSERT INTO customers (name, mobile, address, email) VALUES ('$customer_name', '$customer_mobile', '$customer_address', '$customer_email') ON DUPLICATE KEY UPDATE name = '$customer_name', address = '$customer_address', email = '$customer_email'");
                $success = "&#10003; Advance Credit Money Receipt <strong>$adv_receipt_no</strong> generated for <strong>₹" . number_format($final_adv_amt, 2) . "</strong>! <a href='view_pdf.php?advance_no=" . urlencode($adv_receipt_no) . "' target='_blank' class='underline font-bold text-amber-900 ml-2'>&#128438; Print Advance Receipt</a>";
                goto skip_invoice;
            } else {
                $error = "&#10007; Error generating advance credit receipt: " . mysqli_error($conn);
                goto skip_invoice;
            }
        }
    }

    // Advance Adjustment on Product Bill
    $advance_adjusted = floatval($_POST['advance_adjusted'] ?? 0);
    $applied_advance_id = intval($_POST['applied_advance_id'] ?? 0);

    if ($is_split) {
        $payment_method = 'Cash+UPI';
        $paid_amount    = $cash_paid + $upi_paid;
        if ($paid_amount < $total_amount) {
            if ($paid_amount > 0) {
                $payment_status = 'part';
            } else {
                $payment_status = 'unpaid';
            }
        }
    }

    if(strtoupper($payment_method) === 'NEFT') {
        if($paid_amount > 0) $account_paid = $paid_amount;
    }

    $making_charge_amount = $making_charge;
    $gst_amount = floatval($_POST['gst_amount'] ?? 0);
    $old_gold_amount = floatval($_POST['old_gold_amount'] ?? 0);
    $discount = floatval($_POST['discount'] ?? 0);
    
    // Overall discount on total product price, advance credit and old gold deduction
    $subtotal_before_tax = $subtotal + $pola;
    $total_before_round  = $subtotal_before_tax + $gst_amount - $discount - $old_gold_amount - $advance_adjusted;
    $total_amount  = max(0, round($total_before_round, 2));
    $round_off     = 0;


    $chkEmailColumn = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'email'");
    if($chkEmailColumn && mysqli_num_rows($chkEmailColumn) == 0) {
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN email VARCHAR(255) NULL");
    }
    $customer_query = "INSERT INTO customers (name, mobile, address, email) VALUES ('$customer_name', '$customer_mobile', '$customer_address', '$customer_email')
                       ON DUPLICATE KEY UPDATE name = '$customer_name', address = '$customer_address', email = '$customer_email'";
    mysqli_query($conn, $customer_query);

    $manual_inv = trim($_POST['manual_invoice_no'] ?? '');
    if(!empty($manual_inv)) {
        $invoice_no = mysqli_real_escape_string($conn, $manual_inv);

        $dup = mysqli_query($conn, "SELECT id FROM invoices WHERE invoice_no = '$invoice_no'");
        if($dup && mysqli_num_rows($dup) > 0) {
            $error = "&#10007; Invoice No. '$invoice_no' already exists! Please use a different number.";
            goto skip_invoice;
        }
    } else {
        $today = date('Ymd');
        $raw_gst_type = strtolower(trim($_POST['gst_type'] ?? 'non_gst'));
        $is_gst_invoice = ($gst_amount > 0 || ($raw_gst_type !== 'non_gst' && !empty($raw_gst_type)));

        if ($is_gst_invoice) {
            // --- GST TAX INVOICE SEQUENCE (Continues past sequence: 0450, 0451, 0452...) ---
            $prefix = 'INV-' . $today . '-';
            $q = mysqli_query($conn, "SELECT invoice_no FROM invoices WHERE (invoice_no LIKE 'INV-%' OR invoice_no LIKE 'GST-%')");
            $existing_nums = [];
            if ($q) {
                while ($row = mysqli_fetch_assoc($q)) {
                    $parts = explode('-', $row['invoice_no']);
                    $num = intval(end($parts));
                    if ($num > 0) {
                        $existing_nums[] = $num;
                    }
                }
            }
            $next_num = 450;
            if (!empty($existing_nums)) {
                $next_num = max($existing_nums) + 1;
            }
            while (in_array($next_num, $existing_nums)) {
                $next_num++;
            }
            $invoice_no = $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
        } else {
            // --- CASH MEMO (NON-GST) SEQUENCE (CM-YYYYMMDD-0001, 0002...) ---
            $prefix = 'CM-' . $today . '-';
            $q = mysqli_query($conn, "SELECT invoice_no FROM invoices WHERE invoice_no LIKE 'CM-%'");
            $existing_nums = [];
            if ($q) {
                while ($row = mysqli_fetch_assoc($q)) {
                    $parts = explode('-', $row['invoice_no']);
                    $num = intval(end($parts));
                    if ($num > 0) {
                        $existing_nums[] = $num;
                    }
                }
            }
            $next_num = 1;
            if (!empty($existing_nums)) {
                $next_num = max($existing_nums) + 1;
            }
            while (in_array($next_num, $existing_nums)) {
                $next_num++;
            }
            $invoice_no = $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
        }
    }
    $customer_gstin = mysqli_real_escape_string($conn, $_POST['customer_gstin'] ?? '');
    $huid_code = mysqli_real_escape_string($conn, trim($_POST['huid_code'] ?? ''));
    $created_by_val = "NULL";
    $session_uid = (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) ? intval($_SESSION['user_id']) : 0;
    if ($session_uid > 0) {
        $u_chk = mysqli_query($conn, "SELECT id FROM users WHERE id = $session_uid LIMIT 1");
        if ($u_chk && mysqli_num_rows($u_chk) > 0) {
            $created_by_val = $session_uid;
        } else {
            $first_u = mysqli_query($conn, "SELECT id FROM users ORDER BY id ASC LIMIT 1");
            if ($first_u && $fu_row = mysqli_fetch_assoc($first_u)) {
                $created_by_val = intval($fu_row['id']);
            }
        }
    } else {
        $first_u = mysqli_query($conn, "SELECT id FROM users ORDER BY id ASC LIMIT 1");
        if ($first_u && $fu_row = mysqli_fetch_assoc($first_u)) {
            $created_by_val = intval($fu_row['id']);
        }
    }

    $chk1 = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'paid_amount'");
    if($chk1 && mysqli_num_rows($chk1) == 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN paid_amount DECIMAL(10,2) DEFAULT 0");
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN balance_amount DECIMAL(10,2) DEFAULT 0");
    }
    $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'payment_method'");
    if($chk2 && mysqli_num_rows($chk2) == 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN payment_method VARCHAR(20) DEFAULT 'Cash'");
    }

    $balance_amount = ($payment_status === 'paid') ? 0 : ($total_amount - $paid_amount);
    if($payment_status === 'paid') $paid_amount = $total_amount;

    if($is_split && $paid_amount >= $total_amount) {
        $payment_status = 'paid';
        $balance_amount = 0;
    }

    $old_gold_amount = floatval($_POST['old_gold_amount'] ?? 0);
    $old_gold_type   = mysqli_real_escape_string($conn, trim($_POST['old_gold_type'] ?? 'Old Gold'));
    if (empty($old_gold_type)) $old_gold_type = 'Old Gold';

    $col_og = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'old_gold_amount'")) > 0;
    if(!$col_og) mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN old_gold_amount DECIMAL(10,2) DEFAULT 0");

    $col_ogt = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'old_gold_type'")) > 0;
    if(!$col_ogt) mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN old_gold_type VARCHAR(50) DEFAULT 'Old Gold'");

    // Auto-drop foreign key constraints on invoice_items to allow zero-stock product auto-deletion
    $fk_check = mysqli_query($conn, "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_items' AND REFERENCED_TABLE_NAME IS NOT NULL");
    if ($fk_check) {
        while ($fk_row = mysqli_fetch_assoc($fk_check)) {
            $c_name = $fk_row['CONSTRAINT_NAME'];
            @mysqli_query($conn, "ALTER TABLE invoice_items DROP FOREIGN KEY `$c_name`");
        }
    }

    //  STOCK VALIDATION: Pre-check available stock pieces before creating invoice 
    $raw_items = json_decode($_POST['items'] ?? '[]', true);
    if (is_array($raw_items)) {
        $req_pcs_map = [];
        $req_weight_map = [];
        foreach ($raw_items as $it) {
            $pid = $it['product_id'] ?? '';
            if ($pid !== 'other' && is_numeric($pid)) {
                $pid_int = intval($pid);
                $u_check = strtolower(trim($it['unit'] ?? ''));
                $is_pair_check = ($u_check === 'pair' || $u_check === 'pairs');
                $raw_pcs_req = floatval($it['pcs'] ?? $it['stock_deduct'] ?? 1);
                $q_val_req = floatval($it['quantity'] ?? 1);
                if ($is_pair_check && $raw_pcs_req <= $q_val_req) {
                    $pcs_req = $q_val_req * 2;
                } else {
                    $pcs_req = $raw_pcs_req;
                }
                if ($pcs_req <= 0) $pcs_req = 1;
                $req_pcs_map[$pid_int] = ($req_pcs_map[$pid_int] ?? 0) + $pcs_req;
                
                if (isset($it['unit']) && $it['unit'] === 'g') {
                    $w_req = floatval($it['quantity'] ?? 0);
                    $req_weight_map[$pid_int] = ($req_weight_map[$pid_int] ?? 0) + $w_req;
                }
            }
        }
        foreach ($req_pcs_map as $pid_int => $total_pcs_req) {
            $st_res = mysqli_query($conn, "SELECT name, item_name, quantity, weight FROM products WHERE id = $pid_int");
            if ($st_res && $st_row = mysqli_fetch_assoc($st_res)) {
                $avail_qty = floatval($st_row['quantity']);
                $avail_weight = floatval($st_row['weight'] ?? 0);
                $p_title = !empty($st_row['item_name']) ? $st_row['item_name'] : $st_row['name'];
                if ($avail_qty <= 0) {
                    $error = "&#10007; Cannot create invoice! Product '$p_title' is OUT OF STOCK (0 pcs available).";
                    goto skip_invoice;
                }
                if ($total_pcs_req > $avail_qty) {
                    $error = "&#10007; Cannot create invoice! Product '$p_title' has only $avail_qty pcs in stock, but $total_pcs_req pcs requested.";
                    goto skip_invoice;
                }
                
                $total_w_req = $req_weight_map[$pid_int] ?? 0;
                if ($avail_weight > 0 && $total_w_req > $avail_weight) {
                    $error = "&#10007; Cannot create invoice! Product '$p_title' has only {$avail_weight}g in stock, but {$total_w_req}g requested.";
                    goto skip_invoice;
                }
            } else {
                $error = "&#10007; Cannot create invoice! Selected stock product (ID: $pid_int) was not found in stock.";
                goto skip_invoice;
            }
        }
    }

    // Ensure all required columns exist on invoices table
    $chk_inv_cols = [
        'customer_id'        => 'INT NULL',
        'customer_address'   => 'TEXT NULL',
        'customer_gstin'     => 'VARCHAR(50) NULL',
        'discount'           => 'DECIMAL(10,2) DEFAULT 0',
        'advance_adjusted'   => 'DECIMAL(10,2) DEFAULT 0',
        'advance_id'         => 'INT NULL',
        'applied_advance_id' => 'INT NULL',
        'old_gold_amount'    => 'DECIMAL(10,2) DEFAULT 0',
        'old_gold_type'      => "VARCHAR(50) DEFAULT 'Old Gold'",
        'old_gold_value'     => 'DECIMAL(10,2) DEFAULT 0',
        'refund_amount'      => 'DECIMAL(10,2) DEFAULT 0',
        'refund_mode'        => 'VARCHAR(50) NULL',
        'payment_status'     => "VARCHAR(20) DEFAULT 'pending'",
        'payment_method'     => "VARCHAR(20) DEFAULT 'Cash'",
        'paid_amount'        => 'DECIMAL(10,2) DEFAULT 0',
        'balance_amount'     => 'DECIMAL(10,2) DEFAULT 0',
        'huid_code'          => 'VARCHAR(100) NULL',
        'cash_paid'          => 'DECIMAL(10,2) DEFAULT 0',
        'upi_paid'           => 'DECIMAL(10,2) DEFAULT 0',
        'account_paid'       => 'DECIMAL(10,2) DEFAULT 0',
        'due_date'           => 'DATE NULL',
        'reminder_sent'      => 'TINYINT(1) DEFAULT 0',
    ];
    foreach ($chk_inv_cols as $c_name => $c_def) {
        $c_chk = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE '$c_name'");
        if ($c_chk && mysqli_num_rows($c_chk) == 0) {
            @mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN $c_name $c_def");
        }
    }

    $existing_inv_cols = [];
    $all_cols_res = mysqli_query($conn, "SHOW COLUMNS FROM invoices");
    if ($all_cols_res) {
        while ($cr = mysqli_fetch_assoc($all_cols_res)) {
            $existing_inv_cols[strtolower($cr['Field'])] = true;
        }
    }

    $adv_id_val = ($applied_advance_id > 0) ? $applied_advance_id : null;

    $inv_data = [
        'invoice_no'         => "'$invoice_no'",
        'customer_name'      => "'$customer_name'",
        'customer_mobile'    => "'$customer_mobile'",
        'customer_address'   => "'$customer_address'",
        'customer_gstin'     => "'$customer_gstin'",
        'gst_type'           => "'$gst_type'",
        'subtotal'           => $subtotal,
        'gst_amount'         => $gst_amount,
        'total_amount'       => $total_amount,
        'payment_status'     => "'$payment_status'",
        'payment_method'     => "'$payment_method'",
        'paid_amount'        => $paid_amount,
        'balance_amount'     => $balance_amount,
        'cash_paid'          => $cash_paid,
        'upi_paid'           => $upi_paid,
        'account_paid'       => $account_paid,
        'due_date'           => $due_date,
        'created_by'         => $created_by_val,
        'huid_code'          => "'$huid_code'",
        'old_gold_amount'    => $old_gold_amount,
        'old_gold_type'      => "'$old_gold_type'",
        'old_gold_value'     => $old_gold_amount,
        'advance_adjusted'   => $advance_adjusted,
        'advance_id'         => ($adv_id_val !== null ? $adv_id_val : 'NULL'),
        'applied_advance_id' => ($adv_id_val !== null ? $adv_id_val : 'NULL'),
        'discount'           => $discount,
    ];

    $insert_cols = [];
    $insert_vals = [];
    foreach ($inv_data as $col => $val) {
        if (isset($existing_inv_cols[strtolower($col)])) {
            $insert_cols[] = "`$col`";
            $insert_vals[] = $val;
        }
    }

    $invoice_query = "INSERT INTO invoices (" . implode(", ", $insert_cols) . ") VALUES (" . implode(", ", $insert_vals) . ")";
    $inv_exec = mysqli_query($conn, $invoice_query);
    if(!$inv_exec) {
        die("<div style='padding:30px;font-family:sans-serif;background:#fff1f2;color:#991b1b;border:2px solid #f87171;border-radius:12px;margin:40px auto;max-width:650px;'>
            <h3 style='margin-top:0;'> Invoice Creation Failed</h3>
            <p><strong>MySQL Error:</strong> " . htmlspecialchars(mysqli_error($conn)) . "</p>
            <p><a href='billing.php' style='color:#991b1b;font-weight:bold;text-decoration:underline;'>&larr; Back to Billing</a></p>
        </div>");
    }
    if($inv_exec) {
        // Record advance deduction & surplus handling from customer_advances
        if ($advance_adjusted > 0 && $applied_advance_id > 0) {
            $surplus_action = mysqli_real_escape_string($conn, $_POST['adv_surplus_action'] ?? 'keep_advance');
            $surplus_amt = floatval($_POST['adv_surplus_amount'] ?? 0);
            $refund_mode = mysqli_real_escape_string($conn, $_POST['adv_refund_mode'] ?? 'Cash');

            if ($surplus_action === 'refund_customer' && $surplus_amt > 0.005) {
                // Settle the entire advance, mark as fully adjusted, and log the excess refund
                mysqli_query($conn, "
                    UPDATE customer_advances 
                    SET used_amount = advance_amount,
                        available_amount = 0,
                        status = 'fully_adjusted'
                    WHERE id = $applied_advance_id
                ");
                mysqli_query($conn, "
                    INSERT INTO advance_adjustments (advance_id, invoice_no, amount_adjusted)
                    VALUES ($applied_advance_id, '$invoice_no', $advance_adjusted)
                ");
                log_product_activity($conn, [
                    'activity_type' => 'billed',
                    'product_id' => null,
                    'product_name' => 'Advance Credit Refund',
                    'category' => 'Advance',
                    'serial_no' => '',
                    'huid_code' => '',
                    'quantity' => 1,
                    'unit' => 'refund',
                    'weight' => 0,
                    'rate' => $surplus_amt,
                    'total_amount' => $surplus_amt,
                    'invoice_no' => $invoice_no,
                    'customer_name' => $customer_name,
                    'customer_mobile' => $customer_mobile,
                    'details' => "Refunded excess advance ₹" . number_format($surplus_amt, 2) . " via $refund_mode to $customer_name against Invoice/Memo #$invoice_no",
                    'performed_by' => $_SESSION['user_name'] ?? 'Admin'
                ]);
            } else {
                // Keep remaining advance in customer credit: deduct only the billed amount
                mysqli_query($conn, "
                    UPDATE customer_advances 
                    SET used_amount = used_amount + $advance_adjusted,
                        available_amount = GREATEST(0, available_amount - $advance_adjusted),
                        status = CASE WHEN (available_amount - $advance_adjusted) <= 0.005 THEN 'fully_adjusted' ELSE 'partially_used' END
                    WHERE id = $applied_advance_id
                ");
                mysqli_query($conn, "
                    INSERT INTO advance_adjustments (advance_id, invoice_no, amount_adjusted)
                    VALUES ($applied_advance_id, '$invoice_no', $advance_adjusted)
                ");
            }
        }

        $last_old_gold_amount = $old_gold_amount;
        $invoice_id = mysqli_insert_id($conn);

        $items = json_decode($_POST['items'], true);
        $col_prod_name = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'product_name'")) > 0;
        if(!$col_prod_name) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN product_name VARCHAR(200) NULL");
        $col_serial = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'serial_no'")) > 0;
        if(!$col_serial) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN serial_no VARCHAR(100) NULL");
        $col_huid = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'huid_code'")) > 0;
        if(!$col_huid) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN huid_code VARCHAR(100) NULL");
        $col_hsn = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'hsn_code'")) > 0;
        if(!$col_hsn) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN hsn_code VARCHAR(50) NULL");
        $col_qty_decimal = false;
        $colRes = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items WHERE Field='quantity'");
        if($colRes) {
            $colRow = mysqli_fetch_assoc($colRes);
            if($colRow && stripos($colRow['Type'] ?? '', 'decimal') !== false) $col_qty_decimal = true;
        }
        if(!$col_qty_decimal) mysqli_query($conn, "ALTER TABLE invoice_items MODIFY COLUMN quantity DECIMAL(10,3) NULL");

        // Per-item making charge / hallmark / discount / pcs / unit / weight columns
        $col_item_mc = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'making_charge'")) > 0;
        if(!$col_item_mc) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN making_charge DECIMAL(10,2) DEFAULT 0");
        $col_item_mc_pct = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'making_charge_pct'")) > 0;
        if(!$col_item_mc_pct) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN making_charge_pct DECIMAL(5,2) DEFAULT 0");
        $col_item_hm = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'hallmark'")) > 0;
        if(!$col_item_hm) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN hallmark DECIMAL(10,2) DEFAULT 0");
        $col_item_hm_type = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'hallmark_type'")) > 0;
        if(!$col_item_hm_type) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN hallmark_type VARCHAR(50) DEFAULT '22K'");
        $col_item_disc = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'discount'")) > 0;
        if(!$col_item_disc) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN discount DECIMAL(10,2) DEFAULT 0");
        $col_item_pcs = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'pcs'")) > 0;
        if(!$col_item_pcs) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN pcs INT DEFAULT 1");
        $col_item_unit = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'unit'")) > 0;
        if(!$col_item_unit) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN unit VARCHAR(20) DEFAULT 'g'");
        $col_item_gross = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'gross_wt'")) > 0;
        if(!$col_item_gross) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN gross_wt DECIMAL(10,3) DEFAULT 0");
        $col_item_net = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'net_wt'")) > 0;
        // Ensure diamond and metal columns exist on invoice_items
        $col_d_cent = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'diamond_cent'")) > 0;
        if(!$col_d_cent) @mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN diamond_cent VARCHAR(50) DEFAULT ''");
        $col_m_kt = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'metal_kt'")) > 0;
        if(!$col_m_kt) @mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN metal_kt VARCHAR(50) DEFAULT ''");
        $col_m_wt = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'metal_weight'")) > 0;
        if(!$col_m_wt) @mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN metal_weight VARCHAR(50) DEFAULT ''");

        if(is_array($items)) {
            foreach($items as $item) {
                $product_id = $item['product_id'] ?? '';
                $quantity   = floatval($item['quantity'] ?? 0);
                $price      = floatval($item['price'] ?? 0);
                $total      = floatval($item['total'] ?? 0);
                $item_making_charge = floatval($item['making_charge'] ?? 0);
                $item_making_charge_pct = floatval($item['making_charge_pct'] ?? 0);
                $item_hallmark      = floatval($item['hallmark'] ?? 0);
                $item_discount      = floatval($item['discount'] ?? 0);
                $manual_name = mysqli_real_escape_string($conn, trim($item['name'] ?? $item['product'] ?? ''));
                $manual_serial = mysqli_real_escape_string($conn, trim($item['serial'] ?? $item['serial_no'] ?? ''));
                $manual_huid = mysqli_real_escape_string($conn, trim($item['huid_code'] ?? $item['huid'] ?? ''));
                $manual_hsn = mysqli_real_escape_string($conn, trim($item['hsn'] ?? $item['hsn_code'] ?? ''));
                if ($manual_hsn === '0') $manual_hsn = '';

                $item_unit  = mysqli_real_escape_string($conn, $item['unit'] ?? 'g');
                $u_lower    = strtolower(trim($item_unit));
                $is_pair    = ($u_lower === 'pair' || $u_lower === 'pairs');
                $raw_pcs    = intval($item['pcs'] ?? $item['stock_deduct'] ?? ($is_pair ? 2 : 1));
                if ($raw_pcs <= 0) $raw_pcs = $is_pair ? 2 : 1;
                $pcs_deduct = $raw_pcs;
                $item_pcs   = $raw_pcs;

                $item_gross = floatval($item['gross_wt'] ?? ($item_unit === 'g' ? $quantity : 0));
                $item_net   = floatval($item['net_wt'] ?? ($item_unit === 'g' ? $quantity : 0));
                $item_gst_type = mysqli_real_escape_string($conn, trim($item['gst_type'] ?? 'non_gst'));
                if (empty($item_gst_type)) $item_gst_type = 'non_gst';

                $item_diamond_cent = mysqli_real_escape_string($conn, trim($item['diamond_cent'] ?? ''));
                $item_metal_kt     = mysqli_real_escape_string($conn, trim($item['metal_kt'] ?? $item['gold_kt'] ?? ''));
                $item_metal_weight = mysqli_real_escape_string($conn, trim($item['metal_weight'] ?? $item['gold_weight'] ?? ''));
                $item_hallmark_type = mysqli_real_escape_string($conn, trim($item['hallmark_type'] ?? '22K'));

                if($product_id === 'other' || !is_numeric($product_id)) {
                    $item_query = "INSERT INTO invoice_items (invoice_id, product_id, product_name, serial_no, huid_code, hsn_code, quantity, price, total, making_charge, making_charge_pct, hallmark, hallmark_type, discount, pcs, unit, gross_wt, net_wt, gst_type, diamond_cent, metal_kt, metal_weight) VALUES ($invoice_id, NULL, '".$manual_name."', '".$manual_serial."', '".$manual_huid."', '".$manual_hsn."', $quantity, $price, $total, $item_making_charge, $item_making_charge_pct, $item_hallmark, '".$item_hallmark_type."', $item_discount, $item_pcs, '".$item_unit."', $item_gross, $item_net, '".$item_gst_type."', '".$item_diamond_cent."', '".$item_metal_kt."', '".$item_metal_weight."')";
                    mysqli_query($conn, $item_query);
                    log_product_activity($conn, [
                        'activity_type' => 'billed',
                        'product_id' => null,
                        'product_name' => $manual_name ?: 'Manual Jewellery Item',
                        'category' => 'Custom Item',
                        'serial_no' => $manual_serial,
                        'huid_code' => $manual_huid,
                        'quantity' => $item_pcs,
                        'unit' => $item_unit,
                        'weight' => $item_gross,
                        'rate' => $price,
                        'total_amount' => $total,
                        'invoice_no' => $invoice_no,
                        'customer_name' => $customer_name,
                        'customer_mobile' => $customer_mobile,
                        'details' => "Billed in Invoice/CashMemo #$invoice_no to " . ($customer_name ?: 'Customer'),
                        'performed_by' => $_SESSION['user_name'] ?? 'Admin'
                    ]);
                    continue;
                }
                $pid = intval($product_id);

                // Fetch product category for activity logging
                $prod_chk = mysqli_query($conn, "SELECT category FROM products WHERE id = $pid");
                $p_cat = ($prod_chk && $p_info = mysqli_fetch_assoc($prod_chk)) ? ($p_info['category'] ?? '') : '';

                $item_query = "INSERT INTO invoice_items (invoice_id, product_id, product_name, serial_no, huid_code, hsn_code, quantity, price, total, making_charge, making_charge_pct, hallmark, hallmark_type, discount, pcs, unit, gross_wt, net_wt, gst_type, diamond_cent, metal_kt, metal_weight)
                               VALUES ($invoice_id, $pid, '".$manual_name."', '".$manual_serial."', '".$manual_huid."', '".$manual_hsn."', $quantity, $price, $total, $item_making_charge, $item_making_charge_pct, $item_hallmark, '".$item_hallmark_type."', $item_discount, $item_pcs, '".$item_unit."', $item_gross, $item_net, '".$item_gst_type."', '".$item_diamond_cent."', '".$item_metal_kt."', '".$item_metal_weight."')";
                mysqli_query($conn, $item_query);

                // Comprehensive weight deduction (gross/net weight or quantity if unit is grams)
                $w_deduct = floatval($item['gross_wt'] ?? ($item['net_wt'] ?? 0));
                if ($w_deduct <= 0 && ($item_unit === 'g' || $u_lower === 'g' || $u_lower === 'gm' || $u_lower === 'grams')) {
                    $w_deduct = floatval($item['quantity'] ?? 0);
                }
                
                if ($w_deduct > 0) {
                    mysqli_query($conn, "UPDATE products SET weight = GREATEST(0, CAST(weight AS DECIMAL(10,3)) - $w_deduct), updated_at = NOW() WHERE id = $pid AND weight IS NOT NULL AND weight != ''");
                }
                
                // 1. Deduct piece count from products.quantity
                mysqli_query($conn, "UPDATE products SET quantity = quantity - $pcs_deduct, updated_at = NOW() WHERE id = $pid");
                
                if ($w_deduct > 0) {
                    // Check if there is still weight left but quantity became <= 0 (sold partial weight of last piece)
                    $st_res = mysqli_query($conn, "SELECT weight, quantity FROM products WHERE id = $pid");
                    if ($st_res && $row = mysqli_fetch_assoc($st_res)) {
                        $rem_w = floatval($row['weight'] ?? 0);
                        $rem_qty = floatval($row['quantity'] ?? 0);
                        if ($rem_w > 0.001 && $rem_qty <= 0) {
                            // Restore quantity to 1 so the remaining weight can still be sold
                            mysqli_query($conn, "UPDATE products SET quantity = 1 WHERE id = $pid");
                        }
                    }
                }
                
                // 2. Safeguard: DO NOT hard delete zero-stock products. Mark as 0 and Out of Stock to keep barcode/catalog history intact.
                mysqli_query($conn, "UPDATE products SET quantity = 0, status = 'Out of Stock' WHERE id = $pid AND quantity < 0");
                mysqli_query($conn, "UPDATE products SET status = 'Out of Stock' WHERE id = $pid AND quantity = 0");

                // 3. Log stock billed activity
                log_product_activity($conn, [
                    'activity_type' => 'billed',
                    'product_id' => $pid,
                    'product_name' => $manual_name ?: 'Stock Jewellery Item',
                    'category' => $p_cat,
                    'serial_no' => $manual_serial,
                    'huid_code' => $manual_huid,
                    'quantity' => $pcs_deduct,
                    'unit' => $item_unit,
                    'weight' => $w_deduct,
                    'rate' => $price,
                    'total_amount' => $total,
                    'invoice_no' => $invoice_no,
                    'customer_name' => $customer_name,
                    'customer_mobile' => $customer_mobile,
                    'details' => "Sold in Invoice/CashMemo #$invoice_no to " . ($customer_name ?: 'Customer') . " (Stock reduced by " . ($w_deduct > 0 ? "{$w_deduct}g" : "{$pcs_deduct} {$item_unit}") . ")",
                    'performed_by' => $_SESSION['user_name'] ?? 'Admin'
                ]);

            }
        }
        $redirect_url = 'view_pdf.php?invoice_no=' . urlencode($invoice_no);
        if(!headers_sent()) {
            header("Location: " . $redirect_url);
            exit();
        }
        echo '<script>window.location.replace(' . json_encode($redirect_url) . ');</script>';
        exit();
    } else {
        $error = "&#10007; Error: " . mysqli_error($conn);
    }
    skip_invoice:
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Billing - MAA GOURI JEWELLERS</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="shortcut icon" type="image/png" href="logo.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Poppins', serif; }

        /* SIDEBAR */
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
        .sidebar-logo img { width: 44px; height: 44px; object-fit: cover; border-radius: 50%; background: rgba(255,255,255,0.1); }
        .sidebar-logo-text h2 { color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3; font-family: 'Poppins', serif; }
        .sidebar-logo-text p { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }
        .sidebar-nav { flex: 1; padding: 10px 0; overflow-y: auto; overflow-x: hidden; }
        .sidebar-section-label { padding: 10px 20px 4px; color: rgba(255,255,255,0.45); font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; position: sticky; top: 0; background: #011921; color: #f5c842; z-index: 10; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent; position: relative; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
        .sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
        .sidebar-nav a.active::after { content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 60%; background: #fff; border-radius: 4px 0 0 4px; }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }
        .sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }
        .sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
        .sidebar-overlay.active { display: block; }
        .page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; }
        nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important; }
        .burger-menu { width: 28px; height: 20px; position: relative; cursor: pointer; }
        .burger-menu span { display: block; position: absolute; height: 3px; width: 100%; background: #fff; border-radius: 3px; transition: all 0.3s ease; }
        .burger-menu span:nth-child(1) { top: 0; }
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

        /* GENERAL */
        body { background: #F5F5F5; margin: 0; padding: 0; }
        .jewel-card { background: linear-gradient(145deg, #fdf6e3, #f5ead0); border-radius: 16px; border: 1px solid rgba(181,115,14,0.2); box-shadow: 0 4px 20px rgba(181,115,14,0.08); }
        .jewel-input { background: #fff; border: 1.5px solid rgba(181,115,14,0.3); color: #3a1f00; font-size: 13px; transition: border-color 0.2s, box-shadow 0.2s; }
        .jewel-input:focus { outline: none; border-color: #d68b16; box-shadow: 0 0 0 3px rgba(214,139,22,0.15); }
        .jewel-table { border-collapse: collapse; width: 100%; }
        .jewel-table thead tr { background: linear-gradient(135deg, #7a4e0a, #d68b16); }
        .jewel-table thead th { color: #fff; font-size: 11px; padding: 8px 6px; text-align: left; }
        .jewel-table tbody tr { border-bottom: 1px solid rgba(181,115,14,0.12); }
        .jewel-table tbody tr:hover { background: rgba(214,139,22,0.05); }
        .btn-gold { background: linear-gradient(135deg, #d68b16, #b5730e); border: none; color: #fff; font-weight: 700; cursor: pointer; transition: all 0.2s ease; }
        .btn-gold:hover { background: linear-gradient(135deg, #e8a020, #c8830e); box-shadow: 0 4px 16px rgba(214,139,22,0.35); transform: translateY(-1px); }
        .remove-btn { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); border-radius: 6px; padding: 3px 10px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
        .remove-btn:hover { background: #ef4444; color: #fff; }

        /* PRODUCT SELECT TABS */
        .add-mode-tabs { display: flex; gap: 0; margin-bottom: 12px; border-radius: 10px; overflow: hidden; border: 1.5px solid rgba(181,115,14,0.3); }
        .add-mode-tab { flex: 1; padding: 9px 8px; text-align: center; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; background: #fff; color: #7a4e0a; border: none; }
        .add-mode-tab.active { background: linear-gradient(135deg, #d68b16, #b5730e); color: #fff; }
        .add-mode-panel { display: none; }
        .add-mode-panel.active { display: block; }

        /* SPLIT PAYMENT */
        .split-payment-box { background: linear-gradient(145deg, #f0f9ff, #e0f2fe); border: 1.5px solid rgba(37,99,235,0.2); border-radius: 14px; padding: 16px; margin-top: 12px; }
        .split-input-cash { border: 1.5px solid rgba(5,150,105,0.4) !important; color: #065f46 !important; }
        .split-input-cash:focus { border-color: #059669 !important; box-shadow: 0 0 0 3px rgba(5,150,105,0.15) !important; }
        .split-input-upi { border: 1.5px solid rgba(37,99,235,0.4) !important; color: #1e3a8a !important; }
        .split-input-upi:focus { border-color: #2563eb !important; box-shadow: 0 0 0 3px rgba(37,99,235,0.15) !important; }
        .split-progress-wrap { background: #e5e7eb; border-radius: 999px; height: 10px; overflow: hidden; margin: 10px 0; display: flex; }
        .split-bar-cash { height: 100%; background: linear-gradient(90deg, #059669, #34d399); transition: width 0.35s ease; }
        .split-bar-upi { height: 100%; background: linear-gradient(90deg, #2563eb, #60a5fa); transition: width 0.35s ease; }
        .split-legend { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
        .split-legend-item { display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; }
        .split-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .split-summary-row { display: flex; justify-content: space-between; align-items: center; font-size: 12px; padding: 3px 0; }

        /* DUE TODAY SECTION */
        .due-today-section { border: 2px solid #fca5a5; border-radius: 16px; overflow: hidden; margin-bottom: 24px; }
        .due-today-header { background: linear-gradient(135deg, #dc2626, #b91c1c); padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; }
        .due-today-grid { background: #fff9f9; padding: 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
        .due-card { background: #fff; border: 1px solid #fca5a5; border-radius: 12px; padding: 14px; position: relative; transition: box-shadow 0.2s; }
        .due-card:hover { box-shadow: 0 4px 16px rgba(220,38,38,0.12); }
        .due-avatar { width: 40px; height: 40px; border-radius: 50%; background: #fef2f2; border: 1.5px solid #fca5a5; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; color: #dc2626; flex-shrink: 0; }
        .due-action-btn { flex: 1; padding: 8px 6px; border-radius: 8px; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .due-btn-remind { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
        .due-btn-remind:hover { background: #fde68a; }
        .due-btn-remind:disabled { opacity: 0.6; cursor: default; }
        .due-btn-paid { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .due-btn-paid:hover { background: #a7f3d0; }
        .due-btn-paid:disabled { opacity: 0.6; cursor: default; }
        @keyframes bellRing {
            0%,100%{transform:rotate(0)}
            20%{transform:rotate(-18deg)}
            40%{transform:rotate(18deg)}
            60%{transform:rotate(-10deg)}
            80%{transform:rotate(10deg)}
        }
        .bell-ring { animation: bellRing 2s ease-in-out infinite; display: inline-block; }

        /* LOADING OVERLAY */
        @keyframes ornFloat { 0%,100%{transform:rotate(0deg) scale(1);opacity:0.15} 50%{transform:rotate(20deg) scale(1.15);opacity:0.28} }
        @keyframes haloPulse { 0%,100%{opacity:0.3;transform:scale(1)} 50%{opacity:1;transform:scale(1.1)} }
        @keyframes gemGlowPulse { 0%,100%{filter:drop-shadow(0 0 8px #d68b16)} 50%{filter:drop-shadow(0 0 22px #ff9900)} }
        @keyframes titleGold { from{color:#d68b16} to{color:#f5c842} }
        @keyframes barSlide { 0%{transform:translateX(-100%)} 100%{transform:translateX(480%)} }
        @keyframes dotBounce { 0%,100%{opacity:0.3;transform:scale(0.7)} 50%{opacity:1;transform:scale(1.2)} }
        @keyframes notifSlide { from{transform:translateX(400px);opacity:0} to{transform:translateX(0);opacity:1} }
        .jewel-sparkle { position: fixed; border-radius: 50%; pointer-events: none; z-index: 0; animation: sparkleFloat linear infinite; }
        @keyframes sparkleFloat { 0%{transform:translateY(100vh) scale(0);opacity:0} 10%{opacity:1} 90%{opacity:0.5} 100%{transform:translateY(-10vh) scale(1);opacity:0} }

        @media print {
            body * { visibility: hidden; }
            .print-invoice, .print-invoice * { visibility: visible; }
            .print-invoice { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
            .sidebar, .sidebar-overlay, nav.nav-gold { display: none !important; }
        }
    </style>
    <link rel="stylesheet" href="mobile.css">
</head>
<body class="<?php echo $theme == 'light' ? 'light-theme' : 'dark-theme'; ?>">

<script>
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
function createJewelSparkles() {
    const colors = ['#d68b16','#b5730e','#800020','#c9a96e','#f5c842'];
    document.querySelectorAll('.jewel-sparkle').forEach(s => s.remove());
    for(let i = 0; i < 40; i++) {
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
function populateStockSelects() {
    if (typeof filterGramStock === 'function') filterGramStock('');
}
function loadShopRates() {}

window.addEventListener('load', function() {
    try {
        populateStockSelects();
        loadShopRates();
    } catch(e) {
        console.warn("Init error:", e);
    }

    const isReload = performance.getEntriesByType("navigation")[0]?.type === "reload";
    const hasVisited = sessionStorage.getItem('visited');

    const hideOverlay = function() {
        const ov = document.getElementById('loadingOverlay');
        if(ov) {
            ov.style.opacity = '0';
            ov.style.visibility = 'hidden';
            setTimeout(() => ov.style.display = 'none', 500);
        }
        const pw = document.querySelector('.page-wrapper');
        if(pw) { pw.style.animation = 'slideInFromRightGlobal 0.3s ease-out forwards'; }
    };

    if (!hasVisited || isReload) {
        sessionStorage.setItem('visited', 'true');
        try { createJewelSparkles(); } catch(e) {}
        setTimeout(hideOverlay, 800);
    } else {
        hideOverlay();
    }
});
</script>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;overflow:hidden;transition:opacity 0.6s ease,visibility 0.6s ease;background:radial-gradient(ellipse at 50% 60%, #1a0a00 0%, #0d0500 100%);">
    <!-- <div style="position:absolute;top:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite;">&#10022;</div>
    <div style="position:absolute;top:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 1s;">&#10022;</div>
    <div style="position:absolute;bottom:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 2s;">&#10022;</div>
    <div style="position:absolute;bottom:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 3s;">&#10022;</div> -->
    <div style="position:relative;z-index:10;text-align:center;">
                <div style="position:relative;width:120px;height:120px;margin:0 auto 24px;display:flex;align-items:center;justify-content:center;">
            
            
            <div style="width:120px;height:120px;background:transparent;animation:gemGlowPulse 1.5s ease-in-out infinite;">
                <img src="<?php echo htmlspecialchars($COMPANY['logo_path'] ?? 'logo.png'); ?>" alt="MAA GOURI JEWELLERS Logo" style="width:100%;height:100%;object-fit:contain;display:block;">
            </div>
        </div>
        <div style="display:flex;gap:9px;justify-content:center;">
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite;"></div>
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite 0.2s;"></div>
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite 0.4s;"></div>
        </div>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) {
                echo '<img src="'.$path.'" alt="Logo">';
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

        <a href="index.php">
            <i class="fas fa-home"></i> DASHBOARD
        </a>
        <a href="billing.php" class="active">
            <i class="fas fa-receipt"></i> BILLING
        </a>
        <a href="stock.php">
            <i class="fas fa-boxes"></i> STOCK
        </a>
        <a href="customers.php">
            <i class="fas fa-users"></i> CUSTOMERS
        </a>
        <a href="transfer.php">
            <i class="fas fa-exchange-alt"></i> TRANSFER
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

        <a href="product_summary.php">
            <i class="fas fa-boxes-packing"></i> PRODUCT SUMMARY
        </a>
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

<!-- TOP NAVBAR -->
<nav class="nav-gold shadow-lg sticky top-0 z-50 no-print" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3">
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-4">
                <div class="mobile-burger" style="display:none;">
                    <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                        <span></span><span></span><span></span>
                    </div>
                </div>
                <span class="font-bold text-white text-sm hidden sm:inline" style="font-family:'Poppins',serif;">
                    <i class="fas fa-receipt mr-2"></i>Billing
                </span>
            </div>
            <span class="text-sm font-medium text-white">
                <i class="fas fa-user mr-1"></i>
                <?php echo htmlspecialchars($_SESSION['user_name']); ?>
            </span>
        </div>
    </div>
</nav>

<!-- PAGE WRAPPER -->
<div class="page-wrapper">
<div class="container mx-auto px-4 sm:px-6 py-6 sm:py-8 no-print">

    <?php if($success): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:#f0fdf4;border:1px solid #86efac;color:#166534;"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if($error): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if(isset($pdf_success)): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:#f0fdf4;border:1px solid #86efac;color:#166534;"><?php echo $pdf_success; ?></div>
    <?php endif; ?>
    <?php if(isset($pdf_error)): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;"><?php echo $pdf_error; ?></div>
    <?php endif; ?>

    <!-- Page Title -->
    <div class="mb-6">
        <h2 class="text-2xl sm:text-3xl font-bold" style="color:#800020;font-family:'Poppins',serif;">
            <i class="fas fa-receipt mr-2" style="color:#d68b16;"></i> Billing
        </h2>
        <p class="text-sm mt-1" style="color:#7a4e0a;">Create invoices and manage customer transactions</p>
    </div>

    <!--  PAYMENT DUE TODAY SECTION  -->

<?php if(!empty($due_today_bills)): ?>
    <div class="due-today-section">
        <!-- Header -->
        <div class="due-today-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="bell-ring" style="font-size:22px;color:#fff;" title="Due Today">&#128276;</span>
                <div style="flex:1;min-width:0;">
                    <div style="color:#fff;font-weight:700;font-size:15px;font-family:'Poppins',serif;">
                        Payment Due Today
                    </div>
                    <div style="color:rgba(255,255,255,0.78);font-size:11px;">
                        <?php echo count($due_today_bills); ?> customer<?php echo count($due_today_bills) > 1 ? 's' : ''; ?> have pending balance due today
                    </div>
                </div>
                <button type="button" class="due-close-btn" onclick="closeDueTodaySection()" title="Hide this section"
                    style="background:rgba(255,255,255,0.18);border:none;color:#fff;font-size:18px;line-height:1;width:32px;height:32px;border-radius:999px;cursor:pointer;">
                    &times;
                </button>
            </div>
            <span style="background:rgba(255,255,255,0.22);color:#fff;font-size:12px;font-weight:700;padding:4px 14px;border-radius:20px;">
                <?php echo date('d M Y'); ?>
            </span>
        </div>

        <!-- Cards Grid -->
        <div class="due-today-grid">
            <?php foreach($due_today_bills as $db):
                $words = explode(' ', trim($db['customer_name']));
                $initials = strtoupper(substr($words[0], 0, 1));
                if(count($words) >= 2) $initials .= strtoupper(substr($words[1], 0, 1));
                $pct = $db['total_amount'] > 0 ? min(100, round(($db['paid_amount'] / $db['total_amount']) * 100)) : 0;
                $safe_inv  = htmlspecialchars($db['invoice_no']);
                $safe_name = htmlspecialchars(addslashes($db['customer_name']));
                $safe_mob  = htmlspecialchars($db['customer_mobile']);
                $balance   = floatval($db['balance_amount']);
            ?>
            <div class="due-card" id="duecard-<?php echo $safe_inv; ?>">

                <!-- Customer info row -->
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                    <div class="due-avatar"><?php echo $initials; ?></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:13px;color:#991b1b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo htmlspecialchars($db['customer_name']); ?>
                        </div>
                        <div style="font-size:11px;color:#9ca3af;">
                            <?php echo htmlspecialchars($db['customer_mobile']); ?>
                            &nbsp;&middot;&nbsp;
                            <span style="color:#b5730e;font-weight:600;"><?php echo $safe_inv; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Amount boxes -->
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <div style="flex:1;background:#fef2f2;border-radius:8px;padding:8px 10px;text-align:center;">
                        <div style="font-size:10px;color:#9ca3af;margin-bottom:2px;">Balance Due</div>
                        <div style="font-size:15px;font-weight:700;color:#dc2626;">
                            &#8377;<?php echo number_format($db['balance_amount'], 2); ?>
                        </div>
                    </div>
                    <div style="flex:1;background:#f0fdf4;border-radius:8px;padding:8px 10px;text-align:center;">
                        <div style="font-size:10px;color:#9ca3af;margin-bottom:2px;">Invoice Total</div>
                        <div style="font-size:13px;font-weight:600;color:#059669;">
                            &#8377;<?php echo number_format($db['total_amount'], 2); ?>
                        </div>
                    </div>
                </div>

                <!-- Progress bar -->
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:#9ca3af;margin-bottom:3px;">
                        <span>Already paid: &#8377;<?php echo number_format($db['paid_amount'], 2); ?></span>
                        <span><?php echo $pct; ?>%</span>
                    </div>
                    <div style="background:#fee2e2;border-radius:999px;height:7px;overflow:hidden;">
                        <div style="height:100%;width:<?php echo $pct; ?>%;background:linear-gradient(90deg,#059669,#34d399);border-radius:999px;transition:width 0.4s ease;"></div>
                    </div>
                </div>

                <!-- Action buttons -->
                <div style="display:flex;gap:8px;">
                    <button type="button" class="due-action-btn due-btn-remind"
                        id="remind-btn-<?php echo $safe_inv; ?>"
                        onclick="sendDueReminder('<?php echo $safe_inv; ?>', '<?php echo $safe_name; ?>', '<?php echo $safe_mob; ?>', <?php echo $balance; ?>)">
                        &#128231; Send Reminder
                    </button>
                    <button type="button" class="due-action-btn due-btn-paid"
                        id="paid-btn-<?php echo $safe_inv; ?>"
                        onclick="markDueAsPaid('<?php echo $safe_inv; ?>', '<?php echo $safe_name; ?>', '<?php echo $safe_mob; ?>', <?php echo $db['total_amount']; ?>, <?php echo $db['paid_amount']; ?>, <?php echo $balance; ?>)">
                        &#10003; Mark as Paid
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
    </div>
<?php endif; ?>




<!-- ============================================================ -->
<!-- NEW: Payment Modal (paste once, anywhere after the section    -->
<!-- above - e.g. right before </body>)                            -->
<!-- ============================================================ -->

<div id="paymentModalOverlay" class="payment-modal-overlay" onclick="if(event.target===this) closePaymentModal()">
  <div class="payment-modal">
    <div class="payment-modal-header">
      <h3>Mark Payment</h3>
      <button onclick="closePaymentModal()">&times;</button>
    </div>
    <div class="payment-modal-body">
      <div style="font-weight:700;color:#991b1b;" id="pmCustomerName"></div>
      <div style="font-size:12px;color:#9ca3af;margin-bottom:10px;" id="pmCustomerMob"></div>
      <div class="pm-rows">
        <div><span>Invoice Total</span><span id="pmTotal"></span></div>
        <div><span>Already Paid</span><span id="pmAlreadyPaid"></span></div>
        <div><span>Balance Due</span><span id="pmBalance"></span></div>
      </div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-top:10px;">Amount Receiving Now (&#8377;)</label>
      <input type="number" id="pmAmountInput" min="0" step="0.01" oninput="updateRemainingPreview()">
      <div style="font-size:13px;font-weight:600;color:#dc2626;">
        Remaining After This Payment: <span id="pmRemainingPreview"></span>
      </div>
      <div id="pmError" style="color:#dc2626;font-size:12px;margin-top:6px;"></div>
    </div>
    <div class="payment-modal-footer">
      <button onclick="closePaymentModal()">Cancel</button>
      <button onclick="submitPayment()">Confirm Payment</button>
    </div>
  </div>
</div>


<!-- ============================================================ -->
<!-- NEW: CSS - paste inside your existing <style> tag              -->
<!-- ============================================================ -->
<style>
.payment-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;}
.payment-modal{background:#fff;border-radius:12px;width:360px;max-width:90%;padding:20px;box-shadow:0 10px 40px rgba(0,0,0,0.2);}
.payment-modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
.payment-modal-header h3{margin:0;font-family:'Poppins',serif;color:#991b1b;}
.payment-modal-header button{background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af;}
.pm-rows div{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;color:#374151;}
#pmAmountInput{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:15px;margin:8px 0;box-sizing:border-box;}
.payment-modal-footer{display:flex;gap:10px;margin-top:14px;}
.payment-modal-footer button{flex:1;padding:10px;border-radius:8px;border:none;cursor:pointer;font-weight:600;}
.payment-modal-footer button:first-child{background:#f3f4f6;color:#374151;}
.payment-modal-footer button:last-child{background:#059669;color:#fff;}
</style>


<!-- ============================================================ -->
<!-- NEW: JS - paste inside your existing <script> tag              -->
<!-- ============================================================ -->
<script>
let currentPaymentData = {};

function markDueAsPaid(invoiceNo, customerName, mobile, totalAmount, paidAmount, balanceAmount) {
  currentPaymentData = {
    invoice_no: invoiceNo,
    total: parseFloat(totalAmount),
    paid: parseFloat(paidAmount),
    balance: parseFloat(balanceAmount)
  };

  document.getElementById('pmCustomerName').textContent = customerName;
  document.getElementById('pmCustomerMob').textContent = mobile;
  document.getElementById('pmTotal').textContent = '₹' + currentPaymentData.total.toFixed(2);
  document.getElementById('pmAlreadyPaid').textContent = '₹' + currentPaymentData.paid.toFixed(2);
  document.getElementById('pmBalance').textContent = '₹' + currentPaymentData.balance.toFixed(2);

  const input = document.getElementById('pmAmountInput');
  input.value = currentPaymentData.balance.toFixed(2);
  input.max = currentPaymentData.balance;
  document.getElementById('pmError').textContent = '';
  updateRemainingPreview();

  document.getElementById('paymentModalOverlay').style.display = 'flex';
}

function closePaymentModal() {
  document.getElementById('paymentModalOverlay').style.display = 'none';
}

function updateRemainingPreview() {
  const amount = parseFloat(document.getElementById('pmAmountInput').value) || 0;
  const remaining = Math.max(currentPaymentData.balance - amount, 0);
  document.getElementById('pmRemainingPreview').textContent = '₹' + remaining.toFixed(2);
}

function submitPayment() {
  const amount = parseFloat(document.getElementById('pmAmountInput').value);
  const errorEl = document.getElementById('pmError');
  errorEl.textContent = '';

  if (isNaN(amount) || amount <= 0) {
    errorEl.textContent = 'Please enter a valid amount.';
    return;
  }
  if (amount > currentPaymentData.balance + 0.01) {
    errorEl.textContent = 'Amount cannot exceed balance due.';
    return;
  }

  const btn = document.querySelector('.payment-modal-footer button:last-child');
  btn.disabled = true;
  btn.textContent = 'Processing...';

  fetch(window.location.pathname + '?action=mark_paid', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    credentials: 'same-origin',
    body: 'invoice_no=' + encodeURIComponent(currentPaymentData.invoice_no) + '&amount=' + encodeURIComponent(amount)
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    btn.textContent = 'Confirm Payment';
    if (data.success) {
      closePaymentModal();
      showNotif(' ' + data.message, 'success');
      if (data.fully_paid) {
        const card = document.getElementById('duecard-' + currentPaymentData.invoice_no);
        if (card) {
          card.style.transition = 'opacity 0.3s, height 0.3s, margin 0.3s, padding 0.3s';
          card.style.opacity = '0';
          card.style.height = '0';
          card.style.margin = '0';
          card.style.padding = '0';
          setTimeout(() => { card.remove(); hideDueTodaySectionIfEmpty(); }, 300);
        }
      } else {
        location.reload();
      }
    } else {
      errorEl.textContent = data.message || 'Something went wrong.';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Confirm Payment';
    errorEl.textContent = 'Network error. Try again.';
  });
}
</script>
    <!--  END PAYMENT DUE TODAY SECTION  -->

    <!-- Search Bill by Mobile -->
    <div class="jewel-card p-4 sm:p-5 mb-6">
        <h3 class="text-base font-bold mb-3" style="color:#7a4e0a;">
            <i class="fas fa-search mr-2" style="color:#d68b16;"></i> Search Bill by Mobile Number
        </h3>
        <div class="flex flex-col sm:flex-row gap-3">
            <input type="tel" id="searchMobile" placeholder="&#128241; Enter Customer Mobile Number..."
                class="jewel-input flex-1 rounded-lg px-4 py-2 text-sm" maxlength="15"
                oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            <button onclick="searchBillsByMobile()" class="btn-gold px-6 py-2 rounded-lg text-sm font-bold">&#128269; Search</button>
            <button onclick="clearSearch()" class="px-4 py-2 rounded-lg text-sm font-semibold"
                style="background:#fff;border:1.5px solid rgba(181,115,14,0.4);color:#7a4e0a;">&#10006; Clear</button>
        </div>
        <div id="searchResults" class="mt-4 hidden">
            <div id="searchResultsContent"></div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Billing Form -->
        <div class="lg:col-span-2">
            <div class="jewel-card p-4 sm:p-6">
                <h2 class="text-xl sm:text-2xl font-bold mb-5" style="color:#800020;font-family:'Poppins',serif;">
                    <?php foreach($logo_paths as $path) { if(file_exists($path)) { echo '<img src="'.$path.'" style="width:32px;height:32px;object-fit:cover;border-radius:50%;border:1px solid #d68b16;display:inline-block;vertical-align:middle;margin-right:8px;">'; break; } } ?>
                    Create New Invoice
                </h2>

                <form method="POST" id="billingForm" enctype="multipart/form-data">

                    <!-- Invoice Number -->
                    <div class="mb-4 p-3 rounded-xl" style="background:rgba(214,139,22,0.05);border:1px solid rgba(214,139,22,0.2);">
                        <div class="flex items-center gap-3 mb-2">
                            <label class="text-sm font-semibold" style="color:#7a4e0a;">&#128290; Invoice Number</label>
                            <label class="flex items-center gap-2 cursor-pointer text-xs" style="color:#9ca3af;">
                                <input type="checkbox" id="manualInvoiceToggle" onchange="toggleManualInvoice()" style="accent-color:#d68b16;">
                                Enter Manual Invoice No.?
                            </label>
                        </div>
                        <div id="manualInvoiceDiv" style="display:none;">
                            <input type="text" name="manual_invoice_no" id="manualInvoiceNo"
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="e.g. INV-2024-001">
                            <p class="text-xs mt-1" style="color:#9ca3af;">&#9888;&#65039; If empty: Cash Memos auto-generate as CM-YYYYMMDD-XXXX and GST Tax Invoices as INV-YYYYMMDD-XXXX</p>
                        </div>
                        <div id="autoInvoiceInfo" class="text-xs font-semibold" style="color:#d68b16;">Auto-generated: Cash Memo (CM-YYYYMMDD-XXXX) | GST Tax Invoice (INV-YYYYMMDD-XXXX)</div>
                    </div>

                    <!-- Customer Details -->
                    <div class="p-4 rounded-xl mb-4" style="background:rgba(214,139,22,0.05);border:1.5px solid rgba(214,139,22,0.25);">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3 pb-2 border-b border-amber-200">
                            <h3 class="text-sm font-bold flex items-center gap-1.5" style="color:#7a4e0a;">
                                <i class="fas fa-user-circle" style="color:#d68b16;"></i> Customer Information
                            </h3>
                            
                            <div class="flex items-center gap-2">
                                <label for="advanceCustomerDropdownSelect" class="text-xs font-bold whitespace-nowrap" style="color:#78350f;">
                                    ⚡ Advance Customer:
                                </label>
                                <select id="advanceCustomerDropdownSelect" onchange="onAdvanceCustomerSelectChange(this.value)" class="text-xs py-1.5 px-3 rounded-lg font-bold cursor-pointer transition shadow-sm" style="background:#78350f;color:#ffffff;border:1.5px solid #92400e;max-width:320px;">
                                    <option value="" style="background:#fff;color:#1c1917;">-- ⚡ Select Advance Customer (<?php echo count($active_advance_customers); ?> Active) --</option>
                                    <?php foreach($active_advance_customers as $ac): ?>
                                    <option value="<?php echo $ac['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($ac['customer_name'], ENT_QUOTES); ?>"
                                            data-mobile="<?php echo htmlspecialchars($ac['customer_mobile'], ENT_QUOTES); ?>"
                                            data-address="<?php echo htmlspecialchars($ac['customer_address'] ?? '', ENT_QUOTES); ?>"
                                            data-amount="<?php echo floatval($ac['advance_amount'] ?? $ac['available_amount']); ?>"
                                            data-available="<?php echo floatval($ac['available_amount']); ?>"
                                            data-receipt="<?php echo htmlspecialchars($ac['receipt_no'], ENT_QUOTES); ?>"
                                            style="background:#fff;color:#1c1917;">
                                        👤 <?php echo htmlspecialchars($ac['customer_name']); ?> (₹<?php echo number_format($ac['available_amount'], 2); ?> Avail) - <?php echo htmlspecialchars($ac['customer_mobile']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>

                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Customer Name *</label>
                                <input type="text" name="customer_name" id="customerName" required
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm font-medium" placeholder="Enter full name" 
                                    list="advanceCustomerDatalist"
                                    autocomplete="off"
                                    oninput="onCustomerDetailsInput()">
                                <datalist id="advanceCustomerDatalist">
                                    <?php foreach($active_advance_customers as $ac): ?>
                                    <option value="<?php echo htmlspecialchars($ac['customer_name']); ?>">
                                        ₹<?php echo number_format($ac['available_amount'], 2); ?> Advance Available &bull; Ph: <?php echo htmlspecialchars($ac['customer_mobile']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div>
                                <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Mobile Number *</label>
                                <input type="tel" name="customer_mobile" id="customerMobile" required
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm font-medium" placeholder="10-digit mobile number" 
                                    list="advanceMobileDatalist"
                                    autocomplete="off"
                                    oninput="onCustomerDetailsInput()">
                                <datalist id="advanceMobileDatalist">
                                    <?php foreach($active_advance_customers as $ac): ?>
                                    <option value="<?php echo htmlspecialchars($ac['customer_mobile']); ?>">
                                        <?php echo htmlspecialchars($ac['customer_name']); ?> (₹<?php echo number_format($ac['available_amount'], 2); ?> Advance)
                                    </option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <!-- Active Advance Credit Customer Alert Banner -->
                            <div id="advanceCreditCustomerBanner" class="sm:col-span-2 hidden p-3.5 rounded-xl text-xs transition" style="background:linear-gradient(135deg,#ecfdf5,#f0fdf4);border:2px solid #10b981;box-shadow:0 4px 12px rgba(16,185,129,0.15);">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <div style="width:38px;height:38px;background:#059669;color:#fff;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
                                            💳
                                        </div>
                                        <div>
                                            <div class="font-extrabold text-sm flex items-center gap-2 flex-wrap" style="color:#064e3b;">
                                                <span>Active Advance Credit:</span>
                                                <span id="bannerAdvAmount" class="text-sm font-black px-2.5 py-0.5 rounded-lg" style="background:#fff;color:#047857;border:1.5px solid #6ee7b7;">₹0.00</span>
                                            </div>
                                            <div class="text-xs font-medium mt-0.5 flex items-center gap-2 flex-wrap" style="color:#065f46;">
                                                <span>Receipt: <strong id="bannerAdvReceipt" class="font-mono">-</strong></span>
                                                <span>&bull;</span>
                                                <span>Original Deposit: <strong id="bannerAdvTotal">₹0.00</strong></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" onclick="scrollAndFocusAdvanceAdjustment()" class="px-4 py-2 text-white font-extrabold rounded-lg text-xs transition shadow cursor-pointer flex items-center gap-1.5" style="background:linear-gradient(135deg,#059669,#047857);border:none;">
                                            <span>Apply Advance to Bill</span> <i class="fas fa-arrow-down text-[10px]"></i>
                                        </button>
                                        <button type="button" onclick="clearCustomerAdvanceCredit()" title="Detach advance credit" class="px-3 py-2 font-bold rounded-lg text-xs transition cursor-pointer" style="background:#fff;color:#dc2626;border:1px solid #fca5a5;">
                                            ✕ Cancel
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Address</label>
                                <input type="text" name="customer_address" id="customerAddress"
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="Customer Address">
                            </div>
                            <div>
                                <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Email <span style="color:#9ca3af;font-size:11px;">(Optional, required for reminder email)</span></label>
                                <input type="email" name="customer_email" id="customerEmail"
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="customer@email.com">
                            </div>
                            <div>
                                <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">GSTIN <span style="color:#9ca3af;font-size:11px;">(Optional)</span></label>
                                <input type="text" name="customer_gstin" id="customerGstin"
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="e.g. 19ELQPP1010L1ZR"
                                    maxlength="15" style="text-transform:uppercase;"
                                    oninput="this.value=this.value.toUpperCase(); calculateTotal();">
                            </div>
                        </div>
                    </div>




                    <!-- ADD ITEM SECTION -->
                    <div class="mb-4 p-4 rounded-xl" style="background:rgba(214,139,22,0.04);border:1.5px solid rgba(214,139,22,0.2);">
                        <h3 class="text-sm font-bold mb-3" style="color:#800020;">
                            <i class="fas fa-plus-circle mr-1" style="color:#d68b16;"></i> Add Items
                        </h3>

                        <!-- UNIFIED FORM PANEL -->
                        <div class="add-mode-panel active" id="panelGram">
                            <div class="flex items-center gap-4 p-3 rounded-lg bg-amber-50/60 border border-amber-200/50 mb-3">
                                <span class="text-xs font-semibold text-yellow-800">Source:</span>
                                <label class="flex items-center gap-1.5 text-xs cursor-pointer font-medium text-gray-700">
                                    <input type="radio" name="gram_source" value="stock" checked onchange="switchSource('gram', 'stock')" class="accent-amber-600">
                                    From Stock
                                </label>
                                <label class="flex items-center gap-1.5 text-xs cursor-pointer font-medium text-gray-700">
                                    <input type="radio" name="gram_source" value="category" onchange="switchSource('gram', 'category')" class="accent-amber-600">
                                    From Category
                                </label>
                                <label class="flex items-center gap-1.5 text-xs cursor-pointer font-medium text-gray-700">
                                    <input type="radio" name="gram_source" value="manual" onchange="switchSource('gram', 'manual')" class="accent-amber-600">
                                    Manual Entry
                                </label>
                            </div>

                            <!-- Source: Stock -->
                            <div id="gramSourceStock" class="">
                                <p class="text-xs mb-2 text-gray-400">Search stock by name, SKU, or serial number.</p>
                                <div class="mb-2 relative">
                                    <input type="text" id="gramStockSearch" placeholder=" Search stock..." class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="filterGramStock(this.value)" autocomplete="off">
                                    <div id="gramStockSuggestions" class="autocomplete-suggestions hidden"></div>
                                </div>
                                <div class="mb-3">
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Select Product</label>
                                    <select id="gramStockProduct" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="onGramStockChange()">
                                        <option value="">-- Select Product --</option>
                                        <?php foreach($all_products as $p): 
                                            $isOutOfStock = (floatval($p['quantity'] ?? 0) <= 0);
                                            $pUnit = (strtolower($p['unit'] ?? '') === 'pair') ? 'pair' : 'pcs';
                                            $stockText = $isOutOfStock ? 'OUT OF STOCK' : ($p['quantity'] . ' ' . $pUnit);
                                            $displayName = !empty($p['item_name']) ? $p['item_name'] : $p['name'];
                                            $pCat = !empty($p['category']) ? $p['category'] : '';
                                            $catBadge = $pCat ? "[$pCat] " : '';
                                            $display = $catBadge . $displayName . ' | SN:' . ($p['serial_no'] ?: '-') . ' | Stock: ' . $stockText;
                                        ?>
                                        <option value="<?php echo $p['id']; ?>"
                                            data-price="<?php echo htmlspecialchars($p['price'] ?? 0); ?>"
                                            data-name="<?php echo htmlspecialchars($p['name'] ?? ''); ?>"
                                            data-serial="<?php echo htmlspecialchars($p['serial_no'] ?? ''); ?>"
                                            data-category="<?php echo htmlspecialchars($p['category'] ?? ''); ?>"
                                            data-item-name="<?php echo htmlspecialchars($displayName); ?>"
                                            data-qty="<?php echo htmlspecialchars($p['quantity'] ?? 0); ?>"
                                            data-unit="<?php echo htmlspecialchars($pUnit); ?>"
                                            data-weight="<?php echo htmlspecialchars($p['weight'] ?? ''); ?>"
                                            data-huid="<?php echo htmlspecialchars($p['huid_code'] ?: $p['serial_no']); ?>"
                                            data-diamond-cent="<?php echo htmlspecialchars($p['diamond_cent'] ?? ''); ?>"
                                            data-metal-kt="<?php echo htmlspecialchars($p['metal_kt'] ?? ''); ?>"
                                            data-metal-weight="<?php echo htmlspecialchars($p['metal_weight'] ?? ''); ?>"
                                            style="<?php echo $isOutOfStock ? 'color:#dc2626;' : ''; ?>"
                                        >
                                            <?php echo htmlspecialchars($display); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3" id="gramItemNameContainer">
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Specific Item Name / Ornament Type *</label>
                                    <input list="ornamentTypeList" type="text" id="gramItemNameInput" placeholder="e.g. Gold Chain, Gold Bala, Payel, Ring..." class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="autoGramTotal()">
                                    <datalist id="ornamentTypeList">
                                        <option value="Gold Chain"></option>
                                        <option value="Gold Bala"></option>
                                        <option value="Gold Necklace"></option>
                                        <option value="Gold Jhumka"></option>
                                        <option value="Gold Tops"></option>
                                        <option value="Gold Churi"></option>
                                        <option value="Gold Choker"></option>
                                        <option value="Ladies Ring"></option>
                                        <option value="Gents Ring"></option>
                                        <option value="Single Loket"></option>
                                        <option value="Double Loket"></option>
                                        <option value="Breslet Noya"></option>
                                        <option value="Silver Ornaments"></option>
                                        <option value="Silver Payel"></option>
                                        <option value="Silver Chain"></option>
                                        <option value="Silver Bala"></option>
                                        <option value="Silver Ring"></option>
                                        <option value="Silver Thali"></option>
                                        <option value="Silver Bati"></option>
                                        <option value="Silver Glass"></option>
                                    </datalist>
                                </div>
                                <div id="gramStockProductInfo" class="hidden p-2 rounded-lg text-xs bg-green-50 border border-green-200 text-green-800 mb-3"></div>
                            </div>

                            <!-- Source: Category -->
                            <div id="gramSourceCategory" class="hidden">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <label class="block mb-1 text-xs font-semibold text-yellow-800">Category</label>
                                        <select id="gramCatSelect" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="updateGramItemTypes()">
                                            <option value="">-- Select Category --</option>
                                            <option value="Gold 24K">Gold 24K</option>
                                            <option value="Gold 22K">Gold 22K</option>
                                            <option value="Gold 18K">Gold 18K</option>
                                            <option value="Gold 12K">Gold 12K</option>
                                            <option value="Gold 9K">Gold 9K</option>
                                            <option value="Silver">Silver</option>
                                            <option value="Stone">Stone</option>
                                            <option value="Diamond">Diamond</option>
                                            <option value="Others">Others</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block mb-1 text-xs font-semibold text-yellow-800">Item Type</label>
                                        <select id="gramItemType" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="onGramItemTypeChange(); autoGramTotal()">
                                            <option value="">-- Select Category first --</option>
                                        </select>
                                        <div id="gramCatStockStatus" class="mt-1 text-xs font-bold hidden"></div>
                                    </div>
                                </div>
                                <div id="gramCatLiveRateBadge" class="hidden p-2 rounded-lg text-xs bg-amber-50 border border-amber-200 text-amber-800 mb-3">
                                    <span id="gramCatLiveRateText"></span>
                                </div>
                            </div>

                            <!-- Source: Manual -->
                            <div id="gramSourceManual" class="hidden">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                    <div class="sm:col-span-2">
                                        <label class="block mb-1 text-xs font-semibold text-yellow-800">Item Description *</label>
                                        <input type="text" id="gramManualName" placeholder="e.g. Handmade Kada 22K, Box..." class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block mb-1 text-xs font-semibold text-yellow-800">HSN Code</label>
                                        <input type="text" id="gramManualHsn" placeholder="e.g. 7113" value="" class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                                    </div>
                                </div>
                            </div>

                            <!-- DUAL CALCULATION: DIAMOND CENT + GOLD BREAKDOWN CONTAINER -->
                            <div id="posDiamondBreakdownContainer" class="hidden mb-4 p-3.5 rounded-xl bg-amber-50/90 border border-amber-300/80 shadow-sm">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-xs font-bold text-amber-900 flex items-center gap-1.5">
                                        <span>💎 Diamond & Gold Mounting Breakdown</span>
                                    </h4>
                                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-amber-200/80 text-amber-950 border border-amber-300">Auto Dual Rate Calc</span>
                                </div>
                                
                                <!-- Row 1: Diamond Cent & Diamond Price Calc -->
                                <div class="p-2.5 rounded-lg bg-white border border-amber-200 mb-2.5">
                                    <div class="text-xs font-bold text-blue-900 mb-1.5 flex items-center justify-between">
                                        <span>💎 1. Diamond Component</span>
                                        <span class="text-xs text-blue-700 font-normal">Rate: ₹<span id="posDiamondRateSpan">0</span> / Ct</span>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2">
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-0.5">Diamond Carat (Ct)</label>
                                            <input type="number" id="posDiamondCent" placeholder="Carat (e.g. 0.25)" step="0.01" min="0" class="jewel-input w-full rounded-lg px-2.5 py-1.5 text-xs font-bold text-amber-900" oninput="calcDiamondBreakdown()">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-0.5">Rate / Ct (₹)</label>
                                            <input type="number" id="posDiamondRate" placeholder="Rate / Ct" step="1" min="0" class="jewel-input w-full rounded-lg px-2.5 py-1.5 text-xs font-semibold text-amber-900" oninput="calcDiamondBreakdown()">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-blue-800 mb-0.5">Diamond Price (₹)</label>
                                            <input type="number" id="posDiamondPrice" placeholder="₹ Price" step="0.01" min="0" class="jewel-input w-full rounded-lg px-2.5 py-1.5 text-xs font-bold text-blue-900 bg-blue-50/50" oninput="onManualDiamondPriceChange()">
                                        </div>
                                    </div>
                                </div>

                                <!-- Row 2: Gold Mounting Weight & Gold Price Calc -->
                                <div class="p-2.5 rounded-lg bg-white border border-amber-200 mb-2">
                                    <div class="text-xs font-bold text-amber-900 mb-1.5 flex items-center justify-between">
                                        <span>🪙 2. Gold Mounting Component</span>
                                        <span class="text-xs text-amber-800 font-normal">Rate: ₹<span id="posGoldRateSpan">0</span> / 10g</span>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-2">
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-0.5">Gold Purity (KT)</label>
                                            <select id="posGoldKt" class="jewel-input w-full rounded-lg px-2 py-1.5 text-xs font-semibold" onchange="onPosGoldKtChange()">
                                                <option value="18K">18K Gold</option>
                                                <option value="14K">14K Gold</option>
                                                <option value="22K">22K Gold</option>
                                                <option value="24K">24K Gold</option>
                                                <option value="12K">12K Gold</option>
                                                <option value="9K">9K Gold</option>
                                                <option value="Platinum">Platinum</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-0.5">Gold Weight (g)</label>
                                            <input type="number" id="posGoldWeight" placeholder="Grams" step="0.001" min="0" class="jewel-input w-full rounded-lg px-2.5 py-1.5 text-xs font-bold text-amber-900" oninput="calcDiamondBreakdown()">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-0.5">Gold Rate / 10g (₹)</label>
                                            <input type="number" id="posGoldRate" placeholder="Rate / 10g" step="1" min="0" class="jewel-input w-full rounded-lg px-2.5 py-1.5 text-xs font-semibold text-amber-900" oninput="calcDiamondBreakdown()">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-emerald-800 mb-0.5">Gold Price (₹)</label>
                                            <input type="number" id="posGoldPrice" placeholder="₹ Price" step="0.01" min="0" class="jewel-input w-full rounded-lg px-2.5 py-1.5 text-xs font-bold text-emerald-900 bg-emerald-50/50" oninput="onManualGoldPriceChange()">
                                        </div>
                                    </div>
                                </div>

                                <!-- Summary Row: Diamond Price + Gold Price = Total Item Base Price -->
                                <div class="flex items-center justify-between p-2 rounded-lg bg-amber-100/80 border border-amber-300/80 text-xs">
                                    <div class="font-semibold text-amber-950">
                                        Total Item Base: <span class="font-bold text-blue-900" id="breakdownDiamondSummary">₹0</span> (Diamond) + <span class="font-bold text-emerald-900" id="breakdownGoldSummary">₹0</span> (Gold)
                                    </div>
                                    <div class="font-black text-sm text-amber-900" id="breakdownTotalSummary">₹0.00</div>
                                </div>
                            </div>

                            <!-- Rate & Rate Hint -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Rate / Price (₹) *</label>
                                    <input type="number" id="gramRate" placeholder="Rate per 10g or Piece" step="0.01" min="0" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="autoGramTotal()">
                                    <div class="text-xs mt-1" style="color:#059669;" id="gramRatePerGramHint"></div>
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">HUID Code <span style="color:#9ca3af;">(Optional)</span></label>
                                    <input type="text" name="huid_code" id="manualHuid" placeholder="Leave blank if none"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm" list="huidList" oninput="populateHuidOptions()">
                                    <datalist id="huidList"></datalist>
                                </div>
                            </div>
                            
                            <!-- Gross Weight & Net Weight (For Stock & Category items) -->
                            <div id="weightFieldsGrossNet" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Gross Weight (g) <span class="text-stone-400 font-normal">(Cut from Stock)</span> *</label>
                                    <input type="number" id="gramGrossWeight" placeholder="Gross weight (e.g. 10.500)" step="0.001" min="0" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="onGrossWeightInput()">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Net Weight (g) <span class="text-emerald-700 font-bold">(Used for Price)</span> *</label>
                                    <input type="number" id="gramNetWeight" placeholder="Net weight (e.g. 10.000)" step="0.001" min="0" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="onNetWeightInput()">
                                </div>
                            </div>

                            <!-- Single Weight Field (For Manual Entry items only) -->
                            <div id="weightFieldsManual" class="hidden mb-3">
                                <label class="block mb-1 text-xs font-semibold text-yellow-800">Weight (g) <span class="text-stone-400 font-normal">(Optional for Piece / Mala / Stone)</span></label>
                                <input type="number" id="gramManualWeight" placeholder="Weight in grams (optional)" step="0.001" min="0" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="autoGramTotal()">
                            </div>

                            <!-- Quantity & Unit -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Quantity &amp; Unit *</label>
                                    <div class="flex gap-2">
                                        <input type="number" id="gramQty" value="1" step="1" min="1" class="jewel-input w-1/2 rounded-lg px-3 py-2 text-sm" oninput="autoGramTotal()" onchange="autoGramTotal()" onkeyup="autoGramTotal()">
                                        <select id="gramUnit" class="jewel-input w-1/2 rounded-lg px-2 py-2 text-sm" onchange="autoGramTotal()" oninput="autoGramTotal()">
                                            <option value="pcs">Pcs (1 pc)</option>
                                            <option value="pair">Pair (2 pcs)</option>
                                        </select>
                                    </div>
                                </div>
                                <div id="gramTotalPreviewRow" style="display:none;" class="flex flex-col justify-end">
                                    <label class="block mb-1 text-xs font-semibold text-green-700">Calculated Base Amount</label>
                                    <div class="text-base font-bold text-green-800 px-3 py-1.5 bg-green-50 border border-green-200 rounded-lg" id="gramTotalPreview">₹0.00</div>
                                </div>
                            </div>
                        </div>

                        <!-- Per-Item GST & Extra Charges -->
                        <div class="mt-4 pt-4 border-t border-dashed border-amber-300/60">
                            <h4 class="text-xs font-bold mb-3 text-amber-900 flex items-center gap-1.5">
                                <span>✨ Additional Details for this Item</span>
                            </h4>
                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                                <div>
                                    <div class="flex items-center justify-between mb-1.5">
                                        <label id="mcLabel" class="text-xs font-bold text-amber-900">Making Charge</label>
                                        <div class="inline-flex p-0.5 bg-amber-200/70 rounded-md gap-0.5">
                                            <button type="button" id="btnMcPct" onclick="setMcMode('pct')" class="mc-tab-btn active px-2 py-0.5 text-[11px] font-bold rounded transition-all bg-amber-700 text-white shadow-sm">
                                                %
                                            </button>
                                            <button type="button" id="btnMcDirect" onclick="setMcMode('direct')" class="mc-tab-btn px-2 py-0.5 text-[11px] font-bold rounded transition-all text-amber-900 hover:bg-white/50">
                                                ₹
                                            </button>
                                        </div>
                                    </div>
                                    <input type="number" id="itemMakingCharge" value="" step="0.1" min="0" placeholder="e.g. 12%" class="jewel-input w-full rounded-xl px-3 py-2 text-sm font-semibold" oninput="updateMakingChargeHint()">
                                    <div id="itemMakingChargeHint" class="text-xs mt-1.5 font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-lg border border-emerald-200" style="display:none;"></div>
                                </div>
                                <div>
                                    <label class="block mb-1.5 text-xs font-bold text-amber-900">Karat / Hallmark</label>
                                    <select id="itemHallmarkType" class="jewel-input w-full rounded-xl px-2.5 py-2 text-xs font-bold" onchange="onHallmarkTypeChange()">
                                        <option value="None">None / NA</option>
                                        <option value="22K" selected>22K</option>
                                        <option value="18K">18K</option>
                                        <option value="14K">14K</option>
                                        <option value="24K">24K</option>
                                        <option value="925 Silver">925 Silver</option>
                                        <option value="999 Fine Silver">999 Fine Silver</option>
                                        <option value="Custom">Custom...</option>
                                    </select>
                                    <input type="text" id="itemHallmarkCustom" placeholder="Type custom hallmark (e.g. 21K, 916)" class="hidden jewel-input w-full rounded-xl px-2.5 py-1.5 text-xs font-bold mt-1.5 border-amber-400 bg-amber-50/40">
                                </div>
                                <div>
                                    <label class="block mb-1.5 text-xs font-bold text-amber-900">Hallmark Chg (₹)</label>
                                    <input type="number" id="itemHallmark" value="" step="1" min="0" placeholder="0 (Optional)" class="jewel-input w-full rounded-xl px-3 py-2 text-sm font-semibold">
                                </div>
                                <div>
                                    <label class="block mb-1.5 text-xs font-bold text-amber-900">GST Rate</label>
                                    <select id="itemGstType" class="jewel-input w-full rounded-xl px-3 py-2 text-sm font-semibold">
                                        <option value="non_gst">0% (Non-GST)</option>
                                        <option value="gst_3">3% GST</option>
                                        <option value="gst_18">18% GST</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Unified Add Item Button -->
                        <div class="mt-4">
                            <button type="button" id="unifiedAddBtn" onclick="submitCurrentItem()"
                                class="btn-gold w-full py-3 rounded-xl text-sm font-bold flex items-center justify-center gap-2 shadow-md">
                                <i class="fas fa-plus"></i> Add Item to Bill
                            </button>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="overflow-x-auto mb-4">
                        <table class="jewel-table rounded-xl overflow-hidden">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs">#</th>
                                    <th class="px-3 py-2 text-left text-xs">Product / Description</th>
                                    <th class="px-3 py-2 text-center text-xs">GMS/Qty</th>
                                    <th class="px-3 py-2 text-right text-xs">Rate</th>
                                    <th class="px-3 py-2 text-right text-xs">Amount</th>
                                    <th class="px-3 py-2 text-right text-xs" style="width:110px;">Making&nbsp;Chg&nbsp;(%)</th>
                                    <th class="px-3 py-2 text-right text-xs" style="width:85px;">Hallmark</th>
                                    <th class="px-3 py-2 text-right text-xs">Net Total</th>
                                    <th class="px-3 py-2 text-center text-xs">GST</th>
                                    <th class="px-3 py-2 text-xs"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsList">
                                <tr id="emptyRow">
                                    <td colspan="10" style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">
                                        No items added yet - enter details above to add products
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals -->
                    <div class="p-4 rounded-xl" style="background:rgba(214,139,22,0.06);border:1px solid rgba(214,139,22,0.18);">
                        <div class="flex justify-end">
                            <div class="w-full sm:w-96 space-y-1.5">
                                <div class="flex justify-between text-sm pb-1 mb-1" style="color:#059669;border-bottom:1px dashed rgba(5,150,105,0.3);" id="totalWeightRow">
                                    <span class="font-medium">Total Gross Weight</span><span id="totalWeightDisplay" class="font-bold">0.000 g</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#7a4e0a;">
                                    <span>Subtotal</span><span id="subtotal" class="font-semibold">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#b5730e;">
                                    <span>Making Charge</span><span id="makingChargeAmount">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#059669;">
                                    <span>Hallmark</span><span id="hallmarkAmount">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between items-center text-sm py-1" style="color:#dc2626;border-top:1px dashed rgba(220,38,38,0.2);border-bottom:1px dashed rgba(220,38,38,0.2);">
                                    <div class="flex items-center gap-1 font-bold text-xs" style="color:#dc2626;">
                                        <i class="fas fa-tag"></i> <span>Discount on Total</span> <span class="font-semibold text-xs">(₹)</span>
                                    </div>
                                    <input type="number" id="overallDiscountInput" value="" step="1" min="0" placeholder="0" class="jewel-input rounded-lg px-2 py-1 text-sm text-right w-28 border-red-300 font-bold" style="color:#dc2626;" oninput="calculateTotal()">
                                </div>


                                <!-- ADVANCE CREDIT ADJUSTMENT (DIRECTLY ABOVE OLD GOLD) -->
                                <div id="advanceAdjustmentContainer" class="p-3.5 rounded-xl border-2 border-amber-400 bg-gradient-to-b from-amber-50 to-amber-100/70 mb-2.5 space-y-2.5 text-xs shadow-sm" style="display:none;">
                                    <div class="flex items-center justify-between pb-1.5 border-b border-amber-200">
                                        <span class="font-black text-amber-950 flex items-center gap-1.5 text-xs">
                                            <i class="fas fa-hand-holding-usd text-amber-700"></i> Advance Credit Available:
                                        </span>
                                        <span id="advAvailableBadge" class="font-black text-emerald-800 bg-emerald-100 border border-emerald-400 px-2 py-0.5 rounded-md text-xs shadow-sm">₹0.00</span>
                                    </div>
                                    
                                    <div class="grid grid-cols-2 gap-2">
                                        <button type="button" id="btnAdvFull" onclick="setAdvanceAdjustmentMode('full')" class="py-2 px-2.5 rounded-lg font-extrabold border transition bg-amber-700 text-white border-amber-800 shadow-sm text-center text-xs hover:bg-amber-800 cursor-pointer">
                                            ⚡ Take Full Advance
                                        </button>
                                        <button type="button" id="btnAdvCustom" onclick="setAdvanceAdjustmentMode('custom')" class="py-2 px-2.5 rounded-lg font-extrabold border transition bg-white text-stone-800 border-amber-300 text-center text-xs hover:bg-amber-50 cursor-pointer">
                                            ✏️ Custom Amount
                                        </button>
                                    </div>

                                    <div id="advCustomInputDiv" style="display:none;" class="flex items-center gap-2 pt-1 bg-white p-2 rounded-lg border border-amber-300">
                                        <label class="font-extrabold text-amber-950 whitespace-nowrap text-xs">Deduct (₹):</label>
                                        <input type="number" id="advCustomAmountInput" min="0" step="1" placeholder="0" class="jewel-input w-full rounded-md px-2 py-1 text-sm font-extrabold text-right border-amber-400 bg-amber-50/50" oninput="onCustomAdvanceInput()">
                                    </div>

                                    <!-- Excess / Surplus Advance Options (Shown when Bill Amount < Advance Available) -->
                                    <div id="advSurplusContainer" style="display:none;" class="p-2.5 bg-amber-100/90 border border-amber-300 rounded-xl space-y-2 text-xs">
                                        <div class="flex items-center justify-between font-bold text-amber-950 pb-1 border-b border-amber-200">
                                            <span class="flex items-center gap-1"><i class="fas fa-coins text-amber-700"></i> Remaining Advance:</span>
                                            <span id="advSurplusAmountBadge" class="font-extrabold text-amber-950 bg-amber-200/90 px-2 py-0.5 rounded text-xs">₹0.00</span>
                                        </div>
                                        <div class="text-[11px] text-stone-700 font-semibold">Choose action for excess credit:</div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <label id="lblSurplusKeep" class="flex items-center gap-1.5 p-2 rounded-lg border-2 border-amber-700 bg-amber-200/80 cursor-pointer font-bold text-[11px] text-amber-950">
                                                <input type="radio" name="adv_surplus_action_radio" value="keep_advance" checked onchange="onAdvSurplusActionChange(this.value)">
                                                <span>🏦 Keep in Credit<br><span class="text-[9.5px] font-normal text-stone-600">(Save for future)</span></span>
                                            </label>
                                            <label id="lblSurplusRefund" class="flex items-center gap-1.5 p-2 rounded-lg border border-stone-300 bg-white cursor-pointer font-bold text-[11px] text-stone-700 hover:bg-amber-50">
                                                <input type="radio" name="adv_surplus_action_radio" value="refund_customer" onchange="onAdvSurplusActionChange(this.value)">
                                                <span>💵 Return to Customer<br><span class="text-[9.5px] font-normal text-stone-600">(Refund excess)</span></span>
                                            </label>
                                        </div>
                                        <div id="advRefundModeDiv" style="display:none;" class="pt-1.5 border-t border-amber-200 flex items-center gap-2">
                                            <label class="font-bold text-[11px] text-stone-700 whitespace-nowrap">Refund Mode:</label>
                                            <select id="advRefundModeSelect" class="jewel-input flex-1 rounded px-2 py-1 text-xs font-semibold" onchange="onAdvRefundModeChange(this.value)">
                                                <option value="Cash">Cash Return</option>
                                                <option value="UPI / Online">UPI / Online Transfer</option>
                                                <option value="Bank / Cheque">Bank / Cheque</option>
                                            </select>
                                        </div>
                                        <div id="advSurplusSummaryNote" class="text-[10px] font-semibold text-emerald-800 bg-emerald-50 px-2 py-1 rounded border border-emerald-200">
                                            Remaining balance will stay saved in customer advance credit.
                                        </div>
                                    </div>

                                    <div class="flex justify-between items-center text-xs pt-1.5 border-t border-amber-200/80 font-bold text-amber-950">
                                        <div class="flex items-center gap-2">
                                            <span>Advance Deducted:</span>
                                            <button type="button" onclick="setAdvanceAdjustmentMode('none')" class="text-[10px] text-rose-600 underline font-semibold hover:text-rose-800 cursor-pointer">Cancel</button>
                                        </div>
                                        <span id="advDeductionDisplay" class="font-black text-emerald-800 text-sm">- ₹0.00</span>
                                    </div>
                                </div>

                                <div class="flex justify-between text-sm py-1 px-2 rounded-lg bg-amber-50 border border-amber-200" style="color:#78350f; display:none;" id="advanceAdjustedRow">
                                    <span class="font-bold flex items-center gap-1"><i class="fas fa-check-circle text-emerald-600"></i> Advance Credit Adjusted</span>
                                    <span id="advanceAdjustedDisplay" class="font-black text-emerald-700">- &#8377;0.00</span>
                                </div>


                                <div class="flex justify-between items-center text-sm pt-1 pb-1" style="color:#b91c1c;border-top:1px dashed rgba(185,28,28,0.2);border-bottom:1px dashed rgba(185,28,28,0.2);">
                                    <div class="flex items-center gap-1">
                                        <select id="oldGoldTypeSelect" class="jewel-input rounded px-1 py-0.5 text-xs font-bold border-red-300 bg-white" style="color:#b91c1c;" onchange="calculateTotal()">
                                            <option value="Old Gold">Old Gold</option>
                                            <option value="Old Silver">Old Silver</option>
                                            <option value="Old Gold &amp; Silver">Old Gold &amp; Silver</option>
                                        </select>
                                        <span class="font-semibold text-xs">(₹)</span>
                                    </div>
                                    <input type="number" id="oldGoldAmountInput" value="" step="1" min="0" placeholder="0" class="jewel-input rounded-lg px-2 py-1 text-sm text-right w-28 border-red-300 font-bold" oninput="calculateTotal()">
                                </div>
                                <div class="flex justify-between text-sm" style="color:#b91c1c;display:none;" id="oldGoldRow">
                                    <span id="oldGoldLabel">Old Gold Deduction</span><span id="oldGoldDisplayAmount">- &#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#2563eb;" id="cgstRow">
                                    <span>CGST</span><span id="cgstAmount">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#2563eb;" id="sgstRow">
                                    <span>SGST</span><span id="sgstAmount">&#8377;0.00</span>
                                </div>
                                <div style="height:1px;background:rgba(181,115,14,0.25);margin:8px 0;"></div>
                                <div class="flex justify-between font-bold text-xl" style="color:#800020;">
                                    <span>Grand Total</span><span id="grandTotal">&#8377;0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hidden form fields -->
                    <input type="hidden" name="create_invoice" value="1">
                    <input type="hidden" name="subtotal" id="hiddenSubtotal" value="0">
                    <input type="hidden" name="gst_amount" id="hiddenGst" value="0">
                    <input type="hidden" name="total_amount" id="hiddenTotal" value="0">
                    <input type="hidden" name="gst_type" id="hiddenGstType" value="non_gst">
                    <input type="hidden" name="items" id="hiddenItems" value="[]">
                    <input type="hidden" name="making_charge" id="hiddenMakingCharge" value="0">
                    <input type="hidden" name="hallmark" id="hiddenHallmark" value="0">
                    <input type="hidden" name="pola" value="0">
                    <input type="hidden" name="discount" id="hiddenDiscount" value="0">
                    <input type="hidden" name="old_gold_amount" id="hiddenOldGold" value="0">
                    <input type="hidden" name="old_gold_type" id="hiddenOldGoldType" value="Old Gold">
                    <input type="hidden" name="cash_paid" id="hiddenCashPaid" value="0">
                    <input type="hidden" name="upi_paid" id="hiddenUpiPaid" value="0">
                    <input type="hidden" name="is_split_payment" id="hiddenIsSplit" value="0">
                    <input type="hidden" name="applied_advance_id" id="hiddenAppliedAdvanceId" value="0">
                    <input type="hidden" name="advance_adjusted" id="hiddenAdvanceAdjusted" value="0">
                    <input type="hidden" name="adv_surplus_action" id="hiddenAdvSurplusAction" value="keep_advance">
                    <input type="hidden" name="adv_surplus_amount" id="hiddenAdvSurplusAmount" value="0">
                    <input type="hidden" name="adv_refund_mode" id="hiddenAdvRefundMode" value="Cash">
                    <input type="hidden" name="is_advance_credit_booking" id="hiddenIsAdvanceBooking" value="0">
                    <input type="hidden" name="advance_deposit_amount" id="hiddenAdvanceDepositAmount" value="0">
                    <input type="hidden" name="booking_notes" id="hiddenBookingNotes" value="">

                    <!-- PAYMENT STATUS -->
                    <div class="mt-5">
                        <label class="block mb-2 text-sm font-bold" style="color:#7a4e0a;">&#128179; Payment Status</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">Payment Type</label>
                                <select name="payment_status" id="paymentStatus"
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="togglePartPayment()">
                                    <option value="paid">Paid (Full Payment)</option>
                                    <option value="part">Part Payment (Due)</option>
                                    <option value="advance">Advanced (Credit)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">Payment Method</label>
                                <select name="payment_method" id="paymentMethod"
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="toggleSplitPayment()">
                                    <option value="Cash">&#128181; Cash</option>
                                    <option value="UPI">&#128241; UPI</option>
                                    <option value="NEFT">&#127974; NEFT</option>
                                    <option value="Split">&#128181;+&#128241; Split (Cash + UPI)</option>
                                </select>
                            </div>
                        </div>

                        <!-- ADVANCE BOOKING DEPOSIT BOX (WHEN ADVANCED (CREDIT) SELECTED) -->
                        <div id="advanceBookingDiv" style="display:none;" class="mt-3 p-4 rounded-xl bg-amber-50/95 border-2 border-amber-400 shadow-sm">
                            <div class="flex items-center gap-2 mb-1.5 text-amber-950 font-extrabold text-sm">
                                <span>✨</span> <span>Advance Credit Deposit Details</span>
                            </div>
                            <p class="text-xs text-amber-800 mb-3 font-medium">Record advance deposit money without product selection. Generates an official Advance Credit Money Receipt.</p>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block mb-1 text-xs font-bold text-amber-950">Advance Deposit Amount (₹) *</label>
                                    <input type="number" id="advanceDepositInput" min="1" step="1" placeholder="Enter advance amount given..." class="jewel-input w-full rounded-lg px-3 py-2 text-sm font-bold border-amber-400 bg-white" oninput="onAdvanceDepositInput()">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-bold text-amber-950">Expected Order / Delivery Date</label>
                                    <input type="date" id="advanceDeliveryDate" class="jewel-input w-full rounded-lg px-3 py-2 text-sm border-amber-300 bg-white">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block mb-1 text-xs font-bold text-amber-950">Booking Purpose / Notes</label>
                                    <input type="text" id="advanceBookingNotes" placeholder="e.g. Advance for 22K Gold Necklace wedding booking" class="jewel-input w-full rounded-lg px-3 py-2 text-sm border-amber-300 bg-white">
                                </div>
                            </div>
                        </div>


                        <!-- Split Payment Box -->
                        <div id="splitPaymentDiv" style="display:none;" class="split-payment-box">
                            <div class="flex items-center gap-2 mb-3">
                                <span>&#128181;</span>
                                <p class="text-sm font-bold" style="color:#1e3a8a;">Split Payment Details</p>
                                <span>&#128241;</span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#065f46;">Cash Amount (&#8377;)</label>
                                    <input type="number" id="cashAmount" value="" step="1" min="0" placeholder="0"
                                        class="jewel-input split-input-cash w-full rounded-lg px-3 py-2 text-sm"
                                        oninput="onSplitInput('cash')">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#1e3a8a;">UPI Amount (&#8377;)</label>
                                    <input type="number" id="upiAmount" value="" step="1" min="0" placeholder="0"
                                        class="jewel-input split-input-upi w-full rounded-lg px-3 py-2 text-sm"
                                        oninput="onSplitInput('upi')">
                                </div>
                            </div>
                            <div class="flex gap-2 mb-3 flex-wrap" style="display:none;" id="quickSplitButtons">
                                <span class="text-xs font-semibold self-center" style="color:#6b7280;">Quick:</span>
                                <button type="button" onclick="quickSplit(50)" class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.3);color:#1d4ed8;">50/50</button>
                                <button type="button" onclick="quickSplit(25)" class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.3);color:#1d4ed8;">25/75</button>
                                <button type="button" onclick="quickSplit(75)" class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.3);color:#1d4ed8;">75/25</button>
                                <button type="button" onclick="quickSplit(0)"  class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(5,150,105,0.1);border:1px solid rgba(5,150,105,0.3);color:#065f46;">All Cash</button>
                                <button type="button" onclick="quickSplit(100)" class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.3);color:#1e3a8a;">All UPI</button>
                            </div>
                            <div class="split-legend">
                                <div class="split-legend-item"><div class="split-dot" style="background:#059669;"></div><span style="color:#065f46;">Cash</span></div>
                                <div class="split-legend-item"><div class="split-dot" style="background:#2563eb;"></div><span style="color:#1e3a8a;">UPI</span></div>
                            </div>
                            <div class="split-progress-wrap">
                                <div class="split-bar-cash" id="splitProgressCash" style="width:0%;"></div>
                                <div class="split-bar-upi"  id="splitProgressUpi"  style="width:0%;"></div>
                            </div>
                            <div class="p-3 rounded-xl" style="background:rgba(255,255,255,0.7);border:1px solid rgba(37,99,235,0.1);">
                                <div class="split-summary-row"><span style="color:#374151;font-weight:600;">Grand Total</span><span id="splitGrandTotal" style="color:#800020;font-weight:800;">&#8377;0.00</span></div>
                                <div style="height:1px;background:#e5e7eb;margin:4px 0;"></div>
                                <div class="split-summary-row"><span style="color:#059669;">&#128181; Cash</span><span id="splitCashDisplay" style="color:#059669;font-weight:700;">&#8377;0.00</span></div>
                                <div class="split-summary-row"><span style="color:#2563eb;">&#128241; UPI</span><span id="splitUpiDisplay" style="color:#2563eb;font-weight:700;">&#8377;0.00</span></div>
                                <div style="height:1px;background:#e5e7eb;margin:4px 0;"></div>
                                <div class="split-summary-row"><span style="color:#374151;font-weight:600;">Total Paid</span><span id="splitPaidTotal" style="color:#059669;font-weight:700;">&#8377;0.00</span></div>
                                <div class="split-summary-row"><span style="color:#dc2626;font-weight:600;">Balance Due</span><span id="splitRemaining" style="color:#dc2626;font-weight:700;">&#8377;0.00</span></div>
                            </div>
                            <div id="splitStatusBadge" class="mt-2 text-center text-xs font-bold py-2 rounded-lg hidden"></div>
                        </div>

                        <!-- Part payment paid amount + due date -->
                        <div id="partAmountDiv" style="display:none;" class="mt-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">&#128181; Paid Amount (&#8377;)</label>
                                    <input type="number" name="paid_amount" id="paidAmount" value="" step="1" min="0"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm"
                                        placeholder="How much paid now?" oninput="updateBalanceFromPart()">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">&#128197; Due Date <span style="color:#9ca3af;font-weight:400;">(when customer will pay)</span></label>
                                    <input type="date" name="due_date" id="dueDate"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                                </div>
                            </div>
                            <div id="dueDateHint" class="mt-1 text-xs hidden" style="color:#d97706;">
                                &#128197; <span id="dueDateText"></span>
                            </div>
                        </div>

                        <!-- Balance display -->
                        <div id="balanceDisplay" style="display:none;" class="mt-2 p-3 rounded-lg text-sm"
                            style="background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.3);">
                            <span style="color:#d97706;">&#9888;&#65039; Balance Amount: <strong id="balanceAmt">&#8377;0.00</strong></span>
                        </div>
                        <button type="button" id="reminderButton" onclick="sendPaymentReminder()"
                            class="btn-b mt-3 py-2 px-4 rounded-lg font-semibold"
                            style="display:none;background:#fde68a;color:#92400e;border:1px solid #facc15;">
                            &#128231; Send Reminder Email
                        </button>
                    </div>

                    <div class="mt-6">
                        <button type="submit" name="create_invoice" id="submitBtn"
                            class="btn-gold w-full py-3 rounded-xl font-bold text-lg"
                            style="background:linear-gradient(135deg,#800020,#d68b16);font-family:'Poppins',serif;letter-spacing:1px;">
                            &#10024; Generate Invoice &#10024;
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column -->
        <div>
            <!-- My Shop Rates -->
            <div class="jewel-card p-4 sm:p-5 mt-4">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="text-base font-bold" style="color:#800020;font-family:'Poppins',serif;">
                        <i class="fas fa-store mr-2" style="color:#d68b16;"></i> My Shop Rates
                    </h3>
                    <span class="text-xs px-2 py-1 rounded-lg" id="shopRateSaveStatus"
                        style="background:rgba(214,139,22,0.1);border:1px solid rgba(214,139,22,0.3);color:#b5730e;">Not saved</span>
                </div>
                <p class="text-xs mb-3" style="color:#9ca3af;">Enter price <strong>per 10 grams</strong> (as shown in market). Billing auto-converts to per gram.</p>
                <button type="button" onclick="autoFillShopFromLive()" class="w-full py-2 rounded-lg text-xs font-bold mb-3"
                    style="background:linear-gradient(135deg,#059669,#34d399);color:#fff;border:none;cursor:pointer;">
                     Auto-fill from Live Market Rates
                </button>
                <?php
                $shopFields = [
                    ['key'=>'gold24','label'=>'Gold 24K','color'=>'#d68b16','dispId'=>'shopGold24Display','inputId'=>'shopGold24Input','step'=>'1','perGramId'=>'shopGold24PerGram','unit'=>'per 10g','ph'=>'e.g. 78000'],
                    ['key'=>'gold22','label'=>'Gold 22K','color'=>'#d68b16','dispId'=>'shopGold22Display','inputId'=>'shopGold22Input','step'=>'1','perGramId'=>'shopGold22PerGram','unit'=>'per 10g','ph'=>'e.g. 65000'],
                    ['key'=>'gold18','label'=>'Gold 18K','color'=>'#b5730e','dispId'=>'shopGold18Display','inputId'=>'shopGold18Input','step'=>'1','perGramId'=>'shopGold18PerGram','unit'=>'per 10g','ph'=>'e.g. 53000'],
                    ['key'=>'gold12','label'=>'Gold 12K','color'=>'#9a5d09','dispId'=>'shopGold12Display','inputId'=>'shopGold12Input','step'=>'1','perGramId'=>'shopGold12PerGram','unit'=>'per 10g','ph'=>'e.g. 35000'],
                    ['key'=>'gold9', 'label'=>'Gold 9K', 'color'=>'#7f4a04','dispId'=>'shopGold9Display', 'inputId'=>'shopGold9Input', 'step'=>'1','perGramId'=>'shopGold9PerGram','unit'=>'per 10g','ph'=>'e.g. 26000'],
                    ['key'=>'silver','label'=>'Silver',  'color'=>'#6b7280','dispId'=>'shopSilverDisplay', 'inputId'=>'shopSilverInput', 'step'=>'0.5','perGramId'=>'shopSilverPerGram','unit'=>'per 10g','ph'=>'e.g. 900'],
                    ['key'=>'diamond','label'=>'Diamond','color'=>'#2563eb','dispId'=>'shopDiamondDisplay','inputId'=>'shopDiamondInput','step'=>'1','perGramId'=>'shopDiamondPerGram','unit'=>'per 1 Ct','ph'=>'e.g. 500'],
                ];
                foreach($shopFields as $f): 
                    $prefilledVal = isset($savedShopRates[$f['key']]) ? floatval($savedShopRates[$f['key']]) : '';
                    $unitLabel = $f['unit'];
                    $isDiamond = ($f['key'] === 'diamond');
                ?>
                <div class="mb-3 p-2 rounded-lg" style="background:rgba(214,139,22,0.03);border:1px solid rgba(214,139,22,0.12);">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-semibold" style="color:<?php echo $f['color']; ?>;">
                            <?php echo $f['label']; ?> <span style="color:#9ca3af;font-weight:400;">(<?php echo $unitLabel; ?>)</span>
                        </label>
                        <span class="text-xs font-bold" style="color:<?php echo $f['color']; ?>;" id="<?php echo $f['dispId']; ?>"><?php echo $prefilledVal ? '&#8377;' . number_format($prefilledVal) : '&#8212;'; ?></span>
                    </div>
                    <div class="flex gap-2 mb-1">
                        <input type="number" id="<?php echo $f['inputId']; ?>" placeholder="<?php echo $f['ph']; ?>"
                            value="<?php echo $prefilledVal ?: ''; ?>"
                            step="<?php echo $f['step']; ?>" min="0" class="jewel-input flex-1 rounded-lg px-3 py-2 text-sm"
                            oninput="previewShopRate('<?php echo $f['key']; ?>')">
                        <button type="button" onclick="saveShopRate('<?php echo $f['key']; ?>')"
                            class="btn-gold px-3 py-2 rounded-lg text-xs font-bold cursor-pointer">Save</button>
                    </div>
                    <div class="text-xs" style="color:#059669;" id="<?php echo $f['perGramId']; ?>">
                        <?php 
                        if ($prefilledVal) {
                            if ($isDiamond) {
                                echo '&#8776; &#8377;' . number_format($prefilledVal) . ' per 1 Ct (used in billing)';
                            } else {
                                echo '&#8776; &#8377;' . number_format($prefilledVal/10, 2) . ' per gram (used in billing)';
                            }
                        }
                        ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <button type="button" onclick="saveAllShopRates()" class="btn-gold w-full py-2 rounded-lg text-sm font-bold mt-1 cursor-pointer">
                    &#128190; Save All Rates
                </button>
                <div class="p-3 rounded-xl mt-3" style="background:rgba(214,139,22,0.05);border:1px solid rgba(181,115,14,0.12);">
                    <div class="text-xs font-semibold mb-2" style="color:#b5730e;">&#9878;&#65039; Shop Value Calculator</div>
                    <div class="flex gap-2">
                        <select id="shopMetalSelect" class="jewel-input flex-1 rounded-lg px-2 py-1 text-xs" onchange="calcShopValue()">
                            <option value="gold22">Gold 22K</option>
                            <option value="gold18">Gold 18K</option>
                            <option value="gold12">Gold 12K</option>
                            <option value="gold9">Gold 9K</option>
                            <option value="silver">Silver</option>
                            <option value="diamond">Diamond</option>
                        </select>
                        <input type="number" id="shopMetalGrams" placeholder="GMS" step="0.001" min="0"
                            class="jewel-input w-20 rounded-lg px-2 py-1 text-xs" oninput="calcShopValue()">
                    </div>
                    <div class="text-center mt-2 font-bold text-sm" id="shopCalcResult" style="color:#059669;">&#8212;</div>
                </div>
                <p class="text-xs mt-2 text-center" style="color:#9ca3af;" id="shopRateLastSaved">Rates saved in your browser</p>
            </div>

            <!-- Shop Rate Confirmation Modal -->
            <div id="shopRateConfirmModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);z-index:99999;align-items:center;justify-content:center;display:none;">
                <div class="bg-white p-5 rounded-2xl max-w-sm w-full mx-4 shadow-2xl text-center border border-amber-200" style="animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);">
                    <div class="w-12 h-12 rounded-full bg-amber-100 border border-amber-300 text-amber-800 flex items-center justify-center mx-auto mb-3 text-xl font-bold">
                        💰
                    </div>
                    <h3 class="text-base font-bold text-amber-950 mb-1" id="shopRateConfirmTitle">Confirm Rate Update</h3>
                    <p class="text-xs text-gray-600 mb-4" id="shopRateConfirmMessage">Are you sure you want to update this shop rate?</p>
                    <div class="flex gap-2">
                        <button type="button" onclick="closeShopRateConfirmModal()" class="flex-1 py-2 px-3 rounded-xl border border-gray-300 text-gray-700 font-semibold text-xs bg-gray-50 hover:bg-gray-100 cursor-pointer">
                            Cancel
                        </button>
                        <button type="button" id="btnConfirmShopRateAction" class="flex-1 py-2 px-3 rounded-xl font-bold text-xs text-white bg-gradient-to-r from-amber-600 to-yellow-600 hover:from-amber-700 hover:to-yellow-700 shadow-md cursor-pointer">
                            ✓ Yes, Save Rate
                        </button>
                    </div>
                </div>
            </div>
            <br>

          

            <!-- Live Metal Rates -->
            <div class="jewel-card p-4 sm:p-5 mt-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-bold" style="color:#800020;font-family:'Poppins',serif;">
                        <i class="fas fa-coins mr-2" style="color:#d68b16;"></i> Live Metal Rates
                    </h3>
                    <div class="flex items-center gap-2">
                        <span id="metalPriceStatus" class="text-xs" style="color:<?php echo !empty($initial_metal_rates['fallback']) ? '#d97706' : '#059669'; ?>;">
                            <?php echo !empty($initial_metal_rates['fallback']) ? '⚠ Market' : '● Live'; ?>
                        </span>
                        <button onclick="fetchMetalPrices()" class="text-xs px-2 py-1 rounded-lg"
                            style="background:rgba(214,139,22,0.12);border:1px solid rgba(214,139,22,0.3);color:#d68b16;cursor:pointer;">&#128260;</button>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div class="rounded-xl p-3 text-center" style="background:rgba(214,139,22,0.08);border:1px solid rgba(214,139,22,0.3);">
                        <div class="text-xs font-semibold" style="color:#d68b16;">Gold 24K</div>
                        <div class="text-sm font-bold mt-1" style="color:#7a4e0a;" id="gold24Price">₹<?php echo number_format($initial_metal_rates['gold24'] ?? 78500); ?></div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="gold24Change">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(214,139,22,0.05);border:1px solid rgba(181,115,14,0.25);">
                        <div class="text-xs font-semibold" style="color:#b5730e;">Gold 22K</div>
                        <div class="text-sm font-bold mt-1" style="color:#7a4e0a;" id="gold22Price">₹<?php echo number_format($initial_metal_rates['gold22'] ?? 72000); ?></div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="gold22Change">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(214,139,22,0.045);border:1px solid rgba(181,115,14,0.22);">
                        <div class="text-xs font-semibold" style="color:#a86809;">Gold 18K</div>
                        <div class="text-sm font-bold mt-1" style="color:#7a4e0a;" id="gold18Price">₹<?php echo number_format($initial_metal_rates['gold18'] ?? 58875); ?></div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="gold18Change">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(214,139,22,0.04);border:1px solid rgba(181,115,14,0.2);">
                        <div class="text-xs font-semibold" style="color:#9a5d09;">Gold 12K</div>
                        <div class="text-sm font-bold mt-1" style="color:#7a4e0a;" id="gold12Price">₹<?php echo number_format($initial_metal_rates['gold12'] ?? 39250); ?></div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="gold12Change">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(214,139,22,0.03);border:1px solid rgba(181,115,14,0.15);">
                        <div class="text-xs font-semibold" style="color:#7f4a04;">Gold 9K</div>
                        <div class="text-sm font-bold mt-1" style="color:#7a4e0a;" id="gold9Price">₹<?php echo number_format($initial_metal_rates['gold9'] ?? 29437); ?></div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="gold9Change">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(192,192,192,0.08);border:1px solid rgba(192,192,192,0.2);">
                        <div class="text-xs font-semibold" style="color:#6b7280;">Silver</div>
                        <div class="text-sm font-bold mt-1" style="color:#374151;" id="silverPrice">₹<?php echo number_format($initial_metal_rates['silver'] ?? 920); ?></div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="silverChange">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(229,228,226,0.05);border:1px solid rgba(229,228,226,0.18);">
                        <div class="text-xs font-semibold" style="color:#6b7280;">Platinum</div>
                        <div class="text-sm font-bold mt-1" style="color:#374151;" id="platinumPrice">₹<?php echo number_format($initial_metal_rates['platinum'] ?? 32000); ?></div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="platinumChange">per 10g</div>
                    </div>
                </div>
                <div class="p-3 rounded-xl" style="background:rgba(214,139,22,0.05);border:1px solid rgba(181,115,14,0.12);">
                    <div class="text-xs font-semibold mb-2" style="color:#b5730e;">&#9878;&#65039; Quick Value Calculator</div>
                    <div class="flex gap-2">
                        <select id="metalSelect" class="jewel-input flex-1 rounded-lg px-2 py-1 text-xs" onchange="calcMetalValue()">
                            <option value="gold24">Gold 24K</option>
                            <option value="gold22">Gold 22K</option>
                            <option value="gold12">Gold 12K</option>
                            <option value="gold9">Gold 9K</option>
                            <option value="silver">Silver</option>
                            <option value="platinum">Platinum</option>
                        </select>
                        <input type="number" id="metalGrams" placeholder="GMS" step="0.001" min="0"
                            class="jewel-input w-20 rounded-lg px-2 py-1 text-xs" oninput="calcMetalValue()">
                    </div>
                    <div class="text-center mt-2 font-bold text-sm" id="metalCalcResult" style="color:#059669;">&#8212;</div>
                </div>
                <p class="text-xs mt-2 text-center" style="color:#9ca3af;" id="metalUpdateInfo">Fetching Indian market rates...</p>
            </div>
         <!-- EMI Calculator -->
            <div class="jewel-card p-4 sm:p-6 mt-6">
                <h3 class="text-lg font-bold mb-4" style="color:#800020;font-family:'Poppins',serif;">
                    <i class="fas fa-calculator mr-2" style="color:#d68b16;"></i> EMI Calculator
                </h3>
                <div class="space-y-3">
                    <div>
                        <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Loan Amount (&#8377;)</label>
                        <input type="number" id="loanAmount" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="Enter amount">
                    </div>
                    <div>
                        <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Interest Rate (%/year)</label>
                        <input type="number" id="interestRate" value="12" class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Tenure (Months)</label>
                        <input type="number" id="tenure" value="6" class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                    </div>
                    <button type="button" onclick="calculateEMI()" class="btn-gold w-full py-2 rounded-lg font-semibold">Calculate EMI</button>
                    <div id="emiResult" class="text-center p-3 rounded-lg hidden"
                        style="background:linear-gradient(135deg,rgba(214,139,22,0.08),rgba(128,0,32,0.05));border:1px solid rgba(214,139,22,0.2);">
                        <p class="text-xs font-semibold" style="color:#7a4e0a;">Monthly EMI:</p>
                        <p class="text-2xl font-bold" style="color:#800020;" id="emiAmount">&#8377;0</p>
                        <p class="text-xs mt-1" style="color:#b5730e;">Total Payment: <span id="totalPayment">&#8377;0</span></p>
                    </div>
                </div>
            </div>
 </div>
    </div><!-- /grid -->

 

    <!-- PDF Upload (after invoice creation) -->
    <?php if(!empty($last_invoice_no)): ?>
    <div class="mt-6 jewel-card p-4 sm:p-6">
        <h3 class="text-lg font-bold mb-4" style="color:#800020;">
            <i class="fas fa-file-pdf mr-2" style="color:#dc2626;"></i> Upload Invoice PDF
        </h3>
        <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-3">
            <input type="hidden" name="invoice_no" value="<?php echo htmlspecialchars($last_invoice_no); ?>">
            <input type="file" name="invoice_pdf" accept="application/pdf" required
                class="jewel-input rounded-lg px-3 py-2 text-sm flex-1">
            <button type="submit" name="upload_pdf" class="btn-gold px-6 py-2 rounded-lg font-semibold">&#128228; Upload PDF</button>
        </form>
    </div>
    <?php endif; ?>

</div><!-- /container -->
    <footer style="background:linear-gradient(0deg,#f5e6c8,#fdf6e3);border-top:2px solid #d68b16;padding:20px;margin-top:40px;text-align:center;">
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Design & Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology & Research Private Limited</a>
        </p>
    </footer>
</div><!-- /page-wrapper -->

<!-- JAVASCRIPT -->
<script>
const ALL_PRODUCTS = <?php echo json_encode($all_products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const itemTypeOptions = <?php echo json_encode($itemTypeOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

// Global rate tracking objects (defined at top to avoid Temporal Dead Zone ReferenceError)
var shopRates = { gold24:0, gold22:0, gold18:0, gold12:0, gold9:0, silver:0, diamond:0 };
var shopDisplayIds = { gold24:'shopGold24Display', gold22:'shopGold22Display', gold18:'shopGold18Display', gold12:'shopGold12Display', gold9:'shopGold9Display', silver:'shopSilverDisplay', diamond:'shopDiamondDisplay' };
var shopInputIds   = { gold24:'shopGold24Input',   gold22:'shopGold22Input',   gold18:'shopGold18Input',   gold12:'shopGold12Input',   gold9:'shopGold9Input',   silver:'shopSilverInput',   diamond:'shopDiamondInput'   };
var metalRates = { gold24:0, gold22:0, gold18:0, gold12:0, gold9:0, silver:0, platinum:0 };

// Global billing state variables (defined at top to prevent TDZ ReferenceError)
var items = [];
var currentMainTab = 'gram';
var currentGramSource = 'stock';
var currentQtySource = 'stock';
var netWeightManual = false;
var currentMcMode = 'pct';
var currentCustomerAdvance = 0;
var currentAdvanceId = 0;
var currentAdvanceReceipt = '';
var advanceAdjustmentMode = 'none';
var appliedAdvanceAmount = 0;
var allAdvanceCustomers = <?php echo json_encode($active_advance_customers); ?>;

const defaultItemTypeOptions = {
    'Gold 22K': ['Necklace','Chur','Bala','Chain','Tops','Single Loket','Double Loket','Churi','Jhuladul','Jhumka','Ladies Ring','Gold Choker','Gents Ring','Gents Breslet','Ladies Breslet','Tika','Takti','Mantasa','Pearl Choker','Bauti Chur','Soket Bauti','Breslet Noya','Stell Noya','Baby Ring','Bali','Pitaring','Baby Breslet','Pearl Sitahar','Nose Pin','Other'],
    'Gold 18K': ['Necklace','Chur','Bala','Chain','Tops','Single Loket','Double Loket','Churi','Jhuladul','Jhumka','Ladies Ring','Gold Choker','Gents Ring','Gents Breslet','Ladies Breslet','Tika','Takti','Mantasa','Pearl Choker','Bauti Chur','Soket Bauti','Breslet Noya','Stell Noya','Baby Ring','Bali','Pitaring','Baby Breslet','Pearl Sitahar','Nose Pin','Other'],
    'Silver':   ['Thali','Bati','Glass','Spoon','Showpiece','B.B.C Silver','Mix Silver','Other'],
    'Stone':    ['Natural Pearl','Gomed','Red Coral','Nila','Panna','Jerkon','Amethist','Cats Eye','Other'],
    'Diamond':  ['Ladies Ring','Gents Ring','Tops','Mangal Sutra','Nose Pin','Necklace','Other'],
};
const mergedItemTypeOptions = {};
Object.keys(defaultItemTypeOptions).forEach(category => {
    const dbOptions = Array.isArray(itemTypeOptions[category]) ? itemTypeOptions[category] : [];
    mergedItemTypeOptions[category] = Array.from(new Set([...dbOptions, ...defaultItemTypeOptions[category]]));
});
Object.keys(itemTypeOptions).forEach(category => {
    if(!mergedItemTypeOptions[category]) {
        mergedItemTypeOptions[category] = itemTypeOptions[category];
    }
});
// Also add any custom item_names from ALL_PRODUCTS that aren't already included
ALL_PRODUCTS.forEach(p => {
    const cat = p.category || '';
    const name = (p.item_name || p.name || '').trim();
    if (!cat || !name) return;
    if (!mergedItemTypeOptions[cat]) mergedItemTypeOptions[cat] = [];
    if (!mergedItemTypeOptions[cat].includes(name)) {
        mergedItemTypeOptions[cat].push(name);
    }
});

// Tab switching
function switchMainTab(tab) {
    currentMainTab = tab;
    document.querySelectorAll('.add-mode-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.add-mode-panel').forEach(p => p.classList.add('hidden'));
    
    if (tab === 'gram') {
        document.getElementById('tabGram').classList.add('active');
        document.getElementById('panelGram').classList.remove('hidden');
    } else {
        document.getElementById('tabQty').classList.add('active');
        document.getElementById('panelQty').classList.remove('hidden');
    }
    resetItemCharges();
}

function switchSource(tab, source) {
    if (tab === 'gram') {
        currentGramSource = source;
        document.getElementById('gramSourceStock').classList.add('hidden');
        document.getElementById('gramSourceCategory').classList.add('hidden');
        document.getElementById('gramSourceManual').classList.add('hidden');
        
        const grossNetDiv = document.getElementById('weightFieldsGrossNet');
        const manualWtDiv = document.getElementById('weightFieldsManual');
        const hmSelect = document.getElementById('itemHallmarkType');
        const custHm = document.getElementById('itemHallmarkCustom');

        if (source === 'stock') {
            document.getElementById('gramSourceStock').classList.remove('hidden');
            if (grossNetDiv) grossNetDiv.classList.remove('hidden');
            if (manualWtDiv) manualWtDiv.classList.add('hidden');
            if (custHm) { custHm.value = ''; custHm.classList.add('hidden'); }
        } else if (source === 'category') {
            document.getElementById('gramSourceCategory').classList.remove('hidden');
            if (grossNetDiv) grossNetDiv.classList.remove('hidden');
            if (manualWtDiv) manualWtDiv.classList.add('hidden');
            if (custHm) { custHm.value = ''; custHm.classList.add('hidden'); }
        } else {
            document.getElementById('gramSourceManual').classList.remove('hidden');
            if (grossNetDiv) grossNetDiv.classList.add('hidden');
            if (manualWtDiv) manualWtDiv.classList.remove('hidden');
            // Manual entry defaults Karat / Hallmark to None
            if (hmSelect) hmSelect.value = 'None';
            if (custHm) { custHm.value = ''; custHm.classList.add('hidden'); }
        }
    } else {
        currentQtySource = source;
        document.getElementById('qtySourceStock').classList.add('hidden');
        document.getElementById('qtySourceCategory').classList.add('hidden');
        document.getElementById('qtySourceManual').classList.add('hidden');
        
        if (source === 'stock') {
            document.getElementById('qtySourceStock').classList.remove('hidden');
        } else if (source === 'category') {
            document.getElementById('qtySourceCategory').classList.remove('hidden');
        } else {
            document.getElementById('qtySourceManual').classList.remove('hidden');
        }
    }
    resetItemCharges();
}

function onHallmarkTypeChange() {
    const sel = document.getElementById('itemHallmarkType');
    const custInput = document.getElementById('itemHallmarkCustom');
    if (!sel || !custInput) return;
    if (sel.value === 'Custom') {
        custInput.classList.remove('hidden');
        custInput.focus();
    } else {
        custInput.classList.add('hidden');
        custInput.value = '';
    }
}

// ====================  GRAM FORM LOGIC ====================

// Stock search & filter with floating suggestions
function filterGramStock(query) {
    const select = document.getElementById('gramStockProduct');
    const infoDiv = document.getElementById('gramStockProductInfo');
    const suggDiv = document.getElementById('gramStockSuggestions');
    if (!select) return;
    query = (query || '').trim().toLowerCase();
    if (infoDiv) infoDiv.classList.add('hidden');
    
    // If search is empty and select already contains pre-rendered options, keep them
    if (query === '' && select.options.length > 1) {
        if (suggDiv) suggDiv.classList.add('hidden');
        return;
    }

    while(select.options.length > 1) select.remove(1);
    
    const filtered = query.length > 0
        ? ALL_PRODUCTS.filter(p =>
            ((p.serial_no || '').toLowerCase().includes(query) ||
             (p.name || '').toLowerCase().includes(query) ||
             (p.item_name || '').toLowerCase().includes(query) ||
             (p.category || '').toLowerCase().includes(query))
          )
        : ALL_PRODUCTS;
        
    filtered.forEach(p => {
        const isOutOfStock = (parseFloat(p.quantity) <= 0);
        const pUnit = (p.unit || '').toLowerCase() === 'pair' ? 'pair' : 'pcs';
        const stockText = isOutOfStock ? 'OUT OF STOCK' : (p.quantity + ' ' + pUnit);
        const catBadge = p.category ? '[' + p.category + '] ' : '';
        const display = catBadge + (p.item_name || p.name) + ' | SN:' + (p.serial_no || '-') + ' | Stock: ' + stockText;
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = display;
        opt.dataset.price = p.price || 0;
        opt.dataset.name = p.name || '';
        opt.dataset.serial = p.serial_no || '';
        opt.dataset.category = p.category || '';
        opt.dataset.itemName = p.item_name || p.name || '';
        opt.dataset.qty = p.quantity || 0;
        opt.dataset.unit = pUnit;
        opt.dataset.weight = p.weight || '';
        opt.dataset.huid = p.huid_code || p.serial_no || '';
        opt.dataset.diamondCent = p.diamond_cent || '';
        opt.dataset.metalKt     = p.metal_kt || '';
        opt.dataset.metalWeight = p.metal_weight || '';
        if (isOutOfStock) opt.style.color = '#dc2626';
        select.appendChild(opt);
    });

    if (suggDiv) {
        suggDiv.innerHTML = '';
        if (query.length > 0 && filtered.length > 0) {
            filtered.slice(0, 8).forEach(p => {
                const isOut = (parseFloat(p.quantity) <= 0);
                const pUnit = (p.unit || '').toLowerCase() === 'pair' ? 'pair' : 'pcs';
                const item = document.createElement('div');
                item.className = 'autocomplete-suggestion-item';
                item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;background:#fff;';
                item.innerHTML = '<div><strong style="color:#022c22;">' + (p.category ? '<span style="color:#b5730e;font-weight:bold;">[' + p.category + ']</span> ' : '') + (p.item_name || p.name) + '</strong></div><div style="font-size:11px;color:' + (isOut?'#dc2626':'#7a4e0a') + ';">SN: ' + (p.serial_no || '-') + ' | ' + (isOut ? 'OUT OF STOCK' : (p.quantity + ' ' + pUnit)) + '</div>';
                item.onmouseover = function() { this.style.background = '#f5ead0'; };
                item.onmouseout = function() { this.style.background = '#fff'; };
                item.onclick = function() {
                    select.value = p.id;
                    document.getElementById('gramStockSearch').value = (p.item_name || p.name);
                    suggDiv.classList.add('hidden');
                    onGramStockChange();
                };
                suggDiv.appendChild(item);
            });
            suggDiv.classList.remove('hidden');
        } else {
            suggDiv.classList.add('hidden');
        }
    }
}

function clearGramStockSearch() {
    document.getElementById('gramStockSearch').value = '';
    const suggDiv = document.getElementById('gramStockSuggestions');
    if (suggDiv) suggDiv.classList.add('hidden');
    filterGramStock('');
}

function onGrossWeightInput() {
    const grossEl = document.getElementById('gramGrossWeight');
    const netEl = document.getElementById('gramNetWeight');
    if (!netWeightManual && grossEl && netEl) {
        netEl.value = grossEl.value;
    }
    autoGramTotal();
}

function onNetWeightInput() {
    netWeightManual = true;
    autoGramTotal();
}

function onGramStockChange() {
    const select = document.getElementById('gramStockProduct');
    const infoDiv = document.getElementById('gramStockProductInfo');
    const rateInput = document.getElementById('gramRate');
    const grossInput = document.getElementById('gramGrossWeight');
    const netInput = document.getElementById('gramNetWeight');
    const nameInput = document.getElementById('gramItemNameInput');
    const hmTypeSelect = document.getElementById('itemHallmarkType');
    const opt = select.options[select.selectedIndex];
    
    if (!opt || !opt.value) {
        infoDiv.classList.add('hidden');
        return;
    }
    
    const price       = parseFloat(opt.dataset.price) || 0;    // DB: total item value
    const qty         = parseFloat(opt.dataset.qty) || 0;      // DB: pieces in stock
    const name        = opt.dataset.itemName || opt.dataset.name || '';
    const category    = opt.dataset.category || '';
    const huid        = opt.dataset.huid || '';
    const serial      = opt.dataset.serial || '';
    const stockWeight = parseFloat(opt.dataset.weight) || 0;

    // Detect if this is generic/basket stock (e.g. "Gold Ornaments", "Silver Ornaments", "Mixed Gold Stock", "Others", numeric, or pooled without individual serial)
    const nameLower = name.toLowerCase().trim();
    const isBasketOrGeneric = nameLower === 'gold ornaments' || 
                              nameLower === 'silver ornaments' || 
                              nameLower === 'mixed gold stock' || 
                              nameLower === 'others' || 
                              nameLower === 'other' || 
                              nameLower === '' || 
                              /^\d+$/.test(nameLower) ||
                              ((!serial || serial === '-') && (!huid || huid === '-') && qty > 1);

    // Auto-fill Item Name: if basket/generic, leave BLANK so user must enter specific ornament name!
    if (nameInput) {
        if (isBasketOrGeneric) {
            nameInput.value = '';
            nameInput.placeholder = 'Enter specific ornament (e.g. Gold Chain, Bala, Payel...) *';
            nameInput.focus();
        } else {
            nameInput.value = name;
        }
    }

    // Auto-fill HUID
    if (document.getElementById('manualHuid')) {
        document.getElementById('manualHuid').value = (huid && huid !== '-') ? huid : '';
    }

    // Auto-fill smart Hallmark Type based on category
    if (hmTypeSelect) {
        const catL = (category || '').toLowerCase();
        const nameL = (name || '').toLowerCase();
        if (catL.includes('22')) hmTypeSelect.value = '22K';
        else if (catL.includes('18')) hmTypeSelect.value = '18K';
        else if (catL.includes('14')) hmTypeSelect.value = '14K';
        else if (catL.includes('24')) hmTypeSelect.value = '24K';
        else if (catL.includes('999') || catL.includes('fine silver')) hmTypeSelect.value = '999 Fine Silver';
        else if (catL.includes('silver') || catL.includes('925')) hmTypeSelect.value = '925 Silver';
        else if (catL.includes('stone') || catL.includes('mala') || catL.includes('other') || nameL.includes('mala') || nameL.includes('stone')) hmTypeSelect.value = 'None';
        else hmTypeSelect.value = '22K';
    }

    // Auto-fill Gross & Net weight:
    // For basket/generic products where weight in stock is bulk total weight, leave Gross & Net weight BLANK!
    // Only prefill weight if the product is an individual item with predefined single piece weight.
    netWeightManual = false;
    const isSinglePieceWithWeight = (!isBasketOrGeneric && (qty === 1 || (serial !== '' && serial !== '-')) && stockWeight > 0);

    if (grossInput) {
        grossInput.value = isSinglePieceWithWeight ? stockWeight : '';
        grossInput.placeholder = 'Gross wt in grams (Optional for Stone/Mala/Pcs)';
    }
    if (netInput) {
        netInput.value = isSinglePieceWithWeight ? stockWeight : '';
        netInput.placeholder = 'Net wt in grams (Optional for Stone/Mala/Pcs)';
    }

    // Automatically detect and select Pcs vs Pair strictly from product stock
    const unitSelect = document.getElementById('gramUnit');
    if (unitSelect) {
        const stockUnit = (opt.dataset.unit || '').toLowerCase().trim();
        if (stockUnit === 'pair' || stockUnit === 'pairs') {
            unitSelect.value = 'pair';
        } else if (stockUnit === 'pcs' || stockUnit === 'pc' || stockUnit === 'piece') {
            unitSelect.value = 'pcs';
        } else {
            const isPair = nameLower.includes('pair') || 
                           nameLower.includes('jhumka') || 
                           nameLower.includes('tops') || 
                           nameLower.includes('jhuladul') || 
                           nameLower.includes('dul') || 
                           nameLower.includes('bali') || 
                           nameLower.includes('noya') || 
                           nameLower.includes('bauti') || 
                           nameLower.includes('earring');
            unitSelect.value = isPair ? 'pair' : 'pcs';
        }
    }

    const dCent = opt.dataset.diamondCent || '';
    const mKt   = opt.dataset.metalKt || '';
    const mWt   = opt.dataset.metalWeight || '';
    const catLower = (category || '').toLowerCase();
    const dCentVal = parseFloat(dCent) || 0;
    const goldWeightVal = parseFloat(mWt) || parseFloat(stockWeight) || 0;

    // Breakdown panel opens ONLY when selected item contains Gold + Diamond (diamondCent > 0 OR diamond category with gold details)
    const isGoldAndDiamond = (dCentVal > 0 || (catLower.includes('diamond') && (goldWeightVal > 0 || mKt !== '')));

    const dBreakdown = document.getElementById('posDiamondBreakdownContainer');
    if (dBreakdown) {
        if (isGoldAndDiamond) {
            dBreakdown.classList.remove('hidden');
            const dRateVal = shopRates.diamond || getShopRateForCategory('diamond') || (parseFloat(localStorage.getItem('shopRate_diamond')) || 0);
            if (document.getElementById('posDiamondCent')) document.getElementById('posDiamondCent').value = dCent;
            if (document.getElementById('posDiamondRate')) document.getElementById('posDiamondRate').value = dRateVal > 0 ? dRateVal : '';
            if (document.getElementById('posGoldKt')) document.getElementById('posGoldKt').value = mKt || '18K';
            if (document.getElementById('posGoldWeight')) document.getElementById('posGoldWeight').value = mWt || stockWeight || '';
            const goldKtVal = mKt || '18K';
            const goldRate10g = getShopRateForCategory(goldKtVal) * 10;
            if (document.getElementById('posGoldRate')) document.getElementById('posGoldRate').value = goldRate10g || '';
            calcDiamondBreakdown();
        } else {
            dBreakdown.classList.add('hidden');
            if (document.getElementById('posDiamondCent')) document.getElementById('posDiamondCent').value = '';
            if (document.getElementById('posDiamondPrice')) document.getElementById('posDiamondPrice').value = '';
            if (document.getElementById('posGoldWeight')) document.getElementById('posGoldWeight').value = '';
            if (document.getElementById('posGoldPrice')) document.getElementById('posGoldPrice').value = '';
        }
    }

    // Use shop rate (per 10g) for this category - the correct billing rate
    const shopRatePerGram = getShopRateForCategory(category); // returns per-gram from shop rates
    const shopRate10g     = shopRatePerGram * 10;             // convert back to per-10g for the field
    const wtInfo          = stockWeight > 0 ? ' | Available Weight: <strong style="color:#059669;">' + stockWeight.toFixed(3) + ' g</strong>' : '';

    if (qty <= 0) {
        rateInput.value = '';
        infoDiv.innerHTML = '<strong style="color:#dc2626;">' + name + '</strong>' + wtInfo + ' | <strong style="color:#dc2626;"> OUT OF STOCK (0 pcs available)</strong>';
    } else if (isGoldAndDiamond) {
        infoDiv.innerHTML = '<strong>' + name + '</strong> | 💎 Diamond & Gold Dual Rate Active | Available Qty: <strong style="color:#059669;">' + qty + ' pcs</strong>' + wtInfo;
    } else if (shopRate10g > 0) {
        rateInput.value = shopRate10g.toFixed(0);
        const hint = document.getElementById('gramRatePerGramHint');
        if(hint) hint.textContent = '≈ ₹' + shopRatePerGram.toLocaleString('en-IN', {maximumFractionDigits:2}) + ' per gram (shop rate used in billing)';
        infoDiv.innerHTML = '<strong>' + name + '</strong> | Shop Rate: ₹' + shopRatePerGram.toLocaleString('en-IN', {maximumFractionDigits:2}) + '/g | Available Qty: <strong style="color:#059669;">' + qty + ' pcs</strong>' + wtInfo;
    } else if (price > 0) {
        // Direct item price (e.g. Stone, Mala, Mukut, Box, or fixed-price items)
        rateInput.value = price.toFixed(0);
        const hint = document.getElementById('gramRatePerGramHint');
        if(hint) hint.textContent = '≈ ₹' + price.toLocaleString('en-IN', {maximumFractionDigits:2}) + ' per piece';
        infoDiv.innerHTML = '<strong>' + name + '</strong> | Price: ₹' + price.toLocaleString('en-IN', {maximumFractionDigits:2}) + ' / pc | Available Qty: <strong style="color:#059669;">' + qty + ' pcs</strong>' + wtInfo;
    } else {
        rateInput.value = '';
        const hint = document.getElementById('gramRatePerGramHint');
        if(hint) {
            hint.textContent = 'Enter rate / price per piece or weight';
            hint.style.color = '#7a4e0a';
        }
        infoDiv.innerHTML = '<strong>' + name + '</strong> | Available Qty: <strong style="color:#059669;">' + qty + ' pcs</strong>' + wtInfo;
    }

    infoDiv.classList.remove('hidden');
    autoGramTotal();
}

function onPosGoldKtChange() {
    const kt = document.getElementById('posGoldKt').value;
    const goldRate10g = getShopRateForCategory(kt) * 10;
    if (document.getElementById('posGoldRate')) {
        document.getElementById('posGoldRate').value = goldRate10g || '';
    }
    calcDiamondBreakdown();
}

function calcDiamondBreakdown(manualType) {
    const cent = parseFloat(document.getElementById('posDiamondCent').value) || 0;
    const dRate = parseFloat(document.getElementById('posDiamondRate').value) || 0;
    if (document.getElementById('posDiamondRateSpan')) {
        document.getElementById('posDiamondRateSpan').textContent = dRate.toLocaleString('en-IN');
    }

    let dPrice = 0;
    if (manualType === 'diamond') {
        dPrice = parseFloat(document.getElementById('posDiamondPrice').value) || 0;
    } else {
        dPrice = parseFloat((cent * dRate).toFixed(2));
        if (document.getElementById('posDiamondPrice')) {
            document.getElementById('posDiamondPrice').value = dPrice > 0 ? dPrice.toFixed(2) : '';
        }
    }

    const gWeight = parseFloat(document.getElementById('posGoldWeight').value) || 0;
    const gRate10g = parseFloat(document.getElementById('posGoldRate').value) || 0;
    const gRatePerGram = gRate10g / 10;
    if (document.getElementById('posGoldRateSpan')) {
        document.getElementById('posGoldRateSpan').textContent = gRate10g.toLocaleString('en-IN');
    }

    let gPrice = 0;
    if (manualType === 'gold') {
        gPrice = parseFloat(document.getElementById('posGoldPrice').value) || 0;
    } else {
        gPrice = parseFloat((gWeight * gRatePerGram).toFixed(2));
        if (document.getElementById('posGoldPrice')) {
            document.getElementById('posGoldPrice').value = gPrice > 0 ? gPrice.toFixed(2) : '';
        }
    }

    const totalBase = parseFloat((dPrice + gPrice).toFixed(2));
    const rateInput = document.getElementById('gramRate');
    if (rateInput && totalBase > 0) {
        rateInput.value = totalBase.toFixed(2);
    }

    if (document.getElementById('breakdownDiamondSummary')) {
        document.getElementById('breakdownDiamondSummary').textContent = '₹' + dPrice.toLocaleString('en-IN');
    }
    if (document.getElementById('breakdownGoldSummary')) {
        document.getElementById('breakdownGoldSummary').textContent = '₹' + gPrice.toLocaleString('en-IN');
    }
    if (document.getElementById('breakdownTotalSummary')) {
        document.getElementById('breakdownTotalSummary').textContent = '₹' + totalBase.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    autoGramTotal();
}

function onManualDiamondPriceChange() {
    calcDiamondBreakdown('diamond');
}

function onManualGoldPriceChange() {
    calcDiamondBreakdown('gold');
}

function toggleDiamondBreakdownPanel() {
    const dBreakdown = document.getElementById('posDiamondBreakdownContainer');
    if (dBreakdown) {
        dBreakdown.classList.toggle('hidden');
        if (!dBreakdown.classList.contains('hidden')) {
            calcDiamondBreakdown();
        }
    }
}

function updateGramItemTypes() {
    const category = document.getElementById('gramCatSelect').value;
    const itemTypeSelect = document.getElementById('gramItemType');
    itemTypeSelect.innerHTML = '<option value="">-- Select Item Type --</option>';
    
    // Auto-update Hallmark Type based on category
    const hmTypeSelect = document.getElementById('itemHallmarkType');
    if (hmTypeSelect && category) {
        const catL = category.toLowerCase();
        if (catL.includes('22')) hmTypeSelect.value = '22K';
        else if (catL.includes('18')) hmTypeSelect.value = '18K';
        else if (catL.includes('14')) hmTypeSelect.value = '14K';
        else if (catL.includes('24')) hmTypeSelect.value = '24K';
        else if (catL.includes('999') || catL.includes('fine silver')) hmTypeSelect.value = '999 Fine Silver';
        else if (catL.includes('silver') || catL.includes('925')) hmTypeSelect.value = '925 Silver';
        else if (catL.includes('stone') || catL.includes('mala') || catL.includes('other') || catL.includes('diamond')) hmTypeSelect.value = 'None';
        else hmTypeSelect.value = '22K';
    }

    const dBreakdown = document.getElementById('posDiamondBreakdownContainer');
    if (dBreakdown) {
        if (category === 'Diamond') {
            dBreakdown.classList.remove('hidden');
            const dRateVal = shopRates.diamond || getShopRateForCategory('diamond') || (parseFloat(localStorage.getItem('shopRate_diamond')) || 0);
            if (document.getElementById('posDiamondRate')) document.getElementById('posDiamondRate').value = dRateVal > 0 ? dRateVal : '';
            if (document.getElementById('posGoldWeight')) document.getElementById('posGoldWeight').value = '';
            const goldRate10g = getShopRateForCategory('18K') * 10;
            if (document.getElementById('posGoldRate')) document.getElementById('posGoldRate').value = goldRate10g || '';
            calcDiamondBreakdown();
        } else {
            dBreakdown.classList.add('hidden');
        }
    }

    if (!category) {
        onGramItemTypeChange();
        return;
    }
    
    const options = mergedItemTypeOptions[category] || [];
    options.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item;
        opt.textContent = item;
        itemTypeSelect.appendChild(opt);
    });
    
    // Auto-fill shop rate for selected category
    const shopRatePerGram = getShopRateForCategory(category);
    const shopRate10g = shopRatePerGram * 10;
    const rateInput = document.getElementById('gramRate');
    if (shopRate10g > 0) {
        rateInput.value = shopRate10g.toFixed(0);
        const hint = document.getElementById('gramRatePerGramHint');
        if(hint) {
            hint.textContent = '≈ ₹' + shopRatePerGram.toLocaleString('en-IN', {maximumFractionDigits:2}) + ' per gram (shop rate)';
            hint.style.color = '#059669';
        }
    } else {
        rateInput.value = '';
        const hint = document.getElementById('gramRatePerGramHint');
        if(hint) {
            if (category === 'Stone' || category === 'Others') {
                hint.textContent = 'Enter rate / price per piece or weight';
                hint.style.color = '#7a4e0a';
            } else {
                hint.textContent = '⚠ Set shop rate for ' + category + ' in the panel on the right first!';
                hint.style.color = '#dc2626';
            }
        }
    }
    autoGramTotal();
    onGramItemTypeChange();
}

function onGramItemTypeChange() {
    const itemTypeSelect = document.getElementById('gramItemType');
    const statusDiv = document.getElementById('gramCatStockStatus');
    const opt = itemTypeSelect ? itemTypeSelect.options[itemTypeSelect.selectedIndex] : null;

    if (opt && opt.value) {
        const unitSelect = document.getElementById('gramUnit');
        if (unitSelect) {
            const valLower = opt.value.toLowerCase();
            const isPair = valLower.includes('pair') || 
                           valLower.includes('jhumka') || 
                           valLower.includes('tops') || 
                           valLower.includes('jhuladul') || 
                           valLower.includes('dul') || 
                           valLower.includes('bali') || 
                           valLower.includes('noya') || 
                           valLower.includes('bauti') || 
                           valLower.includes('earring');
            unitSelect.value = isPair ? 'pair' : 'pcs';
        }
        if (document.getElementById('gramItemNameInput')) {
            document.getElementById('gramItemNameInput').value = opt.value;
        }
    }

    if (!statusDiv) return;

    if (!opt || !opt.value) {
        statusDiv.classList.add('hidden');
        return;
    }

    const inStock = (opt.dataset.inStock === 'true');
    const stockQty = parseFloat(opt.dataset.stockQty) || 0;
    const stockWeight = parseFloat(opt.dataset.stockWeight) || 0;
    const isOutOfStock = (opt.dataset.isOutOfStock === 'true');

    if (isOutOfStock) {
        statusDiv.className = 'mt-1 text-xs font-bold text-red-600 flex items-center gap-1';
        statusDiv.innerHTML = '<i class="fas fa-times-circle"></i> Out of Stock in Inventory (0 pcs available)';
        statusDiv.classList.remove('hidden');
    } else if (inStock) {
        const wtInfo = stockWeight > 0 ? ' (' + stockWeight.toFixed(3) + 'g)' : '';
        statusDiv.className = 'mt-1 text-xs font-semibold text-emerald-700 flex items-center gap-1';
        statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> In Stock: <strong>' + stockQty + ' pcs' + wtInfo + '</strong>';
        statusDiv.classList.remove('hidden');
    } else {
        statusDiv.className = 'mt-1 text-xs font-medium text-amber-700 flex items-center gap-1';
        statusDiv.innerHTML = '<i class="fas fa-info-circle"></i> Not in stock database \u2014 will be billed as general item';
        statusDiv.classList.remove('hidden');
    }
}

function setMcMode(mode) {
    currentMcMode = mode;
    const btnPct = document.getElementById('btnMcPct');
    const btnDirect = document.getElementById('btnMcDirect');
    const input = document.getElementById('itemMakingCharge');

    if (mode === 'direct') {
        if (btnPct) {
            btnPct.className = 'mc-tab-btn px-2 py-0.5 text-[11px] font-bold rounded transition-all text-amber-900 hover:bg-white/50';
        }
        if (btnDirect) {
            btnDirect.className = 'mc-tab-btn active px-2 py-0.5 text-[11px] font-bold rounded transition-all bg-emerald-600 text-white shadow-sm';
        }
        if (input) { input.step = '1'; input.placeholder = 'e.g. ₹500'; }
    } else {
        if (btnPct) {
            btnPct.className = 'mc-tab-btn active px-2 py-0.5 text-[11px] font-bold rounded transition-all bg-amber-700 text-white shadow-sm';
        }
        if (btnDirect) {
            btnDirect.className = 'mc-tab-btn px-2 py-0.5 text-[11px] font-bold rounded transition-all text-amber-900 hover:bg-white/50';
        }
        if (input) { input.step = '0.1'; input.placeholder = 'e.g. 12%'; }
    }
    updateMakingChargeHint();
}

function updateMakingChargeHint() {
    const hintEl = document.getElementById('itemMakingChargeHint');
    if(!hintEl) return;
    const inputVal = parseFloat(document.getElementById('itemMakingCharge').value) || 0;
    
    let baseAmount = 0;
    if (typeof currentMainTab === 'undefined' || currentMainTab === 'gram') {
        const checkedRadio = document.querySelector('input[name="gram_source"]:checked');
        const source = checkedRadio ? checkedRadio.value : (typeof currentGramSource !== 'undefined' ? currentGramSource : 'stock');
        let weight = parseFloat(document.getElementById('gramGrossWeight')?.value) || 0;
        const rate10g = parseFloat(document.getElementById('gramRate')?.value) || 0;
        const qty = parseFloat(document.getElementById('gramQty')?.value) || 1;
        const dContainer = document.getElementById('posDiamondBreakdownContainer');
        const isDiamondBreakdown = dContainer && !dContainer.classList.contains('hidden');

        if (isDiamondBreakdown) {
            baseAmount = qty * rate10g;
        } else if (weight > 0) {
            baseAmount = weight * (rate10g / 10);
        } else {
            baseAmount = qty * rate10g;
        }
    } else {
        const checkedRadio = document.querySelector('input[name="qty_source"]:checked');
        const source = checkedRadio ? checkedRadio.value : (typeof currentQtySource !== 'undefined' ? currentQtySource : 'stock');
        let qty = 0;
        if (source === 'stock') qty = parseFloat(document.getElementById('qtyCount')?.value) || 0;
        else if (source === 'category') qty = parseFloat(document.getElementById('qtyCountCat')?.value) || 0;
        else qty = parseFloat(document.getElementById('qtyCountManual')?.value) || 0;
        const rate = parseFloat(document.getElementById('qtyRate')?.value) || 0;
        baseAmount = qty * rate;
    }
    
    if (inputVal > 0) {
        if (currentMcMode === 'pct') {
            const mcAmt = baseAmount > 0 ? baseAmount * (inputVal / 100) : 0;
            hintEl.textContent = '= ₹' + mcAmt.toFixed(2);
            hintEl.style.display = 'block';
        } else {
            const mcPct = baseAmount > 0 ? (inputVal / baseAmount) * 100 : 0;
            hintEl.textContent = baseAmount > 0 ? '= ' + mcPct.toFixed(2) + '%' : 'Direct ₹' + inputVal.toFixed(2);
            hintEl.style.display = 'block';
        }
    } else {
        hintEl.textContent = '';
        hintEl.style.display = 'none';
    }
}

function autoGramTotal() {
    const checkedRadio = document.querySelector('input[name="gram_source"]:checked');
    const source = checkedRadio ? checkedRadio.value : (typeof currentGramSource !== 'undefined' ? currentGramSource : 'stock');
    currentGramSource = source;

    let grossWeight = 0;
    let netWeight = 0;

    if (source === 'manual') {
        const manualWt = parseFloat(document.getElementById('gramManualWeight')?.value) || 0;
        grossWeight = manualWt;
        netWeight = manualWt;
    } else {
        grossWeight = parseFloat(document.getElementById('gramGrossWeight')?.value) || 0;
        netWeight = parseFloat(document.getElementById('gramNetWeight')?.value) || 0;
        // If net weight not specified but gross weight entered, net weight = gross weight
        if (netWeight <= 0 && grossWeight > 0) {
            netWeight = grossWeight;
        }
    }
    
    // Pricing calculation uses NET WEIGHT (Net Weight x Rate per gram)
    let calcWeight = netWeight;
    
    const rate10g = parseFloat(document.getElementById('gramRate')?.value) || 0;
    const qty = parseFloat(document.getElementById('gramQty')?.value) || 1;
    
    const unitEl = document.getElementById('gramUnit');
    const isPair = unitEl && unitEl.value === 'pair';
    const unitLabel = isPair ? 'pair' : 'pcs';
    const pcsMultiplier = isPair ? (qty * 2) : (qty > 0 ? qty : 1);
    
    let total = 0;
    const hint = document.getElementById('gramRatePerGramHint');
    
    const dContainer = document.getElementById('posDiamondBreakdownContainer');
    const isDiamondBreakdown = dContainer && !dContainer.classList.contains('hidden');

    if (isDiamondBreakdown) {
        total = parseFloat((pcsMultiplier * rate10g).toFixed(2));
        if(hint && rate10g > 0) {
            hint.textContent = '≈ ₹' + rate10g.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}) + (isPair ? ' × ' + qty + ' Pair (' + pcsMultiplier + ' pcs)' : ' × ' + qty + ' pcs');
        } else if(hint) {
            hint.textContent = '';
        }
    } else if (calcWeight > 0) {
        const ratePerGram = rate10g / 10;
        total = parseFloat((calcWeight * ratePerGram).toFixed(2));
        if(hint && rate10g > 0) {
            if (source === 'manual') {
                let pcsNotice = isPair ? ' [' + qty + ' Pair (' + pcsMultiplier + ' pcs)]' : (qty > 1 ? ' [' + qty + ' pcs]' : '');
                hint.textContent = '≈ ₹' + ratePerGram.toLocaleString('en-IN', {maximumFractionDigits:2}) + '/g × ' + calcWeight.toFixed(3) + 'g' + pcsNotice;
            } else {
                let grossNotice = (grossWeight > 0 && grossWeight !== calcWeight) ? ' (Gross: ' + grossWeight.toFixed(3) + 'g)' : '';
                let pcsNotice = isPair ? ' [' + qty + ' Pair (' + pcsMultiplier + ' pcs)]' : (qty > 1 ? ' [' + qty + ' pcs]' : '');
                hint.textContent = '≈ ₹' + ratePerGram.toLocaleString('en-IN', {maximumFractionDigits:2}) + '/g × ' + calcWeight.toFixed(3) + 'g Net' + grossNotice + pcsNotice;
            }
        } else if(hint) {
            hint.textContent = '';
        }
    } else if (qty > 0 && rate10g > 0) {
        total = parseFloat((pcsMultiplier * rate10g).toFixed(2));
        if(hint) hint.textContent = '≈ ₹' + rate10g.toLocaleString('en-IN', {maximumFractionDigits:2}) + (isPair ? ' × ' + qty + ' Pair (' + pcsMultiplier + ' pcs)' : ' × ' + qty + ' pcs');
    } else if(hint) {
        hint.textContent = '';
    }
    
    const previewRow = document.getElementById('gramTotalPreviewRow');
    const previewEl = document.getElementById('gramTotalPreview');
    
    if (total > 0) {
        previewEl.textContent = '₹' + total.toLocaleString('en-IN', {minimumFractionDigits: 2});
        previewRow.style.display = '';
    } else {
        previewRow.style.display = 'none';
    }
    updateMakingChargeHint();
}

function submitCurrentItem() {
    try {
        if (typeof currentMainTab !== 'undefined' && currentMainTab === 'qty') {
            submitQtyItem();
        } else {
            submitGramItem();
        }
    } catch(e) {
        alert('⚠️ Error adding item to bill:\n' + (e.message || e) + '\n\n' + (e.stack || ''));
        console.error(e);
    }
}

function submitGramItem() {
    try {
        const checkedRadio = document.querySelector('input[name="gram_source"]:checked');
        const source = checkedRadio ? checkedRadio.value : (typeof currentGramSource !== 'undefined' ? currentGramSource : 'stock');
        currentGramSource = source;

        let productId = 'other';
        let name = '';
        let itemType = '';
        let hsn = '';
        let grossWeight = 0;
        let netWeight = 0;

        if (source === 'manual') {
            const manualWt = parseFloat(document.getElementById('gramManualWeight')?.value) || 0;
            grossWeight = manualWt;
            netWeight = manualWt;
        } else {
            grossWeight = parseFloat(document.getElementById('gramGrossWeight')?.value) || 0;
            netWeight = parseFloat(document.getElementById('gramNetWeight')?.value) || 0;
            if (netWeight <= 0 && grossWeight > 0) netWeight = grossWeight;
            if (grossWeight <= 0 && netWeight > 0) grossWeight = netWeight;
        }

        let rate10g = parseFloat(document.getElementById('gramRate')?.value) || 0;
        const qty = parseFloat(document.getElementById('gramQty')?.value) || 1;
        
        const customNameInput = document.getElementById('gramItemNameInput');
        const customTypedName = customNameInput ? customNameInput.value.trim() : '';

        if (source === 'stock') {
            const select = document.getElementById('gramStockProduct');
            const opt = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
            if (!opt || !opt.value) { alert('Please select a product from stock.'); return; }
            productId = opt.value;
            name = customTypedName || opt.dataset.itemName || opt.dataset.name || opt.text;
            hsn = opt.dataset.hsn || '7113';

            const pCat = opt.dataset.category || '';
            const isNonMetalStock = pCat.toLowerCase().includes('stone') || pCat.toLowerCase().includes('mala') || pCat.toLowerCase().includes('other');
            if (!isNonMetalStock && grossWeight <= 0 && netWeight <= 0) {
                alert('⚠️ Please enter Gross Weight and Net Weight for this stock item.');
                if (document.getElementById('gramGrossWeight')) document.getElementById('gramGrossWeight').focus();
                return;
            }

            // Auto-fill rate from product price or shop rates if left blank
            if (rate10g <= 0) {
                const pPrice = parseFloat(opt.dataset.price) || 0;
                const shopR = getShopRateForCategory(pCat) * 10;
                rate10g = pPrice > 0 ? pPrice : (shopR > 0 ? shopR : 0);
                if (rate10g > 0 && document.getElementById('gramRate')) {
                    document.getElementById('gramRate').value = rate10g.toFixed(0);
                }
            }
        } else if (source === 'category') {
            const cat = document.getElementById('gramCatSelect')?.value;
            const typeSelect = document.getElementById('gramItemType');
            const type = typeSelect ? typeSelect.value : '';
            if (!cat) { alert('Please select a category.'); return; }
            if (!type && !customTypedName) { alert('Please select or enter an item type.'); return; }

            const isNonMetalCat = cat.toLowerCase().includes('stone') || cat.toLowerCase().includes('other');
            if (!isNonMetalCat && grossWeight <= 0 && netWeight <= 0) {
                alert('⚠️ Please enter Gross Weight and Net Weight for this item.');
                if (document.getElementById('gramGrossWeight')) document.getElementById('gramGrossWeight').focus();
                return;
            }

            productId = 'other';
            name = customTypedName || type;
            itemType = type;
            hsn = '7113';

            if (rate10g <= 0) {
                const shopR = getShopRateForCategory(cat) * 10;
                if (shopR > 0) {
                    rate10g = shopR;
                    if (document.getElementById('gramRate')) document.getElementById('gramRate').value = rate10g.toFixed(0);
                }
            }
        } else {
            const nameEl = document.getElementById('gramManualName');
            const hsnEl = document.getElementById('gramManualHsn');
            name = (nameEl ? nameEl.value.trim() : '') || customTypedName;
            hsn = hsnEl ? hsnEl.value.trim() : '';
            if (!name) { alert('Please enter an item description.'); return; }
        }
        
        if (!name || name.trim() === '' || name.toLowerCase() === 'others' || name.toLowerCase() === 'gold ornaments' || name.toLowerCase() === 'silver ornaments' || name.toLowerCase() === 'mixed gold stock' || /^\d+$/.test(name.trim())) {
            alert('⚠️ Please enter the specific ornament / item name (e.g. Gold Chain, Gold Bala, Payel, Ring, etc.)');
            if (customNameInput) customNameInput.focus();
            return;
        }
        
        if (rate10g <= 0) { alert('Please enter a rate / price for the item.'); return; }
        if (qty <= 0) { alert('Please enter quantity.'); return; }
        
        let baseAmount = 0;
        let unit = 'g';
        let itemPrice = 0;
        
        const unitEl = document.getElementById('gramUnit');
        const isPair = unitEl && unitEl.value === 'pair';
        const pcsMultiplier = isPair ? (qty * 2) : (qty > 0 ? qty : 1);
        
        const dContainer = document.getElementById('posDiamondBreakdownContainer');
        const isDiamondBreakdown = dContainer && !dContainer.classList.contains('hidden');

        if (isDiamondBreakdown) {
            baseAmount = parseFloat((pcsMultiplier * rate10g).toFixed(2));
            unit = netWeight > 0 ? 'g' : (isPair ? 'pair' : 'pcs');
            itemPrice = rate10g;
        } else if (netWeight > 0) {
            // Price calculation strictly on Net Weight * Rate per gram (Weight is total scale weight)
            let ratePerGram = rate10g / 10;
            baseAmount = parseFloat((netWeight * ratePerGram).toFixed(2));
            unit = isPair ? 'pair' : (grossWeight > 0 ? 'g' : 'pcs');
            itemPrice = ratePerGram;
        } else {
            baseAmount = parseFloat((pcsMultiplier * rate10g).toFixed(2));
            unit = isPair ? 'pair' : 'pcs';
            itemPrice = rate10g;
        }
        
        const mcInputVal = parseFloat(document.getElementById('itemMakingCharge')?.value) || 0;
        let imcPct = 0;
        let imcAmt = 0;

        if (typeof currentMcMode !== 'undefined' && currentMcMode === 'pct') {
            imcPct = mcInputVal;
            imcAmt = parseFloat((baseAmount * (mcInputVal / 100)).toFixed(2));
        } else {
            imcAmt = mcInputVal;
            imcPct = baseAmount > 0 ? parseFloat(((mcInputVal / baseAmount) * 100).toFixed(2)) : 0;
        }

        let hmType = document.getElementById('itemHallmarkType')?.value || (source === 'manual' ? 'None' : '22K');
        if (hmType === 'Custom') {
            const custHm = document.getElementById('itemHallmarkCustom')?.value.trim();
            hmType = custHm || 'None';
        }
        const ihm = parseFloat(document.getElementById('itemHallmark')?.value) || 0;
        const idisc = parseFloat(document.getElementById('itemDiscount')?.value) || 0;
        const igst = document.getElementById('itemGstType')?.value || 'non_gst';
        if (igst === 'non_gst') {
            hsn = '';
        }
        const itemTotal = parseFloat((baseAmount + imcAmt + ihm - idisc).toFixed(2));
        
        const inputHuid = document.getElementById('manualHuid') ? document.getElementById('manualHuid').value.trim() : '';

        let dCent = 0;
        let dRate = 0;
        let gWt = 0;
        let gKt = '';
        let gR10g = 0;

        if (dContainer && !dContainer.classList.contains('hidden')) {
            dCent = parseFloat(document.getElementById('posDiamondCent')?.value) || 0;
            dRate = parseFloat(document.getElementById('posDiamondRate')?.value) || 0;
            gWt = parseFloat(document.getElementById('posGoldWeight')?.value) || 0;
            gKt = document.getElementById('posGoldKt')?.value || '18K';
            gR10g = parseFloat(document.getElementById('posGoldRate')?.value) || 0;
        }

        if (dCent > 0 && dRate <= 0) {
            dRate = shopRates.diamond || getShopRateForCategory('diamond') || (parseFloat(localStorage.getItem('shopRate_diamond')) || 0);
        }
        if (gWt > 0 && gR10g <= 0) {
            gR10g = (getShopRateForCategory(gKt || '18K') * 10) || 0;
        }

        let finalName = name;
        if (gWt > 0 || dCent > 0) {
            let dualDetails = [];
            if (gWt > 0) {
                dualDetails.push(gKt + ' Gold: ' + gWt.toFixed(3) + 'g');
            }
            if (dCent > 0) {
                dualDetails.push('Diamond: ' + dCent.toFixed(2) + ' Ct');
            }
            if (dualDetails.length > 0) {
                finalName += ' [' + dualDetails.join(' | ') + ']';
            }
        }
        if (isPair && finalName.toLowerCase().indexOf('pair') === -1) {
            finalName += ' (Pair)';
        }

        let stockSerial = '';
        let stockHuid = '';
        if (source === 'stock') {
            const sel = document.getElementById('gramStockProduct');
            const opt = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
            stockSerial = opt ? (opt.dataset.serial || '') : '';
            stockHuid = opt ? (opt.dataset.huid || '') : '';
        }

        const pcsToDeduct = pcsMultiplier;

        items.push({
            product_id: productId,
            name: finalName,
            item_type: itemType,
            hsn: hsn,
            quantity: isPair ? qty : (grossWeight > 0 ? grossWeight : qty),
            unit: isPair ? 'pair' : (grossWeight > 0 ? 'g' : 'pcs'),
            pcs: pcsToDeduct,
            gross_wt: grossWeight,
            net_wt: netWeight,
            unit_net_wt: netWeight,
            unit_gross_wt: grossWeight,
            stock_deduct: pcsToDeduct,
            hallmark_type: hmType,
            price: itemPrice,
            base_amount: baseAmount,
            mc_mode: currentMcMode,
            making_charge_pct: imcPct,
            making_charge: imcAmt,
            hallmark: ihm,
            discount: idisc,
            total: itemTotal,
            gst_type: igst,
            is_manual: (source === 'manual'),
            is_item_only: (source === 'category'),
            serial_no: stockSerial || inputHuid,
            huid_code: inputHuid || stockHuid || stockSerial,
            diamond_cent: dCent,
            diamond_rate: dRate,
            gold_weight: gWt > 0 ? gWt : (netWeight > 0 ? netWeight : 0),
            gold_kt: gKt,
            gold_rate_10g: gR10g
        });
        
        updateItemsList();
        calculateTotal();
        resetItemCharges();
        
        // Reset fields safely
        if (document.getElementById('gramGrossWeight')) document.getElementById('gramGrossWeight').value = '';
        if (document.getElementById('gramNetWeight')) document.getElementById('gramNetWeight').value = '';
        if (document.getElementById('gramManualWeight')) document.getElementById('gramManualWeight').value = '';
        if (document.getElementById('gramManualName')) document.getElementById('gramManualName').value = '';
        if (document.getElementById('gramManualHsn')) document.getElementById('gramManualHsn').value = '';
        if (document.getElementById('gramItemNameInput')) document.getElementById('gramItemNameInput').value = '';
        if (document.getElementById('gramRate')) document.getElementById('gramRate').value = '';
        if (document.getElementById('manualHuid')) document.getElementById('manualHuid').value = '';
        if (document.getElementById('gramQty')) document.getElementById('gramQty').value = '1';
        netWeightManual = false;
        
        if (source === 'stock') {
            if (document.getElementById('gramStockProduct')) document.getElementById('gramStockProduct').value = '';
            if (document.getElementById('gramStockProductInfo')) document.getElementById('gramStockProductInfo').classList.add('hidden');
            if (document.getElementById('gramUnit')) document.getElementById('gramUnit').value = 'pcs';
            if (typeof filterGramStock === 'function') filterGramStock('');
        } else if (source === 'category') {
            if (document.getElementById('gramCatSelect')) document.getElementById('gramCatSelect').value = '';
            if (document.getElementById('gramItemType')) document.getElementById('gramItemType').value = '';
            if (document.getElementById('gramCatLiveRateBadge')) document.getElementById('gramCatLiveRateBadge').classList.add('hidden');
        } else if (source === 'manual') {
            if (document.getElementById('gramManualName')) document.getElementById('gramManualName').value = '';
        }
        if (document.getElementById('gramTotalPreviewRow')) document.getElementById('gramTotalPreviewRow').style.display = 'none';
        showNotif(' Added Item: ' + name, 'success');
    } catch(e) {
        alert('⚠️ Error in submitGramItem:\n' + (e.message || e) + '\n\nStack:\n' + (e.stack || ''));
        console.error(e);
    }
}

// ====================  QTY FORM LOGIC ====================

// Stock search & filter with floating suggestions
function filterQtyStock(query) {
    const select = document.getElementById('qtyStockProduct');
    const infoDiv = document.getElementById('qtyStockProductInfo');
    const suggDiv = document.getElementById('qtyStockSuggestions');
    if (!select) return;
    query = (query || '').trim().toLowerCase();
    if (infoDiv) infoDiv.classList.add('hidden');
    while(select.options.length > 1) select.remove(1);
    
    const filtered = query.length > 0
        ? ALL_PRODUCTS.filter(p =>
            ((p.serial_no || '').toLowerCase().includes(query) ||
             (p.name || '').toLowerCase().includes(query) ||
             (p.item_name || '').toLowerCase().includes(query) ||
             (p.category || '').toLowerCase().includes(query))
          )
        : ALL_PRODUCTS;
        
    filtered.forEach(p => {
        const isOutOfStock = (parseFloat(p.quantity) <= 0);
        const pUnit = (p.unit || '').toLowerCase() === 'pair' ? 'pair' : 'pcs';
        const stockText = isOutOfStock ? 'OUT OF STOCK' : (p.quantity + ' ' + pUnit);
        const catBadge = p.category ? '[' + p.category + '] ' : '';
        const display = catBadge + (p.item_name || p.name) + ' | SN:' + (p.serial_no || '-') + ' | Stock: ' + stockText;
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = display;
        opt.dataset.price = p.price;
        opt.dataset.name = p.name;
        opt.dataset.serial = p.serial_no;
        opt.dataset.category = p.category;
        opt.dataset.itemName = p.item_name || p.name;
        opt.dataset.qty = p.quantity;
        opt.dataset.unit = pUnit;
        opt.dataset.weight = p.weight || '';
        if (isOutOfStock) opt.style.color = '#dc2626';
        select.appendChild(opt);
    });

    if (suggDiv) {
        suggDiv.innerHTML = '';
        if (query.length > 0 && filtered.length > 0) {
            filtered.slice(0, 8).forEach(p => {
                const isOut = (parseFloat(p.quantity) <= 0);
                const pUnit = (p.unit || '').toLowerCase() === 'pair' ? 'pair' : 'pcs';
                const item = document.createElement('div');
                item.className = 'autocomplete-suggestion-item';
                item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;background:#fff;';
                item.innerHTML = '<div><strong style="color:#022c22;">' + (p.category ? '<span style="color:#b5730e;font-weight:bold;">[' + p.category + ']</span> ' : '') + (p.item_name || p.name) + '</strong></div><div style="font-size:11px;color:' + (isOut?'#dc2626':'#7a4e0a') + ';">SN: ' + (p.serial_no || '-') + ' | ' + (isOut ? 'OUT OF STOCK' : (p.quantity + ' ' + pUnit)) + '</div>';
                item.onmouseover = function() { this.style.background = '#f5ead0'; };
                item.onmouseout = function() { this.style.background = '#fff'; };
                item.onclick = function() {
                    select.value = p.id;
                    if (document.getElementById('qtyStockSearch')) document.getElementById('qtyStockSearch').value = (p.item_name || p.name);
                    suggDiv.classList.add('hidden');
                    onQtyStockChange();
                };
                suggDiv.appendChild(item);
            });
            suggDiv.classList.remove('hidden');
        } else {
            suggDiv.classList.add('hidden');
        }
    }
}

function clearQtyStockSearch() {
    if (document.getElementById('qtyStockSearch')) document.getElementById('qtyStockSearch').value = '';
    const suggDiv = document.getElementById('qtyStockSuggestions');
    if (suggDiv) suggDiv.classList.add('hidden');
    filterQtyStock('');
}

function onQtyStockChange() {
    const select = document.getElementById('qtyStockProduct');
    const infoDiv = document.getElementById('qtyStockProductInfo');
    const rateInput = document.getElementById('qtyRate');
    if (!select || !infoDiv || !rateInput) return;
    const opt = select.options[select.selectedIndex];
    
    if (!opt || !opt.value) {
        infoDiv.classList.add('hidden');
        return;
    }
    
    const price    = parseFloat(opt.dataset.price) || 0;
    const qty      = parseFloat(opt.dataset.qty) || 0;
    const name     = opt.dataset.itemName || opt.dataset.name || '';
    const category = opt.dataset.category || '';
    
    if (qty <= 0) {
        rateInput.value = '';
        infoDiv.innerHTML = '<strong style="color:#dc2626;">' + name + '</strong> | <strong style="color:#dc2626;">OUT OF STOCK (0 pcs available)</strong>';
    } else if (price > 0) {
        rateInput.value = price.toFixed(0);
        infoDiv.innerHTML = '<strong>' + name + '</strong> | Price: \u20B9' + price.toLocaleString('en-IN') + ' | Stock: <strong>' + qty + ' pcs</strong>';
    } else {
        rateInput.value = '';
        infoDiv.innerHTML = '<strong>' + name + '</strong> | Stock: <strong>' + qty + ' pcs</strong> | ⚠ Enter rate per piece manually';
    }
    infoDiv.classList.remove('hidden');
    
    autoQtyTotal();
}

function updateQtyItemTypes() {
    const category = document.getElementById('qtyCatSelect')?.value;
    const itemTypeSelect = document.getElementById('qtyItemType');
    if (!itemTypeSelect) return;
    itemTypeSelect.innerHTML = '<option value="">-- Select Item Type --</option>';
    if (!category) return;
    
    const options = mergedItemTypeOptions[category] || [];
    options.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item;
        opt.textContent = item;
        itemTypeSelect.appendChild(opt);
    });
    autoQtyTotal();
}

function autoQtyTotal() {
    const checkedRadio = document.querySelector('input[name="qty_source"]:checked');
    const source = checkedRadio ? checkedRadio.value : (typeof currentQtySource !== 'undefined' ? currentQtySource : 'stock');
    currentQtySource = source;

    let qty = 0;
    if (source === 'stock') {
        qty = parseInt(document.getElementById('qtyCount')?.value) || 0;
    } else if (source === 'category') {
        qty = parseInt(document.getElementById('qtyCountCat')?.value) || 0;
    } else {
        qty = parseInt(document.getElementById('qtyCountManual')?.value) || 0;
    }
    
    const rate = parseFloat(document.getElementById('qtyRate')?.value) || 0;
    const total = parseFloat((qty * rate).toFixed(2));
    
    const previewRow = document.getElementById('qtyTotalPreviewRow');
    const previewEl = document.getElementById('qtyTotalPreview');
    
    if (total > 0 && previewEl && previewRow) {
        previewEl.textContent = '₹' + total.toLocaleString('en-IN', {minimumFractionDigits: 2});
        previewRow.style.display = '';
    } else if (previewRow) {
        previewRow.style.display = 'none';
    }
    updateMakingChargeHint();
}

function submitQtyItem() {
    try {
        const checkedRadio = document.querySelector('input[name="qty_source"]:checked');
        const source = checkedRadio ? checkedRadio.value : (typeof currentQtySource !== 'undefined' ? currentQtySource : 'stock');
        currentQtySource = source;

        let productId = 'other';
        let name = '';
        let itemType = '';
        let hsn = '';
        let qty = 0;
        let rate = parseFloat(document.getElementById('qtyRate')?.value) || 0;
        
        if (source === 'stock') {
            const select = document.getElementById('qtyStockProduct');
            const opt = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
            if (!opt || !opt.value) { alert('Please select a product from stock.'); return; }
            productId = opt.value;
            name = opt.dataset.itemName;
            hsn = opt.dataset.hsn || '7113';
            qty = parseInt(document.getElementById('qtyCount')?.value) || 0;

            // Stock pcs validation check
            const stockQty = parseFloat(opt.dataset.qty) || 0;
            let existingPcs = 0;
            items.forEach(it => {
                if (String(it.product_id) === String(productId)) {
                    existingPcs += (parseFloat(it.pcs) || parseFloat(it.quantity) || 1);
                }
            });
            const totalReqPcs = existingPcs + qty;
            if (stockQty <= 0) {
                alert(' Out of Stock!\n"' + name + '" is currently out of stock (0 pcs available).');
                return;
            }
            if (totalReqPcs > stockQty) {
                alert(' Exceeds Available Stock!\nOnly ' + stockQty + ' pcs available in stock for "' + name + '", but ' + totalReqPcs + ' pcs requested.');
                return;
            }
        } else if (source === 'category') {
            const cat = document.getElementById('qtyCatSelect')?.value;
            const type = document.getElementById('qtyItemType')?.value;
            if (!cat) { alert('Please select a category.'); return; }
            if (!type) { alert('Please select an item type.'); return; }
            productId = 'other';
            name = type;  // e.g. "Jhumka"
            itemType = type;
            hsn = '7113';
            qty = parseInt(document.getElementById('qtyCountCat')?.value) || 0;
        } else {
            const nameEl = document.getElementById('qtyManualName');
            const hsnEl = document.getElementById('qtyManualHsn');
            name = nameEl ? nameEl.value.trim() : '';
            hsn = hsnEl ? hsnEl.value.trim() : '';
            qty = parseInt(document.getElementById('qtyCountManual')?.value) || 0;
            if (!name) { alert('Please enter an item description.'); return; }
        }
        
        if (qty <= 0) { alert('Please enter quantity.'); return; }
        if (rate <= 0) { alert('Please enter rate per piece.'); return; }
        
        const baseAmount = parseFloat((qty * rate).toFixed(2));
        const imcPct = parseFloat(document.getElementById('itemMakingCharge')?.value) || 0;
        const imc = parseFloat((baseAmount * (imcPct / 100)).toFixed(2));
        const ihm = parseFloat(document.getElementById('itemHallmark')?.value) || 0;
        const idisc = parseFloat(document.getElementById('itemDiscount')?.value) || 0;
        const igst = document.getElementById('itemGstType')?.value || 'non_gst';
        const itemTotal = parseFloat((baseAmount + imc + ihm - idisc).toFixed(2));
        
        let stockQtySerial = '';
        if (source === 'stock') {
            const sel = document.getElementById('qtyStockProduct');
            const opt = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
            stockQtySerial = opt ? (opt.dataset.serial || '') : '';
        }

        items.push({
            product_id: productId,
            name: name,
            item_type: itemType,
            hsn: hsn,
            quantity: qty,
            unit: 'pcs',
            pcs: qty,
            stock_deduct: qty,
            price: rate,
            base_amount: baseAmount,
            making_charge_pct: imcPct,
            making_charge: imc,
            hallmark: ihm,
            discount: idisc,
            total: itemTotal,
            gst_type: igst,
            is_manual: (source === 'manual'),
            is_item_only: (source === 'category'),
            serial_no: stockQtySerial
        });
        
        updateItemsList();
        calculateTotal();
        resetItemCharges();
        
        // Reset fields safely
        if (document.getElementById('qtyCount')) document.getElementById('qtyCount').value = '';
        if (document.getElementById('qtyCountCat')) document.getElementById('qtyCountCat').value = '';
        if (document.getElementById('qtyCountManual')) document.getElementById('qtyCountManual').value = '';
        if (document.getElementById('qtyRate')) document.getElementById('qtyRate').value = '';
        if (document.getElementById('qtyManualName')) document.getElementById('qtyManualName').value = '';
        if (currentQtySource === 'stock') {
            if (document.getElementById('qtyStockProduct')) document.getElementById('qtyStockProduct').value = '';
            if (document.getElementById('qtyStockProductInfo')) document.getElementById('qtyStockProductInfo').classList.add('hidden');
            if (typeof filterQtyStock === 'function') filterQtyStock('');
        } else if (currentQtySource === 'category') {
            if (document.getElementById('qtyCatSelect')) document.getElementById('qtyCatSelect').value = '';
            if (document.getElementById('qtyItemType')) document.getElementById('qtyItemType').value = '';
        } else if (currentQtySource === 'manual') {
            if (document.getElementById('qtyManualName')) document.getElementById('qtyManualName').value = '';
        }
        if (document.getElementById('qtyTotalPreviewRow')) document.getElementById('qtyTotalPreviewRow').style.display = 'none';
        showNotif(' Added Qty Item: ' + name, 'success');
    } catch(e) {
        alert('⚠️ Error in submitQtyItem:\n' + (e.message || e) + '\n\nStack:\n' + (e.stack || ''));
        console.error(e);
    }
}

function populateStockSelects() {
    filterGramStock('');
    filterQtyStock('');
}
function resetItemCharges() {
    if (document.getElementById('itemMakingCharge')) document.getElementById('itemMakingCharge').value = '';
    if (document.getElementById('itemHallmark')) document.getElementById('itemHallmark').value = '';
    if (document.getElementById('itemDiscount')) document.getElementById('itemDiscount').value = '';
    if (document.getElementById('itemGstType')) document.getElementById('itemGstType').value = 'non_gst';
    if (typeof setMcMode === 'function') setMcMode('pct');
    const hint = document.getElementById('itemMakingChargeHint');
    if (hint) { hint.textContent = ''; hint.style.display = 'none'; }
    if (typeof currentGramSource !== 'undefined' && currentGramSource === 'manual') {
        const hmSelect = document.getElementById('itemHallmarkType');
        if (hmSelect) hmSelect.value = 'None';
        const custHm = document.getElementById('itemHallmarkCustom');
        if (custHm) { custHm.value = ''; custHm.classList.add('hidden'); }
    }
}

document.addEventListener('input', function(e) {
    if (e.target && e.target.type === 'number') {
        let val = e.target.value;
        if (val.length > 1 && val.startsWith('0') && val[1] !== '.') {
            e.target.value = val.replace(/^0+/, '');
        }
    }
});

function updateItemsList() {
    try {
        sessionStorage.setItem('billing_cart_items', JSON.stringify(items));
    } catch(e) {}

    const tbody = document.getElementById('itemsList');
    if(items.length === 0) {
        tbody.innerHTML = '<tr id="emptyRow"><td colspan="10" style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">No items added yet \u2014 enter details above to add products</td></tr>';
        return;
    }
    let html = '';
    items.forEach((item, idx) => {
        const icon = item.is_manual ? '\u270F\uFE0F' : (item.is_item_only ? '\uD83C\uDFF7\uFE0F' : '\uD83D\uDC8E');
        const gstSelect = '<select class="jewel-input rounded text-xs" style="width:55px;padding:2px;" onchange="updateItemGst(' + idx + ', this.value)">' +
            '<option value="non_gst"' + (item.gst_type === 'non_gst' ? ' selected' : '') + '>0%</option>' +
            '<option value="gst_3"' + (item.gst_type === 'gst_3' ? ' selected' : '') + '>3%</option>' +
            '<option value="gst_18"' + (item.gst_type === 'gst_18' ? ' selected' : '') + '>18%</option>' +
            '</select>';
        const badge = item.is_manual ? '<span style="color:#9ca3af;font-size:10px;">[Manual]</span>' :
                      item.is_item_only ? '<span style="color:#b5730e;font-size:10px;">[Category]</span>' : '';
        const base = (item.base_amount !== undefined) ? item.base_amount : (item.price * item.quantity);
        let mcPct = item.making_charge_pct || 0;
        let mcAmt = item.making_charge || 0;
        const mcMode = item.mc_mode || 'pct';
        const mcInputVal = (mcMode === 'direct') ? (mcAmt > 0 ? mcAmt : '') : (mcPct > 0 ? mcPct : '');
        const mcSubDisp = (mcMode === 'direct') ? (mcPct > 0 ? '(' + mcPct.toFixed(1) + '%)' : '') : (mcAmt > 0 ? '(\u20B9' + mcAmt.toFixed(2) + ')' : '');

        const hm = item.hallmark || 0;
        const disc = item.discount || 0;
        const hmValDisp = (hm > 0) ? hm : '';
        const discValDisp = (disc > 0) ? disc : '';
        const chargeInputStyle = 'width:60px;padding:3px 4px;border:1px solid #e5c98a;border-radius:5px;font-size:11px;text-align:right;';
        
        let qtyDisp = '';
        if (item.is_manual) {
            if (item.net_wt > 0 || item.gross_wt > 0) {
                const w = (item.net_wt > 0 ? item.net_wt : item.gross_wt).toFixed(3);
                qtyDisp += '<div class="font-bold text-stone-900">' + w + 'g</div>';
                if (item.unit === 'pair') {
                    qtyDisp += '<div style="font-size:10px;color:#7c3aed;font-weight:600;">' + (item.quantity || 1) + ' Pair (' + (item.pcs || ((item.quantity||1)*2)) + ' pcs)</div>';
                } else if (item.pcs && item.pcs > 1) {
                    qtyDisp += '<div style="font-size:10px;color:#6b7280;">(' + item.pcs + ' pcs)</div>';
                }
            } else {
                qtyDisp = item.quantity > 0 ? item.quantity + ' ' + (item.unit || 'pcs') : '\u2014';
                if (item.unit === 'pair') {
                    qtyDisp += '<div style="font-size:10px;color:#7c3aed;font-weight:600;">(' + (item.pcs || (item.quantity * 2)) + ' pcs)</div>';
                }
            }
        } else if (item.net_wt > 0 || item.gross_wt > 0) {
            const netW = (item.net_wt > 0 ? item.net_wt : item.gross_wt).toFixed(3);
            qtyDisp += '<div class="font-bold text-stone-900">' + netW + 'g <span style="font-size:10px;color:#059669;font-weight:600;">(Net)</span></div>';
            if (item.gross_wt > 0 && item.gross_wt !== item.net_wt) {
                qtyDisp += '<div style="font-size:10px;color:#6b7280;">Gross: ' + item.gross_wt.toFixed(3) + 'g</div>';
            }
            if (item.unit === 'pair' || (item.name && item.name.toLowerCase().includes('pair'))) {
                qtyDisp += '<div style="font-size:10px;color:#7c3aed;font-weight:600;">' + (item.quantity || 1) + ' Pair (' + (item.pcs || ((item.quantity||1)*2)) + ' pcs)</div>';
            } else if (item.pcs && item.pcs > 1 && item.unit !== 'g') {
                qtyDisp += '<div style="font-size:10px;color:#6b7280;">(' + item.pcs + ' pcs)</div>';
            }
        } else {
            qtyDisp = item.quantity > 0 ? item.quantity + ' ' + (item.unit || 'pcs') : '\u2014';
            if (item.unit === 'pair') {
                qtyDisp += '<div style="font-size:10px;color:#7c3aed;font-weight:600;">(' + (item.pcs || (item.quantity * 2)) + ' pcs)</div>';
            }
        }

        let rateDisp = '';
        if (item.gold_rate_10g > 0 || item.diamond_rate > 0) {
            let rParts = [];
            if (item.gold_rate_10g > 0) rParts.push((item.gold_kt || 'Gold') + ': ₹' + (item.gold_rate_10g / 10).toLocaleString('en-IN') + '/g');
            if (item.diamond_rate > 0) rParts.push('Diamond: ₹' + item.diamond_rate.toLocaleString('en-IN') + '/1Ct');
            rateDisp = '<div style="font-size:10px;line-height:1.2;font-weight:600;">' + rParts.join('<br>') + '</div>';
        } else {
            rateDisp = item.price > 0 ? '\u20B9' + item.price.toFixed(2) + ((item.net_wt > 0 || item.unit === 'g') ? '/g' : '') : '\u2014';
        }

        const showHm = item.hallmark_type && item.hallmark_type.toLowerCase() !== 'none' && item.hallmark_type.toLowerCase() !== 'na' && item.hallmark_type.trim() !== '';
        const hmBadge = showHm ? '<span style="display:inline-block;padding:1px 5px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;font-size:9.5px;font-weight:700;margin-left:4px;">' + htmlEsc(item.hallmark_type) + '</span>' : '';
        const cleanHuid = (item.huid_code || '').trim().replace(/^HUID[:\s\-_]*/i, '');
        const huidBadge = cleanHuid ? '<span style="display:inline-block;padding:1px 5px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:4px;font-size:9.5px;font-family:monospace;font-weight:600;margin-left:4px;">HUID: ' + htmlEsc(cleanHuid) + '</span>' : '';

        const showHsn = (item.gst_type && item.gst_type !== 'non_gst' && item.hsn && item.hsn.trim() !== '' && item.hsn !== '0');
        const hsnHtml = showHsn ? '<div style="color:#9ca3af;font-size:10px;">HSN: ' + htmlEsc(item.hsn) + '</div>' : '';

        html += '<tr>' +
            '<td class="px-2 py-2 text-xs text-center" style="color:#9ca3af;">' + (idx+1) + '</td>' +
            '<td class="px-2 py-2 text-xs" style="color:#374151;">' + icon + ' <strong>' + htmlEsc(item.name) + '</strong>' +
                hmBadge + huidBadge +
                (item.item_type ? '<span style="color:#b5730e;font-size:10px;"> [' + htmlEsc(item.item_type) + ']</span>' : '') +
                badge + hsnHtml + '</td>' +
            '<td class="px-2 py-2 text-center text-xs font-medium" style="color:#4b5563;">' + qtyDisp + '</td>' +
            '<td class="px-2 py-2 text-right text-xs" style="color:#374151;">' + rateDisp + '</td>' +
            '<td class="px-2 py-2 text-right text-xs" style="color:#374151;">\u20B9' + base.toFixed(2) + '</td>' +
            '<td class="px-2 py-2 text-right text-xs">' +
                '<input type="number" min="0" step="' + (mcMode === 'direct' ? '1' : '0.1') + '" value="' + mcInputVal + '" placeholder="0" style="' + chargeInputStyle + '" onchange="updateItemCharge(' + idx + ',\'' + (mcMode === 'direct' ? 'making_charge' : 'making_charge_pct') + '\',this.value)">' +
                '<div style="font-size:9.5px;color:#059669;font-weight:600;margin-top:1px;">' + mcSubDisp + '</div>' +
            '</td>' +
            '<td class="px-2 py-2 text-right text-xs"><input type="number" min="0" step="1" value="' + hmValDisp + '" placeholder="0" style="' + chargeInputStyle + '" onchange="updateItemCharge(' + idx + ',\'hallmark\',this.value)"></td>' +
            '<td class="px-2 py-2 text-right text-xs font-semibold" style="color:#7a4e0a;">\u20B9' + item.total.toFixed(2) + '</td>' +
            '<td class="px-2 py-2 text-center text-xs">' + gstSelect + '</td>' +
            '<td class="px-2 py-2"><button onclick="removeItem(' + idx + ')" class="remove-btn">\u2715</button></td>' +
            '</tr>';

    });
    tbody.innerHTML = html;
}

function updateItemCharge(idx, field, value) {
    if(!items[idx]) return;
    let val = parseFloat(value);
    if(isNaN(val) || val < 0) val = 0;
    
    const base = (items[idx].base_amount !== undefined) ? items[idx].base_amount : (items[idx].price * items[idx].quantity);
    
    if (field === 'making_charge_pct') {
        items[idx].making_charge_pct = val;
        const imcAmt = parseFloat((base * (val / 100)).toFixed(2));
        items[idx].making_charge = imcAmt;
    } else if (field === 'making_charge') {
        items[idx].making_charge = val;
        items[idx].making_charge_pct = (base > 0) ? parseFloat((val / base * 100).toFixed(2)) : 0;
    } else {
        items[idx][field] = val;
    }
    
    const mc   = items[idx].making_charge || 0;
    const hm   = items[idx].hallmark || 0;
    items[idx].total = parseFloat((base + mc + hm).toFixed(2));
    updateItemsList();
    calculateTotal();
}

function htmlEsc(str) {
    if(!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function removeItem(index) { items.splice(index, 1); updateItemsList(); calculateTotal(); }
function updateItemGst(index, value) { 
    if(items[index]) { 
        items[index].gst_type = value; 
        updateItemsList(); 
        calculateTotal(); 
    } 
}

// Items added via Stock already carry their real product serial_no (HUID).
// Manual / Category items don't have one, so fall back to the invoice-level
// "HUID Code" field entered in Customer Details, if the user filled it in.
const AVAILABLE_HUIDS = <?php echo json_encode($available_huids); ?> || [];

function getInvoiceHuid() {
    const el = document.getElementById('manualHuid');
    return el ? el.value.trim() : '';
}
function buildItemsForSubmit() {
    const huid = getInvoiceHuid();
    return items.map(function(it) {
        const out = Object.assign({}, it);
        if (!out.serial_no && huid) out.serial_no = huid;
        return out;
    });
}

function populateHuidOptions() {
    const input = document.getElementById('manualHuid');
    const list = document.getElementById('huidList');
    if(!input || !list) return;
    const term = input.value.trim().toLowerCase();
    list.innerHTML = '';
    AVAILABLE_HUIDS.forEach(function(code) {
        if(term === '' || code.toLowerCase().startsWith(term)) {
            const option = document.createElement('option');
            option.value = code;
            list.appendChild(option);
        }
    });
}


function calculateTotal() {
    const subtotal = items.reduce((sum, item) => sum + item.total, 0);
    const makingAmt = items.reduce((sum, item) => sum + (item.making_charge || 0), 0);
    const hallmark  = items.reduce((sum, item) => sum + (item.hallmark || 0), 0);
    const discountEl = document.getElementById('overallDiscountInput');
    const discount  = parseFloat(discountEl?.value) || 0;
    
    let cgst = 0, sgst = 0;
    items.forEach(item => {
        let taxable_amount = (item.total !== undefined) ? item.total : (item.base_amount !== undefined ? item.base_amount : item.price * item.quantity);
        if (item.gst_type === 'gst_3') {
            cgst += taxable_amount * 0.015;
            sgst += taxable_amount * 0.015;
        } else if (item.gst_type === 'gst_18') {
            cgst += taxable_amount * 0.09;
            sgst += taxable_amount * 0.09;
        }
    });

    const totalGoldWeight = items.reduce((sum, item) => {
        if (item.gold_weight && parseFloat(item.gold_weight) > 0) return sum + parseFloat(item.gold_weight);
        if (item.unit === 'g') return sum + (parseFloat(item.quantity) || 0);
        return sum + (parseFloat(item.gross_wt) || 0);
    }, 0);
    const totalDiamondCent = items.reduce((sum, item) => {
        return sum + (parseFloat(item.diamond_cent) || 0);
    }, 0);
    const totalPieces = items.reduce((sum, item) => {
        if (item.pcs) return sum + (parseInt(item.pcs) || 1);
        if (item.unit !== 'g') return sum + (parseInt(item.quantity) || 1);
        return sum + 1;
    }, 0);

    const oldGoldEl = document.getElementById('oldGoldAmountInput');
    const oldGold = parseFloat(oldGoldEl?.value) || 0;

    // --- CALCULATE ADVANCE CREDIT DEDUCTION & GRAND TOTAL ---
    const subtotalWithTax = subtotal + cgst + sgst;
    const netPayableBeforeAdv = Math.max(0, subtotalWithTax - discount);

    let calculatedAdvDeduct = 0;
    let surplusAmount = 0;
    const surplusAction = document.getElementById('hiddenAdvSurplusAction')?.value || 'keep_advance';

    if (currentCustomerAdvance > 0 && netPayableBeforeAdv > 0) {
        if (advanceAdjustmentMode === 'full') {
            if (currentCustomerAdvance > netPayableBeforeAdv) {
                surplusAmount = parseFloat((currentCustomerAdvance - netPayableBeforeAdv).toFixed(2));
                if (surplusAction === 'refund_customer') {
                    // Full advance is redeemed, excess returned
                    calculatedAdvDeduct = currentCustomerAdvance;
                } else {
                    // Bill amount is deducted, remaining saved in credit
                    calculatedAdvDeduct = netPayableBeforeAdv;
                }
            } else {
                calculatedAdvDeduct = currentCustomerAdvance;
                surplusAmount = 0;
            }
        } else if (advanceAdjustmentMode === 'custom') {
            const customVal = parseFloat(document.getElementById('advCustomAmountInput')?.value) || 0;
            calculatedAdvDeduct = Math.min(netPayableBeforeAdv, Math.min(currentCustomerAdvance, customVal));
            surplusAmount = 0;
        }
    }
    appliedAdvanceAmount = parseFloat(calculatedAdvDeduct.toFixed(2));

    const grand = Math.max(0, netPayableBeforeAdv - Math.min(netPayableBeforeAdv, appliedAdvanceAmount) - oldGold);
    
    const fmt = v => '\u20B9' + v.toFixed(2);
    if (document.getElementById('totalWeightDisplay')) {
        let weightTextParts = [];
        if (totalGoldWeight > 0) weightTextParts.push('Gold: ' + totalGoldWeight.toFixed(3) + ' g');
        if (totalDiamondCent > 0) weightTextParts.push('Diamond: ' + totalDiamondCent.toFixed(2) + ' Ct');
        let weightText = weightTextParts.join(' | ');
        if (!weightText && totalPieces > 0) weightText = '0.000 g';
        if (totalPieces > 0) weightText += ' (' + totalPieces + ' pcs)';

        document.getElementById('totalWeightDisplay').innerHTML = '<strong style="color:#7a4e0a;">' + weightText + '</strong>';
        const weightRow = document.getElementById('totalWeightRow');
        if (weightRow) weightRow.style.display = (totalGoldWeight > 0 || totalDiamondCent > 0 || totalPieces > 0) ? '' : 'none';
    }
    document.getElementById('subtotal').textContent = fmt(subtotal - makingAmt - hallmark);
    document.getElementById('makingChargeAmount').textContent = fmt(makingAmt);
    document.getElementById('hallmarkAmount').textContent     = fmt(hallmark);

    // Update hidden form fields
    document.getElementById('hiddenSubtotal').value     = (subtotal - makingAmt - hallmark).toFixed(2);
    document.getElementById('hiddenGst').value          = (cgst + sgst).toFixed(2);
    document.getElementById('hiddenTotal').value        = grand.toFixed(2);
    document.getElementById('hiddenMakingCharge').value = makingAmt.toFixed(2);
    document.getElementById('hiddenHallmark').value     = hallmark.toFixed(2);
    document.getElementById('hiddenDiscount').value     = discount.toFixed(2);
    document.getElementById('hiddenOldGold').value      = oldGold.toFixed(2);
    document.getElementById('hiddenAdvanceAdjusted').value = (surplusAction === 'refund_customer' ? currentCustomerAdvance : Math.min(netPayableBeforeAdv, appliedAdvanceAmount)).toFixed(2);
    document.getElementById('hiddenAppliedAdvanceId').value = (appliedAdvanceAmount > 0) ? currentAdvanceId : 0;


    // Update Advance Credit UI displays & Surplus Handling Panel
    const surplusDiv = document.getElementById('advSurplusContainer');
    const surplusBadge = document.getElementById('advSurplusAmountBadge');
    const surplusNote = document.getElementById('advSurplusSummaryNote');
    const refundModeDiv = document.getElementById('advRefundModeDiv');

    if (surplusDiv) {
        if (advanceAdjustmentMode === 'full' && surplusAmount > 0.005) {
            surplusDiv.style.display = 'block';
            if (surplusBadge) surplusBadge.textContent = fmt(surplusAmount);
            if (document.getElementById('hiddenAdvSurplusAmount')) document.getElementById('hiddenAdvSurplusAmount').value = surplusAmount.toFixed(2);
            
            if (surplusAction === 'refund_customer') {
                if (refundModeDiv) refundModeDiv.style.display = 'flex';
                const rMode = document.getElementById('advRefundModeSelect')?.value || 'Cash';
                if (surplusNote) surplusNote.innerHTML = '<span class="text-rose-800">💵 <strong>' + fmt(surplusAmount) + '</strong> excess cash/online will be returned to customer via ' + rMode + '.</span>';
                if (document.getElementById('advDeductionDisplay')) {
                    document.getElementById('advDeductionDisplay').textContent = '- ' + fmt(netPayableBeforeAdv) + ' (+ ' + fmt(surplusAmount) + ' Return)';
                }
                if (document.getElementById('advanceAdjustedDisplay')) {
                    document.getElementById('advanceAdjustedDisplay').textContent = '- ' + fmt(netPayableBeforeAdv) + ' (Excess ' + fmt(surplusAmount) + ' Refunded)';
                    document.getElementById('advanceAdjustedRow').style.display = '';
                }
            } else {
                if (refundModeDiv) refundModeDiv.style.display = 'none';
                if (surplusNote) surplusNote.innerHTML = '<span class="text-emerald-800">🏦 Remaining <strong>' + fmt(surplusAmount) + '</strong> will stay saved in customer advance credit.</span>';
                if (document.getElementById('advDeductionDisplay')) {
                    document.getElementById('advDeductionDisplay').textContent = '- ' + fmt(appliedAdvanceAmount) + ' (₹' + surplusAmount.toFixed(0) + ' kept in Credit)';
                }
                if (document.getElementById('advanceAdjustedDisplay')) {
                    document.getElementById('advanceAdjustedDisplay').textContent = '- ' + fmt(appliedAdvanceAmount);
                    document.getElementById('advanceAdjustedRow').style.display = (appliedAdvanceAmount > 0) ? '' : 'none';
                }
            }
        } else {
            surplusDiv.style.display = 'none';
            if (document.getElementById('hiddenAdvSurplusAmount')) document.getElementById('hiddenAdvSurplusAmount').value = '0';
            if (document.getElementById('advDeductionDisplay')) {
                document.getElementById('advDeductionDisplay').textContent = '- ' + fmt(appliedAdvanceAmount);
            }
            if (document.getElementById('advanceAdjustedDisplay')) {
                document.getElementById('advanceAdjustedDisplay').textContent = '- ' + fmt(appliedAdvanceAmount);
                document.getElementById('advanceAdjustedRow').style.display = (appliedAdvanceAmount > 0) ? '' : 'none';
            }
        }
    }

    const selectedOldType = document.getElementById('oldGoldTypeSelect')?.value || 'Old Gold';
    if(document.getElementById('hiddenOldGoldType')) document.getElementById('hiddenOldGoldType').value = selectedOldType;
    if(document.getElementById('oldGoldLabel')) document.getElementById('oldGoldLabel').textContent = selectedOldType + ' Deduction';
    if(document.getElementById('oldGoldDisplayAmount')) {
        document.getElementById('oldGoldDisplayAmount').textContent = '- ' + fmt(oldGold);
        document.getElementById('oldGoldRow').style.display = (oldGold > 0) ? '' : 'none';
    }
    document.getElementById('cgstAmount').textContent     = fmt(cgst);
    document.getElementById('sgstAmount').textContent     = fmt(sgst);
    document.getElementById('grandTotal').textContent     = fmt(grand);
    
    document.getElementById('cgstRow').style.display = (cgst > 0) ? '' : 'none';
    document.getElementById('sgstRow').style.display = (sgst > 0) ? '' : 'none';
    
    document.getElementById('hiddenSubtotal').value  = subtotal;
    document.getElementById('hiddenGst').value       = cgst + sgst;
    document.getElementById('hiddenTotal').value     = grand;
    let overallGst = 'non_gst';
    if (items.some(i => i.gst_type === 'gst_18')) overallGst = 'gst_18';
    else if (items.some(i => i.gst_type === 'gst_3')) overallGst = 'gst_3';
    if (document.getElementById('hiddenGstType')) document.getElementById('hiddenGstType').value = overallGst;
    document.getElementById('hiddenItems').value     = JSON.stringify(buildItemsForSubmit());
    document.getElementById('hiddenMakingCharge').value = makingAmt;

    document.getElementById('hiddenHallmark').value  = hallmark;
    document.getElementById('hiddenDiscount').value  = discount;
    if(document.getElementById('hiddenOldGold')) document.getElementById('hiddenOldGold').value = oldGold;
    if(document.getElementById('paymentMethod').value === 'Split') {
        document.getElementById('splitGrandTotal').textContent = fmt(grand);
        updateSplitDisplay();
    }
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.innerHTML = (cgst + sgst > 0) ? ' Generate Tax Invoice ' : ' Generate Cash Memo ';
    }
    updateBalanceFromPart();
}

function toggleManualInvoice() {
    const checked = document.getElementById('manualInvoiceToggle').checked;
    document.getElementById('manualInvoiceDiv').style.display = checked ? 'block' : 'none';
    document.getElementById('autoInvoiceInfo').style.display  = checked ? 'none'  : 'block';
}

// Shop Rates

function saveRatesToServer() {
    try {
        fetch('billing.php?action=save_shop_rates', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(shopRates)
        }).catch(e => console.log(e));
    } catch(e) {}
}

function loadShopRates() {
    ['gold24','gold22','gold18','gold12','gold9','silver','diamond'].forEach(k => {
        const val = localStorage.getItem('shopRate_' + k);
        const inputEl = document.getElementById(shopInputIds[k]);
        if(val && parseFloat(val) > 0) {
            shopRates[k] = parseFloat(val);
            const dispEl  = document.getElementById(shopDisplayIds[k]);
            if(inputEl) inputEl.value = val;
            if(dispEl) dispEl.textContent = '\u20B9' + parseFloat(val).toLocaleString('en-IN');
            previewShopRate(k);
        } else if (inputEl && inputEl.value && parseFloat(inputEl.value) > 0) {
            shopRates[k] = parseFloat(inputEl.value);
            previewShopRate(k);
        }
    });
    const saved = localStorage.getItem('shopRateSavedAt');
    if(saved) {
        const statusEl = document.getElementById('shopRateSaveStatus');
        const lastSavedEl = document.getElementById('shopRateLastSaved');
        if(statusEl) {
            statusEl.textContent = '✔ Saved';
            statusEl.style.background = 'rgba(5,150,105,0.15)';
            statusEl.style.borderColor = '#059669';
            statusEl.style.color = '#059669';
        }
        if(lastSavedEl) lastSavedEl.textContent  = 'Last saved: ' + saved;
    }
    calcShopValue();
    refreshActiveBillingRate();
}

function refreshActiveBillingRate() {
    try {
        if (typeof currentGramSource !== 'undefined') {
            if (currentGramSource === 'stock') {
                const select = document.getElementById('gramStockProduct');
                if (select && select.value) onGramStockChange();
            } else if (currentGramSource === 'category') {
                const cat = document.getElementById('gramCatSelect');
                if (cat && cat.value) updateGramItemTypes();
            }
        }
    } catch(e) {}
}

function previewShopRate(key) {
    const inputEl = document.getElementById(shopInputIds[key]);
    if (!inputEl) return;
    const val = parseFloat(inputEl.value) || 0;
    shopRates[key] = val;
    const dispEl = document.getElementById(shopDisplayIds[key]);
    if (dispEl) dispEl.textContent = val > 0 ? '\u20B9' + val.toLocaleString('en-IN') : '\u2014';
    // Show per-gram equivalent
    const perGramIds = { gold24:'shopGold24PerGram', gold22:'shopGold22PerGram', gold18:'shopGold18PerGram', gold12:'shopGold12PerGram', gold9:'shopGold9PerGram', silver:'shopSilverPerGram', diamond:'shopDiamondPerGram' };
    const pgEl = document.getElementById(perGramIds[key]);
    if(pgEl) {
        if (key === 'diamond') {
            pgEl.textContent = val > 0 ? '≈ ₹' + val.toLocaleString('en-IN') + ' per 1 Ct (used in billing)' : '';
        } else {
            pgEl.textContent = val > 0 ? '≈ ₹' + (val/10).toLocaleString('en-IN', {maximumFractionDigits:2}) + ' per gram (used in billing)' : '';
        }
    }
}

// Auto-fill shop rates from live market data
function autoFillShopFromLive() {
    const g24live = metalRates.gold24 * 10;
    const g22live = metalRates.gold22 * 10;
    const g18live = Math.round(g22live * 18 / 22);
    const g12live = Math.round(g22live * 12 / 22);
    const g9live  = Math.round(g22live * 9  / 22);
    const silvLive = metalRates.silver * 10;

    if(g24live > 0 && document.getElementById('shopGold24Input')) {
        document.getElementById('shopGold24Input').value = Math.round(g24live);
        previewShopRate('gold24');
    }
    if(g22live > 0) {
        document.getElementById('shopGold22Input').value = Math.round(g22live);
        previewShopRate('gold22');
    }
    if(g18live > 0) {
        document.getElementById('shopGold18Input').value = g18live;
        previewShopRate('gold18');
    }
    if(g12live > 0) {
        document.getElementById('shopGold12Input').value = g12live;
        previewShopRate('gold12');
    }
    if(g9live > 0) {
        document.getElementById('shopGold9Input').value = g9live;
        previewShopRate('gold9');
    }
    if(silvLive > 0) {
        document.getElementById('shopSilverInput').value = Math.round(silvLive);
        previewShopRate('silver');
    }
    if(g22live <= 0 && g24live <= 0) alert('Live rates not loaded yet. Click the \u21BB refresh button on Live Metal Rates first.');
}

function saveShopRate(key) {
    const inputEl = document.getElementById(shopInputIds[key]);
    const rawVal = inputEl ? (inputEl.value || '').toString().replace(/[^0-9.]/g, '') : '';
    const val = parseFloat(rawVal) || 0;
    const labelNames = { gold24: 'Gold 24K', gold22: 'Gold 22K', gold18: 'Gold 18K', gold12: 'Gold 12K', gold9: 'Gold 9K', silver: 'Silver', diamond: 'Diamond' };
    const label = labelNames[key] || key;
    
    if (val <= 0) {
        alert('Please enter a valid price for ' + label + ' before clicking Save!');
        return;
    }

    executeSaveShopRate(key, val, label);
}

function executeSaveShopRate(key, val, label) {
    const inputEl = document.getElementById(shopInputIds[key]);
    shopRates[key] = val;
    localStorage.setItem('shopRate_' + key, val);
    const now = new Date().toLocaleString('en-IN');
    localStorage.setItem('shopRateSavedAt', now);
    saveRatesToServer();
    
    if (document.getElementById(shopDisplayIds[key])) {
        document.getElementById(shopDisplayIds[key]).textContent = '₹' + val.toLocaleString('en-IN');
    }
    previewShopRate(key);
    
    if (document.getElementById('shopRateSaveStatus')) {
        const el = document.getElementById('shopRateSaveStatus');
        el.textContent = '✔ Saved';
        el.style.background = '#d1fae5';
        el.style.border = '1px solid #6ee7b7';
        el.style.color = '#065f46';
    }
    if (document.getElementById('shopRateLastSaved')) {
        document.getElementById('shopRateLastSaved').textContent = 'Last saved: ' + now;
    }
    
    if (inputEl && inputEl.nextElementSibling) {
        const saveBtn = inputEl.nextElementSibling;
        const origText = saveBtn.textContent;
        saveBtn.textContent = ' Saved';
        saveBtn.style.background = '#059669';
        saveBtn.style.color = '#ffffff';
        setTimeout(() => {
            saveBtn.textContent = origText;
            saveBtn.style.background = '';
            saveBtn.style.color = '';
        }, 2000);
    }
    
    const unitMsg = (key === 'diamond') ? '/ 1 Ct' : '/ 10g';
    showNotif(' ' + label + ' shop rate saved: ₹' + val.toLocaleString('en-IN') + ' ' + unitMsg, 'success');
    calcShopValue();
    refreshActiveBillingRate();
    alert('✓ ' + label + ' price saved successfully!\nRate: ₹' + val.toLocaleString('en-IN') + ' (' + unitMsg + ')');
}

function saveAllShopRates() {
    let saved = 0;
    ['gold24','gold22','gold18','gold12','gold9','silver','diamond'].forEach(k => {
        const inputEl = document.getElementById(shopInputIds[k]);
        const rawVal = inputEl ? (inputEl.value || '').toString().replace(/[^0-9.]/g, '') : '';
        const val = parseFloat(rawVal) || 0;
        if(val > 0) {
            shopRates[k] = val;
            localStorage.setItem('shopRate_' + k, val);
            if (document.getElementById(shopDisplayIds[k])) {
                document.getElementById(shopDisplayIds[k]).textContent = '₹' + val.toLocaleString('en-IN');
            }
            previewShopRate(k);
            if (inputEl && inputEl.nextElementSibling) {
                const saveBtn = inputEl.nextElementSibling;
                const origText = saveBtn.textContent;
                saveBtn.textContent = ' Saved';
                saveBtn.style.background = '#059669';
                saveBtn.style.color = '#ffffff';
                setTimeout(() => {
                    saveBtn.textContent = origText;
                    saveBtn.style.background = '';
                    saveBtn.style.color = '';
                }, 2000);
            }
            saved++;
        }
    });
    if(saved === 0) { alert('Please enter a price in at least one rate field before saving!'); return; }
    const now = new Date().toLocaleString('en-IN');
    localStorage.setItem('shopRateSavedAt', now);
    saveRatesToServer();
    if (document.getElementById('shopRateSaveStatus')) {
        const el = document.getElementById('shopRateSaveStatus');
        el.textContent = '✔ All Saved';
        el.style.background = '#d1fae5';
        el.style.border = '1px solid #6ee7b7';
        el.style.color = '#065f46';
    }
    if (document.getElementById('shopRateLastSaved')) {
        document.getElementById('shopRateLastSaved').textContent = 'Last saved: ' + now;
    }
    showNotif(' All Shop Rates Saved Successfully!', 'success');
    calcShopValue();
    refreshActiveBillingRate();
    alert('✓ All Shop Rates Saved Successfully!');
}

function closeShopRateConfirmModal() {
    const modal = document.getElementById('shopRateConfirmModal');
    if (modal) modal.style.display = 'none';
}

function getShopRateForCategory(category) {
    const c = (category || '').trim().toLowerCase();
    let key = '';
    if (c.includes('24')) key = 'gold24';
    else if (c.includes('22')) key = 'gold22';
    else if (c.includes('18')) key = 'gold18';
    else if (c.includes('12')) key = 'gold12';
    else if (c.includes('9'))  key = 'gold9';
    else if (c.includes('silver')) key = 'silver';
    else if (c.includes('diamond')) key = 'diamond';
    else if (c.includes('gold')) key = 'gold22';
    
    if (!key) return 0;

    const inputEl = document.getElementById(shopInputIds[key]);
    const inputVal = inputEl ? (parseFloat(inputEl.value) || 0) : 0;
    if (inputVal > 0) {
        shopRates[key] = inputVal;
    }
    
    if (!shopRates[key] || shopRates[key] <= 0) {
        const saved = localStorage.getItem('shopRate_' + key);
        if (saved && parseFloat(saved) > 0) {
            shopRates[key] = parseFloat(saved);
        }
    }
    
    if (key === 'diamond') {
        return (shopRates[key] || 0);
    }
    return (shopRates[key] || 0) / 10;
}

function calcShopValue() {
    const metal = document.getElementById('shopMetalSelect').value;
    const grams = parseFloat(document.getElementById('shopMetalGrams').value) || 0;
    const ratePerGram = (shopRates[metal] || 0) / 10;
    const el = document.getElementById('shopCalcResult');
    if(grams > 0 && ratePerGram > 0) {
        el.textContent = '\u2248 \u20B9' + Math.round(ratePerGram * grams).toLocaleString('en-IN');
        el.style.color = '#059669';
    } else if(!shopRates[metal] || shopRates[metal] <= 0) {
        el.textContent = 'Set shop rate first'; el.style.color = '#9ca3af';
    } else {
        el.textContent = '\u2014';
    }
}

// EMI Calculator
function calculateEMI() {
    const P = parseFloat(document.getElementById('loanAmount').value) || 0;
    const r = (parseFloat(document.getElementById('interestRate').value) || 0) / 12 / 100;
    const n = parseFloat(document.getElementById('tenure').value) || 0;
    if(P > 0 && r > 0 && n > 0) {
        const emi = P * r * Math.pow(1+r,n) / (Math.pow(1+r,n) - 1);
        document.getElementById('emiAmount').textContent   = '\u20B9' + emi.toFixed(2);
        document.getElementById('totalPayment').textContent = '\u20B9' + (emi * n).toFixed(2);
        document.getElementById('emiResult').classList.remove('hidden');
    } else {
        alert('Please fill all EMI fields correctly.');
    }
}

// Live Metal Rates

async function fetchMetalPrices() {
    const statusEl = document.getElementById('metalPriceStatus');
    if(statusEl) {
        statusEl.textContent = '⟳ Fetching...';
        statusEl.style.color = '#3b82f6';
    }
    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000);
        const res  = await fetch('metal_rates.php?t=' + Date.now(), { signal: controller.signal });
        clearTimeout(timeoutId);
        const data = await res.json();
        if(!data || !data.success) throw new Error('API error');
        
        metalRates.gold24   = (data.gold24 || 0)   / 10;
        metalRates.gold22   = (data.gold22 || 0)   / 10;
        metalRates.gold18   = (data.gold18 || Math.round((data.gold24 || 0) * 0.75)) / 10;
        metalRates.gold12   = (data.gold12 || Math.round((data.gold24 || 0) * 0.50)) / 10;
        metalRates.gold9    = (data.gold9  || Math.round((data.gold24 || 0) * 0.375)) / 10;
        metalRates.silver   = (data.silver || 0)   / 10;
        metalRates.platinum = (data.platinum || 0) / 10;
        
        const fmt = v => '₹' + Math.round(v).toLocaleString('en-IN');
        if(document.getElementById('gold24Price'))   document.getElementById('gold24Price').textContent   = fmt(data.gold24 || 0);
        if(document.getElementById('gold22Price'))   document.getElementById('gold22Price').textContent   = fmt(data.gold22 || 0);
        if(document.getElementById('gold18Price'))   document.getElementById('gold18Price').textContent   = fmt(data.gold18 || Math.round((data.gold24 || 0) * 0.75));
        if(document.getElementById('gold12Price'))   document.getElementById('gold12Price').textContent   = fmt(data.gold12 || Math.round((data.gold24 || 0) * 0.50));
        if(document.getElementById('gold9Price'))    document.getElementById('gold9Price').textContent    = fmt(data.gold9  || Math.round((data.gold24 || 0) * 0.375));
        if(document.getElementById('silverPrice'))   document.getElementById('silverPrice').textContent   = fmt(data.silver || 0);
        if(document.getElementById('platinumPrice')) document.getElementById('platinumPrice').textContent = fmt(data.platinum || 0);
        
        ['gold24Change','gold22Change','gold18Change','gold12Change','gold9Change','silverChange','platinumChange'].forEach(id => {
            const el = document.getElementById(id);
            if(el) {
                el.textContent = data.fallback ? '⚠ Approx' : 'per 10g';
                el.style.color = data.fallback ? '#d97706' : '#9ca3af';
            }
        });
        if(statusEl) {
            statusEl.textContent = data.fallback ? '⚠ Market' : (data.cached ? '● Cached' : '● Live');
            statusEl.style.color = data.fallback ? '#d97706' : '#059669';
        }
        const infoEl = document.getElementById('metalUpdateInfo');
        if(infoEl) infoEl.textContent = 'Source: ' + (data.source || 'Live') + ' · ' + (data.updated || '');
        calcMetalValue();
    } catch(err) {
        if(statusEl) { statusEl.textContent = '● Market'; statusEl.style.color = '#059669'; }
        const fallbackG24 = 78500;
        const fallbackG22 = 72000;
        const fallbackG18 = 58875;
        const fallbackG12 = 39250;
        const fallbackG9  = 29437;
        const fallbackSilv = 920;
        const fallbackPlat = 32000;
        if(document.getElementById('gold24Price'))   document.getElementById('gold24Price').textContent   = '₹' + fallbackG24.toLocaleString('en-IN');
        if(document.getElementById('gold22Price'))   document.getElementById('gold22Price').textContent   = '₹' + fallbackG22.toLocaleString('en-IN');
        if(document.getElementById('gold18Price'))   document.getElementById('gold18Price').textContent   = '₹' + fallbackG18.toLocaleString('en-IN');
        if(document.getElementById('gold12Price'))   document.getElementById('gold12Price').textContent   = '₹' + fallbackG12.toLocaleString('en-IN');
        if(document.getElementById('gold9Price'))    document.getElementById('gold9Price').textContent    = '₹' + fallbackG9.toLocaleString('en-IN');
        if(document.getElementById('silverPrice'))   document.getElementById('silverPrice').textContent   = '₹' + fallbackSilv.toLocaleString('en-IN');
        if(document.getElementById('platinumPrice')) document.getElementById('platinumPrice').textContent = '₹' + fallbackPlat.toLocaleString('en-IN');
        metalRates.gold24=fallbackG24/10; metalRates.gold22=fallbackG22/10; metalRates.gold18=fallbackG18/10; metalRates.gold12=fallbackG12/10; metalRates.gold9=fallbackG9/10; metalRates.silver=fallbackSilv/10; metalRates.platinum=fallbackPlat/10;
        calcMetalValue();
    }
}

document.addEventListener('DOMContentLoaded', fetchMetalPrices);
window.addEventListener('load', fetchMetalPrices);
fetchMetalPrices();
setInterval(fetchMetalPrices, 5 * 60 * 1000);

function calcMetalValue() {
    const metal = document.getElementById('metalSelect').value;
    const grams = parseFloat(document.getElementById('metalGrams').value) || 0;
    const rate  = metalRates[metal] || 0;
    const el    = document.getElementById('metalCalcResult');
    el.textContent = grams > 0 && rate > 0 ? '\u2248 \u20B9' + Math.round(rate * grams).toLocaleString('en-IN') : '\u2014';
    el.style.color = '#059669';
}

fetchMetalPrices();
setInterval(fetchMetalPrices, 10 * 60 * 1000);

// Split Payment
function toggleSplitPayment() {
    const method = document.getElementById('paymentMethod').value;
    const splitDiv = document.getElementById('splitPaymentDiv');
    if(method === 'Split') {
        splitDiv.style.display = 'block';
        document.getElementById('hiddenIsSplit').value = '1';
        updateSplitDisplay();
    } else {
        splitDiv.style.display = 'none';
        document.getElementById('hiddenIsSplit').value = '0';
        document.getElementById('cashAmount').value = 0;
        document.getElementById('upiAmount').value  = 0;
        document.getElementById('hiddenCashPaid').value = 0;
        document.getElementById('hiddenUpiPaid').value  = 0;
    }
    togglePartPayment();
}

function onSplitInput(changedField) {
    const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
    let cash = parseFloat(document.getElementById('cashAmount').value) || 0;
    let upi  = parseFloat(document.getElementById('upiAmount').value)  || 0;
    cash = Math.min(Math.max(0, cash), grand);
    document.getElementById('cashAmount').value = cash;
    upi = Math.min(Math.max(0, upi), grand);
    document.getElementById('upiAmount').value = upi;
    document.getElementById('hiddenCashPaid').value = cash;
    document.getElementById('hiddenUpiPaid').value  = upi;
    document.getElementById('paidAmount').value = (cash + upi).toFixed(2);
    updateSplitDisplay();
    updateBalanceFromPart();
}

function quickSplit(cashPercent) {
    const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
    if(grand <= 0) { alert('Please add items first!'); return; }
    const cash = parseFloat((grand * cashPercent / 100).toFixed(2));
    const upi  = parseFloat((grand - cash).toFixed(2));
    document.getElementById('cashAmount').value = cash;
    document.getElementById('upiAmount').value  = upi;
    document.getElementById('hiddenCashPaid').value = cash;
    document.getElementById('hiddenUpiPaid').value  = upi;
    document.getElementById('paidAmount').value = grand.toFixed(2);
    updateSplitDisplay();
    updateBalanceFromPart();
}

function updateSplitDisplay() {
    const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
    const cash  = parseFloat(document.getElementById('cashAmount').value) || 0;
    const upi   = parseFloat(document.getElementById('upiAmount').value)  || 0;
    const paid  = cash + upi;
    const remaining = Math.max(0, grand - paid);
    const fmt = v => '\u20B9' + v.toLocaleString('en-IN', {minimumFractionDigits:2});
    document.getElementById('splitGrandTotal').textContent  = fmt(grand);
    document.getElementById('splitCashDisplay').textContent = fmt(cash);
    document.getElementById('splitUpiDisplay').textContent  = fmt(upi);
    document.getElementById('splitPaidTotal').textContent   = fmt(paid);
    document.getElementById('splitRemaining').textContent   = fmt(remaining);
    const cashPct = grand > 0 ? Math.min(100, (cash / grand) * 100) : 0;
    const upiPct  = grand > 0 ? Math.min(100, (upi  / grand) * 100) : 0;
    document.getElementById('splitProgressCash').style.width = cashPct + '%';
    document.getElementById('splitProgressUpi').style.width  = upiPct  + '%';
    const badge = document.getElementById('splitStatusBadge');
    if(paid <= 0) {
        badge.classList.add('hidden');
    } else if(remaining <= 0.01) {
        badge.classList.remove('hidden');
        badge.style.cssText = 'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;display:block;text-align:center;padding:8px;border-radius:8px;font-size:12px;font-weight:700;';
        badge.innerHTML = '\u2705 Fully Paid \u2014 Cash ' + fmt(cash) + ' + UPI ' + fmt(upi);
    } else {
        badge.classList.remove('hidden');
        badge.style.cssText = 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;display:block;text-align:center;padding:8px;border-radius:8px;font-size:12px;font-weight:700;';
        badge.innerHTML = '\u26A0\uFE0F Balance Remaining: ' + fmt(remaining);
    }
}

// Part Payment & Advance Booking
function togglePartPayment() {
    const status = document.getElementById('paymentStatus').value;
    const method = document.getElementById('paymentMethod').value;
    const partDiv = document.getElementById('partAmountDiv');
    const balDiv  = document.getElementById('balanceDisplay');
    const advDiv  = document.getElementById('advanceBookingDiv');
    const submitBtn = document.getElementById('submitBtn');
    const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;

    if (status === 'advance') {
        if (advDiv) advDiv.style.display = 'block';
        if (partDiv) partDiv.style.display = 'none';
        if (balDiv) balDiv.style.display = 'none';
        if (submitBtn) submitBtn.innerHTML = '✨ Generate Advance Credit Receipt ✨';
        updateReminderButtonVisibility();
        return;
    } else {
        if (advDiv) advDiv.style.display = 'none';
        if (submitBtn) submitBtn.innerHTML = '✨ Generate Invoice ✨';
    }

    if(method === 'Split') {
        const cash = parseFloat(document.getElementById('cashAmount').value) || 0;
        const upi  = parseFloat(document.getElementById('upiAmount').value)  || 0;
        const paid = cash + upi;
        const remaining = Math.max(0, grand - paid);
        if(remaining > 0) {
            partDiv.style.display = 'block';
            balDiv.style.display  = 'block';
            document.getElementById('balanceAmt').textContent = '\u20B9' + remaining.toFixed(2);
            document.getElementById('paidAmount').value = paid.toFixed(2);
            if(status === 'paid' && paid > 0) {
                document.getElementById('paymentStatus').value = 'part';
            }
        } else {
            partDiv.style.display = 'none';
            balDiv.style.display  = 'none';
        }
        updateReminderButtonVisibility();
        return;
    }
    if(status === 'part') {
        partDiv.style.display = 'block';
        balDiv.style.display  = 'block';
    } else if(status === 'unpaid') {
        partDiv.style.display = 'none';
        balDiv.style.display  = 'block';
        document.getElementById('balanceAmt').textContent = '\u20B9' + grand.toFixed(2);
    } else {
        partDiv.style.display = 'none';
        balDiv.style.display  = 'none';
    }
    updateReminderButtonVisibility();
}

// --- ADVANCE CUSTOMER DROPDOWN & AUTO-COMPLETE ---

function onAdvanceCustomerSelectChange(advId) {

    const sel = document.getElementById('advanceCustomerDropdownSelect');
    if (!advId) {
        clearCustomerAdvanceCredit();
        return;
    }

    let customerData = null;

    if (sel && sel.selectedIndex >= 0) {
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.dataset && opt.dataset.name) {
            customerData = {
                id: advId,
                customer_name: opt.dataset.name,
                customer_mobile: opt.dataset.mobile,
                customer_address: opt.dataset.address,
                available_amount: parseFloat(opt.dataset.available) || 0,
                advance_amount: parseFloat(opt.dataset.amount) || 0,
                receipt_no: opt.dataset.receipt
            };
        }
    }

    if (!customerData && Array.isArray(allAdvanceCustomers)) {
        customerData = allAdvanceCustomers.find(c => String(c.id) === String(advId));
    }

    if (customerData) {
        const nameInput = document.getElementById('customerName');
        const mobileInput = document.getElementById('customerMobile');
        const addressInput = document.getElementById('customerAddress');

        if (nameInput) nameInput.value = customerData.customer_name || '';
        if (mobileInput) mobileInput.value = customerData.customer_mobile || '';
        if (addressInput && customerData.customer_address) {
            addressInput.value = customerData.customer_address;
        }

        showCustomerAdvanceCredit(customerData);
    }
}

function loadAdvanceCustomers() {
    fetch('billing.php?action=get_advance_customers')
        .then(r => r.json())
        .then(data => {
            if (data.success && Array.isArray(data.customers)) {
                allAdvanceCustomers = data.customers;
                const select = document.getElementById('advanceCustomerDropdownSelect');
                if (select) {
                    select.innerHTML = '<option value="" style="background:#fff;color:#1c1917;">-- ⚡ Select Advance Customer (' + allAdvanceCustomers.length + ' Active) --</option>';
                    allAdvanceCustomers.forEach(c => {
                        const opt = document.createElement('option');
                        opt.value = c.id;
                        opt.dataset.name = c.customer_name || '';
                        opt.dataset.mobile = c.customer_mobile || '';
                        opt.dataset.address = c.customer_address || '';
                        opt.dataset.available = c.available_amount || 0;
                        opt.dataset.amount = c.advance_amount || c.available_amount || 0;
                        opt.dataset.receipt = c.receipt_no || '';
                        opt.style.background = '#fff';
                        opt.style.color = '#1c1917';
                        opt.textContent = '👤 ' + c.customer_name + ' (₹' + parseFloat(c.available_amount).toLocaleString('en-IN') + ' Avail) - ' + (c.customer_mobile || '');
                        select.appendChild(opt);
                    });
                }
            }
        }).catch(err => console.log('Error loading advance customers:', err));
}



let customerInputTimer = null;
function onCustomerDetailsInput() {
    clearTimeout(customerInputTimer);
    customerInputTimer = setTimeout(() => {
        const mob = document.getElementById('customerMobile').value.trim();
        const name = document.getElementById('customerName').value.trim();
        
        // Check in preloaded advance list first
        const match = allAdvanceCustomers.find(c => 
            (mob.length >= 4 && (c.customer_mobile || '').includes(mob)) ||
            (name.length >= 3 && (c.customer_name || '').toLowerCase() === name.toLowerCase())
        );

        if (match) {
            showCustomerAdvanceCredit(match);
            const sel = document.getElementById('advanceCustomerDropdownSelect');
            if (sel) sel.value = match.id;
            return;
        }

        if (mob.length >= 4 || name.length >= 3) {
            fetch('billing.php?action=check_customer_advance&mobile=' + encodeURIComponent(mob) + '&name=' + encodeURIComponent(name))
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.has_advance && data.advance) {
                        showCustomerAdvanceCredit(data.advance);
                        const sel = document.getElementById('advanceCustomerDropdownSelect');
                        if (sel) sel.value = data.advance.id;
                    } else if (!currentAdvanceId) {
                        clearCustomerAdvanceCredit();
                    }
                }).catch(() => {});
        } else if (!currentAdvanceId) {
            clearCustomerAdvanceCredit();
        }
    }, 300);
}


function showCustomerAdvanceCredit(adv) {
    currentCustomerAdvance = parseFloat(adv.available_amount) || 0;
    currentAdvanceId = parseInt(adv.id) || 0;
    currentAdvanceReceipt = adv.receipt_no || '';

    const banner = document.getElementById('advanceCreditCustomerBanner');
    if (banner) {
        banner.classList.remove('hidden');
        document.getElementById('bannerAdvAmount').textContent = '₹' + currentCustomerAdvance.toLocaleString('en-IN', {minimumFractionDigits:2});
        document.getElementById('bannerAdvReceipt').textContent = currentAdvanceReceipt;
        document.getElementById('bannerAdvTotal').textContent = '₹' + parseFloat(adv.advance_amount || currentCustomerAdvance).toLocaleString('en-IN', {minimumFractionDigits:2});
    }

    const container = document.getElementById('advanceAdjustmentContainer');
    if (container && currentCustomerAdvance > 0) {
        container.style.display = 'block';
        document.getElementById('advAvailableBadge').textContent = '₹' + currentCustomerAdvance.toLocaleString('en-IN', {minimumFractionDigits:2});
        const fullBtn = document.getElementById('btnAdvFull');
        if (fullBtn) fullBtn.textContent = '⚡ Take Full Advance (₹' + Math.round(currentCustomerAdvance).toLocaleString('en-IN') + ')';
        setAdvanceAdjustmentMode('full');
    }
}

function clearCustomerAdvanceCredit() {
    currentCustomerAdvance = 0;
    currentAdvanceId = 0;
    currentAdvanceReceipt = '';
    advanceAdjustmentMode = 'none';
    appliedAdvanceAmount = 0;
    const banner = document.getElementById('advanceCreditCustomerBanner');
    if (banner) banner.classList.add('hidden');
    const container = document.getElementById('advanceAdjustmentContainer');
    if (container) container.style.display = 'none';
    if (document.getElementById('advanceAdjustedRow')) document.getElementById('advanceAdjustedRow').style.display = 'none';
    calculateTotal();
}

function setAdvanceAdjustmentMode(mode) {
    advanceAdjustmentMode = mode;
    const btnFull = document.getElementById('btnAdvFull');
    const btnCust = document.getElementById('btnAdvCustom');
    const custDiv = document.getElementById('advCustomInputDiv');

    if (mode === 'full') {
        if (btnFull) btnFull.className = 'py-2 px-2.5 rounded-lg font-extrabold border transition bg-amber-700 text-white border-amber-800 shadow-sm text-center text-xs hover:bg-amber-800 cursor-pointer';
        if (btnCust) btnCust.className = 'py-2 px-2.5 rounded-lg font-extrabold border transition bg-white text-stone-800 border-amber-300 text-center text-xs hover:bg-amber-50 cursor-pointer';
        if (custDiv) custDiv.style.display = 'none';
    } else if (mode === 'custom') {
        if (btnCust) btnCust.className = 'py-2 px-2.5 rounded-lg font-extrabold border transition bg-amber-700 text-white border-amber-800 shadow-sm text-center text-xs hover:bg-amber-800 cursor-pointer';
        if (btnFull) btnFull.className = 'py-2 px-2.5 rounded-lg font-extrabold border transition bg-white text-stone-800 border-amber-300 text-center text-xs hover:bg-amber-50 cursor-pointer';
        if (custDiv) {
            custDiv.style.display = 'flex';
            const input = document.getElementById('advCustomAmountInput');
            if (input && (!input.value || parseFloat(input.value) <= 0)) input.value = currentCustomerAdvance;
        }
    } else {
        if (btnFull) btnFull.className = 'py-2 px-2.5 rounded-lg font-extrabold border transition bg-white text-stone-800 border-amber-300 text-center text-xs hover:bg-amber-50 cursor-pointer';
        if (btnCust) btnCust.className = 'py-2 px-2.5 rounded-lg font-extrabold border transition bg-white text-stone-800 border-amber-300 text-center text-xs hover:bg-amber-50 cursor-pointer';
        if (custDiv) custDiv.style.display = 'none';
    }
    calculateTotal();
}

function onCustomAdvanceInput() {
    if (advanceAdjustmentMode === 'custom') {
        calculateTotal();
    }
}

function onAdvSurplusActionChange(val) {
    if (document.getElementById('hiddenAdvSurplusAction')) {
        document.getElementById('hiddenAdvSurplusAction').value = val;
    }
    const lblKeep = document.getElementById('lblSurplusKeep');
    const lblRefund = document.getElementById('lblSurplusRefund');
    if (val === 'refund_customer') {
        if (lblRefund) lblRefund.className = 'flex items-center gap-1.5 p-2 rounded-lg border-2 border-rose-600 bg-rose-50 cursor-pointer font-bold text-[11px] text-rose-950';
        if (lblKeep) lblKeep.className = 'flex items-center gap-1.5 p-2 rounded-lg border border-stone-300 bg-white cursor-pointer font-bold text-[11px] text-stone-700 hover:bg-amber-50';
    } else {
        if (lblKeep) lblKeep.className = 'flex items-center gap-1.5 p-2 rounded-lg border-2 border-amber-700 bg-amber-200/80 cursor-pointer font-bold text-[11px] text-amber-950';
        if (lblRefund) lblRefund.className = 'flex items-center gap-1.5 p-2 rounded-lg border border-stone-300 bg-white cursor-pointer font-bold text-[11px] text-stone-700 hover:bg-amber-50';
    }
    calculateTotal();
}

function onAdvRefundModeChange(val) {
    if (document.getElementById('hiddenAdvRefundMode')) {
        document.getElementById('hiddenAdvRefundMode').value = val;
    }
    calculateTotal();
}

function scrollAndFocusAdvanceAdjustment() {
    const el = document.getElementById('advanceAdjustmentContainer');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.style.transition = 'box-shadow 0.3s ease';
        el.style.boxShadow = '0 0 0 4px rgba(217,119,6,0.5)';
        setTimeout(() => el.style.boxShadow = '', 1500);
    }
}

function onAdvanceDepositInput() {
    const val = parseFloat(document.getElementById('advanceDepositInput')?.value) || 0;
    if (document.getElementById('paidAmount')) {
        document.getElementById('paidAmount').value = val > 0 ? val : '';
    }
}


function updateDueDateHint() {
    const val = document.getElementById('dueDate').value;
    const hint = document.getElementById('dueDateHint');
    const text = document.getElementById('dueDateText');
    if(!val) { hint.classList.add('hidden'); return; }
    const d = new Date(val + 'T00:00:00');
    const today = new Date(); today.setHours(0,0,0,0);
    const diff = Math.round((d - today) / (1000 * 60 * 60 * 24));
    if(diff < 0) {
        text.textContent = 'Date is in the past \u2014 please select a future date.';
        hint.style.color = '#dc2626';
    } else if(diff === 0) {
        text.textContent = 'Due today!';
        hint.style.color = '#d97706';
    } else {
        text.textContent = 'Customer expected to pay in ' + diff + ' day(s) \u2014 on ' +
            d.toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'});
        hint.style.color = '#059669';
    }
    hint.classList.remove('hidden');
}

function updateBalanceFromPart() {
    const method = document.getElementById('paymentMethod').value;
    const status = document.getElementById('paymentStatus').value;
    const grand  = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
    const partDiv = document.getElementById('partAmountDiv');
    const balDiv  = document.getElementById('balanceDisplay');
    let balance = 0;

    if(method === 'Split') {
        const cash = parseFloat(document.getElementById('cashAmount').value) || 0;
        const upi  = parseFloat(document.getElementById('upiAmount').value)  || 0;
        const paid = cash + upi;
        const remaining = Math.max(0, grand - paid);
        balance = remaining;
        if(remaining > 0) {
            partDiv.style.display = 'block';
            balDiv.style.display  = 'block';
            document.getElementById('paidAmount').value = paid.toFixed(2);
            document.getElementById('balanceAmt').textContent = '\u20B9' + remaining.toFixed(2);
            if(status === 'paid' && paid > 0) {
                document.getElementById('paymentStatus').value = 'part';
            }
        } else {
            partDiv.style.display = 'none';
            balDiv.style.display  = 'none';
        }
    } else {
        if(status === 'part') {
            const paid = parseFloat(document.getElementById('paidAmount').value) || 0;
            balance = Math.max(0, grand - paid);
            document.getElementById('balanceAmt').textContent = '\u20B9' + balance.toFixed(2);
            partDiv.style.display = 'block';
            balDiv.style.display  = 'block';
        } else if(status === 'unpaid') {
            balance = grand;
            document.getElementById('balanceAmt').textContent = '\u20B9' + balance.toFixed(2);
            partDiv.style.display = 'none';
            balDiv.style.display  = 'block';
        } else {
            balance = 0;
            partDiv.style.display = 'none';
            balDiv.style.display  = 'none';
        }
    }
    updateReminderButtonVisibility();
}

function updateReminderButtonVisibility() {
    const status = document.getElementById('paymentStatus').value;
    const method = document.getElementById('paymentMethod').value;
    const email = document.getElementById('customerEmail').value.trim();
    const mobile = document.getElementById('customerMobile').value.trim();
    const balanceText = document.getElementById('balanceAmt').textContent.replace(/\u20B9|,/g, '');
    const balance = parseFloat(balanceText) || 0;
    const button = document.getElementById('reminderButton');
    if((status === 'part' || status === 'unpaid' || (method === 'Split' && balance > 0)) && balance > 0 && (email !== '' || mobile !== '')) {
        button.style.display = 'inline-flex';
    } else {
        button.style.display = 'none';
    }
}

function sendPaymentReminder() {
    const email = document.getElementById('customerEmail').value.trim();
    const name = document.getElementById('customerName').value.trim() || 'Customer';
    const mobile = document.getElementById('customerMobile').value.trim();
    const invoiceNo = document.getElementById('manualInvoiceNo').value.trim();
    const balanceText = document.getElementById('balanceAmt').textContent.replace(/\u20B9|,/g, '');
    const balance = parseFloat(balanceText) || 0;
    if(balance <= 0) { alert('There is no due amount to remind.'); return; }
    if(email === '' && mobile === '') { alert('Please enter customer email or mobile number to send the reminder.'); return; }
    const payload = new URLSearchParams();
    payload.append('customer_email', email);
    payload.append('customer_name', name);
    payload.append('customer_mobile', mobile);
    payload.append('invoice_no', invoiceNo);
    payload.append('balance_amount', balance.toFixed(2));
    fetch(window.location.pathname + '?action=send_reminder', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: payload.toString(),
        credentials: 'same-origin'
    })
    .then(async response => {
        const text = await response.text();
        if(!response.ok) throw new Error(text || 'HTTP ' + response.status);
        try { return JSON.parse(text); } catch(err) { throw new Error('Invalid JSON response: ' + text); }
    })
    .then(data => {
        if(data.success) {
            btn.textContent = '✔ Sent';
            btn.classList.remove('bg-amber-600','hover:bg-amber-700');
            btn.classList.add('bg-emerald-600','cursor-default');
            showNotif('Reminder email sent to ' + customerName + ' (' + invoiceNo + ')', 'success');
        } else {
            btn.disabled = false;
            btn.textContent = '❌ Failed';
            showNotif('❌ ' + (data.message || 'Failed to send reminder email'), 'error');
            setTimeout(() => { btn.textContent = '✉ Remind'; }, 3000);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = '❌ Error';
        showNotif('❌ ' + (err.message || 'Failed to send reminder'), 'error');
        setTimeout(() => { btn.textContent = '✉ Remind'; }, 3000);
    });
}

//  NEW: Due Today - Send Reminder 
function sendDueReminder(invoiceNo, customerName, customerMobile, balanceAmount) {
    const btn = document.getElementById('remind-btn-' + invoiceNo);
    if(!btn) return;
    btn.disabled = true;
    btn.textContent = '\u23F3 Sending...';

    const payload = new URLSearchParams();
    payload.append('customer_name', customerName);
    payload.append('customer_mobile', customerMobile);
    payload.append('invoice_no', invoiceNo);
    payload.append('balance_amount', balanceAmount.toFixed(2));

    fetch(window.location.pathname + '?action=send_reminder', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: payload.toString(),
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            const card = document.getElementById('duecard-' + invoiceNo);
            if(card) {
                card.style.transition = 'opacity 0.3s, height 0.3s, margin 0.3s, padding 0.3s';
                card.style.opacity = '0';
                card.style.height = '0';
                card.style.margin = '0';
                card.style.padding = '0';
                setTimeout(() => {
                    card.remove();
                    hideDueTodaySectionIfEmpty();
                }, 300);
            } else {
                hideDueTodaySectionIfEmpty();
            }
            showNotif(data.message, 'success');
        } else {
            btn.disabled = false;
            btn.textContent = '\uD83D\uDCE7 Send Reminder';
            showNotif(data.message || 'Could not send reminder.', 'error');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = '\uD83D\uDCE7 Send Reminder';
        showNotif('Network error. Please try again.', 'error');
    });
}

function hideDueTodaySectionIfEmpty() {
    const section = document.querySelector('.due-today-section');
    if(!section) return;
    const grid = section.querySelector('.due-today-grid');
    if(!grid) return;
    if(grid.querySelectorAll('.due-card').length === 0) {
        section.style.display = 'none';
    }
}

function closeDueTodaySection() {
    const section = document.querySelector('.due-today-section');
    if(!section) return;
    section.style.transition = 'opacity 0.3s, height 0.3s, margin 0.3s, padding 0.3s';
    section.style.opacity = '0';
    section.style.height = '0';
    section.style.margin = '0';
    section.style.padding = '0';
    setTimeout(() => section.style.display = 'none', 300);
}

// Notification helper
function showNotif(msg, type) {
    const d = document.createElement('div');
    d.style.cssText = 'position:fixed;top:80px;right:20px;padding:12px 18px;border-radius:8px;font-size:12px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,0.15);animation:notifSlide 0.3s ease;background:' + (type==='success'?'#d1fae5':'#fef3c7') + ';color:' + (type==='success'?'#065f46':'#92400e') + ';border:1px solid ' + (type==='success'?'#6ee7b7':'#fcd34d') + ';max-width:280px;';
    d.innerHTML = msg;
    document.body.appendChild(d);
    setTimeout(() => { d.style.opacity='0'; d.style.transition='opacity 0.3s'; setTimeout(()=>d.remove(),300); }, 3500);
}

// Mobile search
function searchBillsByMobile() {
    const mobile = document.getElementById('searchMobile').value.trim();
    if(mobile.length < 5) { alert('Please enter at least 5 digits!'); return; }
    const resultsDiv = document.getElementById('searchResults');
    const contentDiv = document.getElementById('searchResultsContent');
    contentDiv.innerHTML = '<p style="color:#b5730e;font-size:13px;">\uD83D\uDD0D Searching...</p>';
    resultsDiv.classList.remove('hidden');
    fetch('billing.php?action=search_mobile&mobile=' + encodeURIComponent(mobile))
        .then(r => r.json())
        .then(data => {
            if(!data.success) { contentDiv.innerHTML = '<p style="color:#dc2626;">\u274C ' + data.message + '</p>'; return; }
            if(data.count === 0) { contentDiv.innerHTML = '<p style="color:#d97706;">\u26A0\uFE0F No bills found for: <strong>' + mobile + '</strong></p>'; return; }
            let html = '<p style="color:#059669;font-size:13px;margin-bottom:12px;">\u2705 <strong>' + data.count + '</strong> bill(s) found for <strong>' + data.bills[0].customer_name + '</strong></p>';
            html += '<div class="overflow-x-auto"><table style="width:100%;border-collapse:collapse;font-size:12px;">' +
                '<thead><tr style="background:linear-gradient(135deg,#7a4e0a,#d68b16);">' +
                '<th style="padding:8px;color:#fff;text-align:left;">Invoice No</th>' +
                '<th style="padding:8px;color:#fff;text-align:left;">Customer</th>' +
                '<th style="padding:8px;color:#fff;text-align:left;">Amount</th>' +
                '<th style="padding:8px;color:#fff;text-align:left;">Date</th>' +
                '<th style="padding:8px;color:#fff;text-align:center;">Action</th>' +
                '</tr></thead><tbody>';
            data.bills.forEach((bill,i) => {
                const date = new Date(bill.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
                html += '<tr style="background:' + (i%2===0?'#fdf6e3':'#f5ead0') + ';border-bottom:1px solid rgba(181,115,14,0.15);">' +
                    '<td style="padding:8px;color:#7a4e0a;font-weight:600;">' + bill.invoice_no + '</td>' +
                    '<td style="padding:8px;color:#374151;">' + bill.customer_name + '<br><small style="color:#9ca3af;">' + bill.customer_mobile + '</small></td>' +
                    '<td style="padding:8px;color:#059669;font-weight:700;">\u20B9' + parseFloat(bill.total_amount).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</td>' +
                    '<td style="padding:8px;color:#6b7280;">' + date + '</td>' +
                    '<td style="padding:8px;text-align:center;">' +
                    '<a href="view_pdf.php?invoice_no=' + encodeURIComponent(bill.invoice_no) + '" target="_blank" ' +
                    'style="background:linear-gradient(135deg,#800020,#d68b16);color:#fff;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:bold;text-decoration:none;">\uD83D\uDDA8\uFE0F Print</a>' +
                    '</td></tr>';
            });
            html += '</tbody></table></div>';
            contentDiv.innerHTML = html;
        })
        .catch(() => { contentDiv.innerHTML = '<p style="color:#dc2626;">\u274C Network error. Please try again.</p>'; });
}

function clearSearch() {
    document.getElementById('searchMobile').value = '';
    document.getElementById('searchResults').classList.add('hidden');
    document.getElementById('searchResultsContent').innerHTML = '';
}

document.getElementById('searchMobile').addEventListener('keydown', e => { if(e.key === 'Enter') searchBillsByMobile(); });

// Handle Enter key across Billing Form
document.getElementById('billingForm').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        const target = e.target;
        if (!target) return;
        if (target.tagName === 'TEXTAREA') return;

        // If user is inside item entry fields (Gross Wt, Net Wt, Qty, Item Name, Making Charge, etc.), Enter adds item to bill
        const itemContainer = target.closest('#panelGram');
        if (itemContainer) {
            e.preventDefault();
            if (typeof submitCurrentItem === 'function') {
                submitCurrentItem();
            }
            return;
        }

        // If user is in Customer Name, Enter moves focus to Customer Mobile
        if (target.id === 'customerName') {
            e.preventDefault();
            document.getElementById('customerMobile')?.focus();
            return;
        }

        // If user is in Customer Mobile, Enter moves focus to product Gross Weight
        if (target.id === 'customerMobile') {
            e.preventDefault();
            document.getElementById('gramGrossWeight')?.focus() || target.blur();
            return;
        }

        // If user is in Discount, Old Gold, Advance, or Payment inputs, Enter triggers Invoice Generation with validation
        e.preventDefault();
        if (typeof calculateTotal === 'function') {
            calculateTotal();
        }

        if (items.length === 0) {
            alert('❌ Please add at least one product to the bill first!');
            document.getElementById('gramGrossWeight')?.focus() || document.getElementById('gramStockSearch')?.focus();
            return;
        }

        const custNameEl = document.getElementById('customerName');
        if (custNameEl && !custNameEl.value.trim()) {
            alert('❌ Please enter Customer Name!');
            custNameEl.focus();
            custNameEl.style.boxShadow = '0 0 0 3px rgba(220,38,38,0.4)';
            setTimeout(() => custNameEl.style.boxShadow = '', 2000);
            return;
        }

        const custMobEl = document.getElementById('customerMobile');
        if (custMobEl && !custMobEl.value.trim()) {
            alert('❌ Please enter Customer Mobile Number!');
            custMobEl.focus();
            custMobEl.style.boxShadow = '0 0 0 3px rgba(220,38,38,0.4)';
            setTimeout(() => custMobEl.style.boxShadow = '', 2000);
            return;
        }

        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.click();
        }
    }
});

// Form submit validation
let isBillingFormSubmitting = false;
document.getElementById('billingForm').addEventListener('submit', function(e) {
    if (isBillingFormSubmitting) {
        e.preventDefault();
        return false;
    }

    // Force recalculate totals and sync all hidden inputs right before submission
    if (typeof calculateTotal === 'function') {
        calculateTotal();
    }

    const paymentStatusVal = document.getElementById('paymentStatus').value;
    const advDepositVal = parseFloat(document.getElementById('advanceDepositInput')?.value) || 0;

    // --- CASE 1: ADVANCE CREDIT BOOKING DEPOSIT (ONLY WHEN NO ITEMS ARE ADDED) ---
    if (items.length === 0 && (paymentStatusVal === 'advance' || advDepositVal > 0)) {
        const cName = document.getElementById('customerName')?.value.trim();
        const cMob = document.getElementById('customerMobile')?.value.trim();
        if (!cName) {
            e.preventDefault();
            alert('❌ Please enter Customer Name for Advance Credit booking!');
            document.getElementById('customerName')?.focus();
            return false;
        }
        if (!cMob) {
            e.preventDefault();
            alert('❌ Please enter Customer Mobile Number for Advance Credit booking!');
            document.getElementById('customerMobile')?.focus();
            return false;
        }
        if (advDepositVal <= 0) {
            e.preventDefault();
            alert('❌ Please enter the Advance Deposit Amount (₹)!');
            document.getElementById('advanceDepositInput')?.focus();
            return false;
        }

        isBillingFormSubmitting = true;
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.style.pointerEvents = 'none';
            submitBtn.style.opacity = '0.7';
            submitBtn.style.cursor = 'not-allowed';
            submitBtn.innerHTML = '⏳ Generating Advance Receipt...';
        }

        try { sessionStorage.removeItem('billing_cart_items'); } catch(err) {}
        document.getElementById('hiddenIsAdvanceBooking').value = '1';
        document.getElementById('hiddenAdvanceDepositAmount').value = advDepositVal;
        document.getElementById('hiddenBookingNotes').value = document.getElementById('advanceBookingNotes')?.value.trim() || '';
        document.getElementById('hiddenItems').value = '[]';
        return true;
    }

    // --- CASE 2: REGULAR INVOICE BILLING (WITH ITEMS) ---
    if(items.length === 0) { 
        e.preventDefault(); 
        alert('❌ Please add at least one product to the bill!'); 
        return false; 
    }

    const custName = document.getElementById('customerName')?.value.trim();
    if(!custName) { 
        e.preventDefault(); 
        alert('❌ Please enter Customer Name before generating invoice!'); 
        const el = document.getElementById('customerName');
        if (el) {
            el.focus();
            el.style.boxShadow = '0 0 0 3px rgba(220,38,38,0.4)';
            setTimeout(() => el.style.boxShadow = '', 2000);
        }
        return false; 
    }

    const custMob = document.getElementById('customerMobile')?.value.trim();
    if(!custMob) { 
        e.preventDefault(); 
        alert('❌ Please enter Customer Mobile Number before generating invoice!'); 
        const el = document.getElementById('customerMobile');
        if (el) {
            el.focus();
            el.style.boxShadow = '0 0 0 3px rgba(220,38,38,0.4)';
            setTimeout(() => el.style.boxShadow = '', 2000);
        }
        return false; 
    }

    document.getElementById('hiddenIsAdvanceBooking').value = '0';
    document.getElementById('hiddenItems').value = JSON.stringify(buildItemsForSubmit());

    // Aggregate requested stock pieces per product_id
    const reqPcsMap = {};
    for (let i = 0; i < items.length; i++) {
        const item = items[i];
        if (item.product_id && item.product_id !== 'other' && !isNaN(item.product_id)) {
            const pid = String(item.product_id);
            const reqPcs = parseFloat(item.pcs) || parseFloat(item.stock_deduct) || 1;
            reqPcsMap[pid] = (reqPcsMap[pid] || 0) + reqPcs;
        }
    }

    for (const pid in reqPcsMap) {
        const reqPcs = reqPcsMap[pid];
        const prod = ALL_PRODUCTS.find(p => String(p.id) === pid);
        if (prod) {
            const availQty = parseFloat(prod.quantity) || 0;
            const pname = prod.item_name || prod.name;
            if (availQty <= 0) {
                e.preventDefault();
                alert('\u274C Cannot complete invoice!\nProduct "' + pname + '" is OUT OF STOCK.');
                return false;
            }
            if (reqPcs > availQty) {
                e.preventDefault();
                alert('\u274C Cannot complete invoice!\nProduct "' + pname + '" has only ' + availQty + ' pcs in stock, but ' + reqPcs + ' pcs requested across your items list.');
                return false;
            }
        }
    }

    if(document.getElementById('paymentMethod').value === 'Split') {
        const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
        const cash  = parseFloat(document.getElementById('cashAmount').value) || 0;
        const upi   = parseFloat(document.getElementById('upiAmount').value)  || 0;
        if(cash + upi > grand + 0.5) { e.preventDefault(); alert('\u26A0\uFE0F Split total (\u20B9' + (cash+upi).toFixed(2) + ') cannot exceed Grand Total (\u20B9' + grand.toFixed(2) + ')!'); return false; }
    }

    isBillingFormSubmitting = true;
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.style.pointerEvents = 'none';
        submitBtn.style.opacity = '0.7';
        submitBtn.style.cursor = 'not-allowed';
        submitBtn.innerHTML = '⏳ Generating Invoice...';
    }

    try { sessionStorage.removeItem('billing_cart_items'); } catch(err) {}
    document.getElementById('hiddenItems').value = JSON.stringify(buildItemsForSubmit());
});


// Init
loadShopRates();
loadAdvanceCustomers();

// Restore in-progress cart items if page reloaded
try {
    const savedCart = sessionStorage.getItem('billing_cart_items');
    if (savedCart) {
        const parsed = JSON.parse(savedCart);
        if (Array.isArray(parsed) && parsed.length > 0 && items.length === 0) {
            items = parsed;
        }
    }
} catch(e) {}

updateItemsList();
calculateTotal();
populateHuidOptions();
updateReminderButtonVisibility();
document.getElementById('customerEmail').addEventListener('input', updateReminderButtonVisibility);
document.getElementById('dueDate').addEventListener('change', updateDueDateHint);
document.getElementById('customerMobile').addEventListener('input', updateReminderButtonVisibility);
document.getElementById('paymentStatus').addEventListener('change', updateReminderButtonVisibility);
if (typeof filterGramStock === 'function') { filterGramStock(''); }
document.addEventListener('DOMContentLoaded', function() {
    if (typeof filterGramStock === 'function') { filterGramStock(''); }
    loadAdvanceCustomers();
});
window.addEventListener('load', function() {
    if (typeof filterGramStock === 'function') { filterGramStock(''); }
    loadAdvanceCustomers();
});
</script>
</body>
</html>

<?php
function convertNumberToWords($number) {
    if($number <= 0) return 'Zero Rupees Only';
    $amount = round($number);
    $rupees = (int)$amount;
    $words = [
        0=>'',1=>'One',2=>'Two',3=>'Three',4=>'Four',5=>'Five',6=>'Six',7=>'Seven',8=>'Eight',9=>'Nine',
        10=>'Ten',11=>'Eleven',12=>'Twelve',13=>'Thirteen',14=>'Fourteen',15=>'Fifteen',16=>'Sixteen',
        17=>'Seventeen',18=>'Eighteen',19=>'Nineteen',20=>'Twenty',30=>'Thirty',40=>'Forty',50=>'Fifty',
        60=>'Sixty',70=>'Seventy',80=>'Eighty',90=>'Ninety'
    ];
    $result = '';
    if($rupees >= 10000000) { $c=floor($rupees/10000000); $result.=($c<20?$words[$c]:$words[floor($c/10)*10].($c%10?' '.$words[$c%10]:'')).' Crore '; $rupees%=10000000; }
    if($rupees >= 100000)   { $c=floor($rupees/100000);   $result.=($c<20?$words[$c]:$words[floor($c/10)*10].($c%10?' '.$words[$c%10]:'')).' Lakh ';  $rupees%=100000; }
    if($rupees >= 1000)     { $c=floor($rupees/1000);     $result.=($c<20?$words[$c]:$words[floor($c/10)*10].($c%10?' '.$words[$c%10]:'')).' Thousand '; $rupees%=1000; }
    if($rupees >= 100)      { $result.=$words[floor($rupees/100)].' Hundred '; $rupees%=100; }
    if($rupees > 0) {
        if($rupees < 20) $result.=$words[$rupees].' ';
        else $result.=$words[floor($rupees/10)*10].($rupees%10?' '.$words[$rupees%10]:'').' ';
    }
    return trim($result).' Rupees Only';
}
?>








