<?php
require_once __DIR__ . '/../config/database.php';

// 1. Create a test Basket Product (Gold 22K, 50g, 10 pcs, blank HUID & serial)
$pname = 'Gold Basket Stock ' . rand(100, 999);
$insert_p = mysqli_query($conn, "INSERT INTO products (name, item_name, category, weight, quantity, unit, price, serial_no, huid_code, created_at, updated_at) 
VALUES ('$pname', 'Gold Ornaments', 'Gold 22K', 50.000, 10, 'pcs', 78500, '', '', NOW(), NOW())");
$prod_id = mysqli_insert_id($conn);

echo "Created Basket Product ID: $prod_id ($pname) with 50.000g and 10 pcs\n";

// 2. Simulate Billing 1 Pair (2 pcs), Gross Wt: 10.500g, Net Wt: 10.000g, Hallmark: BIS 916
$gross_wt = 10.500;
$net_wt = 10.000;
$pcs_to_deduct = 2; // 1 Pair = 2 pcs

// Deduct Gross weight and pieces
mysqli_query($conn, "UPDATE products SET weight = GREATEST(0, CAST(weight AS DECIMAL(10,3)) - $gross_wt), quantity = quantity - $pcs_to_deduct, updated_at = NOW() WHERE id = $prod_id");

// Verify product stock after billing
$res = mysqli_query($conn, "SELECT weight, quantity FROM products WHERE id = $prod_id");
$after = mysqli_fetch_assoc($res);
echo "After billing 1 Pair with Gross Wt 10.5g:\n";
echo "Remaining Weight: " . $after['weight'] . " g (Expected: 39.500 g)\n";
echo "Remaining Qty: " . $after['quantity'] . " pcs (Expected: 8 pcs)\n";

if (abs(floatval($after['weight']) - 39.5) < 0.001 && intval($after['quantity']) === 8) {
    echo " SUCCESS: Basket stock deduction verified correctly!\n";
} else {
    echo "❌ FAILURE: Deduction mismatch.\n";
}
