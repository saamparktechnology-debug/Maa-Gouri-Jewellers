<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';
require_once 'includes/activity_logger.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Read and clear flash messages for PRG pattern
$success = $_SESSION['flash_success'] ?? null;
$error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Ensure helper columns exist in products table
function ensure_product_columns($conn) {
    static $checked = false;
    if ($checked) return;
    $cols = [
        'huid_code' => "VARCHAR(100) DEFAULT NULL",
        'created_at' => "DATETIME DEFAULT NULL",
        'unit' => "VARCHAR(20) DEFAULT 'pcs'",
        'diamond_cent' => "VARCHAR(50) DEFAULT ''",
        'metal_kt' => "VARCHAR(50) DEFAULT ''",
        'metal_weight' => "VARCHAR(50) DEFAULT ''",
        'status' => "VARCHAR(30) DEFAULT 'In Stock'"
    ];
    foreach ($cols as $col => $def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE '$col'");
        if ($chk && mysqli_num_rows($chk) == 0) {
            @mysqli_query($conn, "ALTER TABLE products ADD COLUMN $col $def");
        }
    }
    $checked = true;
}

// Drop unique index on serial_no if exists to allow multiple empty entries
$idx_chk = mysqli_query($conn, "SHOW INDEX FROM products WHERE Key_name = 'serial_no'");
if ($idx_chk && mysqli_num_rows($idx_chk) > 0) {
    @mysqli_query($conn, "ALTER TABLE products DROP INDEX serial_no");
}

// Handle add/edit/delete/merge product
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    ensure_product_columns($conn);
    $redirect_url = "stock.php" . (!empty($_GET['search']) ? "?search=" . urlencode($_GET['search']) : "");

    // --- Action: Add Product ---
    if(isset($_POST['add_product'])) {
        $serial_no = mysqli_real_escape_string($conn, trim($_POST['serial_no'] ?? ''));
        $name = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
        $item_name_raw = $_POST['item_name'] ?? '';
        if($item_name_raw === 'Other' && !empty($_POST['item_name_custom'])) {
            $item_name_raw = $_POST['item_name_custom'];
        }
        $item_name = mysqli_real_escape_string($conn, trim($item_name_raw));
        $category = mysqli_real_escape_string($conn, trim($_POST['category'] ?? ''));
        $weight = mysqli_real_escape_string($conn, trim($_POST['weight'] ?? '0'));
        $huid_code = mysqli_real_escape_string($conn, trim($_POST['huid_code'] ?? ''));
        $price = floatval($_POST['price'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = mysqli_real_escape_string($conn, trim($_POST['unit'] ?? 'pcs'));

        $diamond_cent = ($category === 'Diamond') ? mysqli_real_escape_string($conn, trim($_POST['diamond_cent'] ?? '')) : '';
        $metal_kt     = ($category === 'Diamond') ? mysqli_real_escape_string($conn, trim($_POST['metal_kt'] ?? '')) : '';
        $metal_weight = ($category === 'Diamond') ? mysqli_real_escape_string($conn, trim($_POST['metal_weight'] ?? '')) : '';

        if ($category === 'Diamond' && (empty($weight) || $weight === '0')) {
            $weight = $metal_weight;
        }

        // 1. Uniqueness check for Serial Number (if provided)
        if (!empty($serial_no)) {
            $chk_s = mysqli_query($conn, "SELECT id, name, item_name FROM products WHERE serial_no = '$serial_no' LIMIT 1");
            if ($chk_s && $s_row = mysqli_fetch_assoc($chk_s)) {
                $p_title = htmlspecialchars($s_row['item_name'] ?: ($s_row['name'] ?: 'Item'));
                $_SESSION['flash_error'] = " Duplicate Serial Number! Product '#{$s_row['id']} - {$p_title}' already exists with Serial No '{$serial_no}'. Each Serial Number must be unique.";
                header("Location: " . $redirect_url);
                exit();
            }
        }

        // 2. Uniqueness check for HUID Code (if provided)
        if (!empty($huid_code)) {
            $chk_h = mysqli_query($conn, "SELECT id, name, item_name FROM products WHERE huid_code = '$huid_code' LIMIT 1");
            if ($chk_h && $h_row = mysqli_fetch_assoc($chk_h)) {
                $p_title = htmlspecialchars($h_row['item_name'] ?: ($h_row['name'] ?: 'Item'));
                $_SESSION['flash_error'] = " Duplicate HUID Code! Product '#{$h_row['id']} - {$p_title}' already exists with HUID '{$huid_code}'. Each HUID must be unique.";
                header("Location: " . $redirect_url);
                exit();
            }
        }

        // 3. Smart Basket / Pool Stock Auto-Merge:
        // If neither Serial Number nor HUID is given, this is a bulk/pool item.
        // Check if an existing basket pool item already exists for this Category and Item Type.
        if (empty($serial_no) && empty($huid_code) && !empty($category) && !empty($item_name)) {
            $pool_chk = mysqli_query($conn, "SELECT id, name, item_name, category, quantity, weight, unit, price FROM products WHERE (serial_no IS NULL OR TRIM(serial_no) = '') AND (huid_code IS NULL OR TRIM(huid_code) = '') AND category = '$category' AND item_name = '$item_name' LIMIT 1");
            if ($pool_chk && $pool_row = mysqli_fetch_assoc($pool_chk)) {
                $pool_id  = intval($pool_row['id']);
                $prev_qty = floatval($pool_row['quantity']);
                $prev_wt  = floatval($pool_row['weight']);
                $db_unit  = strtolower(trim($pool_row['unit'] ?? 'pcs'));
                $in_unit  = strtolower(trim($unit));

                // Unit conversion (pair vs pcs)
                if ($db_unit === $in_unit) {
                    $added_qty = $quantity;
                } elseif ($db_unit === 'pcs' && $in_unit === 'pair') {
                    $added_qty = $quantity * 2;
                } elseif ($db_unit === 'pair' && $in_unit === 'pcs') {
                    $added_qty = $quantity / 2;
                } else {
                    $added_qty = $quantity;
                }

                $final_qty = $prev_qty + $added_qty;
                $final_wt  = round($prev_wt + floatval($weight), 3);
                $upd_price = ($price > 0) ? ", price = '$price'" : "";

                $upd_sql = "UPDATE products SET quantity = '$final_qty', weight = '$final_wt', status = 'In Stock', updated_at = NOW() $upd_price WHERE id = $pool_id";
                if (mysqli_query($conn, $upd_sql)) {
                    log_product_activity($conn, [
                        'activity_type' => 'restocked',
                        'product_id' => $pool_id,
                        'product_name' => $pool_row['item_name'] ?: ($pool_row['name'] ?: $item_name),
                        'category' => $category,
                        'serial_no' => '',
                        'huid_code' => '',
                        'quantity' => $added_qty,
                        'unit' => $db_unit,
                        'weight' => floatval($weight),
                        'rate' => $price > 0 ? $price : floatval($pool_row['price']),
                        'total_amount' => round(($price > 0 ? $price : floatval($pool_row['price'])) * ($db_unit === 'g' ? floatval($weight) : $added_qty), 2),
                        'details' => "Auto-Restocked into existing Basket Stock: +{$quantity} {$unit}" . ($weight > 0 ? " (+{$weight}g)" : "") . " (New Total: {$final_qty} {$db_unit}, {$final_wt}g)",
                        'performed_by' => $_SESSION['user_name'] ?? 'Admin'
                    ]);
                    $_SESSION['flash_success'] = " Auto-Merged with existing basket stock for '{$category} - {$item_name}'! Added: +{$quantity} {$unit}" . ($weight > 0 ? ", +{$weight}g" : "") . ". Total now: {$final_qty} {$db_unit}, {$final_wt}g (Item #{$pool_id}).";
                } else {
                    $_SESSION['flash_error'] = "Error updating basket stock: " . mysqli_error($conn);
                }
                header("Location: " . $redirect_url);
                exit();
            }
        }

        // 4. Normal Insert for New Products
        $query = "INSERT INTO products (serial_no, name, item_name, category, weight, price, quantity, unit, huid_code, diamond_cent, metal_kt, metal_weight, status, created_at, updated_at) VALUES ('$serial_no', '$name', '$item_name', '$category', '$weight', '$price', '$quantity', '$unit', '$huid_code', '$diamond_cent', '$metal_kt', '$metal_weight', 'In Stock', NOW(), NOW())";
        if(mysqli_query($conn, $query)) {
            $new_pid = mysqli_insert_id($conn);
            log_product_activity($conn, [
                'activity_type' => 'added',
                'product_id' => $new_pid,
                'product_name' => $item_name ?: $name,
                'category' => $category,
                'serial_no' => $serial_no,
                'huid_code' => $huid_code,
                'quantity' => floatval($quantity),
                'unit' => $unit,
                'weight' => floatval($weight),
                'rate' => floatval($price),
                'total_amount' => round(floatval($price) * ($unit === 'g' ? floatval($weight) : floatval($quantity)), 2),
                'details' => "Product added to stock: " . ($weight > 0 ? "{$weight}g" : "{$quantity} {$unit}"),
                'performed_by' => $_SESSION['user_name'] ?? 'Admin'
            ]);
            $_SESSION['flash_success'] = " Product added successfully! (ID: #$new_pid)";
        } else {
            $_SESSION['flash_error'] = "Error adding product: " . mysqli_error($conn);
        }
        header("Location: " . $redirect_url);
        exit();
    }
    // --- Action: Quick Restock Quantity ---
    elseif(isset($_POST['update_quantity'])) {
        $id = intval($_POST['product_id']);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $weight = floatval($_POST['weight'] ?? 0);
        $input_unit = strtolower(trim($_POST['unit'] ?? 'pcs'));

        $p_res = mysqli_query($conn, "SELECT quantity, unit, weight, name, item_name, category, price, serial_no, huid_code FROM products WHERE id = $id");
        if ($p_res && $p_row = mysqli_fetch_assoc($p_res)) {
            $db_qty = floatval($p_row['quantity']);
            $db_unit = strtolower(trim($p_row['unit'] ?? 'pcs'));
            $db_weight = floatval($p_row['weight'] ?? 0);

            // Conversion logic: 1 pair = 2 pcs
            if ($db_unit === $input_unit) {
                $added_qty = $quantity;
            } elseif ($db_unit === 'pcs' && $input_unit === 'pair') {
                $added_qty = $quantity * 2;
            } elseif ($db_unit === 'pair' && $input_unit === 'pcs') {
                $added_qty = $quantity / 2;
            } else {
                $added_qty = $quantity;
            }

            $final_qty = $db_qty + $added_qty;
            $final_weight = round($db_weight + $weight, 3);

            $sql = "UPDATE products SET quantity = '$final_qty', weight = '$final_weight', status = 'In Stock', updated_at = NOW() WHERE id = $id";
            if(mysqli_query($conn, $sql)) {
                log_product_activity($conn, [
                    'activity_type' => 'restocked',
                    'product_id' => $id,
                    'product_name' => $p_row['item_name'] ?: $p_row['name'],
                    'category' => $p_row['category'] ?? '',
                    'serial_no' => $p_row['serial_no'] ?? '',
                    'huid_code' => $p_row['huid_code'] ?? '',
                    'quantity' => $added_qty,
                    'unit' => $db_unit,
                    'weight' => $weight,
                    'rate' => floatval($p_row['price'] ?? 0),
                    'details' => "Stock Updated: +{$quantity} {$input_unit}" . ($weight > 0 ? " (+{$weight}g)" : "") . " (Total Now: {$final_qty} {$db_unit}, {$final_weight}g)",
                    'performed_by' => $_SESSION['user_name'] ?? 'Admin'
                ]);
                $_SESSION['flash_success'] = " Stock updated successfully! Total now: {$final_qty} {$db_unit}, {$final_weight}g";
            } else {
                $_SESSION['flash_error'] = "Error updating stock: " . mysqli_error($conn);
            }
        } else {
            $_SESSION['flash_error'] = "Product not found.";
        }
        header("Location: " . $redirect_url);
        exit();
    }
    // --- Action: Update Product Details / Restock Mode ---
    elseif(isset($_POST['update_product'])) {
        $id = intval($_POST['product_id']);
        $edit_mode = $_POST['edit_mode'] ?? 'add';
        $serial_no = mysqli_real_escape_string($conn, trim($_POST['serial_no'] ?? ''));
        $name = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
        $item_name_raw2 = $_POST['item_name'] ?? '';
        if($item_name_raw2 === 'Other' && !empty($_POST['item_name_custom'])) { $item_name_raw2 = $_POST['item_name_custom']; }
        $item_name = mysqli_real_escape_string($conn, trim($item_name_raw2));
        $category = mysqli_real_escape_string($conn, trim($_POST['category']));
        $huid_code = mysqli_real_escape_string($conn, trim($_POST['huid_code'] ?? ''));
        $price = floatval($_POST['price'] ?? 0);
        $unit = mysqli_real_escape_string($conn, $_POST['unit'] ?? 'pcs');
        $diamond_cent = ($category === 'Diamond') ? mysqli_real_escape_string($conn, trim($_POST['diamond_cent'] ?? '')) : '';
        $metal_kt     = ($category === 'Diamond') ? mysqli_real_escape_string($conn, trim($_POST['metal_kt'] ?? '')) : '';
        $metal_weight = ($category === 'Diamond') ? mysqli_real_escape_string($conn, trim($_POST['metal_weight'] ?? '')) : '';

        // Validate unique serial_no if changed
        if (!empty($serial_no)) {
            $chk_s = mysqli_query($conn, "SELECT id FROM products WHERE serial_no = '$serial_no' AND id != $id LIMIT 1");
            if ($chk_s && mysqli_num_rows($chk_s) > 0) {
                $_SESSION['flash_error'] = " Serial Number '$serial_no' is already used by another product (ID: #" . mysqli_fetch_assoc($chk_s)['id'] . ").";
                header("Location: " . $redirect_url);
                exit();
            }
        }

        // Validate unique huid_code if changed
        if (!empty($huid_code)) {
            $chk_h = mysqli_query($conn, "SELECT id FROM products WHERE huid_code = '$huid_code' AND id != $id LIMIT 1");
            if ($chk_h && mysqli_num_rows($chk_h) > 0) {
                $_SESSION['flash_error'] = " HUID Code '$huid_code' is already used by another product (ID: #" . mysqli_fetch_assoc($chk_h)['id'] . ").";
                header("Location: " . $redirect_url);
                exit();
            }
        }

        // Fetch current product state
        $p_res = mysqli_query($conn, "SELECT weight, quantity, unit, name, item_name, category, metal_weight FROM products WHERE id = $id LIMIT 1");
        $curr_prod = $p_res ? mysqli_fetch_assoc($p_res) : null;
        $prev_w = floatval($curr_prod['weight'] ?? 0);
        $prev_q = floatval($curr_prod['quantity'] ?? 0);

        if ($edit_mode === 'add') {
            $add_w = floatval($_POST['add_weight'] ?? 0);
            $add_q = floatval($_POST['add_quantity'] ?? 0);
            $weight = $prev_w + $add_w;
            $quantity = $prev_q + $add_q;

            if ($category === 'Diamond' && !empty($metal_weight)) {
                $metal_weight = floatval($curr_prod['metal_weight'] ?? 0) + $add_w;
            }

            $query = "UPDATE products SET serial_no='$serial_no', name='$name', item_name='$item_name', category='$category', weight='$weight', price='$price', quantity='$quantity', unit='$unit', huid_code='$huid_code', diamond_cent='$diamond_cent', metal_kt='$metal_kt', metal_weight='$metal_weight', status='In Stock', updated_at = NOW() WHERE id=$id";
            if(mysqli_query($conn, $query)) {
                log_product_activity($conn, [
                    'activity_type' => 'restocked',
                    'product_id' => $id,
                    'product_name' => $item_name ?: $name,
                    'category' => $category,
                    'serial_no' => $serial_no,
                    'huid_code' => $huid_code,
                    'quantity' => $add_q,
                    'unit' => $unit,
                    'weight' => $add_w,
                    'rate' => $price,
                    'details' => "Restocked stock (+{$add_w}g, +{$add_q} {$unit}) — Previous: {$prev_w}g / {$prev_q}, New Total: {$weight}g / {$quantity} {$unit}",
                    'performed_by' => $_SESSION['user_name'] ?? 'Admin'
                ]);
                $_SESSION['flash_success'] = " Stock added successfully! Added: +{$add_w}g, +{$add_q} {$unit}. New Total: {$weight}g / {$quantity} {$unit}";
            } else {
                $_SESSION['flash_error'] = "Error updating product: " . mysqli_error($conn);
            }
        } else {
            // Direct correction mode
            $weight = mysqli_real_escape_string($conn, $_POST['weight'] ?? '0');
            $quantity = floatval($_POST['quantity'] ?? 0);

            if ($category === 'Diamond' && (empty($weight) || $weight === '0')) {
                $weight = $metal_weight;
            }

            $query = "UPDATE products SET serial_no='$serial_no', name='$name', item_name='$item_name', category='$category', weight='$weight', price='$price', quantity='$quantity', unit='$unit', huid_code='$huid_code', diamond_cent='$diamond_cent', metal_kt='$metal_kt', metal_weight='$metal_weight', status = IF($quantity > 0, 'In Stock', 'Out of Stock'), updated_at = NOW() WHERE id=$id";
            if(mysqli_query($conn, $query)) {
                log_product_activity($conn, [
                    'activity_type' => 'edited',
                    'product_id' => $id,
                    'product_name' => $item_name ?: $name,
                    'category' => $category,
                    'serial_no' => $serial_no,
                    'huid_code' => $huid_code,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'weight' => floatval($weight),
                    'rate' => $price,
                    'details' => "Corrected product details (Weight: {$weight}g, Qty: {$quantity} {$unit}, Price: ₹{$price})",
                    'performed_by' => $_SESSION['user_name'] ?? 'Admin'
                ]);
                $_SESSION['flash_success'] = " Product details corrected successfully! ";
            } else {
                $_SESSION['flash_error'] = "Error updating product: " . mysqli_error($conn);
            }
        }
        header("Location: " . $redirect_url);
        exit();
    }
    // --- Action: Delete Product ---
    elseif(isset($_POST['delete_product'])) {
        $id = intval($_POST['product_id']);
        $del_p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id = $id"));
        if ($del_p) {
            log_product_activity($conn, [
                'activity_type' => 'deleted',
                'product_id' => $id,
                'product_name' => $del_p['item_name'] ?: $del_p['name'],
                'category' => $del_p['category'] ?? '',
                'serial_no' => $del_p['serial_no'] ?? '',
                'huid_code' => $del_p['huid_code'] ?? '',
                'quantity' => floatval($del_p['quantity']),
                'unit' => $del_p['unit'] ?? 'pcs',
                'weight' => floatval($del_p['weight'] ?? 0),
                'rate' => floatval($del_p['price'] ?? 0),
                'details' => "Product deleted from inventory",
                'performed_by' => $_SESSION['user_name'] ?? 'Admin'
            ]);
        }
        // Safe deletion: DO NOT delete invoice_items to preserve customer invoice history
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        if(mysqli_query($conn, "DELETE FROM products WHERE id = $id")) {
            $_SESSION['flash_success'] = " Product deleted successfully from inventory!";
        } else {
            $_SESSION['flash_error'] = "Error deleting product: " . mysqli_error($conn);
        }
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
        header("Location: " . $redirect_url);
        exit();
    }
    // --- Action: Auto-Merge Duplicate Basket Stock ---
    elseif(isset($_POST['merge_duplicate_baskets'])) {
        $dup_q = mysqli_query($conn, "SELECT category, item_name, COUNT(*) as c FROM products WHERE (serial_no IS NULL OR TRIM(serial_no) = '') AND (huid_code IS NULL OR TRIM(huid_code) = '') GROUP BY category, item_name HAVING c > 1");
        $merged_groups = 0;
        $total_rows_cleaned = 0;

        if ($dup_q) {
            while ($dg = mysqli_fetch_assoc($dup_q)) {
                $m_cat = mysqli_real_escape_string($conn, $dg['category']);
                $m_item = mysqli_real_escape_string($conn, $dg['item_name']);

                // Fetch all duplicate rows for this category + item_name
                $all_rows_q = mysqli_query($conn, "SELECT id, quantity, weight, unit, price FROM products WHERE (serial_no IS NULL OR TRIM(serial_no) = '') AND (huid_code IS NULL OR TRIM(huid_code) = '') AND category = '$m_cat' AND item_name = '$m_item' ORDER BY id ASC");
                $primary_row = null;
                $accum_qty = 0;
                $accum_weight = 0;
                $dup_ids = [];

                while ($row = mysqli_fetch_assoc($all_rows_q)) {
                    if (!$primary_row) {
                        $primary_row = $row;
                        $accum_qty += floatval($row['quantity']);
                        $accum_weight += floatval($row['weight']);
                    } else {
                        $dup_ids[] = intval($row['id']);
                        $accum_qty += floatval($row['quantity']);
                        $accum_weight += floatval($row['weight']);
                    }
                }

                if ($primary_row && !empty($dup_ids)) {
                    $pid = intval($primary_row['id']);
                    $accum_weight = round($accum_weight, 3);

                    // Re-link historical invoice_items, stock_transfers, and logs to primary_id
                    foreach ($dup_ids as $did) {
                        @mysqli_query($conn, "UPDATE invoice_items SET product_id = $pid WHERE product_id = $did");
                        @mysqli_query($conn, "UPDATE stock_transfers SET product_id = $pid WHERE product_id = $did");
                        @mysqli_query($conn, "UPDATE product_activity_logs SET product_id = $pid WHERE product_id = $did");
                    }

                    // Delete the redundant duplicate product rows
                    $id_list = implode(',', $dup_ids);
                    @mysqli_query($conn, "DELETE FROM products WHERE id IN ($id_list)");

                    // Update primary row with total consolidated stock
                    mysqli_query($conn, "UPDATE products SET quantity = '$accum_qty', weight = '$accum_weight', status = 'In Stock', updated_at = NOW() WHERE id = $pid");

                    log_product_activity($conn, [
                        'activity_type' => 'restocked',
                        'product_id' => $pid,
                        'product_name' => $m_item,
                        'category' => $m_cat,
                        'serial_no' => '',
                        'huid_code' => '',
                        'quantity' => $accum_qty,
                        'unit' => $primary_row['unit'] ?? 'pcs',
                        'weight' => $accum_weight,
                        'rate' => floatval($primary_row['price'] ?? 0),
                        'details' => "Consolidated & Merged " . count($dup_ids) . " duplicate basket rows into Primary Record #$pid. Total Stock: {$accum_qty} {$primary_row['unit']}, {$accum_weight}g",
                        'performed_by' => $_SESSION['user_name'] ?? 'Admin'
                    ]);

                    $merged_groups++;
                    $total_rows_cleaned += count($dup_ids);
                }
            }
        }

        if ($merged_groups > 0) {
            $_SESSION['flash_success'] = " Successfully consolidated {$merged_groups} product groups! Merged and cleaned {$total_rows_cleaned} duplicate stock rows.";
        } else {
            $_SESSION['flash_success'] = " No duplicate basket stock records found. Inventory is clean!";
        }
        header("Location: " . $redirect_url);
        exit();
    }
}

$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
if(!empty($search)) {
    $products = mysqli_query($conn, "SELECT * FROM products WHERE serial_no LIKE '%$search%' OR name LIKE '%$search%' OR item_name LIKE '%$search%' OR category LIKE '%$search%' OR huid_code LIKE '%$search%' ORDER BY id");
} else {
    $products = mysqli_query($conn, "SELECT * FROM products ORDER BY id");
}
$low_stock = mysqli_query($conn, "SELECT * FROM products WHERE quantity < 5 ORDER BY quantity ASC");
$dup_chk_res = mysqli_query($conn, "SELECT category, item_name, COUNT(*) as c FROM products WHERE (serial_no IS NULL OR TRIM(serial_no) = '') AND (huid_code IS NULL OR TRIM(huid_code) = '') AND (item_name IS NOT NULL AND TRIM(item_name) != '') GROUP BY category, item_name HAVING c > 1");
$duplicate_basket_count = $dup_chk_res ? mysqli_num_rows($dup_chk_res) : 0;
$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png','radhey shyam logo.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="author" content="MANU GUPTA">
    <title>Live Stock - MAA GOURI JEWELLERS</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Poppins', sans-serif; font-weight: 700; }

        /* ========== SIDEBAR (identical to index.php) ========== */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
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
            width: 44px; height: 44px;
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
            right: 0; top: 50%;
            transform: translateY(-50%);
            width: 4px; height: 60%;
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

        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }

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

        .sidebar-logout:hover { background: #ef4444; color: #fff; }

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
            margin-left: 240px;
            min-height: 100vh;
            transition: margin-left 0.35s ease;
        }

        /* ========== TOP NAVBAR ========== */
        nav.nav-gold { background: linear-gradient(135deg, #011921, #03373b) !important; border-bottom: 2.5px solid #ffd700; box-shadow: 0 0 12px rgba(255, 215, 0, 0.5) !important;
        }

        /* ========== BURGER MENU ========== */
        .burger-menu {
            width: 28px; height: 20px;
            position: relative;
            cursor: pointer;
        }

        .burger-menu span {
            display: block;
            position: absolute;
            height: 3px; width: 100%;
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
            nav.nav-gold { margin-left: 0 !important; }
        }

        @media (min-width: 769px) {
            .mobile-burger { display: none !important; }
        }

        /* ========== PAGE BODY ========== */
        body { background: #F5F5F5; margin: 0; padding: 0; }

        /* ========== STOCK PAGE STYLES ========== */

        /* Jewel Card */
        .jewel-card {
            background: #fff;
            border: 1px solid rgba(181,115,14,0.2);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(181,115,14,0.08);
            transition: all 0.3s ease;
        }

        .jewel-card:hover {
            border-color: #d68b16;
            box-shadow: 0 8px 30px rgba(181,115,14,0.15);
        }

        /* Sticky Summary Bar */
        .sticky-stock-summary {
            position: sticky;
            bottom: 0;
            background: linear-gradient(135deg, #1a0a00, #4a2000);
            border-top: 2px solid #d68b16;
            padding: 6px 10px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            border-radius: 0 0 20px 20px;
            z-index: 100;
            color: #fff;
            box-shadow: 0 -10px 30px rgba(0,0,0,0.15);
            gap: 4px;
            margin-top: auto;
            margin-left: -20px;
            margin-right: -20px;
            margin-bottom: -20px;
            box-sizing: border-box;
        }
        .sticky-stock-summary .summary-item {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 3px;
            font-size: 9.5px;
            background: rgba(255, 255, 255, 0.08);
            padding: 3px 6px;
            border-radius: 6px;
            border: 1px solid rgba(255, 215, 0, 0.25);
            transition: all 0.2s ease;
            white-space: nowrap;
            flex: 1 1 0px;
            min-width: 0;
            box-sizing: border-box;
            cursor: pointer;
        }
        .sticky-stock-summary .summary-item:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: #ffd700;
        }
        .sticky-stock-summary .summary-item i {
            color: #ffd700;
            font-size: 10px;
            flex-shrink: 0;
        }
        .sticky-stock-summary .summary-item span {
            font-size: 9.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sticky-stock-summary .summary-item span strong {
            color: #ffd700;
            font-size: 10px;
            font-weight: 700;
        }
        .summary-dropdown-btn {
            background: rgba(255, 215, 0, 0.2);
            color: #ffd700;
            border: 1px solid rgba(255, 215, 0, 0.4);
            border-radius: 4px;
            padding: 1px 4px;
            font-size: 8px;
            cursor: pointer;
            margin-left: 2px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            flex-shrink: 0;
        }
        .summary-dropdown-btn:hover {
            background: #ffd700;
            color: #1a0a00;
        }
        .summary-dropdown-menu {
            display: none;
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-bottom: 10px;
            background: #011921;
            border: 1.5px solid #d68b16;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.8);
            padding: 12px;
            min-width: 220px;
            max-width: 280px;
            z-index: 9999;
            color: #fff;
            font-size: 12px;
            pointer-events: auto;
        }
        .sticky-stock-summary .summary-item:first-child .summary-dropdown-menu {
            left: 0;
            transform: none;
        }
        .sticky-stock-summary .summary-item:last-child .summary-dropdown-menu {
            left: auto;
            right: 0;
            transform: none;
        }
        .summary-dropdown-menu.show {
            display: block !important;
            animation: fadeInDropdown 0.2s ease-out;
        }
        @keyframes fadeInDropdown {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .summary-dropdown-menu h4 {
            color: #ffd700;
            font-weight: 700;
            font-size: 12px;
            border-bottom: 1px solid rgba(214,139,22,0.3);
            padding-bottom: 6px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .summary-dropdown-menu .cat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px dashed rgba(255,255,255,0.1);
        }
        .summary-dropdown-menu .cat-row:last-child {
            border-bottom: none;
        }
        .summary-dropdown-menu .cat-name {
            color: #e2e8f0;
            font-weight: 500;
        }
        .summary-dropdown-menu .cat-val {
            color: #ffd700;
            font-weight: 700;
        }

        /* Form Inputs */
        .jewel-input {
            background: #fdf6e3;
            border: 1px solid rgba(181,115,14,0.3);
            color: #4a3000;
            border-radius: 10px;
            transition: all 0.3s ease;
            padding: 8px 12px;
            width: 100%;
        }

        .jewel-input:focus {
            border-color: #d68b16;
            box-shadow: 0 0 0 3px rgba(214,139,22,0.15);
            outline: none;
        }

        .jewel-input::placeholder { color: rgba(122,78,10,0.4); }
        select.jewel-input option { background: #fff; color: #4a3000; }
        select.jewel-input optgroup { background: #fdf6e3; color: #800020; font-weight: bold; font-style: normal; }

        .custom-item-input { display: none; }
        .custom-item-input.show { display: block; }

        label { color: #7a4e0a; font-weight: 500; font-size: 13px; }

        /* Table */
        .jewel-table { width: 100%; border-collapse: collapse; }
        .jewel-table thead th {
            background: linear-gradient(135deg, #7a4e0a, #d68b16);
            color: #fff;
            font-weight: 600;
            padding: 8px 10px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .jewel-table td {
            border-bottom: 1px solid rgba(181,115,14,0.1);
            padding: 6px 10px;
            color: #3a2800;
            font-size: 12px;
        }
        .jewel-table tbody tr:hover { background: #fdf6e3; }

        .serial-number-col {
            background: rgba(181,115,14,0.08);
            font-weight: 600;
            color: #7a4e0a;
            text-align: center;
        }

        /* Status badges */
        .status-low    { background: #FEE2E2; color: #991B1B; }
        .status-medium { background: #FEF3C7; color: #92400E; }
        .status-high   { background: #D1FAE5; color: #065F46; }

        /* Buttons */
        .btn-jewel {
            background: linear-gradient(135deg, #800020, #d68b16);
            border: none;
            border-radius: 50px;
            padding: 10px 22px;
            font-weight: 700;
            color: #fff;
            transition: all 0.3s ease;
            display: inline-block;
            text-decoration: none;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-jewel:hover {
            transform: scale(1.04);
            box-shadow: 0 8px 24px rgba(214,139,22,0.35);
            color: #fff;
        }

        .btn-edit {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #fff;
            border: 1px solid rgba(59,130,246,0.6);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            box-shadow: 0 2px 6px rgba(59,130,246,0.25);
            letter-spacing: 0.2px;
        }
        .btn-edit:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 4px 10px rgba(59,130,246,0.4);
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
        }
        .btn-edit:active { transform: translateY(0); }

        .btn-addstock {
            background: linear-gradient(135deg, #d68b16, #a85a0a);
            color: #fff;
            border: 1px solid rgba(214,139,22,0.6);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            box-shadow: 0 2px 6px rgba(214,139,22,0.25);
            letter-spacing: 0.2px;
        }
        .btn-addstock:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 4px 10px rgba(214,139,22,0.4);
            background: linear-gradient(135deg, #e89917, #d68b16);
        }
        .btn-addstock:active { transform: translateY(0); }

        .btn-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            border: 1px solid rgba(239,68,68,0.6);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            box-shadow: 0 2px 6px rgba(239,68,68,0.25);
            letter-spacing: 0.2px;
        }
        .btn-delete:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 4px 10px rgba(239,68,68,0.4);
            background: linear-gradient(135deg, #f87171, #ef4444);
        }
        .btn-delete:active { transform: translateY(0); }

        .action-btns { display: flex; gap: 8px; justify-content: center; align-items: center; flex-wrap: nowrap; }

        .search-highlight { background: #fffbeb !important; border-left: 3px solid #d68b16; }

        /* Modal */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.flex { display: flex; }

        .modal-content {
            background: linear-gradient(145deg, #fdf6e3, #fff);
            border: 1px solid rgba(181,115,14,0.35);
            border-radius: 20px;
            padding: 28px;
            width: 100%;
            max-width: 460px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }

        .modal-content h3 { color: #800020; margin-bottom: 18px; }
        .modal-content label { color: #7a4e0a; display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; }

        /* Search box */
        .search-wrapper { position: relative; }
        .search-wrapper .search-icon {
            position: absolute;
            left: 10px; top: 50%;
            transform: translateY(-50%);
            color: #d68b16;
            font-size: 13px;
        }
        .search-wrapper input { padding-left: 32px; }

        /* Page heading */
        .page-heading {
            background: linear-gradient(135deg, #fdf6e3, #f5ead0);
            border-bottom: 2px solid rgba(181,115,14,0.2);
            padding: 20px 28px;
        }
        .page-heading h1 { color: #800020; font-size: 1.6rem; }
        .page-heading p { color: #7a4e0a; font-size: 13px; margin-top: 2px; }

        /* Responsive */
        @media (max-width: 640px) {
            .stock-grid { grid-template-columns: 1fr !important; }
            .table-container { overflow-x: auto; }
            .jewel-table { min-width: 680px; }
            .action-btns { flex-wrap: wrap; gap: 6px; }
            .action-btns button { flex: 1; min-width: 70px; padding: 6px 8px; font-size: 10px; }
        }

        /* ========== SPARKLES & LOADING OVERLAY ========== */
        .jewel-sparkle { position: fixed; border-radius: 50%; pointer-events: none; z-index: 0; animation: sparkleFloat linear infinite; }
        @keyframes sparkleFloat { 0% { transform: translateY(100vh) scale(0); opacity: 0; } 10% { opacity: 1; } 90% { opacity: 0.5; } 100% { transform: translateY(-10vh) scale(1); opacity: 0; } }
        @keyframes ornFloat { 0%,100%{transform:rotate(0deg) scale(1);opacity:0.15} 50%{transform:rotate(20deg) scale(1.15);opacity:0.28} }
        @keyframes haloPulse { 0%,100%{opacity:0.3;transform:scale(1)} 50%{opacity:1;transform:scale(1.1)} }
        @keyframes gemGlowPulse { 0%,100%{filter:drop-shadow(0 0 8px #d68b16)} 50%{filter:drop-shadow(0 0 22px #ff9900)} }
        @keyframes titleGold { from{color:#d68b16} to{color:#f5c842} }
        @keyframes barSlide { 0%{transform:translateX(-100%)} 100%{transform:translateX(480%)} }
        @keyframes dotBounce { 0%,100%{opacity:0.3;transform:scale(0.7)} 50%{opacity:1;transform:scale(1.2)} }
        @keyframes starFade { 0%{opacity:0;transform:scale(0)} 50%{opacity:1} 100%{opacity:0;transform:scale(1)} }
        @keyframes ringExpand { 0%{opacity:0.7;transform:scale(0.2)} 100%{opacity:0;transform:scale(2)} }
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
    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>
    <!-- <div style="position:absolute;top:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite;"></div>
    <div style="position:absolute;top:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 1s;"></div>
    <div style="position:absolute;bottom:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 2s;"></div>
    <div style="position:absolute;bottom:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 3s;"></div> -->
    <div id="loaderStars" style="position:absolute;inset:0;pointer-events:none;z-index:2;"></div>
    <div id="loaderRings" style="position:absolute;inset:0;pointer-events:none;z-index:2;display:flex;align-items:center;justify-content:center;"></div>
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
</div>

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

        <a href="index.php">
            <i class="fas fa-home"></i> DASHBOARD
        </a>
        <a href="billing.php">
            <i class="fas fa-receipt"></i> BILLING
        </a>
        <a href="stock.php" class="active">
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

<!-- ========== TOP NAVBAR ========== -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">
            <div class="ml-auto flex items-center gap-4">
                <span class="text-sm font-medium text-white hidden sm:inline">
                    <i class="fas fa-user mr-1"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <!-- Mobile burger -->
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

    <!-- Page Heading -->
    <div class="page-heading">
        <h1 class="gold-font"><i class="fas fa-boxes mr-2"></i> Live Stock Management</h1>
        <p>Manage your jewellery inventory in real-time</p>
    </div>

    <div class="container mx-auto px-4 sm:px-6 py-6">

        <!-- Success/Error Messages -->
        <?php if(isset($success)): ?>
            <div class="bg-green-50 border border-green-400 text-green-800 px-4 py-3 rounded-lg mb-4 text-sm">
                <i class="fas fa-check-circle mr-2 text-green-500"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        <?php if(isset($error)): ?>
            <div class="bg-red-50 border border-red-400 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm">
                <i class="fas fa-exclamation-circle mr-2 text-red-500"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Low Stock Alert -->
        <?php if(mysqli_num_rows($low_stock) > 0): ?>
        <div class="mb-5 p-4 rounded-lg" style="background:#FEF2F2;border-left:4px solid #EF4444;">
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <i class="fas fa-exclamation-triangle text-red-500 text-xl flex-shrink-0"></i>
                <div>
                    <p class="font-bold text-red-700 text-sm"> Low Stock Alert!</p>
                    <p class="text-red-600 text-xs mb-2">Following products have low stock (less than 5 units):</p>
                    <div class="flex flex-wrap gap-2">
                        <?php
                        mysqli_data_seek($low_stock, 0);
                        while($item = mysqli_fetch_assoc($low_stock)): ?>
                            <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold" style="background:#FEE2E2;color:#991B1B;">
                                 <?php echo htmlspecialchars($item['name']); ?>: <?php echo $item['quantity']; ?> left
                            </span>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
 
        <!-- Duplicate Basket Alert & Auto-Consolidate Banner -->
        <?php if(!empty($duplicate_basket_count) && $duplicate_basket_count > 0): ?>
        <div class="mb-5 p-4 rounded-xl bg-amber-50 border border-amber-300 shadow-sm flex flex-wrap items-center justify-between gap-3" style="background:#fffbeb;border:1px solid #fcd34d;">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center text-amber-800 text-lg flex-shrink-0" style="background:#fef3c7;color:#92400e;">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <p class="font-bold text-sm text-amber-950" style="color:#78350f;">
                        <i class="fas fa-info-circle mr-1"></i> Duplicate Basket Stock Detected (<?php echo $duplicate_basket_count; ?> group<?php echo $duplicate_basket_count > 1 ? 's' : ''; ?>)
                    </p>
                    <p class="text-xs text-amber-800" style="color:#92400e;">
                        Some common bulk items (e.g. Silver Ornaments) exist in multiple split rows. You can safely merge them into single consolidated stock items with one click.
                    </p>
                </div>
            </div>
            <form method="POST" onsubmit="return confirm('Safely merge and consolidate all split basket stocks into single records? Quantities, weights, and sales history will be preserved.');">
                <button type="submit" name="merge_duplicate_baskets" class="px-4 py-2 rounded-lg text-xs font-bold text-white shadow transition flex items-center gap-2 cursor-pointer" style="background:linear-gradient(135deg,#b45309,#78350f);">
                    <i class="fas fa-compress-arrows-alt"></i> Merge Duplicate Basket Stocks
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Main Grid -->
        <div class="stock-grid grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">

            <!-- Add Product Form -->
            <div class="h-full">
                <div class="jewel-card p-5 h-full flex flex-col justify-between">
                    <h2 class="text-lg font-bold mb-4 gold-font" style="color:#800020;">
                        <i class="fas fa-plus-circle mr-2" style="color:#d68b16;"></i> Add New Product
                    </h2>
                    <form method="POST" onsubmit="return preventDoubleSubmit(this);">

                        <div class="mb-3">
                            <label> Product Name</label>
                            <input list="productNameList" type="text" id="addProductName" name="name" placeholder="Enter product name or choose Others" class="jewel-input" onchange="onAddProductNameChange()">
                            <datalist id="productNameList">
                                <option value="Others"></option>
                            </datalist>
                        </div>

                        <div class="mb-3">
                            <label> Category *</label>
                            <select name="category" id="addCategorySelect" required class="jewel-input" onchange="updateItemTypes('addCategorySelect','addItemSelect','addCustomItem')">
                                <option value="">-- Select Category --</option>
                                <optgroup label=" Gold">
                                    <option value="Gold 24K">Gold 24K</option>
                                    <option value="Gold 22K">Gold 22K</option>
                                    <option value="Gold 18K">Gold 18K</option>
                                    <option value="Gold 12K">Gold 12K</option>
                                    <option value="Gold 9K">Gold 9K</option>
                                </optgroup>
                                <optgroup label=" Silver">
                                    <option value="Silver">Silver</option>
                                </optgroup>
                                <optgroup label=" Stone">
                                    <option value="Stone">Stone</option>
                                </optgroup>
                                <optgroup label=" Diamond">
                                    <option value="Diamond">Diamond</option>
                                </optgroup>
                                <optgroup label=" Other">
                                    <option value="Other">Other</option>
                                </optgroup>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label> Item Type *</label>
                            <select name="item_name" id="addItemSelect" required class="jewel-input" onchange="toggleCustomItem('addCustomItem', this.value)">
                                <option value="">-- Select Category First --</option>
                            </select>
                            <div class="custom-item-input mt-2" id="addCustomItem">
                                <input type="text" name="item_name_custom" placeholder="Custom item name..." class="jewel-input">
                            </div>
                        </div>

                        <div class="p-2.5 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-900 mb-3 flex items-start gap-2">
                            <i class="fas fa-info-circle text-amber-600 mt-0.5"></i>
                            <div>
                                <strong>Basket / Bulk Pool Stock:</strong> Serial No &amp; HUID are optional. You can pool items with total weight &amp; piece count.
                            </div>
                        </div>

                        <!-- Diamond Specific Fields -->
                        <div id="addDiamondFieldsContainer" class="hidden mb-3 p-3 rounded-lg bg-amber-50 border border-amber-200">
                            <div class="text-xs font-bold text-amber-900 mb-2">💎 Diamond & Gold Details</div>
                            <div class="mb-2">
                                <label class="block text-xs font-semibold text-amber-800 mb-1">Diamond Weight (Ct)</label>
                                <input type="number" step="0.01" name="diamond_cent" id="addDiamondCent" placeholder="e.g. 0.25 (Ct)" class="jewel-input">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-xs font-semibold text-amber-800 mb-1">Gold Purity (KT)</label>
                                    <select name="metal_kt" id="addMetalKt" class="jewel-input">
                                        <option value="24K">24K Gold</option>
                                        <option value="22K">22K Gold</option>
                                        <option value="18K">18K Gold</option>
                                        <option value="12K">12K Gold</option>
                                        <option value="9K">9K Gold</option>
                                        <option value="Platinum">Platinum</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-amber-800 mb-1">Gold Weight (gms)</label>
                                    <input type="number" step="0.001" name="metal_weight" id="addMetalWeight" placeholder="e.g. 3.500" class="jewel-input">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label> Serial Number <span class="text-stone-400 font-normal text-xs">(Optional)</span></label>
                            <input type="text" name="serial_no" placeholder="Leave blank for basket inventory" class="jewel-input">
                        </div>

                        <div class="mb-3">
                            <label> HUID Code <span class="text-stone-400 font-normal text-xs">(Optional)</span></label>
                            <input type="text" name="huid_code" placeholder="Leave blank if not hallmarked yet" class="jewel-input">
                        </div>

                        <div class="mb-3" id="addWeightContainer">
                            <label> Total Weight (grams) *</label>
                            <input type="text" name="weight" placeholder="e.g. 12.500" class="jewel-input" required>
                        </div>

                        <input type="hidden" name="price" value="0">

                        <div class="mb-4 flex gap-2">
                            <div class="flex-1">
                                <label> Quantity *</label>
                                <input type="number" name="quantity" placeholder="e.g. 10" required class="jewel-input">
                            </div>
                            <div class="w-1/3">
                                <label> Unit *</label>
                                <select name="unit" class="jewel-input">
                                    <option value="pcs">Pcs</option>
                                    <option value="pair">Pair</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" name="add_product" class="btn-jewel w-full text-center">
                            <i class="fas fa-save mr-1"></i> Add Product
                        </button>
                    </form>
                </div>
            </div>

            <!-- Products List -->
            <div class="lg:col-span-2 h-full">
                <div class="jewel-card p-5 h-full flex flex-col">
                    <div class="flex flex-wrap justify-between items-center mb-4 gap-3">
                        <h2 class="text-lg font-bold gold-font" style="color:#800020;">
                            <i class="fas fa-list mr-2" style="color:#d68b16;"></i> Current Stock
                        </h2>

                        <!-- Search -->
                        <form method="GET" class="flex flex-wrap gap-2">
                            <div class="search-wrapper">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" name="search" placeholder="Search by HUID, Serial No, Name..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    class="jewel-input" style="padding-left:32px;width:220px;">
                            </div>
                            <button type="submit" class="btn-jewel" style="padding:8px 18px;font-size:12px;">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <?php if(!empty($search)): ?>
                                <a href="stock.php" class="btn-jewel" style="padding:8px 18px;font-size:12px;background:linear-gradient(135deg,#6b7280,#4b5563);">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if(!empty($search) && mysqli_num_rows($products) == 0): ?>
                        <div class="text-center py-4 text-sm" style="color:#7a4e0a;background:#fdf6e3;border-radius:10px;border:1px solid rgba(181,115,14,0.2);">
                            <i class="fas fa-search mr-2" style="color:#d68b16;"></i>
                            No product found matching "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        </div>
                    <?php endif; ?>

                    <div class="table-container overflow-x-auto overflow-y-auto flex-1" style="max-height: 580px; margin-bottom: 0;">
                        <table class="jewel-table">
                            <thead>
                                <tr>
                                    <th class="text-left" style="border-radius:12px 0 0 0;">SL</th>
                                    <th class="text-left">Product Details</th>
                                    <th class="text-left">Item Name</th>
                                    <th class="text-left">Serial No</th>
                                    <th class="text-left">HUID</th>
                                    <th class="text-left">Weight</th>
                                    <th class="text-left">Added / Updated</th>
                                    <th class="text-center">Stock</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center" style="border-radius:0 12px 0 0;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $serial = 1;
                                mysqli_data_seek($products, 0);
                                while($product = mysqli_fetch_assoc($products)):
                                    $qty = $product['quantity'];
                                    $status_class = $qty < 5 ? 'status-low' : ($qty < 20 ? 'status-medium' : 'status-high');
                                    $status_text  = $qty < 5 ? 'Critical' : ($qty < 20 ? 'Low' : 'Good');
                                    $highlight = (!empty($search) && (
                                        stripos($product['serial_no'] ?? '', $search) !== false ||
                                        stripos($product['name'] ?? '', $search) !== false ||
                                        stripos($product['item_name'] ?? '', $search) !== false ||
                                        stripos($product['category'] ?? '', $search) !== false ||
                                        stripos($product['huid_code'] ?? '', $search) !== false
                                    )) ? 'search-highlight' : '';
                                ?>
                                <tr class="<?php echo $highlight; ?>">
                                    <td class="serial-number-col"><?php echo $serial++; ?></td>
                                    <td>
                                        <div class="font-semibold" style="color:#800020;"><?php echo htmlspecialchars($product['name']); ?></div>
                                        <div class="text-xs flex flex-wrap gap-1 items-center mt-0.5" style="color:#7a4e0a;">
                                            <span><?php echo htmlspecialchars($product['category'] ?? ''); ?></span>
                                            <?php if(($product['category'] ?? '') === 'Diamond'): ?>
                                                <?php if(!empty($product['diamond_cent'])): ?>
                                                    <span class="px-1.5 py-0.5 rounded text-xs font-bold" style="background:rgba(214,139,22,0.15);color:#b5730e;border:1px solid rgba(214,139,22,0.3);">
                                                        💎 <?php echo htmlspecialchars($product['diamond_cent']); ?> Ct
                                                    </span>
                                                <?php endif; ?>
                                                <?php if(!empty($product['metal_kt']) || !empty($product['metal_weight'])): ?>
                                                    <span class="px-1.5 py-0.5 rounded text-xs font-medium" style="background:rgba(181,115,14,0.08);color:#7a4e0a;border:1px solid rgba(181,115,14,0.2);">
                                                        🪙 <?php echo htmlspecialchars($product['metal_kt'] ?? ''); ?><?php if(!empty($product['metal_weight'])) echo ' (' . htmlspecialchars($product['metal_weight']) . 'g)'; ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="font-medium text-sm" style="color:#b5730e;"><?php echo htmlspecialchars($product['item_name'] ?? '-'); ?></div>
                                    </td>
                                    <td>
                                        <div class="text-sm font-semibold" style="color:#4b5563;"><?php echo htmlspecialchars($product['serial_no'] ?? '-'); ?></div>
                                    </td>
                                    <td>
                                        <div class="text-sm font-semibold" style="color:#4b5563;"><?php echo htmlspecialchars($product['huid_code'] ?: '-'); ?></div>
                                    </td>
                                    <td class="text-sm">
                                        <?php echo htmlspecialchars($product['weight'] ?? 'N/A'); ?>
                                        <span class="text-xs" style="color:#9ca3af;">g</span>
                                    </td>
                                    <td class="text-xs" style="color:#6b7280; line-height: 1.4;">
                                         <?php if(!empty($product['created_at'])): ?>
                                             <div><span class="font-semibold" style="color:#9ca3af;">Added:</span> <span class="font-medium"><?php echo date('d M Y h:i A', strtotime($product['created_at'])); ?></span></div>
                                         <?php endif; ?>
                                         <?php if(!empty($product['updated_at'])): ?>
                                             <div class="mt-1" style="color:#b5730e;"><span class="font-semibold">Updated:</span> <span class="font-medium"><?php echo date('d M Y h:i A', strtotime($product['updated_at'])); ?></span></div>
                                         <?php endif; ?>
                                         <?php if(empty($product['created_at']) && empty($product['updated_at'])): ?>
                                             <span style="color:#d1d5db;">-</span>
                                         <?php endif; ?>
                                    </td>
                                    <td class="text-center font-bold text-sm" style="color:#800020;">
                                        <?php echo $qty; ?>
                                        <span class="text-xs font-semibold uppercase" style="color:#7a4e0a;display:block;font-size:10px;"><?php echo htmlspecialchars($product['unit'] ?? 'pcs'); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="action-btns flex flex-wrap gap-1 justify-center">
                                            <button onclick="openEditModal(<?php echo $product['id']; ?>,'<?php echo addslashes($product['serial_no']); ?>','<?php echo addslashes($product['name']); ?>','<?php echo addslashes($product['item_name'] ?? ''); ?>','<?php echo $product['category']; ?>','<?php echo addslashes($product['weight'] ?? ''); ?>',<?php echo $product['price']; ?>,<?php echo $product['quantity']; ?>,'<?php echo addslashes($product['huid_code'] ?? ''); ?>','<?php echo addslashes($product['unit'] ?? 'pcs'); ?>','<?php echo addslashes($product['diamond_cent'] ?? ''); ?>','<?php echo addslashes($product['metal_kt'] ?? ''); ?>','<?php echo addslashes($product['metal_weight'] ?? ''); ?>')" class="btn-edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button onclick="openUpdateModal(<?php echo $product['id']; ?>,'<?php echo addslashes($product['name']); ?>','<?php echo addslashes($product['unit'] ?? 'pcs'); ?>')" class="btn-addstock">
                                                <i class="fas fa-plus"></i> Stock
                                            </button>
                                            <button onclick="openDeleteModal(<?php echo $product['id']; ?>,'<?php echo addslashes($product['name']); ?>')" class="btn-delete">
                                                <i class="fas fa-trash"></i> Del
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if(mysqli_num_rows($products) == 0 && empty($search)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-10" style="color:#7a4e0a;">
                                        <i class="fas fa-gem text-3xl mb-3 block" style="color:#d68b16;opacity:0.4;"></i>
                                        No products found. Please add some products.
                                    </td>
                                </tr>
                                 <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Sticky Summary Bar -->
                    <?php
                    $total_unique_items = 0;
                    $total_qty = 0;
                    $total_weight = 0.0;
                    $total_price = 0.0;
                    $cat_breakdown = [];

                    if ($products && mysqli_num_rows($products) > 0) {
                        mysqli_data_seek($products, 0);
                        while($row = mysqli_fetch_assoc($products)) {
                            $total_unique_items++;
                            $q = intval($row['quantity']);
                            $total_qty += $q;
                            $w = floatval($row['weight'] ?? 0);
                            $total_weight += $w;
                            $p = floatval($row['price'] ?? 0);
                            $total_price += $p;

                            $cat = trim($row['category'] ?? 'Other');
                            if (empty($cat)) { $cat = 'Other'; }

                            if (!isset($cat_breakdown[$cat])) {
                                $cat_breakdown[$cat] = [
                                    'unique_items' => 0,
                                    'qty'          => 0,
                                    'weight'       => 0.0,
                                    'price'        => 0.0
                                ];
                            }
                            $cat_breakdown[$cat]['unique_items']++;
                            $cat_breakdown[$cat]['qty'] += $q;
                            $cat_breakdown[$cat]['weight'] += $w;
                            $cat_breakdown[$cat]['price'] += $p;
                        }
                    }
                    ?>
                    <div class="sticky-stock-summary">
                        <!-- Unique Items -->
                        <div class="summary-item">
                            <i class="fas fa-boxes"></i>
                            <span>Unique Items: <strong><?php echo $total_unique_items; ?></strong></span>
                            <button type="button" onclick="toggleStockBreakdown('uniqueDropdown', event)" class="summary-dropdown-btn" title="Category Breakdown">
                                Cat <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="summary-dropdown-menu" id="uniqueDropdown">
                                <h4><span><i class="fas fa-boxes text-yellow-400 mr-1"></i> Unique Items</span> <i class="fas fa-times text-xs opacity-50 cursor-pointer" onclick="toggleStockBreakdown('uniqueDropdown', event)"></i></h4>
                                <?php if (!empty($cat_breakdown)): ?>
                                    <?php foreach ($cat_breakdown as $cname => $cdata): ?>
                                        <div class="cat-row">
                                            <span class="cat-name"><?php echo htmlspecialchars($cname); ?></span>
                                            <span class="cat-val"><?php echo $cdata['unique_items']; ?> items</span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-xs text-gray-400 py-1">No items found</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Total Quantity -->
                        <div class="summary-item">
                            <i class="fas fa-cubes"></i>
                            <span>Total Qty: <strong><?php echo number_format($total_qty); ?> pcs</strong></span>
                            <button type="button" onclick="toggleStockBreakdown('qtyDropdown', event)" class="summary-dropdown-btn" title="Category Breakdown">
                                Cat <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="summary-dropdown-menu" id="qtyDropdown">
                                <h4><span><i class="fas fa-cubes text-yellow-400 mr-1"></i> Quantity</span> <i class="fas fa-times text-xs opacity-50 cursor-pointer" onclick="toggleStockBreakdown('qtyDropdown', event)"></i></h4>
                                <?php if (!empty($cat_breakdown)): ?>
                                    <?php foreach ($cat_breakdown as $cname => $cdata): ?>
                                        <div class="cat-row">
                                            <span class="cat-name"><?php echo htmlspecialchars($cname); ?></span>
                                            <span class="cat-val"><?php echo number_format($cdata['qty']); ?> pcs</span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-xs text-gray-400 py-1">No stock found</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Total Weight -->
                        <div class="summary-item">
                            <i class="fas fa-weight-hanging"></i>
                            <span>Total Weight: <strong><?php echo number_format($total_weight, 3); ?> g</strong></span>
                            <button type="button" onclick="toggleStockBreakdown('weightDropdown', event)" class="summary-dropdown-btn" title="Category Breakdown">
                                Cat <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="summary-dropdown-menu" id="weightDropdown">
                                <h4><span><i class="fas fa-weight-hanging text-yellow-400 mr-1"></i> Weight Breakdown</span> <i class="fas fa-times text-xs opacity-50 cursor-pointer" onclick="toggleStockBreakdown('weightDropdown', event)"></i></h4>
                                <?php if (!empty($cat_breakdown)): ?>
                                    <?php foreach ($cat_breakdown as $cname => $cdata): ?>
                                        <div class="cat-row">
                                            <span class="cat-name"><?php echo htmlspecialchars($cname); ?></span>
                                            <span class="cat-val"><?php echo number_format($cdata['weight'], 3); ?> g</span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-xs text-gray-400 py-1">No weight data</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Estimated Value -->
                        <div class="summary-item">
                            <i class="fas fa-coins"></i>
                            <span>Est. Value: <strong>₹<?php echo number_format($total_price, 2); ?></strong></span>
                            <button type="button" onclick="toggleStockBreakdown('valueDropdown', event)" class="summary-dropdown-btn" title="Category Breakdown">
                                Cat <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="summary-dropdown-menu" id="valueDropdown">
                                <h4><span><i class="fas fa-coins text-yellow-400 mr-1"></i> Value Breakdown</span> <i class="fas fa-times text-xs opacity-50 cursor-pointer" onclick="toggleStockBreakdown('valueDropdown', event)"></i></h4>
                                <?php if (!empty($cat_breakdown)): ?>
                                    <?php foreach ($cat_breakdown as $cname => $cdata): ?>
                                        <div class="cat-row">
                                            <span class="cat-name"><?php echo htmlspecialchars($cname); ?></span>
                                            <span class="cat-val">₹<?php echo number_format($cdata['price'], 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-xs text-gray-400 py-1">No value data</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer style="background:linear-gradient(0deg,#f5e6c8,#fdf6e3);border-top:2px solid #d68b16;padding:20px;margin-top:40px;text-align:center;">
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Design & Developed by <a href="https://saamparktechnology.com/" target="_blank" style="text-decoration:underline;color:#800020;font-weight:700;">Saampark Technology & Research Private Limited</a>
        </p>
    </footer>
</div><!-- end .page-wrapper -->

<!-- ========== MODALS ========== -->

<!-- Edit Product Modal -->
<div id="editModal" class="modal-overlay">
    <div class="modal-content" style="max-width:540px;">
        <div class="flex items-center justify-between mb-3 border-b border-amber-200 pb-2.5">
            <h3 class="text-lg font-bold gold-font flex items-center gap-2">
                <i class="fas fa-edit" style="color:#d68b16;"></i> Edit Product / Stock
            </h3>
            <button type="button" onclick="closeEditModal()" class="text-stone-400 hover:text-stone-700 font-bold text-lg">&times;</button>
        </div>

        <form method="POST" onsubmit="return preventDoubleSubmit(this);">
            <input type="hidden" name="product_id" id="editProductId">
            <input type="hidden" name="edit_mode" id="editModalModeInput" value="add">
            <input type="hidden" id="editPrevWeightHidden" value="0">
            <input type="hidden" id="editPrevQtyHidden" value="0">

            <!-- Mode Selector Tabs -->
            <div class="flex rounded-xl p-1 bg-amber-100/70 border border-amber-300/60 mb-3 gap-1">
                <button type="button" id="tabEditAddStock" onclick="setEditModalMode('add')" class="flex-1 py-1.5 px-2 rounded-lg text-xs font-bold transition-all bg-amber-800 text-white shadow-sm cursor-pointer">
                    <i class="fas fa-plus-circle mr-1"></i> ➕ Add / Restock (+New)
                </button>
                <button type="button" id="tabEditCorrect" onclick="setEditModalMode('correct')" class="flex-1 py-1.5 px-2 rounded-lg text-xs font-bold transition-all text-amber-900 hover:bg-white/60 cursor-pointer">
                    <i class="fas fa-pen-to-square mr-1"></i> ✏️ Correct Mistake (Direct Edit)
                </button>
            </div>

            <!-- Panel 1: Add New Stock (+Weight / +Qty) -->
            <div id="panelEditAddStock" class="mb-3 p-3 rounded-xl bg-amber-50/90 border border-amber-200">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-amber-900">📦 Previous Stock:</span>
                    <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-200/80 text-amber-950" id="editCurrentStockBadge">0.000g | 0 pcs</span>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-2">
                    <div>
                        <label class="block text-xs font-bold text-amber-900 mb-1">➕ New Weight to Add (g)</label>
                        <input type="number" step="0.001" min="0" name="add_weight" id="editAddWeight" placeholder="e.g. 50.000" class="jewel-input text-xs" oninput="calcEditModalPreview()">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-amber-900 mb-1">➕ New Qty to Add</label>
                        <input type="number" step="1" min="0" name="add_quantity" id="editAddQuantity" placeholder="e.g. 5" class="jewel-input text-xs" oninput="calcEditModalPreview()">
                    </div>
                </div>
                <div class="text-xs font-bold text-emerald-800 bg-emerald-50 px-2.5 py-1.5 rounded-lg border border-emerald-200 flex items-center justify-between">
                    <span>✨ Resulting Total Stock:</span>
                    <span id="editPreviewResultValues">0.000g | 0 pcs</span>
                </div>
            </div>

            <!-- Panel 2: Direct Edit / Correct Mistake -->
            <div id="panelEditCorrect" class="hidden mb-3 p-3 rounded-xl bg-rose-50/60 border border-rose-200">
                <div class="text-xs font-bold text-rose-900 mb-2 flex items-center gap-1">
                    <i class="fas fa-exclamation-circle text-rose-600"></i> Correct Mistake in Previous Stock (Direct Override):
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div id="editWeightContainer">
                        <label class="block text-xs font-semibold text-rose-900 mb-1">Correct Total Weight (g)</label>
                        <input type="number" step="0.001" name="weight" id="editProductWeight" class="jewel-input text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-rose-900 mb-1">Correct Total Quantity</label>
                        <input type="number" step="1" name="quantity" id="editProductQuantity" class="jewel-input text-xs">
                    </div>
                </div>
            </div>

            <!-- Common Product Details -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2.5">
                <div>
                    <label class="block text-xs font-semibold mb-1 text-stone-700">Serial Number</label>
                    <input type="text" name="serial_no" id="editProductSerial" placeholder="e.g. SN123" class="jewel-input text-xs">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-stone-700">HUID Code</label>
                    <input type="text" name="huid_code" id="editProductHuid" placeholder="e.g. HUID123456" class="jewel-input text-xs">
                </div>
            </div>

            <div class="mb-2.5">
                <label class="block text-xs font-semibold mb-1 text-stone-700">Product Name</label>
                <input type="text" name="name" id="editProductName" placeholder="Display product name" class="jewel-input text-xs">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2.5">
                <div>
                    <label class="block text-xs font-semibold mb-1 text-stone-700">Category *</label>
                    <select name="category" id="editProductCategory" required class="jewel-input text-xs" onchange="updateItemTypes('editProductCategory','editProductItemName','editCustomItem')">
                        <option value="">-- Select Category --</option>
                        <optgroup label="Gold">
                            <option value="Gold 24K">Gold 24K</option>
                            <option value="Gold 22K">Gold 22K</option>
                            <option value="Gold 18K">Gold 18K</option>
                            <option value="Gold 12K">Gold 12K</option>
                            <option value="Gold 9K">Gold 9K</option>
                        </optgroup>
                        <optgroup label="Silver">
                            <option value="Silver">Silver</option>
                        </optgroup>
                        <optgroup label="Stone">
                            <option value="Stone">Stone</option>
                        </optgroup>
                        <optgroup label="Diamond">
                            <option value="Diamond">Diamond</option>
                        </optgroup>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-stone-700">Item Name *</label>
                    <select name="item_name" id="editProductItemName" required class="jewel-input text-xs" onchange="toggleCustomItem('editCustomItem', this.value)">
                        <option value="">-- Select Item --</option>
                    </select>
                    <div class="custom-item-input mt-1.5" id="editCustomItem">
                        <input type="text" name="item_name_custom" id="editItemCustomInput" placeholder="Custom item name..." class="jewel-input text-xs">
                    </div>
                </div>
            </div>

            <!-- Diamond Specific Fields -->
            <div id="editDiamondFieldsContainer" class="hidden mb-2.5 p-2.5 rounded-lg bg-amber-50 border border-amber-200">
                <div class="text-xs font-bold text-amber-900 mb-1.5">💎 Diamond & Gold Details</div>
                <div class="mb-1.5">
                    <label class="block text-xs font-semibold text-amber-800 mb-1">Diamond Weight (Ct)</label>
                    <input type="number" step="0.01" name="diamond_cent" id="editDiamondCent" placeholder="e.g. 0.25 (Ct)" class="jewel-input text-xs">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-amber-800 mb-1">Gold Purity (KT)</label>
                        <select name="metal_kt" id="editMetalKt" class="jewel-input text-xs">
                            <option value="24K">24K Gold</option>
                            <option value="22K">22K Gold</option>
                            <option value="18K">18K Gold</option>
                            <option value="12K">12K Gold</option>
                            <option value="9K">9K Gold</option>
                            <option value="Platinum">Platinum</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-amber-800 mb-1">Gold Weight (gms)</label>
                        <input type="number" step="0.001" name="metal_weight" id="editMetalWeight" placeholder="e.g. 3.500" class="jewel-input text-xs">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-4">
                <div>
                    <label class="block text-xs font-semibold mb-1 text-stone-700">Unit Price / Rate (₹) *</label>
                    <input type="number" step="0.01" name="price" id="editProductPrice" required class="jewel-input text-xs">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-stone-700">Stock Unit *</label>
                    <select name="unit" id="editProductUnit" class="jewel-input text-xs" onchange="calcEditModalPreview()">
                        <option value="pcs">Pcs</option>
                        <option value="pair">Pair</option>
                    </select>
                </div>
            </div>

            <div class="flex gap-2.5">
                <button type="submit" name="update_product" class="btn-jewel flex-1 text-center py-2 text-xs font-bold shadow-md cursor-pointer">
                    <i class="fas fa-check-circle mr-1"></i> Save Changes
                </button>
                <button type="button" onclick="closeEditModal()" class="py-2 px-4 rounded-xl text-xs font-semibold border border-stone-300 bg-stone-100 hover:bg-stone-200 text-stone-700 cursor-pointer">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Stock Modal -->
<div id="updateModal" class="modal-overlay">
    <div class="modal-content">
        <h3 class="text-xl font-bold gold-font"><i class="fas fa-plus-circle mr-2" style="color:#d68b16;"></i> Add Stock</h3>
        <form method="POST" onsubmit="return preventDoubleSubmit(this);">
            <input type="hidden" name="product_id" id="updateProductId">
            <p class="mb-3 text-sm" style="color:#7a4e0a;">Product: <strong id="updateProductName" style="color:#800020;"></strong></p>
            <div class="mb-3 flex gap-2">
                <div class="flex-1">
                    <label> Add Qty/Pair</label>
                    <input type="number" name="quantity" required class="jewel-input" placeholder="Enter quantity to add">
                </div>
                <div class="w-1/3">
                    <label> Unit</label>
                    <select name="unit" id="updateProductUnit" class="jewel-input">
                        <option value="pcs">Pcs</option>
                        <option value="pair">Pair</option>
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label> Add Weight (grams)</label>
                <input type="number" step="0.001" name="weight" class="jewel-input" placeholder="Enter weight to add">
            </div>
            <div class="flex gap-3">
                <button type="submit" name="update_quantity" class="btn-jewel flex-1 text-center"> Add Stock</button>
                <button type="button" onclick="closeUpdateModal()" class="flex-1 py-2 rounded-lg text-sm font-semibold" style="background:#e5e7eb;color:#374151;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-content text-center">
        <i class="fas fa-exclamation-triangle text-5xl mb-4" style="color:#ef4444;"></i>
        <h3 class="text-xl font-bold gold-font"> Delete Product</h3>
        <p class="my-3 text-sm" style="color:#4b5563;">Are you sure you want to delete <strong id="deleteProductName" style="color:#800020;"></strong>?</p>
        <p class="mb-4 text-xs" style="color:#d97706;"> Related invoice records may also be removed.</p>
        <form method="POST" onsubmit="return preventDoubleSubmit(this);">
            <input type="hidden" name="product_id" id="deleteProductId">
            <div class="flex gap-3">
                <button type="submit" name="delete_product" class="flex-1 py-2 rounded-lg text-sm font-semibold text-white" style="background:linear-gradient(135deg,#ef4444,#dc2626);"> Yes, Delete</button>
                <button type="button" onclick="closeDeleteModal()" class="flex-1 py-2 rounded-lg text-sm font-semibold" style="background:#e5e7eb;color:#374151;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== SCRIPTS ========== -->
<script>
    /* ---------- Prevent Double Form Submission ---------- */
    function preventDoubleSubmit(form) {
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            if (btn.dataset.submitted === 'true') {
                return false;
            }
            btn.dataset.submitted = 'true';
            if (btn.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = btn.name;
                hidden.value = btn.value || '1';
                form.appendChild(hidden);
            }
            btn.disabled = true;
            btn.style.opacity = '0.7';
            btn.style.cursor = 'not-allowed';
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Processing...';
        }
        return true;
    }

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

    /* ---------- Item types ---------- */
    const itemsByCategory = {
        'Gold 24K': ['Gold Ornaments','Mixed Gold Stock','Coin','Bar','Guinea','Necklace','Chur','Bala','Chain','Tops','Single Loket','Double Loket','Churi','Jhuladul','Jhumka','Ladies Ring','Gold Choker','Gents Ring','Gents Breslet','Ladies Breslet','Tika','Takti','Mantasa','Pearl Choker','Bauti Chur','Soket Bauti','Breslet Noya','Stell Noya','Baby Ring','Bali','Pitaring','Baby Breslet','Pearl Sitahar','Nose Pin','Other'],
        'Gold 22K': ['Gold Ornaments','Mixed Gold Stock','Necklace','Chur','Bala','Chain','Tops','Single Loket','Double Loket','Churi','Jhuladul','Jhumka','Ladies Ring','Gold Choker','Gents Ring','Gents Breslet','Ladies Breslet','Tika','Takti','Mantasa','Pearl Choker','Bauti Chur','Soket Bauti','Breslet Noya','Stell Noya','Baby Ring','Bali','Pitaring','Baby Breslet','Pearl Sitahar','Nose Pin','Other'],
        'Gold 18K': ['Gold Ornaments','Mixed Gold Stock','Necklace','Chur','Bala','Chain','Tops','Single Loket','Double Loket','Churi','Jhuladul','Jhumka','Ladies Ring','Gold Choker','Gents Ring','Gents Breslet','Ladies Breslet','Tika','Takti','Mantasa','Pearl Choker','Bauti Chur','Soket Bauti','Breslet Noya','Stell Noya','Baby Ring','Bali','Pitaring','Baby Breslet','Pearl Sitahar','Nose Pin','Other'],
        'Gold 12K': ['Gold Ornaments','Mixed Gold Stock','Necklace','Chur','Bala','Chain','Tops','Single Loket','Double Loket','Churi','Jhuladul','Jhumka','Ladies Ring','Gold Choker','Gents Ring','Gents Breslet','Ladies Breslet','Tika','Takti','Mantasa','Pearl Choker','Bauti Chur','Soket Bauti','Breslet Noya','Stell Noya','Baby Ring','Bali','Pitaring','Baby Breslet','Pearl Sitahar','Nose Pin','Other'],
        'Gold 9K':  ['Gold Ornaments','Mixed Gold Stock','Necklace','Chur','Bala','Chain','Tops','Single Loket','Double Loket','Churi','Jhuladul','Jhumka','Ladies Ring','Gold Choker','Gents Ring','Gents Breslet','Ladies Breslet','Tika','Takti','Mantasa','Pearl Choker','Bauti Chur','Soket Bauti','Breslet Noya','Stell Noya','Baby Ring','Bali','Pitaring','Baby Breslet','Pearl Sitahar','Nose Pin','Other'],
        'Silver':   ['Silver Ornaments','Payel','Chain','Bala','Ring','Churi','Thali','Bati','Glass','Spoon','Showpiece','B.B.C Silver','Mix Silver','Other'],
        'Stone':    ['Natural Pearl','Gomed','Red Coral','Nila','Panna','Jerkon','Amethist','Cats Eye','Other'],
        'Diamond':  ['Ladies Ring','Gents Ring','Tops','Mangal Sutra','Nose Pin','Necklace','Other'],
        'Other':    ['Shankha','Pala','Mala','Moti Mala','Trasel','Branch Fram','Braslate Pala','Parl Mala','Gala','Reparing','Stamp Charg','Other']
    };

    function updateItemTypes(catSelectId, itemSelectId, customDivId) {
        const cat = document.getElementById(catSelectId).value;
        const itemSel = document.getElementById(itemSelectId);
        const customDiv = document.getElementById(customDivId);

        itemSel.innerHTML = '';
        customDiv.classList.remove('show');
        const inp = customDiv.querySelector('input');
        if(inp) inp.value = '';

        // Toggle Diamond Extra Fields Container & Weight Field Container
        const isAdd = (catSelectId === 'addCategorySelect');
        const diamondContainerId = isAdd ? 'addDiamondFieldsContainer' : 'editDiamondFieldsContainer';
        const weightContainerId  = isAdd ? 'addWeightContainer' : 'editWeightContainer';
        
        const dContainer = document.getElementById(diamondContainerId);
        const wContainer = document.getElementById(weightContainerId);

        if (dContainer) {
            if (cat === 'Diamond') {
                dContainer.classList.remove('hidden');
            } else {
                dContainer.classList.add('hidden');
            }
        }

        if (wContainer) {
            const wInput = wContainer.querySelector('input');
            if (cat === 'Diamond') {
                wContainer.classList.add('hidden');
                if (wInput) wInput.removeAttribute('required');
            } else {
                wContainer.classList.remove('hidden');
                if (wInput) wInput.setAttribute('required', 'required');
            }
        }

        if(!cat || !itemsByCategory[cat]) {
            itemSel.innerHTML = '<option value="">-- Select Category First --</option>';
            const addName = document.getElementById('addProductName');
            if(addName && catSelectId === 'addCategorySelect') addName.value = '';
            return;
        }

        itemSel.innerHTML = '<option value="">-- Select Item Type --</option>';

        // Do NOT auto-fill product name - admin must type it manually

        itemsByCategory[cat].forEach(function(item) {
            const opt = document.createElement('option');
            opt.value = item;
            opt.textContent = item === 'Other' ? ' Other (Custom)' : item;
            itemSel.appendChild(opt);
        });
    }

    function onAddProductNameChange() {
        const name = document.getElementById('addProductName').value.trim().toLowerCase();
        if(name === 'others' || name === 'other') {
            const categorySelect = document.getElementById('addCategorySelect');
            if(categorySelect) {
                categorySelect.value = 'Other';
                updateItemTypes('addCategorySelect','addItemSelect','addCustomItem');
            }
        }
    }

    function toggleCustomItem(divId, value) {
        const div = document.getElementById(divId);
        if(value === 'Other') { div.classList.add('show'); div.querySelector('input').focus(); }
        else { div.classList.remove('show'); div.querySelector('input').value = ''; }
    }

    function setEditModalMode(mode) {
        document.getElementById('editModalModeInput').value = mode;
        const tabAdd = document.getElementById('tabEditAddStock');
        const tabCorrect = document.getElementById('tabEditCorrect');
        const panelAdd = document.getElementById('panelEditAddStock');
        const panelCorrect = document.getElementById('panelEditCorrect');

        if (mode === 'add') {
            tabAdd.style.background = '#800020';
            tabAdd.style.color = '#ffffff';
            tabAdd.style.fontWeight = '700';

            tabCorrect.style.background = 'transparent';
            tabCorrect.style.color = '#7a4e0a';
            tabCorrect.style.fontWeight = '600';

            panelAdd.classList.remove('hidden');
            panelCorrect.classList.add('hidden');
            document.getElementById('editProductWeight').required = false;
            document.getElementById('editProductQuantity').required = false;
        } else {
            tabCorrect.style.background = '#800020';
            tabCorrect.style.color = '#ffffff';
            tabCorrect.style.fontWeight = '700';

            tabAdd.style.background = 'transparent';
            tabAdd.style.color = '#7a4e0a';
            tabAdd.style.fontWeight = '600';

            panelCorrect.classList.remove('hidden');
            panelAdd.classList.add('hidden');
            document.getElementById('editProductWeight').required = true;
            document.getElementById('editProductQuantity').required = true;
        }
    }

    function calcEditModalPreview() {
        const prevW = parseFloat(document.getElementById('editPrevWeightHidden').value) || 0;
        const prevQ = parseFloat(document.getElementById('editPrevQtyHidden').value) || 0;
        const addW = parseFloat(document.getElementById('editAddWeight').value) || 0;
        const addQ = parseFloat(document.getElementById('editAddQuantity').value) || 0;
        const unit = document.getElementById('editProductUnit') ? document.getElementById('editProductUnit').value : 'pcs';

        const newW = (prevW + addW).toFixed(3);
        const newQ = (prevQ + addQ);

        document.getElementById('editPreviewResultValues').textContent = newW + 'g | ' + newQ + ' ' + unit;
    }

    /* ---------- Modals ---------- */
    function openEditModal(id, serial_no, name, item_name, category, weight, price, quantity, huid_code, unit, diamond_cent, metal_kt, metal_weight) {
        document.getElementById('editProductId').value = id;
        document.getElementById('editProductSerial').value = serial_no;
        document.getElementById('editProductName').value = name;
        document.getElementById('editProductWeight').value = weight;
        document.getElementById('editProductPrice').value = price;
        document.getElementById('editProductQuantity').value = quantity;
        document.getElementById('editProductHuid').value = huid_code || '';

        // Store and display previous stock state
        document.getElementById('editPrevWeightHidden').value = weight || '0';
        document.getElementById('editPrevQtyHidden').value = quantity || '0';
        const displayW = (parseFloat(weight) || 0).toFixed(3);
        const displayQ = quantity || 0;
        const displayUnit = (unit === 'pair') ? 'pair' : 'pcs';
        document.getElementById('editCurrentStockBadge').textContent = displayW + 'g | ' + displayQ + ' ' + displayUnit;
        document.getElementById('editAddWeight').value = '';
        document.getElementById('editAddQuantity').value = '';

        if (document.getElementById('editProductUnit')) {
            document.getElementById('editProductUnit').value = (unit === 'pair') ? 'pair' : 'pcs';
        }
        if (document.getElementById('editDiamondCent')) {
            document.getElementById('editDiamondCent').value = diamond_cent || '';
        }
        if (document.getElementById('editMetalKt')) {
            document.getElementById('editMetalKt').value = metal_kt || '18K';
        }
        if (document.getElementById('editMetalWeight')) {
            document.getElementById('editMetalWeight').value = metal_weight || '';
        }

        const catSel = document.getElementById('editProductCategory');
        catSel.value = category;
        updateItemTypes('editProductCategory', 'editProductItemName', 'editCustomItem');

        const itemSel = document.getElementById('editProductItemName');
        let matched = false;
        for(let opt of itemSel.options) { if(opt.value === item_name) { matched = true; break; } }
        if(matched) {
            itemSel.value = item_name;
            document.getElementById('editCustomItem').classList.remove('show');
        } else if(item_name) {
            itemSel.value = 'Other';
            document.getElementById('editCustomItem').classList.add('show');
            document.getElementById('editItemCustomInput').value = item_name;
        }

        calcEditModalPreview();
        setEditModalMode('add');
        document.getElementById('editModal').classList.add('flex');
    }

    function closeEditModal() { document.getElementById('editModal').classList.remove('flex'); }

    function openUpdateModal(id, name, unit) {
        document.getElementById('updateProductId').value = id;
        document.getElementById('updateProductName').innerText = name;
        document.getElementById('updateProductUnit').value = unit;
        document.getElementById('updateModal').classList.add('flex');
    }

    function closeUpdateModal() { document.getElementById('updateModal').classList.remove('flex'); }

    function openDeleteModal(id, name) {
        document.getElementById('deleteProductId').value = id;
        document.getElementById('deleteProductName').innerText = name;
        document.getElementById('deleteModal').classList.add('flex');
    }

    function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('flex'); }

    /* ---------- Stock Summary Category Dropdowns ---------- */
    function toggleStockBreakdown(menuId, event) {
        if(event) {
            if(typeof event.stopPropagation === 'function') event.stopPropagation();
        }
        const target = document.getElementById(menuId);
        if (!target) return;
        const isShown = target.classList.contains('show');
        
        document.querySelectorAll('.summary-dropdown-menu').forEach(m => m.classList.remove('show'));
        
        if (!isShown) {
            target.classList.add('show');
        }
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.summary-item') && !e.target.closest('.summary-dropdown-menu')) {
            document.querySelectorAll('.summary-dropdown-menu').forEach(m => m.classList.remove('show'));
        }
    });
</script>

<style>
@media (max-width: 768px) {
    nav.nav-gold { margin-left: 0 !important; }
}
</style>

</body>
</html>






