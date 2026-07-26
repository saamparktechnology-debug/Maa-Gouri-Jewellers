<?php
require_once "config/database.php";
$result = mysqli_query($conn, "DESCRIBE products");
while($row = mysqli_fetch_assoc($result)) {
    echo $row['Field'] . " | " . $row['Type'] . " | " . $row['Null'] . " | " . $row['Key'] . " | " . $row['Default'] . "\n";
}
?>
