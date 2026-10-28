<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    header("Location: redirect.php");
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter your email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Invalid email or password.';
        } elseif (($user['status'] ?? 'active') !== 'active') {
            $errors[] = 'Your account is not active.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            unset($_SESSION['profile_photo']);

            header("Location: redirect.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | GradLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8f5f5 0%, #f3ebeb 100%);
            min-height: 100vh;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 40px 0;
        }

        .login-card {
            border: 0;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(128, 0, 0, 0.12);
        }

        .brand-panel {
            background: linear-gradient(160deg, var(--maroon) 0%, var(--dark-maroon) 100%);
            color: #fff;
            padding: 48px;
            height: 100%;
        }

        .brand-badge {
            display: inline-block;
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.15);
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .brand-title {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .brand-text {
            opacity: .92;
            margin-top: 14px;
        }

        .brand-feature {
            background: rgba(255,255,255,0.10);
            border-radius: 16px;
            padding: 14px 16px;
            margin-top: 14px;
        }

        .form-panel {
            background: #fff;
            padding: 40px;
        }

        .form-title {
            font-weight: 700;
            color: #2b2b2b;
        }

        .form-control {
            border-radius: 14px;
            padding: 12px 14px;
            border: 1px solid #e7dcdc;
        }

        .form-control:focus {
            border-color: var(--maroon);
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.12);
        }

        .btn-maroon {
            background: var(--maroon);
            color: #fff;
            border: none;
            border-radius: 14px;
            padding: 12px 18px;
            font-weight: 600;
        }

        .btn-maroon:hover {
            background: var(--dark-maroon);
            color: #fff;
        }

        .input-label {
            font-weight: 600;
            margin-bottom: 8px;
        }

        @media (max-width: 991px) {
            .brand-panel {
                padding: 32px;
            }

            .form-panel {
                padding: 28px;
            }
        }
    </style>
    <link href="assets/css/loader.css" rel="stylesheet">
    <script src="assets/js/loader.js" defer></script>
</head>
<body>
    <div class="container login-wrapper">
        <div class="row w-100 justify-content-center">
            <div class="col-lg-10 col-xl-9">
                <div class="card login-card">
                    <div class="row g-0">
                        <div class="col-lg-5 d-none d-lg-block">
                            <div class="brand-panel">
                                <img src="assets/logo.png" alt="GradLink Logo" width="50px">
                                <span class="brand-badge">Welcome Back</span>
                                <h1 class="brand-title">Sign in to GradLink</h1>
                                <p class="brand-text">
                                    Access your alumni or industry partner account and continue setting up your profile.
                                </p>

                                <div class="brand-feature">
                                    <strong>Alumni Portal</strong><br>
                                    Manage your academic and personal profile.
                                </div>

                                <div class="brand-feature">
                                    <strong>Partner Portal</strong><br>
                                    Access your company account and recruitment tools.
                                </div>

                                <!-- <div class="brand-feature">
                                    <strong>Smart Flow</strong><br>
                                    Incomplete profiles will be guided to the profile setup page automatically.
                                </div> -->
                            </div>
                        </div>

                        <div class="col-lg-7">
                            <div class="form-panel">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <div>
                                        <h2 class="form-title mb-1">Login</h2>
                                        <p class="text-muted mb-0">Enter your account credentials.</p>
                                    </div>
                                    <a href="register.php" class="text-decoration-none fw-semibold" style="color: var(--maroon);">
                                        Create account
                                    </a>
                                </div>

                                <?php if (!empty($errors)): ?>
                                    <div class="alert alert-danger rounded-4">
                                        <ul class="mb-0">
                                            <?php foreach ($errors as $error): ?>
                                                <li><?= htmlspecialchars($error) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="" data-loader-text="Signing you in…">
                                    <div class="mb-3">
                                        <label class="input-label">Email Address</label>
                                        <input
                                            type="email"
                                            name="email"
                                            class="form-control"
                                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                            placeholder="Enter your email"
                                            required
                                        >
                                    </div>

                                    <div class="mb-3">
                                        <label class="input-label">Password</label>
                                        <input
                                            type="password"
                                            name="password"
                                            class="form-control"
                                            placeholder="Enter your password"
                                            required
                                        >
                                    </div>

                                    <div class="d-grid mt-4">
                                        <button type="submit" class="btn btn-maroon btn-lg">Sign In</button>
                                    </div>
                                </form>

                                <p class="text-muted text-center mt-4 mb-0">
                                    Don’t have an account?
                                    <a href="register.php" class="fw-semibold text-decoration-none" style="color: var(--maroon);">
                                        Register here
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
     </div>
</body>
</html>