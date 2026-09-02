<?php
require_once 'config/database.php';
$res = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items");
while($r = mysqli_fetch_assoc($res)) {
    echo $r['Field'] . " (" . $r['Type'] . ")\n";
}

// Ensure hallmark_type column exists
$chk = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'hallmark_type'");
if (mysqli_num_rows($chk) == 0) {
    mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN hallmark_type VARCHAR(50) DEFAULT 'BIS 916' AFTER hallmark");
    echo "Added hallmark_type column\n";
} else {
    echo "hallmark_type column already exists\n";
}
