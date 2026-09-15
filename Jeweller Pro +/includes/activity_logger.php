<?php
/**
 * Product Activity Logger & Stock Audit Trail
 * Tracks product additions, edits, deletions, billings, restocks, and transfers.
 */

if (!function_exists('init_activity_log_table')) {
    function init_activity_log_table($conn) {
        $sql = "CREATE TABLE IF NOT EXISTS product_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            activity_type ENUM('added', 'edited', 'billed', 'deleted', 'restocked', 'transferred') NOT NULL,
            product_id INT NULL,
            product_name VARCHAR(255) NOT NULL,
            category VARCHAR(100) DEFAULT '',
            serial_no VARCHAR(100) DEFAULT '',
            huid_code VARCHAR(100) DEFAULT '',
            quantity DECIMAL(10,3) DEFAULT 0,
            unit VARCHAR(20) DEFAULT 'pcs',
            weight DECIMAL(10,3) DEFAULT 0,
            rate DECIMAL(12,2) DEFAULT 0,
            total_amount DECIMAL(12,2) DEFAULT 0,
            invoice_no VARCHAR(50) NULL,
            customer_name VARCHAR(150) NULL,
            customer_mobile VARCHAR(20) NULL,
            details TEXT NULL,
            performed_by VARCHAR(100) DEFAULT 'Admin',
            is_nongst TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type (activity_type),
            INDEX idx_created (created_at),
            INDEX idx_pid (product_id),
            INDEX idx_inv (invoice_no),
            INDEX idx_nongst (is_nongst)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        @mysqli_query($conn, $sql);

        $chk_col = @mysqli_query($conn, "SHOW COLUMNS FROM product_activity_logs LIKE 'is_nongst'");
        if ($chk_col && mysqli_num_rows($chk_col) == 0) {
            @mysqli_query($conn, "ALTER TABLE product_activity_logs ADD COLUMN is_nongst TINYINT(1) DEFAULT 0, ADD INDEX idx_nongst (is_nongst)");
            // Backfill existing Cash Memo logs
            @mysqli_query($conn, "UPDATE product_activity_logs SET is_nongst = 1 WHERE invoice_no LIKE 'CM-%'");
        }
    }
}

if (!function_exists('log_product_activity')) {
    function log_product_activity($conn, $data) {
        init_activity_log_table($conn);
        
        $activity_type = mysqli_real_escape_string($conn, $data['activity_type'] ?? 'added');
        $product_id = (!empty($data['product_id']) && is_numeric($data['product_id'])) ? intval($data['product_id']) : "NULL";
        $product_name = mysqli_real_escape_string($conn, trim($data['product_name'] ?? ''));
        $category = mysqli_real_escape_string($conn, trim($data['category'] ?? ''));
        $serial_no = mysqli_real_escape_string($conn, trim($data['serial_no'] ?? ''));
        $huid_code = mysqli_real_escape_string($conn, trim($data['huid_code'] ?? ''));
        $quantity = floatval($data['quantity'] ?? 0);
        $unit = mysqli_real_escape_string($conn, trim($data['unit'] ?? 'pcs'));
        $weight = floatval($data['weight'] ?? 0);
        $rate = floatval($data['rate'] ?? 0);
        $total_amount = floatval($data['total_amount'] ?? 0);
        $raw_inv = trim($data['invoice_no'] ?? '');
        $invoice_no = !empty($raw_inv) ? "'" . mysqli_real_escape_string($conn, $raw_inv) . "'" : "NULL";
        $customer_name = !empty($data['customer_name']) ? "'" . mysqli_real_escape_string($conn, trim($data['customer_name'])) . "'" : "NULL";
        $customer_mobile = !empty($data['customer_mobile']) ? "'" . mysqli_real_escape_string($conn, trim($data['customer_mobile'])) . "'" : "NULL";
        $details = mysqli_real_escape_string($conn, trim($data['details'] ?? ''));
        
        // Auto-detect is_nongst if not explicitly specified
        $is_nongst = 0;
        if (isset($data['is_nongst'])) {
            $is_nongst = $data['is_nongst'] ? 1 : 0;
        } elseif (!empty($raw_inv)) {
            if (stripos($raw_inv, 'CM-') === 0) {
                $is_nongst = 1;
            } else {
                $inv_chk = @mysqli_query($conn, "SELECT gst_type, gst_amount FROM invoices WHERE invoice_no = '" . mysqli_real_escape_string($conn, $raw_inv) . "' LIMIT 1");
                if ($inv_chk && $ir = mysqli_fetch_assoc($inv_chk)) {
                    if (strpos($ir['gst_type'] ?? '', 'gst') !== 0 && (empty($ir['gst_amount']) || floatval($ir['gst_amount']) == 0)) {
                        $is_nongst = 1;
                    }
                }
            }
        }

        $user_session = $_SESSION['user_name'] ?? 'Admin';
        $performed_by = mysqli_real_escape_string($conn, trim($data['performed_by'] ?? $user_session));
        $created_at = !empty($data['created_at']) ? "'" . mysqli_real_escape_string($conn, $data['created_at']) . "'" : "NOW()";

        // Check if is_nongst column exists in table
        static $has_nongst_field = null;
        if ($has_nongst_field === null) {
            $col_chk = @mysqli_query($conn, "SHOW COLUMNS FROM product_activity_logs LIKE 'is_nongst'");
            $has_nongst_field = ($col_chk && mysqli_num_rows($col_chk) > 0);
            if (!$has_nongst_field) {
                @mysqli_query($conn, "ALTER TABLE product_activity_logs ADD COLUMN is_nongst TINYINT(1) DEFAULT 0, ADD INDEX idx_nongst (is_nongst)");
                $col_chk2 = @mysqli_query($conn, "SHOW COLUMNS FROM product_activity_logs LIKE 'is_nongst'");
                $has_nongst_field = ($col_chk2 && mysqli_num_rows($col_chk2) > 0);
            }
        }

        if ($has_nongst_field) {
            $query = "INSERT INTO product_activity_logs (
                activity_type, product_id, product_name, category, serial_no, huid_code,
                quantity, unit, weight, rate, total_amount, invoice_no, customer_name, customer_mobile,
                details, performed_by, is_nongst, created_at
            ) VALUES (
                '$activity_type', $product_id, '$product_name', '$category', '$serial_no', '$huid_code',
                $quantity, '$unit', $weight, $rate, $total_amount, $invoice_no, $customer_name, $customer_mobile,
                '$details', '$performed_by', $is_nongst, $created_at
            )";
        } else {
            $query = "INSERT INTO product_activity_logs (
                activity_type, product_id, product_name, category, serial_no, huid_code,
                quantity, unit, weight, rate, total_amount, invoice_no, customer_name, customer_mobile,
                details, performed_by, created_at
            ) VALUES (
                '$activity_type', $product_id, '$product_name', '$category', '$serial_no', '$huid_code',
                $quantity, '$unit', $weight, $rate, $total_amount, $invoice_no, $customer_name, $customer_mobile,
                '$details', '$performed_by', $created_at
            )";
        }
        return mysqli_query($conn, $query);
    }
}

if (!function_exists('sync_missing_activity_logs')) {
    function sync_missing_activity_logs($conn) {
        init_activity_log_table($conn);
        
        // If table already has logs, no initial batch sync is needed
        $chk_count = @mysqli_fetch_assoc(@mysqli_query($conn, "SELECT COUNT(*) as c FROM product_activity_logs"));
        if ($chk_count && intval($chk_count['c']) > 0) {
            return;
        }

        // 1. Initial sync of billed items from existing invoices
        $inv_res = @mysqli_query($conn, "SELECT ii.*, i.invoice_no, i.customer_name, i.customer_mobile, i.created_at as inv_date FROM invoice_items ii JOIN invoices i ON ii.invoice_id = i.id ORDER BY i.created_at ASC");
        if ($inv_res) {
            while ($row = mysqli_fetch_assoc($inv_res)) {
                $p_name = !empty($row['product_name']) ? $row['product_name'] : 'Jewellery Item';
                $p_wt = floatval($row['gross_wt'] > 0 ? $row['gross_wt'] : ($row['net_wt'] > 0 ? $row['net_wt'] : ($row['unit'] === 'g' ? $row['quantity'] : 0)));
                $p_qty = floatval($row['pcs'] > 0 ? $row['pcs'] : ($row['quantity'] > 0 ? $row['quantity'] : 1));
                $p_rate = floatval($row['price']);
                $p_tot = floatval($row['total']);
                $p_huid = !empty($row['huid_code']) ? $row['huid_code'] : ($row['serial_no'] ?? '');
                
                $cat = '';
                if (!empty($row['product_id'])) {
                    $p_chk = @mysqli_query($conn, "SELECT category FROM products WHERE id = " . intval($row['product_id']));
                    if ($p_chk && $pr = mysqli_fetch_assoc($p_chk)) $cat = $pr['category'] ?? '';
                }

                log_product_activity($conn, [
                    'activity_type' => 'billed',
                    'product_id' => $row['product_id'],
                    'product_name' => $p_name,
                    'category' => $cat,
                    'serial_no' => $row['serial_no'] ?? '',
                    'huid_code' => $p_huid,
                    'quantity' => $p_qty,
                    'unit' => $row['unit'] ?? 'pcs',
                    'weight' => $p_wt,
                    'rate' => $p_rate,
                    'total_amount' => $p_tot,
                    'invoice_no' => $row['invoice_no'],
                    'customer_name' => $row['customer_name'],
                    'customer_mobile' => $row['customer_mobile'],
                    'details' => "Billed in Invoice/CashMemo #" . $row['invoice_no'] . " to " . ($row['customer_name'] ?: 'Customer'),
                    'performed_by' => 'Billing System',
                    'created_at' => $row['inv_date']
                ]);
            }
        }

        // 2. Initial sync of existing inventory products
        $p_res = @mysqli_query($conn, "SELECT p.* FROM products p ORDER BY p.id ASC");
        if ($p_res) {
            while ($p = mysqli_fetch_assoc($p_res)) {
                $p_name = $p['item_name'] ?: $p['name'];
                $p_wt = floatval($p['weight'] ?? 0);
                $p_qty = floatval($p['quantity'] ?? 0);
                $p_rate = floatval($p['price'] ?? 0);
                $p_date = !empty($p['created_at']) ? $p['created_at'] : date('Y-m-d H:i:s');

                log_product_activity($conn, [
                    'activity_type' => 'added',
                    'product_id' => $p['id'],
                    'product_name' => $p_name,
                    'category' => $p['category'] ?? '',
                    'serial_no' => $p['serial_no'] ?? '',
                    'huid_code' => $p['huid_code'] ?? '',
                    'quantity' => $p_qty,
                    'unit' => $p['unit'] ?? 'pcs',
                    'weight' => $p_wt,
                    'rate' => $p_rate,
                    'total_amount' => round($p_rate * ($p['unit'] === 'g' ? $p_wt : $p_qty), 2),
                    'details' => "Initial Stock Added: " . ($p_wt > 0 ? "{$p_wt}g" : "{$p_qty} {$p['unit']}"),
                    'performed_by' => 'Inventory System',
                    'created_at' => $p_date
                ]);
            }
        }
    }
}

