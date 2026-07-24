<?php
require_once __DIR__ . '/config/database.php';

$sample_products = [
    ['serial_no'=>'RSG001','name'=>'Gold Royal Necklace 22K','item_name'=>'Gold Necklace','weight'=>'24.500','category'=>'Gold 22K','price'=>160500.00,'quantity'=>5,'huid_code'=>'RS22K9012'],
    ['serial_no'=>'RSG002','name'=>'Antique Gold Temple Bangles','item_name'=>'Gold Bangles','weight'=>'38.200','category'=>'Gold 22K','price'=>249800.00,'quantity'=>3,'huid_code'=>'RS22K9013'],
    ['serial_no'=>'RSG003','name'=>'Gold Bridal Mangalsutra','item_name'=>'Mangalsutra','weight'=>'18.750','category'=>'Gold 22K','price'=>122600.00,'quantity'=>4,'huid_code'=>'RS22K9014'],
    ['serial_no'=>'RSG004','name'=>'Traditional Gold Jhumka Earrings','item_name'=>'Gold Earrings','weight'=>'12.400','category'=>'Gold 22K','price'=>81100.00,'quantity'=>8,'huid_code'=>'RS22K9015'],
    ['serial_no'=>'RSG005','name'=>'Solid Gold Kada (Men)','item_name'=>'Gold Kada','weight'=>'45.000','category'=>'Gold 22K','price'=>294300.00,'quantity'=>2,'huid_code'=>'RS22K9016'],
    ['serial_no'=>'RSG006','name'=>'Lightweight Gold Chain 18K','item_name'=>'Gold Chain','weight'=>'8.200','category'=>'Gold 18K','price'=>40100.00,'quantity'=>12,'huid_code'=>'RS18K5011'],
    ['serial_no'=>'RSG007','name'=>'Designer Gold Pendant 18K','item_name'=>'Gold Pendant','weight'=>'5.600','category'=>'Gold 18K','price'=>27400.00,'quantity'=>10,'huid_code'=>'RS18K5012'],
    ['serial_no'=>'RSG008','name'=>'Gold Finger Ring 18K','item_name'=>'Gold Ring','weight'=>'4.100','category'=>'Gold 18K','price'=>20050.00,'quantity'=>15,'huid_code'=>'RS18K5013'],
    ['serial_no'=>'RSS001','name'=>'925 Pure Silver Anklets (Payal)','item_name'=>'Silver Payal','weight'=>'85.000','category'=>'Silver','price'=>18360.00,'quantity'=>20,'huid_code'=>'RSS925001'],
    ['serial_no'=>'RSS002','name'=>'Silver Dinner Set 5-Piece','item_name'=>'Silver Utensils','weight'=>'450.000','category'=>'Silver','price'=>97200.00,'quantity'=>2,'huid_code'=>'RSS925002'],
    ['serial_no'=>'RSS003','name'=>'Sterling Silver Chain with Cross','item_name'=>'Silver Chain','weight'=>'15.200','category'=>'Silver','price'=>3280.00,'quantity'=>25,'huid_code'=>'RSS925003'],
    ['serial_no'=>'RSS004','name'=>'Silver Puja Pooja Thali Set','item_name'=>'Silver Thali','weight'=>'250.000','category'=>'Silver','price'=>54000.00,'quantity'=>5,'huid_code'=>'RSS925004'],
    ['serial_no'=>'RSS005','name'=>'Designer Silver Rings Pair','item_name'=>'Silver Ring','weight'=>'10.500','category'=>'Silver','price'=>2260.00,'quantity'=>18,'huid_code'=>'RSS925005'],
    ['serial_no'=>'RSD001','name'=>'Solitaire Diamond Engagement Ring','item_name'=>'Diamond Ring','weight'=>'3.800','category'=>'Diamond','price'=>145000.00,'quantity'=>4,'huid_code'=>'RSDDIA101'],
    ['serial_no'=>'RSD002','name'=>'Diamond Choker Necklace Set','item_name'=>'Diamond Necklace','weight'=>'32.400','category'=>'Diamond','price'=>485000.00,'quantity'=>1,'huid_code'=>'RSDDIA102'],
    ['serial_no'=>'RSD003','name'=>'Diamond Stud Earrings 1 Carat','item_name'=>'Diamond Earrings','weight'=>'4.200','category'=>'Diamond','price'=>98000.00,'quantity'=>6,'huid_code'=>'RSDDIA103'],
    ['serial_no'=>'RSD004','name'=>'Diamond Tennis Bracelet','item_name'=>'Diamond Bracelet','weight'=>'14.800','category'=>'Diamond','price'=>210000.00,'quantity'=>3,'huid_code'=>'RSDDIA104'],
    ['serial_no'=>'RSP001','name'=>'Platinum Couple Bands (Pair)','item_name'=>'Platinum Ring','weight'=>'12.600','category'=>'Platinum','price'=>64900.00,'quantity'=>5,'huid_code'=>'RSP950001'],
    ['serial_no'=>'RSP002','name'=>'Platinum Chain 950 Pure','item_name'=>'Platinum Chain','weight'=>'22.000','category'=>'Platinum','price'=>113300.00,'quantity'=>4,'huid_code'=>'RSP950002'],
    ['serial_no'=>'RSP003','name'=>'Platinum Pendant with Solitaire','item_name'=>'Platinum Pendant','weight'=>'6.400','category'=>'Platinum','price'=>52000.00,'quantity'=>7,'huid_code'=>'RSP950003']
];

$inserted = 0;
foreach($sample_products as $p) {
    $s = mysqli_real_escape_string($conn, $p['serial_no']);
    $n = mysqli_real_escape_string($conn, $p['name']);
    $in = mysqli_real_escape_string($conn, $p['item_name']);
    $w = mysqli_real_escape_string($conn, $p['weight']);
    $c = mysqli_real_escape_string($conn, $p['category']);
    $pr = $p['price'];
    $q = $p['quantity'];
    $h = mysqli_real_escape_string($conn, $p['huid_code']);

    $sql = "INSERT INTO products (serial_no, name, item_name, weight, category, price, quantity, huid_code) 
            VALUES ('$s', '$n', '$in', '$w', '$c', $pr, $q, '$h')
            ON DUPLICATE KEY UPDATE name='$n', item_name='$in', weight='$w', category='$c', price=$pr, quantity=$q, huid_code='$h'";
    if(mysqli_query($conn, $sql)) {
        $inserted++;
    } else {
        echo "Error: " . mysqli_error($conn) . PHP_EOL;
    }
}

echo "Successfully inserted/updated $inserted sample products into radhe_shyam_jewellers database." . PHP_EOL;

$res = mysqli_query($conn, "SELECT serial_no, name, category, weight, price, quantity FROM products ORDER BY id ASC");
echo PHP_EOL . "--- CURRENT PRODUCT CATALOG (" . mysqli_num_rows($res) . " ITEMS) ---" . PHP_EOL;
while($r = mysqli_fetch_assoc($res)) {
    echo sprintf("%-8s | %-36s | %-10s | %6sg | ₹%9.2f | Qty: %d\n", $r['serial_no'], $r['name'], $r['category'], $r['weight'], $r['price'], $r['quantity']);
}


