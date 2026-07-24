<?php
require_once __DIR__ . '/config/database.php';

$pass1 = password_hash('radhe#123', PASSWORD_BCRYPT);
$pass2 = password_hash('123456', PASSWORD_BCRYPT);

mysqli_query($conn, "DELETE FROM users WHERE email='subhapatra169@gmail.com' OR email='hiisupriya@gmail.com' OR mobile='8617536679' OR mobile='9876543210'");

mysqli_query($conn, "INSERT INTO users (name, mobile, email, password) VALUES 
('Subha Patra', '8617536679', 'subhapatra169@gmail.com', '$pass1'),
('Supriya', '9876543210', 'hiisupriya@gmail.com', '$pass2')");

?>
<!DOCTYPE html>
<html>
<head>
    <title>Users Fixed</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 32px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); text-align: center; max-width: 400px; border: 2px solid #22c55e; }
        h2 { color: #15803d; margin-top: 0; }
        .box { background: #f0fdf4; padding: 12px; border-radius: 8px; text-align: left; margin: 16px 0; font-size: 14px; line-height: 1.6; }
        .btn { display: inline-block; padding: 12px 24px; background: #15803d; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="card">
        <h2>✅ Users Activated!</h2>
        <div class="box">
            <strong>Subha Patra:</strong><br>
            subhapatra169@gmail.com / radhe#123<br><br>
            <strong>Supriya:</strong><br>
            hiisupriya@gmail.com / 123456
        </div>
        <a href="login.php" class="btn">Go to Login Page</a>
    </div>
</body>
</html>

