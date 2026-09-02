<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/company_config.php';
require_once __DIR__ . '/../config/mail_config.php';

// 1. Must be logged in to system
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

// 2. Helper to fetch stored geomorphology password hash
function get_geomorphology_password_hash($conn) {
    $res = mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'geomorphology_password' LIMIT 1");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        return $row['setting_value'];
    }
    return null;
}

// Helper to set stored password
function set_geomorphology_password($conn, $new_plain_pass) {
    $hash = password_hash($new_plain_pass, PASSWORD_BCRYPT);
    $stmt = mysqli_prepare($conn, "INSERT INTO system_settings (setting_key, setting_value) VALUES ('geomorphology_password', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ss", $hash, $hash);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
    return false;
}

// Helper to verify geomorphology password
function verify_geomorphology_password($conn, $input_pass) {
    $stored_hash = get_geomorphology_password_hash($conn);
    if (!empty($stored_hash)) {
        if (password_verify($input_pass, $stored_hash)) {
            return true;
        }
    }
    // Fallback default password
    return ($input_pass === 'Deb721140@');
}

// Action: Lock geomorphology
if (isset($_GET['geo_action']) && ($_GET['geo_action'] === 'lock' || $_GET['geo_action'] === 'exit_to_main')) {
    unset($_SESSION['geomorphology_unlocked']);
    unset($_SESSION['geomorphology_unlocked_at']);
    $target = $_GET['target'] ?? '/geomorphology';
    header("Location: " . $target);
    exit();
}

// Handle AJAX / POST requests for authentication & password management
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['geo_ajax_action'])) {

    header('Content-Type: application/json');
    $action = trim($_POST['geo_ajax_action']);

    // 1. Unlock with password
    if ($action === 'unlock') {
        $password = trim($_POST['password'] ?? '');
        if (empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Please enter section password.']);
            exit();
        }
        if (verify_geomorphology_password($conn, $password)) {
            $_SESSION['geomorphology_unlocked'] = true;
            $_SESSION['geomorphology_unlocked_at'] = time();
            echo json_encode(['success' => true, 'message' => 'Unlocked successfully!']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again or reset via OTP.']);
            exit();
        }
    }


    // 2. Send OTP for Forgot Password
    if ($action === 'send_otp') {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid registered admin email address.']);
            exit();
        }

        // Verify that the email belongs to a registered admin in users table OR company config
        $e_esc = mysqli_real_escape_string($conn, $email);
        $u_q = mysqli_query($conn, "SELECT id, email, name FROM users WHERE LOWER(email) = '$e_esc' AND email != '' LIMIT 1");
        $is_valid_admin = false;
        $name = 'Admin';

        if ($u_q && $u_row = mysqli_fetch_assoc($u_q)) {
            $is_valid_admin = true;
            $name = $u_row['name'] ?: 'Admin';
            $email = $u_row['email'];
        } elseif (isset($COMPANY['email']) && strtolower($COMPANY['email']) === $email) {
            $is_valid_admin = true;
            $name = $COMPANY['name'] ?? 'Admin';
            $email = $COMPANY['email'];
        }

        if (!$is_valid_admin) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: This email is not a registered admin email address.']);
            exit();
        }

        $otp = sprintf("%06d", mt_rand(100000, 999999));
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $email_esc = mysqli_real_escape_string($conn, $email);


        $ins = mysqli_query($conn, "INSERT INTO otp_logins (email, otp, expires_at) VALUES ('$email_esc', '$otp', '$expires_at')");
        if ($ins) {
            $subject = "Geomorphology Section - Password Reset OTP";
            $body = '
            <!DOCTYPE html>
            <html>
            <body style="margin:0;padding:20px;font-family:\'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;background-color:#f4f6f9;">
                <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,0.08);border:1px solid #e2e8f0;">
                    <div style="background:linear-gradient(135deg, #1e293b 0%, #0f172a 100%);padding:28px;text-align:center;border-bottom:3px solid #d97706;">
                        <h2 style="color:#ffffff;margin:0;font-size:22px;letter-spacing:1px;font-weight:700;">MAA GOURI JEWELLERS</h2>
                        <p style="color:#fbbf24;margin:6px 0 0 0;font-size:13px;letter-spacing:2px;text-transform:uppercase;">Geomorphology Access Control</p>
                    </div>
                    <div style="padding:32px 28px;text-align:center;">
                        <p style="color:#334155;font-size:15px;margin-top:0;">Hello <strong>' . htmlspecialchars($name ?? 'Admin') . '</strong>,</p>
                        <p style="color:#64748b;font-size:14px;line-height:1.5;">You requested an OTP to reset the security password for the <strong>Geomorphology Section</strong>.</p>
                        
                        <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:10px;padding:18px;margin:24px 0;">
                            <span style="font-size:34px;font-weight:800;letter-spacing:8px;color:#0f172a;font-family:monospace;">' . $otp . '</span>
                        </div>
                        
                        <p style="color:#64748b;font-size:13px;margin-bottom:0;"> This OTP is valid for <strong>10 minutes</strong>. If you did not request this, please ignore this email.</p>
                    </div>
                    <div style="background:#f1f5f9;padding:14px;text-align:center;font-size:12px;color:#64748b;border-top:1px solid #e2e8f0;">
                        Maa Gouri Jewellers ERP &bull; Secure Authentication System
                    </div>
                </div>
            </body>
            </html>';

            $mailRes = sendSMTPMail($email, $subject, $body);
            if ($mailRes['success']) {
                // Mask email for privacy
                $parts = explode('@', $email);
                $masked = substr($parts[0], 0, 2) . '***@' . ($parts[1] ?? 'gmail.com');
                echo json_encode([
                    'success' => true,
                    'message' => 'OTP sent successfully to ' . $masked . '!',
                    'email' => $email
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to send OTP email: ' . $mailRes['message']]);
            }
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error generating OTP.']);
            exit();
        }
    }

    // 3. Verify OTP & Reset Password
    if ($action === 'verify_and_reset') {
        $email = trim($_POST['email'] ?? '');
        $otp = trim($_POST['otp'] ?? '');
        $new_pass = trim($_POST['new_password'] ?? '');
        $confirm_pass = trim($_POST['confirm_password'] ?? '');

        if (empty($email) || empty($otp) || empty($new_pass)) {
            echo json_encode(['success' => false, 'message' => 'Please complete all required fields.']);
            exit();
        }
        if ($new_pass !== $confirm_pass) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
            exit();
        }
        if (strlen($new_pass) < 4) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 4 characters.']);
            exit();
        }

        $email_esc = mysqli_real_escape_string($conn, $email);
        $otp_esc = mysqli_real_escape_string($conn, $otp);

        // Verify that the email belongs to a registered admin in users table OR company config
        $u_q = mysqli_query($conn, "SELECT id FROM users WHERE LOWER(email) = '$email_esc' AND email != '' LIMIT 1");
        $is_valid_admin = false;
        if ($u_q && mysqli_num_rows($u_q) > 0) {
            $is_valid_admin = true;
        } elseif (isset($COMPANY['email']) && strtolower($COMPANY['email']) === strtolower($email)) {
            $is_valid_admin = true;
        }

        if (!$is_valid_admin) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Email is not an authorized admin address.']);
            exit();
        }

        $chk = mysqli_query($conn, "SELECT id FROM otp_logins WHERE email = '$email_esc' AND otp = '$otp_esc' AND is_used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        if ($chk && $row = mysqli_fetch_assoc($chk)) {
            // Mark OTP used
            mysqli_query($conn, "UPDATE otp_logins SET is_used = 1 WHERE id = {$row['id']}");
            
            // Save new password
            if (set_geomorphology_password($conn, $new_pass)) {
                $_SESSION['geomorphology_unlocked'] = true;
                $_SESSION['geomorphology_unlocked_at'] = time();
                echo json_encode(['success' => true, 'message' => 'Password updated successfully! Section is now unlocked.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save new password.']);
            }
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP. Please try again.']);
            exit();
        }
    }

    // 4. Change Password (when already authenticated/unlocked)
    if ($action === 'change_password') {
        $curr_pass = trim($_POST['current_password'] ?? '');
        $new_pass = trim($_POST['new_password'] ?? '');
        $confirm_pass = trim($_POST['confirm_password'] ?? '');

        if (!verify_geomorphology_password($conn, $curr_pass)) {
            echo json_encode(['success' => false, 'message' => 'Current password entered is incorrect.']);
            exit();
        }
        if ($new_pass !== $confirm_pass) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
            exit();
        }
        if (strlen($new_pass) < 4) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 4 characters.']);
            exit();
        }

        if (set_geomorphology_password($conn, $new_pass)) {
            echo json_encode(['success' => true, 'message' => 'Geomorphology password updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save new password.']);
        }
        exit();
    }
}

// 3. If NOT unlocked, render the lock screen and halt execution
if (!isset($_SESSION['geomorphology_unlocked']) || $_SESSION['geomorphology_unlocked'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Geomorphology &bull; Protected Access</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
            * { font-family: 'Plus Jakarta Sans', sans-serif; }
            .bg-glass {
                background: rgba(15, 23, 42, 0.85);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border: 1px solid rgba(255, 255, 255, 0.1);
            }
            .gold-glow {
                box-shadow: 0 0 35px -5px rgba(217, 119, 6, 0.35);
            }
            .gold-gradient-text {
                background: linear-gradient(135deg, #fbbf24, #d97706, #f59e0b);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            .gold-btn {
                background: linear-gradient(135deg, #d97706, #b45309);
                transition: all 0.25s ease;
            }
            .gold-btn:hover {
                background: linear-gradient(135deg, #f59e0b, #d97706);
                transform: translateY(-1px);
                box-shadow: 0 10px 20px -5px rgba(217, 119, 6, 0.4);
            }
        </style>
    </head>
    <body class="min-h-screen bg-slate-950 flex items-center justify-center p-4 relative overflow-hidden text-slate-100">
        <!-- Background decorative ambient circles -->
        <div class="absolute -top-40 -left-40 w-96 h-96 bg-amber-600/15 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-emerald-600/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-slate-900/50 rounded-full blur-3xl pointer-events-none"></div>

        <div class="w-full max-w-md bg-glass rounded-3xl p-8 shadow-2xl relative z-10 gold-glow">
            
            <!-- Brand Icon & Header -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-amber-500/20 to-amber-700/30 border border-amber-500/40 flex items-center justify-center mx-auto mb-4 shadow-inner">
                    <i class="fas fa-layer-group text-2xl text-amber-400"></i>
                </div>
                <h1 class="text-2xl font-black tracking-tight text-white">Geomorphology</h1>
                <p class="text-xs text-slate-400 mt-1 uppercase tracking-widest font-semibold">Protected Management Section</p>
            </div>

            <!-- UNLOCK FORM -->
            <div id="unlockSection">
                <form id="unlockForm" class="space-y-4" onsubmit="handleUnlock(event)">
                    <div id="unlockAlert" class="hidden text-xs px-4 py-3 rounded-xl border"></div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Section Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                                <i class="fas fa-lock text-sm"></i>
                            </span>
                            <input type="password" id="geoPasswordInput" required autofocus
                                placeholder="Enter section password..." 
                                class="w-full pl-10 pr-11 py-3 bg-slate-900/90 border border-slate-700/80 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20 transition">
                            <button type="button" onclick="togglePassVisibility('geoPasswordInput', 'passEyeIcon')" 
                                class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-500 hover:text-slate-300">
                                <i id="passEyeIcon" class="fas fa-eye text-sm"></i>
                            </button>
                        </div>
                        <div class="flex items-center justify-end mt-2">
                            <button type="button" onclick="showOtpModal()" class="text-xs text-amber-400 hover:text-amber-300 font-medium underline flex items-center gap-1">
                                <i class="fas fa-key text-[10px]"></i> Forgot Password?
                            </button>
                        </div>
                    </div>


                    <button type="submit" id="unlockBtn" class="w-full py-3.5 gold-btn rounded-xl font-bold text-sm text-white flex items-center justify-center gap-2 shadow-lg">
                        <i class="fas fa-key text-xs"></i>
                        <span>Unlock Geomorphology</span>
                    </button>
                </form>
            </div>

            <!-- FORGOT PASSWORD / OTP STEP MODAL -->
            <div id="otpModal" class="hidden mt-6 pt-6 border-t border-slate-800">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fas fa-shield-alt text-amber-400"></i>
                        <span>Reset via Email OTP</span>
                    </h3>
                    <button type="button" onclick="hideOtpModal()" class="text-slate-500 hover:text-slate-300 text-xs font-semibold">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>

                <div id="otpAlert" class="hidden text-xs px-4 py-3 rounded-xl border mb-3"></div>

                <!-- Step 1: Send OTP -->
                <div id="otpStep1">
                    <p class="text-xs text-slate-400 mb-3">Enter the admin email address to receive a 6-digit verification code:</p>
                    <div class="space-y-3">
                        <input type="email" id="otpEmailInput" value="" required
                            placeholder="Enter registered admin email address..." 
                            class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500">

                        <button type="button" id="sendOtpBtn" onclick="handleSendOtp()" 
                            class="w-full py-2.5 bg-slate-800 hover:bg-slate-700 text-amber-300 border border-amber-500/30 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2">
                            <i class="fas fa-paper-plane text-[11px]"></i>
                            <span>Send OTP to Email</span>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Verify OTP & Set New Password -->
                <div id="otpStep2" class="hidden space-y-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-400 mb-1">Enter 6-Digit OTP</label>
                        <input type="text" id="otpCodeInput" maxlength="6" placeholder="123456" 
                            class="w-full text-center tracking-[6px] font-mono text-base font-bold py-2 bg-slate-900 border border-slate-700 rounded-xl text-amber-400 placeholder-slate-600 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-400 mb-1">New Password</label>
                        <input type="password" id="otpNewPass" placeholder="Enter new password" 
                            class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-400 mb-1">Confirm New Password</label>
                        <input type="password" id="otpConfirmPass" placeholder="Re-type new password" 
                            class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500">
                    </div>
                    <div class="flex gap-2 pt-1">
                        <button type="button" onclick="handleSendOtp()" class="w-1/3 py-2.5 bg-slate-900 hover:bg-slate-800 text-slate-400 text-xs rounded-xl font-semibold border border-slate-700">Resend</button>
                        <button type="button" id="resetPassBtn" onclick="handleVerifyAndReset()" class="w-2/3 py-2.5 gold-btn text-white text-xs font-bold rounded-xl shadow">Update &amp; Unlock</button>
                    </div>
                </div>
            </div>

            <!-- Footer Return Link -->
            <div class="mt-8 text-center border-t border-slate-800/80 pt-5">
                <a href="/index.php" class="inline-flex items-center gap-2 text-xs font-semibold text-slate-400 hover:text-white transition">
                    <i class="fas fa-arrow-left text-[10px]"></i>
                    <span>Return to Main System</span>
                </a>
            </div>
        </div>

        <script>
            function togglePassVisibility(inputId, iconId) {
                const input = document.getElementById(inputId);
                const icon = document.getElementById(iconId);
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }

            function showAlert(elemId, type, text) {
                const el = document.getElementById(elemId);
                el.classList.remove('hidden', 'bg-red-500/15', 'border-red-500/30', 'text-red-300', 'bg-emerald-500/15', 'border-emerald-500/30', 'text-emerald-300');
                if (type === 'error') {
                    el.classList.add('bg-red-500/15', 'border-red-500/30', 'text-red-300');
                } else {
                    el.classList.add('bg-emerald-500/15', 'border-emerald-500/30', 'text-emerald-300');
                }
                el.innerHTML = text;
            }

            async function handleUnlock(e) {
                e.preventDefault();
                const pass = document.getElementById('geoPasswordInput').value.trim();
                const btn = document.getElementById('unlockBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

                try {
                    const fd = new FormData();
                    fd.append('geo_ajax_action', 'unlock');
                    fd.append('password', pass);

                    const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data.success) {
                        showAlert('unlockAlert', 'success', '<i class="fas fa-check-circle"></i> ' + data.message);
                        setTimeout(() => {
                            window.location.reload();
                        }, 400);
                    } else {
                        showAlert('unlockAlert', 'error', '<i class="fas fa-exclamation-circle"></i> ' + data.message);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-key text-xs"></i><span>Unlock Geomorphology</span>';
                    }
                } catch (err) {
                    showAlert('unlockAlert', 'error', 'Network or server error.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-key text-xs"></i><span>Unlock Geomorphology</span>';
                }
            }

            function showOtpModal() {
                document.getElementById('otpModal').classList.remove('hidden');
            }
            function hideOtpModal() {
                document.getElementById('otpModal').classList.add('hidden');
            }

            async function handleSendOtp() {
                const email = document.getElementById('otpEmailInput').value.trim();
                const btn = document.getElementById('sendOtpBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending OTP...';

                try {
                    const fd = new FormData();
                    fd.append('geo_ajax_action', 'send_otp');
                    fd.append('email', email);

                    const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data.success) {
                        showAlert('otpAlert', 'success', '<i class="fas fa-check-circle"></i> ' + data.message);
                        document.getElementById('otpStep1').classList.add('hidden');
                        document.getElementById('otpStep2').classList.remove('hidden');
                    } else {
                        showAlert('otpAlert', 'error', '<i class="fas fa-exclamation-circle"></i> ' + data.message);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-paper-plane text-[11px]"></i><span>Send OTP to Email</span>';
                    }
                } catch (err) {
                    showAlert('otpAlert', 'error', 'Error sending OTP request.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane text-[11px]"></i><span>Send OTP to Email</span>';
                }
            }

            async function handleVerifyAndReset() {
                const email = document.getElementById('otpEmailInput').value.trim();
                const otp = document.getElementById('otpCodeInput').value.trim();
                const newPass = document.getElementById('otpNewPass').value;
                const confirmPass = document.getElementById('otpConfirmPass').value;
                const btn = document.getElementById('resetPassBtn');

                if (newPass !== confirmPass) {
                    showAlert('otpAlert', 'error', 'Passwords do not match.');
                    return;
                }

                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

                try {
                    const fd = new FormData();
                    fd.append('geo_ajax_action', 'verify_and_reset');
                    fd.append('email', email);
                    fd.append('otp', otp);
                    fd.append('new_password', newPass);
                    fd.append('confirm_password', confirmPass);

                    const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data.success) {
                        showAlert('otpAlert', 'success', '<i class="fas fa-check-circle"></i> ' + data.message);
                        setTimeout(() => {
                            window.location.reload();
                        }, 700);
                    } else {
                        showAlert('otpAlert', 'error', '<i class="fas fa-exclamation-circle"></i> ' + data.message);
                        btn.disabled = false;
                        btn.innerHTML = 'Update &amp; Unlock';
                    }
                } catch (err) {
                    showAlert('otpAlert', 'error', 'Failed to submit reset request.');
                    btn.disabled = false;
                    btn.innerHTML = 'Update &amp; Unlock';
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit();
}
