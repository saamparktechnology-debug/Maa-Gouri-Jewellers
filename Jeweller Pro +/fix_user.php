<?php
require_once __DIR__ . '/config/database.php';

$pass1 = password_hash('radhe#123', PASSWORD_BCRYPT);
$pass2 = password_hash('123456', PASSWORD_BCRYPT);

// Clean up and recreate the two admin accounts
mysqli_query($conn, "DELETE FROM users WHERE email='subhapatra169@gmail.com' OR email='hiisupriya@gmail.com' OR mobile='8617536679' OR mobile='9876543210'");

mysqli_query($conn, "INSERT INTO users (name, mobile, email, password) VALUES 
('Subha Patra', '8617536679', 'subhapatra169@gmail.com', '$pass1'),
('Supriya', '9876543210', 'hiisupriya@gmail.com', '$pass2')");

echo "<div style='font-family:sans-serif;padding:30px;background:#f0fdf4;color:#166534;border:2px solid #86efac;border-radius:12px;max-width:500px;margin:50px auto;text-align:center;'>";
echo "<h2 style='margin-top:0;'>✅ Admin Users Fixed Successfully!</h2>";
echo "<p>The following accounts are ready in your live database:</p>";
echo "<div style='text-align:left;background:#fff;padding:15px;border-radius:8px;margin:15px 0;line-height:1.8;'>";
echo "<strong>1) Subha Patra:</strong><br>Email: <code>subhapatra169@gmail.com</code><br>Pass: <code>radhe#123</code><br><br>";
echo "<strong>2) Supriya:</strong><br>Email: <code>hiisupriya@gmail.com</code><br>Pass: <code>123456</code>";
echo "</div>";
echo "<a href='login.php' style='display:inline-block;padding:10px 20px;background:#166534;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;'>Go to Login Page &rarr;</a>";
echo "</div>";
?>

