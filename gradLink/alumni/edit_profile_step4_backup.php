<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

$stmt = $pdo->prepare("
    SELECT u.first_name, u.last_name, u.email, ap.*
    FROM users u
    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    die('Profile not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $major = trim($_POST['major'] ?? '');
    $graduation_year = trim($_POST['graduation_year'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $employment_status = trim($_POST['employment_status'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $preferred_job_titles = trim($_POST['preferred_job_titles'] ?? '');
    $career_interests = trim($_POST['career_interests'] ?? '');
    $certifications = trim($_POST['certifications'] ?? '');
    $work_experience = trim($_POST['work_experience'] ?? '');
    $preferred_location = trim($_POST['preferred_location'] ?? '');
    $preferred_work_setup = trim($_POST['preferred_work_setup'] ?? '');
    $expected_salary = trim($_POST['expected_salary'] ?? '');
    $available_start_date = trim($_POST['available_start_date'] ?? '');
    $linkedin_url = trim($_POST['linkedin_url'] ?? '');
    $portfolio_url = trim($_POST['portfolio_url'] ?? '');

    if ($first_name === '' || $last_name === '') {
        $errors[] = 'First name and last name are required.';
    }

    if ($student_id === '' || $course === '' || $graduation_year === '') {
        $errors[] = 'Student ID, course, and graduation year are required.';
    }

    $coursesWithMajor = ['BSEd', 'BPEd', 'BTLEd', 'BSBA', 'BSIndTech'];

    if (in_array($course, $coursesWithMajor) && $major === '') {
        $errors[] = 'Please select a major for your course.';
    }

    if (!empty($graduation_year) && !preg_match('/^\d{4}$/', $graduation_year)) {
        $errors[] = 'Graduation year must be a valid 4-digit year.';
    }

    if ($linkedin_url !== '' && !filter_var($linkedin_url, FILTER_VALIDATE_URL)) {
        $errors[] = 'LinkedIn URL must be a valid link.';
    }

    if ($portfolio_url !== '' && !filter_var($portfolio_url, FILTER_VALIDATE_URL)) {
        $errors[] = 'Portfolio URL must be a valid link.';
    }

    if ($available_start_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $available_start_date)) {
        $errors[] = 'Available start date must be a valid date.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $userStmt = $pdo->prepare("
                UPDATE users
                SET first_name = ?, last_name = ?
                WHERE id = ?
            ");
            $userStmt->execute([$first_name, $last_name, $user_id]);

            $profileStmt = $pdo->prepare("
                UPDATE alumni_profiles
                SET student_id = ?,
                    course = ?,
                    major = ?,
                    graduation_year = ?,
                    phone = ?,
                    address = ?,
                    employment_status = ?,
                    bio = ?,
                    skills = ?,
                    preferred_job_titles = ?,
                    career_interests = ?,
                    certifications = ?,
                    work_experience = ?,
                    preferred_location = ?,
                    preferred_work_setup = ?,
                    expected_salary = ?,
                    available_start_date = ?,
                    linkedin_url = ?,
                    portfolio_url = ?,
                    ai_profile_summary = NULL,
                    ai_career_tags = NULL
                WHERE user_id = ?
            ");
            $profileStmt->execute([
                $student_id,
                $course,
                $major !== '' ? $major : null,
                $graduation_year,
                $phone !== '' ? $phone : null,
                $address !== '' ? $address : null,
                $employment_status !== '' ? $employment_status : null,
                $bio !== '' ? $bio : null,
                $skills !== '' ? $skills : null,
                $preferred_job_titles !== '' ? $preferred_job_titles : null,
                $career_interests !== '' ? $career_interests : null,
                $certifications !== '' ? $certifications : null,
                $work_experience !== '' ? $work_experience : null,
                $preferred_location !== '' ? $preferred_location : null,
                $preferred_work_setup !== '' ? $preferred_work_setup : null,
                $expected_salary !== '' ? $expected_salary : null,
                $available_start_date !== '' ? $available_start_date : null,
                $linkedin_url !== '' ? $linkedin_url : null,
                $portfolio_url !== '' ? $portfolio_url : null,
                $user_id
            ]);

            $pdo->commit();

            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;

            $success = 'Profile updated successfully.';

            $stmt = $pdo->prepare("
                SELECT u.first_name, u.last_name, u.email, ap.*
                FROM users u
                LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while updating your profile.';
        }
    }
}

$fieldsToCheck = [
    $profile['student_id'] ?? '',
    $profile['course'] ?? '',
    $profile['graduation_year'] ?? '',
    $profile['phone'] ?? '',
    $profile['address'] ?? '',
    $profile['employment_status'] ?? '',
    $profile['bio'] ?? '',
    $profile['skills'] ?? '',
    $profile['preferred_job_titles'] ?? '',
    $profile['career_interests'] ?? '',
    $profile['work_experience'] ?? '',
    $profile['preferred_location'] ?? '',
    $profile['preferred_work_setup'] ?? ''
];

$filled = 0;
foreach ($fieldsToCheck as $field) {
    if (!empty(trim((string)$field))) {
        $filled++;
    }
}
$completion = round(($filled / count($fieldsToCheck)) * 100);

$coursesWithMajor = ['BSEd', 'BPEd', 'BTLEd', 'BSBA', 'BSIndTech'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Alumni Profile | GradLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8f5f5 0%, #f3ebeb 100%);
            min-height: 100vh;
        }

        .profile-wrapper {
            padding: 40px 0;
        }

        .profile-card {
            border: 0;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(128, 0, 0, 0.10);
        }

        .profile-side {
            background: linear-gradient(160deg, var(--maroon) 0%, var(--dark-maroon) 100%);
            color: #fff;
            padding: 40px;
            height: 100%;
        }

        .profile-badge {
            display: inline-block;
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.15);
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .profile-side h1 {
            font-size: 2rem;
            font-weight: 700;
        }

        .profile-side p {
            opacity: .92;
        }

        .profile-panel {
            background: #fff;
            padding: 40px;
        }

        .section-box {
            background: #fcfafa;
            border: 1px solid #f0e1e1;
            border-radius: 18px;
            padding: 20px;
            margin-top: 20px;
        }

        .section-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--maroon);
            margin-bottom: 14px;
        }

        .form-control,
        .form-select,
        textarea.form-control {
            border-radius: 14px;
            padding: 12px 14px;
            border: 1px solid #e7dcdc;
        }

        .form-control:focus,
        .form-select:focus,
        textarea.form-control:focus {
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

        .progress {
            height: 12px;
            border-radius: 999px;
            background: #f0dede;
        }

        .progress-bar {
            background: linear-gradient(90deg, #f8c51c, #e7a60f);
            border-radius: 999px;
        }

        .mini-stat {
            background: rgba(255,255,255,0.12);
            border-radius: 16px;
            padding: 16px;
            margin-top: 18px;
        }

        @media (max-width: 991px) {
            .profile-panel,
            .profile-side {
                padding: 28px;
            }
        }
    </style>
</head>
<body>
<div class="container profile-wrapper">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">
            <div class="card profile-card">
                <div class="row g-0">
                    <div class="col-lg-4">
                        <div class="profile-side">
                            <span class="profile-badge">Alumni Profile Setup</span>
                            <h1>Complete your profile</h1>
                            <p class="mt-3 mb-4">
                                Fill in your alumni information to continue to your dashboard and unlock the next features.
                            </p>

                            <div class="mini-stat">
                                <div class="small text-white-50 mb-1">Profile Completion</div>
                                <div class="h4 mb-2"><?= $completion ?>%</div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?= $completion ?>%"></div>
                                </div>
                            </div>

                            <div class="mini-stat">
                                <div class="small text-white-50 mb-1">Account Email</div>
                                <div class="fw-semibold"><?= htmlspecialchars($profile['email']) ?></div>
                            </div>

                            <div class="mini-stat">
                                <div class="small text-white-50 mb-1">Quick Tip</div>
                                <div>Add skills, career interests, and preferred roles so AI can recommend better job matches.</div>
                            </div>

                            <a href="dashboard.php" class="btn btn-light w-100 mt-4 rounded-4 fw-semibold">Go to Dashboard</a>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="profile-panel">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <div>
                                    <h2 class="fw-bold mb-1">Edit Alumni Profile</h2>
                                    <p class="text-muted mb-0">Update your academic, personal, and career details for smarter job matching.</p>
                                </div>
                                <a href="../logout.php" class="text-decoration-none fw-semibold" style="color: var(--maroon);">Logout</a>
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
                                    <?= htmlspecialchars($success) ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="">
                                <div class="section-box mt-0">
                                    <div class="section-title">Basic Information</div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">First Name</label>
                                            <input type="text" name="first_name" class="form-control"
                                                   value="<?= htmlspecialchars($profile['first_name'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Last Name</label>
                                            <input type="text" name="last_name" class="form-control"
                                                   value="<?= htmlspecialchars($profile['last_name'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Email Address</label>
                                            <input type="email" class="form-control"
                                                   value="<?= htmlspecialchars($profile['email'] ?? '') ?>" disabled>
                                        </div>
                                    </div>
                                </div>

                                <div class="section-box">
                                    <div class="section-title">Academic Information</div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Student ID</label>
                                            <input type="text" name="student_id" class="form-control"
                                                   value="<?= htmlspecialchars($profile['student_id'] ?? '') ?>" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Course</label>
                                            <select name="course" id="course" class="form-select" required>
                                                <option value="">Select Course</option>
                                                <option value="BSEd" <?= (($profile['course'] ?? '') === 'BSEd') ? 'selected' : '' ?>>Bachelor of Secondary Education</option>
                                                <option value="BEEd" <?= (($profile['course'] ?? '') === 'BEEd') ? 'selected' : '' ?>>Bachelor of Elementary Education</option>
                                                <option value="BPEd" <?= (($profile['course'] ?? '') === 'BPEd') ? 'selected' : '' ?>>Bachelor of Physical Education</option>
                                                <option value="BTLEd" <?= (($profile['course'] ?? '') === 'BTLEd') ? 'selected' : '' ?>>Bachelor of Technology and Livelihood Education</option>
                                                <option value="BSA" <?= (($profile['course'] ?? '') === 'BSA') ? 'selected' : '' ?>>Bachelor of Science in Accountancy</option>
                                                <option value="BSBA" <?= (($profile['course'] ?? '') === 'BSBA') ? 'selected' : '' ?>>Bachelor of Science in Business Administration</option>
                                                <option value="BSIT" <?= (($profile['course'] ?? '') === 'BSIT') ? 'selected' : '' ?>>Bachelor of Science in Information Technology</option>
                                                <option value="BSHM" <?= (($profile['course'] ?? '') === 'BSHM') ? 'selected' : '' ?>>Bachelor of Science in Hospitality Management</option>
                                                <option value="BSIndTech" <?= (($profile['course'] ?? '') === 'BSIndTech') ? 'selected' : '' ?>>Bachelor of Science in Industrial Technology</option>
                                            </select>
                                        </div>

                                        <div class="col-md-6" id="major-container" style="<?= in_array(($profile['course'] ?? ''), $coursesWithMajor) ? 'display:block;' : 'display:none;' ?>">
                                            <label class="form-label">Major / Specialization</label>
                                            <select name="major" id="major" class="form-select">
                                                <option value="">Select Major</option>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Graduation Year</label>
                                            <input type="number" name="graduation_year" min="2000" max="2099" class="form-control"
                                                   value="<?= htmlspecialchars($profile['graduation_year'] ?? '') ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="section-box">
                                    <div class="section-title">Personal Information</div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Phone Number</label>
                                            <input type="text" name="phone" class="form-control"
                                                   value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Employment Status</label>
                                            <select name="employment_status" class="form-select">
                                                <option value="">Select Status</option>
                                                <option value="Employed" <?= (($profile['employment_status'] ?? '') === 'Employed') ? 'selected' : '' ?>>Employed</option>
                                                <option value="Unemployed" <?= (($profile['employment_status'] ?? '') === 'Unemployed') ? 'selected' : '' ?>>Unemployed</option>
                                                <option value="Self-Employed" <?= (($profile['employment_status'] ?? '') === 'Self-Employed') ? 'selected' : '' ?>>Self-Employed</option>
                                                <option value="Student" <?= (($profile['employment_status'] ?? '') === 'Student') ? 'selected' : '' ?>>Student</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Address</label>
                                            <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($profile['address'] ?? '') ?></textarea>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Short Bio</label>
                                            <textarea name="bio" class="form-control" rows="4" placeholder="Tell us a little about yourself..."><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <div class="section-box">
                                    <div class="section-title">Career Profile for AI Job Matching</div>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Skills</label>
                                            <textarea name="skills" class="form-control" rows="3" placeholder="Example: PHP, MySQL, HTML, CSS, teaching, bookkeeping, customer service"><?= htmlspecialchars($profile['skills'] ?? '') ?></textarea>
                                            <div class="form-text">Separate skills with commas. This is one of the most important fields for AI matching.</div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Preferred Job Titles</label>
                                            <textarea name="preferred_job_titles" class="form-control" rows="3" placeholder="Example: Web Developer, IT Support, Teacher, Marketing Assistant"><?= htmlspecialchars($profile['preferred_job_titles'] ?? '') ?></textarea>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Career Interests</label>
                                            <textarea name="career_interests" class="form-control" rows="3" placeholder="Example: software development, education, business, hospitality"><?= htmlspecialchars($profile['career_interests'] ?? '') ?></textarea>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Certifications / Trainings</label>
                                            <textarea name="certifications" class="form-control" rows="3" placeholder="Example: NC II, TESDA, Google certificate, seminars"><?= htmlspecialchars($profile['certifications'] ?? '') ?></textarea>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Work Experience</label>
                                            <textarea name="work_experience" class="form-control" rows="3" placeholder="Example: 6 months internship as IT support, 1 year sales associate"><?= htmlspecialchars($profile['work_experience'] ?? '') ?></textarea>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Preferred Location</label>
                                            <input type="text" name="preferred_location" class="form-control" placeholder="Example: San Pablo City, Laguna, Remote"
                                                   value="<?= htmlspecialchars($profile['preferred_location'] ?? '') ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Preferred Work Setup</label>
                                            <select name="preferred_work_setup" class="form-select">
                                                <option value="">Select Work Setup</option>
                                                <option value="Onsite" <?= (($profile['preferred_work_setup'] ?? '') === 'Onsite') ? 'selected' : '' ?>>Onsite</option>
                                                <option value="Remote" <?= (($profile['preferred_work_setup'] ?? '') === 'Remote') ? 'selected' : '' ?>>Remote</option>
                                                <option value="Hybrid" <?= (($profile['preferred_work_setup'] ?? '') === 'Hybrid') ? 'selected' : '' ?>>Hybrid</option>
                                                <option value="Flexible" <?= (($profile['preferred_work_setup'] ?? '') === 'Flexible') ? 'selected' : '' ?>>Flexible</option>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Expected Salary</label>
                                            <input type="text" name="expected_salary" class="form-control" placeholder="Example: 18,000 - 25,000"
                                                   value="<?= htmlspecialchars($profile['expected_salary'] ?? '') ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Available Start Date</label>
                                            <input type="date" name="available_start_date" class="form-control"
                                                   value="<?= htmlspecialchars($profile['available_start_date'] ?? '') ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">LinkedIn URL</label>
                                            <input type="url" name="linkedin_url" class="form-control" placeholder="https://www.linkedin.com/in/yourname"
                                                   value="<?= htmlspecialchars($profile['linkedin_url'] ?? '') ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Portfolio URL</label>
                                            <input type="url" name="portfolio_url" class="form-control" placeholder="https://your-portfolio.com"
                                                   value="<?= htmlspecialchars($profile['portfolio_url'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid mt-4">
                                    <button type="submit" class="btn btn-maroon btn-lg">Save Profile</button>
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
    const courseSelect = document.getElementById('course');
    const majorSelect = document.getElementById('major');
    const majorContainer = document.getElementById('major-container');
    const savedMajor = "<?= htmlspecialchars($profile['major'] ?? '', ENT_QUOTES) ?>";

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

    function updateMajorOptions() {
        const selected = courseSelect.value;
        majorSelect.innerHTML = '<option value="">Select Major</option>';

        if (majors[selected]) {
            majorContainer.style.display = 'block';

            majors[selected].forEach(function (major) {
                const option = document.createElement('option');
                option.value = major;
                option.textContent = major;

                if (major === savedMajor) {
                    option.selected = true;
                }

                majorSelect.appendChild(option);
            });
        } else {
            majorContainer.style.display = 'none';
        }
    }

    courseSelect.addEventListener('change', updateMajorOptions);
    updateMajorOptions();
</script>
</body>
</html>