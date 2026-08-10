<?php
session_start();
require_once __DIR__ . '/config/database.php';

$transfer_id = intval($_GET['id'] ?? 0);
$transfer_no = mysqli_real_escape_string($conn, trim($_GET['transfer_no'] ?? ''));

$where = "id = $transfer_id";
if (!empty($transfer_no)) {
    $where = "transfer_no = '$transfer_no'";
}

$trf = null;
$res = mysqli_query($conn, "SELECT * FROM stock_transfers WHERE $where LIMIT 1");
if ($res && mysqli_num_rows($res) > 0) {
    $trf = mysqli_fetch_assoc($res);
}

if (!$trf) {
    die("<div style='font-family:sans-serif;padding:40px;text-align:center;color:#ef4444;'><h2> Transfer Voucher Not Found</h2><p>Invalid transfer ID or reference number.</p></div>");
}

// Indian Currency to Words Function
if (!function_exists('num2words')) {
    function num2words($number) {
        $no = floor($number);
        $point = round($number - $no, 2) * 100;
        $hundred = null;
        $digits_1 = strlen($no);
        $i = 0;
        $str = array();
        $words = array('0' => '', '1' => 'One', '2' => 'Two',
            '3' => 'Three', '4' => 'Four', '5' => 'Five', '6' => 'Six',
            '7' => 'Seven', '8' => 'Eight', '9' => 'Nine',
            '10' => 'Ten', '11' => 'Eleven', '12' => 'Twelve',
            '13' => 'Thirteen', '14' => 'Fourteen',
            '15' => 'Fifteen', '16' => 'Sixteen', '17' => 'Seventeen',
            '18' => 'Eighteen', '19' =>'Nineteen', '20' => 'Twenty',
            '30' => 'Thirty', '40' => 'Forty', '50' => 'Fifty',
            '60' => 'Sixty', '70' => 'Seventy', '80' => 'Eighty',
            '90' => 'Ninety');
        $digits = array('', 'Hundred', 'Thousand', 'Lakh', 'Crore');
        while ($i < $digits_1) {
            $divider = ($i == 2) ? 10 : 100;
            $number = floor($no % $divider);
            $no = floor($no / $divider);
            $i += ($divider == 10) ? 1 : 2;
            if ($number) {
                $plural = (($counter = count($str)) && $number > 9) ? 's' : '';
                $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
                $str [] = ($number < 21) ? $words[$number] .
                    " " . $digits[$counter] . $plural . " " . $hundred
                    :
                    $words[floor($number / 10) * 10]
                    . " " . $words[$number % 10] . " "
                    . $digits[$counter] . $plural . " " . $hundred;
            } else $str[] = null;
        }
        $str = array_reverse($str);
        $result = implode('', $str);
        $points = ($point) ?
            " and " . $words[floor($point / 10) * 10] . " " . 
                  $words[$point = $point % 10] . " Paise" : '';
        return ($result ? $result . "Rupees" : "") . $points . " Only";
    }
}

$val_words = num2words($trf['item_value']);
$logo_file = 'logo.png';
if (!file_exists($logo_file)) {
    if (file_exists('logo.jpg')) $logo_file = 'logo.jpg';
    elseif (file_exists('logo.jpeg')) $logo_file = 'logo.jpeg';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transfer Voucher - <?php echo htmlspecialchars($trf['transfer_no']); ?></title>
<link rel="icon" type="image/png" href="logo.png">
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Poppins:wght@400;500;600;700&display=swap');

@page {
    size: A4 portrait;
    margin: 5mm 8mm;
}

* { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins', sans-serif; }
body { background:#cbd5e1; padding:20px 0; color:#1e293b; }

.print-actions { max-width:820px; margin:0 auto 16px; display:flex; justify-content:space-between; align-items:center; }
.btn-print { background:linear-gradient(135deg,#7a4e0a,#d68b16); color:#fff; padding:10px 24px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(214,139,22,0.3); }
.btn-back { background:#fff; color:#475569; padding:10px 18px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid #cbd5e1; }

.invoice-card { 
    width:210mm; max-width:820px; margin:0 auto; background:#fffbf4; border:3px solid #d68b16; border-radius:14px; padding:24px 28px; box-shadow:0 12px 36px rgba(0,0,0,0.18); position:relative; overflow:hidden;
}

.full-page-coloured-watermark {
    position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); width:480px; height:480px; object-fit:contain; opacity:0.12; pointer-events:none; z-index:99; filter:none;
}

.invoice-content { position:relative; z-index:2; }

/* Top Header */
.top-header { display:flex; align-items:center; gap:18px; margin-bottom:16px; border-bottom:2.5px solid #d68b16; padding-bottom:14px; }
.very-left-logo { width:85px; height:85px; object-fit:contain; flex-shrink:0; }
.shop-info-center { flex:1; text-align:center; }
.shop-name-title { font-family:'Playfair Display', serif; font-size:24px; font-weight:800; color:#7a4e0a; text-transform:uppercase; letter-spacing:1px; line-height:1.1; margin-bottom:2px; }
.shop-sub-title { font-size:11px; color:#523e2b; font-weight:600; margin-bottom:4px; }
.shop-address-line { font-size:10.5px; color:#475569; line-height:1.4; }

.document-badge { background:linear-gradient(135deg,#7a4e0a,#d68b16); color:#fff; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; padding:6px 14px; border-radius:6px; display:inline-block; margin-bottom:14px; text-align:center; width:100%; box-shadow:0 2px 8px rgba(122,78,10,0.2); }

.meta-bar { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; background:rgba(247,238,215,0.72); border:1.5px solid #d68b16; border-radius:8px; padding:8px 16px; margin-bottom:16px; font-size:11.5px; }
.meta-label { font-size:9.5px; color:#7a4e0a; text-transform:uppercase; font-weight:600; letter-spacing:0.5px; margin-bottom:1px; }
.meta-value { font-weight:700; color:#2b1b17; font-size:13px; }

.destination-card { background:rgba(255,255,255,0.75); border:1.5px solid #e5c98a; border-radius:8px; padding:12px 16px; margin-bottom:16px; }
.destination-title { font-size:10.5px; font-weight:700; color:#7a4e0a; text-transform:uppercase; letter-spacing:1px; margin-bottom:3px; border-bottom:1px solid #f3e5c8; padding-bottom:3px; display:inline-block; }
.shop-destination-big { font-size:16px; font-weight:800; color:#1e293b; margin-top:2px; }

/* Table */
.inv-table { width:100%; border-collapse:collapse; margin-bottom:16px; background:rgba(255,255,255,0.75); border-radius:8px; overflow:hidden; border:1.5px solid #d68b16; }
.inv-table thead tr { background:linear-gradient(135deg, #7a4e0a, #d68b16); color:#fff; }
.inv-table th { padding:8px 10px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; text-align:left; border-right:1px solid rgba(255,255,255,0.15); }
.inv-table td { padding:8px 10px; font-size:11.5px; color:#334155; border-bottom:1px solid #f1e5cd; border-right:1px solid #f1e5cd; vertical-align:top; }
.inv-table td.right { text-align:right; }
.inv-table td.center { text-align:center; }

.bottom-section { display:grid; grid-template-columns:1.3fr 1fr; gap:16px; margin-top:0; align-items: stretch; }
.amount-words-bar { background:rgba(243,232,206,0.96); border:1.5px solid #d68b16; border-radius:8px; padding:8px 14px; font-size:11px; color:#523e2b; margin-bottom:12px; }
.terms-text-content { font-size:10px; color:#475569; line-height:1.55; background:rgba(255,255,255,0.65); padding:8px 12px; border-radius:6px; border:1px solid #f1e5cd; }

.calc-card { background:rgba(255,255,255,0.80); border:1.5px solid #d68b16; border-radius:8px; padding:14px 18px; display:flex; flex-direction:column; gap:6px; }
.calc-line { display:flex; justify-content:space-between; font-size:11.5px; color:#475569; }
.calc-total-box { background:linear-gradient(135deg, #7a4e0a, #d68b16); color:#fff; border-radius:6px; padding:8px 12px; display:flex; justify-content:space-between; align-items:center; margin:4px 0; }

.signature-stamp-frame { width:165px; height:75px; border:1.5px dashed #d68b16; border-radius:8px; display:inline-flex; flex-direction:column; align-items:center; justify-content:center; background:rgba(248,243,230,0.5); position:relative; }
.stamp-label-text { font-size:9.5px; color:#7a4e0a; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-top:auto; padding-bottom:5px; text-align:center; }

@media print {
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    html, body { width: 100% !important; height: auto !important; background: #fff !important; padding: 0 !important; margin: 0 !important; }
    .print-actions { display:none !important; }
    .invoice-card { width: 100% !important; max-width: 100% !important; height: auto !important; background: #fffbf4 !important; border: 2px solid #d68b16 !important; border-radius: 10px !important; box-shadow: none !important; padding: 16px 20px !important; box-sizing: border-box !important; }
    .full-page-coloured-watermark { position: absolute !important; top: 50% !important; left: 50% !important; transform: translate(-50%, -50%) !important; width: 480px !important; height: 480px !important; object-fit: contain !important; opacity: 0.16 !important; z-index: 9999 !important; pointer-events: none !important; }
    .inv-table { display: table !important; width: 100% !important; white-space: normal !important; border-collapse: collapse !important; }
}
</style>
</head>
<body>

<div class="print-actions">
    <div style="display:flex;gap:10px;">
        <a href="transfer.php" class="btn-back">&larr; Back to Transfers</a>
    </div>
    <button onclick="window.print()" class="btn-print"> Print Transfer Delivery Voucher (A4)</button>
</div>

<div class="invoice-card">
    <?php if(file_exists($logo_file)): ?>
    <img src="<?php echo $logo_file; ?>" class="full-page-coloured-watermark" alt="Watermark Logo">
    <?php endif; ?>

    <div class="invoice-content">
        <!-- Top Header -->
        <div class="top-header">
            <?php if(file_exists($logo_file)): ?>
            <img src="<?php echo $logo_file; ?>" class="very-left-logo" alt="Store Logo">
            <?php endif; ?>
            <div class="shop-info-center">
                <div class="shop-name-title">MAA GOURI JEWELLERS</div>
                <div class="shop-sub-title">PREMIUM GOLD &amp; DIAMOND JEWELLERY</div>
                <div class="shop-address-line">
                    Holding No: 154, Ward No: 12, Hospital Road, Medinipur Town, West Bengal 721101<br>
                    <strong>Contact:</strong> +91 98321 00000 | <strong>GSTIN:</strong> 19ABCDE1234F1ZH
                </div>
            </div>
        </div>

        <div class="document-badge">STOCK TRANSFER DELIVERY CHALLAN / VOUCHER</div>

        <!-- Meta Bar -->
        <div class="meta-bar">
            <div class="meta-item">
                <span class="meta-label">Transfer Ref No:</span>
                <span class="meta-value"><?php echo htmlspecialchars($trf['transfer_no']); ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Transfer Date:</span>
                <span class="meta-value"><?php echo date('d-M-Y', strtotime($trf['transfer_date'])); ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Transfer Mode:</span>
                <span class="meta-value"><?php echo strtoupper($trf['entry_mode']); ?> ENTRY</span>
            </div>
        </div>

        <!-- Destination Card -->
        <div class="destination-card">
            <div class="destination-title">TRANSFER DESTINATION / RECIPIENT SHOP:</div>
            <div class="shop-destination-big"><?php echo htmlspecialchars($trf['destination_shop']); ?></div>
            <?php if(!empty($trf['remarks'])): ?>
            <div style="font-size:11px;color:#64748b;margin-top:3px;">
                <strong>Transfer Note / Remarks:</strong> <?php echo htmlspecialchars($trf['remarks']); ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Items Table -->
        <table class="inv-table">
            <thead>
                <tr>
                    <th style="width:40px;" class="center">#</th>
                    <th>Item Description &amp; Details</th>
                    <th style="width:90px;" class="center">Category</th>
                    <th style="width:110px;" class="center">HUID Code</th>
                    <th style="width:100px;" class="right">Weight (g)</th>
                    <th style="width:70px;" class="center">Qty</th>
                    <th style="width:110px;" class="right">Unit Price</th>
                    <th style="width:120px;" class="right">Total Value</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="center">1</td>
                    <td>
                        <strong style="color:#1e293b;font-size:12px;"><?php echo htmlspecialchars($trf['item_name']); ?></strong>
                    </td>
                    <td class="center"><?php echo htmlspecialchars($trf['category'] ?? 'N/A'); ?></td>
                    <td class="center font-mono"><?php echo htmlspecialchars(!empty($trf['huid_code']) ? $trf['huid_code'] : '-'); ?></td>
                    <td class="right" style="font-weight:700;"><?php echo number_format(floatval($trf['weight']), 3); ?> g</td>
                    <td class="center" style="font-weight:700;"><?php echo intval($trf['quantity']); ?> <?php echo htmlspecialchars($trf['unit']); ?></td>
                    <td class="right">₹<?php echo number_format(floatval($trf['unit_price']), 2); ?></td>
                    <td class="right" style="font-weight:700;color:#15803d;">₹<?php echo number_format(floatval($trf['item_value']), 2); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Bottom Split Section -->
        <div class="bottom-section">
            <div>
                <div class="amount-words-bar">
                    <strong>Total Value in Words:</strong><br><em><?php echo $val_words; ?></em>
                </div>
                <div class="terms-text-content">
                    <strong>Transfer Delivery Conditions:</strong><br>
                    1. This Delivery Challan confirms the transfer of gold/jewellery items listed above.<br>
                    2. Stock quantity and gross weight have been verified and deducted from sender inventory.<br>
                    3. Recipient shop agrees to inspect and acknowledge receipt of transferred items.
                </div>
            </div>

            <div>
                <div class="calc-card">
                    <div class="calc-line">
                        <span>Total Items Transferred:</span>
                        <strong><?php echo intval($trf['quantity']); ?> <?php echo htmlspecialchars($trf['unit']); ?></strong>
                    </div>
                    <div class="calc-line">
                        <span>Total Weight Transferred:</span>
                        <strong><?php echo number_format(floatval($trf['weight']), 3); ?> Grams</strong>
                    </div>
                    <div class="calc-total-box">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;">Total Transfer Value</span>
                        <span style="font-size:15px;font-weight:700;">₹<?php echo number_format(floatval($trf['item_value']), 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Aligned Signatures -->
        <div style="display:flex; justify-content:space-between; margin-top:24px; padding:0 10px;">
            <div class="signature-stamp-frame">
                <div class="stamp-label-text" style="color:#64748b;">Recipient Signature</div>
            </div>
            <div class="signature-stamp-frame">
                <div class="stamp-label-text">Sender / Authorised Signatory<br>MAA GOURI JEWELLERS</div>
            </div>
        </div>

        <div style="margin-top:16px;padding-top:10px;border-top:1px solid #e8d5a8;text-align:center;font-size:9.5px;color:#7a4e0a;">
            Transfer Voucher Generated on: <?php echo date("d-M-Y h:i A"); ?>
        </div>
    </div>
</div>

</body>
</html>
