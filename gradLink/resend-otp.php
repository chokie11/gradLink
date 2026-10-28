<?php
session_start();
require_once 'config/database.php';
require_once 'includes/mailer.php';

if (!isset($_SESSION['pending_registration'])) {
    header("Location: register.php");
    exit;
}

$data = $_SESSION['pending_registration'];
$email = $data['email'];
$name = $data['first_name'] . ' ' . $data['last_name'];

$otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

$pdo->prepare("DELETE FROM email_verifications WHERE email = ?")->execute([$email]);
$stmt = $pdo->prepare("
    INSERT INTO email_verifications (email, otp_code, expires_at, is_verified)
    VALUES (?, ?, ?, 0)
");
$stmt->execute([$email, $otp, $expiresAt]);

sendOtpEmail($email, $name, $otp);

header("Location: verify-otp.php");
exit;