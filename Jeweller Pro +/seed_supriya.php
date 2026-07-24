<?php
require_once 'config/database.php';

$hash  = password_hash('123456', PASSWORD_DEFAULT);
$email = 'hiisupriya@gmail.com';
$mob   = '9876543210';
$name  = 'Supriya Admin';

$chk = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email' OR mobile = '$mob'");
if ($chk && mysqli_num_rows($chk) > 0) {
    mysqli_query($conn, "UPDATE users SET password = '$hash', email = '$email', mobile = '$mob', name = '$name' WHERE email = '$email' OR mobile = '$mob'");
    echo "SUCCESS: User hiisupriya@gmail.com updated in database gouri with password '123456'.\n";
} else {
    mysqli_query($conn, "INSERT INTO users (name, mobile, email, password) VALUES ('$name', '$mob', '$email', '$hash')");
    echo "SUCCESS: User hiisupriya@gmail.com created in database gouri with password '123456'.\n";
}
?>
