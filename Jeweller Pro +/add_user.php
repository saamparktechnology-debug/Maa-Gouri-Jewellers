<?php
require_once 'config/database.php';
require_once 'config/company_config.php';

$email = 'hiisupriya@gmail.com';
$password = '123456';
$hash = password_hash($password, PASSWORD_DEFAULT);
$name = 'Admin User';
$mobile = '9876543210';

// Check if user exists by email
$res = $conn->query("SELECT * FROM users WHERE email = '$email'");
if ($res && $res->num_rows > 0) {
    // Update password and name/mobile
    $stmt = $conn->prepare("UPDATE users SET password = ?, name = ?, mobile = ? WHERE email = ?");
    $stmt->bind_param("ssss", $hash, $name, $mobile, $email);
    if ($stmt->execute()) {
        echo "<h3>✅ User $email password updated to $password successfully!</h3>";
    } else {
        echo "<h3>❌ Error updating user: " . $conn->error . "</h3>";
    }
} else {
    // Ensure no mobile conflicts
    $conn->query("DELETE FROM users WHERE mobile = '$mobile'");
    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO users (name, mobile, email, password) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $mobile, $email, $hash);
    if ($stmt->execute()) {
        echo "<h3>✅ User $email registered with password $password successfully!</h3>";
    } else {
        echo "<h3>❌ Error inserting user: " . $conn->error . "</h3>";
    }
}

echo "<p>⚠️ <strong>Important:</strong> Please delete this <code>add_user.php</code> file from your server after running it for security reasons.</p>";
?>


