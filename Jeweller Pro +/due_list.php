<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';
require_once 'config/mail_config.php';

$is_logged_in = isset($_SESSION['user_id']);
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';
$message = '';

// Create due update history table if it does not exist
$create_due_history = "CREATE TABLE IF NOT EXISTS due_update_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    previous_balance DECIMAL(10,2) NOT NULL,
    new_balance DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_due_history);

$chkModeCol = mysqli_query($conn, "SHOW COLUMNS FROM due_update_history LIKE 'payment_mode'");
if ($chkModeCol && mysqli_num_rows($chkModeCol) == 0) {
    @mysqli_query($conn, "ALTER TABLE due_update_history ADD COLUMN payment_mode VARCHAR(50) DEFAULT 'Cash'");
}

// ── AJAX: Receive Due Payment ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_due_payment') {
    header('Content-Type: application/json');
    $id          = intval($_POST['id'] ?? 0);
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $mode        = mysqli_real_escape_string($conn, trim($_POST['payment_mode'] ?? 'Cash'));

    if ($id <= 0 || $amount_paid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment amount or invoice ID.']);
        exit();
    }

    $current = mysqli_fetch_assoc(mysqli_query($conn, "SELECT invoice_no, balance_amount, total_amount, paid_amount FROM invoices WHERE id = $id LIMIT 1"));
    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
        exit();
    }

    $invoice_no_val   = $current['invoice_no'] ?? '';
    $previous_balance = floatval($current['balance_amount'] ?? 0);
    $current_paid     = floatval($current['paid_amount'] ?? 0);
    
    $new_balance  = max(0, $previous_balance - $amount_paid);
    $new_paid     = $current_paid + $amount_paid;
    $payment_date = date('Y-m-d');
    $payment_status = ($new_balance <= 0) ? 'paid' : 'part';

    $next_due_date = trim($_POST['next_due_date'] ?? '');
    $due_date_sql  = "";
    if ($new_balance <= 0) {
        $due_date_sql = ", due_date = NULL";
    } elseif (!empty($next_due_date)) {
        $safe_next_due = mysqli_real_escape_string($conn, $next_due_date);
        $due_date_sql  = ", due_date = '$safe_next_due'";
    }

    // Insert history
    mysqli_query($conn, "INSERT INTO due_update_history (invoice_id, previous_balance, new_balance, amount_paid, payment_date, payment_mode) VALUES ($id, $previous_balance, $new_balance, $amount_paid, '$payment_date', '$mode')");
    $history_id = mysqli_insert_id($conn);

    // Update invoice
    $upd = mysqli_query($conn, "UPDATE invoices SET balance_amount = $new_balance, paid_amount = $new_paid, payment_status = '$payment_status'$due_date_sql WHERE id = $id");

    echo json_encode([
        'success'          => (bool)$upd,
        'message'          => 'Payment of ₹' . number_format($amount_paid, 2) . ' received (' . $mode . ') successfully!',
        'id'               => $id,
        'invoice_no'       => $invoice_no_val,
        'previous_balance' => $previous_balance,
        'new_balance'      => $new_balance,
        'next_due_date'    => $next_due_date,
        'history_id'       => $history_id,
        'is_fully_paid'    => ($new_balance <= 0)
    ]);
    exit();
}

// ── AJAX: send reminder ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_send_reminder') {
    header('Content-Type: application/json');
    $customer_email  = trim($_POST['customer_email']  ?? '');
    $customer_name   = trim($_POST['customer_name']   ?? 'Customer');
    $customer_mobile = trim($_POST['customer_mobile'] ?? '');
    $invoice_no      = trim($_POST['invoice_no']      ?? '');
    $balance_amount  = floatval($_POST['balance_amount'] ?? 0);

    // Try to look up email by mobile if not provided
    if (empty($customer_email) && !empty($customer_mobile)) {
        $safe_mobile = mysqli_real_escape_string($conn, $customer_mobile);
        $cust_res = mysqli_query($conn, "SELECT email FROM customers WHERE mobile = '$safe_mobile' LIMIT 1");
        if ($cust_res && mysqli_num_rows($cust_res) > 0) {
            $customer_email = trim(mysqli_fetch_assoc($cust_res)['email'] ?? '');
        }
    }

    if (empty($customer_email)) {
        echo json_encode(['success' => false, 'message' => 'Customer email is required to send reminder.']);
        exit();
    }
    if ($balance_amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'No unpaid amount to remind.']);
        exit();
    }

    $subject = 'Payment Reminder from MAA GOURI JEWELLERS';
    $body    = '<p>Dear ' . htmlspecialchars($customer_name) . ',</p>'
             . '<p>This is a reminder that an amount of <strong>&#8377;' . number_format($balance_amount, 2) . '</strong> is still due.'
             . ($invoice_no ? ' (Invoice: ' . htmlspecialchars($invoice_no) . ')' : '') . '</p>'
             . '<p>Please make the remaining payment at your earliest convenience.</p>'
             . '<p>Thank you,<br>MAA GOURI JEWELLERS</p>';

    error_log('[due_list] Reminder attempt -> to: ' . $customer_email . ' | invoice: ' . $invoice_no . ' | balance: ' . $balance_amount);
    $res = sendSMTPMail($customer_email, $subject, $body);
    if (!empty($res['success'])) {
        if (!empty($invoice_no)) {
            $safe_inv = mysqli_real_escape_string($conn, $invoice_no);
            mysqli_query($conn, "UPDATE invoices SET reminder_sent = 1 WHERE invoice_no = '$safe_inv'");
        }
        error_log('[due_list] Reminder sent OK -> ' . $customer_email);
        echo json_encode(['success' => true, 'message' => 'Reminder sent to ' . $customer_email]);
    } else {
        $errMsg = $res['message'] ?? 'Failed to send email.';
        error_log('[due_list] Reminder failed -> ' . $customer_email . ' | error: ' . $errMsg);
        echo json_encode(['success' => false, 'message' => $errMsg]);
    }
    exit();
}

// ── POST: clear due for an invoice ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_due') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $upd = mysqli_query($conn, "UPDATE invoices SET balance_amount = 0, due_date = NULL WHERE id = $id");
        $message = $upd ? 'Due cleared successfully.' : 'Failed to clear due: ' . mysqli_error($conn);
    }
}

// ── AJAX: fetch due update history for modal ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_history') {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid invoice ID.']);
        exit();
    }

    $inv_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT invoice_no, COALESCE(paid_amount, 0) AS paid_amount FROM invoices WHERE id = $id LIMIT 1"));
    $invoice_no = $inv_row['invoice_no'] ?? '';
    $running = floatval($inv_row['paid_amount'] ?? 0);
    $history = [];

    $res = mysqli_query($conn, "SELECT id AS history_id, DATE_FORMAT(payment_date, '%d-%m-%Y') AS payment_date, amount_paid, previous_balance, new_balance
                               FROM due_update_history
                               WHERE invoice_id = $id
                               ORDER BY payment_date ASC, created_at ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $amt = floatval($row['amount_paid'] ?? 0);
            $running += $amt;
            $row['invoice_no'] = $invoice_no;
            $row['total_amount_paid'] = number_format($running, 2, '.', '');
            $history[] = $row;
        }
        $history = array_reverse($history);
    }
    echo json_encode(['success' => true, 'history' => $history]);
    exit();
}

// ── Export all due customers history as CSV ─────────────────────────────────
if (($_GET['action'] ?? '') === 'export_due_history') {
    $hasPaidAmountCol = false;
    $colRes = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'paid_amount'");
    if ($colRes && mysqli_num_rows($colRes) > 0) {
        $hasPaidAmountCol = true;
    }
    $paidColumn = $hasPaidAmountCol ? 'COALESCE(i.paid_amount,0)' : '0';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="due_history_export_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Invoice ID','Invoice No','Customer Name','Customer Mobile','Customer Email','Invoice Total','Due Amount','Due Date','Initial Paid Amount','Payment Date','Amount Paid','Total Amount Paid','Old Due','New Due']);

    $dueQuery = "SELECT i.id, i.invoice_no, i.customer_name, i.customer_mobile, COALESCE(c.email, '') AS customer_email, COALESCE(i.total_amount, 0) AS total_amount, COALESCE(i.balance_amount, 0) AS balance_amount, COALESCE(i.due_date, '') AS due_date, $paidColumn AS initial_paid_amount
                 FROM invoices i
                 LEFT JOIN customers c ON c.mobile = i.customer_mobile
                 WHERE COALESCE(i.balance_amount, 0) > 0
                 ORDER BY i.customer_name, i.invoice_no";
    $dueRes = mysqli_query($conn, $dueQuery);
    if ($dueRes) {
        while ($inv = mysqli_fetch_assoc($dueRes)) {
            $invoiceId = intval($inv['id']);
            $running = floatval($inv['initial_paid_amount']);
            $historyRes = mysqli_query($conn, "SELECT DATE_FORMAT(payment_date, '%d-%m-%Y') AS payment_date, amount_paid, previous_balance, new_balance
                                               FROM due_update_history
                                               WHERE invoice_id = $invoiceId
                                               ORDER BY payment_date ASC, created_at ASC");
            if ($historyRes && mysqli_num_rows($historyRes) > 0) {
                while ($h = mysqli_fetch_assoc($historyRes)) {
                    $running += floatval($h['amount_paid']);
                    fputcsv($output, [
                        $inv['id'],
                        $inv['invoice_no'],
                        $inv['customer_name'],
                        $inv['customer_mobile'],
                        $inv['customer_email'],
                        $inv['total_amount'],
                        $inv['balance_amount'],
                        $inv['due_date'],
                        $inv['initial_paid_amount'],
                        $h['payment_date'],
                        $h['amount_paid'],
                        number_format($running, 2, '.', ''),
                        $h['previous_balance'],
                        $h['new_balance']
                    ]);
                }
            } else {
                fputcsv($output, [
                    $inv['id'],
                    $inv['invoice_no'],
                    $inv['customer_name'],
                    $inv['customer_mobile'],
                    $inv['customer_email'],
                    $inv['total_amount'],
                    $inv['balance_amount'],
                    $inv['due_date'],
                    $inv['initial_paid_amount'],
                    '',
                    '',
                    number_format($running, 2, '.', ''),
                    '',
                    ''
                ]);
            }
        }
    }
    fclose($output);
    exit();
}

// ── Helper: build & send account statement email after a due update ────────────
function sendDueStatementEmail($conn, $id, $new_balance, $due_date) {
    $result = ['attempted' => false, 'sent' => false, 'message' => ''];

    $invRes = mysqli_query($conn, "SELECT i.invoice_no, i.customer_name, i.customer_mobile,
                                            COALESCE(c.email, '') AS customer_email,
                                            COALESCE(i.total_amount, 0) AS total_amount,
                                            COALESCE(i.paid_amount, 0) AS paid_amount
                                     FROM invoices i
                                     LEFT JOIN customers c ON c.mobile = i.customer_mobile
                                     WHERE i.id = $id
                                     LIMIT 1");
    $inv = $invRes ? mysqli_fetch_assoc($invRes) : null;
    if (!$inv) {
        $result['message'] = 'Invoice not found — statement not sent.';
        return $result;
    }

    $email = trim($inv['customer_email']);
    if (empty($email)) {
        $result['message'] = 'No email on file — statement not sent.';
        return $result;
    }

    $result['attempted'] = true;

    // Payment history in chronological order with a running "total paid" total
    $running  = floatval($inv['paid_amount']);
    $histRows = [];
    $histRes = mysqli_query($conn, "SELECT DATE_FORMAT(payment_date, '%d-%m-%Y') AS payment_date,
                                            amount_paid, previous_balance, new_balance
                                     FROM due_update_history
                                     WHERE invoice_id = $id
                                     ORDER BY payment_date ASC, created_at ASC");
    if ($histRes) {
        while ($h = mysqli_fetch_assoc($histRes)) {
            $running += floatval($h['amount_paid']);
            $h['total_paid'] = $running;
            $histRows[] = $h;
        }
    }

    $historyHtml = '<p style="color:#6b7280;">No previous payments recorded yet.</p>';
    if (!empty($histRows)) {
        $historyHtml = '<table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:13px;">'
                      . '<thead><tr style="background:#f3f4f6;">'
                      . '<th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">Date</th>'
                      . '<th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">Amount Paid</th>'
                      . '<th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">Total Paid</th>'
                      . '<th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">Previous Due</th>'
                      . '<th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">New Due</th>'
                      . '</tr></thead><tbody>';
        foreach ($histRows as $h) {
            $historyHtml .= '<tr>'
                           . '<td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($h['payment_date']) . '</td>'
                           . '<td style="padding:8px;border:1px solid #e5e7eb;">&#8377;' . number_format(floatval($h['amount_paid']), 2) . '</td>'
                           . '<td style="padding:8px;border:1px solid #e5e7eb;">&#8377;' . number_format(floatval($h['total_paid']), 2) . '</td>'
                           . '<td style="padding:8px;border:1px solid #e5e7eb;">&#8377;' . number_format(floatval($h['previous_balance']), 2) . '</td>'
                           . '<td style="padding:8px;border:1px solid #e5e7eb;">&#8377;' . number_format(floatval($h['new_balance']), 2) . '</td>'
                           . '</tr>';
        }
        $historyHtml .= '</tbody></table>';
    }

    $due_date_display = ($due_date !== '') ? date('d-m-Y', strtotime($due_date)) : 'Not set';

    $subject = 'Account Statement - Invoice ' . $inv['invoice_no'] . ' | MAA GOURI JEWELLERS';
    $body = '<p>Dear ' . htmlspecialchars($inv['customer_name']) . ',</p>'
         . '<p>Thank you for doing business with <strong>MAA GOURI JEWELLERS</strong>. Below is your updated statement of account for Invoice <strong>' . htmlspecialchars($inv['invoice_no']) . '</strong>:</p>'
         . '<table style="width:100%;border-collapse:collapse;margin:12px 0;font-size:14px;">'
         . '<tr style="background:#f9fafb;"><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Invoice Total Amount</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">&#8377;' . number_format(floatval($inv['total_amount']), 2) . '</td></tr>'
         . '<tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Current Due Balance</strong></td><td style="padding:8px;border:1px solid #e5e7eb;color:#dc2626;font-weight:bold;">&#8377;' . number_format(floatval($new_balance), 2) . '</td></tr>'
         . '<tr style="background:#f9fafb;"><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Due Date</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($due_date_display) . '</td></tr>'
         . '</table>'
         . '<h4 style="margin:16px 0 6px;color:#374151;">Payment History</h4>'
         . $historyHtml
         . '<p style="margin-top:16px;">If you have any questions regarding this statement, please contact us at +91-8617536679.</p>'
         . '<p>Best regards,<br><strong>MAA GOURI JEWELLERS</strong></p>';

    error_log('[due_list] Statement attempt -> to: ' . $email . ' | invoice: ' . $inv['invoice_no'] . ' | new balance: ' . $new_balance);
    $send = sendSMTPMail($email, $subject, $body);
    if (!empty($send['success'])) {
        error_log('[due_list] Statement sent OK -> ' . $email);
        $result['sent'] = true;
    } else {
        $errMsg = $send['message'] ?? 'Failed to send statement email.';
        error_log('[due_list] Statement failed -> ' . $email . ' | error: ' . $errMsg);
        $result['sent']    = false;
        $result['message'] = $errMsg;
    }
    return $result;
}

// ── POST: update due amount / due date ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $id       = intval($_POST['id'] ?? 0);
    $balance  = floatval($_POST['balance_amount'] ?? 0);
    $due_date = trim($_POST['due_date'] ?? '');
    $due_date_sql = ($due_date === '') ? 'NULL' : "'" . mysqli_real_escape_string($conn, $due_date) . "'";

    $statement = ['attempted' => false, 'sent' => false, 'message' => ''];
    $history_id = 0;

    if ($id > 0) {
        $bal = mysqli_real_escape_string($conn, number_format($balance, 2, '.', ''));
        $current = mysqli_fetch_assoc(mysqli_query($conn, "SELECT invoice_no, balance_amount FROM invoices WHERE id = $id LIMIT 1"));
        $invoice_no_val = $current['invoice_no'] ?? '';
        $previous_balance = floatval($current['balance_amount'] ?? 0);
        $new_balance = floatval($bal);
        if ($previous_balance > $new_balance) {
            $amount_paid = $previous_balance - $new_balance;
            $payment_date = date('Y-m-d');
            mysqli_query($conn, "INSERT INTO due_update_history (invoice_id, previous_balance, new_balance, amount_paid, payment_date) VALUES ($id, $previous_balance, $new_balance, $amount_paid, '$payment_date')");
            $history_id = mysqli_insert_id($conn);
        }
        $upd = mysqli_query($conn, "UPDATE invoices SET balance_amount = $bal, due_date = $due_date_sql WHERE id = $id");
        $ok  = (bool)$upd;
        $msg = $ok ? 'Updated successfully.' : 'Update failed: ' . mysqli_error($conn);

        // Auto-send the account statement email to the customer on every successful save
        if ($ok) {
            $statement = sendDueStatementEmail($conn, $id, $new_balance, $due_date);
            if ($statement['attempted']) {
                $msg .= $statement['sent'] ? ' Statement emailed to customer.' : (' Statement email failed: ' . $statement['message']);
            }
        }

        $isAjax = (isset($_POST['ajax']) && $_POST['ajax'] == '1')
               || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success'             => $ok,
                'message'             => $msg,
                'invoice_no'          => $invoice_no_val,
                'history_id'          => $history_id,
                'statement_attempted' => $statement['attempted'],
                'statement_sent'      => $statement['sent'],
                'statement_message'   => $statement['message'],
            ]);
            exit();
        }
        $message = $msg;
    }
}

// ── Fetch all invoices with a due balance (with search support) ───────────────
$search_query = mysqli_real_escape_string($conn, trim($_GET['search'] ?? ''));
$search_where = "";
if (!empty($search_query)) {
    $search_where = " AND (i.customer_name LIKE '%$search_query%' OR i.customer_mobile LIKE '%$search_query%' OR i.invoice_no LIKE '%$search_query%' OR c.email LIKE '%$search_query%') ";
}

$q = "SELECT i.id, i.invoice_no, i.customer_name, i.customer_mobile,
             COALESCE(c.email, '') AS customer_email,
             COALESCE(i.total_amount, 0) AS total_amount,
             i.balance_amount, i.due_date,
             GROUP_CONCAT(DISTINCT COALESCE(ii.product_name, '') SEPARATOR ', ') AS items
      FROM invoices i
      LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
      LEFT JOIN customers c      ON c.mobile = i.customer_mobile
      WHERE i.balance_amount > 0 $search_where
      GROUP BY i.id
      ORDER BY i.due_date IS NULL, i.due_date ASC, i.created_at DESC";

$res  = mysqli_query($conn, $q);
$rows = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
}

$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>Due List – MAA GOURI JEWELLERS</title>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Fallback CDN for Font Awesome in case the primary is blocked -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/theme.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

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

.sidebar-nav { flex: 1; padding: 10px 0; overflow-y: auto; overflow-x: hidden; }
.sidebar-section-label { padding: 10px 20px 4px; color: rgba(255,255,255,0.45); font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; position: sticky; top: 0; background: #011921; color: #f5c842; z-index: 10; }
.sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent; position: relative; }
.sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
.sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
.sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; opacity: 0.9; }
.sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }
.sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
.sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
.sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
.sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }
.sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; border: 1px solid rgba(239,68,68,0.4); }
.sidebar-logout:hover { background: #ef4444; color: #fff; }
.sidebar-login-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(255,255,255,0.2); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; border: 1px solid rgba(255,255,255,0.3); }
.sidebar-login-btn:hover { background: rgba(255,255,255,0.3); }

.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
.sidebar-overlay.active { display: block; }

/* ========== MAIN LAYOUT ========== */
.page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; background: #f9fafb; }
nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important; margin-left: 0; }

/* ========== BURGER MENU ========== */
.burger-menu { width: 28px; height: 20px; position: relative; cursor: pointer; }
.burger-menu span { display: block; position: absolute; height: 3px; width: 100%; background: #ffffff; border-radius: 3px; transition: all 0.3s ease; }
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

/* ── Layout ───────────────────────────────────── */
.page-heading { margin-bottom: 24px; }
.page-heading h1, .page-heading h2 { margin: 0; }
.page-heading p { margin: 0.5rem 0 0 0; color: #7a4e0a; font-size: 14px; }

/* ── Alert ────────────────────────────────────── */
.alert-success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; font-size: 14px; }

/* ── Table shell ──────────────────────────────── */
.due-table-wrap { overflow-x: auto; border-radius: 14px; }
.due-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; min-width: 860px; }

/* ── Header ───────────────────────────────────── */
.due-table thead th {
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    color: #9ca3af;
    padding: 8px 14px;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    background: transparent;
}

/* ── Rows ─────────────────────────────────────── */
.due-table tbody tr {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(15,23,42,0.05);
    transition: box-shadow 0.2s;
}
.due-table tbody tr:hover { box-shadow: 0 4px 18px rgba(15,23,42,0.10); }
.due-table tbody td {
    padding: 13px 14px;
    vertical-align: middle;
    font-size: 13px;
    color: #374151;
    border: none;
}

.due-table tbody td:first-child { border-radius: 12px 0 0 12px; }
.due-table tbody td:last-child  { border-radius: 0 12px 12px 0; }

/* ── Cell helpers ─────────────────────────────── */
.items-cell { max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #6b7280; }
.customer-name { font-weight: 600; color: #111827; }
.inv-badge { display: inline-block; margin-top: 5px; padding: 2px 9px; border-radius: 999px; font-size: 11px; background: #fef3c7; color: #92400e; font-weight: 600; }
.mobile-text { font-weight: 600; color: #374151; }
.email-text  { color: #6b7280; font-size: 12px; max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ── Inline form inputs ───────────────────────── */
.field-label { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.inline-input {
    width: 100%; border: 1.5px solid #e5e7eb; border-radius: 8px;
    padding: 7px 10px; font-size: 13px; color: #111827;
    transition: border-color 0.15s;
    background: #f9fafb;
}
.inline-input:focus { outline: none; border-color: #f59e0b; background: #fff; }

/* ── Buttons ──────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: 5px; padding: 7px 13px; border-radius: 8px; border: 0; cursor: pointer; font-weight: 600; font-size: 12px; transition: opacity 0.15s, transform 0.1s; white-space: nowrap; }
.btn:active { transform: scale(0.97); }
.btn:disabled { opacity: 0.55; cursor: not-allowed; }
.btn-save   { background: #10b981; color: #fff; }
.btn-save:hover   { background: #059669; }
.btn-remind { background: #f59e0b; color: #fff; }
.btn-remind:hover { background: #d97706; }    .btn-history { background: #2563eb; color: #fff; border: 1px solid rgba(37,99,235,0.35); padding: 9px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; }
    .btn-history:hover { background: #1d4ed8; box-shadow: 0 10px 20px rgba(37,99,235,0.18); }.btn-delete { background: #fee2e2; color: #dc2626; border: 1.5px solid rgba(239,68,68,0.2); border-radius: 8px; padding: 7px 13px; font-weight: 600; font-size: 12px; cursor: pointer; transition: background 0.15s, color 0.15s; }
.btn-delete:hover { background: #ef4444; color: #fff; }
.btn-back { background: linear-gradient(135deg, #d68b16, #b5730e); color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; transition: all 0.2s; }
.btn-back:hover { background: linear-gradient(135deg, #e8a020, #c8830e); transform: translateY(-2px); box-shadow: 0 4px 16px rgba(214,139,22,0.35); }
    .btn-export { background: #111827; color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; transition: all 0.2s; border: 1px solid rgba(255,255,255,0.15); }
    .btn-export:hover { background: #1f2937; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(0,0,0,0.12); }

/* ── Action Grid Buttons ──────────────────────── */
.action-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; width: 100%; min-width: 220px; }
.action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    height: 34px;
    width: 100%;
    padding: 0 8px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.action-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.12); }
.action-btn-save { background: #10b981; color: #fff; }
.action-btn-save:hover { background: #059669; }
.action-btn-receive { background: linear-gradient(135deg, #7a4e0a, #d68b16); color: #fff; }
.action-btn-receive:hover { background: linear-gradient(135deg, #8a5e1a, #e69b26); }
.action-btn-history { background: #2563eb; color: #fff; }
.action-btn-history:hover { background: #1d4ed8; }
.action-btn-delete { background: #fee2e2; color: #dc2626; border: 1px solid rgba(239,68,68,0.3); }
.action-btn-delete:hover { background: #ef4444; color: #fff; }
.action-btn-whatsapp { background: linear-gradient(135deg, #25d366, #128c7e); color: #fff; }
.action-btn-whatsapp:hover { background: linear-gradient(135deg, #20bd5a, #0e7a6e); }

/* Due amount display - read only red */
.due-amount-display {
    font-size: 16px;
    font-weight: 800;
    color: #dc2626;
    padding: 6px 4px;
    display: block;
    letter-spacing: 0.3px;
}
.due-amount-label {
    font-size: 10px;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
    display: block;
}
/* ── Toast ────────────────────────────────────── */
#toast {
    position: fixed; bottom: 28px; right: 28px; z-index: 9999;
    padding: 14px 22px; border-radius: 12px; font-size: 13px;
    font-weight: 600; color: #fff; box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    opacity: 0; transform: translateY(12px);
    transition: opacity 0.25s, transform 0.25s;
    pointer-events: none;
}
#toast.show { opacity: 1; transform: translateY(0); }
#toast.success { background: #10b981; }
#toast.error   { background: #ef4444; }

@media (max-width: 720px) {
    .items-cell  { max-width: 140px; }
    .due-table td, .due-table thead th { padding: 10px 10px; }
}

/* ── Loading Overlay ──────────────────────────── */
#loadingOverlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.95);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    opacity: 1;
    transition: opacity 0.3s ease;
    pointer-events: all;
}
#loadingOverlay.hidden {
    opacity: 0;
    pointer-events: none;
}
.loading-spinner {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
}
.spinner {
    width: 60px;
    height: 60px;
    border: 5px solid #f0f0f0;
    border-top-color: #d68b16;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
.loading-text {
    font-size: 16px;
    font-weight: 600;
    color: #7a4e0a;
    font-family: 'Poppins', sans-serif;
}
</style>
</head>
<body class="<?php echo $theme == 'light' ? 'light-theme' : 'dark-theme'; ?>" style="background:#F5F5F5; margin:0; padding:0;">

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

        <!-- Logo -->
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

<script>
function hideLoadingOverlay() {
    const isReload = performance.getEntriesByType("navigation")[0]?.type === "reload";
    const hasVisited = sessionStorage.getItem('visited');

    const overlay = document.getElementById('loadingOverlay');
    if(overlay) {
        if (!hasVisited || isReload) {
            sessionStorage.setItem('visited', 'true');
            setTimeout(() => {
                overlay.classList.add('hidden');
            }, 300);
        } else {
            overlay.style.display = 'none';
            overlay.classList.add('hidden');
            // Animate the content wrapper, NOT body (body transform breaks position:fixed sidebar)
            const pw = document.querySelector('.page-wrapper');
            if(pw) { pw.style.animation = 'slideInFromRightGlobal 0.3s ease-out forwards'; }
        }
    }
}

function showLoadingOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    if(overlay) {
        overlay.style.display = 'flex';
        overlay.classList.remove('hidden');
    }
}

// Hide loading overlay when page is fully loaded
if(document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hideLoadingOverlay);
} else {
    hideLoadingOverlay();
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
function filterDueTable(query) {
    const term = query.trim().toLowerCase();
    const rows = document.querySelectorAll('.due-table tbody tr');
    rows.forEach(row => {
        if (row.classList.contains('empty-row')) return;
        const text = row.innerText.toLowerCase();
        if (term === '' || text.includes(term)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

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

    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>
        <a href="index.php"><i class="fas fa-home"></i> HOME</a>
        <a href="billing.php"><i class="fas fa-receipt"></i> BILLING</a>
        <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
        <a href="customers.php"><i class="fas fa-users"></i> CUSTOMERS</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
        <a href="due_list.php" class="active"><i class="fas fa-hourglass-half"></i> DUE LIST</a>
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="purchase.php"><i class="fas fa-book"></i> PURCHASE</a>
        <a href="contacts.php"><i class="fas fa-address-book"></i> CONTACTS</a>
        <a href="accounts.php"><i class="fas fa-calculator"></i> ACCOUNTS</a>
    </nav>

    <!-- User Info + Logout -->
    <div class="sidebar-user">
        <?php if($is_logged_in): ?>
        <div class="sidebar-user-info">
            <svg width="28" height="28" viewBox="0 0 496 512" aria-hidden="true" focusable="false" style="flex-shrink:0;color:inherit;">
                <path fill="currentColor" d="M248 8C111 8 0 119 0 256s111 248 248 248 248-111 248-248S385 8 248 8zm0 96a72 72 0 1 1 0 144 72 72 0 0 1 0-144zm0 344c-59.6 0-112.9-32.7-139.7-80.4 7.1-44 88.4-68.5 139.7-68.5 51.3 0 132.6 24.5 139.7 68.5C360.9 415.3 307.6 448 248 448z"></path>
            </svg>
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

<!-- ========== TOP NAVBAR ========== -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">

            <!-- Left: Logo + Title -->
            <div class="flex items-center space-x-3">
                <?php
                $logo_found = false;
                foreach($logo_paths as $path) {
                    if(file_exists($path)) {
                        echo '<img src="'.$path.'" alt="Logo" style="height:40px;width:auto;max-width:44px;object-fit:contain;display:inline-block;">';
                        $logo_found = true; break;
                    }
                }
                if(!$logo_found) echo '<i class="fas fa-receipt" style="color:#fff;font-size:24px;"></i>';
                ?>
                <div>
                    <h1 class="text-lg sm:text-xl font-bold" style="color:#fff;margin:0;">Due List</h1>
                </div>
            </div>

            <!-- Right Side -->
            <div class="ml-auto flex items-center gap-4">
                <?php if($is_logged_in): ?>
                <span class="text-sm font-medium text-white flex items-center">
                    <svg width="16" height="16" viewBox="0 0 448 512" aria-hidden="true" focusable="false" style="margin-right:8px;display:inline-block;color:inherit;">
                        <path fill="currentColor" d="M313.6 304c-28.7 14.1-61.9 24-97.6 24s-68.9-9.9-97.6-24C53.6 330.4 0 404.7 0 496h448c0-91.3-53.6-165.6-134.4-192zM224 256a128 128 0 1 0 0-256 128 128 0 0 0 0 256z"></path>
                    </svg>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                </span>
                <?php else: ?>
                <a href="login.php" class="text-sm font-medium text-white hover:opacity-80">
                    <i class="fas fa-sign-in-alt mr-1"></i> LOGIN
                </a>
                <?php endif; ?>

                <!-- Mobile burger -->
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

<!-- ========== PAGE WRAPPER ========== -->
<div class="page-wrapper">
<div class="container mx-auto px-4 sm:px-6 py-6 sm:py-8">

    <!-- Header -->
    <div class="page-heading">
        <h2 class="text-2xl sm:text-3xl font-bold" style="color:#800020;font-family:'Poppins',serif;">
            <i class="fas fa-list mr-2" style="color:#d68b16;"></i> Customers with Due Amounts
        </h2>
        <p>Pending invoices — update amount or due date, then save. Send email reminders directly.</p>
    </div>

    <!-- Navigation & Search Bar -->
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <a href="reports.php" class="btn-back">
                <i class="fas fa-arrow-left mr-1"></i> Back to Reports
            </a>
            <a href="due_list.php?action=export_due_history" onclick="showLoadingOverlay()" class="btn btn-export">
                <i class="fas fa-file-csv mr-1"></i> Export Due History
            </a>
        </div>

        <!-- Search Form & Live Filter -->
        <form method="get" action="due_list.php" style="display:flex;gap:8px;align-items:center;margin:0;flex-wrap:wrap;flex:1;max-width:520px;justify-content:flex-end;">
            <div style="position:relative;flex:1;min-width:240px;">
                <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#d68b16;font-size:14px;"></i>
                <input type="text" name="search" id="dueSearchInput" placeholder="Search Customer, Mobile, Inv No..."
                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                       onkeyup="filterDueTable(this.value)"
                       style="width:100%;padding:9px 12px 9px 36px;border:1.5px solid #d68b16;border-radius:8px;font-size:13px;background:#fff;color:#1e293b;outline:none;box-shadow:0 2px 6px rgba(122,78,10,0.08);">
            </div>
            <button type="submit" class="btn" style="background:linear-gradient(135deg,#7a4e0a,#d68b16);color:#fff;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;box-shadow:0 3px 10px rgba(214,139,22,0.3);border:none;cursor:pointer;">
                <i class="fas fa-search mr-1"></i> Search
            </button>
            <?php if(!empty($_GET['search'])): ?>
            <a href="due_list.php" class="btn" style="background:#e2e8f0;color:#475569;padding:9px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">
                <i class="fas fa-times mr-1"></i> Clear
            </a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($message): ?>
    <div class="alert-success"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Table -->
    <div class="due-table-wrap">
    <table class="due-table">
        <thead>
            <tr>
                <th style="width:36px">#</th>
                <th>Customer</th>
                <th style="width:115px">Phone</th>
                <th style="width:155px">Email</th>
                <th>Items</th>
                <th style="width:130px">Due Amount (₹)</th>
                <th style="width:135px">Due Date</th>
                <th style="width:280px;text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr class="empty-row"><td colspan="8"><i class="fas fa-check-circle mr-2" style="color:#10b981;"></i>No due invoices found.</td></tr>
        <?php else: $i = 1; foreach ($rows as $r): ?>
        <tr>
            <!-- # -->
            <td style="color:#9ca3af;font-weight:600;text-align:center;"><?php echo $i++; ?></td>

            <!-- Customer -->
            <td>
                <div class="customer-name"><?php echo htmlspecialchars($r['customer_name']); ?></div>
                <span class="inv-badge">Inv: <?php echo htmlspecialchars($r['invoice_no']); ?></span>
            </td>

            <!-- Phone -->
            <td><span class="mobile-text"><?php echo htmlspecialchars($r['customer_mobile']); ?></span></td>

            <!-- Email -->
            <td>
                <span class="email-text" title="<?php echo htmlspecialchars($r['customer_email']); ?>">
                    <?php echo $r['customer_email'] ? htmlspecialchars($r['customer_email']) : '<span style="color:#d1d5db">—</span>'; ?>
                </span>
            </td>

            <!-- Items -->
            <td>
                <div class="items-cell" title="<?php echo htmlspecialchars($r['items']); ?>">
                    <?php echo htmlspecialchars($r['items'] ?: '—'); ?>
                </div>
            </td>

            <!-- Due Amount (READ-ONLY, red display) -->
            <td>
                <span class="due-amount-label">Due (₹)</span>
                <span class="due-amount-display due-amount-input"
                      data-id="<?php echo intval($r['id']); ?>"
                      data-balance="<?php echo floatval($r['balance_amount']); ?>">
                    ₹<?php echo number_format((float)$r['balance_amount'], 2); ?>
                </span>
            </td>

            <!-- Due Date (editable) -->
            <td>
                <div class="field-label">Due Date</div>
                <input type="date"
                       class="inline-input due-date-input"
                       data-id="<?php echo intval($r['id']); ?>"
                       value="<?php echo htmlspecialchars($r['due_date'] ?? ''); ?>">
            </td>

            <!-- Actions -->
            <td style="min-width:260px;">
                <div class="action-grid" style="grid-template-columns:1fr 1fr;">
                    <!-- 1. Receive Payment -->
                    <button type="button" class="action-btn action-btn-receive"
                            onclick="openReceiveModal(<?php echo intval($r['id']); ?>, '<?php echo htmlspecialchars(addslashes($r['invoice_no'])); ?>', '<?php echo htmlspecialchars(addslashes($r['customer_name'])); ?>', <?php echo floatval($r['total_amount']); ?>, <?php echo floatval($r['balance_amount']); ?>, '<?php echo htmlspecialchars(addslashes($r['due_date'] ?? '')); ?>')">
                        <i class="fas fa-hand-holding-usd"></i> Receive
                    </button>

                    <!-- 2. WhatsApp Reminder -->
                    <button type="button" class="action-btn action-btn-whatsapp"
                            onclick="sendWhatsAppReminder('<?php echo htmlspecialchars(addslashes($r['customer_name'])); ?>', '<?php echo htmlspecialchars($r['customer_mobile']); ?>', '<?php echo htmlspecialchars(addslashes($r['invoice_no'])); ?>', <?php echo floatval($r['balance_amount']); ?>, <?php echo floatval($r['total_amount']); ?>)">
                        <i class="fab fa-whatsapp"></i> Payment Reminder
                    </button>

                    <!-- 3. History -->
                    <button type="button" class="action-btn action-btn-history"
                            onclick="openHistoryModal(<?php echo intval($r['id']); ?>, '<?php echo htmlspecialchars(addslashes($r['customer_name'])); ?>')">
                        <i class="fas fa-history"></i> History
                    </button>

                    <!-- 4. Delete Due -->
                    <form method="post" onsubmit="return confirm('Clear due for this invoice?');" style="margin:0;width:100%;">
                        <input type="hidden" name="action" value="delete_due">
                        <input type="hidden" name="id"     value="<?php echo intval($r['id']); ?>">
                        <button type="submit" class="action-btn action-btn-delete">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div><!-- /table-wrap -->

</div><!-- /container -->
    <footer style="background:linear-gradient(0deg,#f5e6c8,#fdf6e3);border-top:2px solid #d68b16;padding:20px;margin-top:40px;text-align:center;">
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology</a>
        </p>
    </footer>
</div><!-- /page-wrapper -->

<!-- Toast -->
<div id="toast"></div>

<script>
/* ── Toast helper ─────────────────────────────────── */
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'show ' + (type || 'success');
    clearTimeout(t._timer);
    t._timer = setTimeout(function() { t.className = ''; }, 3200);
}

/* ── WhatsApp Reminder ────────────────────────────── */
function sendWhatsAppReminder(name, mobile, invoiceNo, due, total) {
    // Clean mobile: remove spaces, dashes, leading 0; add 91 country code
    var cleaned = mobile.replace(/[\s\-\(\)]/g, '');
    if (cleaned.startsWith('0')) cleaned = cleaned.substring(1);
    if (!cleaned.startsWith('91')) cleaned = '91' + cleaned;

    var msg = '';
    if (due <= 0) {
        // Fully paid
        msg = '🙏 Dear ' + name + ',';
        msg += '\n\nThank you for your *complete payment* for Invoice *' + invoiceNo + '*.';
        msg += '\n💰 Total Paid: ₹' + total.toFixed(2);
        msg += '\n✅ Your account is fully cleared.';
        msg += '\n\nThank you for trusting *MAA GOURI JEWELLERS*! 🌟\n📞 +91-8617536679';
    } else {
        // Part paid / still due
        var paid = total - due;
        msg = '🙏 Dear ' + name + ',';
        msg += '\n\nThis is a gentle reminder regarding your pending due for Invoice *' + invoiceNo + '*.';
        msg += '\n\n💳 Invoice Total: ₹' + total.toFixed(2);
        msg += '\n✅ Amount Paid: ₹' + paid.toFixed(2);
        msg += '\n🔴 *Remaining Due: ₹' + due.toFixed(2) + '*';
        msg += '\n\nKindly clear the pending amount at your earliest convenience.';
        msg += '\n\nThank you,\n*MAA GOURI JEWELLERS*\n📞 +91-8617536679';
    }

    var url = 'https://wa.me/' + cleaned + '?text=' + encodeURIComponent(msg);
    window.open(url, '_blank');
}

function openHistoryModal(invoiceId, customerName) {
    var modal = document.getElementById('historyModal');
    var title = document.getElementById('historyModalTitle');
    var body  = document.getElementById('historyModalBody');
    title.textContent = 'Due Update History for ' + customerName;
    body.innerHTML = '<div style="text-align:center;padding:24px;">Loading history…</div>';
    modal.style.display = 'flex';

    var params = new URLSearchParams();
    params.append('action', 'ajax_history');
    params.append('id', invoiceId);

    fetch('due_list.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success) {
            body.innerHTML = '<div style="padding:16px;color:#b91c1c;">' + (data.message || 'Unable to load history.') + '</div>';
            return;
        }
        if (!data.history || data.history.length === 0) {
            body.innerHTML = '<div style="padding:16px;color:#374151;">No update history found.</div>';
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;text-align:left;font-size:13px;"><thead><tr style="background:#f3f4f6;">' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">Date</th>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">Amount Paid</th>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">Total Paid</th>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">Old Due</th>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">New Due</th>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;">Action</th>' +
               '</tr></thead><tbody>';
        data.history.forEach(function(row) {
            var receiptUrl = 'view_pdf.php?invoice_no=' + encodeURIComponent(row.invoice_no) + '&receipt=1' + (row.history_id ? '&history_id=' + row.history_id : '');
            html += '<tr>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">' + row.payment_date + '</td>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;color:#059669;font-weight:700;">₹' + parseFloat(row.amount_paid).toFixed(2) + '</td>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">₹' + parseFloat(row.total_amount_paid || 0).toFixed(2) + '</td>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;color:#b91c1c;">₹' + parseFloat(row.previous_balance).toFixed(2) + '</td>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:700;">₹' + parseFloat(row.new_balance).toFixed(2) + '</td>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;">' +
                '<a href="' + receiptUrl + '" target="_blank" style="background:linear-gradient(135deg,#059669,#047857);color:#fff;padding:5px 12px;border-radius:6px;font-size:11px;font-weight:bold;text-decoration:none;display:inline-block;box-shadow:0 2px 6px rgba(5,150,105,0.3);">🧾 Print Receipt</a>' +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
    })
    .catch(function(err) {
        body.innerHTML = '<div style="padding:16px;color:#b91c1c;">Error loading history.</div>';
    });
}

function closeHistoryModal() {
    var modal = document.getElementById('historyModal');
    modal.style.display = 'none';
}

/* ── Receive Modal JS ── */
function openReceiveModal(id, invoiceNo, customerName, totalAmt, currentDue, currentDueDate) {
    document.getElementById('rcvInvoiceId').value = id;
    document.getElementById('rcvRawDue').value = currentDue;
    document.getElementById('rcvCustomerName').textContent = customerName;
    document.getElementById('rcvInvoiceNo').textContent = 'Inv: ' + invoiceNo;
    document.getElementById('rcvTotalAmt').textContent = '₹' + parseFloat(totalAmt).toFixed(2);
    document.getElementById('rcvCurrentDue').textContent = '₹' + parseFloat(currentDue).toFixed(2);
    
    const input = document.getElementById('rcvAmountInput');
    input.value = currentDue;
    document.getElementById('rcvNextDueDate').value = currentDueDate || '';
    calcReceivePreview();
    
    selectRcvMode('Cash');
    document.getElementById('receiveModal').style.display = 'flex';
    setTimeout(() => input.focus(), 150);
}

function selectRcvMode(mode) {
    const radios = document.getElementsByName('rcv_mode');
    for (let r of radios) { if (r.value === mode) r.checked = true; }
    
    const lblCash = document.getElementById('lblModeCash');
    const lblUpi  = document.getElementById('lblModeUpi');
    if (mode === 'Cash') {
        lblCash.style.borderColor = '#d68b16'; lblCash.style.background = '#fff9ee';
        lblUpi.style.borderColor  = '#cbd5e1'; lblUpi.style.background  = '#fff';
    } else {
        lblUpi.style.borderColor  = '#800020'; lblUpi.style.background  = '#fff1f2';
        lblCash.style.borderColor = '#cbd5e1'; lblCash.style.background = '#fff';
    }
}

function calcReceivePreview() {
    const rawDue = parseFloat(document.getElementById('rcvRawDue').value) || 0;
    const rcvAmt = parseFloat(document.getElementById('rcvAmountInput').value) || 0;
    const pending = Math.max(0, rawDue - rcvAmt);
    
    const disp = document.getElementById('rcvPendingDisplay');
    const box  = document.getElementById('rcvPendingBox');
    
    if (rcvAmt >= rawDue && rawDue > 0) {
        disp.textContent = '₹0.00 (Fully Cleared! ✅)';
        disp.style.color = '#15803d';
        box.style.background = '#f0fdf4';
        box.style.borderColor = '#86efac';
    } else {
        disp.textContent = '₹' + pending.toFixed(2);
        disp.style.color = '#b91c1c';
        box.style.background = '#f8fafc';
        box.style.borderColor = '#94a3b8';
    }
}

function closeReceiveModal() {
    document.getElementById('receiveModal').style.display = 'none';
}

function submitReceivePayment() {
    const id          = document.getElementById('rcvInvoiceId').value;
    const amt         = parseFloat(document.getElementById('rcvAmountInput').value) || 0;
    const rawDue      = parseFloat(document.getElementById('rcvRawDue').value) || 0;
    const nextDueDate = document.getElementById('rcvNextDueDate').value || '';
    
    const radios = document.getElementsByName('rcv_mode');
    let mode = 'Cash';
    for (let r of radios) { if (r.checked) mode = r.value; }

    if (amt <= 0) {
        alert('Please enter a valid amount to receive.');
        document.getElementById('rcvAmountInput').focus();
        return;
    }

    const formData = new FormData();
    formData.append('action', 'receive_due_payment');
    formData.append('id', id);
    formData.append('amount_paid', amt);
    formData.append('payment_mode', mode);
    formData.append('next_due_date', nextDueDate);

    fetch('due_list.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            closeReceiveModal();
            showToast('✅ Payment received (₹' + amt.toFixed(2) + ' ' + mode + ')!');
            
            // Open printable invoice / payment receipt PDF (uses invoice_no to avoid "Invoice number missing" error)
            var printUrl = 'view_pdf.php?invoice_no=' + encodeURIComponent(res.invoice_no) + '&receipt=1&history_id=' + res.history_id;
            window.open(printUrl, '_blank');
            
            // Update table DOM - due amount & next due date
            const rowDisplay = document.querySelector('.due-amount-input[data-id="' + id + '"]');
            if (rowDisplay) {
                if (res.is_fully_paid) {
                    const row = rowDisplay.closest('tr');
                    if (row) {
                        row.style.transition = 'opacity 0.6s';
                        row.style.opacity = '0';
                        setTimeout(function() { row.remove(); }, 600);
                    }
                } else {
                    rowDisplay.textContent = '₹' + parseFloat(res.new_balance).toFixed(2);
                    rowDisplay.dataset.balance = res.new_balance;
                    
                    // Update due date input in table row
                    const rowDateInput = rowDisplay.closest('tr').querySelector('.due-date-input');
                    if (rowDateInput && res.next_due_date) {
                        rowDateInput.value = res.next_due_date;
                    }

                    // Flash red to show update
                    rowDisplay.style.transform = 'scale(1.15)';
                    rowDisplay.style.transition = 'transform 0.3s';
                    setTimeout(function() { rowDisplay.style.transform = 'scale(1)'; }, 300);
                }
            }
        } else {
            alert('Error recording payment: ' + (res.message || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Request failed: ' + err.message);
    });
}
</script>

<!-- History Modal -->
<div id="historyModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;background:rgba(0,0,0,0.55);justify-content:center;align-items:center;padding:24px;">
    <div style="background:#fff;border-radius:12px;max-width:720px;width:100%;max-height:calc(100vh - 48px);box-shadow:0 18px 50px rgba(0,0,0,0.18);overflow:hidden;">
        <div style="padding:18px 22px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
            <h2 id="historyModalTitle" style="font-size:18px;margin:0;color:#111827;">History</h2>
            <button type="button" onclick="closeHistoryModal()" style="border:none;background:none;font-size:18px;color:#6b7280;cursor:pointer;">✕</button>
        </div>
        <div id="historyModalBody" style="max-height:70vh;overflow:auto;padding:18px 22px;">Loading history…</div>
    </div>
</div>

<!-- Receive Payment Modal -->
<div id="receiveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:10000;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
    <div style="background:#fff;border-radius:16px;max-width:440px;width:92%;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.25);border:1.5px solid #d68b16;">
        <div style="background:linear-gradient(135deg, #7a4e0a, #d68b16);padding:16px 20px;color:#fff;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-hand-holding-usd"></i> Receive Due Payment
            </div>
            <button onclick="closeReceiveModal()" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;">✕</button>
        </div>
        <div style="padding:20px;display:flex;flex-direction:column;gap:14px;">
            <!-- Customer & Inv Card -->
            <div style="background:#fdfbf4;border:1px solid rgba(214,139,22,0.3);border-radius:10px;padding:12px 14px;font-size:12px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                    <span style="color:#7a4e0a;font-weight:700;" id="rcvCustomerName">Customer Name</span>
                    <span style="font-weight:700;color:#111;" id="rcvInvoiceNo">INV-1001</span>
                </div>
                <div style="display:flex;justify-content:space-between;color:#64748b;font-size:11px;">
                    <span>Total Invoice: <strong id="rcvTotalAmt">₹0.00</strong></span>
                    <span>Current Due: <strong style="color:#b91c1c;" id="rcvCurrentDue">₹0.00</strong></span>
                </div>
            </div>

            <input type="hidden" id="rcvInvoiceId">
            <input type="hidden" id="rcvRawDue">

            <!-- Amount Receiving Now -->
            <div>
                <label style="font-size:11px;font-weight:700;color:#7a4e0a;display:block;margin-bottom:4px;">Amount Receiving Now (₹) *</label>
                <input type="number" step="0.01" min="1" id="rcvAmountInput" class="inline-input" placeholder="Enter amount to receive..." oninput="calcReceivePreview()" style="font-size:15px;font-weight:700;padding:10px;border-color:#d68b16;">
            </div>

            <!-- Payment Mode Selection -->
            <div>
                <label style="font-size:11px;font-weight:700;color:#7a4e0a;display:block;margin-bottom:6px;">Payment Mode (Added to Accounts) *</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <label style="display:flex;align-items:center;gap:8px;padding:10px;border:1.5px solid #d68b16;border-radius:10px;cursor:pointer;background:#fff9ee;" id="lblModeCash">
                        <input type="radio" name="rcv_mode" value="Cash" checked onclick="selectRcvMode('Cash')">
                        <span style="font-size:12px;font-weight:700;color:#7a4e0a;">💵 Cash</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;padding:10px;border:1.5px solid #cbd5e1;border-radius:10px;cursor:pointer;background:#fff;" id="lblModeUpi">
                        <input type="radio" name="rcv_mode" value="UPI" onclick="selectRcvMode('UPI')">
                        <span style="font-size:12px;font-weight:700;color:#800020;">📲 UPI / Digital</span>
                    </label>
                </div>
            </div>

            <!-- Next Due Date Option -->
            <div>
                <label style="font-size:11px;font-weight:700;color:#7a4e0a;display:block;margin-bottom:4px;">Next Due Date (For Remaining Balance)</label>
                <input type="date" id="rcvNextDueDate" class="inline-input" style="font-size:13px;padding:8px;border-color:#d68b16;">
            </div>

            <!-- Remaining Pending Due Preview -->
            <div id="rcvPendingBox" style="background:#f8fafc;border:1px dashed #94a3b8;border-radius:10px;padding:10px 14px;font-size:12px;display:flex;justify-content:space-between;align-items:center;">
                <span style="color:#475569;font-weight:600;">Remaining Pending Due:</span>
                <span style="font-size:14px;font-weight:800;color:#b91c1c;" id="rcvPendingDisplay">₹0.00</span>
            </div>

            <!-- Submit Action -->
            <div style="display:flex;gap:10px;margin-top:6px;">
                <button type="button" onclick="closeReceiveModal()" style="flex:1;padding:10px;border-radius:10px;border:1px solid #cbd5e1;background:#f1f5f9;color:#475569;font-size:12px;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="button" onclick="submitReceivePayment()" style="flex:1.5;padding:10px;border-radius:10px;border:none;background:linear-gradient(135deg, #7a4e0a, #d68b16);color:#fff;font-size:12px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(214,139,22,0.3);">
                    💾 Save &amp; Print Invoice
                </button>
            </div>
        </div>
    </div>
</div>
</body>
</html>

<!-- Font Awesome fallback: replace <i class="fa..."> with emoji if FA didn't load -->
<script>
// Run after DOM ready
(function(){
    function faLoaded() {
        var el = document.createElement('i');
        el.className = 'fas fa-user';
        el.style.display = 'inline-block';
        el.style.visibility = 'hidden';
        document.body.appendChild(el);
        var loaded = window.getComputedStyle(el).getPropertyValue('font-family').toLowerCase().indexOf('fontawesome') !== -1;
        document.body.removeChild(el);
        return loaded;
    }

    if (!faLoaded()) {
        var map = {
            'fa-user': '👤', 'fa-user-circle':'👤', 'fa-sign-out-alt':'🔓', 'fa-sign-in-alt':'🔐',
            'fa-list':'📋', 'fa-arrow-left':'←', 'fa-check-circle':'✅', 'fa-save':'💾',
            'fa-trash-alt':'🗑️', 'fa-spinner':'⏳', 'fa-check':'✓', 'fa-chart-bar':'📊',
            'fa-receipt':'🧾','fa-chart-line':'📈','fa-boxes':'📦','fa-users':'👥','fa-gem':'💎',
            'fa-book':'📖','fa-weight-hanging':'⚖️','fa-coins':'🪙','fa-search':'🔍','fa-plus-circle':'➕'
        };

        document.querySelectorAll('i[class*="fa-"]').forEach(function(i){
            // skip icons explicitly marked to preserve (use index.php originals)
            if (i.hasAttribute('data-fa-preserve')) return;
            var classes = i.className.split(/\s+/);
            for (var c of classes) {
                if (c.indexOf('fa-') === 0) {
                    var key = c.trim();
                    if (map[key]) {
                        var span = document.createElement('span');
                        span.textContent = map[key];
                        span.style.fontSize = window.getComputedStyle(i).fontSize || '14px';
                        span.style.display = 'inline-block';
                        span.style.verticalAlign = 'middle';
                        // copy margin classes like mr-1 if present
                        if (i.className.indexOf('mr-1') !== -1) span.style.marginRight = '6px';
                        i.parentNode.replaceChild(span, i);
                    }
                    break;
                }
            }
        });
    }
})();
</script>



