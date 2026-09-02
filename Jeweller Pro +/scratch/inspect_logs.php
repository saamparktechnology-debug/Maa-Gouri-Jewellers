<?php
require_once 'config/database.php';
$res = mysqli_query($conn, 'SELECT * FROM product_activity_logs LIMIT 10');
while($r = mysqli_fetch_assoc($res)) {
    print_r($r);
}
