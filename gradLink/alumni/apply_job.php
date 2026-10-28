<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

require_once '../includes/resume_text_extractor.php';
require_once '../includes/resume_analyzer.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];

function markJobInvitationApplied(PDO $pdo, int $alumniId, int $jobId): void
{
    try {
        $stmt = $pdo->prepare("
            UPDATE job_invitations
            SET status = 'Applied'
            WHERE alumni_id = ?
              AND job_post_id = ?
        ");
        $stmt->execute([$alumniId, $jobId]);
    } catch (PDOException $e) {
        // Keep the normal application flow working even if the invitation table is not installed yet.
    }
}
$job_id = $_GET['id'] ?? ($_POST['job_id'] ?? null);

if (!$job_id || !ctype_digit((string) $job_id)) {
    header('Location: recommended_jobs.php');
    exit;
}

$jobStmt = $pdo->prepare("
    SELECT jp.*, pp.company_name
    FROM job_posts jp
    INNER JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    WHERE jp.id = ?
      AND jp.status = 'Open'
      AND (jp.deadline IS NULL OR jp.deadline >= CURDATE())
    LIMIT 1
");
$jobStmt->execute([$job_id]);
$job = $jobStmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    header('Location: recommended_jobs.php');
    exit;
}

$checkStmt = $pdo->prepare("
    SELECT id
    FROM applications
    WHERE alumni_id = ? AND job_post_id = ?
    LIMIT 1
");
$checkStmt->execute([$user_id, $job_id]);

if ($checkStmt->fetch()) {
    markJobInvitationApplied($pdo, (int) $user_id, (int) $job_id);

    $_SESSION['flash_message'] = 'You have already applied to this job.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: applications.php');
    exit;
}

$profileStmt = $pdo->prepare("
    SELECT 
        u.first_name,
        u.last_name,
        u.email,
        ap.phone,
        ap.address,
        ap.course,
        ap.major,
        ap.graduation_year,
        ap.employment_status,
        ap.bio
    FROM users u
    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
    WHERE u.id = ?
    LIMIT 1
");
$profileStmt->execute([$user_id]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

$courseLabels = [
    'BSEd' => 'Bachelor of Secondary Education',
    'BEEd' => 'Bachelor of Elementary Education',
    'BPEd' => 'Bachelor of Physical Education',
    'BTLEd' => 'Bachelor of Technology and Livelihood Education',
    'BSA' => 'Bachelor of Science in Accountancy',
    'BSBA' => 'Bachelor of Science in Business Administration',
    'BSIT' => 'Bachelor of Science in Information Technology',
    'BSHM' => 'Bachelor of Science in Hospitality Management',
    'BSIndTech' => 'Bachelor of Science in Industrial Technology'
];

$displayCourse = $courseLabels[$profile['course'] ?? ''] ?? ($profile['course'] ?? '');

$errors = [];
$step = 'upload';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_step = $_POST['form_step'] ?? 'upload';

    if ($form_step === 'upload') {
        if (!isset($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload your resume.';
        } else {
            $allowedExtensions = ['pdf', 'txt', 'docx'];
            $originalName = $_FILES['resume']['name'];
            $tmpName = $_FILES['resume']['tmp_name'];
            $fileSize = $_FILES['resume']['size'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions)) {
                $errors[] = 'Only PDF, DOC, and DOCX files are allowed.';
            }

            if ($fileSize > 5 * 1024 * 1024) {
                $errors[] = 'Resume file must not exceed 5MB.';
            }

            if (empty($errors)) {
                $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
                $uploadDir = '../assets/uploads/resumes/';
                $relativePath = 'assets/uploads/resumes/' . $safeName;
                $fullPath = $uploadDir . $safeName;

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }


              if (!move_uploaded_file($tmpName, $fullPath)) {
    $errors[] = 'Failed to upload resume.';
} else {
    $resumeInsert = $pdo->prepare("
        INSERT INTO resumes (alumni_id, file_name, file_path)
        VALUES (?, ?, ?)
    ");
    $resumeInsert->execute([
        $user_id,
        $originalName,
        $relativePath
    ]);

    $resume_id = $pdo->lastInsertId();

    $prefill_name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
    $prefill_email = $profile['email'] ?? '';
    $prefill_phone = $profile['phone'] ?? '';
    $prefill_address = $profile['address'] ?? '';
    $prefill_course = $displayCourse;
    $prefill_major = $profile['major'] ?? '';
    $prefill_grad_year = $profile['graduation_year'] ?? '';
    $prefill_employment_status = $profile['employment_status'] ?? '';

    $resumeText = extractResumeText($fullPath, $extension);
    $analysis = analyzeResumeWithGemini($resumeText);

    $prefill_skills = $analysis['skills'] ?? '';
    $prefill_summary = $analysis['summary'] ?? '';
    $prefill_recommended_roles = $analysis['recommended_roles'] ?? '';
    $prefill_strengths = $analysis['strengths'] ?? '';
    $prefill_analysis_source = $analysis['source'] ?? 'Local Analyzer';

    if ($prefill_summary === '') {
        $prefill_summary = $profile['bio'] ?? '';
    }

    $step = 'review';
}

                // if (!move_uploaded_file($tmpName, $fullPath)) {
                //     $errors[] = 'Failed to upload resume.';
                // } else {
                //     $resumeInsert = $pdo->prepare("
                //         INSERT INTO resumes (alumni_id, file_name, file_path)
                //         VALUES (?, ?, ?)
                //     ");
                //     $resumeInsert->execute([
                //         $user_id,
                //         $originalName,
                //         $relativePath
                //     ]);

                //     $resume_id = $pdo->lastInsertId();

                //     $prefill_name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
                //     $prefill_email = $profile['email'] ?? '';
                //     $prefill_phone = $profile['phone'] ?? '';
                //     $prefill_address = $profile['address'] ?? '';
                //     $prefill_course = $displayCourse;
                //     $prefill_major = $profile['major'] ?? '';
                //     $prefill_grad_year = $profile['graduation_year'] ?? '';
                //     $prefill_employment_status = $profile['employment_status'] ?? '';
                //     $prefill_skills = '';
                //     $prefill_summary = $profile['bio'] ?? '';

                //     $step = 'review';
                // }
            }
        }
    }

    if ($form_step === 'review') {
        $resume_id = trim($_POST['resume_id'] ?? '');
        $applicant_name = trim($_POST['applicant_name'] ?? '');
        $applicant_email = trim($_POST['applicant_email'] ?? '');
        $applicant_phone = trim($_POST['applicant_phone'] ?? '');
        $applicant_address = trim($_POST['applicant_address'] ?? '');
        $applicant_course = trim($_POST['applicant_course'] ?? '');
        $applicant_major = trim($_POST['applicant_major'] ?? '');
        $applicant_graduation_year = trim($_POST['applicant_graduation_year'] ?? '');
        $applicant_employment_status  = trim($_POST['applicant_employment_status'] ?? '');
        $applicant_skills = trim($_POST['applicant_skills'] ?? '');
        $applicant_summary = trim($_POST['applicant_summary'] ?? '');
        $prefill_analysis_source = trim($_POST['analysis_source'] ?? 'Local Analyzer');

        if ($resume_id === '' || !ctype_digit($resume_id)) {
            $errors[] = 'Invalid resume reference.';
        }

        if ($applicant_name === '' || $applicant_email === '') {
            $errors[] = 'Applicant name and email are required.';
        }

        if (!filter_var($applicant_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }

        if ($applicant_course === '') {
            $errors[] = 'Course is required.';
        }

        if ($applicant_graduation_year !== '' && !preg_match('/^\d{4}$/', $applicant_graduation_year)) {
            $errors[] = 'Graduation year must be a valid 4-digit year.';
        }

        if (empty($errors)) {
            $resumeCheck = $pdo->prepare("
                SELECT id
                FROM resumes
                WHERE id = ? AND alumni_id = ?
                LIMIT 1
            ");
            $resumeCheck->execute([$resume_id, $user_id]);

            if (!$resumeCheck->fetch()) {
                $errors[] = 'Resume record not found.';
            } else {
                $insertStmt = $pdo->prepare("
                    INSERT INTO applications (
                        alumni_id,
                        job_post_id,
                        resume_id,
                        applicant_name,
                        applicant_email,
                        applicant_phone,
                        applicant_address,
                        applicant_course,
                        applicant_major,
                        applicant_graduation_year,
                        applicant_employment_status,
                        applicant_skills,
                        applicant_summary,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?,?, ?, ?, ?, ?, 'Pending')
                ");

                $insertStmt->execute([
                    $user_id,
                    $job_id,
                    $resume_id,
                    $applicant_name,
                    $applicant_email,
                    $applicant_phone !== '' ? $applicant_phone : null,
                    $applicant_address !== '' ? $applicant_address : null,
                    $applicant_course,
                    $applicant_major !== '' ? $applicant_major : null,
                    $applicant_graduation_year !== '' ? $applicant_graduation_year : null,
                    $applicant_employment_status !== '' ? $applicant_employment_status : null,
                    $applicant_skills !== '' ? $applicant_skills : null,
                    $applicant_summary !== '' ? $applicant_summary : null
                ]);

                markJobInvitationApplied($pdo, (int) $user_id, (int) $job_id);

                $_SESSION['flash_message'] = 'Application submitted successfully for "' . $job['title'] . '".';
                $_SESSION['flash_type'] = 'success';

                header('Location: applications.php');
                exit;
            }
        }

        $step = 'review';
    }
}

$pageTitle = 'Apply to Job';
$pageSubtitle = 'Upload your resume, review your details, and submit your application.';
$activePage = 'jobs';

require_once '../includes/dashboard_header.php';
?>

<div class="hero-card">
    <div class="hero-grid">
        <div>
            <span class="badge-soft">Job Application</span>
            <div class="hero-title mt-3"><?= htmlspecialchars($job['title']) ?></div>
            <div class="hero-text">
                <?= htmlspecialchars($job['company_name']) ?>
                <?php if (!empty($job['location'])): ?>
                    • <?= htmlspecialchars($job['location']) ?>
                <?php endif; ?>
                <?php if (!empty($job['employment_type'])): ?>
                    • <?= htmlspecialchars($job['employment_type']) ?>
                <?php endif; ?>
            </div>

            <div class="hero-actions">
                <a href="view_job.php?id=<?= $job['id'] ?>" class="btn-light-outline">Back to Job Details</a>
            </div>
        </div>

        <div class="progress-card">
            <div class="label">Application Process</div>
            <div class="value" style="font-size:22px;">
                <?= $step === 'upload' ? 'Step 1 of 2' : 'Step 2 of 2' ?>
            </div>
            <div class="small">
                <?= $step === 'upload'
                    ? 'Upload your resume first.'
                    : 'Review and edit your details before submitting.' ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger rounded-4 mb-4">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>
    <div class="panel-card">
        <div class="panel-title">Step 1: Upload Resume</div>

        <form method="POST" action="" enctype="multipart/form-data" data-loader-text="Uploading and analyzing your resume…">
            <input type="hidden" name="form_step" value="upload">
            <input type="hidden" name="job_id" value="<?= htmlspecialchars($job_id) ?>">
            <input type="hidden" name="resume_id" value="<?= htmlspecialchars($resume_id ?? $_POST['resume_id'] ?? '') ?>">
            <input type="hidden" name="analysis_source"
                value="<?= htmlspecialchars($prefill_analysis_source ?? 'Local Analyzer') ?>">


            <div class="section-box mt-0">
                <div class="section-title">Resume Upload</div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Resume File</label>
                        <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx" required>
                        <div class="form-text">Accepted formats: PDF, DOC, DOCX. Max size: 5MB.</div>
                    </div>
                </div>
            </div>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-maroon btn-lg">Upload and Continue</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($step === 'review'): ?>
    <div class="panel-card">
        <div class="panel-title">Step 2: Review Application Details</div>
        <div class="text-muted mb-3">
            These fields are prefilled from your alumni profile. Please review and update anything if needed before
            submitting.
        </div>

        <form method="POST" action="" data-loader-text="Submitting your application…">
            <input type="hidden" name="form_step" value="review">
            <input type="hidden" name="job_id" value="<?= htmlspecialchars($job_id) ?>">
            <input type="hidden" name="resume_id" value="<?= htmlspecialchars($resume_id ?? $_POST['resume_id'] ?? '') ?>">

            <div class="section-box mt-0">
                <div class="section-title">Applicant Details</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="applicant_name" class="form-control"
                            value="<?= htmlspecialchars($prefill_name ?? $_POST['applicant_name'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="applicant_email" class="form-control"
                            value="<?= htmlspecialchars($prefill_email ?? $_POST['applicant_email'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="applicant_phone" class="form-control"
                            value="<?= htmlspecialchars($prefill_phone ?? $_POST['applicant_phone'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Graduation Year</label>
                        <input type="text" name="applicant_graduation_year" class="form-control"
                            value="<?= htmlspecialchars($prefill_grad_year ?? $_POST['applicant_graduation_year'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Employment Status</label>
                        <select name="applicant_employment_status" class="form-select">
                            <option value="">Select Status</option>
                            <option value="Employed" <?= (($prefill_employment_status ?? $_POST['applicant_employment_status'] ?? '') === 'Employed') ? 'selected' : '' ?>>Employed
                            </option>
                            <option value="Unemployed" <?= (($prefill_employment_status ?? $_POST['applicant_employment_status'] ?? '') === 'Unemployed') ? 'selected' : '' ?>>Unemployed
                            </option>
                            <option value="Self-Employed" <?= (($prefill_employment_status ?? $_POST['applicant_employment_status'] ?? '') === 'Self-Employed') ? 'selected' : '' ?>>
                                Self-Employed</option>
                            <option value="Student" <?= (($prefill_employment_status ?? $_POST['applicant_employment_status'] ?? '') === 'Student') ? 'selected' : '' ?>>Student</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea name="applicant_address" class="form-control"
                            rows="3"><?= htmlspecialchars($prefill_address ?? $_POST['applicant_address'] ?? '') ?></textarea>
                    </div>



                    <div class="col-md-6">
                        <label class="form-label">Course</label>
                        <input type="text" name="applicant_course" class="form-control"
                            value="<?= htmlspecialchars($prefill_course ?? $_POST['applicant_course'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Major</label>
                        <input type="text" name="applicant_major" class="form-control"
                            value="<?= htmlspecialchars($prefill_major ?? $_POST['applicant_major'] ?? '') ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Skills</label>
                        <textarea name="applicant_skills" class="form-control" rows="3"
                            placeholder="Example: PHP, MySQL, Bootstrap, JavaScript"><?= htmlspecialchars($prefill_skills ?? $_POST['applicant_skills'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Professional Summary / Objective</label>
                        <textarea name="applicant_summary" class="form-control"
                            rows="4"><?= htmlspecialchars($prefill_summary ?? $_POST['applicant_summary'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="section-box mt-0">
                <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="section-title mb-0">AI Resume Analyzer Preview</div>
    <span class="badge-soft"><?= htmlspecialchars($prefill_analysis_source ?? 'Local Analyzer') ?></span>
</div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Detected Skills</label>
                        <textarea class="form-control" rows="3"
                            readonly><?= htmlspecialchars($prefill_skills ?? '') ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Detected Summary</label>
                        <textarea class="form-control" rows="4"
                            readonly><?= htmlspecialchars($prefill_summary ?? '') ?></textarea>
                    </div>

                    <?php if (!empty($prefill_recommended_roles)): ?>
                        <div class="col-12">
                            <label class="form-label">Suggested Roles</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($prefill_recommended_roles) ?>"
                                readonly>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($prefill_strengths)): ?>
                        <div class="col-12">
                            <label class="form-label">Detected Strengths</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($prefill_strengths) ?>"
                                readonly>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-maroon btn-lg">Submit Application</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require_once '../includes/dashboard_footer.php'; ?>