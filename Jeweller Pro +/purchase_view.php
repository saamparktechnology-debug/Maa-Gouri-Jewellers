<?php
session_start();
require_once 'config/database.php';
require_once 'config/company_config.php';

$is_logged_in = isset($_SESSION['user_id']);
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    die("Invalid Purchase ID");
}

$res = $conn->query("SELECT * FROM purchase_entries WHERE id = $id LIMIT 1");
if (!$res || $res->num_rows === 0) {
    die("Purchase entry not found");
}
$purchase = $res->fetch_assoc();

function fmt($v) {
    return number_format((float)$v, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Purchase View #<?= htmlspecialchars($purchase['purchase_no']) ?> | <?= htmlspecialchars($COMPANY['name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
*{font-family:'Poppins',sans-serif;box-sizing:border-box;}
@media print { .no-print { display:none!important; } body { background:#fff!important; } .card { box-shadow:none!important; border:1px solid #ccc!important; } }
</style>
</head>
<body style="background:#F5F5F5;margin:0;padding:20px;">

<div class="max-w-4xl mx-auto bg-white rounded-2xl p-8 shadow-lg card">
    <div class="flex justify-between items-center pb-6 border-b mb-6">
        <div>
            <h1 class="text-2xl font-bold text-amber-900"><?= htmlspecialchars($COMPANY['name']) ?></h1>
            <p class="text-xs text-gray-500"><?= htmlspecialchars($COMPANY['address_line1']) ?>, <?= htmlspecialchars($COMPANY['address_line2']) ?></p>
        </div>
        <div class="text-right">
            <span class="text-xs font-bold uppercase tracking-wider text-amber-800 bg-amber-100 px-3 py-1 rounded-full">Purchase Entry</span>
            <div class="text-sm font-bold text-gray-700 mt-2"># <?= htmlspecialchars($purchase['purchase_no']) ?></div>
            <div class="text-xs text-gray-400">Date: <?= date('d-M-Y', strtotime($purchase['purchase_date'])) ?></div>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-6 mb-8 text-xs">
        <div class="bg-amber-50 p-4 rounded-xl border border-amber-200">
            <h3 class="font-bold text-amber-900 mb-2 uppercase tracking-wide">Supplier (Seller)</h3>
            <p class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($purchase['supplier_name']) ?></p>
            <p class="text-gray-600"><?= htmlspecialchars($purchase['supplier_addr'] ?? '—') ?></p>
            <p class="text-gray-600 mt-1">Mob: <?= htmlspecialchars($purchase['supplier_mobile'] ?? '—') ?></p>
            <p class="text-gray-600">GSTIN: <?= htmlspecialchars($purchase['supplier_gstin'] ?? '—') ?></p>
        </div>
        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200">
            <h3 class="font-bold text-gray-900 mb-2 uppercase tracking-wide">Invoice Details</h3>
            <p>Invoice No: <strong><?= htmlspecialchars($purchase['invoice_no']) ?></strong></p>
            <p>Invoice Date: <?= date('d-M-Y', strtotime($purchase['invoice_date'])) ?></p>
            <p>Payment Mode: <strong><?= htmlspecialchars($purchase['payment_mode']) ?></strong></p>
        </div>
    </div>

    <table class="w-full text-left border-collapse text-xs mb-8">
        <thead>
            <tr class="bg-amber-900 text-white">
                <th class="p-3">Material & Description</th>
                <th class="p-3 text-center">HSN</th>
                <th class="p-3 text-center">Qty</th>
                <th class="p-3 text-right">Rate / Unit</th>
                <th class="p-3 text-right">Tax (GST)</th>
                <th class="p-3 text-right">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr class="border-b">
                <td class="p-3">
                    <strong><?= htmlspecialchars($purchase['description']) ?></strong>
                    <span class="ml-2 text-xs bg-amber-100 text-amber-900 px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($purchase['material_type']) ?></span>
                    <?php if(!empty($purchase['huid_code'])): ?>
                    <div class="text-gray-400 text-xs">HUID: <?= htmlspecialchars($purchase['huid_code']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="p-3 text-center"><?= htmlspecialchars($purchase['hsn_sac'] ?? '—') ?></td>
                <td class="p-3 text-center font-bold"><?= rtrim(rtrim(number_format((float)$purchase['qty'], 4), '0'), '.') ?> <?= htmlspecialchars($purchase['unit']) ?></td>
                <td class="p-3 text-right">₹ <?= fmt($purchase['rate_per_unit']) ?></td>
                <td class="p-3 text-right">₹ <?= fmt($purchase['gst_total']) ?></td>
                <td class="p-3 text-right font-bold text-amber-900">₹ <?= fmt($purchase['total_amount']) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="flex justify-between items-center pt-4 border-t no-print">
        <a href="purchase_history.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-xl font-semibold text-xs text-decoration-none">← Back to History</a>
        <button onclick="window.print()" class="px-5 py-2 bg-amber-800 text-white rounded-xl font-bold text-xs"><i class="fas fa-print mr-1"></i> Print Statement</button>
    </div>
</div>

</body>
</html>
