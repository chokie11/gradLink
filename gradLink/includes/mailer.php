<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendOtpEmail($toEmail, $toName, $otp)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // change if needed
        $mail->SMTPAuth   = true;
        $mail->Username   = 'noreply30211@gmail.com';
        $mail->Password   = 'lqiq ynok ngfh muvr';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('noreply30211@gmail.com', 'GradLink');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Your GradLink Verification Code';

        $logoUrl = 'https://pampangastateu.edu.ph/images/VPAA/VPAA%20Logos/Campuses/Mexico%20Campus%20LOGO.png';

        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Email Verification</title>
        </head>
        <body style="margin:0;padding:0;background:#f6f4f4;font-family:Arial,sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f4f4;padding:30px 0;">
                <tr>
                    <td align="center">
                        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 35px rgba(0,0,0,0.08);">
                            <tr>
                                <td style="background:linear-gradient(135deg,#800000,#5e0b0b);padding:32px;text-align:center;color:#fff;">
                                    <img src="' . $logoUrl . '" alt="Logo" style="width:90px;height:90px;object-fit:contain;background:#fff;border-radius:50%;padding:8px;">
                                    <h1 style="margin:18px 0 8px;font-size:26px;">Verify your email</h1>
                                    <p style="margin:0;font-size:15px;opacity:0.92;">Welcome to GradLink</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:36px 32px;color:#333;">
                                    <p style="margin:0 0 16px;font-size:16px;">Hello ' . htmlspecialchars($toName) . ',</p>
                                    <p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#555;">
                                        Use the verification code below to complete your registration.
                                        This code will expire in 5 minutes.
                                    </p>

                                    <div style="text-align:center;margin:28px 0;">
                                        <span style="display:inline-block;background:#fff5f5;border:2px dashed #800000;color:#800000;font-size:34px;letter-spacing:10px;font-weight:700;padding:18px 28px;border-radius:16px;">
                                            ' . $otp . '
                                        </span>
                                    </div>

                                    <p style="margin:0 0 12px;font-size:14px;color:#666;">
                                        If you did not request this code, you may safely ignore this email.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:20px 32px;background:#faf7f7;border-top:1px solid #eee;text-align:center;color:#888;font-size:12px;">
                                    GradLink • Pampanga State University Mexico Campus
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}


function sendApplicantNotification($toEmail, $applicantName, $jobTitle, $companyName, $status, $partnerNotes = '', $interviewDatetime = '', $rejectionReason = '') {
    $mail = new PHPMailer(true);

    try {
        //Server settings - usually set in mailer.php
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // change if needed
        $mail->SMTPAuth   = true;
        $mail->Username   = 'noreply30211@gmail.com';
        $mail->Password   = 'lqiq ynok ngfh muvr';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('noreply@yourdomain.com', 'Company Portal');
        $mail->addAddress($toEmail, $applicantName);

        $mail->isHTML(true);
        $mail->Subject = 'Application Status Updated';

        $body = "<p>Dear {$applicantName},</p>";
        $body .= "<p>Your application for <strong>{$jobTitle}</strong> at <strong>{$companyName}</strong> has been updated to <strong>{$status}</strong>.</p>";

        if ($status === 'Interview Scheduled' && $interviewDatetime) {
            $body .= "<p>Interview schedule: " . date('F j, Y g:i A', strtotime($interviewDatetime)) . "</p>";
        }

        if ($status === 'Rejected' && $rejectionReason) {
            $body .= "<p>Reason for rejection: {$rejectionReason}</p>";
        }

        if ($partnerNotes) {
            $body .= "<p>Partner notes: {$partnerNotes}</p>";
        }

        $body .= "<p>Thank you for applying.</p>";
        $mail->Body = $body;

        $mail->send();
        // Optionally log or set a session flash for success
    } catch (Exception $e) {
        // Optionally log errors: $mail->ErrorInfo
    }
}

function sendSupportRequest($fromEmail, $fromName, $role, $category, $subject, $message)
{
    require_once __DIR__ . '/../config/support.php';

    // Strip line breaks from header fields
    $clean = function ($v) { return trim(preg_replace('/[\r\n]+/', ' ', (string) $v)); };
    $fromName = $clean($fromName);
    $subject  = $clean($subject);
    $category = $clean($category);

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'noreply30211@gmail.com';
        $mail->Password   = 'lqiq ynok ngfh muvr';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('noreply30211@gmail.com', 'GradLink Support');
        $mail->addAddress(SUPPORT_EMAIL);
        $mail->addReplyTo($fromEmail, $fromName);

        $mail->isHTML(true);
        $mail->Subject = '[GradLink Support] ' . $category . ' - ' . $subject;

        $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

        $mail->Body = '
        <div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;border:1px solid #eadede;border-radius:16px;overflow:hidden;">
            <div style="background:linear-gradient(135deg,#800000,#5e0b0b);color:#fff;padding:22px 26px;">
                <h2 style="margin:0;font-size:20px;">New support request</h2>
                <p style="margin:6px 0 0;opacity:.85;font-size:14px;">' . $e($category) . '</p>
            </div>
            <div style="padding:24px 26px;color:#333;font-size:14px;line-height:1.7;">
                <p style="margin:0 0 4px;"><strong>From:</strong> ' . $e($fromName) . ' (' . $e($fromEmail) . ')</p>
                <p style="margin:0 0 4px;"><strong>Role:</strong> ' . $e(ucfirst($role)) . '</p>
                <p style="margin:0 0 16px;"><strong>Subject:</strong> ' . $e($subject) . '</p>
                <div style="background:#fff5f5;border:1px solid #f0dddd;border-radius:12px;padding:16px;">' . nl2br($e($message)) . '</div>
            </div>
        </div>';
        $mail->AltBody = "From: $fromName <$fromEmail>\nRole: $role\nSubject: $subject\n\n$message";

        return $mail->send();
    } catch (Exception $ex) {
        return false;
    }
}
