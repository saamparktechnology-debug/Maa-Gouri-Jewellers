<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

/*
|--------------------------------------------------------------------------
| Mail Configuration
|--------------------------------------------------------------------------
*/

define('MAIL_FROM_ADDRESS', 'santudhara157@gmail.com');
define('MAIL_FROM_NAME', 'Maa Gouri Jewellers');

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'santudhara157@gmail.com');

// Replace with your NEW Gmail App Password
define('SMTP_PASSWORD', 'drmhuagqcxajImig');

define('SMTP_DEBUG', 0); // Change to 2 while debugging

function sendSMTPMail($to, $subject, $message)
{
    $mail = new PHPMailer(true);

    try {

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        // Debug
        $mail->SMTPDebug = SMTP_DEBUG;
        $mail->Debugoutput = 'html';

        // Character Set
        $mail->CharSet = 'UTF-8';

        // SSL Options (Useful for XAMPP/Localhost)
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        // Sender
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);

        // Reply To
        $mail->addReplyTo(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);

        // Receiver
        $mail->addAddress($to);

        // Email Format
        $mail->isHTML(true);

        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = strip_tags($message);

        // Send Email
        $mail->send();

        return [
            'success' => true,
            'message' => 'Email sent successfully.'
        ];

    } catch (Exception $e) {

        error_log('PHPMailer Error: ' . $mail->ErrorInfo);

        return [
            'success' => false,
            'message' => $mail->ErrorInfo
        ];
    }
}
