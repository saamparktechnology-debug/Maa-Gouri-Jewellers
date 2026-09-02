<?php
require_once __DIR__ . '/../config/database.php';
mysqli_query($conn, "DELETE FROM products WHERE name LIKE 'Gold Basket Stock%'");
echo "Cleaned test products\n";
