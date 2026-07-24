<?php
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'saamparktechnologyresearch@gmail.com';
    $mail->Password   = 'vsenpeqdgkaqgnze';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->SMTPDebug  = 2;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $mail->setFrom('saamparktechnologyresearch@gmail.com', 'MAA GOURI JEWELLERS');
    $mail->addAddress('saamparktechnologyresearch@gmail.com');
    $mail->Subject = 'VPS SMTP Test';
    $mail->Body    = 'Testing SMTP from VPS';

    $mail->send();
    echo "\n\nSUCCESS! VPS SMTP IS WORKING PERFECTLY!\n";
} catch (Exception $e) {
    echo "\n\nVPS SMTP ERROR: " . $mail->ErrorInfo . "\n";
}

