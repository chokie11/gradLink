<?php
session_start();
require_once 'config/database.php';
require_once 'includes/mailer.php';
require_once 'includes/profile_helpers.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = trim($_POST['role'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $extension_name = cleanExtensionName($_POST['extension_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $student_id = trim($_POST['student_id'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $graduation_year = trim($_POST['graduation_year'] ?? '');
    $major = trim($_POST['major'] ?? '');

    $company_name = trim($_POST['company_name'] ?? '');
    $industry_type = trim($_POST['industry_type'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');

    if (!in_array($role, ['alumni', 'partner'])) {
        $errors[] = 'Please select a valid account type.';
    }

    if ($first_name === '' || $last_name === '' || $email === '' || $password === '' || $confirm_password === '') {
        $errors[] = 'Please fill in all required fields.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    if ($role === 'alumni') {
        if ($student_id === '' || $course === '' || $graduation_year === '') {
            $errors[] = 'Please complete all alumni fields.';
        }

        $coursesWithMajor = ['BSEd', 'BPEd', 'BTLEd', 'BSBA', 'BSIndTech'];
        if (in_array($course, $coursesWithMajor) && $major === '') {
            $errors[] = 'Please select a major for your course.';
        }
    }

    if ($role === 'partner') {
        if ($company_name === '' || $industry_type === '' || $contact_person === '') {
            $errors[] = 'Please complete all industry partner fields.';
        }
    }

    if (empty($errors)) {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);

        if ($checkStmt->fetch()) {
            $errors[] = 'Email is already registered.';
        } else {
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            $_SESSION['pending_registration'] = [
                'role' => $role,
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'last_name' => $last_name,
                'extension_name' => $extension_name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'student_id' => $student_id,
                'course' => $course,
                'major' => $major,
                'graduation_year' => $graduation_year,
                'company_name' => $company_name,
                'industry_type' => $industry_type,
                'contact_person' => $contact_person
            ];

            $pdo->prepare("DELETE FROM email_verifications WHERE email = ?")->execute([$email]);

            $stmt = $pdo->prepare("
                INSERT INTO email_verifications (email, otp_code, expires_at, is_verified)
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$email, $otp, $expiresAt]);

            $sent = sendOtpEmail($email, formatFullName($first_name, $middle_name, $last_name, $extension_name), $otp);

            if ($sent) {
                header("Location: verify-otp.php");
                exit;
            } else {
                $errors[] = 'Failed to send OTP email. Please try again.';
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
    <title>Register | GradLink</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8f5f5 0%, #f3ebeb 100%);
            min-height: 100vh;
        }

        .register-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 40px 0;
        }

        .register-card {
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
            position: relative;
        }

        .brand-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.15);
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
            opacity: .9;
            margin-top: 14px;
        }

        .brand-feature {
            background: rgba(255, 255, 255, 0.10);
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

        .role-choice {
            border: 1px solid #eadede;
            border-radius: 18px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.25s ease;
            background: #fff;
        }

        .role-choice:hover {
            border-color: var(--maroon);
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(128, 0, 0, 0.08);
        }

        .role-choice.active {
            border: 2px solid var(--maroon);
            background: #fff8f8;
            box-shadow: 0 10px 24px rgba(128, 0, 0, 0.10);
        }

        .role-choice input {
            display: none;
        }

        .role-label {
            font-weight: 600;
            color: #2b2b2b;
            margin-bottom: 4px;
        }

        .role-subtext {
            font-size: 14px;
            color: #6c757d;
            margin: 0;
        }

        .form-control,
        .form-select {
            border-radius: 14px;
            padding: 12px 14px;
            border: 1px solid #e7dcdc;
        }

        .form-control:focus,
        .form-select:focus {
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

        .section-box {
            background: #fcfafa;
            border: 1px solid #f0e1e1;
            border-radius: 18px;
            padding: 20px;
            margin-top: 18px;
        }

        .section-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--maroon);
            margin-bottom: 14px;
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

    <div class="container register-wrapper">
        <div class="row w-100 justify-content-center">
            <div class="col-lg-11 col-xl-10">
                <div class="card register-card">
                    <div class="row g-0">
                        <div class="col-lg-5 d-none d-lg-block">
                            <div class="brand-panel">
                                    <img src="assets/logo.png" alt="GradLink Logo" width="50px">
                                <span class="brand-badge">Welcome!</span>
                                <h1 class="brand-title">Create your GradLink account</h1>
                                <p class="brand-text">
                                    Start with the right role, then complete your profile after your first login.
                                </p>

                                <div class="brand-feature">
                                    <strong>Alumni</strong><br>
                                    Register with your academic details and build your profile.
                                </div>

                                <div class="brand-feature">
                                    <strong>Industry Partner</strong><br>
                                    Register your company and set up your partner account.
                                </div>

                                <!-- <div class="brand-feature">
                                    <strong>Modern UX</strong><br>
                                    Clean maroon interface, guided registration, and profile completion flow.
                                </div> -->
                            </div>
                        </div>

                        <div class="col-lg-7">
                            <div class="form-panel">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <div>
                                        <h2 class="form-title mb-1">Register</h2>
                                        <p class="text-muted mb-0">Choose your role to continue.</p>
                                    </div>
                                    <a href="login.php" class="text-decoration-none fw-semibold"
                                        style="color:#800000;">Sign in</a>
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

                                <?php if ($success): ?>
                                    <div class="alert alert-success rounded-4">
                                        <?= htmlspecialchars($success) ?> <a href="login.php" class="fw-semibold">Login
                                            here</a>.
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="" data-loader-text="Creating your account and sending the verification code…">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Choose account type</label>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="role-choice w-100" id="card-alumni">
                                                    <input type="radio" name="role" value="alumni" <?= (($_POST['role'] ?? 'alumni') === 'alumni') ? 'checked' : '' ?>>
                                                    <div class="role-label">Alumni</div>
                                                    <p class="role-subtext">For graduates looking to manage their
                                                        profile.</p>
                                                </label>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="role-choice w-100" id="card-partner">
                                                    <input type="radio" name="role" value="partner" <?= (($_POST['role'] ?? 'alumni') === 'partner') ? 'checked' : '' ?>>
                                                    <div class="role-label">Industry Partner</div>
                                                    <p class="role-subtext">For companies and recruiters posting
                                                        opportunities.</p>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="section-box">
                                        <div class="section-title">Basic account information</div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                                <input type="text" name="first_name" class="form-control"
                                                    value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
                                                    required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Middle Name <span class="text-muted small">(optional)</span></label>
                                                <input type="text" name="middle_name" class="form-control"
                                                    value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-8">
                                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                                <input type="text" name="last_name" class="form-control"
                                                    value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Extension <span class="text-muted small">(optional)</span></label>
                                                <select name="extension_name" class="form-select">
                                                    <option value="">None</option>
                                                    <?php foreach (NAME_EXTENSIONS as $ext): ?>
                                                        <option value="<?= htmlspecialchars($ext) ?>" <?= (($_POST['extension_name'] ?? '') === $ext) ? 'selected' : '' ?>><?= htmlspecialchars($ext) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Email Address</label>
                                                <input type="email" name="email" class="form-control"
                                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Password</label>
                                                <input type="password" name="password" class="form-control" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Confirm Password</label>
                                                <input type="password" name="confirm_password" class="form-control"
                                                    required>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="alumni-fields" class="section-box"
                                        style="<?= (($_POST['role'] ?? 'alumni') === 'alumni') ? 'display:block;' : 'display:none;' ?>">
                                        <div class="section-title">Alumni information</div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Student ID</label>
                                                <input type="text" name="student_id" class="form-control"
                                                    value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Course</label>
                                                <select name="course" id="course" class="form-select">
                                                    <option value="">Select Course</option>

                                                    <option value="BSEd" <?= (($_POST['course'] ?? '') === 'BSEd') ? 'selected' : '' ?>>Bachelor of Secondary Education</option>
                                                    <option value="BEEd" <?= (($_POST['course'] ?? '') === 'BEEd') ? 'selected' : '' ?>>Bachelor of Elementary Education</option>
                                                    <option value="BPEd" <?= (($_POST['course'] ?? '') === 'BPEd') ? 'selected' : '' ?>>Bachelor of Physical Education</option>
                                                    <option value="BTLEd" <?= (($_POST['course'] ?? '') === 'BTLEd') ? 'selected' : '' ?>>Bachelor of Technology and Livelihood
                                                        Education</option>
                                                    <option value="BSA" <?= (($_POST['course'] ?? '') === 'BSA') ? 'selected' : '' ?>>Bachelor of Science in Accountancy</option>
                                                    <option value="BSBA" <?= (($_POST['course'] ?? '') === 'BSBA') ? 'selected' : '' ?>>Bachelor of Science in Business
                                                        Administration</option>
                                                    <option value="BSIT" <?= (($_POST['course'] ?? '') === 'BSIT') ? 'selected' : '' ?>>Bachelor of Science in Information Technology
                                                    </option>
                                                    <option value="BSHM" <?= (($_POST['course'] ?? '') === 'BSHM') ? 'selected' : '' ?>>Bachelor of Science in Hospitality Management
                                                    </option>
                                                    <option value="BSIndTech" <?= (($_POST['course'] ?? '') === 'BSIndTech') ? 'selected' : '' ?>>Bachelor of Science in
                                                        Industrial Technology</option>
                                                </select>
                                            </div>

                                            <div class="col-md-6" id="major-container" style="display:none;">
                                                <label class="form-label">Major / Specialization</label>
                                                <select name="major" id="major" class="form-select">
                                                    <option value="">Select Major</option>
                                                </select>
                                            </div>

                                            <script>
                                                const courseSelect = document.getElementById('course');
                                                const majorSelect = document.getElementById('major');
                                                const majorContainer = document.getElementById('major-container');

                                                const majors = {
                                                    "BSEd": [
                                                        "Major in Filipino",
                                                        "Major in English"
                                                    ],
                                                    "BPEd": [
                                                        "Major in School Physical Education"
                                                    ],
                                                    "BTLEd": [
                                                        "Major in Home Economics"
                                                    ],
                                                    "BSBA": [
                                                        "Major in Marketing"
                                                    ],
                                                    "BSIndTech": [
                                                        "Major in Automotive Technology",
                                                        "Major in Electrical Technology",
                                                        "Major in Graphics Technology"
                                                    ]
                                                };

                                                courseSelect.addEventListener('change', function () {
                                                    const selected = this.value;

                                                    majorSelect.innerHTML = '<option value="">Select Major</option>';

                                                    if (majors[selected]) {
                                                        majorContainer.style.display = 'block';

                                                        majors[selected].forEach(function (major) {
                                                            const option = document.createElement('option');
                                                            option.value = major;
                                                            option.textContent = major;
                                                            majorSelect.appendChild(option);
                                                        });

                                                    } else {
                                                        majorContainer.style.display = 'none';
                                                    }
                                                });
                                            </script>
                                            <div class="col-12">
                                                <label class="form-label">Graduation Year</label>
                                                <input type="number" name="graduation_year" min="2000" max="2099"
                                                    class="form-control"
                                                    value="<?= htmlspecialchars($_POST['graduation_year'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div id="partner-fields" class="section-box"
                                        style="<?= (($_POST['role'] ?? 'alumni') === 'partner') ? 'display:block;' : 'display:none;' ?>">
                                        <div class="section-title">Industry partner information</div>
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label">Company Name</label>
                                                <input type="text" name="company_name" class="form-control"
                                                    value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Industry Type</label>
                                                <input type="text" name="industry_type" class="form-control"
                                                    value="<?= htmlspecialchars($_POST['industry_type'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Contact Person</label>
                                                <input type="text" name="contact_person" class="form-control"
                                                    value="<?= htmlspecialchars($_POST['contact_person'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-grid mt-4">
                                        <button type="submit" class="btn btn-maroon btn-lg">Create Account</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const alumniCard = document.getElementById('card-alumni');
        const partnerCard = document.getElementById('card-partner');
        const alumniFields = document.getElementById('alumni-fields');
        const partnerFields = document.getElementById('partner-fields');
        const roleInputs = document.querySelectorAll('input[name="role"]');

        function updateRoleUI() {
            const selected = document.querySelector('input[name="role"]:checked');

            alumniCard.classList.remove('active');
            partnerCard.classList.remove('active');
            alumniFields.style.display = 'none';
            partnerFields.style.display = 'none';

            if (!selected) return;

            if (selected.value === 'alumni') {
                alumniCard.classList.add('active');
                alumniFields.style.display = 'block';
            }

            if (selected.value === 'partner') {
                partnerCard.classList.add('active');
                partnerFields.style.display = 'block';
            }
        }

        roleInputs.forEach(input => {
            input.addEventListener('change', updateRoleUI);
        });

        updateRoleUI();
    </script>
</body>

</html>