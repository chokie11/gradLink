<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['pending_registration'])) {
    header("Location: register.php");
    exit;
}

$errors = [];
$email = $_SESSION['pending_registration']['email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');

    if ($otp === '') {
        $errors[] = 'Please enter the OTP code.';
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM email_verifications
            WHERE email = ? AND otp_code = ? AND is_verified = 0
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$email, $otp]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $errors[] = 'Invalid OTP code.';
        } elseif (strtotime($record['expires_at']) < time()) {
            $errors[] = 'OTP has expired. Please request a new code.';
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare("UPDATE email_verifications SET is_verified = 1 WHERE id = ?")
                    ->execute([$record['id']]);

                $data = $_SESSION['pending_registration'];

                $userStmt = $pdo->prepare("
                    INSERT INTO users (first_name, middle_name, last_name, extension_name, email, password, role, status, email_verified)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1)
                ");
                $userStmt->execute([
                    $data['first_name'],
                    ($data['middle_name'] ?? '') !== '' ? $data['middle_name'] : null,
                    $data['last_name'],
                    ($data['extension_name'] ?? '') !== '' ? $data['extension_name'] : null,
                    $data['email'],
                    $data['password'],
                    $data['role']
                ]);

                $user_id = $pdo->lastInsertId();

                if ($data['role'] === 'alumni') {
                    $profileStmt = $pdo->prepare("
                        INSERT INTO alumni_profiles (user_id, student_id, course, major, graduation_year)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $profileStmt->execute([
                        $user_id,
                        $data['student_id'],
                        $data['course'],
                        $data['major'],
                        $data['graduation_year']
                    ]);
                }

                if ($data['role'] === 'partner') {
                    $profileStmt = $pdo->prepare("
                        INSERT INTO partner_profiles (user_id, company_name, industry_type, contact_person)
                        VALUES (?, ?, ?, ?)
                    ");
                    $profileStmt->execute([
                        $user_id,
                        $data['company_name'],
                        $data['industry_type'],
                        $data['contact_person']
                    ]);
                }

                $pdo->commit();
                unset($_SESSION['pending_registration']);

                header("Location: login.php?registered=1");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP | GradLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #f8f3f3, #f1e6e6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
        }
        .otp-card {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border-radius: 24px;
            padding: 36px;
            box-shadow: 0 20px 60px rgba(128,0,0,.12);
        }
        .otp-badge {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 999px;
            background: #fff2f2;
            color: #800000;
            font-weight: 600;
            margin-bottom: 18px;
        }
        .otp-input {
            text-align: center;
            font-size: 30px;
            letter-spacing: 10px;
            border-radius: 16px;
            border: 1px solid #e7dcdc;
            padding: 14px;
        }
        .otp-input:focus {
            border-color: #800000;
            box-shadow: 0 0 0 .2rem rgba(128,0,0,.12);
        }
        .btn-maroon {
            background: #800000;
            color: #fff;
            border: none;
            border-radius: 14px;
            padding: 12px 18px;
            font-weight: 600;
        }
        .btn-maroon:hover {
            background: #650000;
            color: #fff;
        }
    </style>
    <link href="assets/css/loader.css" rel="stylesheet">
    <script src="assets/js/loader.js" defer></script>
</head>
<body>
    <div class="otp-card">
        <div class="otp-badge">Step 2 of 2 • Email Verification</div>
        <h2 class="fw-bold mb-2">Enter verification code</h2>
        <p class="text-muted mb-4">We sent a 6-digit code to <strong><?= htmlspecialchars($email) ?></strong></p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger rounded-4">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" data-loader-text="Verifying your code…">
            <div class="mb-3">
                <input type="text" name="otp" maxlength="6" class="form-control otp-input" placeholder="000000" required>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-maroon btn-lg">Verify & Create Account</button>
            </div>

            <div class="text-center mt-3">
                <a href="resend-otp.php" class="text-decoration-none fw-semibold" style="color:#800000;">Resend code</a>
            </div>
        </form>
    </div>
</body>
</html>