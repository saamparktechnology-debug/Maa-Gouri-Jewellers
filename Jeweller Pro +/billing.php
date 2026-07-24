<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';
require_once 'config/mail_config.php';

$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

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

    $subject = 'Payment Reminder from MOTI JEWELLERS';
    $invoice_text = $invoice_no ? 'Invoice No: ' . htmlspecialchars($invoice_no) . '<br>' : '';
    $message = '<p>Dear ' . htmlspecialchars($customer_name) . ',</p>' .
               '<p>This is a reminder that an amount of <strong>&#8377;' . number_format($balance_amount, 2) . '</strong> is still due.' .
               ($invoice_no ? ' Please refer to ' . htmlspecialchars($invoice_no) . '.' : '') . '</p>' .
               '<p>Please make the remaining payment at your earliest convenience.</p>' .
               '<p>Thank you,<br>MOTI JEWELLERS</p>';
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

// ── NEW: AJAX: Mark invoice as paid (partial or full custom amount) ───────
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

$logo_paths = ['logo.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];

// Fetch products from DB
$all_products = [];
$products_result = mysqli_query($conn, "SELECT id, name, item_name, serial_no, category, price, quantity, huid_code FROM products ORDER BY category, item_name, name");
if($products_result) {
    while($p = mysqli_fetch_assoc($products_result)) {
        $all_products[] = $p;
    }
}

// Build item type options
$itemTypeOptions = [
    'Gold 22K' => [],
    'Gold 18K' => [],
    'Silver'   => [],
    'Stone'    => [],
    'Diamond'  => [],
    'Others'   => []
];
$categories = ['Gold 22K', 'Gold 18K', 'Silver', 'Stone', 'Diamond'];
foreach ($categories as $cat) {
    $safeCat = mysqli_real_escape_string($conn, $cat);
    $res = mysqli_query($conn, "SELECT DISTINCT item_name FROM products WHERE category = '$safeCat' AND item_name != '' ORDER BY item_name");
    if($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            if (!empty($row['item_name'])) {
                $itemTypeOptions[$cat][] = $row['item_name'];
            }
        }
    }
}

// Gold 22K & 18K items — as specified by shop owner
$goldItems = ['Necklace','Chur','Bala','Chain','Tops','Single Loket','Double Loket','Churi','Jhuladul','Jhumka','Ladies Ring','Gold Choker','Gents Ring','Gents Breslet','Ladies Breslet','Tika','Takti','Mantasa','Pearl Choker','Bauti Chur','Soket Bauti','Breslet Noya','Stell Noya','Baby Ring','Bali','Pitaring','Baby Breslet','Pearl Sitahar','Nose Pin','Other'];
$itemTypeOptions['Gold 22K'] = array_unique(array_merge($itemTypeOptions['Gold 22K'], $goldItems));
$itemTypeOptions['Gold 18K'] = array_unique(array_merge($itemTypeOptions['Gold 18K'], $goldItems));

$itemTypeOptions['Silver']   = array_unique(array_merge($itemTypeOptions['Silver'],
 ['Thali','Bati','Glass','Spoon','Showpiece','B.B.C Silver','Mix Silver','Other']));
$itemTypeOptions['Stone']    = array_unique(array_merge($itemTypeOptions['Stone'],
 ['Natural Pearl','Gomed','Red Coral','Nila','Panna','Jerkon','Amethist','Cats Eye','Other']));
$itemTypeOptions['Diamond']  = array_unique(array_merge($itemTypeOptions['Diamond'],
  ['Ladies Ring','Gents Ring','Tops','Mangal Sutra','Nose Pin','Necklace','Other']));
$itemTypeOptions['Others']   = array_unique(array_merge($itemTypeOptions['Others'],
  ['Shankha','Pala','Mala','Moti Mala','Trasel','Branch Fram','Braslate Pala',
  'Parl Mala','Gala','Reparing','Stamp Charg','Other']));
// ── NEW: Fetch due-today payments ─────────────────────────────────────────
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

// Handle billing submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_invoice'])) {
    $customer_name    = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $customer_mobile  = mysqli_real_escape_string($conn, $_POST['customer_mobile']);
    $customer_address = mysqli_real_escape_string($conn, $_POST['customer_address'] ?? '');
    $customer_email   = mysqli_real_escape_string($conn, $_POST['customer_email'] ?? '');
    $raw_gst_type     = strtolower(trim($_POST['gst_type'] ?? 'non_gst'));
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
    
    // The $subtotal from JS already includes all per-item making charges, hallmarks, and discounts.
    // Old Gold is deducted after tax so GST is calculated strictly on new items subtotal.
    $subtotal_before_tax = $subtotal + $pola;
    $total_before_round  = $subtotal_before_tax + $gst_amount - $old_gold_amount;
    $total_amount  = max(0, round($total_before_round));
    $round_off     = $total_amount - $total_before_round;

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
        $invoice_no = 'INV-' . date('Ymd') . '-' . rand(1000, 9999);
    }
    $customer_gstin = mysqli_real_escape_string($conn, $_POST['customer_gstin'] ?? '');
    $huid_code = mysqli_real_escape_string($conn, trim($_POST['huid_code'] ?? ''));
    $created_by_val = (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) ? intval($_SESSION['user_id']) : "NULL";

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
    $col_og = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'old_gold_amount'")) > 0;
    if(!$col_og) mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN old_gold_amount DECIMAL(10,2) DEFAULT 0");

    // Auto-drop foreign key constraints on invoice_items to allow zero-stock product auto-deletion
    $fk_check = mysqli_query($conn, "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_items' AND REFERENCED_TABLE_NAME IS NOT NULL");
    if ($fk_check) {
        while ($fk_row = mysqli_fetch_assoc($fk_check)) {
            $c_name = $fk_row['CONSTRAINT_NAME'];
            @mysqli_query($conn, "ALTER TABLE invoice_items DROP FOREIGN KEY `$c_name`");
        }
    }

    // ── STOCK VALIDATION: Pre-check available stock pieces before creating invoice ──
    $raw_items = json_decode($_POST['items'] ?? '[]', true);
    if (is_array($raw_items)) {
        $req_pcs_map = [];
        foreach ($raw_items as $it) {
            $pid = $it['product_id'] ?? '';
            if ($pid !== 'other' && is_numeric($pid)) {
                $pid_int = intval($pid);
                $pcs_req = floatval($it['pcs'] ?? $it['stock_deduct'] ?? 1);
                if ($pcs_req <= 0) $pcs_req = 1;
                $req_pcs_map[$pid_int] = ($req_pcs_map[$pid_int] ?? 0) + $pcs_req;
            }
        }
        foreach ($req_pcs_map as $pid_int => $total_pcs_req) {
            $st_res = mysqli_query($conn, "SELECT name, item_name, quantity FROM products WHERE id = $pid_int");
            if ($st_res && $st_row = mysqli_fetch_assoc($st_res)) {
                $avail_qty = floatval($st_row['quantity']);
                $p_title = !empty($st_row['item_name']) ? $st_row['item_name'] : $st_row['name'];
                if ($avail_qty <= 0) {
                    $error = "&#10007; Cannot create invoice! Product '$p_title' is OUT OF STOCK (0 pcs available).";
                    goto skip_invoice;
                }
                if ($total_pcs_req > $avail_qty) {
                    $error = "&#10007; Cannot create invoice! Product '$p_title' has only $avail_qty pcs in stock, but $total_pcs_req pcs requested.";
                    goto skip_invoice;
                }
            } else {
                $error = "&#10007; Cannot create invoice! Selected stock product (ID: $pid_int) was not found in stock.";
                goto skip_invoice;
            }
        }
    }

    $invoice_query = "INSERT INTO invoices (invoice_no, customer_name, customer_mobile, customer_address, customer_gstin, gst_type, subtotal, gst_amount, total_amount, payment_status, payment_method, paid_amount, balance_amount, cash_paid, upi_paid, account_paid, due_date, created_by, huid_code, old_gold_amount)
              VALUES ('$invoice_no', '$customer_name', '$customer_mobile', '$customer_address', '$customer_gstin', '$gst_type', $subtotal, $gst_amount, $total_amount, '$payment_status', '$payment_method', $paid_amount, $balance_amount, $cash_paid, $upi_paid, $account_paid, $due_date, $created_by_val, '$huid_code', $old_gold_amount)";
    $inv_exec = mysqli_query($conn, $invoice_query);
    if(!$inv_exec) {
        die("<div style='padding:30px;font-family:sans-serif;background:#fff1f2;color:#991b1b;border:2px solid #f87171;border-radius:12px;margin:40px auto;max-width:650px;'>
            <h3 style='margin-top:0;'>❌ Invoice Creation Failed</h3>
            <p><strong>MySQL Error:</strong> " . htmlspecialchars(mysqli_error($conn)) . "</p>
            <p><a href='billing.php' style='color:#991b1b;font-weight:bold;text-decoration:underline;'>← Back to Billing</a></p>
        </div>");
    }
    if($inv_exec) {
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

        // Per-item making charge / hallmark / discount columns (manual entry per item)
        $col_item_mc = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'making_charge'")) > 0;
        if(!$col_item_mc) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN making_charge DECIMAL(10,2) DEFAULT 0");
        $col_item_mc_pct = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'making_charge_pct'")) > 0;
        if(!$col_item_mc_pct) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN making_charge_pct DECIMAL(5,2) DEFAULT 0");
        $col_item_hm = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'hallmark'")) > 0;
        if(!$col_item_hm) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN hallmark DECIMAL(10,2) DEFAULT 0");
        $col_item_disc = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'discount'")) > 0;
        if(!$col_item_disc) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN discount DECIMAL(10,2) DEFAULT 0");

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

                if($product_id === 'other' || !is_numeric($product_id)) {
                    $item_query = "INSERT INTO invoice_items (invoice_id, product_id, product_name, serial_no, huid_code, hsn_code, quantity, price, total, making_charge, making_charge_pct, hallmark, discount) VALUES ($invoice_id, NULL, '".$manual_name."', '".$manual_serial."', '".$manual_huid."', '".$manual_hsn."', $quantity, $price, $total, $item_making_charge, $item_making_charge_pct, $item_hallmark, $item_discount)";
                    mysqli_query($conn, $item_query);
                    continue;
                }
                $pid = intval($product_id);
                $pcs_deduct = floatval($item['pcs'] ?? $item['stock_deduct'] ?? 1);
                if($pcs_deduct <= 0) $pcs_deduct = 1;

                $item_query = "INSERT INTO invoice_items (invoice_id, product_id, product_name, serial_no, huid_code, hsn_code, quantity, price, total, making_charge, making_charge_pct, hallmark, discount)
                               VALUES ($invoice_id, $pid, '".$manual_name."', '".$manual_serial."', '".$manual_huid."', '".$manual_hsn."', $quantity, $price, $total, $item_making_charge, $item_making_charge_pct, $item_hallmark, $item_discount)";
                mysqli_query($conn, $item_query);

                // 1. Deduct piece count from products.quantity
                mysqli_query($conn, "UPDATE products SET quantity = quantity - $pcs_deduct WHERE id = $pid");
                // 2. Auto-delete product from stock table if stock reaches 0 or less
                mysqli_query($conn, "DELETE FROM products WHERE id = $pid AND quantity <= 0");
            }
        }
        $total_qty = 0;
        if(is_array($items)) foreach($items as $item) { $total_qty += floatval($item['quantity'] ?? 0); }

        $redirect_url = 'view_pdf.php?invoice_no=' . urlencode($invoice_no);
        // Use JS redirect — works reliably on Vercel/serverless where ob_start() may buffer headers
        echo '<!DOCTYPE html><html><head>';
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url) . '">';
        echo '<script>window.location.replace(' . json_encode($redirect_url) . ');</script>';
        echo '</head><body style="background:#fffbf4;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;">';
        echo '<div style="text-align:center;"><div style="font-size:40px;margin-bottom:12px;">🧾</div>';
        echo '<p style="color:#7a4e0a;font-weight:600;">Invoice saved! Redirecting...</p>';
        echo '<a href="' . htmlspecialchars($redirect_url) . '" style="color:#d68b16;">Click here if not redirected</a></div>';
        echo '</body></html>';
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
    <title>Billing - MOTI JEWELLERS</title>
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
                <img src="logo.png" alt="MOTI JEWELLERS Logo" style="width:100%;height:100%;object-fit:contain;display:block;">
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
            <h2>MOTI JEWELLERS</h2>
            <p>Premium Since 2026</p>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>

        <a href="index.php">
            <i class="fas fa-home"></i> HOME
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
        <a href="sanchari_dashboard.php">
            <i class="fas fa-piggy-bank"></i> SANCHAY SCHEME
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

    <!-- ══ PAYMENT DUE TODAY SECTION ══ -->

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
<!-- above — e.g. right before </body>)                            -->
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
<!-- NEW: CSS — paste inside your existing <style> tag              -->
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
<!-- NEW: JS — paste inside your existing <script> tag              -->
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
      showNotif('✅ ' + data.message, 'success');
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
    <!-- ══ END PAYMENT DUE TODAY SECTION ══ -->

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
                            <p class="text-xs mt-1" style="color:#9ca3af;">&#9888;&#65039; If empty, auto-generated: INV-YYYYMMDD-XXXX</p>
                        </div>
                        <div id="autoInvoiceInfo" class="text-xs" style="color:#9ca3af;">Auto-generated (INV-YYYYMMDD-XXXX)</div>
                    </div>

                    <!-- Customer Details -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                        <div>
                            <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Customer Name *</label>
                            <input type="text" name="customer_name" id="customerName" required
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="Full Name">
                        </div>
                        <div>
                            <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Mobile Number *</label>
                            <input type="tel" name="customer_mobile" id="customerMobile" required
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="10-digit mobile">
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

                    <!-- ADD ITEM SECTION -->
                    <div class="mb-4 p-4 rounded-xl" style="background:rgba(214,139,22,0.04);border:1.5px solid rgba(214,139,22,0.2);">
                        <h3 class="text-sm font-bold mb-3" style="color:#800020;">
                            <i class="fas fa-plus-circle mr-1" style="color:#d68b16;"></i> Add Items
                        </h3>

                        <!-- UNIFIED FORM PANEL -->
                        <div class="add-mode-panel active" id="panelGram">
                            <div class="mb-4 flex items-center gap-4 bg-white p-2 rounded-lg border border-yellow-200">
                                <span class="text-xs font-semibold text-yellow-800">Source:</span>
                                <label class="flex items-center gap-1.5 text-xs cursor-pointer font-medium text-gray-700">
                                    <input type="radio" name="gram_source" value="stock" checked onchange="switchSource('gram', 'stock')" class="accent-amber-600">
                                    From Stock
                                </label>
                                <label class="flex items-center gap-1.5 text-xs cursor-pointer font-medium text-gray-700">
                                    <input type="radio" name="gram_source" value="category" onchange="switchSource('gram', 'category')" class="accent-amber-600">
                                    By Category
                                </label>
                                <label class="flex items-center gap-1.5 text-xs cursor-pointer font-medium text-gray-700">
                                    <input type="radio" name="gram_source" value="manual" onchange="switchSource('gram', 'manual')" class="accent-amber-600">
                                    Manual Entry
                                </label>
                            </div>

                            <!-- Source: Stock -->
                            <div id="gramSourceStock" class="">
                                <p class="text-xs mb-2 text-gray-400">Search stock by name, SKU, or serial number.</p>
                                <div class="flex gap-2 mb-2 relative">
                                    <div class="relative flex-1">
                                        <input type="text" id="gramStockSearch" placeholder="🔍 Search stock..." class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="filterGramStock(this.value)" autocomplete="off">
                                        <div id="gramStockSuggestions" class="autocomplete-suggestions hidden"></div>
                                    </div>
                                    <button type="button" onclick="clearGramStockSearch()" class="px-3 py-2 rounded-lg text-sm bg-white border border-yellow-300 text-yellow-800">✖</button>
                                </div>
                                <div class="mb-3">
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Select Product</label>
                                    <select id="gramStockProduct" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="onGramStockChange()">
                                        <option value="">-- Select Product --</option>
                                    </select>
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
                                            <option value="Gold 22K">Gold 22K</option>
                                            <option value="Gold 18K">Gold 18K</option>
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
                                        <input type="text" id="gramManualHsn" placeholder="7108" value="7108" class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                                    </div>
                                </div>
                            </div>

                            <!-- Rate, HUID, Weight, Qty & Live preview -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Rate / Price (₹) *</label>
                                    <input type="number" id="gramRate" placeholder="Rate per 10g or Piece" step="0.01" min="0" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="autoGramTotal()">
                                    <div class="text-xs mt-1" style="color:#059669;" id="gramRatePerGramHint"></div>
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">HUID Code <span style="color:#9ca3af;">(Optional)</span></label>
                                    <input type="text" name="huid_code" id="manualHuid" placeholder="F108D"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm" list="huidList" oninput="populateHuidOptions()">
                                    <datalist id="huidList"></datalist>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Weight (g)</label>
                                    <input type="number" id="gramWeight" placeholder="Grams (e.g. 5.5)" step="0.001" min="0" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="autoGramTotal()">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold text-yellow-800">Quantity (Pcs) *</label>
                                    <input type="number" id="gramQty" value="1" step="1" min="1" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="autoGramTotal()">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 gap-3 mb-3">
                                <div id="gramTotalPreviewRow" style="display:none;">
                                    <label class="block mb-1 text-xs font-semibold text-green-700">Calculated Base Amount</label>
                                    <div class="text-lg font-bold text-green-800 px-3 py-1.5 bg-green-50 border border-green-200 rounded-lg" id="gramTotalPreview">₹0.00</div>
                                </div>
                            </div>
                        </div>

                        <!-- Per-Item GST & Extra Charges -->
                        <div class="mt-4 pt-4" style="border-top:1px dashed rgba(214,139,22,0.3);">
                            <h4 class="text-xs font-bold mb-2" style="color:#7a4e0a;">Additional Details for this Item</h4>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Making Charge (%)</label>
                                    <input type="number" id="itemMakingCharge" value="" step="0.1" min="0" placeholder="0" class="jewel-input w-full rounded-lg px-2 py-1 text-sm" oninput="updateMakingChargeHint()">
                                    <div id="itemMakingChargeHint" class="text-xs mt-0.5" style="color:#059669;font-weight:600;display:none;"></div>
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Hallmark (₹)</label>
                                    <input type="number" id="itemHallmark" value="" step="1" min="0" placeholder="0" class="jewel-input w-full rounded-lg px-2 py-1 text-sm">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Discount (₹)</label>
                                    <input type="number" id="itemDiscount" value="" step="1" min="0" placeholder="0" class="jewel-input w-full rounded-lg px-2 py-1 text-sm">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">GST Rate</label>
                                    <select id="itemGstType" class="jewel-input w-full rounded-lg px-2 py-1 text-sm">
                                        <option value="non_gst">0%</option>
                                        <option value="gst_3">3%</option>
                                        <option value="gst_18">18%</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Unified Add Item Button -->
                        <div class="mt-4">
                            <button type="button" id="unifiedAddBtn" onclick="submitGramItem()"
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
                                    <th class="px-3 py-2 text-right text-xs" style="width:105px;">Making&nbsp;Chg&nbsp;(%)</th>
                                    <th class="px-3 py-2 text-right text-xs" style="width:80px;">Hallmark</th>
                                    <th class="px-3 py-2 text-right text-xs" style="width:80px;">Discount</th>
                                    <th class="px-3 py-2 text-right text-xs">Net Total</th>
                                    <th class="px-3 py-2 text-center text-xs">GST</th>
                                    <th class="px-3 py-2 text-xs"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsList">
                                <tr id="emptyRow">
                                    <td colspan="11" style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">
                                        No items added yet — enter details above to add products
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals -->
                    <div class="p-4 rounded-xl" style="background:rgba(214,139,22,0.06);border:1px solid rgba(214,139,22,0.18);">
                        <div class="flex justify-end">
                            <div class="w-full sm:w-96 space-y-1.5">
                                <div class="flex justify-between text-sm" style="color:#7a4e0a;">
                                    <span>Subtotal</span><span id="subtotal" class="font-semibold">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#b5730e;">
                                    <span>Making Charge</span><span id="makingChargeAmount">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#059669;">
                                    <span>Hallmark</span><span id="hallmarkAmount">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#dc2626;">
                                    <span>Discount</span><span id="discountAmount">- &#8377;0.00</span>
                                </div>
                                <div class="flex justify-between items-center text-sm pt-1 pb-1" style="color:#b91c1c;border-top:1px dashed rgba(185,28,28,0.2);border-bottom:1px dashed rgba(185,28,28,0.2);">
                                    <span class="font-semibold">Old Gold Exchange / Return (₹)</span>
                                    <input type="number" id="oldGoldAmountInput" value="" step="1" min="0" placeholder="0" class="jewel-input rounded-lg px-2 py-1 text-sm text-right w-32 border-red-300 font-bold" oninput="calculateTotal()">
                                </div>
                                <div class="flex justify-between text-sm" style="color:#b91c1c;display:none;" id="oldGoldRow">
                                    <span>Old Gold Deduction</span><span id="oldGoldDisplayAmount">- &#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#2563eb;" id="cgstRow">
                                    <span>CGST Total</span><span id="cgstAmount">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#2563eb;" id="sgstRow">
                                    <span>SGST Total</span><span id="sgstAmount">&#8377;0.00</span>
                                </div>
                                <div style="height:1px;background:rgba(181,115,14,0.25);margin:8px 0;"></div>
                                <div class="flex justify-between font-bold text-xl" style="color:#800020;">
                                    <span>Grand Total</span><span id="grandTotal">&#8377;0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hidden form fields -->
                    <input type="hidden" name="subtotal" id="hiddenSubtotal" value="0">
                    <input type="hidden" name="gst_amount" id="hiddenGst" value="0">
                    <input type="hidden" name="total_amount" id="hiddenTotal" value="0">
                    <input type="hidden" name="items" id="hiddenItems" value="[]">
                    <input type="hidden" name="making_charge" id="hiddenMakingCharge" value="0">
                    <input type="hidden" name="hallmark" id="hiddenHallmark" value="0">
                    <input type="hidden" name="pola" value="0">
                    <input type="hidden" name="discount" id="hiddenDiscount" value="0">
                    <input type="hidden" name="old_gold_amount" id="hiddenOldGold" value="0">
                    <input type="hidden" name="cash_paid" id="hiddenCashPaid" value="0">
                    <input type="hidden" name="upi_paid" id="hiddenUpiPaid" value="0">
                    <input type="hidden" name="is_split_payment" id="hiddenIsSplit" value="0">

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
                                    <option value="unpaid">Advanced (Credit)</option>
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
                <button onclick="autoFillShopFromLive()" class="w-full py-2 rounded-lg text-xs font-bold mb-3"
                    style="background:linear-gradient(135deg,#059669,#34d399);color:#fff;border:none;cursor:pointer;">
                    ⚡ Auto-fill from Live Market Rates
                </button>
                <?php
                $shopFields = [
                    ['key'=>'gold22','label'=>'Gold 22K','color'=>'#d68b16','dispId'=>'shopGold22Display','inputId'=>'shopGold22Input','step'=>'1','perGramId'=>'shopGold22PerGram'],
                    ['key'=>'gold18','label'=>'Gold 18K','color'=>'#b5730e','dispId'=>'shopGold18Display','inputId'=>'shopGold18Input','step'=>'1','perGramId'=>'shopGold18PerGram'],
                    ['key'=>'silver','label'=>'Silver',  'color'=>'#6b7280','dispId'=>'shopSilverDisplay', 'inputId'=>'shopSilverInput', 'step'=>'0.5','perGramId'=>'shopSilverPerGram'],
                    ['key'=>'diamond','label'=>'Diamond','color'=>'#2563eb','dispId'=>'shopDiamondDisplay','inputId'=>'shopDiamondInput','step'=>'1','perGramId'=>'shopDiamondPerGram'],
                ];
                foreach($shopFields as $f): ?>
                <div class="mb-3 p-2 rounded-lg" style="background:rgba(214,139,22,0.03);border:1px solid rgba(214,139,22,0.12);">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-semibold" style="color:<?php echo $f['color']; ?>;">
                            <?php echo $f['label']; ?> <span style="color:#9ca3af;font-weight:400;">(per 10g)</span>
                        </label>
                        <span class="text-xs font-bold" style="color:<?php echo $f['color']; ?>;" id="<?php echo $f['dispId']; ?>">&#8212;</span>
                    </div>
                    <div class="flex gap-2 mb-1">
                        <input type="number" id="<?php echo $f['inputId']; ?>" placeholder="e.g. 65000"
                            step="<?php echo $f['step']; ?>" min="0" class="jewel-input flex-1 rounded-lg px-3 py-2 text-sm"
                            oninput="previewShopRate('<?php echo $f['key']; ?>')">
                        <button onclick="saveShopRate('<?php echo $f['key']; ?>')"
                            class="btn-gold px-3 py-2 rounded-lg text-xs font-bold">Save</button>
                    </div>
                    <div class="text-xs" style="color:#059669;" id="<?php echo $f['perGramId']; ?>"></div>
                </div>
                <?php endforeach; ?>
                <button onclick="saveAllShopRates()" class="btn-gold w-full py-2 rounded-lg text-sm font-bold mt-1">
                    &#128190; Save All Rates
                </button>
                <div class="p-3 rounded-xl mt-3" style="background:rgba(214,139,22,0.05);border:1px solid rgba(181,115,14,0.12);">
                    <div class="text-xs font-semibold mb-2" style="color:#b5730e;">&#9878;&#65039; Shop Value Calculator</div>
                    <div class="flex gap-2">
                        <select id="shopMetalSelect" class="jewel-input flex-1 rounded-lg px-2 py-1 text-xs" onchange="calcShopValue()">
                            <option value="gold22">Gold 22K</option>
                            <option value="gold18">Gold 18K</option>
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
            <br>

          

            <!-- Live Metal Rates -->
            <div class="jewel-card p-4 sm:p-5 mt-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-bold" style="color:#800020;font-family:'Poppins',serif;">
                        <i class="fas fa-coins mr-2" style="color:#d68b16;"></i> Live Metal Rates
                    </h3>
                    <div class="flex items-center gap-2">
                        <span id="metalPriceStatus" class="text-xs" style="color:#b5730e;">&#8635; Loading...</span>
                        <button onclick="fetchMetalPrices()" class="text-xs px-2 py-1 rounded-lg"
                            style="background:rgba(214,139,22,0.12);border:1px solid rgba(214,139,22,0.3);color:#d68b16;cursor:pointer;">&#128260;</button>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div class="rounded-xl p-3 text-center" style="background:rgba(214,139,22,0.08);border:1px solid rgba(214,139,22,0.3);">
                        <div class="text-xs font-semibold" style="color:#d68b16;">Gold 24K</div>
                        <div class="text-sm font-bold mt-1" style="color:#7a4e0a;" id="gold24Price">&#8212;</div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="gold24Change">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(214,139,22,0.05);border:1px solid rgba(181,115,14,0.25);">
                        <div class="text-xs font-semibold" style="color:#b5730e;">Gold 22K</div>
                        <div class="text-sm font-bold mt-1" style="color:#7a4e0a;" id="gold22Price">&#8212;</div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="gold22Change">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(192,192,192,0.08);border:1px solid rgba(192,192,192,0.2);">
                        <div class="text-xs font-semibold" style="color:#6b7280;">Silver</div>
                        <div class="text-sm font-bold mt-1" style="color:#374151;" id="silverPrice">&#8212;</div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="silverChange">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(229,228,226,0.05);border:1px solid rgba(229,228,226,0.18);">
                        <div class="text-xs font-semibold" style="color:#6b7280;">Platinum</div>
                        <div class="text-sm font-bold mt-1" style="color:#374151;" id="platinumPrice">&#8212;</div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="platinumChange">per 10g</div>
                    </div>
                </div>
                <div class="p-3 rounded-xl" style="background:rgba(214,139,22,0.05);border:1px solid rgba(181,115,14,0.12);">
                    <div class="text-xs font-semibold mb-2" style="color:#b5730e;">&#9878;&#65039; Quick Value Calculator</div>
                    <div class="flex gap-2">
                        <select id="metalSelect" class="jewel-input flex-1 rounded-lg px-2 py-1 text-xs" onchange="calcMetalValue()">
                            <option value="gold24">Gold 24K</option>
                            <option value="gold22">Gold 22K</option>
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
            <div class="jewel-card p-4 sm:p-6">
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
            &copy; 2026 MOTI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology</a>
        </p>
    </footer>
</div><!-- /page-wrapper -->

<!-- JAVASCRIPT -->
<script>
const ALL_PRODUCTS = <?php echo json_encode($all_products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const itemTypeOptions = <?php echo json_encode($itemTypeOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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

let items = [];
let currentMainTab = 'gram'; // 'gram' or 'qty'
let currentGramSource = 'stock'; // 'stock', 'category', 'manual'
let currentQtySource = 'stock'; // 'stock', 'category', 'manual'

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
        
        if (source === 'stock') {
            document.getElementById('gramSourceStock').classList.remove('hidden');
        } else if (source === 'category') {
            document.getElementById('gramSourceCategory').classList.remove('hidden');
        } else {
            document.getElementById('gramSourceManual').classList.remove('hidden');
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

// ==================== ⚖️ GRAM FORM LOGIC ====================

// Stock search & filter with floating suggestions
function filterGramStock(query) {
    const select = document.getElementById('gramStockProduct');
    const infoDiv = document.getElementById('gramStockProductInfo');
    const suggDiv = document.getElementById('gramStockSuggestions');
    query = query.trim().toLowerCase();
    infoDiv.classList.add('hidden');
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
        const stockText = isOutOfStock ? 'OUT OF STOCK' : (p.quantity + ' pcs');
        const display = (p.item_name || p.name) + ' | SN:' + (p.serial_no || '—') + ' | Stock: ' + stockText;
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = display;
        opt.dataset.price = p.price;
        opt.dataset.name = p.name;
        opt.dataset.serial = p.serial_no;
        opt.dataset.category = p.category;
        opt.dataset.itemName = p.item_name || p.name;
        opt.dataset.qty = p.quantity;
        opt.dataset.huid = p.huid_code || p.serial_no || '';
        if (isOutOfStock) opt.style.color = '#dc2626';
        select.appendChild(opt);
    });

    if (suggDiv) {
        suggDiv.innerHTML = '';
        if (query.length > 0 && filtered.length > 0) {
            filtered.slice(0, 8).forEach(p => {
                const isOut = (parseFloat(p.quantity) <= 0);
                const item = document.createElement('div');
                item.className = 'autocomplete-suggestion-item';
                item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;background:#fff;';
                item.innerHTML = '<div><strong style="color:#022c22;">' + (p.item_name || p.name) + '</strong> <span style="font-size:11px;color:#059669;">(' + p.category + ')</span></div><div style="font-size:11px;color:' + (isOut?'#dc2626':'#7a4e0a') + ';">SN: ' + (p.serial_no || '—') + ' | ' + (isOut ? 'OUT OF STOCK' : (p.quantity + ' pcs')) + '</div>';
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

function onGramStockChange() {
    const select = document.getElementById('gramStockProduct');
    const infoDiv = document.getElementById('gramStockProductInfo');
    const rateInput = document.getElementById('gramRate');
    const weightInput = document.getElementById('gramWeight');
    const opt = select.options[select.selectedIndex];
    
    if (!opt || !opt.value) {
        infoDiv.classList.add('hidden');
        return;
    }
    
    const price    = parseFloat(opt.dataset.price) || 0;    // DB: total item value
    const qty      = parseFloat(opt.dataset.qty) || 0;      // DB: pieces in stock
    const name     = opt.dataset.itemName;
    const category = opt.dataset.category || '';
    const huid     = opt.dataset.huid || '';

    // Auto-fill HUID
    if (document.getElementById('manualHuid')) {
        document.getElementById('manualHuid').value = huid;
    }

    // Use shop rate (per 10g) for this category — the correct billing rate
    const shopRatePerGram = getShopRateForCategory(category); // returns per-gram from shop rates
    const shopRate10g     = shopRatePerGram * 10;             // convert back to per-10g for the field

    if (qty <= 0) {
        rateInput.value = '';
        weightInput.value = '';
        infoDiv.innerHTML = '<strong style="color:#dc2626;">' + name + '</strong> | <strong style="color:#dc2626;">❌ OUT OF STOCK (0 pcs available)</strong>';
    } else if (shopRate10g > 0) {
        rateInput.value = shopRate10g.toFixed(0);
        const hint = document.getElementById('gramRatePerGramHint');
        if(hint) hint.textContent = '\u2248 \u20B9' + shopRatePerGram.toLocaleString('en-IN', {maximumFractionDigits:2}) + ' per gram (shop rate used in billing)';
        infoDiv.innerHTML = '<strong>' + name + '</strong> | Shop Rate: \u20B9' + shopRatePerGram.toLocaleString('en-IN', {maximumFractionDigits:2}) + '/g | Stock Value: \u20B9' + price.toLocaleString('en-IN') + ' | Available Stock: <strong style="color:#059669;">' + qty + ' pcs</strong>';
        weightInput.value = '';
    } else {
        rateInput.value = '';
        const hint = document.getElementById('gramRatePerGramHint');
        if(hint) hint.textContent = '\u26A0 Set shop rate for ' + (category || 'this category') + ' in the panel on the right first!';
        if(hint) hint.style.color = '#dc2626';
        infoDiv.innerHTML = '<strong>' + name + '</strong> | Stock Value: \u20B9' + price.toLocaleString('en-IN') + ' | Available Stock: <strong style="color:#059669;">' + qty + ' pcs</strong> | \u26A0 Set shop rate first!';
        weightInput.value = '';
    }

    infoDiv.classList.remove('hidden');
    autoGramTotal();
}

function updateGramItemTypes() {
    const category = document.getElementById('gramCatSelect').value;
    const itemTypeSelect = document.getElementById('gramItemType');
    itemTypeSelect.innerHTML = '<option value="">-- Select Item Type --</option>';
    if (!category) {
        onGramItemTypeChange();
        return;
    }
    
    const options = mergedItemTypeOptions[category] || [];
    
    // Group matching products from ALL_PRODUCTS stock array
    const categoryStock = ALL_PRODUCTS.filter(p => {
        const catName = (p.category || '').toLowerCase();
        const targetCat = category.toLowerCase();
        return catName.includes(targetCat) || targetCat.includes(catName);
    });

    options.forEach(item => {
        const matchingItems = categoryStock.filter(p => {
            const pName = (p.item_name || p.name || '').toLowerCase();
            const itName = item.toLowerCase();
            return pName.includes(itName) || itName.includes(pName);
        });

        let totalQty = 0;
        matchingItems.forEach(p => {
            totalQty += parseFloat(p.quantity) || 0;
        });

        const opt = document.createElement('option');
        opt.value = item;
        if (totalQty > 0) {
            opt.textContent = item + ' (In Stock: ' + totalQty + ' pcs)';
            opt.dataset.inStock = 'true';
            opt.dataset.stockQty = totalQty;
        } else {
            opt.textContent = item + ' (Out of Stock)';
            opt.dataset.inStock = 'false';
            opt.dataset.stockQty = 0;
            opt.style.color = '#dc2626';
        }
        itemTypeSelect.appendChild(opt);
    });
    
    const shopRate = getShopRateForCategory(category);
    const rateInput = document.getElementById('gramRate');
    const rateBadge = document.getElementById('gramCatLiveRateBadge');
    const rateText = document.getElementById('gramCatLiveRateText');
    
    const per10g = shopRate * 10;
    if (per10g > 0) {
        rateInput.value = per10g.toFixed(0);
        const hint = document.getElementById('gramRatePerGramHint');
        if(hint) hint.textContent = '\u2248 \u20B9' + shopRate.toFixed(2) + ' per gram (used in billing)';
        rateText.innerHTML = '<strong>Shop Rate:</strong> \u20B9' + per10g.toLocaleString('en-IN') + '/10g = \u20B9' + shopRate.toFixed(2) + '/g';
        rateBadge.classList.remove('hidden');
    } else {
        rateInput.value = '';
        const hint = document.getElementById('gramRatePerGramHint');
        if(hint) hint.textContent = '';
        rateBadge.classList.add('hidden');
    }
    autoGramTotal();
    onGramItemTypeChange();
}

function onGramItemTypeChange() {
    const itemTypeSelect = document.getElementById('gramItemType');
    const statusDiv = document.getElementById('gramCatStockStatus');
    if (!statusDiv) return;

    const opt = itemTypeSelect.options[itemTypeSelect.selectedIndex];
    if (!opt || !opt.value) {
        statusDiv.classList.add('hidden');
        return;
    }

    const inStock = (opt.dataset.inStock === 'true');
    const stockQty = parseFloat(opt.dataset.stockQty) || 0;

    if (inStock && stockQty > 0) {
        statusDiv.innerHTML = '<span class="px-2 py-0.5 rounded text-xs font-bold bg-green-100 text-green-800 border border-green-300">✅ In Stock (' + stockQty + ' pcs available in inventory)</span>';
    } else {
        statusDiv.innerHTML = '<span class="px-2 py-0.5 rounded text-xs font-bold bg-red-100 text-red-800 border border-red-300">❌ Out of Stock (0 items available in inventory)</span>';
    }
    statusDiv.classList.remove('hidden');
}

function updateMakingChargeHint() {
    const hintEl = document.getElementById('itemMakingChargeHint');
    if(!hintEl) return;
    const imcPct = parseFloat(document.getElementById('itemMakingCharge').value) || 0;
    
    let baseAmount = 0;
    if (typeof currentMainTab === 'undefined' || currentMainTab === 'gram') {
        const checkedRadio = document.querySelector('input[name="gram_source"]:checked');
        const source = checkedRadio ? checkedRadio.value : (typeof currentGramSource !== 'undefined' ? currentGramSource : 'stock');
        let weight = parseFloat(document.getElementById('gramWeight')?.value) || 0;
        const rate10g = parseFloat(document.getElementById('gramRate')?.value) || 0;
        const qty = parseFloat(document.getElementById('gramQty')?.value) || 1;
        baseAmount = weight * (rate10g / 10) * qty;
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
    
    if (imcPct > 0 && baseAmount > 0) {
        const mcAmt = baseAmount * (imcPct / 100);
        hintEl.textContent = '= \u20B9' + mcAmt.toFixed(2);
        hintEl.style.display = 'block';
    } else {
        hintEl.textContent = '';
        hintEl.style.display = 'none';
    }
}

function autoGramTotal() {
    const checkedRadio = document.querySelector('input[name="gram_source"]:checked');
    const source = checkedRadio ? checkedRadio.value : (typeof currentGramSource !== 'undefined' ? currentGramSource : 'stock');
    currentGramSource = source;

    let weight = parseFloat(document.getElementById('gramWeight')?.value) || 0;
    
    const rate10g = parseFloat(document.getElementById('gramRate').value) || 0;
    const qty = parseFloat(document.getElementById('gramQty')?.value) || 1;
    let total = 0;
    const hint = document.getElementById('gramRatePerGramHint');
    
    if (weight > 0) {
        const ratePerGram = rate10g / 10;
        total = parseFloat((weight * ratePerGram * qty).toFixed(2));
        if(hint && rate10g > 0) hint.textContent = '\u2248 \u20B9' + ratePerGram.toLocaleString('en-IN', {maximumFractionDigits:2}) + '/g \u00D7 ' + qty + ' pcs';
        else if(hint) hint.textContent = '';
    } else if (qty > 0 && rate10g > 0) {
        total = parseFloat((qty * rate10g).toFixed(2));
        if(hint) hint.textContent = '\u2248 \u20B9' + rate10g.toLocaleString('en-IN', {maximumFractionDigits:2}) + ' per piece \u00D7 ' + qty + ' pcs';
    } else if(hint) {
        hint.textContent = '';
    }
    
    const previewRow = document.getElementById('gramTotalPreviewRow');
    const previewEl = document.getElementById('gramTotalPreview');
    
    if (total > 0) {
        previewEl.textContent = '\u20B9' + total.toLocaleString('en-IN', {minimumFractionDigits: 2});
        previewRow.style.display = '';
    } else {
        previewRow.style.display = 'none';
    }
    updateMakingChargeHint();
}

function submitGramItem() {
    const checkedRadio = document.querySelector('input[name="gram_source"]:checked');
    const source = checkedRadio ? checkedRadio.value : (typeof currentGramSource !== 'undefined' ? currentGramSource : 'stock');
    currentGramSource = source;

    let productId = 'other';
    let name = '';
    let itemType = '';
    let hsn = '7108';
    let weight = parseFloat(document.getElementById('gramWeight')?.value) || 0;
    const rate10g = parseFloat(document.getElementById('gramRate').value) || 0;
    const qty = parseFloat(document.getElementById('gramQty')?.value) || 1;
    
    if (source === 'stock') {
        const select = document.getElementById('gramStockProduct');
        const opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) { alert('Please select a product from stock.'); return; }
        productId = opt.value;
        name = opt.dataset.itemName;
        hsn = '7108';

        // Stock pcs validation check
        const stockQty = parseFloat(opt.dataset.qty) || 0;
        let existingPcs = 0;
        items.forEach(it => {
            if (String(it.product_id) === String(productId)) {
                existingPcs += (parseFloat(it.pcs) || 1);
            }
        });
        const totalReqPcs = existingPcs + qty;
        if (stockQty <= 0) {
            alert('❌ Out of Stock!\n"' + name + '" is currently out of stock (0 pcs available).');
            return;
        }
        if (totalReqPcs > stockQty) {
            alert('❌ Exceeds Available Stock!\nOnly ' + stockQty + ' pcs available in stock for "' + name + '", but ' + totalReqPcs + ' pcs requested.');
            return;
        }
    } else if (source === 'category') {
        const cat = document.getElementById('gramCatSelect').value;
        const typeSelect = document.getElementById('gramItemType');
        const type = typeSelect.value;
        if (!cat) { alert('Please select a category.'); return; }
        if (!type) { alert('Please select an item type.'); return; }

        const opt = typeSelect.options[typeSelect.selectedIndex];
        const inStock = opt ? (opt.dataset.inStock === 'true') : true;
        const stockQty = opt ? (parseFloat(opt.dataset.stockQty) || 0) : 0;

        if (!inStock || stockQty <= 0) {
            alert('❌ Item Out of Stock!\n"' + type + '" is currently not available in your stock inventory.');
            return;
        }

        productId = 'other';
        name = type;  // e.g. "Jhumka"
        itemType = type;
        hsn = '7108';
    } else {
        name = document.getElementById('gramManualName').value.trim();
        hsn = document.getElementById('gramManualHsn').value.trim() || '7108';
        if (!name) { alert('Please enter an item description.'); return; }
    }
    
    if (rate10g <= 0) { alert('Please enter rate / price.'); return; }
    if (qty <= 0) { alert('Please enter quantity.'); return; }
    
    let baseAmount = 0;
    let unit = 'g';
    let itemPrice = 0;
    
    if (weight > 0) {
        let ratePerGram = rate10g / 10;
        baseAmount = parseFloat((weight * ratePerGram * qty).toFixed(2));
        unit = 'g';
        itemPrice = ratePerGram;
    } else {
        baseAmount = parseFloat((qty * rate10g).toFixed(2));
        unit = 'pcs';
        itemPrice = rate10g;
    }
    
    const imcPct = parseFloat(document.getElementById('itemMakingCharge').value) || 0;
    const imc = parseFloat((baseAmount * (imcPct / 100)).toFixed(2));
    const ihm = parseFloat(document.getElementById('itemHallmark').value) || 0;
    const idisc = parseFloat(document.getElementById('itemDiscount').value) || 0;
    const igst = document.getElementById('itemGstType').value;
    const itemTotal = parseFloat((baseAmount + imc + ihm - idisc).toFixed(2));
    
    const inputHuid = document.getElementById('manualHuid') ? document.getElementById('manualHuid').value.trim() : '';

    items.push({
        product_id: productId,
        name: name + (qty > 1 && weight > 0 ? ' (' + qty + ' pcs)' : ''),
        item_type: itemType,
        hsn: hsn,
        quantity: weight > 0 ? weight : qty,
        unit: unit,
        pcs: qty,
        stock_deduct: qty,
        price: itemPrice,
        base_amount: baseAmount,
        making_charge_pct: imcPct,
        making_charge: imc,
        hallmark: ihm,
        discount: idisc,
        total: itemTotal,
        gst_type: igst,
        is_manual: (source === 'manual'),
        is_item_only: (source === 'category'),
        serial_no: (source === 'stock') ? (document.getElementById('gramStockProduct').options[document.getElementById('gramStockProduct').selectedIndex].dataset.serial || inputHuid) : inputHuid,
        huid_code: inputHuid
    });
    
    updateItemsList();
    calculateTotal();
    resetItemCharges();
    
    // Reset fields
    document.getElementById('gramWeight').value = '';
    document.getElementById('gramRate').value = '';
    if (document.getElementById('manualHuid')) document.getElementById('manualHuid').value = '';
    if (document.getElementById('gramQty')) document.getElementById('gramQty').value = '1';
    
    if (source === 'stock') {
        document.getElementById('gramStockProduct').value = '';
        document.getElementById('gramStockProductInfo').classList.add('hidden');
        filterGramStock('');
    } else if (source === 'category') {
        document.getElementById('gramCatSelect').value = '';
        document.getElementById('gramItemType').value = '';
        document.getElementById('gramCatLiveRateBadge').classList.add('hidden');
    } else if (source === 'manual') {
        document.getElementById('gramManualName').value = '';
    }
    document.getElementById('gramTotalPreviewRow').style.display = 'none';
    showNotif('✅ Added Item: ' + name, 'success');
}

// ==================== 📦 QTY FORM LOGIC ====================

// Stock search & filter with floating suggestions
function filterQtyStock(query) {
    const select = document.getElementById('qtyStockProduct');
    const infoDiv = document.getElementById('qtyStockProductInfo');
    const suggDiv = document.getElementById('qtyStockSuggestions');
    query = query.trim().toLowerCase();
    infoDiv.classList.add('hidden');
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
        const stockText = isOutOfStock ? 'OUT OF STOCK' : (p.quantity + ' pcs');
        const display = (p.item_name || p.name) + ' | SN:' + (p.serial_no || '—') + ' | Stock: ' + stockText;
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = display;
        opt.dataset.price = p.price;
        opt.dataset.name = p.name;
        opt.dataset.serial = p.serial_no;
        opt.dataset.category = p.category;
        opt.dataset.itemName = p.item_name || p.name;
        opt.dataset.qty = p.quantity;
        if (isOutOfStock) opt.style.color = '#dc2626';
        select.appendChild(opt);
    });

    if (suggDiv) {
        suggDiv.innerHTML = '';
        if (query.length > 0 && filtered.length > 0) {
            filtered.slice(0, 8).forEach(p => {
                const isOut = (parseFloat(p.quantity) <= 0);
                const item = document.createElement('div');
                item.className = 'autocomplete-suggestion-item';
                item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;background:#fff;';
                item.innerHTML = '<div><strong style="color:#022c22;">' + (p.item_name || p.name) + '</strong> <span style="font-size:11px;color:#059669;">(' + p.category + ')</span></div><div style="font-size:11px;color:' + (isOut?'#dc2626':'#7a4e0a') + ';">SN: ' + (p.serial_no || '—') + ' | ' + (isOut ? 'OUT OF STOCK' : (p.quantity + ' pcs')) + '</div>';
                item.onmouseover = function() { this.style.background = '#f5ead0'; };
                item.onmouseout = function() { this.style.background = '#fff'; };
                item.onclick = function() {
                    select.value = p.id;
                    document.getElementById('qtyStockSearch').value = (p.item_name || p.name);
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
    document.getElementById('qtyStockSearch').value = '';
    const suggDiv = document.getElementById('qtyStockSuggestions');
    if (suggDiv) suggDiv.classList.add('hidden');
    filterQtyStock('');
}

function onQtyStockChange() {
    const select = document.getElementById('qtyStockProduct');
    const infoDiv = document.getElementById('qtyStockProductInfo');
    const rateInput = document.getElementById('qtyRate');
    const qtyInput = document.getElementById('qtyCount');
    const opt = select.options[select.selectedIndex];
    
    if (!opt || !opt.value) {
        infoDiv.classList.add('hidden');
        return;
    }
    
    const price = parseFloat(opt.dataset.price) || 0;
    const qty = parseFloat(opt.dataset.qty) || 0;
    const name = opt.dataset.itemName;
    
    rateInput.value = price.toFixed(2);
    qtyInput.value = 1;
    
    if (qty <= 0) {
        infoDiv.innerHTML = '<strong style="color:#dc2626;">' + name + '</strong> | <strong style="color:#dc2626;">❌ OUT OF STOCK (0 pcs available)</strong>';
    } else {
        infoDiv.innerHTML = '<strong>' + name + '</strong> Selected. Price per Piece: ₹' + price.toFixed(2) + ' | Available Stock: <strong style="color:#059669;">' + qty + ' pcs</strong>';
    }
    infoDiv.classList.remove('hidden');
    
    autoQtyTotal();
}

function updateQtyItemTypes() {
    const category = document.getElementById('qtyCatSelect').value;
    const itemTypeSelect = document.getElementById('qtyItemType');
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
        qty = parseInt(document.getElementById('qtyCount').value) || 0;
    } else if (source === 'category') {
        qty = parseInt(document.getElementById('qtyCountCat').value) || 0;
    } else {
        qty = parseInt(document.getElementById('qtyCountManual').value) || 0;
    }
    
    const rate = parseFloat(document.getElementById('qtyRate').value) || 0;
    const total = parseFloat((qty * rate).toFixed(2));
    
    const previewRow = document.getElementById('qtyTotalPreviewRow');
    const previewEl = document.getElementById('qtyTotalPreview');
    
    if (total > 0) {
        previewEl.textContent = '₹' + total.toLocaleString('en-IN', {minimumFractionDigits: 2});
        previewRow.style.display = '';
    } else {
        previewRow.style.display = 'none';
    }
    updateMakingChargeHint();
}

function submitQtyItem() {
    const checkedRadio = document.querySelector('input[name="qty_source"]:checked');
    const source = checkedRadio ? checkedRadio.value : (typeof currentQtySource !== 'undefined' ? currentQtySource : 'stock');
    currentQtySource = source;

    let productId = 'other';
    let name = '';
    let itemType = '';
    let hsn = '7113';
    let qty = 0;
    let rate = parseFloat(document.getElementById('qtyRate').value) || 0;
    
    if (source === 'stock') {
        const select = document.getElementById('qtyStockProduct');
        const opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) { alert('Please select a product from stock.'); return; }
        productId = opt.value;
        name = opt.dataset.itemName;
        hsn = '7113';
        qty = parseInt(document.getElementById('qtyCount').value) || 0;

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
            alert('❌ Out of Stock!\n"' + name + '" is currently out of stock (0 pcs available).');
            return;
        }
        if (totalReqPcs > stockQty) {
            alert('❌ Exceeds Available Stock!\nOnly ' + stockQty + ' pcs available in stock for "' + name + '", but ' + totalReqPcs + ' pcs requested.');
            return;
        }
    } else if (source === 'category') {
        const cat = document.getElementById('qtyCatSelect').value;
        const type = document.getElementById('qtyItemType').value;
        if (!cat) { alert('Please select a category.'); return; }
        if (!type) { alert('Please select an item type.'); return; }
        productId = 'other';
        name = type;  // e.g. "Jhumka"
        itemType = type;
        hsn = '7113';
        qty = parseInt(document.getElementById('qtyCountCat').value) || 0;
    } else {
        name = document.getElementById('qtyManualName').value.trim();
        hsn = document.getElementById('qtyManualHsn').value.trim() || '7113';
        qty = parseInt(document.getElementById('qtyCountManual').value) || 0;
        if (!name) { alert('Please enter an item description.'); return; }
    }
    
    if (qty <= 0) { alert('Please enter quantity.'); return; }
    if (rate <= 0) { alert('Please enter rate per piece.'); return; }
    
    const baseAmount = parseFloat((qty * rate).toFixed(2));
    const imcPct = parseFloat(document.getElementById('itemMakingCharge').value) || 0;
    const imc = parseFloat((baseAmount * (imcPct / 100)).toFixed(2));
    const ihm = parseFloat(document.getElementById('itemHallmark').value) || 0;
    const idisc = parseFloat(document.getElementById('itemDiscount').value) || 0;
    const igst = document.getElementById('itemGstType').value;
    const itemTotal = parseFloat((baseAmount + imc + ihm - idisc).toFixed(2));
    
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
        serial_no: (source === 'stock') ? document.getElementById('qtyStockProduct').options[document.getElementById('qtyStockProduct').selectedIndex].dataset.serial : ''
    });
    
    updateItemsList();
    calculateTotal();
    resetItemCharges();
    
    // Reset fields
    document.getElementById('qtyCount').value = '';
    document.getElementById('qtyCountCat').value = '';
    document.getElementById('qtyCountManual').value = '';
    document.getElementById('qtyRate').value = '';
    document.getElementById('qtyManualName').value = '';
    if (currentQtySource === 'stock') {
        document.getElementById('qtyStockProduct').value = '';
        document.getElementById('qtyStockProductInfo').classList.add('hidden');
        filterQtyStock('');
    } else if (currentQtySource === 'category') {
        document.getElementById('qtyCatSelect').value = '';
        document.getElementById('qtyItemType').value = '';
    } else if (currentQtySource === 'manual') {
        document.getElementById('qtyManualName').value = '';
    }
    document.getElementById('qtyTotalPreviewRow').style.display = 'none';
    showNotif('✅ Added Qty Item: ' + name, 'success');
}

function populateStockSelects() {
    filterGramStock('');
    filterQtyStock('');
}
function resetItemCharges() {
    document.getElementById('itemMakingCharge').value = '';
    document.getElementById('itemHallmark').value = '';
    document.getElementById('itemDiscount').value = '';
    document.getElementById('itemGstType').value = 'non_gst';
    const hint = document.getElementById('itemMakingChargeHint');
    if(hint) { hint.textContent = ''; hint.style.display = 'none'; }
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
    const tbody = document.getElementById('itemsList');
    if(items.length === 0) {
        tbody.innerHTML = '<tr id="emptyRow"><td colspan="11" style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">No items added yet \u2014 enter details above to add products</td></tr>';
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
        let mcPct = item.making_charge_pct;
        if (mcPct === undefined || mcPct === null) {
            mcPct = (base > 0 && item.making_charge) ? parseFloat((item.making_charge / base * 100).toFixed(2)) : 0;
        }
        const mcAmt = item.making_charge || 0;
        const hm = item.hallmark || 0;
        const disc = item.discount || 0;
        const mcValDisp = (mcPct > 0) ? mcPct : '';
        const hmValDisp = (hm > 0) ? hm : '';
        const discValDisp = (disc > 0) ? disc : '';
        const chargeInputStyle = 'width:60px;padding:3px 4px;border:1px solid #e5c98a;border-radius:5px;font-size:11px;text-align:right;';
        html += '<tr>' +
            '<td class="px-2 py-2 text-xs text-center" style="color:#9ca3af;">' + (idx+1) + '</td>' +
            '<td class="px-2 py-2 text-xs" style="color:#374151;">' + icon + ' ' + htmlEsc(item.name) +
                (item.item_type ? '<span style="color:#b5730e;font-size:10px;"> [' + htmlEsc(item.item_type) + ']</span>' : '') +
                badge + '<div style="color:#9ca3af;font-size:10px;">HSN: ' + (item.hsn || '7108') + '</div></td>' +
            '<td class="px-2 py-2 text-center text-xs" style="color:#6b7280;">' + (item.quantity > 0 ? item.quantity : '\u2014') + '</td>' +
            '<td class="px-2 py-2 text-right text-xs" style="color:#374151;">' + (item.price > 0 ? '\u20B9' + item.price.toFixed(2) : '\u2014') + '</td>' +
            '<td class="px-2 py-2 text-right text-xs" style="color:#374151;">\u20B9' + base.toFixed(2) + '</td>' +
            '<td class="px-2 py-2 text-right text-xs">' +
                '<input type="number" min="0" step="0.1" value="' + mcValDisp + '" placeholder="0" style="' + chargeInputStyle + '" onchange="updateItemCharge(' + idx + ',\'making_charge_pct\',this.value)">' +
                '<div style="font-size:9.5px;color:#059669;font-weight:600;margin-top:1px;">(\u20B9' + mcAmt.toFixed(2) + ')</div>' +
            '</td>' +
            '<td class="px-2 py-2 text-right text-xs"><input type="number" min="0" step="1" value="' + hmValDisp + '" placeholder="0" style="' + chargeInputStyle + '" onchange="updateItemCharge(' + idx + ',\'hallmark\',this.value)"></td>' +
            '<td class="px-2 py-2 text-right text-xs"><input type="number" min="0" step="1" value="' + discValDisp + '" placeholder="0" style="' + chargeInputStyle + '" onchange="updateItemCharge(' + idx + ',\'discount\',this.value)"></td>' +
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
    const disc = items[idx].discount || 0;
    items[idx].total = parseFloat((base + mc + hm - disc).toFixed(2));
    updateItemsList();
    calculateTotal();
}

function htmlEsc(str) {
    if(!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function removeItem(index) { items.splice(index, 1); updateItemsList(); calculateTotal(); }
function updateItemGst(index, value) { if(items[index]) { items[index].gst_type = value; calculateTotal(); } }

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
    const discount  = items.reduce((sum, item) => sum + (item.discount || 0), 0);
    
    let cgst = 0, sgst = 0;
    items.forEach(item => {
        if (item.gst_type === 'gst_3') {
            cgst += item.total * 0.015;
            sgst += item.total * 0.015;
        } else if (item.gst_type === 'gst_18') {
            cgst += item.total * 0.09;
            sgst += item.total * 0.09;
        }
    });

    const oldGoldEl = document.getElementById('oldGoldAmountInput');
    const oldGold = parseFloat(oldGoldEl?.value) || 0;

    const grand = Math.max(0, subtotal + cgst + sgst - oldGold);
    
    const fmt = v => '\u20B9' + v.toFixed(2);
    document.getElementById('subtotal').textContent       = fmt(subtotal);
    document.getElementById('makingChargeAmount').textContent = fmt(makingAmt);
    document.getElementById('hallmarkAmount').textContent = fmt(hallmark);
    document.getElementById('discountAmount').textContent = '- ' + fmt(discount);
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
        submitBtn.innerHTML = (cgst + sgst > 0) ? '✨ Generate Tax Invoice ✨' : '✨ Generate Cash Memo ✨';
    }
    updateBalanceFromPart();
}

function toggleManualInvoice() {
    const checked = document.getElementById('manualInvoiceToggle').checked;
    document.getElementById('manualInvoiceDiv').style.display = checked ? 'block' : 'none';
    document.getElementById('autoInvoiceInfo').style.display  = checked ? 'none'  : 'block';
}

// Shop Rates
const shopRates = { gold22:0, gold18:0, silver:0, diamond:0 };
const shopDisplayIds = { gold22:'shopGold22Display', gold18:'shopGold18Display', silver:'shopSilverDisplay', diamond:'shopDiamondDisplay' };
const shopInputIds   = { gold22:'shopGold22Input',   gold18:'shopGold18Input',   silver:'shopSilverInput',   diamond:'shopDiamondInput'   };

function loadShopRates() {
    ['gold22','gold18','silver','diamond'].forEach(k => {
        const val = localStorage.getItem('shopRate_' + k);
        if(val && parseFloat(val) > 0) {
            shopRates[k] = parseFloat(val);
            const inputEl = document.getElementById(shopInputIds[k]);
            const dispEl  = document.getElementById(shopDisplayIds[k]);
            if(inputEl) inputEl.value = val;
            if(dispEl) dispEl.textContent = '\u20B9' + parseFloat(val).toLocaleString('en-IN');
            previewShopRate(k);
        }
    });
    const saved = localStorage.getItem('shopRateSavedAt');
    if(saved) {
        const statusEl = document.getElementById('shopRateSaveStatus');
        const lastSavedEl = document.getElementById('shopRateLastSaved');
        if(statusEl) statusEl.textContent = '\u2714 Saved';
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
    const val = parseFloat(document.getElementById(shopInputIds[key]).value) || 0;
    shopRates[key] = val;
    document.getElementById(shopDisplayIds[key]).textContent = val > 0 ? '\u20B9' + val.toLocaleString('en-IN') : '\u2014';
    // Show per-gram equivalent
    const perGramIds = { gold22:'shopGold22PerGram', gold18:'shopGold18PerGram', silver:'shopSilverPerGram', diamond:'shopDiamondPerGram' };
    const pgEl = document.getElementById(perGramIds[key]);
    if(pgEl) {
        pgEl.textContent = val > 0 ? '\u2248 \u20B9' + (val/10).toLocaleString('en-IN', {maximumFractionDigits:2}) + ' per gram (used in billing)' : '';
    }
}

// Auto-fill shop rates from live market data
function autoFillShopFromLive() {
    const liveMap = {
        gold22: document.getElementById('shopGold22Input'),
        gold18: null,  // no direct live 18K — compute as 22K * 18/22
        silver: document.getElementById('shopSilverInput'),
    };
    // Gold 22K
    const g22live = metalRates.gold22 * 10; // metalRates is per gram, convert to per 10g
    const g18live = Math.round(g22live * 18 / 22);
    const silvLive = metalRates.silver * 10;

    if(g22live > 0) {
        document.getElementById('shopGold22Input').value = Math.round(g22live);
        previewShopRate('gold22');
    }
    if(g18live > 0) {
        document.getElementById('shopGold18Input').value = g18live;
        previewShopRate('gold18');
    }
    if(silvLive > 0) {
        document.getElementById('shopSilverInput').value = Math.round(silvLive);
        previewShopRate('silver');
    }
    if(g22live <= 0) alert('Live rates not loaded yet. Click the \u21BB refresh button on Live Metal Rates first.');
}

function saveShopRate(key) {
    const inputEl = document.getElementById(shopInputIds[key]);
    const val = parseFloat(inputEl ? inputEl.value : 0) || 0;
    const labelNames = { gold22: 'Gold 22K', gold18: 'Gold 18K', silver: 'Silver', diamond: 'Diamond' };
    const label = labelNames[key] || key;
    
    if (val <= 0) {
        alert('Please enter a valid price for ' + label + ' before clicking Save!');
        return;
    }
    
    shopRates[key] = val;
    localStorage.setItem('shopRate_' + key, val);
    const now = new Date().toLocaleString('en-IN');
    localStorage.setItem('shopRateSavedAt', now);
    
    document.getElementById(shopDisplayIds[key]).textContent = '\u20B9' + val.toLocaleString('en-IN');
    document.getElementById('shopRateSaveStatus').textContent = '\u2714 Saved';
    document.getElementById('shopRateLastSaved').textContent  = 'Last saved: ' + now;
    
    if (inputEl && inputEl.nextElementSibling) {
        const saveBtn = inputEl.nextElementSibling;
        const origText = saveBtn.textContent;
        saveBtn.textContent = '✓ Saved';
        saveBtn.style.background = '#059669';
        saveBtn.style.color = '#ffffff';
        setTimeout(() => {
            saveBtn.textContent = origText;
            saveBtn.style.background = '';
            saveBtn.style.color = '';
        }, 2000);
    }
    
    showNotif('✔ ' + label + ' shop rate saved: \u20B9' + val.toLocaleString('en-IN') + ' / 10g', 'success');
    calcShopValue();
    refreshActiveBillingRate();
}

function saveAllShopRates() {
    let saved = 0;
    ['gold22','gold18','silver','diamond'].forEach(k => {
        const inputEl = document.getElementById(shopInputIds[k]);
        const val = parseFloat(inputEl ? inputEl.value : 0) || 0;
        if(val > 0) {
            shopRates[k] = val;
            localStorage.setItem('shopRate_' + k, val);
            document.getElementById(shopDisplayIds[k]).textContent = '\u20B9' + val.toLocaleString('en-IN');
            saved++;
        }
    });
    if(saved === 0) { alert('Please enter a price in at least one rate field before saving!'); return; }
    const now = new Date().toLocaleString('en-IN');
    localStorage.setItem('shopRateSavedAt', now);
    document.getElementById('shopRateSaveStatus').textContent = '\u2714 All Saved';
    document.getElementById('shopRateLastSaved').textContent  = 'Last saved: ' + now;
    showNotif('✔ All Shop Rates Saved Successfully!', 'success');
    calcShopValue();
    refreshActiveBillingRate();
}

function getShopRateForCategory(category) {
    const c = (category || '').trim().toLowerCase();
    let key = '';
    if (c.includes('22')) key = 'gold22';
    else if (c.includes('18')) key = 'gold18';
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
const metalRates = { gold24:0, gold22:0, silver:0, platinum:0 };

async function fetchMetalPrices() {
    const statusEl = document.getElementById('metalPriceStatus');
    statusEl.textContent = '\u21BB Fetching...';
    try {
        const res  = await fetch('metal_rates.php?t=' + Date.now());
        const data = await res.json();
        if(!data.success) throw new Error('API error');
        metalRates.gold24   = data.gold24   / 10;
        metalRates.gold22   = data.gold22   / 10;
        metalRates.silver   = data.silver   / 10;
        metalRates.platinum = data.platinum / 10;
        const fmt = v => '\u20B9' + Math.round(v).toLocaleString('en-IN');
        document.getElementById('gold24Price').textContent   = fmt(data.gold24);
        document.getElementById('gold22Price').textContent   = fmt(data.gold22);
        document.getElementById('silverPrice').textContent   = fmt(data.silver);
        document.getElementById('platinumPrice').textContent = fmt(data.platinum);
        ['gold24Change','gold22Change','silverChange','platinumChange'].forEach(id => {
            document.getElementById(id).textContent = data.fallback ? '\u26A0 Approx' : 'per 10g';
            document.getElementById(id).style.color = data.fallback ? '#d97706' : '#9ca3af';
        });
        statusEl.textContent = data.fallback ? '\u26A0 Approx' : (data.cached ? '\u25CF Cached' : '\u25CF Live');
        statusEl.style.color = data.fallback ? '#d97706' : '#059669';
        const infoEl = document.getElementById('metalUpdateInfo');
        if(infoEl) infoEl.textContent = 'Source: ' + data.source + ' \u00B7 ' + data.updated;
        calcMetalValue();
    } catch(err) {
        statusEl.textContent = '\u2717 Offline'; statusEl.style.color = '#dc2626';
        document.getElementById('gold24Price').textContent   = '\u20B91,42,530';
        document.getElementById('gold22Price').textContent   = '\u20B91,30,650';
        document.getElementById('silverPrice').textContent   = '\u20B92,160';
        document.getElementById('platinumPrice').textContent = '\u20B951,510';
        metalRates.gold24=14253; metalRates.gold22=13065; metalRates.silver=216; metalRates.platinum=5151;
        calcMetalValue();
    }
}

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

// Part Payment
function togglePartPayment() {
    const status = document.getElementById('paymentStatus').value;
    const method = document.getElementById('paymentMethod').value;
    const partDiv = document.getElementById('partAmountDiv');
    const balDiv  = document.getElementById('balanceDisplay');
    const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
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
        if(data.success) { showNotif(data.message, 'success'); }
        else { showNotif(data.message, 'error'); }
    })
    .catch(error => { showNotif('\u26A0\uFE0F ' + (error?.message || 'Reminder email could not be sent.'), 'error'); });
}

// ── NEW: Due Today — Send Reminder ────────────────────────────────────────
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

// Form submit validation
document.getElementById('billingForm').addEventListener('submit', function(e) {
    if(items.length === 0) { e.preventDefault(); alert('\u274C Please add at least one product!'); return false; }
    if(!document.getElementById('customerName').value.trim()) { e.preventDefault(); alert('\u274C Please enter customer name!'); return false; }
    if(!document.getElementById('customerMobile').value.trim()) { e.preventDefault(); alert('\u274C Please enter customer mobile number!'); return false; }

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
    document.getElementById('hiddenItems').value = JSON.stringify(buildItemsForSubmit());
});

// Init
loadShopRates();
updateItemsList();
populateHuidOptions();
updateReminderButtonVisibility();
document.getElementById('customerEmail').addEventListener('input', updateReminderButtonVisibility);
document.getElementById('dueDate').addEventListener('change', updateDueDateHint);
document.getElementById('customerMobile').addEventListener('input', updateReminderButtonVisibility);
document.getElementById('paymentStatus').addEventListener('change', updateReminderButtonVisibility);
if(document.getElementById('manualHuid')) {
    document.getElementById('manualHuid').addEventListener('input', populateHuidOptions);
}
if(ALL_PRODUCTS.length > 0) { filterProductSelect(''); }
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





