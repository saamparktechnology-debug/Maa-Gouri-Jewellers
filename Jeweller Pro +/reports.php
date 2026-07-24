<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// AJAX endpoint to get invoice items details
if (isset($_GET['action']) && $_GET['action'] === 'get_invoice_items') {
    header('Content-Type: application/json');
    $invoice_no = mysqli_real_escape_string($conn, trim($_GET['invoice_no'] ?? ''));
    $items = [];
    if (!empty($invoice_no)) {
        $chk = mysqli_query($conn, "SELECT id FROM invoices WHERE invoice_no = '$invoice_no'");
        if ($chk && mysqli_num_rows($chk) > 0) {
            $invoice = mysqli_fetch_assoc($chk);
            $invoice_id = intval($invoice['id']);
            
            // Fetch items
            $res = mysqli_query($conn, "
                SELECT 
                    ii.id, 
                    ii.product_id, 
                    ii.product_name, 
                    ii.serial_no, 
                    ii.huid_code, 
                    ii.hsn_code, 
                    ii.price, 
                    ii.quantity,
                    p.category,
                    p.weight
                FROM invoice_items ii 
                LEFT JOIN products p ON ii.product_id = p.id 
                WHERE ii.invoice_id = $invoice_id
            ");
            
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $items[] = [
                        'id' => intval($row['id']),
                        'product_id' => $row['product_id'] ? intval($row['product_id']) : null,
                        'product_name' => $row['product_name'] ?? '',
                        'serial_no' => $row['serial_no'] ?? '',
                        'huid_code' => $row['huid_code'] ?? '',
                        'hsn_code' => $row['hsn_code'] ?? '0',
                        'price' => floatval($row['price'] ?? 0),
                        'quantity' => floatval($row['quantity'] ?? 0),
                        'category' => $row['category'] ?? '',
                        'weight' => $row['weight'] ?? ''
                    ];
                }
            }
        }
    }
    echo json_encode($items);
    exit();
}

// Handle remove data action
$reset_success = '';
$reset_error = '';
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_invoice'])) {
    $invoice_no = mysqli_real_escape_string($conn, trim($_POST['invoice_no'] ?? ''));
    $delete_mode = $_POST['delete_mode'] ?? 'restore_stock';
    if (empty($invoice_no)) {
        $reset_error = "Error: Invoice number is missing.";
    } else {
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        $success = false;
        
        $chk = mysqli_query($conn, "SELECT id FROM invoices WHERE invoice_no = '$invoice_no'");
        if ($chk && mysqli_num_rows($chk) > 0) {
            $invoice = mysqli_fetch_assoc($chk);
            $invoice_id = $invoice['id'];
            
            if ($delete_mode === 'restore_stock') {
                if (isset($_POST['items_restore'])) {
                    $restore_items = json_decode($_POST['items_restore'], true);
                    if (is_array($restore_items)) {
                        foreach ($restore_items as $item) {
                            $product_id = intval($item['product_id'] ?? 0);
                            $restore_qty = floatval($item['restore_qty'] ?? 0);
                            $hsn_code = mysqli_real_escape_string($conn, $item['hsn_code'] ?? '0');
                            $product_name = mysqli_real_escape_string($conn, $item['product_name'] ?? '');
                            $serial_no = mysqli_real_escape_string($conn, $item['serial_no'] ?? '');
                            $huid_code = mysqli_real_escape_string($conn, $item['huid_code'] ?? '');
                            $price = floatval($item['price'] ?? 0);
                            $unit = mysqli_real_escape_string($conn, $item['unit'] ?? '');
                            $category = mysqli_real_escape_string($conn, $item['category'] ?? '');
                            $weight = mysqli_real_escape_string($conn, $item['weight'] ?? '');
                            
                            if ($restore_qty > 0) {
                                $product_exists = false;
                                if ($product_id > 0) {
                                    $p_chk = mysqli_query($conn, "SELECT id FROM products WHERE id = $product_id");
                                    if ($p_chk && mysqli_num_rows($p_chk) > 0) {
                                        $product_exists = true;
                                    }
                                }
                                
                                if ($product_exists) {
                                    mysqli_query($conn, "UPDATE products SET quantity = quantity + $restore_qty, hsn_code = '$hsn_code' WHERE id = $product_id");
                                } else {
                                    // Re-insert product back to stock
                                    if (empty($category)) {
                                        if (stripos($product_name, 'gold') !== false) {
                                            $category = 'Gold 22K';
                                        } else if (stripos($product_name, 'silver') !== false) {
                                            $category = 'Silver';
                                        } else {
                                            $category = 'Others';
                                        }
                                    }
                                    
                                    // Insert product
                                    mysqli_query($conn, "INSERT INTO products (serial_no, name, item_name, category, weight, price, quantity, huid_code, hsn_code, created_at) VALUES ('$serial_no', '$product_name', '$product_name', '$category', '$weight', $price, $restore_qty, '$huid_code', '$hsn_code', NOW())");
                                }
                            }
                        }
                    }
                } else {
                    // Fallback to original restore behavior if items_restore wasn't submitted (e.g. legacy/direct form POST)
                    $items_res = mysqli_query($conn, "SELECT product_id, quantity FROM invoice_items WHERE invoice_id = $invoice_id");
                    if ($items_res) {
                        while ($item = mysqli_fetch_assoc($items_res)) {
                            if (!empty($item['product_id']) && floatval($item['quantity']) > 0) {
                                $pid = intval($item['product_id']);
                                $p_qty = floatval($item['quantity']);
                                $p_chk = mysqli_query($conn, "SELECT id FROM products WHERE id = $pid");
                                if ($p_chk && mysqli_num_rows($p_chk) > 0) {
                                    mysqli_query($conn, "UPDATE products SET quantity = quantity + $p_qty WHERE id = $pid");
                                } else {
                                    // Fetch item details to re-insert
                                    $item_detail = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM invoice_items WHERE invoice_id = $invoice_id AND product_id = $pid LIMIT 1"));
                                    if ($item_detail) {
                                        $name = mysqli_real_escape_string($conn, $item_detail['product_name']);
                                        $serial = mysqli_real_escape_string($conn, $item_detail['serial_no']);
                                        $huid = mysqli_real_escape_string($conn, $item_detail['huid_code']);
                                        $hsn = mysqli_real_escape_string($conn, $item_detail['hsn_code'] ?? '0');
                                        $price = floatval($item_detail['price']);
                                        $cat = (stripos($name, 'gold') !== false) ? 'Gold 22K' : ((stripos($name, 'silver') !== false) ? 'Silver' : 'Others');
                                        mysqli_query($conn, "INSERT INTO products (serial_no, name, item_name, category, weight, price, quantity, huid_code, hsn_code, created_at) VALUES ('$serial', '$name', '$name', '$cat', '', $price, $p_qty, '$huid', '$hsn', NOW())");
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // Delete invoice
            $q = mysqli_query($conn, "DELETE FROM invoices WHERE id = $invoice_id");
            if ($q) {
                $success = true;
                $reset_success = "Invoice #$invoice_no has been successfully deleted.";
                if ($delete_mode === 'restore_stock') {
                    $reset_success .= " Specified quantities have been moved back to stock.";
                }
            } else {
                $reset_error = "Error deleting invoice: " . mysqli_error($conn);
            }
        } else {
            $reset_error = "Error: Invoice #$invoice_no not found.";
        }
        
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
        
        if ($success) {
            $redirect_url = 'reports.php?reset_msg=' . urlencode($reset_success);
            echo '<!DOCTYPE html><html><head>';
            echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url) . '">';
            echo '<script>window.location.replace(' . json_encode($redirect_url) . ');<\/script>';
            echo '</head><body style="background:#fffbf4;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;">';
            echo '<p style="color:#7a4e0a;font-weight:600;">✅ Done! Redirecting...</p>';
            echo '</body></html>';
            exit();
        }
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reset_report'])) {
    $target = $_POST['reset_target'] ?? '';
    $confirm_text = strtoupper(trim($_POST['confirm_delete_text'] ?? ''));
    
    if ($confirm_text !== 'DELETE') {
        $reset_error = "Error: Please type 'DELETE' to confirm.";
    } else {
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        $success = false;
        
        switch($target) {
            case 'invoices':
                $q1 = mysqli_query($conn, "TRUNCATE TABLE invoice_items");
                $q2 = mysqli_query($conn, "TRUNCATE TABLE invoices");
                if ($q1 && $q2) {
                    $success = true;
                    $reset_success = "All Invoices and Billing data have been successfully deleted.";
                } else {
                    $reset_error = "Error deleting invoices: " . mysqli_error($conn);
                }
                break;
            case 'specific_invoice':
                $spec_id = mysqli_real_escape_string($conn, trim($_POST['specific_identifier'] ?? ''));
                if (empty($spec_id)) {
                    $reset_error = "Error: Please specify the Invoice Number.";
                } else {
                    $chk = mysqli_query($conn, "SELECT id FROM invoices WHERE invoice_no = '$spec_id'");
                    if ($chk && mysqli_num_rows($chk) > 0) {
                        $invoice = mysqli_fetch_assoc($chk);
                        $invoice_id = $invoice['id'];
                        
                        // Restore product quantities
                        $items_res = mysqli_query($conn, "SELECT product_id, quantity FROM invoice_items WHERE invoice_id = $invoice_id");
                        if ($items_res) {
                            while ($item = mysqli_fetch_assoc($items_res)) {
                                if (!empty($item['product_id']) && floatval($item['quantity']) > 0) {
                                    mysqli_query($conn, "UPDATE products SET quantity = quantity + " . floatval($item['quantity']) . " WHERE id = " . intval($item['product_id']));
                                }
                            }
                        }
                        
                        // Delete invoice
                        $q2 = mysqli_query($conn, "DELETE FROM invoices WHERE id = $invoice_id");
                        if ($q2) {
                            $success = true;
                            $reset_success = "Invoice #$spec_id has been successfully deleted and product stock restored.";
                        } else {
                            $reset_error = "Error deleting invoice: " . mysqli_error($conn);
                        }
                    } else {
                        $reset_error = "Error: Invoice #$spec_id not found.";
                    }
                }
                break;
            case 'stock':
                $q = mysqli_query($conn, "TRUNCATE TABLE products");
                if ($q) {
                    $success = true;
                    $reset_success = "All Products/Stock data have been successfully deleted.";
                } else {
                    $reset_error = "Error deleting products: " . mysqli_error($conn);
                }
                break;
            case 'specific_stock':
                $spec_id = mysqli_real_escape_string($conn, trim($_POST['specific_identifier'] ?? ''));
                if (empty($spec_id)) {
                    $reset_error = "Error: Please specify the Product Serial Number, Name, or ID.";
                } else {
                    $chk = mysqli_query($conn, "SELECT id, name FROM products WHERE serial_no = '$spec_id' OR name = '$spec_id' OR id = '$spec_id'");
                    if ($chk && mysqli_num_rows($chk) > 0) {
                        $product = mysqli_fetch_assoc($chk);
                        $p_id = $product['id'];
                        $p_name = $product['name'];
                        
                        $q = mysqli_query($conn, "DELETE FROM products WHERE id = $p_id");
                        if ($q) {
                            $success = true;
                            $reset_success = "Product '$p_name' has been successfully deleted.";
                        } else {
                            $reset_error = "Error deleting product: " . mysqli_error($conn);
                        }
                    } else {
                        $reset_error = "Error: Product matching '$spec_id' not found.";
                    }
                }
                break;
            case 'customers':
                $q = mysqli_query($conn, "TRUNCATE TABLE customers");
                if ($q) {
                    $success = true;
                    $reset_success = "All Customer profiles have been successfully deleted.";
                } else {
                    $reset_error = "Error deleting customers: " . mysqli_error($conn);
                }
                break;
            case 'specific_customer':
                $spec_id = mysqli_real_escape_string($conn, trim($_POST['specific_identifier'] ?? ''));
                if (empty($spec_id)) {
                    $reset_error = "Error: Please specify the Customer Mobile Number, Name, or ID.";
                } else {
                    $chk = mysqli_query($conn, "SELECT id, name, mobile FROM customers WHERE mobile = '$spec_id' OR name = '$spec_id' OR id = '$spec_id'");
                    if ($chk && mysqli_num_rows($chk) > 0) {
                        $customer = mysqli_fetch_assoc($chk);
                        $c_id = $customer['id'];
                        $c_name = $customer['name'];
                        $c_mobile = $customer['mobile'];
                        
                        $q = mysqli_query($conn, "DELETE FROM customers WHERE id = $c_id");
                        if ($q) {
                            $success = true;
                            $reset_success = "Customer '$c_name' ($c_mobile) has been successfully deleted.";
                        } else {
                            $reset_error = "Error deleting customer: " . mysqli_error($conn);
                        }
                    } else {
                        $reset_error = "Error: Customer matching '$spec_id' not found.";
                    }
                }
                break;
            case 'purchases':
                $q1 = mysqli_query($conn, "TRUNCATE TABLE purchase_entries");
                $q2 = mysqli_query($conn, "UPDATE stock_metal SET qty_available = 0");
                if ($q1 && $q2) {
                    $success = true;
                    $reset_success = "All Purchase Entries have been successfully deleted and metal stock reset to 0.";
                } else {
                    $reset_error = "Error deleting purchases: " . mysqli_error($conn);
                }
                break;
            case 'specific_purchase':
                $spec_id = mysqli_real_escape_string($conn, trim($_POST['specific_identifier'] ?? ''));
                if (empty($spec_id)) {
                    $reset_error = "Error: Please specify the Purchase No or ID.";
                } else {
                    $chk = mysqli_query($conn, "SELECT id, purchase_no, material_type, qty FROM purchase_entries WHERE purchase_no = '$spec_id' OR id = '$spec_id'");
                    if ($chk && mysqli_num_rows($chk) > 0) {
                        $purchase = mysqli_fetch_assoc($chk);
                        $p_id = $purchase['id'];
                        $p_no = $purchase['purchase_no'];
                        $mat = $purchase['material_type'];
                        $qty = floatval($purchase['qty']);
                        
                        // Deduct stock from stock_metal
                        mysqli_query($conn, "UPDATE stock_metal SET qty_available = qty_available - $qty WHERE material_type = '$mat'");
                        
                        $q = mysqli_query($conn, "DELETE FROM purchase_entries WHERE id = $p_id");
                        if ($q) {
                            $success = true;
                            $reset_success = "Purchase Entry '$p_no' has been successfully deleted, and stock adjusted.";
                        } else {
                            $reset_error = "Error deleting purchase entry: " . mysqli_error($conn);
                        }
                    } else {
                        $reset_error = "Error: Purchase Entry matching '$spec_id' not found.";
                    }
                }
                break;
            case 'sanchay':
                $q1 = mysqli_query($conn, "TRUNCATE TABLE sanchari_payments");
                $q2 = mysqli_query($conn, "TRUNCATE TABLE sanchari_redemptions");
                $q3 = mysqli_query($conn, "TRUNCATE TABLE sanchari_customers");
                if ($q1 && $q2 && $q3) {
                    $success = true;
                    $reset_success = "All Sanchay Book (scheme, payments, redemptions) data have been successfully deleted.";
                } else {
                    $reset_error = "Error deleting Sanchay Book data: " . mysqli_error($conn);
                }
                break;
            case 'income_expense':
                $chk1 = mysqli_query($conn, "SHOW TABLES LIKE 'income'");
                $chk2 = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
                $q1 = true; $q2 = true;
                if ($chk1 && mysqli_num_rows($chk1) > 0) {
                    $q1 = mysqli_query($conn, "TRUNCATE TABLE income");
                }
                if ($chk2 && mysqli_num_rows($chk2) > 0) {
                    $q2 = mysqli_query($conn, "TRUNCATE TABLE expenses");
                }
                if ($q1 && $q2) {
                    $success = true;
                    $reset_success = "All Income and Expense records have been successfully deleted.";
                } else {
                    $reset_error = "Error deleting income/expenses: " . mysqli_error($conn);
                }
                break;
            default:
                $reset_error = "Error: Invalid selection.";
        }
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
        
        // If successful, reload to refresh stats/reports
        if ($success) {
            $redirect_url = 'reports.php?reset_msg=' . urlencode($reset_success);
            echo '<!DOCTYPE html><html><head>';
            echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url) . '">';
            echo '<script>window.location.replace(' . json_encode($redirect_url) . ');<\/script>';
            echo '</head><body style="background:#fffbf4;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;">';
            echo '<p style="color:#7a4e0a;font-weight:600;">✅ Done! Redirecting...</p>';
            echo '</body></html>';
            exit();
        }
    }
}

// Check for redirection message
if (isset($_GET['reset_msg'])) {
    $reset_success = $_GET['reset_msg'];
}

// Get daily sales for last 7 days
$daily_sales = [];
for($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $sales = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE DATE(created_at) = '$date'"));
    $daily_sales[] = ['date' => date('d M', strtotime($date)), 'total' => $sales['total']];
}

// Get top products
$top_products = mysqli_query($conn, "SELECT p.name, SUM(ii.quantity) as sold FROM invoice_items ii JOIN products p ON ii.product_id = p.id GROUP BY ii.product_id ORDER BY sold DESC LIMIT 5");

// Payment Status Summary
$pay_summary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN payment_status='paid'   THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN payment_status='part'   THEN 1 ELSE 0 END) as part_count,
        SUM(CASE WHEN payment_status='unpaid' THEN 1 ELSE 0 END) as unpaid_count,
        SUM(CASE WHEN payment_status='paid'   THEN total_amount ELSE 0 END) as paid_amt,
        SUM(CASE WHEN payment_status='part'   THEN total_amount ELSE 0 END) as part_amt,
        SUM(CASE WHEN payment_status='unpaid' THEN total_amount ELSE 0 END) as unpaid_amt,
        SUM(CASE WHEN payment_status='part'   THEN balance_amount ELSE 0 END) as part_balance,
        SUM(CASE WHEN payment_status='unpaid' THEN balance_amount ELSE 0 END) as unpaid_balance
    FROM invoices
"));

$paid_cust = mysqli_query($conn, "SELECT invoice_no, customer_name, customer_mobile, total_amount, created_at FROM invoices WHERE payment_status='paid' ORDER BY created_at DESC LIMIT 100");
$paid_rows = [];
while($r = mysqli_fetch_assoc($paid_cust)) $paid_rows[] = $r;

$part_customers = mysqli_query($conn, "SELECT i.invoice_no, i.customer_name, i.customer_mobile, i.total_amount, i.paid_amount, i.balance_amount, i.created_at, COALESCE(c.email, '') AS customer_email FROM invoices i LEFT JOIN customers c ON c.mobile = i.customer_mobile WHERE i.payment_status='part' ORDER BY i.created_at DESC LIMIT 100");
$part_rows = [];
while($r = mysqli_fetch_assoc($part_customers)) $part_rows[] = $r;

$unpaid_customers = mysqli_query($conn, "SELECT invoice_no, customer_name, customer_mobile, total_amount, paid_amount, balance_amount, created_at FROM invoices WHERE payment_status='unpaid' ORDER BY created_at DESC LIMIT 100");
$unpaid_rows = [];
while($r = mysqli_fetch_assoc($unpaid_customers)) $unpaid_rows[] = $r;

// GST summary
$gst_month = mysqli_real_escape_string($conn, $_GET['gst_month'] ?? date('Y-m'));
$gst_month_where = $gst_month ? "WHERE DATE_FORMAT(created_at,'%Y-%m') = '$gst_month'" : '';
$gst_summary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN gst_type LIKE 'gst%'  THEN 1 ELSE 0 END) as gst_count,
        SUM(CASE WHEN gst_type NOT LIKE 'gst%' THEN 1 ELSE 0 END) as nongst_count,
        SUM(CASE WHEN gst_type LIKE 'gst%'  THEN total_amount ELSE 0 END) as gst_taxable_total,
        SUM(CASE WHEN gst_type NOT LIKE 'gst%' THEN total_amount ELSE 0 END) as nongst_amt,
        SUM(CASE WHEN gst_type LIKE 'gst%'  THEN gst_amount   ELSE 0 END) as actual_gst_collected
    FROM invoices $gst_month_where
"));

// Bills table with filters
$filter_from   = mysqli_real_escape_string($conn, $_GET['filter_from']   ?? '');
$filter_to     = mysqli_real_escape_string($conn, $_GET['filter_to']     ?? '');
$filter_name   = mysqli_real_escape_string($conn, trim($_GET['filter_name'] ?? ''));
$filter_status = mysqli_real_escape_string($conn, $_GET['filter_status'] ?? '');
$filter_gst    = mysqli_real_escape_string($conn, $_GET['filter_gst']    ?? '');

$where_clauses = [];
if($filter_from)   $where_clauses[] = "DATE(created_at) >= '$filter_from'";
if($filter_to)     $where_clauses[] = "DATE(created_at) <= '$filter_to'";
if($filter_name)   $where_clauses[] = "(customer_name LIKE '%$filter_name%' OR customer_mobile LIKE '%$filter_name%')";
if($filter_status) $where_clauses[] = "payment_status = '$filter_status'";
if($filter_gst === 'gst')     $where_clauses[] = "gst_type LIKE 'gst%'";
if($filter_gst === 'non_gst') $where_clauses[] = "gst_type NOT LIKE 'gst%'";
$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// customer_gstin column exists directly in invoices table
$bills_result = mysqli_query($conn, "SELECT invoice_no, customer_name, customer_mobile, customer_address, customer_gstin, total_amount, paid_amount, balance_amount, payment_status, gst_type, gst_amount, subtotal, created_at FROM invoices $where_sql ORDER BY created_at DESC LIMIT 500");
$bills_rows = [];
while($r = mysqli_fetch_assoc($bills_result)) $bills_rows[] = $r;

$total_bills_amount = array_sum(array_column($bills_rows, 'total_amount'));
$total_bills_count  = count($bills_rows);

$logo_paths = ['logo.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reports - MOTI JEWELLERS</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="assets/css/theme.css">
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
        .sidebar-logo img { width: 44px; height: 44px; object-fit: cover; border-radius: 50%; background: rgba(255,255,255,0.1); flex-shrink: 0; }
        .sidebar-logo-text h2 { color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3; font-family: 'Poppins', serif; letter-spacing: 0.5px; }
        .sidebar-logo-text p  { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }

        .sidebar-nav { flex: 1; padding: 10px 0; overflow-y: auto; overflow-x: hidden; }
        .sidebar-section-label { padding: 10px 20px 4px; color: rgba(255,255,255,0.45); font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; position: sticky; top: 0; background: #011921; color: #f5c842; z-index: 10; }

        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 20px; color: rgba(255,255,255,0.85);
            text-decoration: none; font-size: 13px; font-weight: 500;
            transition: all 0.2s ease; border-left: 3px solid transparent;
            letter-spacing: 0.3px; position: relative;
        }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
        .sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
        .sidebar-nav a.active::after { content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 60%; background: #fff; border-radius: 4px 0 0 4px; }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; opacity: 0.9; }

        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }

        .sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }

        .sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; border: 1px solid rgba(239,68,68,0.4); }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
        .sidebar-overlay.active { display: block; }

        /* ========== LAYOUT ========== */
        .page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; background: #F5F5F5; }

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
            nav.nav-gold { margin-left: 0 !important; }
        }
        @media (min-width: 769px) { .mobile-burger { display: none !important; } }

        /* ========== PAGE STYLES ========== */
        body { background: #F5F5F5; margin: 0; padding: 0; }

        .page-heading { background: linear-gradient(135deg, #fdf6e3, #f5ead0); border-bottom: 2px solid rgba(181,115,14,0.2); padding: 20px 28px; }
        .page-heading h1 { color: #800020; font-size: 1.6rem; }
        .page-heading p  { color: #7a4e0a; font-size: 13px; margin-top: 2px; }

        .jewel-card { background: #fff; border: 1px solid rgba(181,115,14,0.2); border-radius: 20px; box-shadow: 0 4px 20px rgba(181,115,14,0.08); }

        .jewel-input { background: #fdf6e3; border: 1px solid rgba(181,115,14,0.3); color: #4a3000; border-radius: 10px; padding: 8px 12px; transition: all 0.3s ease; font-size: 13px; }
        .jewel-input:focus { border-color: #d68b16; box-shadow: 0 0 0 3px rgba(214,139,22,0.15); outline: none; }

        /* Section titles */
        .section-title { color: #800020; font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .section-title i { color: #d68b16; }

        /* Buttons */
        .btn-jewel { background: linear-gradient(135deg, #800020, #d68b16); border: none; border-radius: 10px; padding: 9px 22px; font-weight: 700; color: #fff; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-size: 13px; cursor: pointer; }
        .btn-jewel:hover { transform: scale(1.04); box-shadow: 0 8px 24px rgba(214,139,22,0.35); color: #fff; }

        .btn-excel { background: linear-gradient(135deg, #16a34a, #15803d); border: none; border-radius: 10px; padding: 9px 20px; font-weight: 700; color: #fff; transition: all 0.3s; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; }
        .btn-excel:hover { transform: scale(1.04); box-shadow: 0 8px 20px rgba(22,163,74,0.4); }

        /* Stat cards */
        .stat-card-paid   { background: linear-gradient(145deg, #f0fdf4, #dcfce7); border: 1px solid #86efac; border-radius: 16px; }
        .stat-card-part   { background: linear-gradient(145deg, #fff7ed, #fed7aa); border: 1px solid #fdba74; border-radius: 16px; }
        .stat-card-unpaid { background: linear-gradient(145deg, #fff1f2, #fecdd3); border: 1px solid #fca5a5; border-radius: 16px; }
        .stat-card-gst    { background: linear-gradient(145deg, #f0fdfa, #ccfbf1); border: 1px solid #5eead4; border-radius: 16px; }
        .stat-card-nongst { background: linear-gradient(145deg, #f8fafc, #f1f5f9); border: 1px solid #cbd5e1; border-radius: 16px; }

        /* Detail collapse */
        .detail-collapse { display: none; padding: 16px; border-radius: 12px; background: #fdf6e3; border: 1px solid rgba(181,115,14,0.2); margin-top: 8px; }
        .detail-collapse.open { display: block; }

        /* Table */
        .report-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .report-table th { background: linear-gradient(135deg, #7a4e0a, #d68b16); color: #fff; font-weight: 600; padding: 10px 8px; text-align: left; }
        .report-table td { padding: 9px 8px; border-bottom: 1px solid rgba(181,115,14,0.1); color: #3a2800; vertical-align: middle; }
        .report-table tbody tr:hover { background: #fdf6e3; }
        .report-table tbody tr:nth-child(even) { background: #fffbf0; }
        .report-table tbody tr:nth-child(even):hover { background: #fdf6e3; }

        /* Payment badges */
        .badge-paid   { background: #dcfce7; color: #166534; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-part   { background: #fed7aa; color: #9a3412; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-unpaid { background: #fecdd3; color: #9f1239; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }

        /* GST number tag */
        .gstin-tag {
            display: inline-block;
            margin-top: 3px;
            font-size: 10px;
            font-weight: 600;
            color: #0f766e;
            background: #ccfbf1;
            border: 1px solid #5eead4;
            border-radius: 6px;
            padding: 1px 7px;
            letter-spacing: 0.5px;
            font-family: monospace;
        }

        /* Progress bar */
        .progress-bar-bg { background: rgba(181,115,14,0.15); border-radius: 999px; height: 10px; }
        .progress-bar-fill { background: linear-gradient(90deg, #800020, #d68b16); height: 10px; border-radius: 999px; transition: width 0.6s ease; }

        /* Chart card */
        .chart-card { background: linear-gradient(145deg, #fdf6e3, #fff); border: 1px solid rgba(181,115,14,0.2); border-radius: 20px; padding: 20px; }

        /* Summary chips */
        .chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .chip-yellow { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .chip-green  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .chip-teal   { background: #ccfbf1; color: #134e4a; border: 1px solid #5eead4; }
        .chip-gray   { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .chip-emerald{ background: #d1fae5; color: #064e3b; border: 1px solid #6ee7b7; }

        /* Toggle btn */
        .toggle-detail-btn { background: none; border: 1px solid rgba(181,115,14,0.4); color: #7a4e0a; border-radius: 20px; padding: 4px 14px; font-size: 11px; cursor: pointer; transition: all 0.2s; margin-top: 10px; display: inline-block; }
        .toggle-detail-btn:hover { background: rgba(214,139,22,0.1); }

        /* GST total box */
        .gst-total-box { background: linear-gradient(135deg, rgba(52,211,153,0.1), rgba(20,184,166,0.05)); border: 1px solid rgba(52,211,153,0.3); border-radius: 16px; padding: 16px 20px; }

        /* Filter form */
        .filter-label { font-size: 11px; font-weight: 600; color: #7a4e0a; margin-bottom: 4px; display: block; }

        /* Footer */
        footer { background: linear-gradient(0deg, #f5e6c8, #fdf6e3); border-top: 2px solid #d68b16; padding: 20px; text-align: center; margin-top: 40px; }

        @media (max-width: 640px) {
            .charts-grid { grid-template-columns: 1fr !important; }
            .stats-3col  { grid-template-columns: 1fr !important; }
            .bills-table-wrap { overflow-x: auto; }
            .report-table { min-width: 780px; }
        }
    </style>
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

    const texts = ["MOTI JEWELLERS"];
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

    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>

    <!-- background diamonds removed to keep only central gem -->

    <div id="loaderStars" style="position:absolute;inset:0;pointer-events:none;z-index:2;"></div>
    <div id="loaderRings" style="position:absolute;inset:0;pointer-events:none;z-index:2;display:flex;align-items:center;justify-content:center;"></div>

    <div style="position:relative;z-index:10;text-align:center;">
        <!-- Logo -->
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
            if(file_exists($path)) { echo '<img src="'.$path.'" alt="Logo">'; $logo_found=true; break; }
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
        <a href="index.php"><i class="fas fa-home"></i> HOME</a>
        <a href="billing.php"><i class="fas fa-receipt"></i> BILLING</a>
        <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
        <a href="customers.php"><i class="fas fa-users"></i> CUSTOMERS</a>
        <a href="sanchari_dashboard.php">
            <i class="fas fa-piggy-bank"></i> SANCHAY SCHEME
        </a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>
        <a href="reports.php" class="active"><i class="fas fa-chart-bar"></i> REPORTS</a>
        <a href="due_list.php"><i class="fas fa-hourglass-half"></i> DUE LIST</a>
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="purchase.php">
            <i class="fas fa-book"></i>PURCHASE
        </a>
        <a href="accounts.php">
            <i class="fas fa-book"></i> ACCOUNT
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
<!-- ========== END SIDEBAR ========== -->

<!-- ========== NAVBAR ========== -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">
            <div class="ml-auto flex items-center gap-4">
                <span class="text-sm font-medium text-white hidden sm:inline">
                    <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($_SESSION['user_name']); ?>
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
        <h1 class="gold-font"><i class="fas fa-chart-bar mr-2"></i> Business Reports</h1>
        <p>Sales analytics, payment status &amp; GST summary</p>
    </div>

    <div class="container mx-auto px-4 sm:px-6 py-6">

        <!-- Reset Status Banner -->
        <?php if($reset_success): ?>
            <div class="mb-6 p-4 rounded-xl text-sm font-semibold" style="background:#d1fae5; border:1px solid #6ee7b7; color:#065f46;">
                <i class="fas fa-check-circle mr-2"></i> <?php echo htmlspecialchars($reset_success); ?>
            </div>
        <?php endif; ?>
        <?php if($reset_error): ?>
            <div class="mb-6 p-4 rounded-xl text-sm font-semibold" style="background:#fee2e2; border:1px solid #fca5a5; color:#7f1d1d;">
                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo htmlspecialchars($reset_error); ?>
            </div>
        <?php endif; ?>

        <!-- ── CHARTS ROW ── -->
        <div class="charts-grid grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Sales Chart
            <div class="chart-card">
                <h3 class="section-title mb-4"><i class="fas fa-chart-line"></i> Last 7 Days Sales</h3>
                <canvas id="salesChart"></canvas>
            </div> -->

            <!-- Top Products -->
            <!-- <div class="chart-card">
                <h3 class="section-title mb-4"><i class="fas fa-trophy"></i> Top Selling Products</h3>
                <div class="space-y-4">
                    <?php
                    $products_array = [];
                    while($p = mysqli_fetch_assoc($top_products)) $products_array[] = $p;
                    if($products_array): $max_sold = max(array_column($products_array,'sold'));
                    foreach($products_array as $product): ?>
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-sm font-semibold" style="color:#800020;">💎 <?php echo htmlspecialchars($product['name']); ?></span>
                            <span class="text-sm font-bold" style="color:#d68b16;"><?php echo $product['sold']; ?> units</span>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width:<?php echo min(100, ($product['sold']/$max_sold)*100); ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <div class="text-center py-12" style="color:#7a4e0a;opacity:0.5;">
                        <i class="fas fa-chart-simple text-4xl mb-3 block"></i>
                        No sales data yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div> -->
        </div>

        <!-- ── PAYMENT STATUS OVERVIEW ── -->
        <div class="jewel-card p-5 sm:p-6 mb-6">
            <h3 class="section-title mb-5"><i class="fas fa-wallet"></i> Payment Status Overview</h3>

            <div class="stats-3col grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <!-- Paid -->
                <div class="stat-card-paid p-4">
                    <div class="flex justify-between items-start mb-2">
                        <span class="font-bold text-sm" style="color:#166534;">✅ Full Paid</span>
                        <span class="text-2xl font-black" style="color:#166534;"><?php echo $pay_summary['paid_count']; ?></span>
                    </div>
                    <p class="font-bold text-lg" style="color:#166534;">₹<?php echo number_format($pay_summary['paid_amt'],2); ?></p>
                    <p class="text-xs mt-1" style="color:#15803d;">Total collected (full payment)</p>
                    <button onclick="toggleDetail('detailPaid')" class="toggle-detail-btn">👁️ View Customers</button>
                </div>
                <!-- Part -->
                <div class="stat-card-part p-4">
                    <div class="flex justify-between items-start mb-2">
                        <span class="font-bold text-sm" style="color:#9a3412;">⏳ Part Payment</span>
                        <span class="text-2xl font-black" style="color:#9a3412;"><?php echo $pay_summary['part_count']; ?></span>
                    </div>
                    <p class="font-bold text-lg" style="color:#9a3412;">₹<?php echo number_format($pay_summary['part_amt'],2); ?></p>
                    <p class="text-xs mt-1" style="color:#c2410c;">⚠️ Balance: ₹<?php echo number_format($pay_summary['part_balance'],2); ?></p>
                    <button onclick="toggleDetail('detailPart')" class="toggle-detail-btn">👁️ View Customers</button>
                </div>
                <!-- Unpaid -->
                <div class="stat-card-unpaid p-4">
                    <div class="flex justify-between items-start mb-2">
                        <span class="font-bold text-sm" style="color:#9f1239;">❌ Advanced (Credit)</span>
                        <span class="text-2xl font-black" style="color:#9f1239;"><?php echo $pay_summary['unpaid_count']; ?></span>
                    </div>
                    <p class="font-bold text-lg" style="color:#9f1239;">₹<?php echo number_format($pay_summary['unpaid_amt'],2); ?></p>
                    <p class="text-xs mt-1" style="color:#be123c;">⚠️ Total due: ₹<?php echo number_format($pay_summary['unpaid_balance'],2); ?></p>
                    <button onclick="toggleDetail('detailUnpaid')" class="toggle-detail-btn">👁️ View Customers</button>
                </div>
            </div>

            <!-- Paid detail -->
            <div id="detailPaid" class="detail-collapse mb-3">
                <h4 class="font-bold text-sm mb-3" style="color:#166534;">✅ Full Paid Customers</h4>
                <?php if(empty($paid_rows)): ?>
                    <p class="text-sm" style="color:#7a4e0a;">No paid customers found.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead><tr>
                            <th>Customer</th><th>Mobile</th><th class="text-right">Amount</th><th class="text-center">Date</th><th class="text-center">Invoice</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach($paid_rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['customer_mobile']); ?></td>
                                <td class="text-right font-bold" style="color:#16a34a;">₹<?php echo number_format($r['total_amount'],2); ?></td>
                                <td class="text-center"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                <td class="text-center font-mono text-xs" style="color:#d68b16;"><?php echo htmlspecialchars($r['invoice_no']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Part detail -->
            <div id="detailPart" class="detail-collapse mb-3">
                <h4 class="font-bold text-sm mb-3" style="color:#c2410c;">⏳ Part Payment — Balance Remaining</h4>
                <?php if(empty($part_rows)): ?>
                    <p class="text-sm" style="color:#7a4e0a;">No part payment customers found.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead><tr>
                            <th>Customer</th><th>Mobile</th><th class="text-right">Bill Total</th><th class="text-right">Paid</th><th class="text-right">⚠️ Balance</th><th class="text-center">Date</th><th class="text-center">Invoice</th><th class="text-center">Action</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach($part_rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['customer_mobile']); ?></td>
                                <td class="text-right font-bold" style="color:#d97706;">₹<?php echo number_format($r['total_amount'],2); ?></td>
                                <td class="text-right" style="color:#16a34a;">₹<?php echo number_format($r['paid_amount'],2); ?></td>
                                <td class="text-right font-bold" style="color:#dc2626;">₹<?php echo number_format($r['balance_amount'],2); ?></td>
                                <td class="text-center"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                <td class="text-center font-mono text-xs" style="color:#d68b16;"><?php echo htmlspecialchars($r['invoice_no']); ?></td>
                                <td class="text-center">
                                    <button onclick='sendReminder(<?php echo json_encode($r['invoice_no']); ?>, <?php echo json_encode($r['customer_name']); ?>, <?php echo json_encode($r['customer_mobile']); ?>, <?php echo floatval($r['balance_amount']); ?>, <?php echo json_encode($r['customer_email'] ?? ''); ?>)' class="btn-jewel" style="padding:5px 8px;font-size:11px;border-radius:16px;margin-right:6px;">🔔 Reminder</button>
                                    <button onclick='markAsPaid(<?php echo json_encode($r['invoice_no']); ?>, <?php echo floatval($r['balance_amount']); ?>)' class="btn-jewel" style="background:linear-gradient(135deg,#16a34a,#15803d);padding:5px 8px;font-size:11px;border-radius:16px;">✅ Mark Paid</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Unpaid detail -->
            <div id="detailUnpaid" class="detail-collapse">
                <h4 class="font-bold text-sm mb-3" style="color:#be123c;">❌ Unpaid — Full Payment Pending</h4>
                <?php if(empty($unpaid_rows)): ?>
                    <p class="text-sm" style="color:#16a34a;">No unpaid customers! 🎉</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead><tr>
                            <th>Customer</th><th>Mobile</th><th class="text-right">Bill Total</th><th class="text-right">⚠️ Due</th><th class="text-center">Date</th><th class="text-center">Invoice</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach($unpaid_rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['customer_mobile']); ?></td>
                                <td class="text-right font-bold" style="color:#d97706;">₹<?php echo number_format($r['total_amount'],2); ?></td>
                                <td class="text-right font-bold" style="color:#dc2626;">₹<?php echo number_format($r['balance_amount'],2); ?></td>
                                <td class="text-center"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                <td class="text-center font-mono text-xs" style="color:#d68b16;"><?php echo htmlspecialchars($r['invoice_no']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── GST SUMMARY ── -->
        <div class="jewel-card p-5 sm:p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-5 gap-3">
                <h3 class="section-title"><i class="fas fa-receipt"></i> GST Summary</h3>
                <form method="GET" class="flex items-center gap-2 flex-wrap">
                    <?php foreach(['filter_from','filter_to','filter_name','filter_status','filter_gst'] as $fk) {
                        if(!empty($_GET[$fk])) echo '<input type="hidden" name="'.$fk.'" value="'.htmlspecialchars($_GET[$fk]).'">';
                    } ?>
                    <label class="filter-label whitespace-nowrap">📅 Month:</label>
                    <input type="month" name="gst_month" value="<?php echo htmlspecialchars($gst_month); ?>" class="jewel-input" style="width:160px;">
                    <button type="submit" class="btn-jewel" style="padding:8px 16px;font-size:12px;">Go</button>
                    <a href="reports.php" class="btn-jewel" style="padding:8px 14px;font-size:12px;background:linear-gradient(135deg,#6b7280,#4b5563);">All Time</a>
                </form>
            </div>

            <?php
            $actual_gst = floatval($gst_summary['actual_gst_collected'] ?? 0);
            $taxable    = floatval($gst_summary['gst_taxable_total'] ?? 0);
            $cgst = $actual_gst / 2;
            $sgst = $actual_gst / 2;
            if($actual_gst <= 0 && $taxable > 0) {
                $actual_gst = round($taxable * 0.03, 2);
                $cgst = round($taxable * 0.015, 2);
                $sgst = round($taxable * 0.015, 2);
            }
            $month_label = $gst_month ? date('F Y', strtotime($gst_month.'-01')) : 'All Time';
            ?>

            <p class="text-xs mb-4" style="color:#7a4e0a;">📌 Showing data for: <strong style="color:#800020;"><?php echo $month_label; ?></strong></p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <!-- GST Card -->
                <div class="stat-card-gst p-5">
                    <div class="flex justify-between items-center mb-3">
                        <span class="font-bold text-sm" style="color:#134e4a;">📄 GST Bills</span>
                        <span class="text-2xl font-black" style="color:#0f766e;"><?php echo $gst_summary['gst_count']; ?> bills</span>
                    </div>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between py-1" style="border-bottom:1px solid rgba(20,184,166,0.2);">
                            <span style="color:#0d9488;">💰 Taxable Amount</span>
                            <strong style="color:#134e4a;">₹<?php echo number_format($taxable,2); ?></strong>
                        </div>
                        <div class="flex justify-between py-1" style="border-bottom:1px solid rgba(20,184,166,0.2);">
                            <span style="color:#0d9488;">📊 CGST (1.5%)</span>
                            <strong style="color:#0f766e;">₹<?php echo number_format($cgst,2); ?></strong>
                        </div>
                        <div class="flex justify-between py-1" style="border-bottom:1px solid rgba(20,184,166,0.2);">
                            <span style="color:#0d9488;">📊 SGST (1.5%)</span>
                            <strong style="color:#0f766e;">₹<?php echo number_format($sgst,2); ?></strong>
                        </div>
                        <div class="flex justify-between py-2 px-3 rounded-xl mt-1" style="background:rgba(52,211,153,0.15);">
                            <span class="font-bold" style="color:#134e4a;">✅ Total GST</span>
                            <strong class="text-lg" style="color:#0f766e;">₹<?php echo number_format($actual_gst,2); ?></strong>
                        </div>
                    </div>
                </div>
                <!-- Non-GST Card -->
                <div class="stat-card-nongst p-5">
                    <div class="flex justify-between items-center mb-3">
                        <span class="font-bold text-sm" style="color:#475569;">📋 Non-GST Bills</span>
                        <span class="text-2xl font-black" style="color:#334155;"><?php echo $gst_summary['nongst_count']; ?> bills</span>
                    </div>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between py-1" style="border-bottom:1px solid #e2e8f0;">
                            <span style="color:#64748b;">💰 Total Sale Amount</span>
                            <strong style="color:#334155;">₹<?php echo number_format($gst_summary['nongst_amt'],2); ?></strong>
                        </div>
                        <div class="flex justify-between py-1" style="border-bottom:1px solid #e2e8f0;">
                            <span style="color:#64748b;">📊 Tax Rate</span>
                            <strong style="color:#475569;">0% (No GST)</strong>
                        </div>
                        <div class="flex justify-between py-2 px-3 rounded-xl mt-1" style="background:rgba(100,116,139,0.1);">
                            <span class="font-bold" style="color:#475569;">❌ GST Collected</span>
                            <strong class="text-lg" style="color:#334155;">₹0.00</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gst-total-box">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <p class="font-bold text-sm" style="color:#0d9488;">🏛️ Total GST Payable to Govt — <?php echo $month_label; ?></p>
                        <p class="text-xs mt-1" style="color:#14b8a6;">CGST ₹<?php echo number_format($cgst,2); ?> + SGST ₹<?php echo number_format($sgst,2); ?></p>
                    </div>
                    <p class="text-2xl font-black" style="color:#0f766e;">₹<?php echo number_format($actual_gst,2); ?></p>
                </div>
            </div>
        </div>

        <!-- ── ALL BILLS TABLE ── -->
        <div class="jewel-card p-5 sm:p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-5 gap-3">
                <h3 class="section-title"><i class="fas fa-file-invoice"></i> All Bills Record</h3>
                <button onclick="downloadExcel()" class="btn-excel">
                    <i class="fas fa-file-excel"></i> Download Excel (.xlsx)
                </button>
            </div>

            <!-- Filter bar -->
            <form method="GET" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-5 p-4 rounded-xl" style="background:#fdf6e3;border:1px solid rgba(181,115,14,0.15);">
                <div>
                    <label class="filter-label">📅 From Date</label>
                    <input type="date" name="filter_from" value="<?php echo htmlspecialchars($filter_from); ?>" class="jewel-input w-full">
                </div>
                <div>
                    <label class="filter-label">📅 To Date</label>
                    <input type="date" name="filter_to" value="<?php echo htmlspecialchars($filter_to); ?>" class="jewel-input w-full">
                </div>
                <div>
                    <label class="filter-label">🔍 Name / Mobile</label>
                    <input type="text" name="filter_name" value="<?php echo htmlspecialchars($filter_name); ?>" placeholder="Search…" class="jewel-input w-full">
                </div>
                <div>
                    <label class="filter-label">💳 Payment</label>
                    <select name="filter_status" class="jewel-input w-full">
                        <option value="" <?php if(!$filter_status) echo 'selected'; ?>>All</option>
                        <option value="paid"   <?php if($filter_status==='paid')   echo 'selected'; ?>>✅ Paid</option>
                        <option value="part"   <?php if($filter_status==='part')   echo 'selected'; ?>>⏳ Part</option>
                        <option value="unpaid" <?php if($filter_status==='unpaid') echo 'selected'; ?>>❌ Unpaid</option>
                    </select>
                </div>
                <div>
                    <label class="filter-label">🧾 GST Type</label>
                    <select name="filter_gst" class="jewel-input w-full">
                        <option value="" <?php if(!$filter_gst) echo 'selected'; ?>>All</option>
                        <option value="gst"     <?php if($filter_gst==='gst')     echo 'selected'; ?>>📄 GST (3%)</option>
                        <option value="non_gst" <?php if($filter_gst==='non_gst') echo 'selected'; ?>>📋 Non-GST</option>
                    </select>
                </div>
                <div class="col-span-2 sm:col-span-3 lg:col-span-5 flex gap-3">
                    <button type="submit" class="btn-jewel" style="padding:8px 20px;font-size:12px;">🔍 Filter</button>
                    <a href="reports.php" class="btn-jewel" style="padding:8px 16px;font-size:12px;background:linear-gradient(135deg,#6b7280,#4b5563);">✖ Clear</a>
                </div>
            </form>

            <!-- Summary chips -->
            <div class="flex flex-wrap gap-2 mb-4">
                <span class="chip chip-yellow">Total Bills: <strong><?php echo $total_bills_count; ?></strong></span>
                <span class="chip chip-green">Total Amount: <strong>₹<?php echo number_format($total_bills_amount,2); ?></strong></span>
                <?php
                $gst_filtered    = count(array_filter($bills_rows, fn($b)=>$b['gst_type']==='gst'));
                $nongst_filtered = count(array_filter($bills_rows, fn($b)=>$b['gst_type']!=='gst'));
                $total_gst_collected = array_sum(array_column(array_filter($bills_rows, fn($b)=>$b['gst_type']==='gst'), 'gst_amount'));
                $total_cgst = round($total_gst_collected/2, 2);
                $total_sgst = round($total_gst_collected/2, 2);
                ?>
                <span class="chip chip-teal">GST Bills: <strong><?php echo $gst_filtered; ?></strong></span>
                <span class="chip chip-gray">Non-GST: <strong><?php echo $nongst_filtered; ?></strong></span>
                <?php if($total_gst_collected > 0): ?>
                <span class="chip chip-emerald">Total GST: <strong>₹<?php echo number_format($total_gst_collected,2); ?></strong>
                    <span style="font-size:10px;opacity:0.7;">(CGST ₹<?php echo number_format($total_cgst,2); ?> + SGST ₹<?php echo number_format($total_sgst,2); ?>)</span>
                </span>
                <?php endif; ?>
            </div>

            <!-- Table -->
            <div class="bills-table-wrap overflow-x-auto rounded-xl" style="border:1px solid rgba(181,115,14,0.15);">
                <table id="billsTable" class="report-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Invoice No</th>
                            <th>Customer</th>
                            <th>Mobile</th>
                            <th class="text-right">Amount (₹)</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Due</th>
                            <th class="text-center">GST</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Date</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($bills_rows)): ?>
                        <tr><td colspan="11" class="text-center py-10" style="color:#7a4e0a;">No bills found.</td></tr>
                    <?php else: foreach($bills_rows as $i => $bill):
                        $balance   = floatval($bill['balance_amount'] ?? 0);
                        $paid_show = floatval($bill['paid_amount'] ?? 0);
                        $gst_amt   = floatval($bill['gst_amount'] ?? 0);
                        $cgst_amt  = round($gst_amt/2, 2);
                        $sgst_amt  = round($gst_amt/2, 2);
                        $gstin     = trim($bill['customer_gstin'] ?? '');
                        $ps = $bill['payment_status'];
                        if ($ps === 'paid')        $status_badge = '<span class="badge-paid">✅ Paid</span>';
                        elseif ($ps === 'part')    $status_badge = '<span class="badge-part">⏳ Part</span>';
                        elseif ($ps === 'unpaid')  $status_badge = '<span class="badge-unpaid">❌ Unpaid</span>';
                        else                       $status_badge = htmlspecialchars($ps);
                    ?>
                    <tr>
                        <td style="color:#7a4e0a;"><?php echo $i+1; ?></td>
                        <td class="font-mono text-xs" style="color:#d68b16;"><?php echo htmlspecialchars($bill['invoice_no']); ?></td>

                        <!-- ★ UPDATED: Customer name + GSTIN tag below -->
                        <td>
                            <span class="font-semibold" style="color:#800020;"><?php echo htmlspecialchars($bill['customer_name']); ?></span>
                            <?php if($gstin !== ''): ?>
                                <br><span class="gstin-tag">🧾 <?php echo htmlspecialchars($gstin); ?></span>
                            <?php endif; ?>
                        </td>

                        <td style="color:#6b7280;"><?php echo htmlspecialchars($bill['customer_mobile']); ?></td>
                        <td class="text-right font-bold" style="color:#16a34a;">₹<?php echo number_format($bill['total_amount'],2); ?></td>
                        <td class="text-right" style="color:#15803d;">₹<?php echo number_format($paid_show,2); ?></td>
                        <td class="text-right font-bold" style="color:<?php echo $balance>0?'#dc2626':'#9ca3af'; ?>">
                            <?php echo $balance>0 ? '₹'.number_format($balance,2) : '—'; ?>
                        </td>
                        <td class="text-center">
                            <?php if(strpos($bill['gst_type'], 'gst') === 0): ?>
                                <span style="color:#0d9488;font-weight:700;font-size:11px;">📄 GST</span>
                                <?php if($gst_amt>0): ?>
                                    <div style="font-size:9px;color:#14b8a6;">Total: ₹<?php echo number_format($gst_amt,2); ?></div>
                                    <div style="font-size:9px;color:#14b8a6;">C+S: ₹<?php echo number_format($cgst_amt,2); ?> ea</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#9ca3af;font-size:11px;">📋 Non-GST</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo $status_badge; ?></td>
                        <td class="text-center text-xs" style="color:#6b7280;white-space:nowrap;"><?php echo date('d M Y', strtotime($bill['created_at'])); ?></td>
                        <td class="text-center">
                            <div class="flex justify-center gap-1.5">
                                <a href="view_pdf.php?invoice_no=<?php echo urlencode($bill['invoice_no']); ?>" target="_blank"
                                   class="btn-jewel" style="padding:3px 10px;font-size:10px;border-radius:20px;white-space:nowrap;">
                                   🖨️ Print
                                </a>
                                <button onclick="confirmDeleteInvoice('<?php echo htmlspecialchars($bill['invoice_no']); ?>')"
                                        class="btn-jewel" style="background:linear-gradient(135deg,#7f1d1d,#ef4444);padding:3px 10px;font-size:10px;border-radius:20px;white-space:nowrap;">
                                   🗑️ Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>


    </div>

    <footer>
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MOTI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology</a>
        </p>
    </footer>
</div><!-- end .page-wrapper -->

<style>
@media (max-width: 768px) { nav.nav-gold { margin-left: 0 !important; } }
</style>

<script>
    /* ── Sidebar ── */
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

    /* ── Toggle detail tables ── */
    function toggleDetail(id) {
        document.getElementById(id).classList.toggle('open');
    }

    /* ── Actions: Send Reminder & Mark Paid (uses billing.php AJAX endpoints) ── */
    function sendReminder(invoiceNo, customerName, customerMobile, balanceAmount, customerEmail) {
        console.log('sendReminder called', {invoiceNo, customerName, customerMobile, balanceAmount, customerEmail});
        if(!invoiceNo) return alert('Invoice number is missing');
        // If no customer email provided, prompt admin to enter one
        let email = customerEmail || '';
        if(!email) {
            email = prompt('No email found for this customer. Enter email to send reminder to (or Cancel):', '');
            if(email === null) return; // user cancelled
            email = (email || '').trim();
            const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if(email && !emailRe.test(email)) { alert('Please enter a valid email address.'); return; }
        }
        if(!confirm('Send payment reminder to ' + customerName + ' (' + (customerMobile||'') + ') at ' + email + '?')) return;
        const fd = new FormData();
        fd.append('invoice_no', invoiceNo);
        fd.append('customer_name', customerName || 'Customer');
        fd.append('customer_mobile', customerMobile || '');
        fd.append('balance_amount', balanceAmount || 0);
        fd.append('customer_email', email || '');
        fetch('billing.php?action=send_reminder', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(async r => {
                console.log('sendReminder response status', r.status);
                const txt = await r.text();
                try { const res = JSON.parse(txt); if(res.success) { alert(res.message || 'Reminder sent'); location.reload(); } else alert(res.message || txt || 'Failed to send reminder'); }
                catch(e) { alert('Unexpected server response: ' + txt); }
            }).catch(e => { console.error('sendReminder fetch error', e); alert('Error: ' + e); });
    }

    function markAsPaid(invoiceNo, balanceAmount) {
        console.log('markAsPaid called', {invoiceNo, balanceAmount});
        if(!invoiceNo) return alert('Invoice number is missing');
        if(!confirm('Mark invoice ' + invoiceNo + ' as paid (₹' + parseFloat(balanceAmount).toFixed(2) + ')?')) return;
        const fd = new FormData();
        fd.append('invoice_no', invoiceNo);
        fd.append('amount', balanceAmount || 0);
        fd.append('anonymize', '1');
        fetch('billing.php?action=mark_paid', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(async r => {
                console.log('markAsPaid response status', r.status);
                const txt = await r.text();
                console.log('markAsPaid response text', txt);
                try { const res = JSON.parse(txt); if(res.success) { alert(res.message || 'Marked as paid'); location.reload(); } else alert(res.message || txt || 'Failed to mark as paid'); }
                catch(e) { alert('Unexpected server response: ' + txt); }
            }).catch(e => { console.error('markAsPaid fetch error', e); alert('Error: ' + e); });
    }

    /* ── Chart ── */
    const labels    = <?php echo json_encode(array_column($daily_sales,'date')); ?>;
    const salesData = <?php echo json_encode(array_column($daily_sales,'total')); ?>;
    const chartEl = document.getElementById('salesChart');
    if (chartEl) {
        new Chart(chartEl.getContext('2d'), {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Sales (₹)', data: salesData,
                    borderColor: '#d68b16', backgroundColor: 'rgba(214,139,22,0.1)',
                    borderWidth: 3, tension: 0.4, fill: true,
                    pointRadius: 5, pointHoverRadius: 8,
                    pointBackgroundColor: '#d68b16', pointBorderColor: '#800020',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                plugins: {
                    legend: { labels: { font: { size: 12, weight: 'bold' }, color: '#800020' } },
                    tooltip: { backgroundColor: '#fdf6e3', titleColor: '#800020', bodyColor: '#7a4e0a', borderColor: '#d68b16', borderWidth: 1,
                        callbacks: { label: ctx => '💰 ₹ ' + ctx.raw.toLocaleString('en-IN') }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(181,115,14,0.1)' },
                        ticks: { callback: v => '₹' + v.toLocaleString('en-IN'), color:'#7a4e0a', font:{size:11} } },
                    x: { grid: { color: 'rgba(181,115,14,0.1)' },
                        ticks: { color:'#7a4e0a', font:{size:11} } }
                }
            }
        });
    }

    /* ── Excel Export ── */
    const billsData = <?php echo json_encode($bills_rows); ?>;

    function downloadExcel() {
        if (!billsData || billsData.length === 0) { alert('No bills found to export!'); return; }
        const today     = '<?php echo date("d M Y"); ?>';
        const todayFile = '<?php echo date("Y-m-d"); ?>';
        const inrFmt  = v => '₹' + parseFloat(v||0).toLocaleString('en-IN', {minimumFractionDigits:2});
        const fmtDate = raw => {
            if (!raw) return '';
            const [yr, mo, dy] = raw.split(' ')[0].split('-');
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return `${dy}-${months[parseInt(mo)-1]}-${yr.slice(2)}`;
        };
        const totalAmt     = billsData.reduce((s,b) => s + parseFloat(b.total_amount||0), 0);
        const totalPaid    = billsData.reduce((s,b) => s + parseFloat(b.paid_amount||0), 0);
        const totalBalance = billsData.reduce((s,b) => s + parseFloat(b.balance_amount||0), 0);
        const totalGST     = billsData.reduce((s,b) => s + parseFloat(b.gst_amount||0), 0);
        const gstBills     = billsData.filter(b => b.gst_type === 'gst').length;
        const nonGstBills  = billsData.filter(b => b.gst_type !== 'gst').length;
        const paidBills    = billsData.filter(b => b.payment_status === 'paid').length;
        const partBills    = billsData.filter(b => b.payment_status === 'part').length;
        const unpaidBills  = billsData.filter(b => b.payment_status === 'unpaid').length;

        const wb = XLSX.utils.book_new();

        // Sheet 1 — All Bills
        const aoa1 = [];
        aoa1.push(['💎 MOTI JEWELLERS', '', '', '', '', '', '', '', '', '', '', '']);
        aoa1.push(['All Bills Report — Generated: ' + today, '', '', '', '', '', '', '', '', '', '', '']);
        aoa1.push([]);
        aoa1.push(['Total Bills', billsData.length, '', 'Total Amount', inrFmt(totalAmt), '', 'Total GST Collected', inrFmt(totalGST), '', 'Balance Due', inrFmt(totalBalance), '']);
        aoa1.push(['Full Paid', paidBills, '', 'Part Payment', partBills, '', 'Unpaid', unpaidBills, '', 'GST Bills', gstBills, '']);
        aoa1.push([]);
        // ★ UPDATED: Added GSTIN column header
        aoa1.push(['#', 'Invoice No', 'Customer Name', 'GSTIN', 'Mobile', 'Address', 'Total Amount (₹)', 'Paid Amount (₹)', 'Balance Due (₹)', 'GST Type', 'GST Amount (₹)', 'Payment Status', 'Date']);
        billsData.forEach((b, i) => {
            aoa1.push([
                i + 1,
                b.invoice_no,
                b.customer_name,
                b.customer_gstin || '',   // ★ GSTIN column
                b.customer_mobile,
                b.customer_address || '',
                parseFloat(b.total_amount||0),
                parseFloat(b.paid_amount||0),
                parseFloat(b.balance_amount||0),
                b.gst_type === 'gst' ? 'GST (3%)' : 'Non-GST',
                parseFloat(b.gst_amount||0),
                b.payment_status === 'paid' ? 'Full Paid' : b.payment_status === 'part' ? 'Part Payment' : 'Unpaid',
                fmtDate(b.created_at)
            ]);
        });
        aoa1.push(['', 'TOTAL', '', '', '', '', billsData.reduce((s,b)=>s+parseFloat(b.total_amount||0),0), billsData.reduce((s,b)=>s+parseFloat(b.paid_amount||0),0), totalBalance, '', totalGST, '', '']);

        const ws1 = XLSX.utils.aoa_to_sheet(aoa1);
        ws1['!cols'] = [{wch:5},{wch:20},{wch:22},{wch:20},{wch:14},{wch:28},{wch:18},{wch:18},{wch:18},{wch:12},{wch:16},{wch:14},{wch:14}];
        ws1['!merges'] = [{s:{r:0,c:0},e:{r:0,c:12}},{s:{r:1,c:0},e:{r:1,c:12}}];
        XLSX.utils.book_append_sheet(wb, ws1, 'All Bills');

        // Sheet 2 — Payment Summary
        const aoa2 = [];
        aoa2.push(['💎 MOTI JEWELLERS — Payment Summary']);
        aoa2.push(['Generated: ' + today]);
        aoa2.push([]);
        aoa2.push(['Category', 'Count', 'Amount (₹)']);
        aoa2.push(['Full Paid', paidBills, totalPaid]);
        aoa2.push(['Part Payment', partBills, billsData.filter(b=>b.payment_status==='part').reduce((s,b)=>s+parseFloat(b.total_amount||0),0)]);
        aoa2.push(['Unpaid (Credit)', unpaidBills, billsData.filter(b=>b.payment_status==='unpaid').reduce((s,b)=>s+parseFloat(b.total_amount||0),0)]);
        aoa2.push(['TOTAL', billsData.length, totalAmt]);
        aoa2.push([]);
        aoa2.push(['Balance Due', '', totalBalance]);
        aoa2.push(['Total GST Collected', '', totalGST]);
        aoa2.push(['CGST (1.5%)', '', totalGST/2]);
        aoa2.push(['SGST (1.5%)', '', totalGST/2]);
        const ws2 = XLSX.utils.aoa_to_sheet(aoa2);
        ws2['!cols'] = [{wch:28},{wch:12},{wch:20}];
        XLSX.utils.book_append_sheet(wb, ws2, 'Payment Summary');

        // Sheet 3 — Pending
        const pendingData = billsData.filter(b => b.payment_status !== 'paid' && parseFloat(b.balance_amount||0) > 0);
        // ★ UPDATED: Added GSTIN column in pending sheet too
        const aoa3 = [['#','Invoice No','Customer','GSTIN','Mobile','Bill Total (₹)','Paid (₹)','Balance Due (₹)','Status','Date']];
        pendingData.forEach((b,i) => aoa3.push([
            i+1, b.invoice_no, b.customer_name, b.customer_gstin||'', b.customer_mobile,
            parseFloat(b.total_amount||0), parseFloat(b.paid_amount||0), parseFloat(b.balance_amount||0),
            b.payment_status==='part'?'Part Payment':'Unpaid', fmtDate(b.created_at)
        ]));
        if(!pendingData.length) aoa3.push(['','No pending payments! 🎉','','','','','','','','']);
        const ws3 = XLSX.utils.aoa_to_sheet(aoa3);
        ws3['!cols'] = [{wch:5},{wch:20},{wch:22},{wch:20},{wch:14},{wch:18},{wch:16},{wch:18},{wch:14},{wch:14}];
        XLSX.utils.book_append_sheet(wb, ws3, 'Pending Payments');

        XLSX.writeFile(wb, `MAA GOURIJewellers_Report_${todayFile}.xlsx`);
    }

    let currentDeleteInvoiceNo = '';
    let currentDeleteMode = '';
    let invoiceItemsCache = [];

    function confirmDeleteInvoice(invoiceNo) {
        currentDeleteInvoiceNo = invoiceNo;
        currentDeleteMode = '';
        
        // Reset modal UI
        document.getElementById('deleteInvoiceNoText').textContent = invoiceNo;
        document.getElementById('btnDeletePerm').className = 'flex-1 py-3 px-4 rounded-xl border border-red-300 text-red-700 bg-red-50 hover:bg-red-100 font-semibold text-xs transition duration-200 text-center cursor-pointer';
        document.getElementById('btnDeleteRestore').className = 'flex-1 py-3 px-4 rounded-xl border border-green-300 text-green-700 bg-green-50 hover:bg-green-100 font-semibold text-xs transition duration-200 text-center cursor-pointer';
        document.getElementById('restoreStockForm').classList.add('hidden');
        document.getElementById('btnConfirmDeleteSubmit').disabled = true;
        document.getElementById('btnConfirmDeleteSubmit').style.opacity = '0.5';
        
        // Open Modal
        const modal = document.getElementById('deleteInvoiceModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        
        // Fetch invoice items dynamically
        const itemsList = document.getElementById('restoreItemsList');
        itemsList.innerHTML = '<div class="text-center py-4 text-xs text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Loading items...</div>';
        
        fetch('reports.php?action=get_invoice_items&invoice_no=' + encodeURIComponent(invoiceNo))
            .then(response => response.json())
            .then(data => {
                invoiceItemsCache = data;
                itemsList.innerHTML = '';
                
                if (data.length === 0) {
                    itemsList.innerHTML = '<p class="text-xs text-gray-500 text-center py-2">No items found in this invoice.</p>';
                    return;
                }
                
                data.forEach((item, index) => {
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'p-3 bg-yellow-50 bg-opacity-50 rounded-xl border border-yellow-100 flex flex-col gap-2 mb-2';
                    itemDiv.innerHTML = `
                        <div class="flex justify-between items-start">
                            <span class="text-xs font-semibold text-gray-800">${item.product_name}</span>
                            <span class="text-[10px] text-gray-500 font-mono">Sold: ${item.quantity} ${item.unit || 'pcs'}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-semibold text-gray-600 mb-1">Qty to Restore</label>
                                <input type="number" id="restoreQty_${index}" value="${item.quantity}" step="0.001" min="0" max="${item.quantity}" class="jewel-input w-full rounded-lg px-2 py-1 text-xs" style="background:#fff;">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-gray-600 mb-1">HSN Code</label>
                                <input type="text" id="restoreHsn_${index}" value="0" class="jewel-input w-full rounded-lg px-2 py-1 text-xs" style="background:#fff;">
                            </div>
                        </div>
                    `;
                    itemsList.appendChild(itemDiv);
                });
            })
            .catch(err => {
                console.error('Error fetching invoice items:', err);
                itemsList.innerHTML = '<p class="text-xs text-red-500 text-center py-2">Failed to load invoice items.</p>';
            });
    }

    function closeDeleteModal() {
        const modal = document.getElementById('deleteInvoiceModal');
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }

    function selectDeleteMode(mode) {
        currentDeleteMode = mode;
        const btnSubmit = document.getElementById('btnConfirmDeleteSubmit');
        btnSubmit.disabled = false;
        btnSubmit.style.opacity = '1';
        
        const btnPerm = document.getElementById('btnDeletePerm');
        const btnRestore = document.getElementById('btnDeleteRestore');
        const form = document.getElementById('restoreStockForm');
        
        if (mode === 'permanent') {
            btnPerm.className = 'flex-1 py-3 px-4 rounded-xl border-2 border-red-600 text-red-700 bg-red-100 font-bold text-xs transition duration-200 text-center cursor-pointer';
            btnRestore.className = 'flex-1 py-3 px-4 rounded-xl border border-green-300 text-green-700 bg-green-50 hover:bg-green-100 font-semibold text-xs transition duration-200 text-center cursor-pointer';
            form.classList.add('hidden');
        } else {
            btnRestore.className = 'flex-1 py-3 px-4 rounded-xl border-2 border-green-600 text-green-700 bg-green-100 font-bold text-xs transition duration-200 text-center cursor-pointer';
            btnPerm.className = 'flex-1 py-3 px-4 rounded-xl border border-red-300 text-red-700 bg-red-50 hover:bg-red-100 font-semibold text-xs transition duration-200 text-center cursor-pointer';
            form.classList.remove('hidden');
        }
    }

    function submitDeleteInvoiceAction() {
        if (!currentDeleteMode) return;
        
        let confirmMsg = '';
        if (currentDeleteMode === 'permanent') {
            confirmMsg = "⚠️ FINAL WARNING: This action is permanent and CANNOT be undone.\nAre you sure you want to delete Invoice #" + currentDeleteInvoiceNo + " permanently (without restoring stock)?";
        } else {
            confirmMsg = "Are you sure you want to delete Invoice #" + currentDeleteInvoiceNo + " and move the specified quantities back to stock?";
        }
        
        if (!confirm(confirmMsg)) return;
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'reports.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action_delete_invoice';
        actionInput.value = '1';
        form.appendChild(actionInput);
        
        const invoiceInput = document.createElement('input');
        invoiceInput.type = 'hidden';
        invoiceInput.name = 'invoice_no';
        invoiceInput.value = currentDeleteInvoiceNo;
        form.appendChild(invoiceInput);
        
        const modeInput = document.createElement('input');
        modeInput.type = 'hidden';
        modeInput.name = 'delete_mode';
        modeInput.value = currentDeleteMode;
        form.appendChild(modeInput);
        
        if (currentDeleteMode === 'restore_stock') {
            const restoreData = invoiceItemsCache.map((item, index) => {
                const qtyInput = document.getElementById('restoreQty_' + index);
                const hsnInput = document.getElementById('restoreHsn_' + index);
                
                return {
                    product_id: item.product_id,
                    product_name: item.product_name,
                    serial_no: item.serial_no || '',
                    huid_code: item.huid_code || '',
                    price: item.price || 0,
                    unit: item.unit || '',
                    category: item.category || '',
                    weight: item.weight || '',
                    restore_qty: parseFloat(qtyInput ? qtyInput.value : item.quantity) || 0,
                    hsn_code: hsnInput ? hsnInput.value.trim() : '0'
                };
            });
            
            const restoreInput = document.createElement('input');
            restoreInput.type = 'hidden';
            restoreInput.name = 'items_restore';
            restoreInput.value = JSON.stringify(restoreData);
            form.appendChild(restoreInput);
        }
        
        document.body.appendChild(form);
        form.submit();
    }
</script>

<!-- Custom Delete Invoice Modal -->
<div id="deleteInvoiceModal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black bg-opacity-60 backdrop-filter backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl border border-yellow-200 shadow-2xl w-full max-w-md overflow-hidden flex flex-col transform transition-transform duration-300">
        <!-- Modal Header -->
        <div class="px-5 py-4 bg-gradient-to-r from-red-800 to-red-600 flex justify-between items-center text-white">
            <h3 class="text-sm font-bold flex items-center gap-2">
                <i class="fas fa-trash-alt"></i> Delete Invoice #<span id="deleteInvoiceNoText"></span>
            </h3>
            <button onclick="closeDeleteModal()" class="text-white hover:text-gray-200 text-xl font-bold">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div class="p-5 space-y-4 max-h-[60vh] overflow-y-auto">
            <p class="text-xs text-gray-600">
                Choose how you want to handle the items in this invoice when deleting it:
            </p>
            
            <div class="flex gap-3">
                <button type="button" onclick="selectDeleteMode('permanent')" id="btnDeletePerm" class="flex-1 py-3 px-4 rounded-xl border border-red-300 text-red-700 bg-red-50 hover:bg-red-100 font-semibold text-xs transition duration-200 text-center cursor-pointer">
                    🗑️ Delete Permanently<br><span class="text-[9px] font-normal text-red-500">(Stock is NOT updated)</span>
                </button>
                <button type="button" onclick="selectDeleteMode('restore_stock')" id="btnDeleteRestore" class="flex-1 py-3 px-4 rounded-xl border border-green-300 text-green-700 bg-green-50 hover:bg-green-100 font-semibold text-xs transition duration-200 text-center cursor-pointer">
                    📦 Move to Stock<br><span class="text-[9px] font-normal text-green-500">(Add items to inventory)</span>
                </button>
            </div>
            
            <!-- Items Form (Visible only when 'restore_stock' is selected) -->
            <div id="restoreStockForm" class="hidden space-y-3 pt-3 border-t border-dashed border-gray-200">
                <h4 class="text-xs font-bold text-yellow-800 uppercase tracking-wider">Quantities & HSN to move back:</h4>
                <div class="space-y-1" id="restoreItemsList">
                    <!-- Dynamic Items list populated by JS -->
                </div>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="px-5 py-3 bg-gray-50 flex justify-end gap-2.5 border-t border-gray-100">
            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-700 text-xs font-semibold rounded-lg transition cursor-pointer">
                Cancel
            </button>
            <button type="button" id="btnConfirmDeleteSubmit" onclick="submitDeleteInvoiceAction()" disabled class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-lg transition cursor-pointer">
                Confirm Delete
            </button>
        </div>
    </div>
</div>
</body>
</html>






